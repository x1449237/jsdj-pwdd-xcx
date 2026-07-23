<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\BatchOperationConfirm;
use app\model\BatchOperationLog;
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
     * 批量操作（需二级扫码确认+限流100条/秒+熔断机制）
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

        $amountThreshold = yuan_to_fen('10000');
        $countThreshold = 50;
        $dailyAmountLimit = yuan_to_fen('100000');

        $orders = OrderModel::whereIn('id', $ids)->field('id, order_amount, paid_amount, status')->select()->toArray();
        $totalAmount = 0;
        foreach ($orders as $order) {
            $totalAmount += (int)($order['paid_amount'] ?? $order['order_amount'] ?? 0);
        }

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $todayBatchAmount = BatchOperationLog::where('admin_id', $this->adminId())
            ->where('create_time', '>=', $todayStart)
            ->where('create_time', '<=', $todayEnd)
            ->sum('amount');

        $needSmsAlert = false;
        $isCircuitBroken = false;

        if ($totalAmount >= $amountThreshold || count($ids) >= $countThreshold) {
            $needSmsAlert = true;
        }

        if ($todayBatchAmount + $totalAmount >= $dailyAmountLimit) {
            $isCircuitBroken = true;
            return $this->error('今日批量操作金额已达上限（10万元），已触发熔断，请明日再试', 429);
        }

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
                    'total_amount' => $totalAmount,
                    'need_sms_alert' => $needSmsAlert,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $confirmMethod = 'qr';
            if ($needSmsAlert) {
                $confirmMethod = 'qr+sms';
            }

            BatchOperationLog::create([
                'admin_id'       => $this->adminId(),
                'type'           => "order_{$action}",
                'total_count'    => count($ids),
                'success_count'  => 0,
                'fail_count'     => 0,
                'amount'         => $totalAmount,
                'status'         => BatchOperationLog::STATUS_PROCESSING,
                'confirm_method' => $confirmMethod,
            ]);

            $this->operationLog('admin_order_batch_init', "发起批量操作: {$action}，共".count($ids)."条，金额".fen_to_yuan($totalAmount)."元，待二级确认");

            return $this->success([
                'batch_sn'      => $batchSn,
                'need_confirm'  => true,
                'count'         => count($ids),
                'total_amount'  => fen_to_yuan($totalAmount),
                'need_sms_alert'=> $needSmsAlert,
                'message'       => $needSmsAlert ? '高危批量操作，请进行二级扫码确认+短信预警' : '请进行二级扫码确认',
            ], '等待二级确认');
        }

        $confirm = BatchOperationConfirm::where('batch_sn', $confirmSn)
            ->where('status', BatchOperationConfirm::STATUS_PENDING)
            ->find();

        if (!$confirm) {
            return $this->error('确认记录不存在或已处理', 404);
        }

        $rateKey = 'batch_operation_rate:' . $this->adminId();
        if (!rate_limit_check($rateKey, 100, 1)) {
            return $this->error('操作过于频繁，请稍后重试', 429);
        }

        $confirm->status = BatchOperationConfirm::STATUS_RUNNING;
        $confirm->save();

        $processed = 0;
        $failed    = [];
        $successAmount = 0;

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
                                $successAmount += (int)($order->getData('paid_amount') ?: 0);
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
                                $successAmount += (int)($order->getData('paid_amount') ?: 0);
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
            if (count($chunks) > 1) {
                usleep(100000);
            }
        }

        $confirm->status = BatchOperationConfirm::STATUS_COMPLETED;
        $confirm->processed_count = $processed;
        $confirm->confirm_time = date('Y-m-d H:i:s');
        $confirm->save();

        $batchLog = BatchOperationLog::where('admin_id', $this->adminId())
            ->where('type', "order_{$action}")
            ->where('status', BatchOperationLog::STATUS_PROCESSING)
            ->order('id', 'desc')
            ->find();

        if ($batchLog) {
            $batchStatus = $processed == count($ids)
                ? BatchOperationLog::STATUS_SUCCESS
                : (count($failed) == count($ids)
                    ? BatchOperationLog::STATUS_ALL_FAIL
                    : BatchOperationLog::STATUS_PARTIAL_FAIL);
            $batchLog->success_count = $processed;
            $batchLog->fail_count = count($failed);
            $batchLog->status = $batchStatus;
            $batchLog->amount = $successAmount;
            $batchLog->save();
        }

        $this->operationLog('admin_order_batch_done', "批量操作完成: {$action}，成功{$processed}条，失败".count($failed)."条，金额".fen_to_yuan($successAmount)."元");

        return $this->success([
            'processed' => $processed,
            'failed'    => $failed,
            'total'     => count($ids),
            'amount'    => fen_to_yuan($successAmount),
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

    public function packageList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $gameId = $request->paramInt('game_id', 0);
        $status = $request->param('status', '');

        $query = \app\model\OrderPackage::order('sort', 'asc')->order('id', 'desc');

        if ($gameId > 0) {
            $query->byGame($gameId);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $game = \app\model\GameList::find($item['game_id']);
            $item['game_name'] = $game ? $game->name : '';
        }

        $this->operationLog('admin_order_package_list', '查看套餐列表');

        return $this->page($list, $total, $page, $limit);
    }

    public function packageCreate(Request $request)
    {
        $name = $request->param('name', '');
        $gameId = $request->paramInt('game_id', 0);
        $type = $request->param('type', 'duration');
        $durationHours = $request->param('duration_hours', 0);
        $gamesCount = $request->paramInt('games_count', 0);
        $price = $request->param('price', '');
        $originalPrice = $request->param('original_price', '');
        $sort = $request->paramInt('sort', 0);
        $status = $request->paramInt('status', 1);

        if (empty($name)) {
            return $this->error('套餐名称不能为空');
        }
        if ($price === '' || !is_numeric($price) || $price <= 0) {
            return $this->error('套餐价格无效');
        }

        try {
            $orderTypeService = new \app\service\OrderTypeService();
            $packageId = $orderTypeService->createPackage([
                'name'           => $name,
                'game_id'        => $gameId,
                'type'           => $type,
                'duration_hours' => $durationHours,
                'games_count'    => $gamesCount,
                'price'          => $price,
                'original_price' => $originalPrice,
                'sort'           => $sort,
                'status'         => $status,
            ]);

            $this->operationLog('admin_order_package_create', "创建套餐: {$name}");

            return $this->success(['id' => $packageId], '创建成功');
        } catch (\Throwable $e) {
            Log::error('创建套餐失败: ' . $e->getMessage());
            return $this->error('创建失败', 500);
        }
    }

    public function packageUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $data = $request->param();

        if ($id <= 0) {
            return $this->error('套餐ID无效');
        }

        try {
            $orderTypeService = new \app\service\OrderTypeService();
            $orderTypeService->updatePackage($id, $data);

            $this->operationLog('admin_order_package_update', "更新套餐: ID={$id}");

            return $this->success(null, '更新成功');
        } catch (\Throwable $e) {
            Log::error('更新套餐失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function packageToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('套餐ID无效');
        }

        try {
            $orderTypeService = new \app\service\OrderTypeService();
            $orderTypeService->togglePackageStatus($id);

            $this->operationLog('admin_order_package_toggle', "切换套餐状态: ID={$id}");

            return $this->success(null, '状态已切换');
        } catch (\Throwable $e) {
            Log::error('切换套餐状态失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function packageDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('套餐ID无效');
        }

        try {
            $orderTypeService = new \app\service\OrderTypeService();
            $orderTypeService->deletePackage($id);

            $this->operationLog('admin_order_package_delete', "删除套餐: ID={$id}");

            return $this->success(null, '删除成功');
        } catch (\Throwable $e) {
            Log::error('删除套餐失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function refundRuleList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');

        $query = \app\model\OrderRefundRule::order('minutes_threshold', 'asc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_refund_rule_list', '查看退单规则列表');

        return $this->page($list, $total, $page, $limit);
    }

    public function refundRuleCreate(Request $request)
    {
        $name = $request->param('name', '');
        $minutesThreshold = $request->paramInt('minutes_threshold', 0);
        $refundRatio = $request->param('refund_ratio', '');
        $description = $request->param('description', '');
        $status = $request->paramInt('status', 1);

        if (empty($name)) {
            return $this->error('规则名称不能为空');
        }
        if ($refundRatio === '' || !is_numeric($refundRatio)) {
            return $this->error('退款比例无效');
        }

        try {
            $rule = \app\model\OrderRefundRule::create([
                'name'              => $name,
                'minutes_threshold' => $minutesThreshold,
                'refund_ratio'      => $refundRatio,
                'description'       => $description,
                'status'            => $status,
            ]);

            $this->operationLog('admin_refund_rule_create', "创建退单规则: {$name}");

            return $this->success(['id' => $rule->id], '创建成功');
        } catch (\Throwable $e) {
            Log::error('创建退单规则失败: ' . $e->getMessage());
            return $this->error('创建失败', 500);
        }
    }

    public function refundRuleUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $data = $request->param();

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        try {
            $rule = \app\model\OrderRefundRule::find($id);
            if (!$rule) {
                return $this->error('规则不存在', 404);
            }
            $rule->save($data);

            $this->operationLog('admin_refund_rule_update', "更新退单规则: ID={$id}");

            return $this->success(null, '更新成功');
        } catch (\Throwable $e) {
            Log::error('更新退单规则失败: ' . $e->getMessage());
            return $this->error('更新失败', 500);
        }
    }

    public function refundRuleDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        try {
            $rule = \app\model\OrderRefundRule::find($id);
            if (!$rule) {
                return $this->error('规则不存在', 404);
            }
            $rule->delete();

            $this->operationLog('admin_refund_rule_delete', "删除退单规则: ID={$id}");

            return $this->success(null, '删除成功');
        } catch (\Throwable $e) {
            Log::error('删除退单规则失败: ' . $e->getMessage());
            return $this->error('删除失败', 500);
        }
    }

    public function refundRuleToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        try {
            $rule = \app\model\OrderRefundRule::find($id);
            if (!$rule) {
                return $this->error('规则不存在', 404);
            }
            $rule->status = $rule->status == 1 ? 0 : 1;
            $rule->save();

            $this->operationLog('admin_refund_rule_toggle', "切换退单规则状态: ID={$id}");

            return $this->success(null, '状态已切换');
        } catch (\Throwable $e) {
            Log::error('切换退单规则状态失败: ' . $e->getMessage());
            return $this->error('切换失败', 500);
        }
    }

    public function evidenceList(Request $request)
    {
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::find($orderId);
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        $list = \app\model\OrderEvidence::byOrder($orderId)
            ->order('create_time', 'desc')
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $uploader = \app\model\User::find($item['uploader_id']);
            $item['uploader_nickname'] = $uploader ? $uploader->nickname : '';
            $item['uploader_avatar'] = $uploader ? $uploader->avatar : '';
        }

        $this->operationLog('admin_evidence_list', "查看订单凭证: order_id={$orderId}");

        return $this->success($list);
    }

    public function bidList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $orderId = $request->paramInt('order_id', 0);
        $status = $request->param('status', '');

        $query = \app\model\OrderBid::order('id', 'desc');

        if ($orderId > 0) {
            $query->byOrder($orderId);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $order = OrderModel::find($item['order_id']);
            $item['order_sn'] = $order ? $order->order_sn : '';
            $player = \app\model\User::find($item['player_user_id']);
            $item['player_nickname'] = $player ? $player->nickname : '';
            $item['player_avatar'] = $player ? $player->avatar : '';
        }

        $this->operationLog('admin_order_bid_list', '查看竞价订单列表');

        return $this->page($list, $total, $page, $limit);
    }

    public function gameList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');

        $query = \app\model\GameList::order('sort', 'asc')->order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_game_list', '查看游戏列表');

        return $this->page($list, $total, $page, $limit);
    }

    public function gameCreate(Request $request)
    {
        $name = $request->param('name', '');
        $icon = $request->param('icon', '');
        $category = $request->param('category', '');
        $sort = $request->paramInt('sort', 0);
        $status = $request->paramInt('status', 1);

        if (empty($name)) {
            return $this->error('游戏名称不能为空');
        }

        try {
            $game = \app\model\GameList::create([
                'name'     => $name,
                'icon'     => $icon,
                'category' => $category,
                'sort'     => $sort,
                'status'   => $status,
            ]);

            $this->operationLog('admin_game_create', "创建游戏: {$name}");

            return $this->success(['id' => $game->id], '创建成功');
        } catch (\Throwable $e) {
            Log::error('创建游戏失败: ' . $e->getMessage());
            return $this->error('创建失败', 500);
        }
    }

    public function gameUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $data = $request->param();

        if ($id <= 0) {
            return $this->error('游戏ID无效');
        }

        try {
            $game = \app\model\GameList::find($id);
            if (!$game) {
                return $this->error('游戏不存在', 404);
            }
            $game->save($data);

            $this->operationLog('admin_game_update', "更新游戏: ID={$id}");

            return $this->success(null, '更新成功');
        } catch (\Throwable $e) {
            Log::error('更新游戏失败: ' . $e->getMessage());
            return $this->error('更新失败', 500);
        }
    }

    public function gameToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('游戏ID无效');
        }

        try {
            $game = \app\model\GameList::find($id);
            if (!$game) {
                return $this->error('游戏不存在', 404);
            }
            $game->status = $game->status == 1 ? 0 : 1;
            $game->save();

            $this->operationLog('admin_game_toggle', "切换游戏状态: ID={$id}");

            return $this->success(null, '状态已切换');
        } catch (\Throwable $e) {
            Log::error('切换游戏状态失败: ' . $e->getMessage());
            return $this->error('切换失败', 500);
        }
    }

    public function gameDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('游戏ID无效');
        }

        try {
            $game = \app\model\GameList::find($id);
            if (!$game) {
                return $this->error('游戏不存在', 404);
            }
            $game->delete();

            $this->operationLog('admin_game_delete', "删除游戏: ID={$id}");

            return $this->success(null, '删除成功');
        } catch (\Throwable $e) {
            Log::error('删除游戏失败: ' . $e->getMessage());
            return $this->error('删除失败', 500);
        }
    }
}