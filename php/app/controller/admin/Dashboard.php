<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\Order;
use app\model\RiskUser;
use app\model\User;
use app\model\Withdraw;
use think\facade\Db;
use think\Request;

/**
 * 仪表盘控制器
 */
class Dashboard extends BaseController
{
    /**
     * 核心指标卡
     * 总用户、总订单、今日营收、待处理数
     */
    public function index(Request $request)
    {
        // 总用户数
        $totalUsers = User::count();

        // 总订单数
        $totalOrders = Order::count();

        // 今日营收
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd   = date('Y-m-d 23:59:59');

        $todayRevenue = Order::where('status', 'in', [
            Order::STATUS_PAID,
            Order::STATUS_PLAYING,
            Order::STATUS_COMPLETED,
        ])->whereBetween('paid_time', [$todayStart, $todayEnd])
        ->sum('paid_amount');

        // 待处理数
        $pendingWithdraw = Withdraw::where('status', Withdraw::STATUS_PENDING)->count();
        $pendingRisk     = RiskUser::where('status', RiskUser::STATUS_UNPROCESSED)->count();
        $pendingAppeal   = \app\model\PhoneAppeal::where('status', \app\model\PhoneAppeal::STATUS_PENDING)->count();

        $pendingCount = $pendingWithdraw + $pendingRisk + $pendingAppeal;

        $this->operationLog('admin_dashboard', '查看仪表盘');

        return $this->success([
            'total_users'    => $totalUsers,
            'total_orders'   => $totalOrders,
            'today_revenue'  => fen_to_yuan((int)$todayRevenue),
            'pending_count'  => $pendingCount,
            'pending_detail' => [
                'withdraw' => $pendingWithdraw,
                'risk'     => $pendingRisk,
                'appeal'   => $pendingAppeal,
            ],
        ]);
    }

    /**
     * 趋势图数据 - 近30天订单/收入趋势
     */
    public function trends(Request $request)
    {
        $days = $request->paramInt('days', 30);
        $days = min(max($days, 7), 90);

        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate   = date('Y-m-d');

        // 订单趋势
        $orderTrend = Order::where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->field("DATE(create_time) as date, COUNT(*) as count")
            ->group('date')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        // 收入趋势
        $revenueTrend = Order::where('paid_time', '>=', $startDate . ' 00:00:00')
            ->where('paid_time', '<=', $endDate . ' 23:59:59')
            ->where('status', 'in', [
                Order::STATUS_PAID,
                Order::STATUS_PLAYING,
                Order::STATUS_COMPLETED,
            ])
            ->field("DATE(paid_time) as date, SUM(paid_amount) as amount")
            ->group('date')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        // 填充缺失日期
        $orderMap  = array_column($orderTrend, 'count', 'date');
        $revenueMap = array_column($revenueTrend, 'amount', 'date');

        $trendData = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trendData[] = [
                'date'    => $date,
                'orders'  => (int)($orderMap[$date] ?? 0),
                'revenue' => fen_to_yuan((int)($revenueMap[$date] ?? 0)),
            ];
        }

        return $this->success([
            'days'  => $days,
            'trend' => $trendData,
        ]);
    }

    /**
     * 待处理事项红点统计
     * 提现/投诉/大额失败/AI风险/申诉
     */
    public function pending(Request $request)
    {
        // 提现待审核
        $withdrawPending = Withdraw::where('status', Withdraw::STATUS_PENDING)->count();

        // 投诉（申诉工单）
        $appealPending = \app\model\PhoneAppeal::where('status', \app\model\PhoneAppeal::STATUS_PENDING)->count();

        // 大额验证失败订单
        $largeFailOrders = \app\model\RiskControlLog::where('result', 'fail')
            ->where('amount', '>=', yuan_to_fen('500')) // 大额阈值 500元
            ->where('create_time', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ->count();

        // AI 风险用户（未处理）
        $aiRiskCount = RiskUser::where('status', RiskUser::STATUS_UNPROCESSED)->count();

        // 申诉催办
        $reminderCount = \app\model\AppealReminder::where('last_remind_time', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ->group('appeal_id')
            ->count();

        $pendingItems = [
            'withdraw' => [
                'label' => '提现待审核',
                'count' => $withdrawPending,
                'has_new' => $withdrawPending > 0,
            ],
            'appeal' => [
                'label' => '申诉工单',
                'count' => $appealPending,
                'has_new' => $appealPending > 0,
            ],
            'large_fail' => [
                'label' => '大额验证失败',
                'count' => $largeFailOrders,
                'has_new' => $largeFailOrders > 0,
            ],
            'ai_risk' => [
                'label' => 'AI风险用户',
                'count' => $aiRiskCount,
                'has_new' => $aiRiskCount > 0,
            ],
            'reminder' => [
                'label' => '申诉催办',
                'count' => $reminderCount,
                'has_new' => $reminderCount > 0,
            ],
        ];

        $totalPending = $withdrawPending + $appealPending + $largeFailOrders + $aiRiskCount + $reminderCount;

        return $this->success([
            'total'   => $totalPending,
            'items'   => $pendingItems,
        ]);
    }
}