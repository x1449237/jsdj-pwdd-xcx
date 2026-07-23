<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\ApiMonitorService;
use app\model\SystemSlowQueryLog;
use think\Request;

/**
 * 第三方接口监控控制器
 */
class ApiMonitor extends BaseController
{
    /**
     * 接口监控面板
     */
    public function index(Request $request)
    {
        $overview = ApiMonitorService::getOverview();
        $list = ApiMonitorService::getStatusList();

        $this->operationLog('admin_api_monitor', '查看第三方接口监控');

        return $this->success([
            'overview' => $overview,
            'list'     => $list,
        ]);
    }

    /**
     * 接口监控列表
     */
    public function list(Request $request)
    {
        $list = ApiMonitorService::getStatusList();

        return $this->success($list);
    }

    /**
     * 接口详情
     */
    public function detail(Request $request)
    {
        $apiType = $request->param('api_type', '');

        if (empty($apiType)) {
            return $this->error('接口类型不能为空');
        }

        $detail = ApiMonitorService::getDetail($apiType);
        if (!$detail) {
            return $this->error('接口监控不存在');
        }

        return $this->success($detail);
    }

    /**
     * 接口趋势数据
     */
    public function trend(Request $request)
    {
        $apiType = $request->param('api_type', '');
        $days = $request->paramInt('days', 7);
        $days = min(max($days, 1), 30);

        if (empty($apiType)) {
            return $this->error('接口类型不能为空');
        }

        $trend = ApiMonitorService::getTrend($apiType, $days);

        return $this->success([
            'days'  => $days,
            'trend' => $trend,
        ]);
    }

    /**
     * 更新告警阈值
     */
    public function updateThreshold(Request $request)
    {
        $apiType   = $request->param('api_type', '');
        $threshold = $request->param('threshold', 0);

        if (empty($apiType)) {
            return $this->error('接口类型不能为空');
        }

        $threshold = (float)$threshold;
        if ($threshold <= 0 || $threshold > 100) {
            return $this->error('阈值必须在0-100之间');
        }

        $result = ApiMonitorService::updateThreshold($apiType, $threshold);

        if (!$result) {
            return $this->error('更新失败');
        }

        $this->operationLog('admin_api_monitor_threshold', "更新告警阈值: {$apiType} = {$threshold}%");

        return $this->success(null, '更新成功');
    }

    /**
     * 重置统计
     */
    public function resetStats(Request $request)
    {
        $apiType = $request->param('api_type', '');

        if (empty($apiType)) {
            return $this->error('接口类型不能为空');
        }

        $result = ApiMonitorService::resetStats($apiType);

        if (!$result) {
            return $this->error('重置失败');
        }

        $this->operationLog('admin_api_monitor_reset', "重置接口统计: {$apiType}");

        return $this->success(null, '重置成功');
    }

    /**
     * 慢查询日志列表
     */
    public function slowQueryList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $minTime  = $request->paramInt('min_time_ms', 0);
        $dbName   = $request->param('db_name', '');
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = SystemSlowQueryLog::latest();

        if ($minTime > 0) {
            $query->slowThan($minTime);
        }
        if (!empty($dbName)) {
            $query->byDbName($dbName);
        }
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_api_monitor_slow_query', '查看慢查询日志');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 慢查询统计
     */
    public function slowQueryStats(Request $request)
    {
        $days = $request->paramInt('days', 7);
        $days = min(max($days, 1), 30);

        $startDate = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
        $endDate = date('Y-m-d');

        $logs = SystemSlowQueryLog::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->field("DATE(create_time) as date, COUNT(*) as count, AVG(exec_time_ms) as avg_time")
            ->group('date')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        $logMap = [];
        foreach ($logs as $log) {
            $logMap[$log['date']] = $log;
        }

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dayData = $logMap[$date] ?? [
                'count'    => 0,
                'avg_time' => 0,
            ];
            $trend[] = [
                'date'     => $date,
                'count'    => (int)$dayData['count'],
                'avg_time' => (int)$dayData['avg_time'],
            ];
        }

        $totalCount = SystemSlowQueryLog::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->count();

        $avgTime = SystemSlowQueryLog::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->avg('exec_time_ms');

        return $this->success([
            'total_count' => $totalCount,
            'avg_time'    => (int)$avgTime,
            'trend'       => $trend,
        ]);
    }
}
