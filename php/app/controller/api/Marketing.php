<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\service\CouponService;
use app\service\MarketingService;
use app\model\CouponTemplate;
use app\model\UserCoupon;
use app\model\UserRechargeLog;
use app\model\LotteryRecord;
use app\model\GroupBuyOrder;
use app\model\GroupBuyMember;
use think\facade\Log;
use think\Request;

/**
 * 营销相关控制器（微信小程序端）
 */
class Marketing extends BaseController
{
    private $couponService;
    private $marketingService;

    public function __construct()
    {
        $this->couponService = new CouponService();
        $this->marketingService = new MarketingService();
    }

    // ===================== 优惠券 =====================

    /**
     * 优惠券列表（可领取的优惠券模板）
     */
    public function couponList(Request $request)
    {
        $type = $request->param('type', '');

        $query = CouponTemplate::enabled()->order('sort', 'asc')->order('id', 'desc');

        if (!empty($type)) {
            $query->where('type', $type);
        }

        $list = $query->select()->toArray();

        foreach ($list as &$item) {
            $totalCount = (int)$item['total_count'];
            $usedCount = (int)$item['used_count'];
            $item['remain_count'] = $totalCount > 0 ? max(0, $totalCount - $usedCount) : -1;
            $item['can_receive'] = $item['remain_count'] != 0;
        }

        return $this->success($list);
    }

    /**
     * 我的优惠券
     */
    public function myCoupons(Request $request)
    {
        $userId = request()->userId();
        $status = $request->param('status', '');
        [$page, $limit] = $this->pageParams();

        $query = UserCoupon::where('user_id', $userId)->order('create_time', 'desc');

        if (!empty($status)) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $coupon = CouponTemplate::find($item['coupon_id']);
            $item['coupon'] = $coupon ? $coupon->toArray() : null;
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 领取优惠券
     */
    public function receiveCoupon(Request $request)
    {
        $userId = request()->userId();
        $couponId = $request->paramInt('coupon_id', 0);

        if ($couponId <= 0) {
            return $this->error('优惠券ID无效');
        }

        try {
            $userCoupon = $this->couponService->issueCoupon($userId, $couponId, 'activity');

            write_action_log('api_receive_coupon', "用户 ID:{$userId} 领取优惠券: {$couponId}");

            return $this->success($userCoupon->toArray(), '领取成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 可用优惠券列表（下单页用）
     */
    public function usableCoupons(Request $request)
    {
        $userId = request()->userId();
        $orderAmount = $request->param('order_amount', '0');
        $scope = $request->param('scope', 'all');
        $scopeId = $request->paramInt('scope_id', 0);

        $list = $this->couponService->getUserUsableCoupons($userId, $orderAmount, $scope, $scopeId);

        return $this->success($list);
    }

    // ===================== 充值活动 =====================

    /**
     * 充值活动列表
     */
    public function rechargeActivities(Request $request)
    {
        $list = $this->marketingService->getRechargeActivities();
        return $this->success($list);
    }

    /**
     * 充值下单
     */
    public function recharge(Request $request)
    {
        $userId = request()->userId();
        $activityId = $request->paramInt('activity_id', 0);

        if ($activityId <= 0) {
            return $this->error('活动ID无效');
        }

        try {
            $result = $this->marketingService->createRecharge($userId, $activityId);

            write_action_log('api_recharge', "用户 ID:{$userId} 创建充值订单，活动: {$activityId}");

            return $this->success($result, '充值订单创建成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 充值记录
     */
    public function rechargeRecords(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();

        $query = UserRechargeLog::where('user_id', $userId)
            ->where('pay_status', UserRechargeLog::STATUS_PAID)
            ->order('pay_time', 'desc');

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 抽奖 =====================

    /**
     * 抽奖活动详情
     */
    public function lotteryActivity(Request $request)
    {
        $activityId = $request->paramInt('id', 0);

        if ($activityId <= 0) {
            $activity = \app\model\LotteryActivity::active()->order('sort', 'asc')->find();
            if (!$activity) {
                return $this->error('暂无抽奖活动');
            }
            $activityId = $activity->id;
        }

        $data = $this->marketingService->getLotteryActivity($activityId);
        if (!$data) {
            return $this->error('抽奖活动不存在', 404);
        }

        return $this->success($data);
    }

    /**
     * 抽奖
     */
    public function drawLottery(Request $request)
    {
        $userId = request()->userId();
        $activityId = $request->paramInt('activity_id', 0);

        if ($activityId <= 0) {
            return $this->error('活动ID无效');
        }

        try {
            $result = $this->marketingService->drawLottery($userId, $activityId);

            write_action_log('api_draw_lottery', "用户 ID:{$userId} 抽奖，活动: {$activityId}, 结果: " . ($result['is_win'] ? '中奖' : '未中奖'));

            return $this->success($result, $result['is_win'] ? '恭喜中奖！' : '感谢参与');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 我的抽奖记录
     */
    public function lotteryRecords(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();

        $query = LotteryRecord::where('user_id', $userId)->order('draw_time', 'desc');

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 拼团 =====================

    /**
     * 拼团活动列表
     */
    public function groupBuyActivities(Request $request)
    {
        $gameId = $request->paramInt('game_id', 0);
        $list = $this->marketingService->getGroupBuyActivities($gameId);
        return $this->success($list);
    }

    /**
     * 拼团中列表
     */
    public function groupBuyList(Request $request)
    {
        $activityId = $request->paramInt('activity_id', 0);
        [$page, $limit] = $this->pageParams();

        $query = GroupBuyOrder::where('status', GroupBuyOrder::STATUS_PENDING)
            ->where('expire_time', '>', date('Y-m-d H:i:s'))
            ->order('create_time', 'desc');

        if ($activityId > 0) {
            $query->where('activity_id', $activityId);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $members = GroupBuyMember::where('group_id', $item['id'])
                ->with(['user'])
                ->limit($item['max_people'])
                ->select()
                ->toArray();
            $item['members'] = $members;
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 拼团详情
     */
    public function groupBuyDetail(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('拼团ID无效');
        }

        $groupOrder = GroupBuyOrder::find($id);
        if (!$groupOrder) {
            return $this->error('拼团不存在', 404);
        }

        $data = $groupOrder->toArray();
        $members = GroupBuyMember::where('group_id', $id)
            ->order('join_time', 'asc')
            ->select()
            ->toArray();

        foreach ($members as &$member) {
            $user = \app\model\User::find($member['user_id']);
            $member['user'] = $user ? [
                'id'       => $user->id,
                'nickname' => $user->getData('nickname'),
                'avatar'   => $user->getData('avatar'),
            ] : null;
        }

        $data['members'] = $members;

        return $this->success($data);
    }

    /**
     * 创建拼团
     */
    public function createGroupBuy(Request $request)
    {
        $userId = request()->userId();
        $activityId = $request->paramInt('activity_id', 0);

        if ($activityId <= 0) {
            return $this->error('活动ID无效');
        }

        try {
            $result = $this->marketingService->createGroupBuy($userId, $activityId);

            write_action_log('api_create_group_buy', "用户 ID:{$userId} 创建拼团，活动: {$activityId}");

            return $this->success($result, '拼团创建成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 加入拼团
     */
    public function joinGroupBuy(Request $request)
    {
        $userId = request()->userId();
        $groupId = $request->paramInt('group_id', 0);

        if ($groupId <= 0) {
            return $this->error('拼团ID无效');
        }

        try {
            $result = $this->marketingService->joinGroupBuy($userId, $groupId);

            write_action_log('api_join_group_buy', "用户 ID:{$userId} 加入拼团: {$groupId}");

            return $this->success($result, '加入拼团成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 我的拼团
     */
    public function myGroupBuys(Request $request)
    {
        $userId = request()->userId();
        $status = $request->param('status', '');
        [$page, $limit] = $this->pageParams();

        $memberQuery = GroupBuyMember::where('user_id', $userId);
        $groupIds = $memberQuery->column('group_id');

        if (empty($groupIds)) {
            return $this->page([], 0, $page, $limit);
        }

        $query = GroupBuyOrder::whereIn('id', $groupIds)->order('create_time', 'desc');

        if (!empty($status)) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 邀请奖励 =====================

    /**
     * 邀请奖励列表
     */
    public function inviteRewards(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();

        $result = $this->marketingService->getInviteRewards($userId, $page, $limit);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    /**
     * 邀请统计
     */
    public function inviteStats(Request $request)
    {
        $userId = request()->userId();
        $stats = $this->marketingService->getInviteStats($userId);
        return $this->success($stats);
    }
}
