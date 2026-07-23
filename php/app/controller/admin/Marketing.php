<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\CouponService;
use app\model\CouponTemplate;
use app\model\UserCoupon;
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
use think\facade\Log;
use think\Request;

/**
 * 营销管理控制器（管理后台）
 */
class Marketing extends BaseController
{
    // ===================== 优惠券模板管理 =====================

    /**
     * 优惠券模板列表
     */
    public function couponList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword = $request->param('keyword', '');
        $type = $request->param('type', '');
        $status = $request->param('status', '');

        $query = CouponTemplate::order('sort', 'asc')->order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if (!empty($type)) {
            $query->where('type', $type);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_coupon_list', '查看优惠券模板列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建优惠券模板
     */
    public function couponCreate(Request $request)
    {
        $data = $this->collectCouponData($request);

        $coupon = CouponTemplate::create($data);

        $this->operationLog('admin_coupon_create', "创建优惠券模板: {$coupon->name}");

        return $this->success($coupon->toArray(), '创建成功');
    }

    /**
     * 更新优惠券模板
     */
    public function couponUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $coupon = CouponTemplate::find($id);
        if (!$coupon) {
            return $this->error('优惠券模板不存在', 404);
        }

        $data = $this->collectCouponData($request);
        $coupon->save($data);

        $this->operationLog('admin_coupon_update', "更新优惠券模板: {$coupon->name}");

        return $this->success($coupon->toArray(), '更新成功');
    }

    /**
     * 删除优惠券模板
     */
    public function couponDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $coupon = CouponTemplate::find($id);
        if (!$coupon) {
            return $this->error('优惠券模板不存在', 404);
        }

        $coupon->delete();

        $this->operationLog('admin_coupon_delete', "删除优惠券模板: {$coupon->name}");

        return $this->success(null, '删除成功');
    }

    /**
     * 切换优惠券状态
     */
    public function couponToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $coupon = CouponTemplate::find($id);
        if (!$coupon) {
            return $this->error('优惠券模板不存在', 404);
        }

        $newStatus = $coupon->getData('status') == CouponTemplate::STATUS_ENABLED
            ? CouponTemplate::STATUS_DISABLED
            : CouponTemplate::STATUS_ENABLED;

        $coupon->status = $newStatus;
        $coupon->save();

        $this->operationLog('admin_coupon_toggle', "切换优惠券状态: {$coupon->name}, 新状态: {$newStatus}");

        return $this->success(['status' => $newStatus], '状态更新成功');
    }

    /**
     * 收集优惠券数据
     */
    private function collectCouponData(Request $request): array
    {
        return [
            'name'             => $request->param('name', ''),
            'type'             => $request->param('type', 'full_reduction'),
            'value'            => $request->param('value', '0'),
            'min_amount'       => $request->param('min_amount', '0'),
            'total_count'      => $request->paramInt('total_count', 0),
            'validity_days'    => $request->paramInt('validity_days', 0),
            'start_time'       => $request->param('start_time', null),
            'end_time'         => $request->param('end_time', null),
            'applicable_scope' => $request->param('applicable_scope', 'all'),
            'applicable_id'    => $request->paramInt('applicable_id', 0),
            'sort'             => $request->paramInt('sort', 0),
            'status'           => $request->paramInt('status', CouponTemplate::STATUS_ENABLED),
        ];
    }

    /**
     * 发放优惠券给用户
     */
    public function couponIssue(Request $request)
    {
        $userId = $request->paramInt('user_id', 0);
        $couponIds = $request->param('coupon_ids/a', []);

        if ($userId <= 0) {
            return $this->error('用户ID无效');
        }
        if (empty($couponIds)) {
            return $this->error('请选择优惠券');
        }

        $couponService = new CouponService();
        $result = $couponService->issueCoupons($userId, $couponIds, 'admin');

        $this->operationLog('admin_coupon_issue', "给用户 ID:{$userId} 发放优惠券，成功" . count($result['success']) . "张，失败" . count($result['failed']) . "张");

        return $this->success($result, '发放完成');
    }

    /**
     * 用户优惠券发放记录
     */
    public function couponIssueLog(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $userId = $request->paramInt('user_id', 0);
        $couponId = $request->paramInt('coupon_id', 0);

        $query = UserCoupon::order('id', 'desc');

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($couponId > 0) {
            $query->where('coupon_id', $couponId);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 充值活动管理 =====================

    /**
     * 充值活动列表
     */
    public function rechargeList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword = $request->param('keyword', '');
        $status = $request->param('status', '');

        $query = RechargeActivity::order('sort', 'asc')->order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_recharge_list', '查看充值活动列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建充值活动
     */
    public function rechargeCreate(Request $request)
    {
        $data = $this->collectRechargeData($request);
        $activity = RechargeActivity::create($data);

        $this->operationLog('admin_recharge_create', "创建充值活动: {$activity->name}");

        return $this->success($activity->toArray(), '创建成功');
    }

    /**
     * 更新充值活动
     */
    public function rechargeUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = RechargeActivity::find($id);
        if (!$activity) {
            return $this->error('充值活动不存在', 404);
        }

        $data = $this->collectRechargeData($request);
        $activity->save($data);

        $this->operationLog('admin_recharge_update', "更新充值活动: {$activity->name}");

        return $this->success($activity->toArray(), '更新成功');
    }

    /**
     * 删除充值活动
     */
    public function rechargeDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = RechargeActivity::find($id);
        if (!$activity) {
            return $this->error('充值活动不存在', 404);
        }

        $activity->delete();

        $this->operationLog('admin_recharge_delete', "删除充值活动: {$activity->name}");

        return $this->success(null, '删除成功');
    }

    /**
     * 切换充值活动状态
     */
    public function rechargeToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = RechargeActivity::find($id);
        if (!$activity) {
            return $this->error('充值活动不存在', 404);
        }

        $newStatus = $activity->getData('status') == RechargeActivity::STATUS_ENABLED
            ? RechargeActivity::STATUS_DISABLED
            : RechargeActivity::STATUS_ENABLED;

        $activity->status = $newStatus;
        $activity->save();

        $this->operationLog('admin_recharge_toggle', "切换充值活动状态: {$activity->name}, 新状态: {$newStatus}");

        return $this->success(['status' => $newStatus], '状态更新成功');
    }

    private function collectRechargeData(Request $request): array
    {
        return [
            'name'            => $request->param('name', ''),
            'recharge_amount' => $request->param('recharge_amount', '0'),
            'bonus_amount'    => $request->param('bonus_amount', '0'),
            'bonus_type'      => $request->param('bonus_type', 'balance'),
            'bonus_coupon_id' => $request->paramInt('bonus_coupon_id', 0),
            'start_time'      => $request->param('start_time', null),
            'end_time'        => $request->param('end_time', null),
            'sort'            => $request->paramInt('sort', 0),
            'status'          => $request->paramInt('status', RechargeActivity::STATUS_ENABLED),
        ];
    }

    /**
     * 充值记录列表
     */
    public function rechargeRecords(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $userId = $request->paramInt('user_id', 0);

        $query = UserRechargeLog::order('id', 'desc');

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 邀请奖励配置 =====================

    /**
     * 邀请奖励配置列表
     */
    public function inviteRewardList(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $query = InviteRewardConfig::order('sort', 'asc')->order('id', 'desc');

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_invite_reward_list', '查看邀请奖励配置列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建邀请奖励配置
     */
    public function inviteRewardCreate(Request $request)
    {
        $data = $this->collectInviteRewardData($request);
        $config = InviteRewardConfig::create($data);

        $this->operationLog('admin_invite_reward_create', "创建邀请奖励配置 ID:{$config->id}");

        return $this->success($config->toArray(), '创建成功');
    }

    /**
     * 更新邀请奖励配置
     */
    public function inviteRewardUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $config = InviteRewardConfig::find($id);
        if (!$config) {
            return $this->error('配置不存在', 404);
        }

        $data = $this->collectInviteRewardData($request);
        $config->save($data);

        $this->operationLog('admin_invite_reward_update', "更新邀请奖励配置 ID:{$id}");

        return $this->success($config->toArray(), '更新成功');
    }

    /**
     * 删除邀请奖励配置
     */
    public function inviteRewardDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $config = InviteRewardConfig::find($id);
        if (!$config) {
            return $this->error('配置不存在', 404);
        }

        $config->delete();

        $this->operationLog('admin_invite_reward_delete', "删除邀请奖励配置 ID:{$id}");

        return $this->success(null, '删除成功');
    }

    /**
     * 切换邀请奖励配置状态
     */
    public function inviteRewardToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $config = InviteRewardConfig::find($id);
        if (!$config) {
            return $this->error('配置不存在', 404);
        }

        $newStatus = $config->getData('status') == InviteRewardConfig::STATUS_ENABLED
            ? InviteRewardConfig::STATUS_DISABLED
            : InviteRewardConfig::STATUS_ENABLED;

        $config->status = $newStatus;
        $config->save();

        $this->operationLog('admin_invite_reward_toggle', "切换邀请奖励配置状态 ID:{$id}, 新状态: {$newStatus}");

        return $this->success(['status' => $newStatus], '状态更新成功');
    }

    private function collectInviteRewardData(Request $request): array
    {
        return [
            'reward_type'     => $request->param('reward_type', 'balance'),
            'reward_value'    => $request->param('reward_value', '0'),
            'condition_type'  => $request->param('condition_type', 'first_order'),
            'condition_value' => $request->param('condition_value', ''),
            'sort'            => $request->paramInt('sort', 0),
            'status'          => $request->paramInt('status', InviteRewardConfig::STATUS_ENABLED),
        ];
    }

    /**
     * 邀请奖励记录
     */
    public function inviteRewardLog(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $inviterId = $request->paramInt('inviter_id', 0);

        $query = InviteRewardLog::order('id', 'desc');

        if ($inviterId > 0) {
            $query->where('inviter_user_id', $inviterId);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 抽奖活动管理 =====================

    /**
     * 抽奖活动列表
     */
    public function lotteryList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword = $request->param('keyword', '');

        $query = LotteryActivity::order('sort', 'asc')->order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_lottery_list', '查看抽奖活动列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 抽奖活动详情（含奖品）
     */
    public function lotteryDetail(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = LotteryActivity::find($id);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        $data = $activity->toArray();
        $data['prizes'] = LotteryPrize::where('activity_id', $id)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        return $this->success($data);
    }

    /**
     * 创建抽奖活动
     */
    public function lotteryCreate(Request $request)
    {
        $data = $this->collectLotteryData($request);
        $activity = LotteryActivity::create($data);

        $this->operationLog('admin_lottery_create', "创建抽奖活动: {$activity->name}");

        return $this->success($activity->toArray(), '创建成功');
    }

    /**
     * 更新抽奖活动
     */
    public function lotteryUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = LotteryActivity::find($id);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        $data = $this->collectLotteryData($request);
        $activity->save($data);

        $this->operationLog('admin_lottery_update', "更新抽奖活动: {$activity->name}");

        return $this->success($activity->toArray(), '更新成功');
    }

    /**
     * 删除抽奖活动
     */
    public function lotteryDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = LotteryActivity::find($id);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        $activity->delete();

        $this->operationLog('admin_lottery_delete', "删除抽奖活动: {$activity->name}");

        return $this->success(null, '删除成功');
    }

    /**
     * 切换抽奖活动状态
     */
    public function lotteryToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = LotteryActivity::find($id);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        $newStatus = $activity->getData('status') == LotteryActivity::STATUS_ENABLED
            ? LotteryActivity::STATUS_DISABLED
            : LotteryActivity::STATUS_ENABLED;

        $activity->status = $newStatus;
        $activity->save();

        $this->operationLog('admin_lottery_toggle', "切换抽奖活动状态: {$activity->name}, 新状态: {$newStatus}");

        return $this->success(['status' => $newStatus], '状态更新成功');
    }

    private function collectLotteryData(Request $request): array
    {
        return [
            'name'        => $request->param('name', ''),
            'type'        => $request->param('type', 'wheel'),
            'cost_type'   => $request->param('cost_type', 'free'),
            'cost_value'  => $request->param('cost_value', '0'),
            'daily_limit' => $request->paramInt('daily_limit', 0),
            'total_limit' => $request->paramInt('total_limit', 0),
            'start_time'  => $request->param('start_time', null),
            'end_time'    => $request->param('end_time', null),
            'sort'        => $request->paramInt('sort', 0),
            'status'      => $request->paramInt('status', LotteryActivity::STATUS_ENABLED),
        ];
    }

    /**
     * 保存奖品列表
     */
    public function lotterySavePrizes(Request $request)
    {
        $activityId = $request->paramInt('activity_id', 0);
        $prizes = $request->param('prizes/a', []);

        if ($activityId <= 0) {
            return $this->error('活动ID无效');
        }

        $activity = LotteryActivity::find($activityId);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        foreach ($prizes as $prizeData) {
            $prizeId = $prizeData['id'] ?? 0;
            $data = [
                'name'        => $prizeData['name'] ?? '',
                'type'        => $prizeData['type'] ?? 'thank',
                'value'       => $prizeData['value'] ?? '0',
                'probability' => $prizeData['probability'] ?? '0',
                'sort'        => $prizeData['sort'] ?? 0,
                'image'       => $prizeData['image'] ?? '',
                'stock'       => $prizeData['stock'] ?? 0,
                'status'      => $prizeData['status'] ?? 1,
            ];

            if ($prizeId > 0) {
                $prize = LotteryPrize::find($prizeId);
                if ($prize) {
                    $prize->save($data);
                }
            } else {
                $data['activity_id'] = $activityId;
                LotteryPrize::create($data);
            }
        }

        $this->operationLog('admin_lottery_save_prizes', "保存抽奖活动奖品，活动ID: {$activityId}");

        return $this->success(null, '保存成功');
    }

    /**
     * 删除奖品
     */
    public function lotteryPrizeDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $prize = LotteryPrize::find($id);
        if (!$prize) {
            return $this->error('奖品不存在', 404);
        }

        $prize->delete();

        $this->operationLog('admin_lottery_prize_delete', "删除抽奖奖品 ID:{$id}");

        return $this->success(null, '删除成功');
    }

    /**
     * 抽奖记录
     */
    public function lotteryRecords(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $activityId = $request->paramInt('activity_id', 0);
        $userId = $request->paramInt('user_id', 0);

        $query = LotteryRecord::order('id', 'desc');

        if ($activityId > 0) {
            $query->where('activity_id', $activityId);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 拼团活动管理 =====================

    /**
     * 拼团活动列表
     */
    public function groupBuyList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword = $request->param('keyword', '');

        $query = GroupBuyActivity::order('sort', 'asc')->order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_group_buy_list', '查看拼团活动列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建拼团活动
     */
    public function groupBuyCreate(Request $request)
    {
        $data = $this->collectGroupBuyData($request);
        $activity = GroupBuyActivity::create($data);

        $this->operationLog('admin_group_buy_create', "创建拼团活动: {$activity->name}");

        return $this->success($activity->toArray(), '创建成功');
    }

    /**
     * 更新拼团活动
     */
    public function groupBuyUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = GroupBuyActivity::find($id);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        $data = $this->collectGroupBuyData($request);
        $activity->save($data);

        $this->operationLog('admin_group_buy_update', "更新拼团活动: {$activity->name}");

        return $this->success($activity->toArray(), '更新成功');
    }

    /**
     * 删除拼团活动
     */
    public function groupBuyDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = GroupBuyActivity::find($id);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        $activity->delete();

        $this->operationLog('admin_group_buy_delete', "删除拼团活动: {$activity->name}");

        return $this->success(null, '删除成功');
    }

    /**
     * 切换拼团活动状态
     */
    public function groupBuyToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('ID无效');
        }

        $activity = GroupBuyActivity::find($id);
        if (!$activity) {
            return $this->error('活动不存在', 404);
        }

        $newStatus = $activity->getData('status') == GroupBuyActivity::STATUS_ENABLED
            ? GroupBuyActivity::STATUS_DISABLED
            : GroupBuyActivity::STATUS_ENABLED;

        $activity->status = $newStatus;
        $activity->save();

        $this->operationLog('admin_group_buy_toggle', "切换拼团活动状态: {$activity->name}, 新状态: {$newStatus}");

        return $this->success(['status' => $newStatus], '状态更新成功');
    }

    private function collectGroupBuyData(Request $request): array
    {
        return [
            'game_id'        => $request->paramInt('game_id', 0),
            'name'           => $request->param('name', ''),
            'original_price' => $request->param('original_price', '0'),
            'group_price'    => $request->param('group_price', '0'),
            'min_people'     => $request->paramInt('min_people', 2),
            'max_people'     => $request->paramInt('max_people', 5),
            'duration_hours' => $request->paramInt('duration_hours', 24),
            'sort'           => $request->paramInt('sort', 0),
            'status'         => $request->paramInt('status', GroupBuyActivity::STATUS_ENABLED),
        ];
    }

    /**
     * 拼团订单列表
     */
    public function groupBuyOrders(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $activityId = $request->paramInt('activity_id', 0);

        $query = GroupBuyOrder::order('id', 'desc');

        if ($activityId > 0) {
            $query->where('activity_id', $activityId);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }
}
