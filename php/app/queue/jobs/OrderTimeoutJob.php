<?php
declare(strict_types=1);

namespace app\queue\jobs;

use think\facade\Log;
use think\facade\Db;
use app\model\Order;

class OrderTimeoutJob
{
    public function handle(array $data): bool
    {
        $orderId = $data['order_id'] ?? 0;

        if (empty($orderId)) {
            Log::error("OrderTimeoutJob: order_id is empty");
            return false;
        }

        try {
            Db::startTrans();

            $order = Order::find($orderId);
            if (!$order) {
                Log::error("OrderTimeoutJob: order not found, order_id={$orderId}");
                Db::rollback();
                return false;
            }

            if ($order->status != Order::STATUS_PENDING) {
                Log::warning("OrderTimeoutJob: order status not pending, order_id={$orderId}, status={$order->status}");
                Db::rollback();
                return false;
            }

            $order->status = Order::STATUS_TIMEOUT;
            $order->cancel_reason = '订单超时未支付';
            $order->canceled_time = date('Y-m-d H:i:s');
            $order->save();

            Log::info("OrderTimeoutJob: order timeout closed, order_id={$orderId}");

            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("OrderTimeoutJob error: " . $e->getMessage() . ", order_id={$orderId}");
            return false;
        }
    }
}
