<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Cache;
use think\facade\Log;

/**
 * 频控中间件
 * API 限流：默认 60次/分钟，批量操作 100条/秒
 */
class RateLimit
{
    /**
     * 默认频率限制配置
     * @var array
     */
    protected $defaultLimits = [
        // 键名 => [次数, 时间窗口(秒)]
        'default'        => [60, 60],     // 默认 60次/分钟
        'login'          => [5, 60],       // 登录 5次/分钟
        'register'       => [3, 60],       // 注册 3次/分钟
        'send_sms'       => [1, 60],       // 发送短信 1次/分钟
        'upload'         => [30, 60],      // 上传 30次/分钟
        'batch'          => [100, 1],      // 批量操作 100条/秒
        'captcha'        => [10, 60],      // 验证码 10次/分钟
        'ocr'            => [20, 60],      // OCR识别 20次/分钟
        'asr'            => [10, 60],      // 语音识别 10次/分钟
    ];

    /**
     * 处理请求
     * @param \think\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(\think\Request $request, \Closure $next)
    {
        $path = $request->pathinfo();
        $method = $request->method();
        $ip = $request->realIp();

        // 获取限流配置
        $limit = $this->getLimitConfig($path);

        if ($limit === null) {
            // 白名单路径，不限流
            return $next($request);
        }

        [$maxRequests, $window] = $limit;

        // 生成限流 key
        $key = $this->generateKey($ip, $path);

        // 检查频率限制
        if (!$this->check($key, $maxRequests, $window)) {
            Log::warning("Rate limit exceeded: IP={$ip}, Path={$path}, Limit={$maxRequests}/{$window}s");

            return json([
                'code'     => 429,
                'msg'      => "请求过于频繁，请{$window}秒后重试",
                'data'     => null,
                'trace_id' => trace_id(),
            ])->code(429);
        }

        return $next($request);
    }

    /**
     * 生成限流 key
     * @param string $ip
     * @param string $path
     * @return string
     */
    protected function generateKey(string $ip, string $path): string
    {
        // 提取路由前缀作为限流类型
        $type = 'default';
        if (strpos($path, 'login') !== false) {
            $type = 'login';
        } elseif (strpos($path, 'register') !== false) {
            $type = 'register';
        } elseif (strpos($path, 'send_sms') !== false || strpos($path, 'sms') !== false) {
            $type = 'send_sms';
        } elseif (strpos($path, 'upload') !== false) {
            $type = 'upload';
        } elseif (strpos($path, 'batch') !== false) {
            $type = 'batch';
        } elseif (strpos($path, 'captcha') !== false) {
            $type = 'captcha';
        } elseif (strpos($path, 'ocr') !== false) {
            $type = 'ocr';
        } elseif (strpos($path, 'asr') !== false) {
            $type = 'asr';
        }

        return sprintf('rate_limit:%s:%s:%s', $type, $ip, date('YmdH'));
    }

    /**
     * 获取限流配置
     * @param string $path
     * @return array|null null 表示不限流
     */
    protected function getLimitConfig(string $path): ?array
    {
        // 白名单路径（不限流）
        $whitelist = [
            'health',
            'config/public',
        ];

        foreach ($whitelist as $item) {
            if (strpos($path, $item) !== false) {
                return null;
            }
        }

        // 匹配限流配置
        foreach ($this->defaultLimits as $type => $config) {
            if ($type === 'default') {
                continue;
            }
            if (strpos($path, $type) !== false) {
                return $config;
            }
        }

        // 返回默认配置
        return $this->defaultLimits['default'];
    }

    /**
     * 检查频率限制
     * @param string $key
     * @param int $maxRequests
     * @param int $window
     * @return bool
     */
    protected function check(string $key, int $maxRequests, int $window): bool
    {
        try {
            $redis = Cache::store('redis')->handler();

            // 使用滑动窗口算法
            $now = microtime(true) * 1000;
            $windowStart = $now - ($window * 1000);

            // 使用 pipeline 减少网络开销
            $redis->multi();
            // 移除窗口外的记录
            $redis->zRemRangeByScore($key, 0, $windowStart);
            // 添加当前请求
            $redis->zAdd($key, $now, $now . ':' . uniqid());
            // 获取窗口内请求数
            $redis->zCard($key);
            // 设置过期时间
            $redis->expire($key, $window * 2);
            $result = $redis->exec();

            $count = $result[2] ?? 0;

            return $count <= $maxRequests;
        } catch (\Throwable $e) {
            // Redis 不可用时，允许请求通过
            return true;
        }
    }
}