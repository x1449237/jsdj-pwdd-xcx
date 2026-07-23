<?php
declare(strict_types=1);

namespace app\service;

use app\model\OrderBid;
use app\model\Order as OrderModel;
use think\facade\Log;
use think\facade\Db;
use think\facade\Cache;

class OrderBidService
{
    private const BID_LOCK_PREFIX = 'order:bid:lock:';
    private const BID_LOCK_EXPIRE = 10;

    private const MIN_BID_STEP = 1;
    private const MAX_BIDDERS = 20;

    public function placeBid(int $orderId, int $playerId, string $bidPrice): array
    {
        try {
            $lockKey = self::BID_LOCK_PREFIX . $orderId;
            $redis = get_redis();

            $lockAcquired = $this->acquireLock($redis, $lockKey);
            if (!$lockAcquired) {
                throw new \RuntimeException('竞价繁忙，请稍后重试');
            }

            try {
                Db::startTrans();

                $order = OrderModel::find($orderId);
                if (!$order) {
                    throw new \RuntimeException('订单不存在');
                }
                if ($order->getData('status') != OrderModel::STATUS_DISPATCHING) {
                    throw new \RuntimeException('订单当前状态不可竞价');
                }

                $existingBid = OrderBid::byOrder($orderId)
                    ->byPlayer($playerId)
                    ->where('status', OrderBid::STATUS_BIDDING)
                    ->find();
                if ($existingBid) {
                    Db::rollback();
                    throw new \RuntimeException('您已参与竞价，不能重复竞价');
                }

                $currentHighest = $this->getHighestBid($orderId);
                $orderAmount = $order->getData('order_amount');

                $bidPriceFen = yuan_to_fen($bidPrice);

                if ($currentHighest > 0) {
                    if ($bidPriceFen <= $currentHighest) {
                        Db::rollback();
                        throw new \RuntimeException('竞价必须高于当前最高价');
                    }
                    $minStep = max(yuan_to_fen((string)self::MIN_BID_STEP), (int)($orderAmount * 0.01));
                    if ($bidPriceFen - $currentHighest < $minStep) {
                        Db::rollback();
                        throw new \RuntimeException('加价幅度不能低于' . fen_to_yuan($minStep) . '元');
                    }
                }

                $bidderCount = OrderBid::byOrder($orderId)
                    ->where('status', OrderBid::STATUS_BIDDING)
                    ->count();
                if ($bidderCount >= self::MAX_BIDDERS) {
                    Db::rollback();
                    throw new \RuntimeException('竞价人数已达上限');
                }

                $bid = OrderBid::create([
                    'order_id'       => $orderId,
                    'player_user_id' => $playerId,
                    'bid_price'      => $bidPrice,
                    'bid_time'       => date('Y-m-d H:i:s'),
                    'status'         => OrderBid::STATUS_BIDDING,
                    'is_winner'      => 0,
                ]);

                Db::commit();

                return [
                    'bid_id'         => $bid->id,
                    'order_id'       => $orderId,
                    'bid_price'      => $bidPrice,
                    'current_highest'=> $bidPrice,
                    'bidder_count'   => $bidderCount + 1,
                ];
            } finally {
                $this->releaseLock($redis, $lockKey);
            }
        } catch (\Throwable $e) {
            Log::error("竞价失败: order_id={$orderId}, player_id={$playerId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    public function getHighestBid(int $orderId): int
    {
        try {
            $highest = OrderBid::byOrder($orderId)
                ->where('status', OrderBid::STATUS_BIDDING)
                ->order('bid_price', 'desc')
                ->find();
            return $highest ? (int)$highest->getData('bid_price') : 0;
        } catch (\Throwable $e) {
            Log::error("获取最高价失败: order_id={$orderId}, error={$e->getMessage()}");
            return 0;
        }
    }

    public function getBidList(int $orderId): array
    {
        try {
            $bids = OrderBid::byOrder($orderId)
                ->order('bid_price', 'desc')
                ->select()
                ->toArray();

            foreach ($bids as &$bid) {
                $player = \app\model\User::find($bid['player_user_id']);
                if ($player) {
                    $bid['player_nickname'] = $player->nickname;
                    $bid['player_avatar']   = $player->avatar;
                }
            }

            return $bids;
        } catch (\Throwable $e) {
            Log::error("获取竞价列表失败: order_id={$orderId}, error={$e->getMessage()}");
            return [];
        }
    }

    public function getPlayerBids(int $playerId, int $page = 1, int $limit = 10): array
    {
        try {
            $query = OrderBid::byPlayer($playerId)
                ->order('create_time', 'desc');

            $total = $query->count();
            $list = $query->page($page, $limit)->select()->toArray();

            return [
                'list'  => $list,
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $e) {
            Log::error("获取打手竞价记录失败: player_id={$playerId}, error={$e->getMessage()}");
            return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }
    }

    public function selectWinner(int $orderId, int $playerId): bool
    {
        try {
            Db::startTrans();

            $winnerBid = OrderBid::byOrder($orderId)
                ->byPlayer($playerId)
                ->where('status', OrderBid::STATUS_BIDDING)
                ->find();

            if (!$winnerBid) {
                Db::rollback();
                throw new \RuntimeException('该打手未参与竞价');
            }

            OrderBid::byOrder($orderId)
                ->where('status', OrderBid::STATUS_BIDDING)
                ->where('player_user_id', '<>', $playerId)
                ->update([
                    'status'    => OrderBid::STATUS_LOSER,
                    'is_winner' => 0,
                ]);

            $winnerBid->status = OrderBid::STATUS_WINNER;
            $winnerBid->is_winner = 1;
            $winnerBid->save();

            $order = OrderModel::find($orderId);
            if ($order) {
                $newAmount = fen_to_yuan((int)$winnerBid->getData('bid_price'));
                $order->order_amount = $newAmount;
                $order->paid_amount  = $newAmount;
                $order->player_id    = $playerId;
                $order->status       = OrderModel::STATUS_PAID;
                $order->save();
            }

            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("选定中标者失败: order_id={$orderId}, player_id={$playerId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    public function cancelBid(int $orderId, int $playerId): bool
    {
        try {
            $bid = OrderBid::byOrder($orderId)
                ->byPlayer($playerId)
                ->where('status', OrderBid::STATUS_BIDDING)
                ->find();

            if (!$bid) {
                throw new \RuntimeException('竞价记录不存在');
            }

            $bid->status = OrderBid::STATUS_CANCELED;
            $bid->save();
            return true;
        } catch (\Throwable $e) {
            Log::error("取消竞价失败: order_id={$orderId}, player_id={$playerId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    private function acquireLock($redis, string $key): bool
    {
        $requestId = uniqid('bid_', true);
        $result = $redis->set($key, $requestId, ['nx', 'ex' => self::BID_LOCK_EXPIRE]);
        return (bool)$result;
    }

    private function releaseLock($redis, string $key): void
    {
        $script = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
        try {
            $redis->eval($script, [$key, ''], 1);
        } catch (\Throwable $e) {
            Log::warning("释放竞价锁失败: key={$key}, error={$e->getMessage()}");
        }
    }
}
