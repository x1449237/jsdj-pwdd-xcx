<?php
declare(strict_types=1);

namespace app\service;

use app\model\InviteBindLog;
use app\model\InviteCode;
use app\model\Order;
use app\model\DistributorCommission;
use app\model\DistributorFirstReward;
use app\model\User;
use think\facade\Log;
use think\facade\Db;

/**
 * 分销服务
 * 负责分销关系绑定（强制2级）、佣金计算、首单奖励和佣金结算
 */
class DistributorService
{
    /**
     * 一级佣金比例
     */
    private const LEVEL1_COMMISSION_RATE = '0.10';

    /**
     * 二级佣金比例
     */
    private const LEVEL2_COMMISSION_RATE = '0.05';

    /**
     * 首单奖励金额（元）
     */
    private const FIRST_REWARD_AMOUNT = '50.00';

    /**
     * 首单奖励条件：下级完成实名认证 + 3单真实服务
     */
    private const FIRST_REWARD_MIN_ORDERS = 3;

    /**
     * 佣金结算锁前缀
     */
    private const SETTLE_LOCK_PREFIX = 'distributor:settle:lock:';

    /**
     * 绑定分销关系（强制2级）
     * @param int    $userId     被邀请人ID
     * @param string $inviteCode 邀请码
     * @return array
     * @throws \RuntimeException
     */
    public function bindRelation(int $userId, string $inviteCode): array
    {
        try {
            // 查找邀请码
            $code = InviteCode::where('code', $inviteCode)
                ->where('status', 1)
                ->find();

            if (!$code) {
                throw new \RuntimeException('邀请码无效');
            }

            $inviterId = $code->user_id;

            // 不能自己邀请自己
            if ($inviterId == $userId) {
                throw new \RuntimeException('不能邀请自己');
            }

            // 检查是否已绑定
            $existing = InviteBindLog::where('user_id', $userId)->find();
            if ($existing) {
                throw new \RuntimeException('已绑定过邀请关系');
            }

            // 查找邀请人的上级（二级）
            $level2Id = 0;
            $level1Bind = InviteBindLog::where('user_id', $inviterId)->find();
            if ($level1Bind) {
                $level2Id = $level1Bind->inviter_id;
            }

            Db::startTrans();
            try {
                // 绑定一级关系
                InviteBindLog::create([
                    'user_id'    => $userId,
                    'inviter_id' => $inviterId,
                    'level'      => 1,
                    'invite_code'=> $inviteCode,
                ]);

                // 绑定二级关系（如果存在）
                if ($level2Id > 0) {
                    InviteBindLog::create([
                        'user_id'    => $userId,
                        'inviter_id' => $level2Id,
                        'level'      => 2,
                        'invite_code'=> $inviteCode,
                    ]);
                }

                Db::commit();

                Log::info("分销关系绑定: user_id={$userId}, level1={$inviterId}, level2={$level2Id}");

                return [
                    'user_id'    => $userId,
                    'level1_id'  => $inviterId,
                    'level2_id'  => $level2Id,
                ];
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error("绑定分销关系失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 计算分销佣金
     * @param int $orderId
     * @return array
     */
    public function calculateCommission(int $orderId): array
    {
        try {
            $order = Order::find($orderId);
            if (!$order) {
                return [];
            }

            $amount = $order->paid_amount;
            $commissions = [];

            // 查找分销关系
            $bindLogs = InviteBindLog::where('user_id', $order->user_id)
                ->order('level', 'asc')
                ->select();

            foreach ($bindLogs as $bind) {
                $rate = $bind->level == 1
                    ? self::LEVEL1_COMMISSION_RATE
                    : self::LEVEL2_COMMISSION_RATE;

                $commissionAmount = bc_mul($amount, $rate, 2);

                if (bc_comp($commissionAmount, '0.01', 2) >= 0) {
                    $commission = DistributorCommission::create([
                        'order_id'   => $orderId,
                        'user_id'    => $bind->inviter_id,
                        'from_user_id'=> $order->user_id,
                        'level'      => $bind->level,
                        'amount'     => $commissionAmount,
                        'rate'       => $rate,
                        'status'     => 0, // 待结算
                    ]);

                    $commissions[] = $commission->toArray();
                }
            }

            Log::info("计算分销佣金: order_id={$orderId}, count=" . count($commissions));

            return $commissions;
        } catch (\Throwable $e) {
            Log::error("计算分销佣金失败: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 检查首单奖励
     * 条件：下级完成实名认证 + 完成3单真实服务
     * @param int $distributorId 分销人ID
     * @param int $fromUserId    下级用户ID
     * @return bool
     */
    public function checkFirstReward(int $distributorId, int $fromUserId): bool
    {
        try {
            // 检查是否已领取过
            $exists = DistributorFirstReward::where('distributor_id', $distributorId)
                ->where('from_user_id', $fromUserId)
                ->find();

            if ($exists) {
                return false;
            }

            // 检查下级是否完成实名认证
            $fromUser = User::find($fromUserId);
            if (!$fromUser || !$fromUser->is_real_name) {
                return false;
            }

            // 检查下级是否完成3单真实服务
            $completedOrders = Order::where('user_id', $fromUserId)
                ->where('status', Order::STATUS_COMPLETED)
                ->count();

            if ($completedOrders < self::FIRST_REWARD_MIN_ORDERS) {
                return false;
            }

            // 发放首单奖励
            DistributorFirstReward::create([
                'distributor_id' => $distributorId,
                'from_user_id'   => $fromUserId,
                'reward_amount'  => self::FIRST_REWARD_AMOUNT,
                'status'         => 1,
            ]);

            Log::info("首单奖励发放: distributor_id={$distributorId}, from_user_id={$fromUserId}, amount=" . self::FIRST_REWARD_AMOUNT);

            return true;
        } catch (\Throwable $e) {
            Log::error("检查首单奖励失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 结算佣金
     * @param int $orderId
     * @return bool
     */
    public function settleCommission(int $orderId): bool
    {
        $redis = get_redis();
        $lockKey = self::SETTLE_LOCK_PREFIX . $orderId;
        $lockValue = uniqid('settle_', true);

        try {
            // 获取分布式锁
            if (!$redis->set($lockKey, $lockValue, ['nx', 'ex' => 30])) {
                Log::warning("佣金结算获取锁失败: order_id={$orderId}");
                return false;
            }

            Db::startTrans();
            try {
                $commissions = DistributorCommission::where('order_id', $orderId)
                    ->where('status', 0)
                    ->lock(true)
                    ->select();

                foreach ($commissions as $commission) {
                    $commission->status = 1; // 已结算
                    $commission->settle_time = date('Y-m-d H:i:s');
                    $commission->save();

                    // 更新分销人余额
                    $user = User::find($commission->user_id);
                    if ($user) {
                        $user->balance = bc_add($user->balance, $commission->amount, 2);
                        $user->save();
                    }
                }

                Db::commit();

                Log::info("佣金结算完成: order_id={$orderId}, count=" . count($commissions));

                return true;
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error("佣金结算失败: order_id={$orderId}, error={$e->getMessage()}");
            return false;
        } finally {
            if (isset($lockValue)) {
                $lua = <<<LUA
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;
                $redis->eval($lua, [$lockKey, $lockValue], 1);
            }
        }
    }
}