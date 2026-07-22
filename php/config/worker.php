<?php
// +----------------------------------------------------------------------
// | Workerman 配置 - WebSocket 服务
// +----------------------------------------------------------------------

return [
    // 监听地址
    'host' => '0.0.0.0',

    // 监听端口
    'port' => 2345,

    // 进程名称
    'name' => 'game-platform-worker',

    // Worker 进程数
    'count' => 4,

    // 是否以守护进程方式运行
    'daemonize' => false,

    // 标准输出重定向
    'stdout_file' => runtime_path('worker/stdout.log'),

    // 进程 PID 文件
    'pid_file' => runtime_path('worker/worker.pid'),

    // 日志文件
    'log_file' => runtime_path('worker/worker.log'),

    // 心跳检测间隔（秒）
    'heartbeat' => 25,

    // 超时时间（秒）- 超过此时间无心跳则断开
    'timeout' => 70,

    // 最大连接数
    'max_connections' => 10000,

    // 每个进程最大请求数（达到后重启）
    'max_requests' => 100000,

    // 传输层协议
    'transport' => 'tcp',

    // 上下文选项
    'context' => [
        'ssl' => [
            'local_cert'  => '',
            'local_pk'    => '',
            'verify_peer' => false,
        ],
    ],

    // 自定义进程
    'process' => [
        // 定时任务进程
        'cron' => [
            'handler' => \app\worker\CronTask::class,
            'count'   => 1,
        ],
        // 消息队列消费进程
        'queue' => [
            'handler' => \app\worker\QueueConsumer::class,
            'count'   => 2,
        ],
    ],

    // 事件回调
    'event' => [
        // 连接建立
        'onConnect' => function ($connection) {
            // 记录连接
            \think\facade\Log::info('Worker connection established: ' . $connection->id);
        },

        // 接收消息
        'onMessage' => function ($connection, $data) {
            // 解析消息
            $message = json_decode($data, true);
            if (!$message) {
                $connection->send(json_encode([
                    'type' => 'error',
                    'msg'  => 'Invalid message format',
                ]));
                return;
            }

            // 消息路由
            $type = $message['type'] ?? '';
            switch ($type) {
                case 'ping':
                    $connection->send(json_encode(['type' => 'pong', 'time' => time()]));
                    break;
                case 'auth':
                    // 处理认证
                    $connection->auth = $message['token'] ?? '';
                    $connection->send(json_encode([
                        'type' => 'auth',
                        'code' => 0,
                        'msg'  => '认证成功',
                    ]));
                    break;
                default:
                    $connection->send(json_encode([
                        'type' => 'response',
                        'data' => $message,
                    ]));
            }
        },

        // 连接关闭
        'onClose' => function ($connection) {
            \think\facade\Log::info('Worker connection closed: ' . $connection->id);
        },

        // Worker 启动
        'onWorkerStart' => function ($worker) {
            \think\facade\Log::info('Worker started: ' . $worker->id);
        },

        // Worker 停止
        'onWorkerStop' => function ($worker) {
            \think\facade\Log::info('Worker stopped: ' . $worker->id);
        },

        // 错误
        'onError' => function ($connection, $code, $msg) {
            \think\facade\Log::error("Worker error: {$code} - {$msg}");
        },
    ],

    // WebSocket 握手时的回调
    'onWebSocketConnect' => function ($connection, $header) {
        // 可以在这里做连接认证
        $get = $connection->get();
        $token = $get['token'] ?? '';
        if (empty($token)) {
            \think\facade\Log::warning('WebSocket connect without token');
        }
    },
];