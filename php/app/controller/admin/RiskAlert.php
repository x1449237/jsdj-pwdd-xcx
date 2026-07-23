<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\AiRiskAlertService;
use think\Request;

/**
 * AI风险预警控制器
 */
class RiskAlert extends BaseController
{
    /**
     * 风险预警列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $filters = [
            'alert_type'  => $request->param('alert_type', ''),
            'risk_level'  => $request->param('risk_level', ''),
            'status'      => $request->param('status', ''),
            'user_id'     => $request->param('user_id', 0),
            'start_date'  => $request->param('start_date', ''),
            'end_date'    => $request->param('end_date', ''),
        ];

        $result = AiRiskAlertService::getList($filters, $page, $limit);

        $this->operationLog('admin_risk_alert', '查看AI风险预警列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    /**
     * 风险预警统计
     */
    public function stats(Request $request)
    {
        $stats = AiRiskAlertService::getStats();

        return $this->success($stats);
    }

    /**
     * 处理风险预警
     */
    public function handle(Request $request)
    {
        $alertId = $request->paramInt('id', 0);
        $action  = $request->param('action', '');
        $remark  = $request->param('remark', '');

        if ($alertId <= 0) {
            return $this->error('预警ID无效');
        }

        $validActions = ['handle', 'ignore', 'processing'];
        if (!in_array($action, $validActions)) {
            return $this->error('无效的处理操作');
        }

        $result = AiRiskAlertService::handleAlert($alertId, $this->adminId(), $action, $remark);

        if (!$result) {
            return $this->error('处理失败');
        }

        $this->operationLog('admin_risk_alert_handle', "处理风险预警 ID:{$alertId}, 操作:{$action}");

        return $this->success(null, '处理成功');
    }

    /**
     * 批量处理
     */
    public function batchHandle(Request $request)
    {
        $alertIds = $request->param('alert_ids', '');
        $action   = $request->param('action', '');
        $remark   = $request->param('remark', '');

        $ids = array_filter(array_map('intval', explode(',', $alertIds)));

        if (empty($ids)) {
            return $this->error('请选择预警记录');
        }

        $validActions = ['handle', 'ignore'];
        if (!in_array($action, $validActions)) {
            return $this->error('无效的处理操作');
        }

        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $result = AiRiskAlertService::handleAlert($id, $this->adminId(), $action, $remark);
            if ($result) {
                $success++;
            } else {
                $failed++;
            }
        }

        $this->operationLog('admin_risk_alert_batch', "批量处理风险预警, 操作:{$action}, 成功{$success}条, 失败{$failed}条");

        return $this->success([
            'success' => $success,
            'failed'  => $failed,
            'total'   => count($ids),
        ], "批量处理完成，成功{$success}条");
    }

    /**
     * 批量封禁用户
     */
    public function batchBanUsers(Request $request)
    {
        $userIds = $request->param('user_ids', '');
        $reason  = $request->param('reason', '');

        $ids = array_filter(array_map('intval', explode(',', $userIds)));

        if (empty($ids)) {
            return $this->error('请选择用户');
        }

        if (count($ids) > 100) {
            return $this->error('单次最多封禁100个用户');
        }

        $result = AiRiskAlertService::batchBanUsers($ids, $this->adminId(), $reason);

        $this->operationLog('admin_risk_alert_ban', "批量封禁用户, 成功{$result['success']}条, 失败{$result['failed']}条");

        return $this->success($result, "批量封禁完成，成功{$result['success']}条");
    }
}
