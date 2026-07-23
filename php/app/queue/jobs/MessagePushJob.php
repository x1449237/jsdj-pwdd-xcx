<?php
declare(strict_types=1);

namespace app\queue\jobs;

use think\facade\Log;
use think\facade\Cache;

class MessagePushJob
{
    public function handle(array $data): bool
    {
        $type = $data['type'] ?? 'all';
        $target = $data['target'] ?? '';
        $message = $data['message'] ?? [];

        if (empty($message)) {
            Log::error("MessagePushJob: message is empty");
            return false;
        }

        try {
            $redis = Cache::store('redis')->handler();
            $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE);

            switch ($type) {
                case 'all':
                    $redis->publish('ws:push:all', $messageJson);
                    Log::info("MessagePushJob: push to all");
                    break;

                case 'user':
                    $userId = (int)$target;
                    if ($userId > 0) {
                        $redis->publish('ws:push:user:' . $userId, $messageJson);
                        Log::info("MessagePushJob: push to user, user_id={$userId}");
                    }
                    break;

                case 'players':
                    $redis->publish('ws:push:players:all', $messageJson);
                    Log::info("MessagePushJob: push to all players");
                    break;

                case 'room':
                    $roomId = $target;
                    if (!empty($roomId)) {
                        $redis->publish('ws:push:room:' . $roomId, $messageJson);
                        Log::info("MessagePushJob: push to room, room_id={$roomId}");
                    }
                    break;

                default:
                    Log::warning("MessagePushJob: unknown push type, type={$type}");
                    return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("MessagePushJob error: " . $e->getMessage());
            return false;
        }
    }
}
