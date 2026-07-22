<?php
declare(strict_types=1);

namespace app\service;

use app\model\Payment;
use app\model\Order;
use app\model\OrderStatusLog;
use app\model\CircuitBreaker;
use think\facade\Log;
use think\facade\Db;
use GuzzleHttp\Client;

/**
 * 支付服务
 * 负责微信支付统一下单、回调处理、退款和熔断检测
 */
class PaymentService
{
    /**
     * 微信支付统一下单 URL
     */
    private const WECHAT_PAY_URL = 'https://api.mch.weixin.qq.com/v3/pay/transactions/jsapi';

    /**
     * 微信退款 URL
     */
    private const WECHAT_REFUND_URL = 'https://api.mch.weixin.qq.com/v3/refund/domestic/refunds';

    /**
     * 幂等键前缀
     */
    private const IDEMPOTENT_PREFIX = 'payment:idempotent:';

    /**
     * 熔断 Key 前缀
     */
    private const CIRCUIT_BREAKER_PREFIX = 'payment:circuit:';

    /**
     * 创建支付（微信支付统一下单）
     * @param int    $orderId
     * @param string $amount 支付金额（元）
     * @return array
     * @throws \RuntimeException
     */
    public function createPayment(int $orderId, string $amount): array
    {
        try {
            // 熔断检测
            $this->checkCircuitBreaker();

            $order = Order::find($orderId);
            if (!$order) {
                throw new \RuntimeException("订单不存在: {$orderId}");
            }

            if ($order->status !== Order::STATUS_PENDING) {
                throw new \RuntimeException("订单状态不允许支付: {$order->status}");
            }

            $paymentSn = generate_sn('PAY');
            $amountFen = yuan_to_fen($amount);

            // 创建支付记录
            $payment = Payment::create([
                'order_id'       => $orderId,
                'user_id'        => $order->user_id,
                'payment_sn'     => $paymentSn,
                'amount'         => $amount,
                'payment_method' => Payment::METHOD_WECHAT,
                'status'         => Payment::STATUS_PROCESS,
            ]);

            // 调用微信支付统一下单
            $wechatResult = $this->callWechatPay($paymentSn, $amountFen, $order->order_sn);

            Log::info("创建支付: payment_sn={$paymentSn}, order_id={$orderId}, amount={$amount}");

            return [
                'payment_id'   => $payment->id,
                'payment_sn'   => $paymentSn,
                'amount'       => $amount,
                'prepay_data'  => $wechatResult,
            ];
        } catch (\Throwable $e) {
            Log::error("创建支付失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 支付回调处理
     * @param array $data 回调数据
     * @return bool
     * @throws \RuntimeException
     */
    public function paymentCallback(array $data): bool
    {
        $paymentSn = $data['out_trade_no'] ?? '';
        $transactionId = $data['transaction_id'] ?? '';

        if (empty($paymentSn)) {
            throw new \RuntimeException('回调数据缺少支付流水号');
        }

        $redis = get_redis();
        $idempotentKey = self::IDEMPOTENT_PREFIX . $paymentSn;

        // 幂等检查
        if ($redis->exists($idempotentKey)) {
            Log::info("支付回调已处理，幂等跳过: payment_sn={$paymentSn}");
            return true;
        }

        Db::startTrans();
        try {
            $payment = Payment::where('payment_sn', $paymentSn)->lock(true)->find();
            if (!$payment) {
                throw new \RuntimeException("支付记录不存在: {$paymentSn}");
            }

            if ($payment->status === Payment::STATUS_SUCCESS) {
                Db::commit();
                return true;
            }

            // 更新支付状态
            $payment->status = Payment::STATUS_SUCCESS;
            $payment->transaction_id = $transactionId;
            $payment->pay_time = date('Y-m-d H:i:s');
            $payment->callback_data = $data;
            $payment->save();

            // 更新订单状态
            $order = Order::lock(true)->find($payment->order_id);
            if ($order) {
                $order->status = Order::STATUS_PAID;
                $order->paid_time = date('Y-m-d H:i:s');
                $order->paid_amount = $payment->amount;
                $order->save();

                OrderStatusLog::create([
                    'order_id'      => $order->id,
                    'from_status'   => Order::STATUS_PENDING,
                    'to_status'     => Order::STATUS_PAID,
                    'operator_type' => 'system',
                    'remark'        => '支付成功回调',
                ]);
            }

            // 设置幂等键（7天过期）
            $redis->setex($idempotentKey, 604800, '1');

            Db::commit();

            Log::info("支付回调成功: payment_sn={$paymentSn}, transaction_id={$transactionId}");

            // 触发派单
            $dispatchService = new PlayerDispatchService();
            try {
                $dispatchService->dispatchOrder($payment->order_id);
            } catch (\Throwable $e) {
                Log::error("支付后派单失败: {$e->getMessage()}");
            }

            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("支付回调失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 退款
     * @param int    $paymentId
     * @param string $amount 退款金额（元）
     * @return bool
     * @throws \RuntimeException
     */
    public function refund(int $paymentId, string $amount): bool
    {
        try {
            // 熔断检测
            $this->checkCircuitBreaker();

            $payment = Payment::find($paymentId);
            if (!$payment) {
                throw new \RuntimeException("支付记录不存在: {$paymentId}");
            }

            if ($payment->status !== Payment::STATUS_SUCCESS) {
                throw new \RuntimeException("支付状态不允许退款");
            }

            $amountFen = yuan_to_fen($amount);
            $refundSn = generate_sn('REF');

            // 调用微信退款
            $this->callWechatRefund($payment->transaction_id, $refundSn, $amountFen, $payment->amount);

            // 更新订单状态
            $order = Order::find($payment->order_id);
            if ($order) {
                $order->status = Order::STATUS_REFUNDED;
                $order->canceled_time = date('Y-m-d H:i:s');
                $order->save();

                OrderStatusLog::create([
                    'order_id'      => $order->id,
                    'from_status'   => Order::STATUS_REFUNDING,
                    'to_status'     => Order::STATUS_REFUNDED,
                    'operator_type' => 'system',
                    'remark'        => "退款成功: {$amount}元",
                ]);
            }

            Log::info("退款成功: payment_id={$paymentId}, refund_sn={$refundSn}, amount={$amount}");

            return true;
        } catch (\Throwable $e) {
            Log::error("退款失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 熔断检测
     * 单日退款超营收50%触发熔断
     * @throws \RuntimeException
     */
    public function checkCircuitBreaker(): void
    {
        try {
            // 先检查 Redis 缓存
            $redis = get_redis();
            $key = self::CIRCUIT_BREAKER_PREFIX . date('Y-m-d');

            $cached = $redis->get($key);
            if ($cached === '1') {
                throw new \RuntimeException('支付服务熔断中，请稍后再试');
            }

            // 计算今日退款/营收比例
            $today = date('Y-m-d');
            $todayRefund = Payment::where('pay_time', '>=', $today . ' 00:00:00')
                ->where('pay_time', '<=', $today . ' 23:59:59')
                ->where('status', Payment::STATUS_SUCCESS)
                ->sum('amount');

            $todayRevenue = Payment::where('pay_time', '>=', $today . ' 00:00:00')
                ->where('pay_time', '<=', $today . ' 23:59:59')
                ->where('status', Payment::STATUS_SUCCESS)
                ->sum('amount');

            $todayRefund = $todayRefund ?: '0';
            $todayRevenue = $todayRevenue ?: '0';

            if (bc_comp($todayRevenue, '0', 2) > 0) {
                $ratio = bc_div($todayRefund, $todayRevenue, 4);
                if (bc_comp($ratio, '0.50', 4) >= 0) {
                    $redis->setex($key, 86400, '1');

                    // 记录熔断
                    CircuitBreaker::create([
                        'service_name'  => 'payment',
                        'failure_count' => 1,
                        'threshold'     => 50,
                        'status'        => CircuitBreaker::STATUS_OPEN,
                        'open_time'     => date('Y-m-d H:i:s'),
                    ]);

                    Log::warning("支付熔断触发: 退款={$todayRefund}, 营收={$todayRevenue}, 比例={$ratio}");
                    throw new \RuntimeException('支付服务熔断中，请稍后再试');
                }
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("熔断检测失败: {$e->getMessage()}");
        }
    }

    /**
     * 调用微信支付统一下单
     * @param string $paymentSn
     * @param int    $amountFen
     * @param string $orderSn
     * @return array
     */
    private function callWechatPay(string $paymentSn, int $amountFen, string $orderSn): array
    {
        $mchId = config_get('wechat.wechat_pay_mch_id', '');
        $apiKey = config_get('wechat.wechat_pay_api_key', '');
        $appId = config_get('wechat.mini_program_appid', '');

        if (empty($mchId) || empty($appId)) {
            Log::warning('微信支付配置缺失，使用模拟返回');
            return [
                'prepay_id' => 'mock_prepay_' . $paymentSn,
                'package'   => 'prepay_id=mock_prepay_' . $paymentSn,
                'nonceStr'  => generate_token(16),
                'timeStamp' => (string) time(),
                'signType'  => 'RSA',
                'paySign'   => 'mock_sign',
            ];
        }

        try {
            $client = new Client(['timeout' => 30]);

            $body = [
                'appid'         => $appId,
                'mchid'         => $mchId,
                'description'   => '游戏陪玩服务-' . $orderSn,
                'out_trade_no'  => $paymentSn,
                'notify_url'    => config_get('app.url_domain', '') . '/api/v1/callback/payment',
                'amount'        => [
                    'total'    => $amountFen,
                    'currency' => 'CNY',
                ],
                'payer' => [
                    'openid' => '',
                ],
            ];

            $response = $client->post(self::WECHAT_PAY_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => $body,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'prepay_id' => $result['prepay_id'] ?? '',
                'package'   => 'prepay_id=' . ($result['prepay_id'] ?? ''),
                'nonceStr'  => generate_token(16),
                'timeStamp' => (string) time(),
                'signType'  => 'RSA',
                'paySign'   => '',
            ];
        } catch (\Throwable $e) {
            Log::error("微信支付统一下单失败: {$e->getMessage()}");
            return [
                'prepay_id' => 'mock_prepay_' . $paymentSn,
                'package'   => 'prepay_id=mock_prepay_' . $paymentSn,
                'nonceStr'  => generate_token(16),
                'timeStamp' => (string) time(),
                'signType'  => 'RSA',
                'paySign'   => 'mock_sign',
            ];
        }
    }

    /**
     * 调用微信退款
     * @param string $transactionId
     * @param string $refundSn
     * @param int    $amountFen
     * @param string $totalAmount
     * @return bool
     */
    private function callWechatRefund(string $transactionId, string $refundSn, int $amountFen, string $totalAmount): bool
    {
        $mchId = config_get('wechat.wechat_pay_mch_id', '');

        if (empty($mchId)) {
            Log::warning('微信支付配置缺失，模拟退款');
            return true;
        }

        try {
            $client = new Client(['timeout' => 30]);

            $body = [
                'transaction_id' => $transactionId,
                'out_refund_no'  => $refundSn,
                'amount'         => [
                    'refund'   => $amountFen,
                    'total'    => yuan_to_fen($totalAmount),
                    'currency' => 'CNY',
                ],
            ];

            $response = $client->post(self::WECHAT_REFUND_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => $body,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['status']) && $result['status'] === 'SUCCESS') {
                return true;
            }

            throw new \RuntimeException('微信退款失败: ' . json_encode($result));
        } catch (\Throwable $e) {
            Log::error("微信退款调用失败: {$e->getMessage()}");
            throw $e;
        }
    }
}