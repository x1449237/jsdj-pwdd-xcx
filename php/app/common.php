<?php
// +----------------------------------------------------------------------
// | 公共函数文件
// +----------------------------------------------------------------------

use think\facade\Config;
use think\facade\Log;

if (!function_exists('trace_id')) {
    /**
     * 生成或获取 trace_id
     * @return string
     */
    function trace_id(): string
    {
        if (function_exists('request')) {
            $request = request();
            $traceId = $request->traceId();
            if ($traceId) {
                return $traceId;
            }
        }

        // 生成新的 trace_id
        $traceId = sprintf(
            '%s-%s-%s-%s%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );

        if (function_exists('request')) {
            $request->setTraceId($traceId);
        }

        return $traceId;
    }
}

if (!function_exists('bcrypt_create')) {
    /**
     * bcrypt 加密
     * @param string $value
     * @return string
     */
    function bcrypt_create(string $value): string
    {
        return password_hash($value, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('bcrypt_verify')) {
    /**
     * bcrypt 验证
     * @param string $value
     * @param string $hash
     * @return bool
     */
    function bcrypt_verify(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
    }
}

if (!function_exists('bcrypt_needs_rehash')) {
    /**
     * 检查 bcrypt 是否需要重新加密
     * @param string $hash
     * @return bool
     */
    function bcrypt_needs_rehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('bc_add')) {
    /**
     * bcmath 高精度加法
     * @param string $left
     * @param string $right
     * @param int $scale
     * @return string
     */
    function bc_add(string $left, string $right, int $scale = 2): string
    {
        return bcadd($left, $right, $scale);
    }
}

if (!function_exists('bc_sub')) {
    /**
     * bcmath 高精度减法
     * @param string $left
     * @param string $right
     * @param int $scale
     * @return string
     */
    function bc_sub(string $left, string $right, int $scale = 2): string
    {
        return bcsub($left, $right, $scale);
    }
}

if (!function_exists('bc_mul')) {
    /**
     * bcmath 高精度乘法
     * @param string $left
     * @param string $right
     * @param int $scale
     * @return string
     */
    function bc_mul(string $left, string $right, int $scale = 2): string
    {
        return bcmul($left, $right, $scale);
    }
}

if (!function_exists('bc_div')) {
    /**
     * bcmath 高精度除法
     * @param string $left
     * @param string $right
     * @param int $scale
     * @return string
     */
    function bc_div(string $left, string $right, int $scale = 2): string
    {
        return bcdiv($left, $right, $scale);
    }
}

if (!function_exists('bc_comp')) {
    /**
     * bcmath 高精度比较
     * @param string $left
     * @param string $right
     * @param int $scale
     * @return int 0相等, 1左边大, -1右边大
     */
    function bc_comp(string $left, string $right, int $scale = 2): int
    {
        return bccomp($left, $right, $scale);
    }
}

if (!function_exists('yuan_to_fen')) {
    /**
     * 元转分（金额处理）
     * @param string $yuan
     * @return int
     */
    function yuan_to_fen(string $yuan): int
    {
        return (int) bcmul($yuan, '100', 0);
    }
}

if (!function_exists('fen_to_yuan')) {
    /**
     * 分转元
     * @param int $fen
     * @return string
     */
    function fen_to_yuan(int $fen): string
    {
        return bcdiv((string) $fen, '100', 2);
    }
}

if (!function_exists('api_success')) {
    /**
     * 成功响应
     * @param mixed $data
     * @param string $msg
     * @param int $code
     * @return \think\Response
     */
    function api_success($data = null, string $msg = 'success', int $code = 0): \think\Response
    {
        return json([
            'code'     => $code,
            'msg'      => $msg,
            'data'     => $data,
            'trace_id' => trace_id(),
        ]);
    }
}

if (!function_exists('api_error')) {
    /**
     * 错误响应
     * @param string $msg
     * @param int $code
     * @param mixed $data
     * @param int $httpCode
     * @return \think\Response
     */
    function api_error(string $msg = 'error', int $code = 1, $data = null, int $httpCode = 200): \think\Response
    {
        return json([
            'code'     => $code,
            'msg'      => $msg,
            'data'     => $data,
            'trace_id' => trace_id(),
        ])->code($httpCode);
    }
}

if (!function_exists('api_page')) {
    /**
     * 分页响应
     * @param mixed $list
     * @param int $total
     * @param int $page
     * @param int $limit
     * @return \think\Response
     */
    function api_page($list, int $total, int $page = 1, int $limit = 15): \think\Response
    {
        return json([
            'code'     => 0,
            'msg'      => 'success',
            'data'     => [
                'list'       => $list,
                'total'      => $total,
                'page'       => $page,
                'limit'      => $limit,
                'total_page' => ceil($total / $limit),
            ],
            'trace_id' => trace_id(),
        ]);
    }
}

if (!function_exists('generate_sn')) {
    /**
     * 生成唯一订单号
     * @param string $prefix
     * @return string
     */
    function generate_sn(string $prefix = ''): string
    {
        $date = date('YmdHis');
        $rand = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        return $prefix . $date . $rand;
    }
}

if (!function_exists('generate_token')) {
    /**
     * 生成随机 token
     * @param int $length
     * @return string
     */
    function generate_token(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}

if (!function_exists('generate_code')) {
    /**
     * 生成验证码
     * @param int $length
     * @return string
     */
    function generate_code(int $length = 6): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }
}

if (!function_exists('mask_sensitive')) {
    /**
     * 敏感数据脱敏
     * @param string $value
     * @param string $type phone|id_card|bank_card|name|email
     * @return string
     */
    function mask_sensitive(string $value, string $type = 'default'): string
    {
        if (empty($value)) {
            return '';
        }

        switch ($type) {
            case 'phone':
                // 手机号脱敏：138****1234
                return substr($value, 0, 3) . '****' . substr($value, -4);

            case 'id_card':
                // 身份证脱敏：310101****1234
                return substr($value, 0, 6) . '********' . substr($value, -4);

            case 'bank_card':
                // 银行卡脱敏：6222****1234
                return substr($value, 0, 4) . ' **** **** ' . substr($value, -4);

            case 'name':
                // 姓名脱敏：张*
                $len = mb_strlen($value);
                return mb_substr($value, 0, 1) . str_repeat('*', $len - 1);

            case 'email':
                // 邮箱脱敏：t***@example.com
                $parts = explode('@', $value);
                $name = $parts[0];
                $domain = $parts[1] ?? '';
                $maskedName = substr($name, 0, 1) . str_repeat('*', max(strlen($name) - 2, 0)) . substr($name, -1);
                return $maskedName . '@' . $domain;

            default:
                return '***';
        }
    }
}

if (!function_exists('array_to_tree')) {
    /**
     * 数组转树形结构
     * @param array $data
     * @param string $id
     * @param string $pid
     * @param string $children
     * @return array
     */
    function array_to_tree(array $data, string $id = 'id', string $pid = 'parent_id', string $children = 'children'): array
    {
        $tree = [];
        $map  = [];

        foreach ($data as &$item) {
            $map[$item[$id]] = &$item;
        }

        foreach ($data as &$item) {
            $parentId = $item[$pid] ?? 0;
            if (isset($map[$parentId])) {
                $map[$parentId][$children][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }

        return $tree;
    }
}

if (!function_exists('get_client_ip')) {
    /**
     * 获取客户端真实 IP
     * @return string
     */
    function get_client_ip(): string
    {
        if (function_exists('request')) {
            return request()->realIp();
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

if (!function_exists('config_get')) {
    /**
     * 获取配置（支持多级 . 分隔）
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function config_get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $config = Config::get(array_shift($keys));

        foreach ($keys as $k) {
            if (!is_array($config) || !isset($config[$k])) {
                return $default;
            }
            $config = $config[$k];
        }

        return $config;
    }
}

if (!function_exists('write_action_log')) {
    /**
     * 写入操作日志
     * @param string $action
     * @param string $content
     * @param array $extra
     */
    function write_action_log(string $action, string $content, array $extra = []): void
    {
        try {
            $data = array_merge([
                'action'     => $action,
                'content'    => $content,
                'ip'         => get_client_ip(),
                'user_id'    => request()->userId() ?? 0,
                'admin_id'   => request()->adminId() ?? 0,
                'url'        => request()->url(),
                'method'     => request()->method(),
                'user_agent' => request()->header('user-agent', ''),
                'create_time' => date('Y-m-d H:i:s'),
            ], $extra);

            Log::channel('action')->record(json_encode($data, JSON_UNESCAPED_UNICODE), 'info');
        } catch (\Throwable $e) {
            // 静默处理，不影响主流程
        }
    }
}

if (!function_exists('rate_limit_check')) {
    /**
     * 频控检查
     * @param string $key
     * @param int $limit
     * @param int $ttl
     * @return bool
     */
    function rate_limit_check(string $key, int $limit = 60, int $ttl = 60): bool
    {
        try {
            $redis = \think\facade\Cache::store('redis');
            $current = $redis->inc($key);
            if ($current === 1) {
                $redis->expire($key, $ttl);
            }
            return $current <= $limit;
        } catch (\Throwable $e) {
            // Redis 不可用时放行
            return true;
        }
    }
}

if (!function_exists('get_redis')) {
    /**
     * 获取 Redis 实例
     * @return \Redis
     */
    function get_redis(): \Redis
    {
        return \think\facade\Cache::store('redis')->handler();
    }
}

if (!function_exists('cache_get')) {
    /**
     * 读取缓存
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function cache_get(string $key, $default = null)
    {
        try {
            $cacheService = new \app\service\CacheService();
            return $cacheService->getTemp($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('cache_set')) {
    /**
     * 设置缓存
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return bool
     */
    function cache_set(string $key, $value, int $ttl = 3600): bool
    {
        try {
            $cacheService = new \app\service\CacheService();
            return $cacheService->setTemp($key, $value, $ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cache_del')) {
    /**
     * 删除缓存
     * @param string $key
     * @return bool
     */
    function cache_del(string $key): bool
    {
        try {
            $cacheService = new \app\service\CacheService();
            return $cacheService->delTemp($key);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cache_remember')) {
    /**
     * 记忆缓存，不存在则回调生成
     * @param string $key
     * @param callable $callback
     * @param int $ttl
     * @return mixed
     */
    function cache_remember(string $key, callable $callback, int $ttl = 3600)
    {
        try {
            $cacheService = new \app\service\CacheService();
            return $cacheService->remember($key, $callback, $ttl);
        } catch (\Throwable $e) {
            return $callback();
        }
    }
}