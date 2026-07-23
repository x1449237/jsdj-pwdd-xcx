<?php
declare(strict_types=1);

namespace app\service;

use app\model\AiRiskAlert;
use app\model\Order;
use app\model\User;
use app\model\Withdraw;
use think\facade\Db;
use think\facade\Log;

/**
 * AI风险预警服务
 * 负责风险预警检测、列表查询、处理
 */
class AiRiskAlertService
{
    /**
     * 检测并创建风险预警
     * @param string $alertType
     * @param int    $userId
     * @param string $riskLevel
     * @param string $description
     * @param array  $data
     * @return AiRiskAlert|null
     */
    public static function createAlert(
        string $alertType,
        int $userId,
        string $riskLevel = AiRiskAlert::RISK_LEVEL_MEDIUM,
        string $description = '',
        array $data = []
    ): ?AiRiskAlert {
        try {
            $alert = AiRiskAlert::create([
                'alert_type'  => $alertType,
                'user_id'     => $userId,
                'risk_level'  => $riskLevel,
                'description' => $description,
                'data_json'   => $data,
                'status'      => AiRiskAlert::STATUS_PENDING,
            ]);

            Log::info("AI风险预警: type={$alertType}, user_id={$userId}, level={$riskLevel}, desc={$description}");

            return $alert;
        } catch (\Throwable $e) {
            Log::error('创建风险预警失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 检测高退款率风险
     * @param int $userId
     * @return bool
     */
    public static function detectHighRefundRate(int $userId): bool
    {
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');

        $totalOrders = Order::where('user_id', $userId)
            ->where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->count();

        if ($totalOrders < 5) {
            return false;
        }

        $refundOrders = Order::where('user_id', $userId)
            ->where('create_time', '>=', $startDate . ' 00:00:00')
            ->where('create_time', '<=', $endDate . ' 23:59:59')
            ->where('status', Order::STATUS_REFUNDED)
            ->count();

        $refundRate = $totalOrders > 0 ? ($refundOrders / $totalOrders) * 100 : 0;

        if ($refundRate >= 30) {
            self::createAlert(
                AiRiskAlert::ALERT_TYPE_HIGH_REFUND_RATE,
                $userId,
                AiRiskAlert::RISK_LEVEL_HIGH,
                "用户近30天退款率达{$refundRate}%，共{$totalOrders}单，退款{$refundOrders}单",
                [
                    'total_orders'  => $totalOrders,
                    'refund_orders' => $refundOrders,
                    'refund_rate'   => round($refundRate, 2),
                ]
            );
            return true;
        }

        return false;
    }

    /**
     * 检测同IP批量注册
     * @param string $ip
     * @return bool
     */
    public static function detectSameIpRegist(string $ip): bool
    {
        $startDate = date('Y-m-d 00:00:00');
        $endDate = date('Y-m-d 23:59:59');

        $registCount = User::where('last_login_ip', $ip)
            ->where('create_time', '>=', $startDate)
            ->where('create_time', '<=', $endDate)
            ->count();

        if ($registCount >= 5) {
            $users = User::where('last_login_ip', $ip)
                ->where('create_time', '>=', $startDate)
                ->where('create_time', '<=', $endDate)
                ->field('id, nickname, phone')
                ->limit(10)
                ->select()
                ->toArray();

            self::createAlert(
                AiRiskAlert::ALERT_TYPE_SAME_IP_REGIST,
                0,
                AiRiskAlert::RISK_LEVEL_HIGH,
                "同一IP今日注册{$registCount}个账号，疑似批量注册",
                [
                    'ip'           => $ip,
                    'regist_count' => $registCount,
                    'users'        => $users,
                ]
            );
            return true;
        }

        return false;
    }

    /**
     * 检测大额集中提现
     * @param int $userId
     * @param int $amount
     * @return bool
     */
    public static function detectLargeWithdraw(int $userId, int $amount): bool
    {
        $largeThreshold = yuan_to_fen('5000');

        if ($amount >= $largeThreshold) {
            self::createAlert(
                AiRiskAlert::ALERT_TYPE_LARGE_WITHDRAW,
                $userId,
                AiRiskAlert::RISK_LEVEL_HIGH,
                "用户大额提现申请，金额" . fen_to_yuan($amount) . "元",
                [
                    'amount' => $amount,
                ]
            );
            return true;
        }

        return false;
    }

    /**
     * 检测深夜异常下单
     * @param int $userId
     * @return bool
     */
    public static function detectMidnightOrder(int $userId): bool
    {
        $hour = (int)date('H');

        if ($hour >= 2 && $hour <= 5) {
            $startTime = date('Y-m-d 02:00:00');
            $endTime = date('Y-m-d 05:00:00');

            $orderCount = Order::where('user_id', $userId)
                ->where('create_time', '>=', $startTime)
                ->where('create_time', '<=', $endTime)
                ->count();

            if ($orderCount >= 3) {
                self::createAlert(
                    AiRiskAlert::ALERT_TYPE_MIDNIGHT_ORDER,
                    $userId,
                    AiRiskAlert::RISK_LEVEL_MEDIUM,
                    "用户深夜({$hour}点)下单{$orderCount}单，疑似异常行为",
                    [
                        'order_count' => $orderCount,
                        'hour'        => $hour,
                    ]
                );
                return true;
            }
        }

        return false;
    }

    /**
     * 检测高频下单
     * @param int $userId
     * @return bool
     */
    public static function detectFrequencyOrder(int $userId): bool
    {
        $startTime = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $endTime = date('Y-m-d H:i:s');

        $orderCount = Order::where('user_id', $userId)
            ->where('create_time', '>=', $startTime)
            ->where('create_time', '<=', $endTime)
            ->count();

        if ($orderCount >= 10) {
            self::createAlert(
                AiRiskAlert::ALERT_TYPE_FREQUENCY_ORDER,
                $userId,
                AiRiskAlert::RISK_LEVEL_MEDIUM,
                "用户10分钟内下单{$orderCount}单，高频操作",
                [
                    'order_count' => $orderCount,
                ]
            );
            return true;
        }

        return false;
    }

    /**
     * 分页查询风险预警
     * @param array $filters
     * @param int   $page
     * @param int   $limit
     * @return array
     */
    public static function getList(array $filters, int $page = 1, int $limit = 15): array
    {
        $query = AiRiskAlert::with(['user', 'handler'])->latest();

        if (!empty($filters['alert_type'])) {
            $query->byAlertType($filters['alert_type']);
        }
        if (!empty($filters['risk_level'])) {
            $query->byRiskLevel($filters['risk_level']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->byStatus((int)$filters['status']);
        }
        if (!empty($filters['user_id'])) {
            $query->byUserId((int)$filters['user_id']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('create_time', '>=', $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $query->where('create_time', '<=', $filters['end_date'] . ' 23:59:59');
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
     * 处理风险预警
     * @param int    $alertId
     * @param int    $handlerId
     * @param string $action
     * @param string $remark
     * @return bool
     */
    public static function handleAlert(int $alertId, int $handlerId, string $action, string $remark = ''): bool
    {
        $alert = AiRiskAlert::find($alertId);
        if (!$alert) {
            return false;
        }

        try {
            switch ($action) {
                case 'handle':
                    $alert->status = AiRiskAlert::STATUS_HANDLED;
                    $alert->handler_id = $handlerId;
                    $alert->handle_time = date('Y-m-d H:i:s');
                    break;
                case 'ignore':
                    $alert->status = AiRiskAlert::STATUS_IGNORED;
                    $alert->handler_id = $handlerId;
                    $alert->handle_time = date('Y-m-d H:i:s');
                    break;
                case 'processing':
                    $alert->status = AiRiskAlert::STATUS_PROCESSING;
                    break;
                default:
                    return false;
            }

            $alert->save();

            Log::info("风险预警处理: alert_id={$alertId}, action={$action}, handler_id={$handlerId}, remark={$remark}");

            return true;
        } catch (\Throwable $e) {
            Log::error('处理风险预警失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 批量封禁用户
     * @param array $userIds
     * @param int   $handlerId
     * @param string $reason
     * @return array
     */
    public static function batchBanUsers(array $userIds, int $handlerId, string $reason = ''): array
    {
        $success = 0;
        $failed = 0;

        foreach ($userIds as $userId) {
            try {
                $user = User::find($userId);
                if ($user) {
                    $user->status = 0;
                    $user->ban_reason = $reason ?: 'AI风险预警封禁';
                    $user->save();
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("批量封禁用户失败: user_id={$userId}, " . $e->getMessage());
            }
        }

        return [
            'success' => $success,
            'failed'  => $failed,
            'total'   => count($userIds),
        ];
    }

    /**
     * 获取风险预警统计
     * @return array
     */
    public static function getStats(): array
    {
        $pendingCount = AiRiskAlert::where('status', AiRiskAlert::STATUS_PENDING)->count();
        $highCount = AiRiskAlert::where('risk_level', AiRiskAlert::RISK_LEVEL_HIGH)
            ->where('status', AiRiskAlert::STATUS_PENDING)
            ->count();
        $mediumCount = AiRiskAlert::where('risk_level', AiRiskAlert::RISK_LEVEL_MEDIUM)
            ->where('status', AiRiskAlert::STATUS_PENDING)
            ->count();
        $lowCount = AiRiskAlert::where('risk_level', AiRiskAlert::RISK_LEVEL_LOW)
            ->where('status', AiRiskAlert::STATUS_PENDING)
            ->count();

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $todayCount = AiRiskAlert::whereBetween('create_time', [$todayStart, $todayEnd])->count();

        $typeStats = AiRiskAlert::where('status', AiRiskAlert::STATUS_PENDING)
            ->field('alert_type, COUNT(*) as count')
            ->group('alert_type')
            ->select()
            ->toArray();

        return [
            'pending_count'  => $pendingCount,
            'high_count'     => $highCount,
            'medium_count'   => $mediumCount,
            'low_count'      => $lowCount,
            'today_count'    => $todayCount,
            'type_stats'     => $typeStats,
        ];
    }
}
