<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\DispatchRecord;
use app\model\Evaluation;
use app\model\Order as OrderModel;
use app\model\OrderStatusLog;
use app\model\PlayerService;
use app\model\RiskControlLog;
use app\model\Reward;
use app\model\User as UserModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

/**
 * 打手端控制器（微信小程序端）
 */
class Player extends BaseController
{
    /**
     * 接单中心（待接单列表，WebSocket实时推送）
     */
    public function orderCenter(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $serviceTypeId = $request->paramInt('service_type_id', 0);

        // 获取打手服务配置
        $playerServices = PlayerService::where('user_id', $userId)
            ->where('status', PlayerService::STATUS_ONLINE)
            ->select();

        if ($playerServices->isEmpty()) {
            return $this->error('请先设置服务并上线');
        }

        $serviceTypeIds = $playerServices->column('service_type_id');

        // 查询待接单的派单记录
        $query = DispatchRecord::where('player_id', $userId)
            ->where('status', DispatchRecord::STATUS_PENDING)
            ->order('id', 'desc');

        if ($serviceTypeId > 0) {
            $orderIds = OrderModel::where('service_type_id', $serviceTypeId)->column('id');
            $query->whereIn('order_id', $orderIds);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充订单信息
        foreach ($list as &$item) {
            $order = OrderModel::find($item['order_id']);
            if ($order) {
                $item['order'] = $order->toArray();
                $item['order']['user'] = $order->user()->find()?->hidden(['openid', 'unionid', 'id_card'])->toArray();
            }
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 接单
     */
    public function acceptOrder(Request $request)
    {
        $userId  = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        // 检查订单状态
        $order = OrderModel::find($orderId);
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        if ($order->getData('status') != OrderModel::STATUS_DISPATCHING) {
            return $this->error('该订单不在派单中状态');
        }

        // 检查派单记录
        $dispatchRecord = DispatchRecord::where('order_id', $orderId)
            ->where('player_id', $userId)
            ->where('status', DispatchRecord::STATUS_PENDING)
            ->find();
        if (!$dispatchRecord) {
            return $this->error('您没有该订单的接单权限');
        }

        // 检查打手是否在线
        $onlineServices = PlayerService::where('user_id', $userId)
            ->where('status', PlayerService::STATUS_ONLINE)
            ->count();
        if ($onlineServices == 0) {
            return $this->error('请先上线后再接单');
        }

        Db::startTrans();
        try {
            $oldStatus = $order->getData('status');

            // 更新订单状态
            $order->player_id = $userId;
            $order->status    = OrderModel::STATUS_PAID;
            $order->save();

            // 更新派单记录
            $dispatchRecord->status        = DispatchRecord::STATUS_ACCEPTED;
            $dispatchRecord->response_time = date('Y-m-d H:i:s');
            $dispatchRecord->save();

            // 更新打手服务状态为忙碌
            PlayerService::where('user_id', $userId)->update(['status' => PlayerService::STATUS_BUSY]);

            // 记录订单状态日志
            OrderStatusLog::create([
                'order_id'     => $orderId,
                'old_status'   => $oldStatus,
                'new_status'   => OrderModel::STATUS_PAID,
                'operator_id'  => $userId,
                'operator_type'=> 'player',
                'remark'       => '打手接单',
                'create_time'  => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('接单异常: ' . $e->getMessage());
            return $this->error('接单失败，请稍后重试', 500);
        }

        $this->writeRiskLog($userId, 'player_accept_order', 'low', [
            'order_id' => $orderId,
        ]);

        write_action_log('api_player_accept', "打手 ID:{$userId} 接单 ID:{$orderId}");

        return $this->success(null, '接单成功');
    }

    /**
     * 拒单
     */
    public function rejectOrder(Request $request)
    {
        $userId  = request()->userId();
        $orderId = $request->paramInt('order_id', 0);
        $reason  = $request->param('reason', '打手拒绝');

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        $dispatchRecord = DispatchRecord::where('order_id', $orderId)
            ->where('player_id', $userId)
            ->where('status', DispatchRecord::STATUS_PENDING)
            ->find();
        if (!$dispatchRecord) {
            return $this->error('您没有该订单的接单权限');
        }

        $dispatchRecord->status        = DispatchRecord::STATUS_REJECTED;
        $dispatchRecord->reject_reason = $reason;
        $dispatchRecord->response_time = date('Y-m-d H:i:s');
        $dispatchRecord->save();

        $this->writeRiskLog($userId, 'player_reject_order', 'low', [
            'order_id' => $orderId,
            'reason'   => $reason,
        ]);

        write_action_log('api_player_reject', "打手 ID:{$userId} 拒单 ID:{$orderId}");

        return $this->success(null, '已拒单');
    }

    /**
     * 我的订单
     */
    public function myOrders(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');

        $query = OrderModel::where('player_id', $userId)->order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充用户信息
        foreach ($list as &$item) {
            $order = OrderModel::find($item['id']);
            $item['user'] = $order->user()->find()?->hidden(['openid', 'unionid', 'id_card'])->toArray();
            $item['evaluation'] = $order->evaluation()->find();
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 开始服务
     */
    public function startService(Request $request)
    {
        $userId  = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::where('player_id', $userId)->where('id', $orderId)->find();
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        $oldStatus = $order->getData('status');
        if ($oldStatus != OrderModel::STATUS_PAID) {
            return $this->error('当前订单状态不可开始服务');
        }

        $order->status = OrderModel::STATUS_PLAYING;
        $order->save();

        OrderStatusLog::create([
            'order_id'     => $orderId,
            'old_status'   => $oldStatus,
            'new_status'   => OrderModel::STATUS_PLAYING,
            'operator_id'  => $userId,
            'operator_type'=> 'player',
            'remark'       => '打手开始服务',
            'create_time'  => date('Y-m-d H:i:s'),
        ]);

        write_action_log('api_player_start_service', "打手 ID:{$userId} 开始服务订单 ID:{$orderId}");

        return $this->success(null, '服务已开始');
    }

    /**
     * 完成服务
     */
    public function completeService(Request $request)
    {
        $userId  = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::where('player_id', $userId)->where('id', $orderId)->find();
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        $oldStatus = $order->getData('status');
        if ($oldStatus != OrderModel::STATUS_PLAYING) {
            return $this->error('当前订单状态不可完成服务');
        }

        $order->status = OrderModel::STATUS_COMPLETED;
        $order->completed_time = date('Y-m-d H:i:s');
        $order->save();

        OrderStatusLog::create([
            'order_id'     => $orderId,
            'old_status'   => $oldStatus,
            'new_status'   => OrderModel::STATUS_COMPLETED,
            'operator_id'  => $userId,
            'operator_type'=> 'player',
            'remark'       => '打手完成服务',
            'create_time'  => date('Y-m-d H:i:s'),
        ]);

        // 恢复打手服务状态为在线
        PlayerService::where('user_id', $userId)->update(['status' => PlayerService::STATUS_ONLINE]);

        write_action_log('api_player_complete_service', "打手 ID:{$userId} 完成服务订单 ID:{$orderId}");

        return $this->success(null, '服务已完成');
    }

    /**
     * 我的服务管理
     */
    public function myServices(Request $request)
    {
        $userId = request()->userId();

        $services = PlayerService::where('user_id', $userId)->order('id', 'desc')->select()->toArray();

        // 补充服务类型名称
        foreach ($services as &$item) {
            $serviceType = \app\model\ServiceType::find($item['service_type_id']);
            $item['service_type_name'] = $serviceType?->getData('name') ?? '';
        }

        return $this->success($services);
    }

    /**
     * 更新服务
     */
    public function updateService(Request $request)
    {
        $userId        = request()->userId();
        $id            = $request->paramInt('id', 0);
        $gameName      = $request->param('game_name', '');
        $gameRank      = $request->param('game_rank', '');
        $price         = $request->param('price', '');
        $description   = $request->param('description', '');
        $status        = $request->paramInt('status', -1);

        if ($id <= 0) {
            return $this->error('服务ID无效');
        }

        $service = PlayerService::where('user_id', $userId)->where('id', $id)->find();
        if (!$service) {
            return $this->error('服务不存在', 404);
        }

        if ($gameName !== '') {
            $service->game_name = $gameName;
        }
        if ($gameRank !== '') {
            $service->game_rank = $gameRank;
        }
        if ($price !== '' && is_numeric($price)) {
            $service->price = $price;
        }
        if ($description !== '') {
            $service->description = $description;
        }
        if (in_array($status, [PlayerService::STATUS_OFFLINE, PlayerService::STATUS_ONLINE, PlayerService::STATUS_BUSY])) {
            $service->status = $status;
        }

        $service->save();

        write_action_log('api_player_update_service', "打手 ID:{$userId} 更新服务 ID:{$id}");

        return $this->success($service->toArray(), '更新成功');
    }

    /**
     * 收益中心（余额、冻结金额、收入明细）
     */
    public function income(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 收入明细：完成订单金额 + 打赏金额
        $query = OrderModel::where('player_id', $userId)
            ->where('status', OrderModel::STATUS_COMPLETED)
            ->order('id', 'desc');

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $orders = $query->page($page, $limit)->select()->toArray();

        // 计算总收入
        $totalIncome = OrderModel::where('player_id', $userId)
            ->where('status', OrderModel::STATUS_COMPLETED)
            ->sum('order_amount');
        $totalReward = Reward::where('player_id', $userId)
            ->where('status', Reward::STATUS_SUCCESS)
            ->sum('amount');

        $incomeList = [];
        foreach ($orders as $order) {
            $reward = Reward::where('order_id', $order['id'])
                ->where('status', Reward::STATUS_SUCCESS)
                ->find();
            $incomeList[] = [
                'order_id'     => $order['id'],
                'order_sn'     => $order['order_sn'],
                'order_amount' => $order['order_amount'],
                'reward'       => $reward ? $reward->getData('amount') : '0.00',
                'create_time'  => $order['create_time'],
            ];
        }

        return $this->success([
            'balance'        => $user->getData('balance'),
            'frozen_balance' => $user->getData('frozen_balance'),
            'total_income'   => $totalIncome ? fen_to_yuan((int) $totalIncome) : '0.00',
            'total_reward'   => $totalReward ? fen_to_yuan((int) $totalReward) : '0.00',
            'list'           => $incomeList,
            'total'          => $total,
            'page'           => $page,
            'limit'          => $limit,
            'total_page'     => ceil($total / $limit),
        ]);
    }

    /**
     * 评价申诉
     */
    public function evaluationAppeal(Request $request)
    {
        $userId      = request()->userId();
        $evaluationId = $request->paramInt('evaluation_id', 0);
        $reason      = $request->param('reason', '');
        $evidence    = $request->param('evidence/a', []);

        $error = $this->validateRequired([
            'evaluation_id' => $evaluationId,
            'reason'        => $reason,
        ], ['evaluation_id', 'reason']);
        if ($error) {
            return $this->error($error);
        }

        $evaluation = Evaluation::where('player_id', $userId)->where('id', $evaluationId)->find();
        if (!$evaluation) {
            return $this->error('评价不存在', 404);
        }

        // 将评价状态改为隐藏，等待管理员审核
        $evaluation->status = Evaluation::STATUS_HIDDEN;
        $evaluation->save();

        // 创建申诉记录（使用 PhoneAppeal 模型或通过操作日志记录）
        $this->writeRiskLog($userId, 'evaluation_appeal', 'medium', [
            'evaluation_id' => $evaluationId,
            'reason'        => $reason,
            'evidence'      => $evidence,
        ]);

        write_action_log('api_player_evaluation_appeal', "打手 ID:{$userId} 申诉评价 ID:{$evaluationId}");

        return $this->success(null, '申诉已提交，请等待审核');
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

    public function getTags(Request $request)
    {
        $userId = request()->userId();

        try {
            $tagService = new \app\service\PlayerTagService();
            $tags = $tagService->getPlayerTags($userId);
            return $this->success($tags);
        } catch (\Throwable $e) {
            Log::error('获取打手标签失败: ' . $e->getMessage());
            return $this->error('获取失败', 500);
        }
    }

    public function setTags(Request $request)
    {
        $userId = request()->userId();
        $gameTags = $request->param('game_tags/a', []);
        $positionTags = $request->param('position_tags/a', []);
        $voiceTags = $request->param('voice_tags/a', []);
        $rankTags = $request->param('rank_tags/a', []);
        $skillTags = $request->param('skill_tags/a', []);

        try {
            $tags = [
                'game'     => $gameTags,
                'position' => $positionTags,
                'voice'    => $voiceTags,
                'rank'     => $rankTags,
                'skill'    => $skillTags,
            ];

            $tagService = new \app\service\PlayerTagService();
            $tagService->setPlayerTags($userId, $tags);

            write_action_log('api_player_set_tags', "打手 ID:{$userId} 设置标签");

            return $this->success(null, '标签设置成功');
        } catch (\Throwable $e) {
            Log::error('设置打手标签失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function bidOrders(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');

        try {
            $bidService = new \app\service\OrderBidService();
            $result = $bidService->getPlayerBids($userId, $page, $limit);

            $list = $result['list'];
            foreach ($list as &$item) {
                $order = OrderModel::find($item['order_id']);
                if ($order) {
                    $item['order'] = $order->toArray();
                    $item['order']['user'] = $order->user()->find()?->hidden(['openid', 'unionid', 'id_card'])->toArray();
                }
            }

            return $this->page($list, $result['total'], $page, $limit);
        } catch (\Throwable $e) {
            Log::error('获取竞价订单失败: ' . $e->getMessage());
            return $this->error('获取失败', 500);
        }
    }

    public function placeBid(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);
        $bidPrice = $request->param('bid_price', '');

        $error = $this->validateRequired([
            'order_id'  => $orderId,
            'bid_price' => $bidPrice,
        ], ['order_id', 'bid_price']);
        if ($error) {
            return $this->error($error);
        }

        if (!is_numeric($bidPrice) || $bidPrice <= 0) {
            return $this->error('竞价金额无效');
        }

        try {
            $bidService = new \app\service\OrderBidService();
            $result = $bidService->placeBid($orderId, $userId, $bidPrice);

            write_action_log('api_player_place_bid', "打手 ID:{$userId} 参与竞价: order_id={$orderId}, price={$bidPrice}");

            return $this->success($result, '竞价成功');
        } catch (\Throwable $e) {
            Log::error('参与竞价失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function cancelBid(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $bidService = new \app\service\OrderBidService();
            $bidService->cancelBid($orderId, $userId);

            write_action_log('api_player_cancel_bid', "打手 ID:{$userId} 取消竞价: order_id={$orderId}");

            return $this->success(null, '已取消竞价');
        } catch (\Throwable $e) {
            Log::error('取消竞价失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function uploadEvidence(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);
        $type = $request->param('type', '');
        $fileUrl = $request->param('file_url', '');
        $description = $request->param('description', '');

        $error = $this->validateRequired([
            'order_id' => $orderId,
            'type'     => $type,
            'file_url' => $fileUrl,
        ], ['order_id', 'type', 'file_url']);
        if ($error) {
            return $this->error($error);
        }

        $validTypes = ['gameplay_video', 'rank_screenshot', 'other'];
        if (!in_array($type, $validTypes)) {
            return $this->error('无效的凭证类型');
        }

        try {
            $order = OrderModel::where('player_id', $userId)->where('id', $orderId)->find();
            if (!$order) {
                return $this->error('订单不存在', 404);
            }

            if (!in_array($order->getData('status'), [OrderModel::STATUS_PLAYING, OrderModel::STATUS_COMPLETED])) {
                return $this->error('当前订单状态不可上传凭证');
            }

            $orderService = new \app\service\OrderService();
            $evidenceId = $orderService->uploadEvidence($orderId, $userId, $type, $fileUrl, $description);

            write_action_log('api_player_upload_evidence', "打手 ID:{$userId} 上传凭证: order_id={$orderId}, type={$type}");

            return $this->success(['evidence_id' => $evidenceId], '上传成功');
        } catch (\Throwable $e) {
            Log::error('上传凭证失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function evidenceList(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $order = OrderModel::where('player_id', $userId)->where('id', $orderId)->find();
            if (!$order) {
                return $this->error('订单不存在', 404);
            }

            $orderService = new \app\service\OrderService();
            $list = $orderService->getEvidenceList($orderId);

            return $this->success($list);
        } catch (\Throwable $e) {
            Log::error('获取凭证列表失败: ' . $e->getMessage());
            return $this->error('获取失败', 500);
        }
    }

    public function confirmAppointment(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $orderService = new \app\service\OrderService();
            $orderService->confirmAppointment($orderId, $userId);

            write_action_log('api_player_confirm_appointment', "打手 ID:{$userId} 确认预约: order_id={$orderId}");

            return $this->success(null, '预约已确认');
        } catch (\Throwable $e) {
            Log::error('确认预约失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function startServiceV2(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $orderService = new \app\service\OrderService();
            $orderService->startService($orderId, $userId);

            write_action_log('api_player_start_service_v2', "打手 ID:{$userId} 开始服务: order_id={$orderId}");

            return $this->success(null, '服务已开始');
        } catch (\Throwable $e) {
            Log::error('开始服务失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function completeServiceV2(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $orderService = new \app\service\OrderService();
            $orderService->completeService($orderId, $userId);

            write_action_log('api_player_complete_service_v2', "打手 ID:{$userId} 完成服务: order_id={$orderId}");

            return $this->success(null, '服务已完成');
        } catch (\Throwable $e) {
            Log::error('完成服务失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function pauseService(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $orderService = new \app\service\OrderService();
            $orderService->pauseService($orderId, $userId);

            return $this->success(null, '服务已暂停');
        } catch (\Throwable $e) {
            Log::error('暂停服务失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function resumeService(Request $request)
    {
        $userId = request()->userId();
        $orderId = $request->paramInt('order_id', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        try {
            $orderService = new \app\service\OrderService();
            $orderService->resumeService($orderId, $userId);

            return $this->success(null, '服务已恢复');
        } catch (\Throwable $e) {
            Log::error('恢复服务失败: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
}