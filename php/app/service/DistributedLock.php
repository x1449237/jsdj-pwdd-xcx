<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;
use think\facade\Log;
use RedisException;

class DistributedLock
{
    private $redis;

    private const LOCK_PREFIX = 'dist_lock:';
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_RETRY_COUNT = 3;
    private const DEFAULT_RETRY_DELAY = 100000;
    private const WATCHDOG_INTERVAL = 10;

    private $heldLocks = [];
    private $watchdogTimer = null;

    public function __construct()
    {
        $this->redis = Cache::store('redis')->handler();
    }

    public function acquire(string $key, int $timeout = self::DEFAULT_TIMEOUT, int $retryCount = self::DEFAULT_RETRY_COUNT): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;
        $uniqueId = $this->generateUniqueId();
        $attempt = 0;
        $startTime = microtime(true);

        while ($attempt <= $retryCount) {
            try {
                $result = $this->redis->set($lockKey, $uniqueId, ['NX', 'EX' => $timeout]);
                if ($result) {
                    $this->heldLocks[$lockKey] = [
                        'unique_id' => $uniqueId,
                        'timeout' => $timeout,
                        'acquire_time' => time(),
                        'retry_count' => $attempt,
                        'wait_time' => (int)((microtime(true) - $startTime) * 1000),
                    ];

                    $this->logLockAcquire($key, $uniqueId, $timeout, $attempt, $this->heldLocks[$lockKey]['wait_time']);

                    if ($this->isSwooleEnvironment()) {
                        $this->startWatchdog($lockKey, $uniqueId, $timeout);
                    }

                    return true;
                }
            } catch (RedisException $e) {
                Log::error("Distributed lock acquire error: " . $e->getMessage() . ", key=" . $key);
            }

            $attempt++;
            if ($attempt <= $retryCount) {
                usleep(self::DEFAULT_RETRY_DELAY);
            }
        }

        return false;
    }

    public function release(string $key): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;

        if (!isset($this->heldLocks[$lockKey])) {
            return false;
        }

        $lockInfo = $this->heldLocks[$lockKey];
        $uniqueId = $lockInfo['unique_id'];

        try {
            $script = <<<LUA
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
else
    return 0
end
LUA;

            $result = $this->redis->eval($script, [$lockKey, $uniqueId], 1);

            $this->stopWatchdog($lockKey);

            unset($this->heldLocks[$lockKey]);

            $this->logLockRelease($key, $uniqueId, (bool)$result);

            return (bool)$result;
        } catch (RedisException $e) {
            Log::error("Distributed lock release error: " . $e->getMessage() . ", key=" . $key);
            return false;
        }
    }

    public function isLocked(string $key): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;
        try {
            return (bool)$this->redis->exists($lockKey);
        } catch (RedisException $e) {
            Log::error("Distributed lock check error: " . $e->getMessage() . ", key=" . $key);
            return false;
        }
    }

    public function getTtl(string $key): int
    {
        $lockKey = self::LOCK_PREFIX . $key;
        try {
            $ttl = $this->redis->ttl($lockKey);
            return $ttl > 0 ? $ttl : 0;
        } catch (RedisException $e) {
            Log::error("Distributed lock ttl error: " . $e->getMessage() . ", key=" . $key);
            return 0;
        }
    }

    public function forceRelease(string $key): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;
        try {
            $this->stopWatchdog($lockKey);
            unset($this->heldLocks[$lockKey]);
            return (bool)$this->redis->del($lockKey);
        } catch (RedisException $e) {
            Log::error("Distributed lock force release error: " . $e->getMessage() . ", key=" . $key);
            return false;
        }
    }

    public function isHeld(string $key): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;
        return isset($this->heldLocks[$lockKey]);
    }

    public function withLock(string $key, callable $callback, int $timeout = self::DEFAULT_TIMEOUT, $default = null)
    {
        if (!$this->acquire($key, $timeout)) {
            return $default;
        }

        try {
            return $callback();
        } finally {
            $this->release($key);
        }
    }

    public function getHeldLocks(): array
    {
        return array_keys($this->heldLocks);
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->heldLocks) as $lockKey) {
            $key = str_replace(self::LOCK_PREFIX, '', $lockKey);
            $this->release($key);
        }
    }

    private function generateUniqueId(): string
    {
        return uniqid(getmypid() . '_' . microtime(true) . '_', true);
    }

    private function isSwooleEnvironment(): bool
    {
        return extension_loaded('swoole') && class_exists('Swoole\Timer') && \Swoole\Timer::class !== false;
    }

    private function startWatchdog(string $lockKey, string $uniqueId, int $timeout): void
    {
        if (!$this->isSwooleEnvironment()) {
            return;
        }

        $renewInterval = (int)($timeout * 0.7 * 1000);
        if ($renewInterval <= 0) {
            return;
        }

        $timerId = \Swoole\Timer::tick($renewInterval, function () use ($lockKey, $uniqueId, $timeout) {
            try {
                $script = <<<LUA
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('expire', KEYS[1], ARGV[2])
else
    return 0
end
LUA;
                $result = $this->redis->eval($script, [$lockKey, $uniqueId, $timeout], 1);
                if (!$result) {
                    \Swoole\Timer::clear($this->watchdogTimer[$lockKey] ?? 0);
                    unset($this->watchdogTimer[$lockKey]);
                }
            } catch (\Throwable $e) {
                Log::error("Watchdog renew error: " . $e->getMessage() . ", key=" . $lockKey);
            }
        });

        $this->watchdogTimer[$lockKey] = $timerId;
    }

    private function stopWatchdog(string $lockKey): void
    {
        if (!$this->isSwooleEnvironment() || !isset($this->watchdogTimer[$lockKey])) {
            return;
        }

        \Swoole\Timer::clear($this->watchdogTimer[$lockKey]);
        unset($this->watchdogTimer[$lockKey]);
    }

    private function logLockAcquire(string $key, string $uniqueId, int $timeout, int $retryCount, int $waitTime): void
    {
        try {
            \think\facade\Db::name('distributed_lock_log')->insert([
                'lock_key' => $key,
                'holder' => $uniqueId,
                'acquire_time' => date('Y-m-d H:i:s'),
                'timeout' => $timeout,
                'retry_count' => $retryCount,
                'wait_time' => $waitTime,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::debug("Lock acquire log error: " . $e->getMessage());
        }
    }

    private function logLockRelease(string $key, string $uniqueId, bool $success): void
    {
        try {
            \think\facade\Db::name('distributed_lock_log')
                ->where('lock_key', $key)
                ->where('holder', $uniqueId)
                ->where('status', 1)
                ->update([
                    'release_time' => date('Y-m-d H:i:s'),
                    'status' => $success ? 2 : 3,
                ]);
        } catch (\Throwable $e) {
            Log::debug("Lock release log error: " . $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->releaseAll();
    }
}
