<?php
// +----------------------------------------------------------------------
// | 缓存配置 - Redis
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => env('CACHE_DRIVER', 'redis'),

    // 缓存连接方式配置
    'stores'  => [
        // 文件缓存
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => runtime_path('cache'),
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize'  => [],
        ],

        // Redis 缓存
        'redis' => [
            // 驱动方式
            'type'       => 'redis',
            // 服务器地址
            'host'       => env('REDIS.HOST', '127.0.0.1'),
            // 端口
            'port'       => env('REDIS.PORT', 6379),
            // 密码
            'password'   => env('REDIS.PASSWORD', ''),
            // 数据库索引
            'select'     => env('REDIS.SELECT', 0),
            // 超时时间
            'timeout'    => env('REDIS.TIMEOUT', 0),
            // 是否持久连接
            'persistent' => env('REDIS.PERSISTENT', false),
            // 缓存前缀
            'prefix'     => env('REDIS.PREFIX', 'gp_cache:'),
            // 序列化机制
            'serialize'  => ['serialize', 'json'],
            // 缓存有效期
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 连接池配置
            'pool'       => [
                'enable'       => true,
                'min'          => env('REDIS_POOL_MIN', 5),
                'max'          => env('REDIS_POOL_MAX', 50),
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
                'wait_timeout' => 3,
            ],
        ],

        // 更多缓存连接...
    ],

    // 缓存键名分隔符
    'separator' => ':',

    // 默认缓存标签
    'default_tag' => 'default',
];