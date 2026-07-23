<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\ClubAbbreviation;
use app\model\SystemConfig;
use app\model\UserVBadge;
use think\Request;

/**
 * 俱乐部管理（后台）
 * 子模块：俱乐部列表 / 保证金管理 / 对公打款验证
 */
class ClubManage extends BaseController
{
    /**
     * 全量俱乐部列表
     */
    public function clubList(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $query = UserVBadge::with(['user'])->order('create_time', 'desc');

        // 筛选
        $clubStatus = $request->param('club_status', '');
        $badgeType  = $request->param('badge_type', '');
        $auditStatus = $request->param('audit_status', '');
        $keyword    = $request->param('keyword', '');

        if (!empty($clubStatus)) {
            $query->where('club_status', $clubStatus);
        }
        if (!empty($badgeType)) {
            $query->where('badge_type', $badgeType);
        }
        if ($auditStatus !== '') {
            $query->where('audit_status', (int) $auditStatus);
        }
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('club_name', 'like', "%{$keyword}%")
                  ->whereOr('abbreviation', 'like', "%{$keyword}%")
                  ->whereOr('real_name', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_club_list', '查看俱乐部列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 俱乐部详情（全字段）
     */
    public function clubDetail(Request $request)
    {
        $clubId = $request->paramInt('id', 0);
        if ($clubId <= 0) {
            return $this->error('俱乐部ID无效');
        }

        $club = UserVBadge::with(['user'])->find($clubId);
        if (!$club) {
            return $this->error('俱乐部不存在', 404);
        }

        $data = $club->toArray();

        // 缩写历史
        $data['abbr_history'] = ClubAbbreviation::where('club_id', $clubId)
            ->order('create_time', 'asc')->select()->toArray();

        // 审核人
        if ($club->auditor_id > 0) {
            $auditor = \app\model\Admin::find($club->auditor_id);
            $data['auditor'] = $auditor ? ['id' => $auditor->id, 'username' => $auditor->getData('username')] : null;
        }

        $this->operationLog('admin_club_detail', "查看俱乐部详情: {$club->club_name}");

        return $this->success($data);
    }

    /**
     * 冻结俱乐部
     */
    public function freezeClub(Request $request)
    {
        $clubId = $request->paramInt('id', 0);
        $reason = $request->param('reason', '');

        $club = UserVBadge::find($clubId);
        if (!$club) return $this->error('俱乐部不存在', 404);
        if ($club->club_status !== UserVBadge::STATUS_ACTIVE) {
            return $this->error('仅正常运营的俱乐部可冻结');
        }

        $club->club_status = UserVBadge::STATUS_FROZEN;
        $club->is_active   = 0;
        $club->save();

        ClubAbbreviation::where('club_id', $clubId)->update(['club_status' => 'frozen']);

        $this->operationLog('admin_club_freeze', "冻结俱乐部: {$club->club_name}, 原因: {$reason}");

        return $this->success(null, '俱乐部已冻结，V标已熄灭');
    }

    /**
     * 解冻俱乐部
     */
    public function unfreezeClub(Request $request)
    {
        $clubId = $request->paramInt('id', 0);

        $club = UserVBadge::find($clubId);
        if (!$club) return $this->error('俱乐部不存在', 404);
        if ($club->club_status !== UserVBadge::STATUS_FROZEN) {
            return $this->error('仅冻结状态的俱乐部可解冻');
        }

        $club->club_status = UserVBadge::STATUS_ACTIVE;
        $club->is_active   = 1;
        $club->save();

        ClubAbbreviation::where('club_id', $clubId)->update(['club_status' => 'active']);

        $this->operationLog('admin_club_unfreeze', "解冻俱乐部: {$club->club_name}");

        return $this->success(null, '俱乐部已解冻，V标已点亮');
    }

    /**
     * 停业/注销俱乐部
     */
    public function cancelClub(Request $request)
    {
        $clubId = $request->paramInt('id', 0);
        $action = $request->param('action', 'closed'); // closed / cancelled
        $reason = $request->param('reason', '');

        $club = UserVBadge::find($clubId);
        if (!$club) return $this->error('俱乐部不存在', 404);
        if (!in_array($action, ['closed', 'cancelled'])) {
            return $this->error('无效操作');
        }

        $club->club_status = $action;
        $club->is_active   = 0;
        $club->save();

        ClubAbbreviation::where('club_id', $clubId)->update(['club_status' => $action]);

        $actionName = $action === 'closed' ? '停业' : '注销';
        $this->operationLog('admin_club_cancel', "{$actionName}俱乐部: {$club->club_name}, 原因: {$reason}");

        return $this->success(null, "俱乐部已{$actionName}");
    }

    /**
     * 保证金列表
     */
    public function depositList(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $query = UserVBadge::with(['user'])
            ->where('deposit_amount', '>', 0)
            ->order('update_time', 'desc');

        $depositStatus = $request->param('deposit_status', '');
        if ($depositStatus !== '') {
            $query->where('deposit_status', (int) $depositStatus);
        }

        $keyword = $request->param('keyword', '');
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('club_name', 'like', "%{$keyword}%")
                  ->whereOr('abbreviation', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 确认保证金到账
     */
    public function confirmDeposit(Request $request)
    {
        $clubId = $request->paramInt('id', 0);

        $club = UserVBadge::find($clubId);
        if (!$club) return $this->error('俱乐部不存在', 404);
        if ($club->deposit_status !== UserVBadge::DEPOSIT_UNPAID) {
            return $this->error('保证金状态异常');
        }

        $club->deposit_status   = UserVBadge::DEPOSIT_PAID;
        $club->deposit_pay_time = date('Y-m-d H:i:s');
        $club->is_active        = 1;
        $club->club_status      = UserVBadge::STATUS_ACTIVE;
        $club->save();

        ClubAbbreviation::where('club_id', $clubId)->update(['club_status' => 'active']);

        $this->operationLog('admin_club_deposit_confirm', "确认保证金: {$club->club_name}, {$club->deposit_amount}元");

        return $this->success(null, '保证金已确认到账，俱乐部已激活');
    }

    /**
     * 退还保证金
     */
    public function refundDeposit(Request $request)
    {
        $clubId = $request->paramInt('id', 0);
        $reason = $request->param('reason', '');

        $club = UserVBadge::find($clubId);
        if (!$club) return $this->error('俱乐部不存在', 404);
        if ($club->deposit_status !== UserVBadge::DEPOSIT_PAID) {
            return $this->error('保证金状态异常，仅已缴纳的可退还');
        }

        $club->deposit_status = UserVBadge::DEPOSIT_REFUND;
        $club->save();

        $this->operationLog('admin_club_deposit_refund', "退还保证金: {$club->club_name}, {$club->deposit_amount}元, 原因: {$reason}");

        return $this->success(null, '保证金已退还');
    }

    /**
     * 对公打款验证列表
     */
    public function corporateTransferList(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $query = UserVBadge::where('is_enterprise', 1)
            ->where('verification_status', '>', 0)
            ->order('update_time', 'desc');

        $verifyStatus = $request->param('verification_status', '');
        if ($verifyStatus !== '') {
            $query->where('verification_status', (int) $verifyStatus);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 审核对公打款验证
     */
    public function verifyCorporateTransfer(Request $request)
    {
        $clubId = $request->paramInt('id', 0);
        $action = $request->param('action', ''); // pass / fail
        $reason = $request->param('reason', '');

        $club = UserVBadge::where('id', $clubId)
            ->where('is_enterprise', 1)
            ->where('verification_status', UserVBadge::VERIFY_WAITING)
            ->find();

        if (!$club) {
            return $this->error('未找到待审核的打款记录', 404);
        }

        if ($action === 'pass') {
            $club->verification_status = UserVBadge::VERIFY_PASSED;
            $msg = '对公打款验证通过';
        } else {
            $club->verification_status = UserVBadge::VERIFY_FAILED;
            $msg = '对公打款验证已驳回';
        }
        $club->save();

        $this->operationLog('admin_club_verify_transfer', "{$msg}: {$club->club_name}, 验证金额: {$club->verification_amount}");

        return $this->success(null, $msg);
    }

    /**
     * 更新俱乐部保证金配置
     */
    public function updateDepositConfig(Request $request)
    {
        $personal   = $request->paramInt('personal_deposit', 0);
        $enterprise = $request->paramInt('enterprise_deposit', 0);

        SystemConfig::setValue('club_personal_deposit', $personal);
        SystemConfig::setValue('club_enterprise_deposit', $enterprise);

        $this->operationLog('admin_club_deposit_config', "更新保证金: 个人={$personal}, 企业={$enterprise}");

        return $this->success(null, '保证金配置已更新');
    }
}