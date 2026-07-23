<?php
declare(strict_types=1);

namespace app\service;

use app\model\DispatchRecord;
use app\model\Order;
use app\model\PlayerProbeLog;
use app\model\User;
use app\model\WsConnection;
use app\model\PlayerTag;
use think\facade\Log;
use think\facade\Db;

/**
 * 打手派单服务
 * 负责打手权重计算、派单、拒单记录和活跃探针
 */
class PlayerDispatchService
{
    /**
     * 权重计算参数
     */
    private const WEIGHT_GOOD_RATE_MULTIPLIER   = 0.5;  // 好评率权重
    private const WEIGHT_SPEED_MULTIPLIER       = 0.3;  // 接单速度权重
    private const WEIGHT_ONLINE_TIME_MULTIPLIER = 0.2;  // 在线时长权重

    /**
     * 拒单免罚次数
     */
    private const REJECT_GRACE_COUNT = 3;

    /**
     * 活跃探针 - 连续无响应次数阈值
     */
    private const PROBE_FAIL_THRESHOLD = 3;

    /**
     * 活跃探针 Key 前缀
     */
    private const PROBE_KEY_PREFIX = 'player:probe:';

    /**
     * 计算打手权重分
     * 权重 = 好评率 × 0.5 + 接单速度 × 0.3 + 在线时长 × 0.2
     * @param int $playerId
     * @return float
     */
    public function calculateWeight(int $playerId): float
    {
        try {
            $player = User::where('id', $playerId)
                ->where('user_type', User::TYPE_PLAYER)
                ->find();

            if (!$player) {
                return 0.0;
            }

            // 1. 好评率（最近30天）
            $goodRate = $this->getGoodRate($playerId);

            // 2. 接单速度（平均响应时间，归一化到0-1）
            $speedScore = $this->getSpeedScore($playerId);

            // 3. 在线时长（归一化到0-1，基于最近30天在线总时长）
            $onlineTimeScore = $this->getOnlineTimeScore($playerId);

            $weight = $goodRate * self::WEIGHT_GOOD_RATE_MULTIPLIER
                    + $speedScore * self::WEIGHT_SPEED_MULTIPLIER
                    + $onlineTimeScore * self::WEIGHT_ONLINE_TIME_MULTIPLIER;

            $weight = round($weight, 4);

            // 缓存到 Redis
            $redis = get_redis();
            $redis->setex('player:weight:' . $playerId, 300, (string) $weight);

            Log::info("打手权重计算: player_id={$playerId}, weight={$weight}, goodRate={$goodRate}, speed={$speedScore}, online={$onlineTimeScore}");

            return $weight;
        } catch (\Throwable $e) {
            Log::error("打手权重计算失败: player_id={$playerId}, error={$e->getMessage()}");
            return 0.0;
        }
    }

    /**
     * 获取权重最高的前N名在线打手
     * @param string $gameType 游戏类型
     * @param int    $limit
     * @return array
     */
    public function getTopPlayers(string $gameType, int $limit = 10): array
    {
        try {
            $redis = get_redis();

            // 获取在线打手
            $wsService = new WebSocketService();
            $onlinePlayers = $wsService->getOnlinePlayers($gameType);

            if (empty($onlinePlayers)) {
                Log::info("无在线打手: gameType={$gameType}");
                return [];
            }

            $playerIds = array_column($onlinePlayers, 'id');
            $scores = [];

            foreach ($playerIds as $playerId) {
                // 优先从缓存读取权重
                $cached = $redis->get('player:weight:' . $playerId);
                if ($cached !== false) {
                    $scores[$playerId] = (float) $cached;
                } else {
                    $scores[$playerId] = $this->calculateWeight($playerId);
                }
            }

            // 按权重降序排序
            arsort($scores);

            // 取前N名
            $topPlayerIds = array_slice(array_keys($scores), 0, $limit);

            if (empty($topPlayerIds)) {
                return [];
            }

            $topPlayers = [];
            foreach ($topPlayerIds as $playerId) {
                foreach ($onlinePlayers as $player) {
                    if ($player['id'] == $playerId) {
                        $player['weight'] = $scores[$playerId];
                        $topPlayers[] = $player;
                        break;
                    }
                }
            }

            Log::info("获取Top打手: gameType={$gameType}, count=" . count($topPlayers));
            return $topPlayers;
        } catch (\Throwable $e) {
            Log::error("获取Top打手失败: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 派单给Top10打手
     * @param int $orderId
     * @return array
     */
    public function dispatchOrder(int $orderId): array
    {
        try {
            $order = Order::find($orderId);
            if (!$order) {
                throw new \RuntimeException("订单不存在: {$orderId}");
            }

            if ($order->status !== Order::STATUS_PENDING && $order->status !== Order::STATUS_DISPATCHING) {
                throw new \RuntimeException("订单状态不允许派单: {$order->status}");
            }

            $gameType = $order->game_name ?: '';
            $topPlayers = $this->getTopPlayers($gameType, 10);

            if (empty($topPlayers)) {
                Log::warning("无可派单打手: order_id={$orderId}");
                return [];
            }

            $dispatchRecords = [];
            $wsService = new WebSocketService();

            foreach ($topPlayers as $player) {
                $record = DispatchRecord::create([
                    'order_id'      => $orderId,
                    'player_id'     => $player['id'],
                    'dispatch_type' => DispatchRecord::TYPE_AUTO,
                    'status'        => DispatchRecord::STATUS_PENDING,
                    'dispatch_time' => date('Y-m-d H:i:s'),
                ]);

                $dispatchRecords[] = $record->toArray();

                // 推送派单通知给打手
                $wsService->pushToUser($player['id'], [
                    'event'   => 'order_dispatch',
                    'order_id'=> $orderId,
                    'order_sn'=> $order->order_sn,
                    'amount'  => $order->order_amount,
                    'game_name'=> $order->game_name,
                ]);
            }

            // 更新订单状态为派单中
            $order->status = Order::STATUS_DISPATCHING;
            $order->save();

            Log::info("派单完成: order_id={$orderId}, 派给 " . count($topPlayers) . " 名打手");
            return $dispatchRecords;
        } catch (\Throwable $e) {
            Log::error("派单失败: order_id={$orderId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 记录拒单
     * 前3次免罚，超过后降低权重
     * @param int $playerId
     * @return bool
     */
    public function recordReject(int $playerId): bool
    {
        try {
            $redis = get_redis();
            $key = 'player:reject:count:' . $playerId;

            $count = $redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, 86400); // 24小时过期
            }

            $isPenalty = $count > self::REJECT_GRACE_COUNT;

            if ($isPenalty) {
                // 降低权重
                $weightKey = 'player:weight:' . $playerId;
                $currentWeight = (float) $redis->get($weightKey);
                $newWeight = max(0, $currentWeight * 0.9); // 降低10%
                $redis->setex($weightKey, 300, (string) $newWeight);

                Log::warning("打手拒单超免罚次数: player_id={$playerId}, count={$count}, weight={$currentWeight}->{$newWeight}");
            } else {
                Log::info("打手拒单记录: player_id={$playerId}, count={$count} (免罚)");
            }

            return $isPenalty;
        } catch (\Throwable $e) {
            Log::error("记录拒单失败: player_id={$playerId}, error={$e->getMessage()}");
            return false;
        }
    }

    /**
     * 活跃探针
     * 每5分钟下发Ping-Pong，连续3次无响应标记非活跃
     * @return array 标记为非活跃的打手ID列表
     */
    public function probeActive(): array
    {
        $inactivePlayers = [];
        $redis = get_redis();

        try {
            $wsService = new WebSocketService();

            // 获取所有在线打手
            $onlinePlayers = $wsService->getOnlinePlayers();
            $playerIds = array_column($onlinePlayers, 'id');

            foreach ($playerIds as $playerId) {
                $key = self::PROBE_KEY_PREFIX . $playerId;
                $failCount = (int) $redis->get($key);

                // 发送Ping
                $sent = $wsService->pushToUser($playerId, [
                    'event' => 'probe_ping',
                    'time'  => time(),
                ]);

                if (!$sent) {
                    // 推送失败，增加失败计数
                    $failCount++;
                    $redis->setex($key, 1800, $failCount);

                    PlayerProbeLog::create([
                        'player_id' => $playerId,
                        'status'    => 0,
                        'fail_count'=> $failCount,
                    ]);

                    if ($failCount >= self::PROBE_FAIL_THRESHOLD) {
                        // 标记为非活跃
                        $wsService->markOffline($playerId);
                        $inactivePlayers[] = $playerId;

                        Log::warning("打手活跃探针失败: player_id={$playerId}, fail_count={$failCount}, 标记非活跃");
                    }
                } else {
                    // 重置失败计数
                    $redis->del($key);

                    PlayerProbeLog::create([
                        'player_id' => $playerId,
                        'status'    => 1,
                        'fail_count'=> 0,
                    ]);
                }
            }

            Log::info("活跃探针完成: 检测 " . count($playerIds) . " 人, 非活跃 " . count($inactivePlayers) . " 人");
            return $inactivePlayers;
        } catch (\Throwable $e) {
            Log::error("活跃探针执行失败: {$e->getMessage()}");
            return $inactivePlayers;
        }
    }

    /**
     * 计算好评率（最近30天）
     * @param int $playerId
     * @return float 0-1
     */
    private function getGoodRate(int $playerId): float
    {
        try {
            $total = \app\model\Evaluation::where('player_id', $playerId)
                ->where('create_time', '>=', date('Y-m-d H:i:s', time() - 2592000))
                ->count();

            if ($total == 0) {
                return 0.5; // 默认0.5
            }

            $good = \app\model\Evaluation::where('player_id', $playerId)
                ->where('create_time', '>=', date('Y-m-d H:i:s', time() - 2592000))
                ->where('score', '>=', 4)
                ->count();

            return round($good / $total, 4);
        } catch (\Throwable $e) {
            return 0.5;
        }
    }

    /**
     * 计算接单速度分数（归一化到0-1）
     * 基于平均响应时间
     * @param int $playerId
     * @return float
     */
    private function getSpeedScore(int $playerId): float
    {
        try {
            $records = DispatchRecord::where('player_id', $playerId)
                ->where('status', DispatchRecord::STATUS_ACCEPTED)
                ->where('response_time', '>', '0000-00-00 00:00:00')
                ->where('dispatch_time', '>', '0000-00-00 00:00:00')
                ->where('create_time', '>=', date('Y-m-d H:i:s', time() - 2592000))
                ->select();

            if ($records->isEmpty()) {
                return 0.5;
            }

            $totalSeconds = 0;
            $count = 0;
            foreach ($records as $record) {
                $dispatch = strtotime($record->dispatch_time);
                $response = strtotime($record->response_time);
                $diff = $response - $dispatch;
                if ($diff > 0 && $diff < 3600) { // 1小时内的接单
                    $totalSeconds += $diff;
                    $count++;
                }
            }

            if ($count == 0) {
                return 0.5;
            }

            $avgSeconds = $totalSeconds / $count;
            // 60秒内响应得满分，600秒以上得0分
            $score = 1 - min(1, max(0, ($avgSeconds - 60) / 540));

            return round($score, 4);
        } catch (\Throwable $e) {
            return 0.5;
        }
    }

    /**
     * 计算在线时长分数（归一化到0-1）
     * @param int $playerId
     * @return float
     */
    private function getOnlineTimeScore(int $playerId): float
    {
        try {
            $redis = get_redis();
            $key = 'player:online:duration:' . $playerId;
            $totalMinutes = (int) $redis->get($key);

            if ($totalMinutes <= 0) {
                // 从数据库计算
                $totalMinutes = \app\model\PlayerProbeLog::where('player_id', $playerId)
                    ->where('status', 1)
                    ->where('create_time', '>=', date('Y-m-d H:i:s', time() - 2592000))
                    ->count() * 5; // 每次探针间隔5分钟

                $redis->setex($key, 600, $totalMinutes);
            }

            // 每天在线8小时以上得满分
            $dailyMinutes = $totalMinutes / 30;
            $score = min(1, $dailyMinutes / 480);

            return round($score, 4);
        } catch (\Throwable $e) {
            return 0.5;
        }
    }

    private const TAG_WEIGHTS = [
        'game'     => 0.30,
        'rank'     => 0.25,
        'position' => 0.20,
        'voice'    => 0.15,
        'skill'    => 0.10,
    ];

    public function getMatchedPlayers(array $tagRequirements, int $limit = 10): array
    {
        try {
            $wsService = new WebSocketService();
            $gameFilter = $tagRequirements['game'] ?? [];
            $gameType = is_array($gameFilter) ? ($gameFilter[0] ?? '') : (string)$gameFilter;
            $onlinePlayers = $wsService->getOnlinePlayers($gameType);

            if (empty($onlinePlayers)) {
                Log::info("无在线打手: gameType={$gameType}");
                return [];
            }

            $playerIds = array_column($onlinePlayers, 'id');
            $scoredPlayers = [];

            foreach ($playerIds as $playerId) {
                $tagMatchScore = $this->calculateTagMatchScore($playerId, $tagRequirements);

                $redis = get_redis();
                $cachedWeight = $redis->get('player:weight:' . $playerId);
                $baseWeight = $cachedWeight !== false ? (float)$cachedWeight : $this->calculateWeight($playerId);

                $finalScore = $tagMatchScore * 0.6 + $baseWeight * 0.4;

                $scoredPlayers[] = [
                    'player_id'      => $playerId,
                    'match_score'    => round($tagMatchScore, 4),
                    'base_weight'    => round($baseWeight, 4),
                    'final_score'    => round($finalScore, 4),
                ];
            }

            usort($scoredPlayers, function ($a, $b) {
                return $b['final_score'] <=> $a['final_score'];
            });

            $topPlayers = array_slice($scoredPlayers, 0, $limit);

            $result = [];
            foreach ($topPlayers as $sp) {
                foreach ($onlinePlayers as $player) {
                    if ($player['id'] == $sp['player_id']) {
                        $player['match_score'] = $sp['match_score'];
                        $player['final_score'] = $sp['final_score'];
                        $result[] = $player;
                        break;
                    }
                }
            }

            Log::info("标签匹配派单: 条件=" . json_encode($tagRequirements) . ", 匹配数=" . count($result));
            return $result;
        } catch (\Throwable $e) {
            Log::error("标签匹配打手失败: {$e->getMessage()}");
            return [];
        }
    }

    public function calculateTagMatchScore(int $playerId, array $tagRequirements): float
    {
        try {
            $playerTags = $this->getPlayerTagsGrouped($playerId);
            $totalScore = 0.0;
            $totalWeight = 0.0;

            foreach (self::TAG_WEIGHTS as $tagType => $weight) {
                if (isset($tagRequirements[$tagType]) && !empty($tagRequirements[$tagType])) {
                    $required = (array)$tagRequirements[$tagType];
                    $playerTypeTags = $playerTags[$tagType] ?? [];

                    if (empty($playerTypeTags)) {
                        $matchRate = 0;
                    } else {
                        $intersect = array_intersect($required, $playerTypeTags);
                        $matchRate = count($intersect) / count($required);
                    }

                    $totalScore += $matchRate * $weight;
                    $totalWeight += $weight;
                }
            }

            if ($totalWeight > 0) {
                return round($totalScore / $totalWeight, 4);
            }
            return 1.0;
        } catch (\Throwable $e) {
            Log::error("计算标签匹配度失败: player_id={$playerId}, error={$e->getMessage()}");
            return 0.0;
        }
    }

    private function getPlayerTagsGrouped(int $playerId): array
    {
        try {
            $tags = PlayerTag::byPlayer($playerId)->select()->toArray();
            $grouped = [];
            foreach ($tags as $tag) {
                $type = $tag['tag_type'];
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [];
                }
                $grouped[$type][] = $tag['tag_value'];
            }
            return $grouped;
        } catch (\Throwable $e) {
            Log::error("获取打手标签失败: player_id={$playerId}, error={$e->getMessage()}");
            return [];
        }
    }

    public function dispatchOrderWithTags(int $orderId, array $tagRequirements): array
    {
        try {
            $order = Order::find($orderId);
            if (!$order) {
                throw new \RuntimeException("订单不存在: {$orderId}");
            }

            if ($order->status !== Order::STATUS_PENDING && $order->status !== Order::STATUS_DISPATCHING) {
                throw new \RuntimeException("订单状态不允许派单: {$order->status}");
            }

            $topPlayers = $this->getMatchedPlayers($tagRequirements, 10);

            if (empty($topPlayers)) {
                Log::warning("无匹配打手，回退普通派单: order_id={$orderId}");
                return $this->dispatchOrder($orderId);
            }

            $dispatchRecords = [];
            $wsService = new WebSocketService();

            foreach ($topPlayers as $player) {
                $record = DispatchRecord::create([
                    'order_id'      => $orderId,
                    'player_id'     => $player['id'],
                    'dispatch_type' => DispatchRecord::TYPE_AUTO,
                    'status'        => DispatchRecord::STATUS_PENDING,
                    'dispatch_time' => date('Y-m-d H:i:s'),
                    'match_score'   => $player['match_score'] ?? 0,
                ]);

                $dispatchRecords[] = $record->toArray();

                $wsService->pushToUser($player['id'], [
                    'event'      => 'order_dispatch',
                    'order_id'   => $orderId,
                    'order_sn'   => $order->order_sn,
                    'amount'     => $order->order_amount,
                    'game_name'  => $order->game_name,
                    'match_score'=> $player['match_score'] ?? 0,
                ]);
            }

            $order->status = Order::STATUS_DISPATCHING;
            $order->save();

            Log::info("标签派单完成: order_id={$orderId}, 派给 " . count($topPlayers) . " 名打手");
            return $dispatchRecords;
        } catch (\Throwable $e) {
            Log::error("标签派单失败: order_id={$orderId}, error={$e->getMessage()}");
            throw $e;
        }
    }
}