<?php
declare(strict_types=1);

namespace app\websocket;

use Workerman\Worker as WorkermanServer;
use Workerman\Connection\TcpConnection;
use app\service\JwtService;
use app\service\WebSocketService;
use think\facade\Log;

/**
 * WebSocket Worker 主进程
 * 负责连接管理、心跳检测、消息分发
 */
class Worker
{
    /**
     * @var WorkermanServer
     */
    private $worker;

    /**
     * @var int 心跳间隔（秒）
     */
    private const HEARTBEAT_INTERVAL = 25;

    /**
     * @var int 超时时间（秒）
     */
    private const TIMEOUT = 70;

    /**
     * @var array 连接信息映射 [connection_id => [user_id, last_heartbeat, game_type]]
     */
    private static $connections = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->worker = new WorkermanServer('websocket://0.0.0.0:2345');
        $this->worker->count = 4;
        $this->worker->name = 'game-platform-worker';

        $this->registerCallbacks();
    }

    /**
     * 注册回调
     */
    private function registerCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    /**
     * Worker 启动时初始化
     * @param WorkermanServer $worker
     */
    public function onWorkerStart(WorkermanServer $worker): void
    {
        Log::info("Worker process started: pid=" . getmypid() . ", worker_id=" . $worker->id);

        // 启动心跳定时器
        \Workerman\Timer::add(self::HEARTBEAT_INTERVAL, function () use ($worker) {
            $this->checkHeartbeat();
        });

        // 启动 Redis Pub/Sub 订阅
        $this->startRedisSubscribe($worker);
    }

    /**
     * 客户端连接
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection): void
    {
        Log::info("Client connected: connection_id=" . $connection->id . ", ip=" . $connection->getRemoteIp());

        self::$connections[$connection->id] = [
            'user_id'        => 0,
            'last_heartbeat' => time(),
            'game_type'      => '',
            'is_authenticated' => false,
        ];
    }

    /**
     * WebSocket 握手（验证 token）
     * @param TcpConnection $connection
     * @param array         $httpHeader
     */
    public function onWebSocketConnect(TcpConnection $connection, $httpHeader): void
    {
        try {
            // 从 URL 参数获取 token
            $get = $connection->get();
            $token = $get['token'] ?? '';

            // 也可以从 Header 获取
            if (empty($token)) {
                $token = $httpHeader['sec-websocket-protocol'] ?? '';
            }

            if (empty($token)) {
                Log::warning("WebSocket connect without token: connection_id=" . $connection->id);
                $connection->send(json_encode([
                    'type' => 'auth',
                    'code' => 401,
                    'msg'  => 'Token is required',
                ]));
                $connection->close();
                return;
            }

            // 验证 JWT
            $jwtService = new JwtService();
            $payload = $jwtService->verifyToken($token);

            if (!$payload) {
                Log::warning("WebSocket auth failed: token invalid, connection_id=" . $connection->id);
                $connection->send(json_encode([
                    'type' => 'auth',
                    'code' => 401,
                    'msg'  => 'Token invalid or expired',
                ]));
                $connection->close();
                return;
            }

            $userId = (int) ($payload['sub'] ?? 0);
            $gameType = $get['game_type'] ?? '';

            self::$connections[$connection->id] = [
                'user_id'         => $userId,
                'last_heartbeat'  => time(),
                'game_type'       => $gameType,
                'is_authenticated' => true,
            ];

            // 标记在线
            $wsService = new WebSocketService();
            $wsService->markOnline($userId, $gameType);

            $connection->send(json_encode([
                'type' => 'auth',
                'code' => 0,
                'msg'  => '认证成功',
                'data' => ['user_id' => $userId],
            ]));

            Log::info("WebSocket authenticated: user_id={$userId}, connection_id=" . $connection->id);
        } catch (\Throwable $e) {
            Log::error("WebSocket auth error: " . $e->getMessage());
            $connection->send(json_encode([
                'type' => 'auth',
                'code' => 500,
                'msg'  => '认证服务异常',
            ]));
            $connection->close();
        }
    }

    /**
     * 接收消息处理
     * @param TcpConnection $connection
     * @param string        $data
     */
    public function onMessage(TcpConnection $connection, $data): void
    {
        try {
            $message = json_decode($data, true);
            if (!$message) {
                $connection->send(json_encode([
                    'type' => 'error',
                    'msg'  => 'Invalid message format',
                ]));
                return;
            }

            $type = $message['type'] ?? '';
            $payload = $message['data'] ?? [];

            $events = new Events();
            $connInfo = self::$connections[$connection->id] ?? null;

            switch ($type) {
                case 'ping':
                    $events->onPing($connection, $payload);
                    break;

                case 'chat':
                    $events->onChat($connection, $payload);
                    break;

                case 'order_push':
                    $events->onOrderPush($connection, $payload);
                    break;

                case 'system':
                    $events->onSystem($connection, $payload);
                    break;

                case 'pong':
                    // 更新心跳时间
                    if ($connInfo) {
                        self::$connections[$connection->id]['last_heartbeat'] = time();
                    }
                    $connection->send(json_encode([
                        'type' => 'pong_ack',
                        'time' => time(),
                    ]));
                    break;

                default:
                    $connection->send(json_encode([
                        'type' => 'error',
                        'msg'  => "Unknown message type: {$type}",
                    ]));
            }
        } catch (\Throwable $e) {
            Log::error("WebSocket onMessage error: " . $e->getMessage());
            $connection->send(json_encode([
                'type' => 'error',
                'msg'  => 'Internal server error',
            ]));
        }
    }

    /**
     * 断开连接
     * @param TcpConnection $connection
     */
    public function onClose(TcpConnection $connection): void
    {
        $connInfo = self::$connections[$connection->id] ?? null;

        if ($connInfo && $connInfo['is_authenticated']) {
            $userId = $connInfo['user_id'];
            $gameType = $connInfo['game_type'];

            // 标记离线
            $wsService = new WebSocketService();
            $wsService->markOffline($userId, $gameType);

            Log::info("Client disconnected: user_id={$userId}, connection_id=" . $connection->id);
        } else {
            Log::info("Client disconnected: connection_id=" . $connection->id . " (unauthenticated)");
        }

        unset(self::$connections[$connection->id]);
    }

    /**
     * 心跳检测
     * 检查所有连接，超时未心跳的断开
     */
    private function checkHeartbeat(): void
    {
        $now = time();
        $timeoutConnections = [];

        foreach (self::$connections as $connectionId => $info) {
            if ($now - $info['last_heartbeat'] > self::TIMEOUT) {
                $timeoutConnections[] = $connectionId;
            }
        }

        foreach ($timeoutConnections as $connectionId) {
            Log::warning("Connection timeout: connection_id={$connectionId}, last_heartbeat=" . self::$connections[$connectionId]['last_heartbeat']);

            // 尝试关闭连接
            foreach ($this->worker->connections as $conn) {
                if ($conn->id === $connectionId) {
                    $conn->send(json_encode([
                        'type' => 'timeout',
                        'msg'  => 'Connection timeout',
                    ]));
                    $conn->close();
                    break;
                }
            }
        }
    }

    /**
     * 启动 Redis Pub/Sub 订阅
     * 监听消息推送频道
     * @param WorkermanServer $worker
     */
    private function startRedisSubscribe(WorkermanServer $worker): void
    {
        // 仅在第一个 worker 进程启动订阅
        if ($worker->id !== 0) {
            return;
        }

        $redis = get_redis();

        // 在新进程中订阅
        $pid = pcntl_fork();
        if ($pid == -1) {
            Log::error("Redis subscribe fork failed");
            return;
        }

        if ($pid == 0) {
            // 子进程：订阅 Redis 频道
            $subscribeRedis = new \Redis();
            try {
                $subscribeRedis->connect(
                    config_get('redis.host', '127.0.0.1'),
                    (int) config_get('redis.port', 6379)
                );

                if ($password = config_get('redis.password', '')) {
                    $subscribeRedis->auth($password);
                }

                $subscribeRedis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

                // 订阅推送频道
                $channels = [
                    'ws:push:all',
                    'ws:push:players:all',
                ];

                $subscribeRedis->subscribe($channels, function ($redis, $channel, $message) {
                    $this->handleRedisMessage($channel, $message);
                });
            } catch (\Throwable $e) {
                Log::error("Redis subscribe error: " . $e->getMessage());
            }
            exit(0);
        }

        // 父进程记录子进程 PID
        Log::info("Redis subscribe process started: pid={$pid}");
    }

    /**
     * 处理 Redis 订阅消息
     * @param string $channel
     * @param string $message
     */
    private function handleRedisMessage(string $channel, string $message): void
    {
        try {
            $data = json_decode($message, true);
            if (!$data) {
                return;
            }

            $messageText = json_encode($data, JSON_UNESCAPED_UNICODE);

            // 根据频道类型分发
            if (strpos($channel, 'ws:push:all') !== false) {
                // 全员推送
                foreach ($this->worker->connections as $conn) {
                    $conn->send($messageText);
                }
            } elseif (strpos($channel, 'ws:push:players:') !== false) {
                // 打手推送
                foreach ($this->worker->connections as $conn) {
                    $connInfo = self::$connections[$conn->id] ?? null;
                    if ($connInfo && $connInfo['is_authenticated']) {
                        $conn->send($messageText);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error("Handle redis message error: " . $e->getMessage());
        }
    }

    /**
     * 启动 Worker
     */
    public function run(): void
    {
        WorkermanServer::runAll();
    }
}