<?php
declare(strict_types=1);

namespace app\service;

use app\model\Admin;
use app\model\AdminOperationLog;
use think\facade\Log;

/**
 * 管理员操作日志服务
 * 负责操作日志的记录、查询、导出
 */
class AdminLogService
{
    /**
     * 记录操作日志
     * @param int    $adminId
     * @param string $module
     * @param string $action
     * @param array  $params
     * @param bool   $result
     * @param string $ip
     * @param string $device
     * @return bool
     */
    public static function record(
        int $adminId,
        string $module,
        string $action,
        array $params = [],
        bool $result = true,
        string $ip = '',
        string $device = ''
    ): bool {
        try {
            $admin = Admin::find($adminId);
            $username = $admin ? $admin->getData('username') : '';

            if (empty($ip)) {
                $ip = get_client_ip();
            }
            if (empty($device)) {
                $device = request()->header('user-agent', '');
            }

            AdminOperationLog::create([
                'admin_id'    => $adminId,
                'username'    => $username,
                'module'      => $module,
                'action'      => $action,
                'ip'          => $ip,
                'device'      => $device,
                'params_json' => $params,
                'result'      => $result ? 1 : 0,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('操作日志记录失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 分页查询操作日志
     * @param array $filters
     * @param int   $page
     * @param int   $limit
     * @return array
     */
    public static function getList(array $filters, int $page = 1, int $limit = 15): array
    {
        $query = AdminOperationLog::with('admin')->latest();

        if (!empty($filters['admin_id'])) {
            $query->byAdmin((int)$filters['admin_id']);
        }
        if (!empty($filters['module'])) {
            $query->byModule($filters['module']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }
        if (!empty($filters['ip'])) {
            $query->byIp($filters['ip']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('create_time', '>=', $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $query->where('create_time', '<=', $filters['end_date'] . ' 23:59:59');
        }
        if (isset($filters['result']) && $filters['result'] !== '') {
            $query->where('result', (int)$filters['result']);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /**
     * 获取操作日志统计
     * @param int $days
     * @return array
     */
    public static function getStats(int $days = 7): array
    {
        $startDate = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
        $endDate = date('Y-m-d');

        $logs = AdminOperationLog::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->field("DATE(create_time) as date, COUNT(*) as count")
            ->group('date')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        $countMap = array_column($logs, 'count', 'date');

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = [
                'date'  => $date,
                'count' => (int)($countMap[$date] ?? 0),
            ];
        }

        $totalCount = AdminOperationLog::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->count();

        $failCount = AdminOperationLog::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->where('result', 0)
            ->count();

        $moduleStats = AdminOperationLog::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->field('module, COUNT(*) as count')
            ->group('module')
            ->order('count', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        return [
            'total_count'   => $totalCount,
            'fail_count'    => $failCount,
            'success_rate'  => $totalCount > 0 ? round(($totalCount - $failCount) / $totalCount * 100, 2) : 100,
            'trend'         => $trend,
            'module_stats'  => $moduleStats,
        ];
    }

    /**
     * 导出操作日志为CSV
     * @param array $filters
     * @return string CSV内容
     */
    public static function exportCsv(array $filters): string
    {
        $query = AdminOperationLog::with('admin')->latest();

        if (!empty($filters['admin_id'])) {
            $query->byAdmin((int)$filters['admin_id']);
        }
        if (!empty($filters['module'])) {
            $query->byModule($filters['module']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }
        if (!empty($filters['ip'])) {
            $query->byIp($filters['ip']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('create_time', '>=', $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $query->where('create_time', '<=', $filters['end_date'] . ' 23:59:59');
        }

        $list = $query->limit(10000)->select()->toArray();

        $headers = ['ID', '管理员ID', '管理员', '模块', '操作', 'IP', '结果', '时间'];
        $rows = [];
        $rows[] = implode(',', $headers);

        foreach ($list as $item) {
            $row = [
                $item['id'],
                $item['admin_id'],
                $item['username'] ?? '',
                $item['module'],
                $item['action'],
                $item['ip'],
                $item['result'] == 1 ? '成功' : '失败',
                $item['create_time'],
            ];
            $rows[] = implode(',', array_map(function ($v) {
                return '"' . str_replace('"', '""', (string)$v) . '"';
            }, $row));
        }

        return implode("\n", $rows);
    }

    /**
     * 获取模块列表
     * @return array
     */
    public static function getModuleList(): array
    {
        $modules = AdminOperationLog::field('module')
            ->group('module')
            ->order('module', 'asc')
            ->select()
            ->toArray();

        return array_column($modules, 'module');
    }
}
