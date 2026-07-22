<?php
declare(strict_types=1);

namespace app\websocket;

use Workerman\Connection\TcpConnection;
use app\service\ChatService;
use app\service\PlayerDispatchService;
use think\facade\Log;

/**
 * WebSocket 事件处理
 * 处理心跳、聊天、订单推送、系统消息等事件
 */
class Events
{
    /**
     * 心跳响应
     * @param TcpConnection $connection
     * @param array         $data
     */
    public function onPing(TcpConnection $connection, array $data): void
    {
        $pongData = [
            'type' => 'pong',
            'time' => time(),
            'server_time' => date('Y-m-d H:i:s'),
        ];

        $connection->send(json_encode($pongData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 聊天消息处理
     * @param TcpConnection $connection
     * @param array         $data
     */
    public function onChat(TcpConnection $connection, array $data): void
    {
        try {
            $sessionId = $data['session_id'] ?? 0;
            $senderId  = $data['sender_id'] ?? 0;
            $msgType   = $data['msg_type'] ?? 1; // 1-文字 2-图片 3-语音 4-视频
            $content   = $data['content'] ?? '';

            if (empty($sessionId) || empty($senderId) || empty($content)) {
                $connection->send(json_encode([
                    'type' => 'chat_response',
                    'code' => 400,
                    'msg'  => 'Missing required parameters',
                ]));
                return;
            }

            $chatService = new ChatService();
            $result = $chatService->sendMessage($sessionId, $senderId, $msgType, $content);

            $connection->send(json_encode([
                'type' => 'chat_response',
                'code' => 0,
                'msg'  => 'success',
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE));

            Log::info("Chat message processed: session_id={$sessionId}, sender_id={$senderId}, blocked={$result['is_blocked']}");
        } catch (\Throwable $e) {
            Log::error("Chat event error: " . $e->getMessage());
            $connection->send(json_encode([
                'type' => 'chat_response',
                'code' => 500,
                'msg'  => 'Chat service error',
            ]));
        }
    }

    /**
     * 订单推送处理
     * @param TcpConnection $connection
     * @param array         $data
     */
    public function onOrderPush(TcpConnection $connection, array $data): void
    {
        try {
            $action = $data['action'] ?? '';

            switch ($action) {
                case 'accept':
                    // 打手接单
                    $dispatchId = $data['dispatch_id'] ?? 0;
                    $playerId   = $data['player_id'] ?? 0;

                    if ($dispatchId > 0) {
                        $dispatchRecord = \app\model\DispatchRecord::find($dispatchId);
                        if ($dispatchRecord && $dispatchRecord->status === \app\model\DispatchRecord::STATUS_PENDING) {
                            $dispatchRecord->status = \app\model\DispatchRecord::STATUS_ACCEPTED;
                            $dispatchRecord->response_time = date('Y-m-d H:i:s');
                            $dispatchRecord->save();

                            // 更新订单状态
                            $order = \app\model\Order::find($dispatchRecord->order_id);
                            if ($order) {
                                $order->player_id = $playerId;
                                $order->status = \app\model\Order::STATUS_PLAYING;
                                $order->save();
                            }

                            $connection->send(json_encode([
                                'type' => 'order_response',
                                'code' => 0,
                                'msg'  => '接单成功',
                                'data' => ['dispatch_id' => $dispatchId, 'order_id' => $dispatchRecord->order_id],
                            ], JSON_UNESCAPED_UNICODE));

                            Log::info("Player accepted order: dispatch_id={$dispatchId}, player_id={$playerId}");
                        }
                    }
                    break;

                case 'reject':
                    // 打手拒单
                    $dispatchId = $data['dispatch_id'] ?? 0;
                    $playerId   = $data['player_id'] ?? 0;

                    if ($dispatchId > 0) {
                        $dispatchRecord = \app\model\DispatchRecord::find($dispatchId);
                        if ($dispatchRecord && $dispatchRecord->status === \app\model\DispatchRecord::STATUS_PENDING) {
                            $dispatchRecord->status = \app\model\DispatchRecord::STATUS_REJECTED;
                            $dispatchRecord->response_time = date('Y-m-d H:i:s');
                            $dispatchRecord->reject_reason = $data['reason'] ?? '';
                            $dispatchRecord->save();

                            // 记录拒单
                            $dispatchService = new PlayerDispatchService();
                            $dispatchService->recordReject($playerId);

                            $connection->send(json_encode([
                                'type' => 'order_response',
                                'code' => 0,
                                'msg'  => '已拒单',
                                'data' => ['dispatch_id' => $dispatchId],
                            ], JSON_UNESCAPED_UNICODE));

                            Log::info("Player rejected order: dispatch_id={$dispatchId}, player_id={$playerId}");
                        }
                    }
                    break;

                default:
                    $connection->send(json_encode([
                        'type' => 'order_response',
                        'code' => 400,
                        'msg'  => 'Unknown action: ' . $action,
                    ]));
            }
        } catch (\Throwable $e) {
            Log::error("Order push event error: " . $e->getMessage());
            $connection->send(json_encode([
                'type' => 'order_response',
                'code' => 500,
                'msg'  => 'Order service error',
            ]));
        }
    }

    /**
     * 系统消息处理
     * @param TcpConnection $connection
     * @param array         $data
     */
    public function onSystem(TcpConnection $connection, array $data): void
    {
        try {
            $action = $data['action'] ?? '';

            switch ($action) {
                case 'status':
                    // 查询系统状态
                    $redis = get_redis();
                    $connectionCount = $redis->sCard('ws:online:users') ?: 0;

                    $connection->send(json_encode([
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
                    // 消息通知确认
                    $notificationId = $data['notification_id'] ?? 0;
                    if ($notificationId > 0) {
                        \app\model\Notification::where('id', $notificationId)
                            ->update(['is_read' => 1, 'read_time' => date('Y-m-d H:i:s')]);

                        $connection->send(json_encode([
                            'type' => 'system_response',
                            'code' => 0,
                            'msg'  => '已标记已读',
                        ], JSON_UNESCAPED_UNICODE));
                    }
                    break;

                default:
                    $connection->send(json_encode([
                        'type' => 'system_response',
                        'code' => 400,
                        'msg'  => 'Unknown action: ' . $action,
                    ]));
            }
        } catch (\Throwable $e) {
            Log::error("System event error: " . $e->getMessage());
            $connection->send(json_encode([
                'type' => 'system_response',
                'code' => 500,
                'msg'  => 'System service error',
            ]));
        }
    }
}