<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\BatchOperationConfirm;
use app\model\Order as OrderModel;
use app\model\OrderStatusLog;
use app\model\RiskControlLog;
use think\facade\Cache;
use think\facade\Db;
use think\Request;

/**
 * 订单管理控制器
 */
class Order extends BaseController
{
    /**
     * 全平台订单列表（分页、筛选、搜索）
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword    = $request->param('keyword', '');
        $status     = $request->param('status', '');
        $userId     = $request->paramInt('user_id', 0);
        $playerId   = $request->paramInt('player_id', 0);
        $startDate  = $request->param('start_date', '');
        $endDate    = $request->param('end_date', '');
        $minAmount  = $request->param('min_amount', '');
        $maxAmount  = $request->param('max_amount', '');

        $query = OrderModel::order('id', 'desc');

        // 关键词搜索
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('order_sn', 'like', "%{$keyword}%")
                  ->whereOr('game_name', 'like', "%{$keyword}%");
            });
        }

        // 状态筛选
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        // 用户/打手筛选
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($playerId > 0) {
            $query->where('player_id', $playerId);
        }

        // 时间范围
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        // 金额筛选
        if (!empty($minAmount)) {
            $query->where('order_amount', '>=', yuan_to_fen($minAmount));
        }
        if (!empty($maxAmount)) {
            $query->where('order_amount', '<=', yuan_to_fen($maxAmount));
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_order_list', '查看订单列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 订单详情
     */
    public function detail(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        $orderData = $order->toArray();

        // 关联数据
        $orderData['user']        = $order->user()->find();
        $orderData['player']      = $order->player()->find();
        $orderData['status_logs'] = $order->statusLogs()->order('create_time', 'desc')->select()->toArray();
        $orderData['payment']     = $order->payment()->find();
        $orderData['chat_session'] = $order->chatSession()->find();

        $this->operationLog('admin_order_detail', "查看订单详情 ID:{$id}");

        return $this->success($orderData);
    }

    /**
     * 强制扭转订单状态（需二级扫码确认）
     */
    public function forceStatusChange(Request $request)
    {
        $orderId    = $request->paramInt('order_id', 0);
        $newStatus  = $request->paramInt('new_status', -1);
        $confirmSn  = $request->param('confirm_sn', '');
        $remark     = $request->param('remark', '');

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::find($orderId);
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        $validStatuses = [
            OrderModel::STATUS_PENDING, OrderModel::STATUS_PAID, OrderModel::STATUS_PLAYING,
            OrderModel::STATUS_COMPLETED, OrderModel::STATUS_CANCELED, OrderModel::STATUS_REFUNDING,
            OrderModel::STATUS_REFUNDED, OrderModel::STATUS_TIMEOUT, OrderModel::STATUS_DISPATCHING,
        ];

        if (!in_array($newStatus, $validStatuses)) {
            return $this->error('无效的订单状态');
        }

        // 如果没有 confirm_sn，生成确认记录，等待二级扫码确认
        if (empty($confirmSn)) {
            $batchSn = generate_sn('BOC');

            BatchOperationConfirm::create([
                'admin_id'       => $this->adminId(),
                'batch_sn'       => $batchSn,
                'action'         => 'force_status_change',
                'target_count'   => 1,
                'processed_count'=> 0,
                'status'         => BatchOperationConfirm::STATUS_PENDING,
                'detail'         => json_encode([
                    'order_id'   => $orderId,
                    'old_status' => $order->getData('status'),
                    'new_status' => $newStatus,
                    'remark'     => $remark,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->operationLog('admin_order_force_status', "发起强制扭转订单状态，订单ID:{$orderId}，待二级确认");

            return $this->success([
                'batch_sn'   => $batchSn,
                'need_confirm'=> true,
                'message'    => '请进行二级扫码确认',
            ], '等待二级确认');
        }

        // 验证确认记录
        $confirm = BatchOperationConfirm::where('batch_sn', $confirmSn)
            ->where('status', BatchOperationConfirm::STATUS_PENDING)
            ->find();

        if (!$confirm) {
            return $this->error('确认记录不存在或已处理', 404);
        }

        $oldStatus = $order->getData('status');

        // 执行状态变更
        $order->status = $newStatus;
        $order->save();

        // 记录状态变更日志
        OrderStatusLog::create([
            'order_id'    => $orderId,
            'old_status'  => $oldStatus,
            'new_status'  => $newStatus,
            'operator_id' => $this->adminId(),
            'operator_type'=> 'admin',
            'remark'      => $remark ?: '管理员强制扭转状态',
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        // 更新确认记录
        $confirm->status = BatchOperationConfirm::STATUS_COMPLETED;
        $confirm->processed_count = 1;
        $confirm->confirm_time = date('Y-m-d H:i:s');
        $confirm->save();

        $this->operationLog('admin_order_force_status_done', "强制扭转订单状态，订单ID:{$orderId}，{$oldStatus}->{$newStatus}");

        return $this->success(null, '订单状态已强制扭转');
    }

    /**
     * 退款审核
     */
    public function refund(Request $request)
    {
        $orderId = $request->paramInt('order_id', 0);
        $action  = $request->param('action', ''); // approve/reject
        $reason  = $request->param('reason', '');

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::find($orderId);
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        if ($order->getData('status') != OrderModel::STATUS_REFUNDING) {
            return $this->error('该订单不在退款中状态');
        }

        if ($action === 'approve') {
            $order->status = OrderModel::STATUS_REFUNDED;
            $order->save();

            OrderStatusLog::create([
                'order_id'     => $orderId,
                'old_status'   => OrderModel::STATUS_REFUNDING,
                'new_status'   => OrderModel::STATUS_REFUNDED,
                'operator_id'  => $this->adminId(),
                'operator_type'=> 'admin',
                'remark'       => '管理员审核通过退款',
                'create_time'  => date('Y-m-d H:i:s'),
            ]);

            $this->operationLog('admin_order_refund_approve', "退款审核通过，订单ID:{$orderId}");
            return $this->success(null, '退款审核通过');
        } elseif ($action === 'reject') {
            if (empty($reason)) {
                return $this->error('拒绝原因不能为空');
            }

            // 恢复到退款前状态
            $order->status = OrderModel::STATUS_COMPLETED;
            $order->save();

            OrderStatusLog::create([
                'order_id'     => $orderId,
                'old_status'   => OrderModel::STATUS_REFUNDING,
                'new_status'   => OrderModel::STATUS_COMPLETED,
                'operator_id'  => $this->adminId(),
                'operator_type'=> 'admin',
                'remark'       => '管理员拒绝退款，原因: ' . $reason,
                'create_time'  => date('Y-m-d H:i:s'),
            ]);

            $this->operationLog('admin_order_refund_reject', "退款审核拒绝，订单ID:{$orderId}，原因: {$reason}");
            return $this->success(null, '退款审核已拒绝');
        } else {
            return $this->error('无效操作，可选: approve/reject');
        }
    }

    /**
     * 批量操作（需二级扫码确认+限流100条/秒）
     */
    public function batchOperation(Request $request)
    {
        $action    = $request->param('action', '');
        $orderIds  = $request->param('order_ids', '');
        $newStatus = $request->paramInt('new_status', -1);
        $confirmSn = $request->param('confirm_sn', '');
        $remark    = $request->param('remark', '');

        $ids = array_filter(array_map('intval', explode(',', $orderIds)));

        if (empty($ids)) {
            return $this->error('请选择订单');
        }

        if (count($ids) > 500) {
            return $this->error('单次最多操作500条订单');
        }

        // 如果没有确认号，生成确认记录
        if (empty($confirmSn)) {
            $batchSn = generate_sn('BOC');

            BatchOperationConfirm::create([
                'admin_id'       => $this->adminId(),
                'batch_sn'       => $batchSn,
                'action'         => "batch_{$action}",
                'target_count'   => count($ids),
                'processed_count'=> 0,
                'status'         => BatchOperationConfirm::STATUS_PENDING,
                'detail'         => json_encode([
                    'order_ids'  => $ids,
                    'action'     => $action,
                    'new_status' => $newStatus,
                    'remark'     => $remark,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->operationLog('admin_order_batch_init', "发起批量操作: {$action}，共".count($ids)."条，待二级确认");

            return $this->success([
                'batch_sn'    => $batchSn,
                'need_confirm'=> true,
                'count'       => count($ids),
                'message'     => '请进行二级扫码确认',
            ], '等待二级确认');
        }

        // 验证确认记录
        $confirm = BatchOperationConfirm::where('batch_sn', $confirmSn)
            ->where('status', BatchOperationConfirm::STATUS_PENDING)
            ->find();

        if (!$confirm) {
            return $this->error('确认记录不存在或已处理', 404);
        }

        // 限流 100条/秒
        $rateKey = 'batch_operation_rate:' . $this->adminId();
        if (!rate_limit_check($rateKey, 100, 1)) {
            return $this->error('操作过于频繁，请稍后重试', 429);
        }

        $confirm->status = BatchOperationConfirm::STATUS_RUNNING;
        $confirm->save();

        $processed = 0;
        $failed    = [];

        // 分批处理，每批100条，每秒处理一批
        $chunks = array_chunk($ids, 100);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $orderId) {
                try {
                    $order = OrderModel::find($orderId);
                    if (!$order) {
                        $failed[] = $orderId;
                        continue;
                    }

                    switch ($action) {
                        case 'cancel':
                            if (in_array($order->getData('status'), [OrderModel::STATUS_PENDING, OrderModel::STATUS_DISPATCHING])) {
                                $oldStatus = $order->getData('status');
                                $order->status = OrderModel::STATUS_CANCELED;
                                $order->save();

                                OrderStatusLog::create([
                                    'order_id'     => $orderId,
                                    'old_status'   => $oldStatus,
                                    'new_status'   => OrderModel::STATUS_CANCELED,
                                    'operator_id'  => $this->adminId(),
                                    'operator_type'=> 'admin',
                                    'remark'       => $remark ?: '管理员批量取消',
                                    'create_time'  => date('Y-m-d H:i:s'),
                                ]);
                            }
                            break;
                        case 'complete':
                            if ($order->getData('status') == OrderModel::STATUS_PLAYING) {
                                $oldStatus = $order->getData('status');
                                $order->status = OrderModel::STATUS_COMPLETED;
                                $order->save();

                                OrderStatusLog::create([
                                    'order_id'     => $orderId,
                                    'old_status'   => $oldStatus,
                                    'new_status'   => OrderModel::STATUS_COMPLETED,
                                    'operator_id'  => $this->adminId(),
                                    'operator_type'=> 'admin',
                                    'remark'       => $remark ?: '管理员批量完成',
                                    'create_time'  => date('Y-m-d H:i:s'),
                                ]);
                            }
                            break;
                        default:
                            $failed[] = $orderId;
                            continue 2;
                    }
                    $processed++;
                } catch (\Throwable $e) {
                    $failed[] = $orderId;
                }
            }
            // 每秒限制
            if (count($chunks) > 1) {
                usleep(100000); // 100ms
            }
        }

        $confirm->status = BatchOperationConfirm::STATUS_COMPLETED;
        $confirm->processed_count = $processed;
        $confirm->confirm_time = date('Y-m-d H:i:s');
        $confirm->save();

        $this->operationLog('admin_order_batch_done', "批量操作完成: {$action}，成功{$processed}条，失败".count($failed)."条");

        return $this->success([
            'processed' => $processed,
            'failed'    => $failed,
            'total'     => count($ids),
        ], "批量操作完成，成功{$processed}条");
    }

    /**
     * 大额验证失败订单列表
     */
    public function largeFailOrders(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        // 大额阈值 500元
        $largeThreshold = yuan_to_fen('500');

        $query = OrderModel::where('order_amount', '>=', $largeThreshold)
            ->where('status', 'in', [OrderModel::STATUS_PENDING, OrderModel::STATUS_CANCELED])
            ->order('id', 'desc');

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充风控日志
        foreach ($list as &$item) {
            $riskLog = RiskControlLog::where('order_id', $item['id'])
                ->order('create_time', 'desc')
                ->find();
            $item['risk_log'] = $riskLog ? $riskLog->toArray() : null;
        }

        $this->operationLog('admin_order_large_fail', '查看大额验证失败订单');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 二级扫码确认回调
     */
    public function confirmBatch(Request $request)
    {
        $batchSn = $request->param('batch_sn', '');

        if (empty($batchSn)) {
            return $this->error('批次号不能为空');
        }

        $confirm = BatchOperationConfirm::where('batch_sn', $batchSn)->find();
        if (!$confirm) {
            return $this->error('确认记录不存在', 404);
        }

        if ($confirm->getData('status') == BatchOperationConfirm::STATUS_COMPLETED) {
            return $this->error('该批次已处理完成');
        }

        if ($confirm->getData('status') == BatchOperationConfirm::STATUS_FAIL) {
            return $this->error('该批次处理失败');
        }

        return $this->success([
            'batch_sn'       => $batchSn,
            'action'         => $confirm->getData('action'),
            'target_count'   => $confirm->getData('target_count'),
            'processed_count'=> $confirm->getData('processed_count'),
            'status'         => $confirm->getData('status'),
            'detail'         => $confirm->detail,
            'create_time'    => $confirm->getData('create_time'),
        ]);
    }
}