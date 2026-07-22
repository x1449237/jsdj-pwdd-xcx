<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\DispatchRecord;
use app\model\Order as OrderModel;
use app\model\OrderStatusLog;
use app\model\PlayerService;
use app\model\RiskControlLog;
use think\facade\Db;
use think\facade\Log;
use think\Request;

/**
 * 人工派单端控制器（微信小程序端）
 */
class Dispatcher extends BaseController
{
    /**
     * 派单中心（待分配订单列表）
     */
    public function dispatchCenter(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $serviceTypeId = $request->paramInt('service_type_id', 0);
        $startDate     = $request->param('start_date', '');
        $endDate       = $request->param('end_date', '');

        // 查询待分配的订单（已支付但未派单）
        $query = OrderModel::where('status', OrderModel::STATUS_PAID)
            ->order('id', 'desc');

        if ($serviceTypeId > 0) {
            $query->where('service_type_id', $serviceTypeId);
        }
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $orders = $query->page($page, $limit)->select()->toArray();

        // 补充用户信息
        foreach ($orders as &$order) {
            $orderModel = OrderModel::find($order['id']);
            $order['user'] = $orderModel->user()->find()?->hidden(['openid', 'unionid', 'id_card'])->toArray();
            // 已派单记录
            $order['dispatch_records'] = $orderModel->dispatchRecords()->select()->toArray();
        }

        $this->writeRiskLog(request()->userId(), 'dispatch_center', 'low', []);

        return $this->page($orders, $total, $page, $limit);
    }

    /**
     * 派单给指定打手
     */
    public function dispatch(Request $request)
    {
        $userId   = request()->userId();
        $orderId  = $request->paramInt('order_id', 0);
        $playerId = $request->paramInt('player_id', 0);

        $error = $this->validateRequired([
            'order_id'  => $orderId,
            'player_id' => $playerId,
        ], ['order_id', 'player_id']);
        if ($error) {
            return $this->error($error);
        }

        $order = OrderModel::find($orderId);
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        if ($order->getData('status') != OrderModel::STATUS_PAID) {
            return $this->error('该订单不在待派单状态');
        }

        // 检查打手是否存在且在线
        $playerService = PlayerService::where('user_id', $playerId)
            ->where('status', PlayerService::STATUS_ONLINE)
            ->find();
        if (!$playerService) {
            return $this->error('该打手不在线或不存在');
        }

        // 检查是否已派单给该打手
        $existDispatch = DispatchRecord::where('order_id', $orderId)
            ->where('player_id', $playerId)
            ->where('status', DispatchRecord::STATUS_PENDING)
            ->find();
        if ($existDispatch) {
            return $this->error('该订单已派给该打手，等待接单中');
        }

        Db::startTrans();
        try {
            $oldStatus = $order->getData('status');

            // 更新订单状态为派单中
            $order->status = OrderModel::STATUS_DISPATCHING;
            $order->save();

            // 创建派单记录
            DispatchRecord::create([
                'order_id'      => $orderId,
                'player_id'     => $playerId,
                'dispatch_type' => DispatchRecord::TYPE_MANUAL,
                'status'        => DispatchRecord::STATUS_PENDING,
                'dispatch_time' => date('Y-m-d H:i:s'),
            ]);

            // 记录订单状态日志
            OrderStatusLog::create([
                'order_id'     => $orderId,
                'old_status'   => $oldStatus,
                'new_status'   => OrderModel::STATUS_DISPATCHING,
                'operator_id'  => $userId,
                'operator_type'=> 'dispatcher',
                'remark'       => "人工派单给打手 ID:{$playerId}",
                'create_time'  => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('派单异常: ' . $e->getMessage());
            return $this->error('派单失败，请稍后重试', 500);
        }

        $this->writeRiskLog($userId, 'dispatch_order', 'low', [
            'order_id'  => $orderId,
            'player_id' => $playerId,
        ]);

        write_action_log('api_dispatcher_dispatch', "派单员 ID:{$userId} 派单 ID:{$orderId} 给打手 ID:{$playerId}");

        return $this->success(null, '派单成功');
    }

    /**
     * 派单历史
     */
    public function dispatchHistory(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        // 查询人工派单的历史记录
        $query = DispatchRecord::where('dispatch_type', DispatchRecord::TYPE_MANUAL)
            ->order('id', 'desc');

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充订单和打手信息
        foreach ($list as &$item) {
            $order = OrderModel::find($item['order_id']);
            if ($order) {
                $item['order'] = $order->toArray();
            }
            $player = \app\model\User::find($item['player_id']);
            if ($player) {
                $item['player'] = $player->hidden(['openid', 'unionid', 'id_card'])->toArray();
            }
        }

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 私有辅助方法 =====================

    /**
     * 写入风控日志
     */
    private function writeRiskLog(int $userId, string $event, string $riskLevel, array $detail = []): void
    {
        try {
            RiskControlLog::create([
                'user_id'    => $userId,
                'event'      => $event,
                'risk_level' => $riskLevel,
                'detail'     => $detail,
                'result'     => RiskControlLog::RESULT_PASS,
            ]);
        } catch (\Throwable $e) {
            Log::error('风控日志写入失败: ' . $e->getMessage());
        }
    }
}