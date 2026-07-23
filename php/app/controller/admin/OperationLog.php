<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\AdminLogService;
use think\Request;

/**
 * 操作日志控制器
 */
class OperationLog extends BaseController
{
    /**
     * 操作日志列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();

        $filters = [
            'admin_id'   => $request->param('admin_id', 0),
            'module'     => $request->param('module', ''),
            'action'     => $request->param('action', ''),
            'ip'         => $request->param('ip', ''),
            'start_date' => $request->param('start_date', ''),
            'end_date'   => $request->param('end_date', ''),
            'result'     => $request->param('result', ''),
        ];

        $result = AdminLogService::getList($filters, $page, $limit);

        $this->operationLog('admin_operation_log', '查看操作日志列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    /**
     * 操作日志统计
     */
    public function stats(Request $request)
    {
        $days = $request->paramInt('days', 7);
        $days = min(max($days, 1), 30);

        $stats = AdminLogService::getStats($days);

        return $this->success($stats);
    }

    /**
     * 导出操作日志CSV
     */
    public function export(Request $request)
    {
        $filters = [
            'admin_id'   => $request->param('admin_id', 0),
            'module'     => $request->param('module', ''),
            'action'     => $request->param('action', ''),
            'ip'         => $request->param('ip', ''),
            'start_date' => $request->param('start_date', ''),
            'end_date'   => $request->param('end_date', ''),
            'result'     => $request->param('result', ''),
        ];

        $csvContent = AdminLogService::exportCsv($filters);

        $this->operationLog('admin_operation_log_export', '导出操作日志CSV');

        $filename = 'operation_log_' . date('YmdHis') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * 获取模块列表
     */
    public function modules(Request $request)
    {
        $modules = AdminLogService::getModuleList();

        return $this->success($modules);
    }
}
