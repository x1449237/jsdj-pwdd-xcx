<?php
declare(strict_types=1);

namespace app\swoole;

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\Timer;
use think\facade\Log;
use app\swoole\Handler\ConnectionHandler;
use app\swoole\Handler\MessageHandler;
use app\service\JwtService;
use app\service\WebSocketService;

class WebSocketServer
{
    private $server;

    private $host = '0.0.0.0';
    private $port = 9501;
    private $workerNum = 4;

    private const HEARTBEAT_INTERVAL = 20;
    private const HEARTBEAT_TIMEOUT = 60;

    private static $connections = [];
    private static $rooms = [];

    private $connectionHandler;
    private $messageHandler;

    public function __construct()
    {
        $this->host = config_get('swoole.host', '0.0.0.0');
        $this->port = config_get('swoole.port', 9501);
        $this->workerNum = config_get('swoole.worker_num', 4);

        $this->server = new Server($this->host, $this->port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

        $this->server->set([
            'worker_num' => $this->workerNum,
            'heartbeat_check_interval' => self::HEARTBEAT_INTERVAL,
            'heartbeat_idle_time' => self::HEARTBEAT_TIMEOUT,
            'daemonize' => config_get('swoole.daemonize', false),
            'log_file' => runtime_path('logs/swoole.log'),
            'log_level' => SWOOLE_LOG_INFO,
            'pid_file' => runtime_path('swoole.pid'),
            'open_tcp_nodelay' => true,
            'buffer_output_size' => 2 * 1024 * 1024,
            'package_max_length' => 2 * 1024 * 1024,
            'max_connection' => 10000,
        ]);

        $this->connectionHandler = new ConnectionHandler();
        $this->messageHandler = new MessageHandler();

        $this->registerCallbacks();
    }

    private function registerCallbacks(): void
    {
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('workerStart', [$this, 'onWorkerStart']);
        $this->server->on('open', [$this, 'onOpen']);
        $this->server->on('message', [$this, 'onMessage']);
        $this->server->on('close', [$this, 'onClose']);
        $this->server->on('request', [$this, 'onRequest']);
    }

    public function onStart(Server $server): void
    {
        Log::info("Swoole WebSocket server started: host={$this->host}, port={$this->port}");
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        Log::info("Worker {$workerId} started");

        if ($workerId === 0) {
            Timer::tick(self::HEARTBEAT_INTERVAL * 1000, function () use ($server) {
                $this->checkHeartbeat($server);
            });
        }

        $this->startRedisSubscribe($server, $workerId);
    }

    public function onOpen(Server $server, Request $request): void
    {
        $fd = $request->fd;
        $ip = $request->server['remote_addr'] ?? '0.0.0.0';

        Log::info("Client connected: fd={$fd}, ip={$ip}");

        $token = $request->get['token'] ?? '';
        $gameType = $request->get['game_type'] ?? '';

        if (empty($token)) {
            $server->push($fd, json_encode([
                'type' => 'auth',
                'code' => 401,
                'msg'  => 'Token is required',
            ]));
            $server->close($fd);
            return;
        }

        try {
            $jwtService = new JwtService();
            $payload = $jwtService->verifyToken($token);

            if (!$payload) {
                $server->push($fd, json_encode([
                    'type' => 'auth',
                    'code' => 401,
                    'msg'  => 'Token invalid or expired',
                ]));
                $server->close($fd);
                return;
            }

            $userId = (int)($payload['sub'] ?? 0);

            self::$connections[$fd] = [
                'user_id'         => $userId,
                'fd'              => $fd,
                'game_type'       => $gameType,
                'last_heartbeat'  => time(),
                'is_authenticated' => true,
                'ip'              => $ip,
                'rooms'           => [],
            ];

            $wsService = new WebSocketService();
            $wsService->markOnline($userId, $gameType);

            $server->push($fd, json_encode([
                'type' => 'auth',
                'code' => 0,
                'msg'  => '认证成功',
                'data' => ['user_id' => $userId],
            ]));

            Log::info("WebSocket authenticated: user_id={$userId}, fd={$fd}");
        } catch (\Throwable $e) {
            Log::error("WebSocket auth error: " . $e->getMessage());
            $server->push($fd, json_encode([
                'type' => 'auth',
                'code' => 500,
                'msg'  => '认证服务异常',
            ]));
            $server->close($fd);
        }
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $data = $frame->data;

        try {
            $message = json_decode($data, true);
            if (!$message) {
                $server->push($fd, json_encode([
                    'type' => 'error',
                    'msg'  => 'Invalid message format',
                ]));
                return;
            }

            $type = $message['type'] ?? '';
            $payload = $message['data'] ?? [];
            $connInfo = self::$connections[$fd] ?? null;

            if (!$connInfo || !$connInfo['is_authenticated']) {
                $server->push($fd, json_encode([
                    'type' => 'error',
                    'msg'  => 'Not authenticated',
                ]));
                return;
            }

            self::$connections[$fd]['last_heartbeat'] = time();

            switch ($type) {
                case 'ping':
                    $this->messageHandler->onPing($server, $fd, $connInfo, $payload);
                    break;

                case 'chat':
                    $this->messageHandler->onChat($server, $fd, $connInfo, $payload);
                    break;

                case 'group_chat':
                    $this->messageHandler->onGroupChat($server, $fd, $connInfo, $payload);
                    break;

                case 'after_sale':
                    $this->messageHandler->onAfterSale($server, $fd, $connInfo, $payload);
                    break;

                case 'order_push':
                    $this->messageHandler->onOrderPush($server, $fd, $connInfo, $payload);
                    break;

                case 'system':
                    $this->messageHandler->onSystem($server, $fd, $connInfo, $payload);
                    break;

                case 'join_room':
                    $this->joinRoom($fd, $payload['room_id'] ?? '');
                    break;

                case 'leave_room':
                    $this->leaveRoom($fd, $payload['room_id'] ?? '');
                    break;

                default:
                    $server->push($fd, json_encode([
                        'type' => 'error',
                        'msg'  => "Unknown message type: {$type}",
                    ]));
            }
        } catch (\Throwable $e) {
            Log::error("WebSocket onMessage error: " . $e->getMessage());
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode([
                    'type' => 'error',
                    'msg'  => 'Internal server error',
                ]));
            }
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        $connInfo = self::$connections[$fd] ?? null;

        if ($connInfo && $connInfo['is_authenticated']) {
            $userId = $connInfo['user_id'];
            $gameType = $connInfo['game_type'];

            foreach ($connInfo['rooms'] as $roomId) {
                $this->leaveRoom($fd, $roomId);
            }

            $wsService = new WebSocketService();
            $wsService->markOffline($userId, $gameType);

            Log::info("Client disconnected: user_id={$userId}, fd={$fd}");
        } else {
            Log::info("Client disconnected: fd={$fd} (unauthenticated)");
        }

        unset(self::$connections[$fd]);
    }

    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response): void
    {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'code' => 0,
            'msg'  => 'Swoole WebSocket server is running',
            'data' => [
                'connections' => count(self::$connections),
                'server_time' => date('Y-m-d H:i:s'),
            ],
        ]));
    }

    private function checkHeartbeat(Server $server): void
    {
        $now = time();
        $timeoutFds = [];

        foreach (self::$connections as $fd => $info) {
            if ($now - $info['last_heartbeat'] > self::HEARTBEAT_TIMEOUT) {
                $timeoutFds[] = $fd;
            }
        }

        foreach ($timeoutFds as $fd) {
            Log::warning("Connection timeout: fd={$fd}");
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode([
                    'type' => 'timeout',
                    'msg'  => 'Connection timeout',
                ]));
                $server->close($fd);
            }
        }
    }

    private function startRedisSubscribe(Server $server, int $workerId): void
    {
        if ($workerId !== 0) {
            return;
        }

        $redis = new \Swoole\Redis();
        go(function () use ($redis, $server) {
            try {
                $redis->connect(
                    config_get('cache.stores.redis.host', '127.0.0.1'),
                    (int)config_get('cache.stores.redis.port', 6379)
                );

                $password = config_get('cache.stores.redis.password', '');
                if ($password) {
                    $redis->auth($password);
                }

                $select = (int)config_get('cache.stores.redis.select', 0);
                if ($select > 0) {
                    $redis->select($select);
                }

                $channels = [
                    'ws:push:all',
                    'ws:push:players:all',
                    'ws:push:user:*',
                    'ws:push:room:*',
                ];

                $redis->subscribe($channels, function ($redis, $channel, $message) use ($server) {
                    $this->handleRedisMessage($server, $channel, $message);
                });
            } catch (\Throwable $e) {
                Log::error("Swoole Redis subscribe error: " . $e->getMessage());
            }
        });
    }

    private function handleRedisMessage(Server $server, string $channel, string $message): void
    {
        try {
            $data = json_decode($message, true);
            if (!$data) {
                return;
            }

            $messageText = json_encode($data, JSON_UNESCAPED_UNICODE);

            if (strpos($channel, 'ws:push:all') !== false) {
                foreach (self::$connections as $fd => $info) {
                    if ($server->isEstablished($fd)) {
                        $server->push($fd, $messageText);
                    }
                }
            } elseif (strpos($channel, 'ws:push:players:') !== false) {
                foreach (self::$connections as $fd => $info) {
                    if ($info['is_authenticated']) {
                        if ($server->isEstablished($fd)) {
                            $server->push($fd, $messageText);
                        }
                    }
                }
            } elseif (strpos($channel, 'ws:push:user:') !== false) {
                $parts = explode(':', $channel);
                $userId = (int)end($parts);
                foreach (self::$connections as $fd => $info) {
                    if ($info['user_id'] === $userId) {
                        if ($server->isEstablished($fd)) {
                            $server->push($fd, $messageText);
                        }
                    }
                }
            } elseif (strpos($channel, 'ws:push:room:') !== false) {
                $parts = explode(':', $channel);
                $roomId = end($parts);
                $this->sendToRoom($server, $roomId, $messageText);
            }
        } catch (\Throwable $e) {
            Log::error("Handle redis message error: " . $e->getMessage());
        }
    }

    private function joinRoom(int $fd, string $roomId): void
    {
        if (empty($roomId)) {
            return;
        }

        if (!isset(self::$rooms[$roomId])) {
            self::$rooms[$roomId] = [];
        }

        self::$rooms[$roomId][$fd] = $fd;
        self::$connections[$fd]['rooms'][] = $roomId;
    }

    private function leaveRoom(int $fd, string $roomId): void
    {
        if (empty($roomId) || !isset(self::$rooms[$roomId])) {
            return;
        }

        unset(self::$rooms[$roomId][$fd]);

        if (empty(self::$rooms[$roomId])) {
            unset(self::$rooms[$roomId]);
        }

        $key = array_search($roomId, self::$connections[$fd]['rooms'] ?? []);
        if ($key !== false) {
            unset(self::$connections[$fd]['rooms'][$key]);
        }
    }

    private function sendToRoom(Server $server, string $roomId, string $message): void
    {
        if (!isset(self::$rooms[$roomId])) {
            return;
        }

        foreach (self::$rooms[$roomId] as $fd) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, $message);
            }
        }
    }

    public static function getConnection(int $fd): ?array
    {
        return self::$connections[$fd] ?? null;
    }

    public static function getAllConnections(): array
    {
        return self::$connections;
    }

    public static function getConnectionsByUserId(int $userId): array
    {
        $result = [];
        foreach (self::$connections as $fd => $info) {
            if ($info['user_id'] === $userId) {
                $result[] = $info;
            }
        }
        return $result;
    }

    public function start(): void
    {
        $this->server->start();
    }
}
