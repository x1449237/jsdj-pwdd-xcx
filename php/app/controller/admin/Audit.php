<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\JoinUsLog;
use app\model\User;
use think\Request;

/**
 * 入驻审核控制器
 */
class Audit extends BaseController
{
    /**
     * 俱乐部入驻审核列表
     */
    public function clubList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $auditStatus = $request->param('audit_status', '');
        $clubType    = $request->param('club_type', '');

        $query = \app\model\UserVBadge::with(['user'])->order('create_time', 'desc');

        if ($auditStatus !== '') {
            $query->where('audit_status', (int)$auditStatus);
        }
        if (!empty($clubType)) {
            $query->where('badge_type', $clubType);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_audit_club_list', '查看俱乐部入驻审核列表');

        return $this->page($list, $total, $page, $limit);
    }
    /**
     * 打手审核列表
     */
    public function playerList(Request $request)
    {
        return $this->getAuditList($request, User::TYPE_PLAYER, 'player');
    }

    /**
     * 分销商审核列表
     */
    public function distributorList(Request $request)
    {
        return $this->getAuditList($request, 'distributor', 'distributor');
    }

    /**
     * 派单员审核列表
     */
    public function dispatcherList(Request $request)
    {
        return $this->getAuditList($request, 'dispatcher', 'dispatcher');
    }

    /**
     * 内置管理员审核列表
     */
    public function adminList(Request $request)
    {
        return $this->getAuditList($request, 'admin', 'admin');
    }

    /**
     * 审核通过
     */
    public function approve(Request $request)
    {
        $id     = $request->paramInt('id', 0);
        $type   = $request->param('type', '');
        $remark = $request->param('remark', '');

        if ($id <= 0) {
            return $this->error('申请ID无效');
        }

        // 俱乐部入驻审核
        if ($type === 'club') {
            $club = \app\model\UserVBadge::find($id);
            if (!$club) {
                return $this->error('俱乐部入驻记录不存在', 404);
            }
            if ($club->audit_status != \app\model\UserVBadge::AUDIT_PENDING) {
                return $this->error('该申请已审核');
            }
            $club->audit_status = \app\model\UserVBadge::AUDIT_PASSED;
            $club->audit_time   = date('Y-m-d H:i:s');
            $club->auditor_id   = $this->adminId();
            $club->is_active    = 1;
            $club->save();

            $this->operationLog('admin_audit_club_approve', "俱乐部入驻审核通过，ID:{$id}，名称:{$club->club_name}");
            return $this->success(null, '俱乐部入驻审核通过，V标已点亮');
        }

        $log = JoinUsLog::find($id);
        if (!$log) {
            return $this->error('申请记录不存在', 404);
        }

        if ($log->getData('status') != JoinUsLog::STATUS_PENDING) {
            return $this->error('该申请已审核');
        }

        $log->status = JoinUsLog::STATUS_PASSED;
        $log->review_remark = $remark ?: '审核通过';
        $log->reviewer_id = $this->adminId();
        $log->review_time = date('Y-m-d H:i:s');
        $log->save();

        $this->operationLog('admin_audit_approve', "入驻审核通过，ID:{$id}");

        return $this->success(null, '审核通过');
    }

    /**
     * 审核拒绝
     */
    public function reject(Request $request)
    {
        $id     = $request->paramInt('id', 0);
        $type   = $request->param('type', '');
        $remark = $request->param('remark', '');

        if ($id <= 0) {
            return $this->error('申请ID无效');
        }

        if (empty($remark)) {
            return $this->error('拒绝原因不能为空');
        }

        // 俱乐部入驻审核
        if ($type === 'club') {
            $club = \app\model\UserVBadge::find($id);
            if (!$club) {
                return $this->error('俱乐部入驻记录不存在', 404);
            }
            if ($club->audit_status != \app\model\UserVBadge::AUDIT_PENDING) {
                return $this->error('该申请已审核');
            }
            $club->audit_status = \app\model\UserVBadge::AUDIT_REJECTED;
            $club->audit_time   = date('Y-m-d H:i:s');
            $club->auditor_id   = $this->adminId();
            $club->is_active    = 0;
            $club->save();

            $this->operationLog('admin_audit_club_reject', "俱乐部入驻审核拒绝，ID:{$id}，原因: {$remark}");
            return $this->success(null, '俱乐部入驻申请已驳回');
        }

        $log = JoinUsLog::find($id);
        if (!$log) {
            return $this->error('申请记录不存在', 404);
        }

        if ($log->getData('status') != JoinUsLog::STATUS_PENDING) {
            return $this->error('该申请已审核');
        }

        $log->status = JoinUsLog::STATUS_REJECT;
        $log->review_remark = $remark;
        $log->reviewer_id = $this->adminId();
        $log->review_time = date('Y-m-d H:i:s');
        $log->save();

        $this->operationLog('admin_audit_reject', "入驻审核拒绝，ID:{$id}，原因: {$remark}");

        return $this->success(null, '审核已拒绝');
    }

    /**
     * 强制下架
     */
    public function forceOffline(Request $request)
    {
        $id     = $request->paramInt('id', 0);
        $userId = $request->paramInt('user_id', 0);
        $type   = $request->param('type', '');
        $reason = $request->param('reason', '');

        // 俱乐部强制下架
        if ($type === 'club') {
            if ($id <= 0) {
                return $this->error('俱乐部ID无效');
            }
            $club = \app\model\UserVBadge::find($id);
            if (!$club) {
                return $this->error('俱乐部不存在', 404);
            }
            $club->is_active = 0;
            $club->save();

            $this->operationLog('admin_audit_club_force_offline', "俱乐部强制下架，ID:{$id}，名称:{$club->club_name}");
            return $this->success(null, '俱乐部已强制下架，V标已熄灭');
        }

        if ($userId <= 0) {
            return $this->error('用户ID无效');
        }

        if (empty($reason)) {
            return $this->error('下架原因不能为空');
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 禁用用户
        $user->status = User::STATUS_DISABLED;
        $user->save();

        // 记录下架日志
        $log = JoinUsLog::where('user_id', $userId)
            ->where('status', JoinUsLog::STATUS_PASSED)
            ->order('review_time', 'desc')
            ->find();

        if ($log) {
            $log->status = JoinUsLog::STATUS_REJECT;
            $log->review_remark = '强制下架: ' . $reason;
            $log->reviewer_id = $this->adminId();
            $log->review_time = date('Y-m-d H:i:s');
            $log->save();
        }

        $this->operationLog('admin_audit_force_offline', "强制下架用户 ID:{$userId}，原因: {$reason}");

        return $this->success(null, '用户已强制下架');
    }

    /**
     * 等级收益查看
     */
    public function levelIncome(Request $request)
    {
        $userId = $request->paramInt('user_id', 0);

        if ($userId <= 0) {
            return $this->error('用户ID无效');
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 获取用户等级
        $level = $user->getData('level');

        // 获取收益数据
        $totalIncome = \app\model\Order::where('player_id', $userId)
            ->where('status', \app\model\Order::STATUS_COMPLETED)
            ->sum('order_amount');

        $monthIncome = \app\model\Order::where('player_id', $userId)
            ->where('status', \app\model\Order::STATUS_COMPLETED)
            ->where('create_time', '>=', date('Y-m-01 00:00:00'))
            ->sum('order_amount');

        $commissionTotal = \app\model\DistributorCommission::where('user_id', $userId)
            ->sum('amount');

        // 获取打手服务配置
        $playerServices = $user->playerServices()->select()->toArray();

        $this->operationLog('admin_audit_level_income', "查看等级收益，用户ID:{$userId}");

        return $this->success([
            'user_id'          => $userId,
            'level'            => $level,
            'total_income'     => fen_to_yuan((int)$totalIncome),
            'month_income'     => fen_to_yuan((int)$monthIncome),
            'commission_total' => fen_to_yuan((int)$commissionTotal),
            'player_services'  => $playerServices,
        ]);
    }

    /**
     * 获取审核列表通用方法
     * @param Request $request
     * @param mixed   $type  用户类型
     * @param string  $typeName 类型名称
     * @return \think\Response
     */
    private function getAuditList(Request $request, $type, string $typeName): \think\Response
    {
        [$page, $limit] = $this->pageParams();
        $status   = $request->param('status', '');
        $keyword  = $request->param('keyword', '');

        $query = JoinUsLog::order('create_time', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('real_name', 'like', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%");
            });
        }

        // 按类型筛选：通过关联用户来筛选
        if (is_int($type)) {
            $query->whereHas('user', function ($q) use ($type) {
                $q->where('user_type', $type);
            });
        } else {
            // 对于非 user_type 的类型，通过 join_us_log 的 role_type 字段
            $query->where('role_type', $typeName);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_audit_list', "查看{$typeName}审核列表");

        return $this->page($list, $total, $page, $limit);
    }
}