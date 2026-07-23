<?php
declare(strict_types=1);

namespace app\swoole\Handler;

use Swoole\WebSocket\Server;
use think\facade\Log;
use app\service\ChatService;
use app\service\GroupChatService;
use app\service\AfterSaleService;
use app\service\PlayerDispatchService;
use app\model\DispatchRecord;
use app\model\Order;
use app\model\Notification;

class MessageHandler
{
    public function onPing(Server $server, int $fd, array $connInfo, array $data): void
    {
        $pongData = [
            'type' => 'pong',
            'time' => time(),
            'server_time' => date('Y-m-d H:i:s'),
        ];

        $server->push($fd, json_encode($pongData, JSON_UNESCAPED_UNICODE));
    }

    public function onChat(Server $server, int $fd, array $connInfo, array $data): void
    {
        try {
            $sessionId = $data['session_id'] ?? 0;
            $senderId  = $data['sender_id'] ?? 0;
            $msgType   = $data['msg_type'] ?? 1;
            $content   = $data['content'] ?? '';

            if (empty($sessionId) || empty($senderId) || empty($content)) {
                $server->push($fd, json_encode([
                    'type' => 'chat_response',
                    'code' => 400,
                    'msg'  => 'Missing required parameters',
                ]));
                return;
            }

            $chatService = new ChatService();
            $result = $chatService->sendMessage($sessionId, $senderId, $msgType, $content);

            $server->push($fd, json_encode([
                'type' => 'chat_response',
                'code' => 0,
                'msg'  => 'success',
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE));

            Log::info("Chat message processed: session_id={$sessionId}, sender_id={$senderId}, blocked={$result['is_blocked']}");
        } catch (\Throwable $e) {
            Log::error("Chat event error: " . $e->getMessage());
            $server->push($fd, json_encode([
                'type' => 'chat_response',
                'code' => 500,
                'msg'  => 'Chat service error',
            ]));
        }
    }

    public function onGroupChat(Server $server, int $fd, array $connInfo, array $data): void
    {
        try {
            $groupId    = $data['group_id'] ?? 0;
            $senderId   = $data['sender_id'] ?? 0;
            $senderType = $data['sender_type'] ?? 1;
            $msgType    = $data['msg_type'] ?? 1;
            $content    = $data['content'] ?? '';

            if (empty($groupId) || empty($senderId) || empty($content)) {
                $server->push($fd, json_encode([
                    'type' => 'group_chat_response',
                    'code' => 400,
                    'msg'  => 'Missing required parameters',
                ]));
                return;
            }

            $groupChatService = new GroupChatService();
            $result = $groupChatService->sendGroupMessage($groupId, $senderId, $senderType, $msgType, $content);

            $server->push($fd, json_encode([
                'type' => 'group_chat_response',
                'code' => 0,
                'msg'  => 'success',
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE));

            Log::info("Group chat message: group_id={$groupId}, sender_id={$senderId}");
        } catch (\Throwable $e) {
            Log::error("Group chat event error: " . $e->getMessage());
            $server->push($fd, json_encode([
                'type' => 'group_chat_response',
                'code' => 500,
                'msg'  => 'Group chat service error',
            ]));
        }
    }

    public function onAfterSale(Server $server, int $fd, array $connInfo, array $data): void
    {
        try {
            $sessionId  = $data['session_id'] ?? 0;
            $senderId   = $data['sender_id'] ?? 0;
            $senderType = $data['sender_type'] ?? 1;
            $msgType    = $data['msg_type'] ?? 1;
            $content    = $data['content'] ?? '';

            if (empty($sessionId) || empty($senderId) || empty($content)) {
                $server->push($fd, json_encode([
                    'type' => 'after_sale_response',
                    'code' => 400,
                    'msg'  => 'Missing required parameters',
                ]));
                return;
            }

            $afterSaleService = new AfterSaleService();
            $result = $afterSaleService->sendMessage($sessionId, $senderId, $senderType, $msgType, $content);

            $server->push($fd, json_encode([
                'type' => 'after_sale_response',
                'code' => 0,
                'msg'  => 'success',
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE));

            Log::info("After sale message: session_id={$sessionId}, sender_id={$senderId}");
        } catch (\Throwable $e) {
            Log::error("After sale event error: " . $e->getMessage());
            $server->push($fd, json_encode([
                'type' => 'after_sale_response',
                'code' => 500,
                'msg'  => 'After sale service error',
            ]));
        }
    }

    public function onOrderPush(Server $server, int $fd, array $connInfo, array $data): void
    {
        try {
            $action = $data['action'] ?? '';

            switch ($action) {
                case 'accept':
                    $this->handleAcceptOrder($server, $fd, $connInfo, $data);
                    break;

                case 'reject':
                    $this->handleRejectOrder($server, $fd, $connInfo, $data);
                    break;

                default:
                    $server->push($fd, json_encode([
                        'type' => 'order_response',
                        'code' => 400,
                        'msg'  => 'Unknown action: ' . $action,
                    ]));
            }
        } catch (\Throwable $e) {
            Log::error("Order push event error: " . $e->getMessage());
            $server->push($fd, json_encode([
                'type' => 'order_response',
                'code' => 500,
                'msg'  => 'Order service error',
            ]));
        }
    }

    private function handleAcceptOrder(Server $server, int $fd, array $connInfo, array $data): void
    {
        $dispatchId = $data['dispatch_id'] ?? 0;
        $playerId   = $data['player_id'] ?? 0;

        if ($dispatchId > 0) {
            $dispatchRecord = DispatchRecord::find($dispatchId);
            if ($dispatchRecord && $dispatchRecord->status === DispatchRecord::STATUS_PENDING) {
                $dispatchRecord->status = DispatchRecord::STATUS_ACCEPTED;
                $dispatchRecord->response_time = date('Y-m-d H:i:s');
                $dispatchRecord->save();

                $order = Order::find($dispatchRecord->order_id);
                if ($order) {
                    $order->player_id = $playerId;
                    $order->status = Order::STATUS_PLAYING;
                    $order->save();
                }

                $server->push($fd, json_encode([
                    'type' => 'order_response',
                    'code' => 0,
                    'msg'  => '接单成功',
                    'data' => ['dispatch_id' => $dispatchId, 'order_id' => $dispatchRecord->order_id],
                ], JSON_UNESCAPED_UNICODE));

                Log::info("Player accepted order: dispatch_id={$dispatchId}, player_id={$playerId}");
            }
        }
    }

    private function handleRejectOrder(Server $server, int $fd, array $connInfo, array $data): void
    {
        $dispatchId = $data['dispatch_id'] ?? 0;
        $playerId   = $data['player_id'] ?? 0;

        if ($dispatchId > 0) {
            $dispatchRecord = DispatchRecord::find($dispatchId);
            if ($dispatchRecord && $dispatchRecord->status === DispatchRecord::STATUS_PENDING) {
                $dispatchRecord->status = DispatchRecord::STATUS_REJECTED;
                $dispatchRecord->response_time = date('Y-m-d H:i:s');
                $dispatchRecord->reject_reason = $data['reason'] ?? '';
                $dispatchRecord->save();

                $dispatchService = new PlayerDispatchService();
                $dispatchService->recordReject($playerId);

                $server->push($fd, json_encode([
                    'type' => 'order_response',
                    'code' => 0,
                    'msg'  => '已拒单',
                    'data' => ['dispatch_id' => $dispatchId],
                ], JSON_UNESCAPED_UNICODE));

                Log::info("Player rejected order: dispatch_id={$dispatchId}, player_id={$playerId}");
            }
        }
    }

    public function onSystem(Server $server, int $fd, array $connInfo, array $data): void
    {
        try {
            $action = $data['action'] ?? '';

            switch ($action) {
                case 'status':
                    $redis = get_redis();
                    $connectionCount = $redis->sCard('ws:online:users') ?: 0;

                    $server->push($fd, json_encode([
                        'type' => 'system_response',
                        'code' => 0,
                        'msg'  => 'success',
                        'data' => [
                            'online_users'  => $connectionCount,
                            'server_time'   => date('Y-m-d H:i:s'),
                            'version'       => '1.0.0',
                        ],
                    ], JSON_UNESCAPED_UNICODE));
                    break;

                case 'notification':
                    $notificationId = $data['notification_id'] ?? 0;
                    if ($notificationId > 0) {
                        Notification::where('id', $notificationId)
                            ->update(['is_read' => 1, 'read_time' => date('Y-m-d H:i:s')]);

                        $server->push($fd, json_encode([
                            'type' => 'system_response',
                            'code' => 0,
                            'msg'  => '已标记已读',
                        ], JSON_UNESCAPED_UNICODE));
                    }
                    break;

                default:
                    $server->push($fd, json_encode([
                        'type' => 'system_response',
                        'code' => 400,
                        'msg'  => 'Unknown action: ' . $action,
                    ]));
            }
        } catch (\Throwable $e) {
            Log::error("System event error: " . $e->getMessage());
            $server->push($fd, json_encode([
                'type' => 'system_response',
                'code' => 500,
                'msg'  => 'System service error',
            ]));
        }
    }
}
