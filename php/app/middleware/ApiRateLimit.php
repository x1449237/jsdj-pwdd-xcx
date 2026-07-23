<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Cache;
use think\facade\Log;
use think\facade\Db;

class ApiRateLimit
{
    private $redis;

    private const RATE_LIMIT_PREFIX = 'api_rate:';
    private const BLOCKED_IP_PREFIX = 'blocked_ip:';
    private const CRAWLER_THRESHOLD = 300;
    private const BLOCK_DURATION = 3600;

    private $userTypeLimits = [
        'default' => 60,
        'player'  => 120,
        'admin'   => 300,
    ];

    private $window = 60;

    public function __construct()
    {
        $this->redis = Cache::store('redis')->handler();

        $config = config('rate_limit', []);
        if (!empty($config)) {
            $this->userTypeLimits = $config['limits'] ?? $this->userTypeLimits;
            $this->window = $config['window'] ?? $this->window;
        }
    }

    public function handle($request, \Closure $next)
    {
        $ip = $request->realIp();
        $path = $request->pathinfo();
        $userId = $request->userId() ?? 0;
        $userType = $this->getUserType($request);

        if ($this->isIpBlocked($ip)) {
            return json([
                'code'     => 429,
                'msg'      => 'IP已被封禁，请1小时后重试',
                'data'     => null,
                'trace_id' => trace_id(),
            ])->code(429);
        }

        $limit = $this->userTypeLimits[$userType] ?? $this->userTypeLimits['default'];

        $key = $this->generateKey($ip, $userId, $path);

        $result = $this->checkRateLimit($key, $limit, $this->window);

        if (!$result['allowed']) {
            $this->handleExceeded($ip, $userId, $path, $userType, $result['count']);

            return json([
                'code'     => 429,
                'msg'      => "请求过于频繁，请{$this->window}秒后重试",
                'data'     => [
                    'limit' => $limit,
                    'window' => $this->window,
                    'retry_after' => $this->window,
                ],
                'trace_id' => trace_id(),
            ])->code(429);
        }

        $response = $next($request);

        $response->header(['X-RateLimit-Limit' => $limit]);
        $response->header(['X-RateLimit-Remaining' => max(0, $limit - $result['count'])]);
        $response->header(['X-RateLimit-Reset' => time() + $this->window]);

        return $response;
    }

    private function generateKey(string $ip, int $userId, string $path): string
    {
        $pathPrefix = explode('/', $path)[0] ?? 'default';

        if ($userId > 0) {
            return self::RATE_LIMIT_PREFIX . "user:{$userId}:{$pathPrefix}:" . date('YmdHi');
        }

        return self::RATE_LIMIT_PREFIX . "ip:{$ip}:{$pathPrefix}:" . date('YmdHi');
    }

    private function checkRateLimit(string $key, int $limit, int $window): array
    {
        try {
            $now = microtime(true) * 1000;
            $windowStart = $now - ($window * 1000);

            $this->redis->multi();
            $this->redis->zRemRangeByScore($key, 0, $windowStart);
            $this->redis->zAdd($key, $now, $now . ':' . uniqid());
            $this->redis->zCard($key);
            $this->redis->expire($key, $window * 2);
            $result = $this->redis->exec();

            $count = $result[2] ?? 0;

            return [
                'allowed' => $count <= $limit,
                'count'   => $count,
                'limit'   => $limit,
            ];
        } catch (\Throwable $e) {
            Log::error("Rate limit check error: " . $e->getMessage());
            return [
                'allowed' => true,
                'count'   => 0,
                'limit'   => $limit,
            ];
        }
    }

    private function handleExceeded(string $ip, int $userId, string $path, string $userType, int $hits): void
    {
        try {
            Db::name('api_rate_limit_log')->insert([
                'ip'         => $ip,
                'user_id'    => $userId,
                'endpoint'   => $path,
                'hits'       => $hits,
                'blocked'    => 0,
                'user_type'  => $userType === 'default' ? 0 : ($userType === 'player' ? 1 : 2),
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::error("Rate limit log error: " . $e->getMessage());
        }

        $this->checkAndBlockCrawler($ip, $path);
    }

    private function checkAndBlockCrawler(string $ip, string $path): void
    {
        try {
            $crawlerKey = self::RATE_LIMIT_PREFIX . "crawler:{$ip}:" . date('YmdH');
            $count = $this->redis->incr($crawlerKey);
            if ($count === 1) {
                $this->redis->expire($crawlerKey, 3600);
            }

            if ($count >= self::CRAWLER_THRESHOLD) {
                $this->blockIp($ip);

                try {
                    Db::name('api_rate_limit_log')
                        ->where('ip', $ip)
                        ->whereTime('create_time', '-1 hour')
                        ->update([
                            'blocked' => 1,
                            'block_until' => date('Y-m-d H:i:s', time() + self::BLOCK_DURATION),
                        ]);
                } catch (\Throwable $e) {
                    Log::error("Block log update error: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::error("Crawler check error: " . $e->getMessage());
        }
    }

    private function blockIp(string $ip): void
    {
        try {
            $blockKey = self::BLOCKED_IP_PREFIX . $ip;
            $this->redis->setex($blockKey, self::BLOCK_DURATION, '1');
            Log::warning("IP blocked due to crawler behavior: ip={$ip}");
        } catch (\Throwable $e) {
            Log::error("Block ip error: " . $e->getMessage());
        }
    }

    private function isIpBlocked(string $ip): bool
    {
        try {
            $blockKey = self::BLOCKED_IP_PREFIX . $ip;
            return (bool)$this->redis->exists($blockKey);
        } catch (\Throwable $e) {
            Log::error("Blocked ip check error: " . $e->getMessage());
            return false;
        }
    }

    private function getUserType($request): string
    {
        if ($request->adminId() > 0) {
            return 'admin';
        }

        if ($request->is_player ?? false) {
            return 'player';
        }

        if ($request->userId() > 0) {
            return 'default';
        }

        return 'default';
    }

    public function unblockIp(string $ip): bool
    {
        try {
            $blockKey = self::BLOCKED_IP_PREFIX . $ip;
            return (bool)$this->redis->del($blockKey);
        } catch (\Throwable $e) {
            Log::error("Unblock ip error: " . $e->getMessage());
            return false;
        }
    }

    public function getBlockedIps(): array
    {
        try {
            $keys = $this->redis->keys(self::BLOCKED_IP_PREFIX . '*');
            $ips = [];
            foreach ($keys as $key) {
                $ip = str_replace(self::BLOCKED_IP_PREFIX, '', $key);
                $ttl = $this->redis->ttl($key);
                $ips[] = [
                    'ip' => $ip,
                    'remaining' => $ttl > 0 ? $ttl : 0,
                ];
            }
            return $ips;
        } catch (\Throwable $e) {
            Log::error("Get blocked ips error: " . $e->getMessage());
            return [];
        }
    }
}
