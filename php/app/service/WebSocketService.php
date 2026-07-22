<?php
declare(strict_types=1);

namespace app\service;

use app\model\WsConnection;
use app\model\User;
use think\facade\Log;

/**
 * WebSocket 服务
 * 使用 Redis Pub/Sub 实现跨进程消息推送
 */
class WebSocketService
{
    /**
     * Redis Pub/Sub 频道前缀
     */
    private const CHANNEL_PREFIX = 'ws:push:';

    /**
     * 在线用户集合 Key
     */
    private const ONLINE_SET_KEY = 'ws:online:users';

    /**
     * 打手在线集合 Key
     */
    private const ONLINE_PLAYER_PREFIX = 'ws:online:players:';

    /**
     * 推送消息给指定用户
     * @param int   $userId
     * @param array $data
     * @return bool
     */
    public function pushToUser(int $userId, array $data): bool
    {
        try {
            $redis = get_redis();
            $message = json_encode([
                'type' => 'push',
                'data' => $data,
                'time' => time(),
            ], JSON_UNESCAPED_UNICODE);

            $channel = self::CHANNEL_PREFIX . 'user:' . $userId;
            $redis->publish($channel, $message);

            Log::info("WebSocket push to user {$userId}: " . json_encode($data));
            return true;
        } catch (\Throwable $e) {
            Log::error("WebSocket pushToUser error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 批量推送消息给多个用户
     * @param array $userIds
     * @param array $data
     * @return bool
     */
    public function pushToUsers(array $userIds, array $data): bool
    {
        try {
            $redis = get_redis();
            $message = json_encode([
                'type' => 'push',
                'data' => $data,
                'time' => time(),
            ], JSON_UNESCAPED_UNICODE);

            foreach ($userIds as $userId) {
                $channel = self::CHANNEL_PREFIX . 'user:' . $userId;
                $redis->publish($channel, $message);
            }

            Log::info("WebSocket push to " . count($userIds) . " users");
            return true;
        } catch (\Throwable $e) {
            Log::error("WebSocket pushToUsers error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 全员推送
     * @param array $data
     * @return bool
     */
    public function pushToAll(array $data): bool
    {
        try {
            $redis = get_redis();
            $message = json_encode([
                'type' => 'broadcast',
                'data' => $data,
                'time' => time(),
            ], JSON_UNESCAPED_UNICODE);

            $channel = self::CHANNEL_PREFIX . 'all';
            $redis->publish($channel, $message);

            Log::info("WebSocket push to all");
            return true;
        } catch (\Throwable $e) {
            Log::error("WebSocket pushToAll error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 广播给打手
     * @param array       $data
     * @param string|null $gameType 游戏类型，null 为全部
     * @return bool
     */
    public function broadcastToPlayers(array $data, ?string $gameType = null): bool
    {
        try {
            $redis = get_redis();
            $message = json_encode([
                'type' => 'order_dispatch',
                'data' => $data,
                'time' => time(),
            ], JSON_UNESCAPED_UNICODE);

            if ($gameType) {
                $channel = self::CHANNEL_PREFIX . 'players:' . $gameType;
                $redis->publish($channel, $message);
            } else {
                // 广播给全类型打手频道
                $channel = self::CHANNEL_PREFIX . 'players:all';
                $redis->publish($channel, $message);
            }

            Log::info("WebSocket broadcast to players, gameType=" . ($gameType ?? 'all'));
            return true;
        } catch (\Throwable $e) {
            Log::error("WebSocket broadcastToPlayers error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 检查用户在线状态
     * @param int $userId
     * @return bool
     */
    public function isOnline(int $userId): bool
    {
        try {
            $redis = get_redis();
            return $redis->sIsMember(self::ONLINE_SET_KEY, (string) $userId);
        } catch (\Throwable $e) {
            Log::error("WebSocket isOnline error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取在线打手列表
     * @param string|null $gameType 游戏类型，null 为全部
     * @return array
     */
    public function getOnlinePlayers(?string $gameType = null): array
    {
        try {
            $redis = get_redis();

            if ($gameType) {
                $key = self::ONLINE_PLAYER_PREFIX . $gameType;
                $playerIds = $redis->sMembers($key);
            } else {
                // 获取所有游戏类型的打手并去重
                $keys = $redis->keys(self::ONLINE_PLAYER_PREFIX . '*');
                $playerIds = [];
                foreach ($keys as $key) {
                    $ids = $redis->sMembers($key);
                    $playerIds = array_merge($playerIds, $ids);
                }
                $playerIds = array_unique($playerIds);
            }

            $playerIds = array_map('intval', $playerIds);

            if (empty($playerIds)) {
                return [];
            }

            $players = User::whereIn('id', $playerIds)
                ->where('user_type', User::TYPE_PLAYER)
                ->field('id,nickname,avatar,level')
                ->select()
                ->toArray();

            return $players;
        } catch (\Throwable $e) {
            Log::error("WebSocket getOnlinePlayers error: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 标记用户上线
     * @param int    $userId
     * @param string $gameType
     * @return bool
     */
    public function markOnline(int $userId, string $gameType = ''): bool
    {
        try {
            $redis = get_redis();
            $redis->sAdd(self::ONLINE_SET_KEY, (string) $userId);

            if ($gameType) {
                $redis->sAdd(self::ONLINE_PLAYER_PREFIX . $gameType, (string) $userId);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("WebSocket markOnline error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 标记用户下线
     * @param int    $userId
     * @param string $gameType
     * @return bool
     */
    public function markOffline(int $userId, string $gameType = ''): bool
    {
        try {
            $redis = get_redis();
            $redis->sRem(self::ONLINE_SET_KEY, (string) $userId);

            if ($gameType) {
                $redis->sRem(self::ONLINE_PLAYER_PREFIX . $gameType, (string) $userId);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("WebSocket markOffline error: {$e->getMessage()}");
            return false;
        }
    }
}