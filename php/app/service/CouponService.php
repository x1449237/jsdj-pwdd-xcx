<?php
declare(strict_types=1);

namespace app\service;

use app\model\CouponTemplate;
use app\model\UserCoupon;
use app\model\User;
use think\facade\Db;
use think\facade\Log;

/**
 * 优惠券服务
 * 负责优惠券发放、使用、过期处理等核心业务
 */
class CouponService
{
    /**
     * 发放优惠券给用户
     * @param int    $userId
     * @param int    $couponId
     * @param string $channel
     * @return UserCoupon|null
     * @throws \RuntimeException
     */
    public function issueCoupon(int $userId, int $couponId, string $channel = 'admin'): ?UserCoupon
    {
        Db::startTrans();
        try {
            $coupon = CouponTemplate::find($couponId);
            if (!$coupon) {
                throw new \RuntimeException('优惠券模板不存在');
            }
            if (!$coupon->isAvailable()) {
                throw new \RuntimeException('优惠券不可领取');
            }

            $user = User::find($userId);
            if (!$user) {
                throw new \RuntimeException('用户不存在');
            }

            $totalCount = $coupon->getData('total_count');
            if ($totalCount > 0) {
                $coupon->used_count = $coupon->getData('used_count') + 1;
                $coupon->save();
            }

            $validityDays = (int)$coupon->getData('validity_days');
            $expireTime = null;
            if ($validityDays > 0) {
                $expireTime = date('Y-m-d H:i:s', time() + $validityDays * 86400);
            } elseif ($coupon->getData('end_time')) {
                $expireTime = $coupon->getData('end_time');
            }

            $userCoupon = UserCoupon::create([
                'coupon_id'       => $couponId,
                'user_id'         => $userId,
                'status'          => UserCoupon::STATUS_UNUSED,
                'receive_channel' => $channel,
                'receive_time'    => date('Y-m-d H:i:s'),
                'expire_time'     => $expireTime,
            ]);

            Db::commit();

            Log::info("优惠券发放成功: user_id={$userId}, coupon_id={$couponId}, user_coupon_id={$userCoupon->id}");

            return $userCoupon;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('优惠券发放失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 批量发放优惠券
     * @param int   $userId
     * @param array $couponIds
     * @param string $channel
     * @return array
     */
    public function issueCoupons(int $userId, array $couponIds, string $channel = 'admin'): array
    {
        $result = ['success' => [], 'failed' => []];
        foreach ($couponIds as $couponId) {
            try {
                $userCoupon = $this->issueCoupon($userId, (int)$couponId, $channel);
                if ($userCoupon) {
                    $result['success'][] = $userCoupon->toArray();
                }
            } catch (\Throwable $e) {
                $result['failed'][] = [
                    'coupon_id' => $couponId,
                    'message'   => $e->getMessage(),
                ];
            }
        }
        return $result;
    }

    /**
     * 使用优惠券
     * @param int $userCouponId
     * @param int $userId
     * @param int $orderId
     * @param string $orderAmount
     * @return bool
     * @throws \RuntimeException
     */
    public function useCoupon(int $userCouponId, int $userId, int $orderId, string $orderAmount): bool
    {
        Db::startTrans();
        try {
            $userCoupon = UserCoupon::where('id', $userCouponId)
                ->where('user_id', $userId)
                ->find();
            if (!$userCoupon) {
                throw new \RuntimeException('优惠券不存在');
            }
            if (!$userCoupon->isUsable()) {
                throw new \RuntimeException('优惠券不可用');
            }

            $coupon = CouponTemplate::find($userCoupon->getData('coupon_id'));
            if (!$coupon || !$coupon->isAvailable()) {
                throw new \RuntimeException('优惠券模板已失效');
            }

            $minAmount = (string)$coupon->getData('min_amount');
            if (bc_comp($orderAmount, $minAmount, 2) < 0) {
                throw new \RuntimeException('订单金额不满足优惠券使用门槛');
            }

            $userCoupon->status   = UserCoupon::STATUS_USED;
            $userCoupon->use_time = date('Y-m-d H:i:s');
            $userCoupon->order_id = $orderId;
            $userCoupon->save();

            Db::commit();

            Log::info("优惠券使用成功: user_coupon_id={$userCouponId}, order_id={$orderId}");

            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('优惠券使用失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 计算优惠券可抵扣金额
     * @param int    $userCouponId
     * @param string $orderAmount
     * @return string
     */
    public function calculateDiscount(int $userCouponId, string $orderAmount): string
    {
        $userCoupon = UserCoupon::find($userCouponId);
        if (!$userCoupon || !$userCoupon->isUsable()) {
            return '0.00';
        }

        $coupon = CouponTemplate::find($userCoupon->getData('coupon_id'));
        if (!$coupon || !$coupon->isAvailable()) {
            return '0.00';
        }

        $minAmount = (string)$coupon->getData('min_amount');
        if (bc_comp($orderAmount, $minAmount, 2) < 0) {
            return '0.00';
        }

        return (string)$coupon->getData('value');
    }

    /**
     * 获取用户可用优惠券列表
     * @param int $userId
     * @param string $orderAmount
     * @param string $scope
     * @param int    $scopeId
     * @return array
     */
    public function getUserUsableCoupons(int $userId, string $orderAmount = '0', string $scope = 'all', int $scopeId = 0): array
    {
        $now = date('Y-m-d H:i:s');

        $query = UserCoupon::where('user_id', $userId)
            ->where('status', UserCoupon::STATUS_UNUSED)
            ->where(function ($q) use ($now) {
                $q->whereNull('expire_time')->whereOr('expire_time', '>', $now);
            })
            ->order('receive_time', 'desc');

        $list = $query->select()->toArray();

        $result = [];
        foreach ($list as $item) {
            $coupon = CouponTemplate::find($item['coupon_id']);
            if (!$coupon) continue;

            $isApplicable = true;
            $applicableScope = $coupon->getData('applicable_scope');
            if ($applicableScope != 'all') {
                if ($applicableScope != $scope || ($scopeId > 0 && (int)$coupon->getData('applicable_id') != $scopeId)) {
                    $isApplicable = false;
                }
            }

            $minAmount = (string)$coupon->getData('min_amount');
            $canUse = $isApplicable && bc_comp($orderAmount, $minAmount, 2) >= 0;

            $item['coupon'] = $coupon->toArray();
            $item['can_use'] = $canUse;
            $item['discount_amount'] = $canUse ? (string)$coupon->getData('value') : '0.00';
            $result[] = $item;
        }

        return $result;
    }

    /**
     * 处理过期优惠券（定时任务调用）
     * @return int 处理数量
     */
    public function processExpiredCoupons(): int
    {
        $now = date('Y-m-d H:i:s');
        $count = 0;

        $list = UserCoupon::where('status', UserCoupon::STATUS_UNUSED)
            ->whereNotNull('expire_time')
            ->where('expire_time', '<=', $now)
            ->select();

        foreach ($list as $userCoupon) {
            $userCoupon->status = UserCoupon::STATUS_EXPIRED;
            $userCoupon->save();
            $count++;
        }

        if ($count > 0) {
            Log::info("过期优惠券处理完成，共处理 {$count} 张");
        }

        return $count;
    }

    /**
     * 发放新人优惠券
     * @param int $userId
     * @return array
     */
    public function issueNewUserCoupons(int $userId): array
    {
        $coupons = CouponTemplate::enabled()
            ->where('type', CouponTemplate::TYPE_NEW_USER)
            ->select();

        $result = ['success' => 0, 'failed' => 0];
        foreach ($coupons as $coupon) {
            try {
                $this->issueCoupon($userId, (int)$coupon->id, 'register');
                $result['success']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::warning('新人优惠券发放失败: ' . $e->getMessage());
            }
        }

        return $result;
    }
}
