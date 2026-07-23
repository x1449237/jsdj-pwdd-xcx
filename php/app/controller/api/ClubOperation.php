<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\service\ClubOperationService;
use app\service\ClubMemberService;
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

    // ========== 内部订单 ==========

    public function internalOrderList(Request $request)
    {
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');

        $result = $this->operationService->getInternalOrderList($clubId, [
            'page'   => $page,
            'limit'  => $limit,
            'status' => $status,
        ]);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function acceptInternalOrder(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('id', 0);
        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $result = $this->operationService->acceptInternalOrder($orderId, $userId);
            return $this->success($result, '接单成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ========== 优惠券 ==========

    public function couponList(Request $request)
    {
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        [$page, $limit] = $this->pageParams();
        $type = $request->param('type', '');

        $result = $this->operationService->getCouponList($clubId, [
            'page'  => $page,
            'limit' => $limit,
            'type'  => $type,
        ]);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function receiveCoupon(Request $request)
    {
        $userId = request()->userId();
        $couponId = $request->paramInt('id', 0);
        if ($couponId <= 0) {
            return $this->error('优惠券ID无效');
        }

        try {
            $result = $this->operationService->receiveCoupon($couponId, $userId);
            return $this->success($result, '领取成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function myCoupons(Request $request)
    {
        $userId = request()->userId();
        $clubId = $request->paramInt('club_id', 0);

        $list = $this->operationService->getUserCoupons($userId, $clubId);
        return $this->success($list);
    }

    // ========== 动态/战绩 ==========

    public function dynamicList(Request $request)
    {
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        [$page, $limit] = $this->pageParams();
        $type = $request->param('type', '');

        $result = $this->operationService->getDynamicList($clubId, [
            'page'   => $page,
            'limit'  => $limit,
            'type'   => $type,
            'status' => 1,
        ]);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function publishDynamic(Request $request)
    {
        $userId = request()->userId();
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        $data = $request->post();

        try {
            $result = $this->operationService->publishDynamic($clubId, $userId, $data);
            return $this->success($result, '发布成功，等待审核');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ========== 分店/分区 ==========

    public function branchList(Request $request)
    {
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        $list = $this->operationService->getBranchList($clubId);
        return $this->success($list);
    }

    // ========== 成员相关 ==========

    public function memberList(Request $request)
    {
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        [$page, $limit] = $this->pageParams();
        $role = $request->param('role', '');
        $keyword = $request->param('keyword', '');

        $result = $this->memberService->getMemberList($clubId, [
            'page'    => $page,
            'limit'   => $limit,
            'role'    => $role,
            'keyword' => $keyword,
        ]);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function joinApply(Request $request)
    {
        $userId = request()->userId();
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        try {
            $result = $this->memberService->joinApply($clubId, $userId);
            return $this->success($result, '申请已提交，等待审核');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function myRoles()
    {
        $userId = request()->userId();
        $roles = $this->memberService->getUserClubRole($userId);
        return $this->success($roles);
    }

    // ========== 公告 ==========

    public function announcementList(Request $request)
    {
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        [$page, $limit] = $this->pageParams();
        $result = $this->operationService->getAnnouncementList($clubId, $page, $limit);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    // ========== 缩写备选推荐 ==========

    public function abbrAlternatives(Request $request)
    {
        $clubName = $request->param('club_name', '');
        if (empty($clubName)) {
            return $this->error('俱乐部名称不能为空');
        }

        $alternatives = $this->operationService->generateAbbrAlternatives($clubName);
        return $this->success(['alternatives' => $alternatives]);
    }

    // ========== 俱乐部管理端数据 ==========

    public function manageDashboard(Request $request)
    {
        $userId = request()->userId();
        $clubId = $request->paramInt('club_id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        $role = \app\model\ClubMember::getUserRole($clubId, $userId);
        if (!$role || !in_array($role, ['founder', 'manager'])) {
            return $this->error('无管理权限', 403);
        }

        $data = $this->operationService->getDashboardData($clubId);
        $data['my_role'] = $role;
        $data['my_role_name'] = \app\model\ClubMember::getRoleMap()[$role] ?? $role;

        return $this->success($data);
    }

    public function manageTrend(Request $request)
    {
        $userId = request()->userId();
        $clubId = $request->paramInt('club_id', 0);
        $days = $request->paramInt('days', 7);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        $role = \app\model\ClubMember::getUserRole($clubId, $userId);
        if (!$role || !in_array($role, ['founder', 'manager'])) {
            return $this->error('无管理权限', 403);
        }

        $data = $this->operationService->getTrendData($clubId, $days);
        return $this->success($data);
    }
}
