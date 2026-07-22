<?php
// +----------------------------------------------------------------------
// | 数据库配置 - 支持连接池与读写分离
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    // 默认使用的数据库连接
    'default'     => env('DATABASE.TYPE', 'mysql'),

    // 数据库连接方式
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type'            => env('DATABASE.TYPE', 'mysql'),
            // 服务器地址
            'hostname'        => env('DATABASE.HOSTNAME', '127.0.0.1'),
            // 数据库名
            'database'        => env('DATABASE.DATABASE', 'game_platform'),
            // 用户名
            'username'        => env('DATABASE.USERNAME', 'root'),
            // 密码
            'password'        => env('DATABASE.PASSWORD', ''),
            // 端口
            'hostport'        => env('DATABASE.HOSTPORT', '3306'),
            // 数据库连接参数
            'params'          => [
                // 强制列名为指定大小写
                \PDO::ATTR_CASE              => \PDO::CASE_NATURAL,
                // 抛出异常
                \PDO::ATTR_ERRMODE           => \PDO::ERRMODE_EXCEPTION,
                // 默认提取模式
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // 使用原生预处理语句
                \PDO::ATTR_EMULATE_PREPARES  => false,
                // 字符集
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ],
            // 数据库编码
            'charset'         => env('DATABASE.CHARSET', 'utf8mb4'),
            // 数据库表前缀
            'prefix'          => env('DATABASE.PREFIX', 'gp_'),
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 1,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => true,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',

            // 数据库调试模式
            'debug'           => env('DATABASE.DEBUG', true),

            // 是否严格检查字段是否存在
            'fields_strict'   => true,

            // 是否需要进行SQL性能分析
            'sql_explain'     => false,

            // 是否需要断线重连
            'break_reconnect' => true,

            // 断线标识字符串
            'break_match_str' => [
                'server has gone away',
                'no connection to the server',
                'Lost connection',
                'is dead or not enabled',
                'Error while sending',
                'decryption failed or bad record mac',
                'server closed the connection unexpectedly',
                'SSL connection has been closed unexpectedly',
                'Error writing data to the connection',
                'Resource deadlock avoided',
                'Transaction() on null',
                'child connection forced to terminate due to client_idle_limit',
                'query_wait_timeout',
                'reset by peer',
            ],

            // 数据库连接池配置
            'pool' => [
                // 是否开启连接池
                'enable'        => true,
                // 最小连接数
                'min'           => env('DB_POOL_MIN', 5),
                // 最大连接数
                'max'           => env('DB_POOL_MAX', 50),
                // 空闲超时时间（秒）
                'idle_timeout'  => env('DB_POOL_IDLE_TIMEOUT', 60),
                // 连接最大存活时间（秒）
                'max_lifetime'  => env('DB_POOL_MAX_LIFETIME', 3600),
                // 获取连接超时时间（秒）
                'wait_timeout'  => 3,
            ],
        ],

        // 从库连接配置（读写分离）
        'slave' => [
            'type'            => 'mysql',
            'hostname'        => env('DB_READ_HOST', '127.0.0.1'),
            'database'        => env('DATABASE.DATABASE', 'game_platform'),
            'username'        => env('DB_READ_USER', 'root'),
            'password'        => env('DB_READ_PASSWORD', ''),
            'hostport'        => env('DB_READ_PORT', '3306'),
            'charset'         => 'utf8mb4',
            'prefix'          => env('DATABASE.PREFIX', 'gp_'),
            'deploy'          => 0,
            'rw_separate'     => false,
            'debug'           => false,
            'break_reconnect' => true,
            'params'          => [
                \PDO::ATTR_CASE              => \PDO::CASE_NATURAL,
                \PDO::ATTR_ERRMODE           => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES  => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ],
        ],
    ],

    // 查询缓存配置
    'query_cache' => [
        'enable'  => true,
        'expire'  => 60,
        'cache'   => 'cache',
        'tag'     => 'db_query',
    ],
];