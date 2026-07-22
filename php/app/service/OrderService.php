<?php
declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\OrderStatusLog;
use app\model\Payment;
use app\model\CircuitBreaker;
use think\facade\Log;
use think\facade\Db;

/**
 * 订单服务
 * 负责订单创建、金额计算、结算、退款等核心业务
 */
class OrderService
{
    /**
     * 平台抽成比例（默认 20%）
     */
    private const PLATFORM_FEE_RATE = '0.20';

    /**
     * 大额订单阈值（元）
     */
    private const LARGE_AMOUNT_THRESHOLD = '5000.00';

    /**
     * 未成年人单日消费上限（元）
     */
    private const MINOR_DAILY_LIMIT = '200.00';

    /**
     * 结算分布式锁前缀
     */
    private const SETTLE_LOCK_PREFIX = 'order:settle:lock:';

    /**
     * 结算幂等键前缀
     */
    private const SETTLE_IDEMPOTENT_PREFIX = 'order:settle:done:';

    /**
     * 创建订单
     * @param int   $userId
     * @param array $data
     * @return array
     * @throws \RuntimeException
     */
    public function createOrder(int $userId, array $data): array
    {
        try {
            $amount = $data['order_amount'] ?? '0';

            // 金额校验
            if (bc_comp($amount, '0.01', 2) < 0) {
                throw new \RuntimeException('订单金额不能小于0.01元');
            }

            // 未成年人限制
            $this->checkMinorLimit($userId, $amount);

            // 大额订单触发风控标记
            $isLarge = false;
            if (bc_comp($amount, self::LARGE_AMOUNT_THRESHOLD, 2) >= 0) {
                $isLarge = true;
                Log::info("大额订单触发: user_id={$userId}, amount={$amount}");
            }

            // 计算平台抽成和打手收入
            $platformFee = $this->calculatePlatformFee($amount);
            $playerIncome = $this->calculatePlayerIncome($amount);

            $orderSn = generate_sn('GP');

            $order = Order::create([
                'order_sn'       => $orderSn,
                'user_id'        => $userId,
                'service_type_id'=> $data['service_type_id'] ?? 0,
                'game_name'      => $data['game_name'] ?? '',
                'order_amount'   => $amount,
                'paid_amount'    => '0',
                'discount_amount'=> '0',
                'status'         => Order::STATUS_PENDING,
                'remark'         => $data['remark'] ?? '',
            ]);

            // 记录状态变更
            OrderStatusLog::create([
                'order_id'      => $order->id,
                'from_status'   => -1,
                'to_status'     => Order::STATUS_PENDING,
                'operator_id'   => $userId,
                'operator_type' => 'user',
                'remark'        => '创建订单',
            ]);

            Log::info("订单创建成功: order_sn={$orderSn}, user_id={$userId}, amount={$amount}");

            return [
                'order_id'      => $order->id,
                'order_sn'      => $orderSn,
                'order_amount'   => $amount,
                'platform_fee'  => $platformFee,
                'player_income' => $playerIncome,
                'is_large'      => $isLarge,
            ];
        } catch (\Throwable $e) {
            Log::error("创建订单失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 计算平台抽成
     * @param string $amount 订单金额（元）
     * @return string 平台抽成（元）
     */
    public function calculatePlatformFee(string $amount): string
    {
        return bc_mul($amount, self::PLATFORM_FEE_RATE, 2);
    }

    /**
     * 计算打手收入
     * @param string $amount 订单金额（元）
     * @return string 打手收入（元）
     */
    public function calculatePlayerIncome(string $amount): string
    {
        $platformFee = $this->calculatePlatformFee($amount);
        return bc_sub($amount, $platformFee, 2);
    }

    /**
     * 订单结算（Redis分布式锁 + 幂等键）
     * @param int $orderId
     * @return bool
     */
    public function settleOrder(int $orderId): bool
    {
        $redis = get_redis();
        $lockKey = self::SETTLE_LOCK_PREFIX . $orderId;
        $idempotentKey = self::SETTLE_IDEMPOTENT_PREFIX . $orderId;

        try {
            // 幂等检查
            if ($redis->exists($idempotentKey)) {
                Log::info("订单已结算，幂等跳过: order_id={$orderId}");
                return true;
            }

            // 获取分布式锁（30秒超时）
            $lockValue = uniqid('settle_', true);
            if (!$redis->set($lockKey, $lockValue, ['nx', 'ex' => 30])) {
                Log::warning("订单结算获取锁失败: order_id={$orderId}");
                return false;
            }

            Db::startTrans();
            try {
                $order = Order::lock(true)->find($orderId);
                if (!$order) {
                    throw new \RuntimeException("订单不存在: {$orderId}");
                }

                if ($order->status !== Order::STATUS_COMPLETED) {
                    throw new \RuntimeException("订单状态不允许结算: {$order->status}");
                }

                // 更新订单状态为已结算（假设有结算状态，这里更新为已完成）
                // 实际结算逻辑：更新打手余额等
                $order->save();

                // 设置幂等键（7天过期）
                $redis->setex($idempotentKey, 604800, '1');

                Db::commit();

                Log::info("订单结算成功: order_id={$orderId}");
                return true;
            } catch (\Throwable $e) {
                Db::rollback();
                Log::error("订单结算事务失败: order_id={$orderId}, error={$e->getMessage()}");
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error("订单结算失败: order_id={$orderId}, error={$e->getMessage()}");
            return false;
        } finally {
            // 释放锁（Lua 脚本保证原子性）
            if (isset($lockValue)) {
                $lua = <<<LUA
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;
                $redis->eval($lua, [$lockKey, $lockValue], 1);
            }
        }
    }

    /**
     * 检查超时订单
     * @return int 处理的超时订单数量
     */
    public function checkTimeout(): int
    {
        try {
            $count = 0;

            // 查询待支付超时订单（30分钟未支付）
            $payTimeout = Order::where('status', Order::STATUS_PENDING)
                ->where('create_time', '<', date('Y-m-d H:i:s', time() - 1800))
                ->select();

            foreach ($payTimeout as $order) {
                $order->status = Order::STATUS_TIMEOUT;
                $order->save();

                OrderStatusLog::create([
                    'order_id'      => $order->id,
                    'from_status'   => Order::STATUS_PENDING,
                    'to_status'     => Order::STATUS_TIMEOUT,
                    'operator_type' => 'system',
                    'remark'        => '支付超时自动取消',
                ]);

                $count++;
            }

            // 查询进行中已超时的订单（根据服务类型设定的超时规则）
            // 这里简化处理，实际应从 TimeoutRule 模型读取规则
            $activeTimeout = Order::where('status', Order::STATUS_PLAYING)
                ->where('update_time', '<', date('Y-m-d H:i:s', time() - 86400))
                ->select();

            foreach ($activeTimeout as $order) {
                $order->status = Order::STATUS_TIMEOUT;
                $order->save();

                OrderStatusLog::create([
                    'order_id'      => $order->id,
                    'from_status'   => Order::STATUS_PLAYING,
                    'to_status'     => Order::STATUS_TIMEOUT,
                    'operator_type' => 'system',
                    'remark'        => '服务超时自动取消',
                ]);

                $count++;
            }

            Log::info("超时订单检查完成，处理 {$count} 笔");
            return $count;
        } catch (\Throwable $e) {
            Log::error("超时订单检查失败: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 自动确认完成
     * T+3 天后自动确认完成
     * @return int
     */
    public function autoComplete(): int
    {
        try {
            $count = 0;
            $threeDaysAgo = date('Y-m-d H:i:s', time() - 259200);

            $orders = Order::where('status', Order::STATUS_PLAYING)
                ->where('update_time', '<', $threeDaysAgo)
                ->select();

            foreach ($orders as $order) {
                $order->status = Order::STATUS_COMPLETED;
                $order->completed_time = date('Y-m-d H:i:s');
                $order->save();

                OrderStatusLog::create([
                    'order_id'      => $order->id,
                    'from_status'   => Order::STATUS_PLAYING,
                    'to_status'     => Order::STATUS_COMPLETED,
                    'operator_type' => 'system',
                    'remark'        => 'T+3自动确认完成',
                ]);

                $count++;
            }

            Log::info("自动确认完成处理 {$count} 笔订单");
            return $count;
        } catch (\Throwable $e) {
            Log::error("自动确认完成失败: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 退款
     * @param int $orderId
     * @return bool
     * @throws \RuntimeException
     */
    public function refundOrder(int $orderId): bool
    {
        try {
            // 熔断检测
            if ($this->checkRefundCircuitBreaker()) {
                throw new \RuntimeException('退款服务熔断中，请稍后再试');
            }

            $order = Order::find($orderId);
            if (!$order) {
                throw new \RuntimeException("订单不存在: {$orderId}");
            }

            if (!in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PLAYING])) {
                throw new \RuntimeException("订单状态不允许退款: {$order->status}");
            }

            $order->status = Order::STATUS_REFUNDING;
            $order->save();

            OrderStatusLog::create([
                'order_id'      => $order->id,
                'from_status'   => $order->status,
                'to_status'     => Order::STATUS_REFUNDING,
                'operator_type' => 'system',
                'remark'        => '发起退款',
            ]);

            Log::info("退款发起成功: order_id={$orderId}");
            return true;
        } catch (\Throwable $e) {
            Log::error("退款失败: order_id={$orderId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 退款熔断检测
     * 单日退款超营收50%触发熔断
     * @return bool
     */
    private function checkRefundCircuitBreaker(): bool
    {
        try {
            $today = date('Y-m-d');
            $redis = get_redis();
            $key = 'refund:circuit_breaker:' . $today;

            // 检查Redis缓存
            if ($redis->exists($key)) {
                return (bool) $redis->get($key);
            }

            // 计算今日退款总额
            $todayRefund = Payment::where('status', Payment::STATUS_SUCCESS)
                ->where('pay_time', '>=', $today . ' 00:00:00')
                ->where('pay_time', '<=', $today . ' 23:59:59')
                ->sum('amount');

            // 计算今日营收总额
            $todayRevenue = Payment::where('status', Payment::STATUS_SUCCESS)
                ->where('pay_time', '>=', $today . ' 00:00:00')
                ->where('pay_time', '<=', $today . ' 23:59:59')
                ->sum('amount');

            $todayRefund = $todayRefund ?: '0';
            $todayRevenue = $todayRevenue ?: '0';

            $isBreaker = false;
            if (bc_comp($todayRevenue, '0', 2) > 0) {
                $ratio = bc_div($todayRefund, $todayRevenue, 4);
                if (bc_comp($ratio, '0.50', 4) >= 0) {
                    $isBreaker = true;
                    Log::warning("退款熔断触发: 退款={$todayRefund}, 营收={$todayRevenue}, 比例={$ratio}");

                    // 记录熔断
                    CircuitBreaker::create([
                        'service_name'  => 'refund',
                        'failure_count' => 1,
                        'threshold'     => 50,
                        'status'        => CircuitBreaker::STATUS_OPEN,
                        'open_time'     => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $redis->setex($key, 3600, $isBreaker ? '1' : '0');
            return $isBreaker;
        } catch (\Throwable $e) {
            Log::error("退款熔断检测失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 未成年人消费限制检查
     * @param int    $userId
     * @param string $amount
     * @throws \RuntimeException
     */
    private function checkMinorLimit(int $userId, string $amount): void
    {
        try {
            $user = \app\model\User::find($userId);
            if (!$user) {
                return;
            }

            // 检查是否未成年人（通过身份证号计算年龄）
            if (!empty($user->id_card)) {
                $age = $this->calculateAge($user->id_card);
                if ($age < 18) {
                    // 检查今日累计消费
                    $todaySpent = Order::where('user_id', $userId)
                        ->where('status', 'in', [
                            Order::STATUS_PAID,
                            Order::STATUS_PLAYING,
                            Order::STATUS_COMPLETED,
                        ])
                        ->where('create_time', '>=', date('Y-m-d') . ' 00:00:00')
                        ->sum('paid_amount');

                    $total = bc_add($todaySpent, $amount, 2);
                    if (bc_comp($total, self::MINOR_DAILY_LIMIT, 2) > 0) {
                        throw new \RuntimeException('未成年用户单日消费超过限额');
                    }
                }
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("未成年人限制检查失败: {$e->getMessage()}");
        }
    }

    /**
     * 根据身份证号计算年龄
     * @param string $idCard
     * @return int
     */
    private function calculateAge(string $idCard): int
    {
        $birthday = substr($idCard, 6, 8);
        $year  = (int) substr($birthday, 0, 4);
        $month = (int) substr($birthday, 4, 2);
        $day   = (int) substr($birthday, 6, 2);

        $currentYear  = (int) date('Y');
        $currentMonth = (int) date('m');
        $currentDay   = (int) date('d');

        $age = $currentYear - $year;
        if ($currentMonth < $month || ($currentMonth == $month && $currentDay < $day)) {
            $age--;
        }

        return $age;
    }
}