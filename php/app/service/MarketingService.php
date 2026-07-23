<?php
declare(strict_types=1);

namespace app\service;

use app\model\RechargeActivity;
use app\model\UserRechargeLog;
use app\model\InviteRewardConfig;
use app\model\InviteRewardLog;
use app\model\LotteryActivity;
use app\model\LotteryPrize;
use app\model\LotteryRecord;
use app\model\GroupBuyActivity;
use app\model\GroupBuyOrder;
use app\model\GroupBuyMember;
use app\model\InviteBindLog;
use app\model\User;
use app\model\Order as OrderModel;
use think\facade\Db;
use think\facade\Log;

/**
 * 营销服务
 * 负责充值活动、抽奖、拼团、邀请奖励等营销业务
 */
class MarketingService
{
    /**
     * 获取充值活动列表
     * @return array
     */
    public function getRechargeActivities(): array
    {
        return RechargeActivity::active()
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 创建充值订单
     * @param int $userId
     * @param int $activityId
     * @return array
     * @throws \RuntimeException
     */
    public function createRecharge(int $userId, int $activityId): array
    {
        Db::startTrans();
        try {
            $user = User::find($userId);
            if (!$user) {
                throw new \RuntimeException('用户不存在');
            }

            $activity = RechargeActivity::find($activityId);
            if (!$activity || !$activity->isActive()) {
                throw new \RuntimeException('充值活动不存在或已结束');
            }

            $outTradeNo = generate_sn('RC');

            $rechargeLog = UserRechargeLog::create([
                'user_id'      => $userId,
                'amount'       => $activity->getData('recharge_amount'),
                'bonus_amount' => $activity->getData('bonus_amount'),
                'activity_id'  => $activityId,
                'pay_status'   => UserRechargeLog::STATUS_PENDING,
                'out_trade_no' => $outTradeNo,
            ]);

            Db::commit();

            return [
                'recharge_id'  => $rechargeLog->id,
                'out_trade_no' => $outTradeNo,
                'amount'       => $activity->getData('recharge_amount'),
                'bonus_amount' => $activity->getData('bonus_amount'),
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('创建充值订单失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 充值成功处理
     * @param string $outTradeNo
     * @param string $transactionId
     * @return bool
     */
    public function rechargeSuccess(string $outTradeNo, string $transactionId): bool
    {
        Db::startTrans();
        try {
            $rechargeLog = UserRechargeLog::where('out_trade_no', $outTradeNo)->find();
            if (!$rechargeLog) {
                throw new \RuntimeException('充值记录不存在');
            }
            if ($rechargeLog->getData('pay_status') == UserRechargeLog::STATUS_PAID) {
                return true;
            }

            $userId = (int)$rechargeLog->getData('user_id');
            $amount = (string)$rechargeLog->getData('amount');
            $bonusAmount = (string)$rechargeLog->getData('bonus_amount');
            $totalAmount = bc_add($amount, $bonusAmount, 2);

            $user = User::find($userId);
            if (!$user) {
                throw new \RuntimeException('用户不存在');
            }

            $user->balance = bc_add((string)$user->getData('balance'), $totalAmount, 2);
            $user->save();

            $rechargeLog->pay_status     = UserRechargeLog::STATUS_PAID;
            $rechargeLog->transaction_id = $transactionId;
            $rechargeLog->pay_time       = date('Y-m-d H:i:s');
            $rechargeLog->save();

            $activityId = (int)$rechargeLog->getData('activity_id');
            if ($activityId > 0) {
                $activity = RechargeActivity::find($activityId);
                if ($activity && $activity->getData('bonus_type') == 'coupon') {
                    $couponId = (int)$activity->getData('bonus_coupon_id');
                    if ($couponId > 0) {
                        try {
                            $couponService = new CouponService();
                            $couponService->issueCoupon($userId, $couponId, 'activity');
                        } catch (\Throwable $e) {
                            Log::warning('充值赠送优惠券失败: ' . $e->getMessage());
                        }
                    }
                }
            }

            Db::commit();

            Log::info("充值成功: user_id={$userId}, amount={$amount}, bonus={$bonusAmount}");

            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('充值成功处理失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查并发放邀请奖励
     * @param int    $inviteeUserId
     * @param string $conditionType
     * @param array  $extra
     * @return array
     */
    public function checkAndIssueInviteReward(int $inviteeUserId, string $conditionType, array $extra = []): array
    {
        $result = ['issued' => false, 'rewards' => []];

        try {
            $bindLog = InviteBindLog::where('user_id', $inviteeUserId)->find();
            if (!$bindLog) {
                return $result;
            }

            $inviterUserId = (int)$bindLog->getData('inviter_id');
            if ($inviterUserId <= 0) {
                return $result;
            }

            $configs = InviteRewardConfig::enabled()
                ->where('condition_type', $conditionType)
                ->select();

            if ($configs->isEmpty()) {
                return $result;
            }

            Db::startTrans();

            foreach ($configs as $config) {
                $existLog = InviteRewardLog::where('inviter_user_id', $inviterUserId)
                    ->where('invitee_user_id', $inviteeUserId)
                    ->where('condition_type', $conditionType)
                    ->find();
                if ($existLog) {
                    continue;
                }

                $conditionValue = $config->getData('condition_value');
                if ($conditionType == 'first_order' && !empty($conditionValue)) {
                    $orderAmount = $extra['order_amount'] ?? '0';
                    if (bc_comp($orderAmount, $conditionValue, 2) < 0) {
                        continue;
                    }
                }

                $rewardType = $config->getData('reward_type');
                $rewardValue = (string)$config->getData('reward_value');

                $rewardLog = InviteRewardLog::create([
                    'inviter_user_id' => $inviterUserId,
                    'invitee_user_id' => $inviteeUserId,
                    'reward_type'     => $rewardType,
                    'reward_value'    => $rewardValue,
                    'condition_type'  => $conditionType,
                    'status'          => InviteRewardLog::STATUS_GRANTED,
                    'reward_time'     => date('Y-m-d H:i:s'),
                ]);

                if ($rewardType == 'balance') {
                    $inviter = User::find($inviterUserId);
                    if ($inviter) {
                        $inviter->balance = bc_add((string)$inviter->getData('balance'), $rewardValue, 2);
                        $inviter->save();
                    }
                } elseif ($rewardType == 'coupon') {
                    try {
                        $couponService = new CouponService();
                        $couponService->issueCoupon($inviterUserId, (int)$rewardValue, 'invite');
                    } catch (\Throwable $e) {
                        Log::warning('邀请奖励发放优惠券失败: ' . $e->getMessage());
                        $rewardLog->status = InviteRewardLog::STATUS_FAILED;
                        $rewardLog->remark = $e->getMessage();
                        $rewardLog->save();
                    }
                }

                $result['rewards'][] = $rewardLog->toArray();
                $result['issued'] = true;
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('邀请奖励发放失败: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * 获取抽奖活动详情
     * @param int $activityId
     * @return array|null
     */
    public function getLotteryActivity(int $activityId): ?array
    {
        $activity = LotteryActivity::find($activityId);
        if (!$activity) {
            return null;
        }

        $data = $activity->toArray();
        $data['prizes'] = $activity->prizes()->select()->toArray();

        return $data;
    }

    /**
     * 执行抽奖
     * @param int $userId
     * @param int $activityId
     * @return array
     * @throws \RuntimeException
     */
    public function drawLottery(int $userId, int $activityId): array
    {
        Db::startTrans();
        try {
            $activity = LotteryActivity::find($activityId);
            if (!$activity || !$activity->isActive()) {
                throw new \RuntimeException('抽奖活动不存在或已结束');
            }

            $dailyLimit = (int)$activity->getData('daily_limit');
            if ($dailyLimit > 0) {
                $todayStart = date('Y-m-d 00:00:00');
                $todayEnd   = date('Y-m-d 23:59:59');
                $todayCount = LotteryRecord::where('user_id', $userId)
                    ->where('activity_id', $activityId)
                    ->where('create_time', '>=', $todayStart)
                    ->where('create_time', '<=', $todayEnd)
                    ->count();
                if ($todayCount >= $dailyLimit) {
                    throw new \RuntimeException('今日抽奖次数已用完');
                }
            }

            $costType = $activity->getData('cost_type');
            $costValue = (string)$activity->getData('cost_value');
            if ($costType == 'balance') {
                $user = User::find($userId);
                if (!$user || bc_comp((string)$user->getData('balance'), $costValue, 2) < 0) {
                    throw new \RuntimeException('余额不足');
                }
                $user->balance = bc_sub((string)$user->getData('balance'), $costValue, 2);
                $user->save();
            }

            $prize = $this->drawPrize($activityId);
            $isWin = $prize && $prize->getData('type') != 'thank';

            $record = LotteryRecord::create([
                'activity_id' => $activityId,
                'user_id'     => $userId,
                'prize_id'    => $prize ? $prize->id : 0,
                'prize_name'  => $prize ? $prize->getData('name') : '谢谢参与',
                'prize_type'  => $prize ? $prize->getData('type') : 'thank',
                'is_win'      => $isWin ? 1 : 0,
                'draw_time'   => date('Y-m-d H:i:s'),
            ]);

            if ($isWin && $prize) {
                $prizeType = $prize->getData('type');
                $prizeValue = (string)$prize->getData('value');

                if ($prizeType == 'balance') {
                    $user = User::find($userId);
                    if ($user) {
                        $user->balance = bc_add((string)$user->getData('balance'), $prizeValue, 2);
                        $user->save();
                    }
                } elseif ($prizeType == 'coupon') {
                    try {
                        $couponService = new CouponService();
                        $couponService->issueCoupon($userId, (int)$prizeValue, 'activity');
                    } catch (\Throwable $e) {
                        Log::warning('抽奖中奖发放优惠券失败: ' . $e->getMessage());
                    }
                }

                if ((int)$prize->getData('stock') > 0) {
                    $prize->used_count = (int)$prize->getData('used_count') + 1;
                    $prize->save();
                }
            }

            Db::commit();

            return [
                'record_id'  => $record->id,
                'is_win'     => $isWin,
                'prize_id'   => $prize ? $prize->id : 0,
                'prize_name' => $prize ? $prize->getData('name') : '谢谢参与',
                'prize_type' => $prize ? $prize->getData('type') : 'thank',
                'prize'      => $prize ? $prize->toArray() : null,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('抽奖失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 根据概率抽取奖品
     * @param int $activityId
     * @return LotteryPrize|null
     */
    private function drawPrize(int $activityId): ?LotteryPrize
    {
        $prizes = LotteryPrize::enabled()
            ->where('activity_id', $activityId)
            ->order('sort', 'asc')
            ->select();

        if ($prizes->isEmpty()) {
            return null;
        }

        $totalProbability = '0';
        $availablePrizes = [];

        foreach ($prizes as $prize) {
            if (!$prize->hasStock()) {
                continue;
            }
            $probability = (string)$prize->getData('probability');
            $totalProbability = bc_add($totalProbability, $probability, 4);
            $availablePrizes[] = $prize;
        }

        if (empty($availablePrizes)) {
            return null;
        }

        $random = mt_rand() / mt_getrandmax();
        $randomStr = number_format($random, 4, '.', '');
        $cumulative = '0';

        foreach ($availablePrizes as $prize) {
            $probability = (string)$prize->getData('probability');
            $normalizedProb = bc_comp($totalProbability, '0', 4) > 0
                ? bc_div($probability, $totalProbability, 4)
                : '0';
            $cumulative = bc_add($cumulative, $normalizedProb, 4);

            if (bccomp($randomStr, $cumulative, 4) <= 0) {
                return $prize;
            }
        }

        return end($availablePrizes) ?: null;
    }

    /**
     * 获取拼团活动列表
     * @param int $gameId
     * @return array
     */
    public function getGroupBuyActivities(int $gameId = 0): array
    {
        $query = GroupBuyActivity::enabled()->order('sort', 'asc');
        if ($gameId > 0) {
            $query->where('game_id', $gameId);
        }
        return $query->select()->toArray();
    }

    /**
     * 创建拼团
     * @param int $userId
     * @param int $activityId
     * @return array
     * @throws \RuntimeException
     */
    public function createGroupBuy(int $userId, int $activityId): array
    {
        Db::startTrans();
        try {
            $activity = GroupBuyActivity::find($activityId);
            if (!$activity || $activity->getData('status') != GroupBuyActivity::STATUS_ENABLED) {
                throw new \RuntimeException('拼团活动不存在或已结束');
            }

            $durationHours = (int)$activity->getData('duration_hours');
            $expireTime = date('Y-m-d H:i:s', time() + $durationHours * 3600);

            $groupOrder = GroupBuyOrder::create([
                'activity_id'    => $activityId,
                'leader_user_id' => $userId,
                'current_people' => 1,
                'max_people'     => $activity->getData('max_people'),
                'status'         => GroupBuyOrder::STATUS_PENDING,
                'expire_time'    => $expireTime,
            ]);

            GroupBuyMember::create([
                'group_id'  => $groupOrder->id,
                'user_id'   => $userId,
                'is_leader' => 1,
                'join_time' => date('Y-m-d H:i:s'),
                'status'    => GroupBuyMember::STATUS_JOINED,
            ]);

            Db::commit();

            return $groupOrder->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('创建拼团失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 加入拼团
     * @param int $userId
     * @param int $groupId
     * @return array
     * @throws \RuntimeException
     */
    public function joinGroupBuy(int $userId, int $groupId): array
    {
        Db::startTrans();
        try {
            $groupOrder = GroupBuyOrder::find($groupId);
            if (!$groupOrder) {
                throw new \RuntimeException('拼团不存在');
            }
            if ($groupOrder->getData('status') != GroupBuyOrder::STATUS_PENDING) {
                throw new \RuntimeException('拼团已结束或已取消');
            }
            if ($groupOrder->isExpired()) {
                $groupOrder->status = GroupBuyOrder::STATUS_FAILED;
                $groupOrder->save();
                throw new \RuntimeException('拼团已过期');
            }
            if ($groupOrder->isFull()) {
                throw new \RuntimeException('拼团人数已满');
            }

            $existMember = GroupBuyMember::where('group_id', $groupId)
                ->where('user_id', $userId)
                ->find();
            if ($existMember) {
                throw new \RuntimeException('您已加入该拼团');
            }

            GroupBuyMember::create([
                'group_id'  => $groupId,
                'user_id'   => $userId,
                'is_leader' => 0,
                'join_time' => date('Y-m-d H:i:s'),
                'status'    => GroupBuyMember::STATUS_JOINED,
            ]);

            $groupOrder->current_people = (int)$groupOrder->getData('current_people') + 1;
            $groupOrder->save();

            if ($groupOrder->isFull()) {
                $groupOrder->status = GroupBuyOrder::STATUS_SUCCESS;
                $groupOrder->success_time = date('Y-m-d H:i:s');
                $groupOrder->save();
            }

            Db::commit();

            return $groupOrder->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('加入拼团失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取用户邀请奖励列表
     * @param int $userId
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getInviteRewards(int $userId, int $page = 1, int $limit = 20): array
    {
        $query = InviteRewardLog::where('inviter_user_id', $userId)
            ->order('create_time', 'desc');

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $invitee = User::find($item['invitee_user_id']);
            $item['invitee'] = $invitee ? [
                'id'       => $invitee->id,
                'nickname' => $invitee->getData('nickname'),
                'avatar'   => $invitee->getData('avatar'),
            ] : null;
        }

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 获取用户邀请统计
     * @param int $userId
     * @return array
     */
    public function getInviteStats(int $userId): array
    {
        $totalInvitees = InviteBindLog::where('inviter_id', $userId)->count();
        $totalRewards = InviteRewardLog::where('inviter_user_id', $userId)
            ->where('status', InviteRewardLog::STATUS_GRANTED)
            ->count();

        $balanceReward = InviteRewardLog::where('inviter_user_id', $userId)
            ->where('status', InviteRewardLog::STATUS_GRANTED)
            ->where('reward_type', 'balance')
            ->sum('reward_value');

        return [
            'total_invitees' => $totalInvitees,
            'total_rewards'  => $totalRewards,
            'balance_reward' => (string)($balanceReward ?: '0.00'),
        ];
    }
}
