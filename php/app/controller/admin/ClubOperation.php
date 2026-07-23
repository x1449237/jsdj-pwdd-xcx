<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\ClubOperationService;
use app\service\ClubMemberService;
use app\model\ClubDepositTier;
use app\model\ClubDynamic;
use app\model\ClubInternalOrder;
use app\model\UserVBadge;
use think\Request;

class ClubOperation extends BaseController
{
    protected $operationService;
    protected $memberService;

    public function __construct()
    {
        $this->operationService = new ClubOperationService();
        $this->memberService = new ClubMemberService();
    }

    // ========== 保证金阶梯配置 ==========

    public function depositTierList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $clubType = $request->param('club_type', '');

        $query = ClubDepositTier::order('club_type', 'asc')
            ->order('revenue_threshold', 'asc');

        if (!empty($clubType)) {
            $query->where('club_type', $clubType);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['revenue_threshold_yuan'] = bcdiv((string) $item['revenue_threshold'], '100', 2);
            $item['deposit_amount_yuan']    = bcdiv((string) $item['deposit_amount'], '100', 2);
        }

        return $this->page($list, $total, $page, $limit);
    }

    public function depositTierCreate(Request $request)
    {
        $data = $request->post();

        $this->validateRequired($data, ['club_type', 'tier_name', 'revenue_threshold', 'deposit_amount']);

        ClubDepositTier::create([
            'club_type'         => $data['club_type'],
            'tier_name'         => $data['tier_name'],
            'revenue_threshold' => (int) ($data['revenue_threshold'] * 100),
            'deposit_amount'    => (int) ($data['deposit_amount'] * 100),
            'status'            => $data['status'] ?? 1,
        ]);

        $this->operationLog('admin_club_deposit_tier_create', "创建保证金阶梯: {$data['tier_name']}");

        return $this->success(null, '创建成功');
    }

    public function depositTierUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $tier = ClubDepositTier::find($id);
        if (!$tier) {
            return $this->error('阶梯配置不存在', 404);
        }

        $data = $request->post();

        if (isset($data['tier_name'])) {
            $tier->tier_name = $data['tier_name'];
        }
        if (isset($data['revenue_threshold'])) {
            $tier->revenue_threshold = (int) ($data['revenue_threshold'] * 100);
        }
        if (isset($data['deposit_amount'])) {
            $tier->deposit_amount = (int) ($data['deposit_amount'] * 100);
        }
        if (isset($data['status'])) {
            $tier->status = (int) $data['status'];
        }
        if (isset($data['club_type'])) {
            $tier->club_type = $data['club_type'];
        }

        $tier->save();

        $this->operationLog('admin_club_deposit_tier_update', "更新保证金阶梯: id={$id}");

        return $this->success(null, '更新成功');
    }

    public function depositTierDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $tier = ClubDepositTier::find($id);
        if (!$tier) {
            return $this->error('阶梯配置不存在', 404);
        }

        $tier->delete();

        $this->operationLog('admin_club_deposit_tier_delete', "删除保证金阶梯: id={$id}");

        return $this->success(null, '删除成功');
    }

    // ========== 运营数据看板 ==========

    public function operationDashboard(Request $request)
    {
        $clubId = $request->paramInt('club_id', 0);
        $days = $request->paramInt('days', 30);

        $totalClubs = UserVBadge::where('club_status', UserVBadge::STATUS_ACTIVE)->count();
        $totalMembers = \app\model\ClubMember::where('status', 1)->count();
        $totalOrders = ClubInternalOrder::count();
        $totalRevenue = ClubInternalOrder::where('status', 4)->sum('reward');

        $trendData = [];
        if ($clubId > 0) {
            $trendData = $this->operationService->getTrendData($clubId, $days);
        }

        return $this->success([
            'total_clubs'    => $totalClubs,
            'total_members'  => $totalMembers,
            'total_orders'   => $totalOrders,
            'total_revenue'  => $totalRevenue,
            'total_revenue_yuan' => bcdiv((string) $totalRevenue, '100', 2),
            'trend'          => $trendData,
        ]);
    }

    // ========== 内部订单监控 ==========

    public function internalOrderList(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $query = ClubInternalOrder::with(['club', 'player'])
            ->order('create_time', 'desc');

        $clubId = $request->paramInt('club_id', 0);
        $status = $request->param('status', '');
        $keyword = $request->param('keyword', '');

        if ($clubId > 0) {
            $query->where('club_id', $clubId);
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                  ->whereOr('order_no', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $statusMap = ClubInternalOrder::getStatusMap();
        foreach ($list as &$item) {
            $item['status_name'] = $statusMap[$item['status']] ?? '';
            $item['reward_yuan'] = bcdiv((string) $item['reward'], '100', 2);
            $item['club_name'] = $item['club']['club_name'] ?? '';
            $item['player_nickname'] = $item['player']['nickname'] ?? '';
            unset($item['club'], $item['player']);
        }

        $this->operationLog('admin_club_internal_order_list', '查看内部订单列表');

        return $this->page($list, $total, $page, $limit);
    }

    public function internalOrderDetail(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $order = ClubInternalOrder::with(['club', 'player'])->find($id);
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        $data = $order->toArray();
        $data['status_name'] = ClubInternalOrder::getStatusMap()[$order->status] ?? '';
        $data['reward_yuan'] = bcdiv((string) $order->reward, '100', 2);

        return $this->success($data);
    }

    // ========== 动态审核 ==========

    public function dynamicAuditList(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $query = ClubDynamic::with(['club', 'player'])
            ->order('create_time', 'desc');

        $status = $request->param('status', 0);
        $clubId = $request->paramInt('club_id', 0);

        if ($clubId > 0) {
            $query->where('club_id', $clubId);
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $statusMap = ClubDynamic::getStatusMap();
        $typeMap = ClubDynamic::getTypeMap();
        foreach ($list as &$item) {
            $item['status_name'] = $statusMap[$item['status']] ?? '';
            $item['type_name'] = $typeMap[$item['type']] ?? '';
            $item['club_name'] = $item['club']['club_name'] ?? '';
            $item['player_nickname'] = $item['player']['nickname'] ?? '';
            $item['images'] = !empty($item['images_json']) ? json_decode($item['images_json'], true) : [];
            unset($item['images_json'], $item['club'], $item['player']);
        }

        $this->operationLog('admin_club_dynamic_audit_list', '查看动态审核列表');

        return $this->page($list, $total, $page, $limit);
    }

    public function dynamicAudit(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $action = $request->param('action', ''); // pass / reject

        if (!in_array($action, ['pass', 'reject'])) {
            return $this->error('无效操作');
        }

        $dynamic = ClubDynamic::find($id);
        if (!$dynamic) {
            return $this->error('动态不存在', 404);
        }

        $dynamic->status = $action === 'pass' ? ClubDynamic::STATUS_APPROVED : ClubDynamic::STATUS_REJECTED;
        $dynamic->save();

        $this->operationLog('admin_club_dynamic_audit', "动态审核: id={$id}, action={$action}");

        return $this->success(null, $action === 'pass' ? '审核通过' : '已驳回');
    }

    // ========== 优惠券管理（后台） ==========

    public function couponList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $clubId = $request->paramInt('club_id', 0);

        $query = \app\model\ClubCoupon::with(['club'])
            ->order('create_time', 'desc');

        if ($clubId > 0) {
            $query->where('club_id', $clubId);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['value_yuan'] = bcdiv((string) $item['value'], '100', 2);
            $item['min_amount_yuan'] = bcdiv((string) $item['min_amount'], '100', 2);
            $item['club_name'] = $item['club']['club_name'] ?? '';
            unset($item['club']);
        }

        return $this->page($list, $total, $page, $limit);
    }

    public function couponToggleStatus(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $coupon = \app\model\ClubCoupon::find($id);
        if (!$coupon) {
            return $this->error('优惠券不存在', 404);
        }

        $coupon->status = $coupon->status ? 0 : 1;
        $coupon->save();

        $this->operationLog('admin_club_coupon_toggle', "优惠券状态切换: id={$id}");

        return $this->success(null, '状态已更新');
    }
}
