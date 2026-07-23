<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\Order;
use app\model\User;
use app\model\Withdraw;
use app\model\AiRiskAlert;
use app\model\DataDashboardSnapshot;
use app\model\WsConnection;
use think\facade\Db;
use think\Request;

/**
 * 数据大屏控制器
 */
class DataDashboard extends BaseController
{
    /**
     * 数据大屏实时数据
     */
    public function realtime(Request $request)
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $onlineCount = WsConnection::where('is_online', 1)->count();

        $todayOrders = Order::where('create_time', '>=', $todayStart)
            ->where('create_time', '<=', $todayEnd)
            ->count();

        $todayRevenue = Order::where('paid_time', '>=', $todayStart)
            ->where('paid_time', '<=', $todayEnd)
            ->where('status', 'in', [
                Order::STATUS_PAID,
                Order::STATUS_PLAYING,
                Order::STATUS_COMPLETED,
            ])
            ->sum('paid_amount');

        $pendingRisk = AiRiskAlert::where('status', AiRiskAlert::STATUS_PENDING)->count();
        $highRisk = AiRiskAlert::where('status', AiRiskAlert::STATUS_PENDING)
            ->where('risk_level', 'high')
            ->count();

        $hourStart = date('Y-m-d H:00:00', strtotime('-1 hour'));
        $hourEnd = date('Y-m-d H:59:59');

        $hourOrders = Order::where('create_time', '>=', $hourStart)
            ->where('create_time', '<=', $hourEnd)
            ->count();

        $peakOrders = $this->getPeakOrders();

        $todayWithdraw = Withdraw::where('create_time', '>=', $todayStart)
            ->where('create_time', '<=', $todayEnd)
            ->sum('amount');

        $totalUsers = User::count();
        $todayNewUsers = User::where('create_time', '>=', $todayStart)
            ->where('create_time', '<=', $todayEnd)
            ->count();

        $data = [
            'online_count'     => $onlineCount,
            'today_orders'     => $todayOrders,
            'today_revenue'    => fen_to_yuan((int)$todayRevenue),
            'today_withdraw'   => fen_to_yuan((int)$todayWithdraw),
            'pending_risk'     => $pendingRisk,
            'high_risk'        => $highRisk,
            'hour_orders'      => $hourOrders,
            'peak_orders'      => $peakOrders,
            'total_users'      => $totalUsers,
            'today_new_users'  => $todayNewUsers,
            'update_time'      => date('Y-m-d H:i:s'),
        ];

        try {
            DataDashboardSnapshot::create([
                'snapshot_type' => DataDashboardSnapshot::SNAPSHOT_REALTIME,
                'data_json'     => $data,
                'snapshot_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }

        $this->operationLog('admin_data_dashboard', '查看数据大屏');

        return $this->success($data);
    }

    /**
     * 获取订单峰值
     */
    private function getPeakOrders(): int
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $orders = Order::where('create_time', '>=', $todayStart)
            ->where('create_time', '<=', $todayEnd)
            ->field("HOUR(create_time) as hour, COUNT(*) as count")
            ->group('hour')
            ->select()
            ->toArray();

        $peak = 0;
        foreach ($orders as $order) {
            if ($order['count'] > $peak) {
                $peak = $order['count'];
            }
        }

        return $peak;
    }

    /**
     * 24小时订单趋势
     */
    public function orderTrend(Request $request)
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $orders = Order::where('create_time', '>=', $todayStart)
            ->where('create_time', '<=', $todayEnd)
            ->field("HOUR(create_time) as hour, COUNT(*) as order_count, SUM(paid_amount) as revenue")
            ->group('hour')
            ->order('hour', 'asc')
            ->select()
            ->toArray();

        $orderMap = [];
        foreach ($orders as $order) {
            $orderMap[$order['hour']] = $order;
        }

        $trend = [];
        for ($i = 0; $i < 24; $i++) {
            $hourData = $orderMap[$i] ?? [
                'order_count' => 0,
                'revenue'     => 0,
            ];
            $trend[] = [
                'hour'        => $i,
                'order_count' => (int)$hourData['order_count'],
                'revenue'     => fen_to_yuan((int)($hourData['revenue'] ?? 0)),
            ];
        }

        return $this->success($trend);
    }

    /**
     * 实时资金流水
     */
    public function fundFlow(Request $request)
    {
        $hours = $request->paramInt('hours', 24);
        $hours = min(max($hours, 1), 168);

        $startTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $endTime = date('Y-m-d H:i:s');

        $payments = \app\model\Payment::where('status', 1)
            ->where('pay_time', '>=', $startTime)
            ->where('pay_time', '<=', $endTime)
            ->field("DATE_FORMAT(pay_time, '%Y-%m-%d %H:00:00') as time, SUM(amount) as income")
            ->group('time')
            ->order('time', 'asc')
            ->select()
            ->toArray();

        $withdraws = Withdraw::where('status', 3)
            ->where('create_time', '>=', $startTime)
            ->where('create_time', '<=', $endTime)
            ->field("DATE_FORMAT(create_time, '%Y-%m-%d %H:00:00') as time, SUM(amount) as expense")
            ->group('time')
            ->order('time', 'asc')
            ->select()
            ->toArray();

        $incomeMap = array_column($payments, 'income', 'time');
        $expenseMap = array_column($withdraws, 'expense', 'time');

        $trend = [];
        for ($i = $hours; $i >= 0; $i--) {
            $time = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
            $trend[] = [
                'time'    => substr($time, 11, 5),
                'income'  => fen_to_yuan((int)($incomeMap[$time] ?? 0)),
                'expense' => fen_to_yuan((int)($expenseMap[$time] ?? 0)),
            ];
        }

        return $this->success($trend);
    }

    /**
     * 风险工单统计
     */
    public function riskStats(Request $request)
    {
        $days = $request->paramInt('days', 7);
        $days = min(max($days, 1), 30);

        $startDate = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
        $endDate = date('Y-m-d');

        $alerts = AiRiskAlert::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->field("DATE(create_time) as date, 
                    risk_level, 
                    COUNT(*) as count")
            ->group('date, risk_level')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        $dailyMap = [];
        foreach ($alerts as $alert) {
            $date = $alert['date'];
            if (!isset($dailyMap[$date])) {
                $dailyMap[$date] = [
                    'date'   => $date,
                    'total'  => 0,
                    'high'   => 0,
                    'medium' => 0,
                    'low'    => 0,
                ];
            }
            $dailyMap[$date]['total'] += $alert['count'];
            $dailyMap[$date][$alert['risk_level']] = $alert['count'];
        }

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dayData = $dailyMap[$date] ?? [
                'date'   => $date,
                'total'  => 0,
                'high'   => 0,
                'medium' => 0,
                'low'    => 0,
            ];
            $trend[] = $dayData;
        }

        $typeStats = AiRiskAlert::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->field('alert_type, COUNT(*) as count')
            ->group('alert_type')
            ->order('count', 'desc')
            ->select()
            ->toArray();

        $pendingCount = AiRiskAlert::where('status', AiRiskAlert::STATUS_PENDING)->count();

        return $this->success([
            'trend'         => $trend,
            'type_stats'    => $typeStats,
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * 历史快照
     */
    public function snapshots(Request $request)
    {
        $snapshotType = $request->param('snapshot_type', 'hourly');
        $page = $request->paramInt('page', 1);
        $limit = $request->paramInt('limit', 24);

        $query = DataDashboardSnapshot::byType($snapshotType)->latest();

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }
}
