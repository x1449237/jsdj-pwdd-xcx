<?php
// +----------------------------------------------------------------------
// | 路由配置
// +----------------------------------------------------------------------

return [
    // 路由中间件
    'middleware' => [
        // API 路由组中间件
        'api' => [
            \app\middleware\Cors::class,
            \app\middleware\ApiVersion::class,
            \app\middleware\RateLimit::class,
        ],
        // 需要认证的路由（JWT user）
        'auth' => [
            \app\middleware\Auth::class,
        ],
        // 管理员认证（JWT admin）
        'auth_admin' => [
            \app\middleware\Auth::class,
        ],
        // 打手身份校验（JWT user + player）
        'auth_player' => [
            \app\middleware\Auth::class,
        ],
        // 分销商身份校验（JWT user + distributor）
        'auth_distributor' => [
            \app\middleware\Auth::class,
        ],
        // 派单员身份校验（JWT user + dispatcher）
        'auth_dispatcher' => [
            \app\middleware\Auth::class,
        ],
    ],

    // 是否强制使用路由
    'url_route_must'         => true,

    // 合并路由规则
    'route_rule_merge'       => true,

    // 路由是否完全匹配
    'route_complete_match'   => false,

    // 访问控制器层名称
    'controller_layer'       => 'controller',

    // 空控制器名
    'empty_controller'       => 'Error',

    // 是否使用控制器后缀
    'controller_suffix'      => false,

    // 默认的路由变量规则
    'default_route_pattern'  => '[\w\.]+',

    // 是否开启请求缓存
    'request_cache'          => false,

    // 请求缓存有效期
    'request_cache_expire'   => 3600,

    // 全局请求缓存排除规则
    'request_cache_except'   => [
        '/api/v1/user/login',
        '/api/v1/admin/login',
        '/api/v1/upload/*',
    ],

    // 默认控制器名
    'default_controller'     => 'Index',

    // 默认操作名
    'default_action'         => 'index',

    // 操作方法后缀
    'action_suffix'          => '',

    // 默认JSONP格式返回的处理方法
    'default_jsonp_handler'  => 'jsonpReturn',

    // 默认JSONP处理方法
    'var_jsonp_handler'      => 'callback',
];