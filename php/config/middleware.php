<?php
// +----------------------------------------------------------------------
// | 中间件配置 - 全局中间件
// +----------------------------------------------------------------------

return [
    // 别名或分组
    'alias'    => [
        'auth'       => \app\middleware\Auth::class,
        'rate_limit' => \app\middleware\RateLimit::class,
        'api_rate_limit' => \app\middleware\ApiRateLimit::class,
        'api_version' => \app\middleware\ApiVersion::class,
        'cors'       => \app\middleware\Cors::class,
        'throttle'   => \think\middleware\Throttle::class,
        'slow_query_log' => \app\middleware\SlowQueryLog::class,
        'auth_player' => \app\middleware\PlayerAuth::class,
        'auth_distributor' => \app\middleware\DistributorAuth::class,
        'auth_dispatcher' => \app\middleware\DispatcherAuth::class,
        'auth_admin' => \app\middleware\AdminAuth::class,
    ],

    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [
        \app\middleware\Cors::class,
        \app\middleware\ApiVersion::class,
        \app\middleware\ApiRateLimit::class,
        \app\middleware\SlowQueryLog::class,
        \app\middleware\Auth::class,
    ],

    // 全局中间件 - 所有请求都会经过
    'global' => [
        // 跨域中间件
        \app\middleware\Cors::class,
        // API版本控制
        \app\middleware\ApiVersion::class,
        // 接口限流中间件
        \app\middleware\ApiRateLimit::class,
        // 慢查询日志中间件
        \app\middleware\SlowQueryLog::class,
    ],
];