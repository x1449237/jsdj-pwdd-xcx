<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;
use think\facade\Log;
use RedisException;

class CacheService
{
    private $redis;

    private const CACHE_PERMANENT_PREFIX = 'perm:';
    private const CACHE_TEMP_PREFIX = 'temp:';
    private const CACHE_LOCK_PREFIX = 'lock:';
    private const CACHE_BLOOM_PREFIX = 'bloom:';

    private const NULL_CACHE_TTL = 60;
    private const LOCK_TIMEOUT = 10;
    private const LOCK_RETRY_COUNT = 3;
    private const LOCK_RETRY_DELAY = 100000;

    public function __construct()
    {
        $this->redis = Cache::store('redis')->handler();
    }

    public function getPermanent(string $key, $default = null)
    {
        return $this->get(self::CACHE_PERMANENT_PREFIX . $key, $default);
    }

    public function setPermanent(string $key, $value): bool
    {
        return $this->set(self::CACHE_PERMANENT_PREFIX . $key, $value, 0);
    }

    public function delPermanent(string $key): bool
    {
        return $this->del(self::CACHE_PERMANENT_PREFIX . $key);
    }

    public function getTemp(string $key, $default = null)
    {
        return $this->get(self::CACHE_TEMP_PREFIX . $key, $default);
    }

    public function setTemp(string $key, $value, int $ttl = 3600): bool
    {
        $randomTtl = $this->addRandomTtl($ttl);
        return $this->set(self::CACHE_TEMP_PREFIX . $key, $value, $randomTtl);
    }

    public function delTemp(string $key): bool
    {
        return $this->del(self::CACHE_TEMP_PREFIX . $key);
    }

    public function remember(string $key, callable $callback, int $ttl = 3600, bool $permanent = false)
    {
        $cacheKey = $permanent ? self::CACHE_PERMANENT_PREFIX . $key : self::CACHE_TEMP_PREFIX . $key;

        $value = $this->get($cacheKey);
        if ($value !== null) {
            $this->recordHit($key, true);
            return $value;
        }

        $this->recordHit($key, false);

        $lockKey = self::CACHE_LOCK_PREFIX . $key;
        if (!$this->acquireLock($lockKey)) {
            usleep(self::LOCK_RETRY_DELAY);
            $value = $this->get($cacheKey);
            if ($value !== null) {
                return $value;
            }
            return $default ?? null;
        }

        try {
            $value = $callback();

            if ($value === null || $value === false || $value === []) {
                $this->set($cacheKey, null, self::NULL_CACHE_TTL);
            } else {
                $actualTtl = $permanent ? 0 : $this->addRandomTtl($ttl);
                $this->set($cacheKey, $value, $actualTtl);
            }

            return $value;
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    public function rememberWithBloom(string $key, string $bloomKey, callable $callback, int $ttl = 3600)
    {
        if (!$this->bloomExists($bloomKey, $key)) {
            return null;
        }

        return $this->remember($key, $callback, $ttl);
    }

    public function bloomAdd(string $bloomKey, string $item): bool
    {
        try {
            $key = self::CACHE_BLOOM_PREFIX . $bloomKey;
            $hash = $this->bloomHash($item);
            foreach ($hash as $bit) {
                $this->redis->setBit($key, $bit, 1);
            }
            return true;
        } catch (RedisException $e) {
            Log::error("Bloom filter add error: " . $e->getMessage());
            return false;
        }
    }

    public function bloomExists(string $bloomKey, string $item): bool
    {
        try {
            $key = self::CACHE_BLOOM_PREFIX . $bloomKey;
            $hash = $this->bloomHash($item);
            foreach ($hash as $bit) {
                if (!$this->redis->getBit($key, $bit)) {
                    return false;
                }
            }
            return true;
        } catch (RedisException $e) {
            Log::error("Bloom filter exists error: " . $e->getMessage());
            return true;
        }
    }

    private function bloomHash(string $item): array
    {
        $bits = [];
        $size = 1000000;
        $hash1 = crc32($item);
        $hash2 = crc32(strrev($item));
        $hash3 = crc32(md5($item));

        $bits[] = abs($hash1 % $size);
        $bits[] = abs($hash2 % $size);
        $bits[] = abs($hash3 % $size);

        return $bits;
    }

    private function addRandomTtl(int $baseTtl): int
    {
        if ($baseTtl <= 0) {
            return 0;
        }
        $randomFactor = mt_rand(0, 100) / 100;
        $variance = (int)($baseTtl * 0.1);
        return $baseTtl + (int)($randomFactor * $variance) - (int)($variance / 2);
    }

    private function acquireLock(string $key): bool
    {
        $attempt = 0;
        $uniqueId = uniqid(getmypid() . '_', true);

        while ($attempt < self::LOCK_RETRY_COUNT) {
            $result = $this->redis->set($key, $uniqueId, ['NX', 'EX' => self::LOCK_TIMEOUT]);
            if ($result) {
                return true;
            }
            $attempt++;
            usleep(self::LOCK_RETRY_DELAY);
        }

        return false;
    }

    private function releaseLock(string $key): bool
    {
        $this->redis->del($key);
        return true;
    }

    private function get(string $key, $default = null)
    {
        try {
            $value = $this->redis->get($key);
            if ($value === false || $value === null) {
                return $default;
            }
            return unserialize($value);
        } catch (RedisException $e) {
            Log::error("Cache get error: " . $e->getMessage() . ", key=" . $key);
            return $default;
        }
    }

    private function set(string $key, $value, int $ttl = 0): bool
    {
        try {
            $serialized = serialize($value);
            if ($ttl > 0) {
                return (bool)$this->redis->setex($key, $ttl, $serialized);
            } else {
                return (bool)$this->redis->set($key, $serialized);
            }
        } catch (RedisException $e) {
            Log::error("Cache set error: " . $e->getMessage() . ", key=" . $key);
            return false;
        }
    }

    private function del(string $key): bool
    {
        try {
            return (bool)$this->redis->del($key);
        } catch (RedisException $e) {
            Log::error("Cache del error: " . $e->getMessage() . ", key=" . $key);
            return false;
        }
    }

    private function recordHit(string $key, bool $hit): void
    {
        try {
            $date = date('Y-m-d');
            $prefix = explode(':', $key)[0] ?? 'default';
            $statKey = "cache_stat:{$prefix}:{$date}";

            $this->redis->hIncrBy($statKey, 'total', 1);
            if ($hit) {
                $this->redis->hIncrBy($statKey, 'hit', 1);
            }
            $this->redis->expire($statKey, 86400 * 7);
        } catch (\Throwable $e) {
            Log::error("Cache hit record error: " . $e->getMessage());
        }
    }

    public function getHitStats(string $prefix = '', string $date = ''): array
    {
        try {
            $date = $date ?: date('Y-m-d');
            $prefix = $prefix ?: 'default';
            $statKey = "cache_stat:{$prefix}:{$date}";

            $stats = $this->redis->hGetAll($statKey);
            $total = (int)($stats['total'] ?? 0);
            $hit = (int)($stats['hit'] ?? 0);
            $rate = $total > 0 ? round(($hit / $total) * 100, 2) : 0;

            return [
                'prefix' => $prefix,
                'date' => $date,
                'total' => $total,
                'hit' => $hit,
                'miss' => $total - $hit,
                'hit_rate' => $rate . '%',
            ];
        } catch (\Throwable $e) {
            Log::error("Cache hit stats error: " . $e->getMessage());
            return [
                'prefix' => $prefix,
                'date' => $date,
                'total' => 0,
                'hit' => 0,
                'miss' => 0,
                'hit_rate' => '0%',
            ];
        }
    }

    public function flushAll(): bool
    {
        try {
            return (bool)$this->redis->flushDB();
        } catch (RedisException $e) {
            Log::error("Cache flush error: " . $e->getMessage());
            return false;
        }
    }

    public function getKeys(string $pattern = '*'): array
    {
        try {
            return $this->redis->keys($pattern);
        } catch (RedisException $e) {
            Log::error("Cache keys error: " . $e->getMessage());
            return [];
        }
    }

    public function ttl(string $key): int
    {
        try {
            return $this->redis->ttl($key);
        } catch (RedisException $e) {
            Log::error("Cache ttl error: " . $e->getMessage());
            return -1;
        }
    }
}
