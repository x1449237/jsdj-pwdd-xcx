<?php
// +----------------------------------------------------------------------
// | 中间件配置 - 全局中间件
// +----------------------------------------------------------------------

return [
    // 别名或分组
    'alias'    => [
        'auth'       => \app\middleware\Auth::class,
        'rate_limit' => \app\middleware\RateLimit::class,
        'api_version' => \app\middleware\ApiVersion::class,
        'cors'       => \app\middleware\Cors::class,
        'throttle'   => \think\middleware\Throttle::class,
    ],

    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [
        \app\middleware\Cors::class,
        \app\middleware\ApiVersion::class,
        \app\middleware\RateLimit::class,
        \app\middleware\Auth::class,
    ],

    // 全局中间件 - 所有请求都会经过
    'global' => [
        // 跨域中间件
        \app\middleware\Cors::class,
        // API版本控制
        \app\middleware\ApiVersion::class,
        // 频控中间件
        \app\middleware\RateLimit::class,
    ],
];