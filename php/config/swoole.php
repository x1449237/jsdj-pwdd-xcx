<?php
return [
    'host' => env('SWOOLE_HOST', '0.0.0.0'),
    'port' => (int)env('SWOOLE_PORT', 9501),
    'mode' => SWOOLE_PROCESS,
    'sock_type' => SWOOLE_SOCK_TCP,

    'settings' => [
        'worker_num' => (int)env('SWOOLE_WORKER_NUM', 4),
        'task_worker_num' => (int)env('SWOOLE_TASK_WORKER_NUM', 2),
        'daemonize' => (bool)env('SWOOLE_DAEMONIZE', false),
        'backlog' => 128,
        'dispatch_mode' => 2,
        'open_tcp_nodelay' => true,
        'heartbeat_check_interval' => 20,
        'heartbeat_idle_time' => 60,
        'max_connection' => 10000,
        'buffer_output_size' => 2 * 1024 * 1024,
        'package_max_length' => 2 * 1024 * 1024,
        'log_file' => runtime_path('swoole/swoole.log'),
        'log_level' => (int)env('SWOOLE_LOG_LEVEL', 2),
        'pid_file' => runtime_path('swoole/swoole.pid'),
        'reactor_num' => (int)env('SWOOLE_REACTOR_NUM', 4),
    ],

    'websocket' => [
        'heartbeat_interval' => 20,
        'heartbeat_timeout' => 60,
        'max_frame_size' => 65536,
        'room_max_users' => 500,
    ],

    'redis' => [
        'host' => env('REDIS.HOST', '127.0.0.1'),
        'port' => (int)env('REDIS.PORT', 6379),
        'password' => env('REDIS.PASSWORD', ''),
        'db' => (int)env('REDIS.SELECT', 0),
    ],
];
