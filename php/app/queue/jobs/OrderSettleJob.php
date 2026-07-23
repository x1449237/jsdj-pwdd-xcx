<?php
declare(strict_types=1);

namespace app\queue\jobs;

use think\facade\Log;
use think\facade\Db;
use app\model\Order;

class OrderSettleJob
{
    public function handle(array $data): bool
    {
        $orderId = $data['order_id'] ?? 0;

        if (empty($orderId)) {
            Log::error("OrderSettleJob: order_id is empty");
            return false;
        }

        try {
            Db::startTrans();

            $order = Order::find($orderId);
            if (!$order) {
                Log::error("OrderSettleJob: order not found, order_id={$orderId}");
                Db::rollback();
                return false;
            }

            if ($order->status != Order::STATUS_COMPLETED) {
                Log::warning("OrderSettleJob: order status not completed, order_id={$orderId}, status={$order->status}");
                Db::rollback();
                return false;
            }

            $order->settle_time = date('Y-m-d H:i:s');
            $order->save();

            Log::info("OrderSettleJob: order settled successfully, order_id={$orderId}");

            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("OrderSettleJob error: " . $e->getMessage() . ", order_id={$orderId}");
            return false;
        }
    }
}
