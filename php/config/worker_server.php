<?php
// +----------------------------------------------------------------------
// | Workerman 服务配置 - think-worker 集成
// | WebSocket 监听地址、进程数、定时任务等
// +----------------------------------------------------------------------

return [
    // ========== 基础配置 ==========

    // 是否启用 Workerman
    'enable' => true,

    // 服务类型：websocket / http
    'server' => 'websocket',

    // WebSocket 监听地址（0.0.0.0 表示监听所有网络接口）
    'host' => '0.0.0.0',

    // WebSocket 监听端口
    'port' => 2345,

    // 进程名称（用于 ps aux 查看）
    'name' => 'GamePlatformWorker',

    // Worker 进程数（建议设为 CPU 核心数）
    'count' => 4,

    // 是否需要守护进程（生产环境建议 true）
    'daemonize' => false,

    // ========== 协议配置 ==========

    // 通信协议
    'protocol' => 'websocket',

    // 每个进程最大请求数（达到后重启，防止内存泄漏）
    'max_request' => 100000,

    // 最大数据包大小（10MB）
    'max_package_size' => 10485760,

    // ========== 连接配置 ==========

    'connection' => [
        // 心跳间隔（秒），客户端在此时间内无任何数据则发送心跳
        'heartbeat_idle_time'      => 25,

        // 心跳超时（秒），超过此时间无心跳则断开连接
        'heartbeat_check_interval' => 70,

        // 最大连接数
        'max_connection'           => 10000,

        // 连接超时（秒）
        'connect_timeout'          => 30,

        // 每个 IP 最大连接数
        'max_connection_per_ip'    => 100,
    ],

    // ========== SSL/TLS 配置 ==========

    'ssl' => [
        // 是否启用 SSL
        'enable'    => false,

        // SSL 证书文件路径（PEM 格式）
        'cert_file' => '',

        // SSL 私钥文件路径
        'key_file'  => '',

        // 是否验证客户端证书
        'verify_peer' => false,
    ],

    // ========== 进程 ID 文件 ==========

    'pid_file' => runtime_path('worker/worker_server.pid'),

    // ========== 日志配置 ==========

    // 标准输出文件
    'stdout_file' => runtime_path('worker/server_stdout.log'),

    // 日志文件
    'log_file' => runtime_path('worker/server.log'),

    // 日志级别：debug / info / notice / warning / error
    'log_level' => 'info',

    // ========== 文件监控（开发环境） ==========

    'file_monitor' => false,

    // 文件监控间隔（秒）
    'file_monitor_interval' => 2,

    // 监控的文件扩展名
    'static_extension' => 'js,css,jpg,jpeg,gif,png,ico,svg',

    // ========== 自定义进程 ==========

    'process' => [
        // 定时任务进程
        'cron' => [
            // 处理器类
            'handler' => \app\worker\CronTask::class,

            // 进程数
            'count'   => 1,

            // 定时任务配置
            'tasks'   => [
                // 订单超时处理（每分钟）
                [
                    'name'     => 'order_timeout',
                    'interval' => 60,
                    'handler'  => \app\command\OrderTimeout::class,
                ],
                // 订单自动结算（每5分钟）
                [
                    'name'     => 'order_settle',
                    'interval' => 300,
                    'handler'  => \app\command\OrderSettle::class,
                ],
                // 申诉催办提醒（每30分钟）
                [
                    'name'     => 'appeal_reminder',
                    'interval' => 1800,
                    'handler'  => \app\command\AppealReminder::class,
                ],
                // 监控告警（每分钟）
                [
                    'name'     => 'monitor_alert',
                    'interval' => 60,
                    'handler'  => \app\command\MonitorAlert::class,
                ],
                // 每日备份（每天凌晨2点）
                [
                    'name'     => 'daily_backup',
                    'interval' => 86400,
                    'at'       => '02:00',
                    'handler'  => \app\command\DailyBackup::class,
                ],
                // NTP 时间同步（每小时）
                [
                    'name'     => 'ntp_sync',
                    'interval' => 3600,
                    'handler'  => \app\command\NtpSync::class,
                ],
                // 第三方接口重试（每2分钟）
                [
                    'name'     => 'third_party_retry',
                    'interval' => 120,
                    'handler'  => \app\command\ThirdPartyRetry::class,
                ],
                // 打手探测（每30秒）
                [
                    'name'     => 'player_probe',
                    'interval' => 30,
                    'handler'  => \app\command\PlayerProbe::class,
                ],
            ],
        ],

        // 消息队列消费进程
        'queue' => [
            'handler' => \app\worker\QueueConsumer::class,
            'count'   => 2,

            // 队列配置
            'queues'  => [
                'chat_message'     => ['name' => 'chat_message_queue', 'delay' => 0],
                'order_notify'     => ['name' => 'order_notify_queue', 'delay' => 0],
                'asr_process'      => ['name' => 'asr_process_queue', 'delay' => 0],
                'ocr_process'      => ['name' => 'ocr_process_queue', 'delay' => 0],
                'push_notification' => ['name' => 'push_notification_queue', 'delay' => 0],
            ],
        ],

        // WebSocket 事件处理进程
        'websocket' => [
            'handler' => \app\websocket\Events::class,
            'count'   => 1,
        ],
    ],

    // ========== 上下文配置 ==========

    // 上下文配置（传递给 Worker 的额外参数）
    'context' => [
        // SSL 上下文选项（当 ssl.enable = true 时使用）
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ],

    // ========== 业务配置 ==========

    // WebSocket 路由前缀
    'ws_prefix' => '/ws',

    // 允许的 Origin（跨域白名单）
    'allowed_origins' => [
        '*',
    ],

    // 是否启用 WebSocket 认证
    'ws_auth' => true,

    // WebSocket 认证 token 参数名
    'ws_token_param' => 'token',
];