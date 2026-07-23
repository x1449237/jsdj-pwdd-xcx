<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemApiMonitor;
use app\model\ThirdPartyApiLog;
use think\facade\Log;

/**
 * 第三方接口监控服务
 * 负责接口监控统计、告警、耗时分析
 */
class ApiMonitorService
{
    /**
     * 记录接口调用
     * @param string $apiType
     * @param bool   $success
     * @param int    $durationMs
     * @param string $endpoint
     * @return bool
     */
    public static function recordCall(string $apiType, bool $success, int $durationMs, string $endpoint = ''): bool
    {
        try {
            $monitor = SystemApiMonitor::where('api_type', $apiType)->find();

            if (!$monitor) {
                $monitor = SystemApiMonitor::create([
                    'api_type'       => $apiType,
                    'endpoint'       => $endpoint,
                    'call_count'     => 1,
                    'success_count'  => $success ? 1 : 0,
                    'fail_count'     => $success ? 0 : 1,
                    'avg_time_ms'    => $durationMs,
                    'last_call_time' => date('Y-m-d H:i:s'),
                    'status'         => 1,
                ]);
            } else {
                $totalCalls = $monitor->call_count + 1;
                $totalSuccess = $monitor->success_count + ($success ? 1 : 0);
                $totalFail = $monitor->fail_count + ($success ? 0 : 1);

                $newAvg = (int)round((($monitor->avg_time_ms * $monitor->call_count) + $durationMs) / $totalCalls);

                $successRate = $totalCalls > 0 ? ($totalSuccess / $totalCalls) * 100 : 100;
                $status = $successRate >= $monitor->alert_threshold ? 1 : 0;

                $monitor->call_count = $totalCalls;
                $monitor->success_count = $totalSuccess;
                $monitor->fail_count = $totalFail;
                $monitor->avg_time_ms = $newAvg;
                $monitor->last_call_time = date('Y-m-d H:i:s');
                $monitor->status = $status;

                if (!empty($endpoint)) {
                    $monitor->endpoint = $endpoint;
                }

                $monitor->save();

                if ($status == 0) {
                    self::triggerAlert($apiType, $successRate, $monitor->alert_threshold);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('记录接口监控失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 触发告警
     * @param string $apiType
     * @param float  $successRate
     * @param float  $threshold
     * @return void
     */
    private static function triggerAlert(string $apiType, float $successRate, float $threshold): void
    {
        Log::warning("接口告警: {$apiType} 成功率 {$successRate}% 低于阈值 {$threshold}%");
    }

    /**
     * 获取所有接口监控状态
     * @return array
     */
    public static function getStatusList(): array
    {
        $list = SystemApiMonitor::order('id', 'asc')->select()->toArray();

        foreach ($list as &$item) {
            $item['success_rate'] = $item['call_count'] > 0
                ? round(($item['success_count'] / $item['call_count']) * 100, 2)
                : 100.00;
            $item['fail_rate'] = $item['call_count'] > 0
                ? round(($item['fail_count'] / $item['call_count']) * 100, 2)
                : 0.00;
        }

        return $list;
    }

    /**
     * 获取单个接口监控详情
     * @param string $apiType
     * @return array|null
     */
    public static function getDetail(string $apiType): ?array
    {
        $monitor = SystemApiMonitor::where('api_type', $apiType)->find();
        if (!$monitor) {
            return null;
        }

        $data = $monitor->toArray();
        $data['success_rate'] = $data['call_count'] > 0
            ? round(($data['success_count'] / $data['call_count']) * 100, 2)
            : 100.00;
        $data['fail_rate'] = $data['call_count'] > 0
            ? round(($data['fail_count'] / $data['call_count']) * 100, 2)
            : 0.00;

        return $data;
    }

    /**
     * 获取接口趋势数据（近N天）
     * @param string $apiType
     * @param int    $days
     * @return array
     */
    public static function getTrend(string $apiType, int $days = 7): array
    {
        $startDate = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
        $endDate = date('Y-m-d');

        $logs = ThirdPartyApiLog::where('api_name', $apiType)
            ->where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->field("DATE(create_time) as date, 
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as fail_count,
                    AVG(duration_ms) as avg_time_ms")
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
                'total_count'   => 0,
                'success_count' => 0,
                'fail_count'    => 0,
                'avg_time_ms'   => 0,
            ];

            $successRate = $dayData['total_count'] > 0
                ? round(($dayData['success_count'] / $dayData['total_count']) * 100, 2)
                : 100.00;

            $trend[] = [
                'date'         => $date,
                'total_count'  => (int)$dayData['total_count'],
                'success_count'=> (int)$dayData['success_count'],
                'fail_count'   => (int)$dayData['fail_count'],
                'avg_time_ms'  => (int)$dayData['avg_time_ms'],
                'success_rate' => $successRate,
            ];
        }

        return $trend;
    }

    /**
     * 更新告警阈值
     * @param string $apiType
     * @param float  $threshold
     * @return bool
     */
    public static function updateThreshold(string $apiType, float $threshold): bool
    {
        try {
            $monitor = SystemApiMonitor::where('api_type', $apiType)->find();
            if (!$monitor) {
                return false;
            }

            $monitor->alert_threshold = $threshold;

            $successRate = $monitor->call_count > 0
                ? ($monitor->success_count / $monitor->call_count) * 100
                : 100;

            $monitor->status = $successRate >= $threshold ? 1 : 0;
            $monitor->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('更新告警阈值失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 重置统计数据
     * @param string $apiType
     * @return bool
     */
    public static function resetStats(string $apiType): bool
    {
        try {
            $monitor = SystemApiMonitor::where('api_type', $apiType)->find();
            if (!$monitor) {
                return false;
            }

            $monitor->call_count = 0;
            $monitor->success_count = 0;
            $monitor->fail_count = 0;
            $monitor->avg_time_ms = 0;
            $monitor->status = 1;
            $monitor->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('重置统计数据失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取总体统计
     * @return array
     */
    public static function getOverview(): array
    {
        $list = self::getStatusList();

        $totalCalls = 0;
        $totalSuccess = 0;
        $totalFail = 0;
        $abnormalCount = 0;

        foreach ($list as $item) {
            $totalCalls += $item['call_count'];
            $totalSuccess += $item['success_count'];
            $totalFail += $item['fail_count'];
            if ($item['status'] == 0) {
                $abnormalCount++;
            }
        }

        $overallSuccessRate = $totalCalls > 0 ? round(($totalSuccess / $totalCalls) * 100, 2) : 100.00;

        return [
            'total_calls'        => $totalCalls,
            'total_success'      => $totalSuccess,
            'total_fail'         => $totalFail,
            'overall_success_rate' => $overallSuccessRate,
            'abnormal_count'     => $abnormalCount,
            'normal_count'       => count($list) - $abnormalCount,
            'total_apis'         => count($list),
        ];
    }
}
