<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\ChatSession;
use app\model\ElectronicSignature;
use app\model\Evaluation;
use app\model\GuardianVerify;
use app\model\Order as OrderModel;
use app\model\OrderStatusLog;
use app\model\Payment;
use app\model\PlayerService;
use app\model\Reward;
use app\model\RiskControlLog;
use app\model\User as UserModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

/**
 * 订单相关控制器（微信小程序端）
 */
class Order extends BaseController
{
    /**
     * 创建订单（含未成年人金额校验、监护人验证触发、电子签触发）
     */
    public function create(Request $request)
    {
        $userId        = request()->userId();
        $playerServiceId = $request->paramInt('player_service_id', 0);
        $gameName      = $request->param('game_name', '');
        $orderAmount   = $request->param('order_amount', '');
        $remark        = $request->param('remark', '');
        $paymentMethod = $request->param('payment_method', 'wechat');

        $error = $this->validateRequired([
            'player_service_id' => $playerServiceId,
            'game_name'         => $gameName,
            'order_amount'      => $orderAmount,
        ], ['player_service_id', 'game_name', 'order_amount']);
        if ($error) {
            return $this->error($error);
        }

        if (!is_numeric($orderAmount) || $orderAmount <= 0) {
            return $this->error('订单金额无效');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        if ($user->getData('status') == UserModel::STATUS_DISABLED) {
            return $this->error('账号已被禁用', 403);
        }

        // 检查打手服务是否存在且在线
        $playerService = PlayerService::find($playerServiceId);
        if (!$playerService || $playerService->getData('status') != PlayerService::STATUS_ONLINE) {
            return $this->error('该打手服务不可用');
        }

        $playerId = $playerService->getData('user_id');

        // 未成年人金额校验：单笔不超过阈值
        $minorLimit = config_get('order.minor_amount_limit', 200);
        if ($user->getData('is_guardian') == 1 && bc_comp($orderAmount, (string) $minorLimit) > 0) {
            // 检查监护人验证是否有效
            $guardianVerified = GuardianVerify::where('user_id', $userId)
                ->where('status', GuardianVerify::STATUS_VERIFIED)
                ->where('expire_time', '>', date('Y-m-d H:i:s'))
                ->find();
            if (!$guardianVerified) {
                return $this->error('未成年人单笔订单金额不能超过¥' . $minorLimit . '，请先完成监护人验证');
            }
        }

        // 大额订单触发电子签检查
        $largeAmountThreshold = config_get('order.large_amount_threshold', 500);
        $needElectronicSign   = false;
        if (bc_comp($orderAmount, (string) $largeAmountThreshold) >= 0) {
            // 检查是否已完成电子签名
            $signed = ElectronicSignature::where('user_id', $userId)
                ->where('status', ElectronicSignature::STATUS_SIGNED)
                ->find();
            if (!$signed) {
                $needElectronicSign = true;
            }
        }

        // 使用事务创建订单
        $orderSn = generate_sn('OD');
        $orderId = 0;

        Db::startTrans();
        try {
            $order = OrderModel::create([
                'order_sn'        => $orderSn,
                'user_id'         => $userId,
                'player_id'       => $playerId,
                'service_type_id' => $playerService->getData('service_type_id'),
                'game_name'       => $gameName,
                'order_amount'    => $orderAmount,
                'paid_amount'     => $orderAmount,
                'discount_amount' => '0',
                'status'          => OrderModel::STATUS_PENDING,
                'remark'          => $remark,
            ]);

            $orderId = $order->id;

            // 记录订单状态日志
            OrderStatusLog::create([
                'order_id'     => $orderId,
                'old_status'   => -1,
                'new_status'   => OrderModel::STATUS_PENDING,
                'operator_id'  => $userId,
                'operator_type'=> 'user',
                'remark'       => '用户创建订单',
                'create_time'  => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('创建订单异常: ' . $e->getMessage());
            return $this->error('订单创建失败，请稍后重试', 500);
        }

        // 风控日志
        $this->writeRiskLog($userId, 'order_create', 'low', [
            'order_sn'    => $orderSn,
            'order_amount'=> $orderAmount,
            'player_id'   => $playerId,
        ]);

        write_action_log('api_order_create', "用户 ID:{$userId} 创建订单: {$orderSn}");

        // 返回订单信息，标记是否需要电子签名和监护人验证
        $retData = [
            'order_sn'            => $orderSn,
            'order_id'            => $orderId,
            'order_amount'        => $orderAmount,
            'need_electronic_sign' => $needElectronicSign,
            'need_guardian_verify' => false,
        ];

        // 未成年人需要监护人验证触发
        if ($user->getData('is_guardian') == 1) {
            $retData['need_guardian_verify'] = true;
        }

        if ($needElectronicSign) {
            return $this->success($retData, '订单创建成功，大额订单需要先完成电子签名');
        }

        return $this->success($retData, '订单创建成功');
    }

    /**
     * 订单列表（分页，按状态筛选）
     */
    public function list(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');

        $query = OrderModel::where('user_id', $userId)->order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充评价信息
        foreach ($list as &$item) {
            $item['evaluation'] = Evaluation::where('order_id', $item['id'])->find();
            $item['reward']     = Reward::where('order_id', $item['id'])->find();
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 订单详情
     */
    public function detail(Request $request)
    {
        $userId = request()->userId();
        $id     = $request->paramInt('id', 0);
        $orderSn = $request->param('order_sn', '');

        if ($id <= 0 && empty($orderSn)) {
            return $this->error('订单ID或订单号不能为空');
        }

        $query = OrderModel::where('user_id', $userId);
        if ($id > 0) {
            $query->where('id', $id);
        } else {
            $query->where('order_sn', $orderSn);
        }

        $order = $query->find();
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        $orderData = $order->toArray();

        // 关联数据
        $orderData['player']       = $order->player()->find()?->hidden(['openid', 'unionid', 'id_card'])->toArray();
        $orderData['status_logs']  = $order->statusLogs()->order('create_time', 'desc')->select()->toArray();
        $orderData['payment']      = $order->payment()->find();
        $orderData['evaluation']   = $order->evaluation()->find();
        $orderData['reward']       = $order->reward()->find();
        $orderData['chat_session'] = $order->chatSession()->find();

        return $this->success($orderData);
    }

    /**
     * 取消订单
     */
    public function cancel(Request $request)
    {
        $userId = request()->userId();
        $id     = $request->paramInt('id', 0);
        $reason = $request->param('reason', '用户主动取消');

        if ($id <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::where('user_id', $userId)->where('id', $id)->find();
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        // 只能取消待支付和派单中的订单
        $oldStatus = $order->getData('status');
        $cancelable = [OrderModel::STATUS_PENDING, OrderModel::STATUS_DISPATCHING];
        if (!in_array($oldStatus, $cancelable)) {
            return $this->error('当前订单状态不可取消');
        }

        $order->status = OrderModel::STATUS_CANCELED;
        $order->canceled_time = date('Y-m-d H:i:s');
        $order->cancel_reason = $reason;
        $order->save();

        // 记录状态日志
        OrderStatusLog::create([
            'order_id'     => $id,
            'old_status'   => $oldStatus,
            'new_status'   => OrderModel::STATUS_CANCELED,
            'operator_id'  => $userId,
            'operator_type'=> 'user',
            'remark'       => $reason,
            'create_time'  => date('Y-m-d H:i:s'),
        ]);

        $this->writeRiskLog($userId, 'order_cancel', 'low', [
            'order_id' => $id,
            'reason'   => $reason,
        ]);

        write_action_log('api_order_cancel', "用户 ID:{$userId} 取消订单 ID:{$id}");

        return $this->success(null, '订单已取消');
    }

    /**
     * 确认完成（待验收→已完成）
     */
    public function confirmComplete(Request $request)
    {
        $userId = request()->userId();
        $id     = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('订单ID无效');
        }

        $order = OrderModel::where('user_id', $userId)->where('id', $id)->find();
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        // 只有进行中的订单可以确认完成
        $oldStatus = $order->getData('status');
        if ($oldStatus != OrderModel::STATUS_PLAYING) {
            return $this->error('当前订单状态不可确认完成');
        }

        $order->status = OrderModel::STATUS_COMPLETED;
        $order->completed_time = date('Y-m-d H:i:s');
        $order->save();

        // 记录状态日志
        OrderStatusLog::create([
            'order_id'     => $id,
            'old_status'   => $oldStatus,
            'new_status'   => OrderModel::STATUS_COMPLETED,
            'operator_id'  => $userId,
            'operator_type'=> 'user',
            'remark'       => '用户确认完成',
            'create_time'  => date('Y-m-d H:i:s'),
        ]);

        write_action_log('api_order_confirm', "用户 ID:{$userId} 确认完成订单 ID:{$id}");

        return $this->success(null, '订单已完成');
    }

    /**
     * 评价（24小时冷静期）
     */
    public function evaluate(Request $request)
    {
        $userId      = request()->userId();
        $orderId     = $request->paramInt('order_id', 0);
        $rating      = $request->paramInt('rating', 0);
        $content     = $request->param('content', '');
        $tags        = $request->param('tags/a', []);
        $isAnonymous = $request->paramInt('is_anonymous', 0);

        if ($orderId <= 0) {
            return $this->error('订单ID无效');
        }

        if ($rating < 1 || $rating > 5) {
            return $this->error('评分必须在1-5之间');
        }

        $order = OrderModel::where('user_id', $userId)->where('id', $orderId)->find();
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        if ($order->getData('status') != OrderModel::STATUS_COMPLETED) {
            return $this->error('只能评价已完成的订单');
        }

        // 检查24小时冷静期
        $completedTime = strtotime($order->getData('completed_time'));
        $coolingHours  = config_get('order.evaluation_cooling_hours', 24);
        if (time() - $completedTime < $coolingHours * 3600) {
            return $this->error("订单完成后需等待{$coolingHours}小时才能评价");
        }

        // 检查是否已评价
        $existEval = Evaluation::where('order_id', $orderId)->find();
        if ($existEval) {
            return $this->error('该订单已评价');
        }

        Evaluation::create([
            'order_id'     => $orderId,
            'user_id'      => $userId,
            'player_id'    => $order->getData('player_id'),
            'rating'       => $rating,
            'content'      => $content,
            'tags'         => $tags,
            'is_anonymous' => $isAnonymous,
            'status'       => Evaluation::STATUS_SHOW,
        ]);

        // 更新打手评分
        $playerId = $order->getData('player_id');
        $avgRating = Evaluation::where('player_id', $playerId)
            ->where('status', Evaluation::STATUS_SHOW)
            ->avg('rating');
        if ($avgRating) {
            PlayerService::where('user_id', $playerId)->update(['rating' => round($avgRating, 1)]);
        }

        write_action_log('api_order_evaluate', "用户 ID:{$userId} 评价订单 ID:{$orderId}，评分:{$rating}");

        return $this->success(null, '评价成功');
    }

    /**
     * 打赏
     */
    public function reward(Request $request)
    {
        $userId  = request()->userId();
        $orderId = $request->paramInt('order_id', 0);
        $amount  = $request->param('amount', '');
        $message = $request->param('message', '');

        $error = $this->validateRequired([
            'order_id' => $orderId,
            'amount'   => $amount,
        ], ['order_id', 'amount']);
        if ($error) {
            return $this->error($error);
        }

        if (!is_numeric($amount) || $amount <= 0) {
            return $this->error('打赏金额无效');
        }

        $order = OrderModel::where('user_id', $userId)->where('id', $orderId)->find();
        if (!$order) {
            return $this->error('订单不存在', 404);
        }

        if (!in_array($order->getData('status'), [OrderModel::STATUS_COMPLETED, OrderModel::STATUS_PLAYING])) {
            return $this->error('只能对已完成或进行中的订单打赏');
        }

        // 检查是否已打赏
        $existReward = Reward::where('order_id', $orderId)->where('status', Reward::STATUS_SUCCESS)->find();
        if ($existReward) {
            return $this->error('该订单已打赏');
        }

        // 检查余额
        $user = UserModel::find($userId);
        if (bc_comp($user->getData('balance'), $amount) < 0) {
            return $this->error('余额不足');
        }

        Db::startTrans();
        try {
            // 扣减余额
            $user->balance = bc_sub($user->getData('balance'), $amount);
            $user->save();

            // 创建打赏记录
            Reward::create([
                'order_id'  => $orderId,
                'user_id'   => $userId,
                'player_id' => $order->getData('player_id'),
                'amount'    => $amount,
                'message'   => $message,
                'status'    => Reward::STATUS_SUCCESS,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('打赏异常: ' . $e->getMessage());
            return $this->error('打赏失败，请稍后重试', 500);
        }

        $this->writeRiskLog($userId, 'order_reward', 'low', [
            'order_id' => $orderId,
            'amount'   => $amount,
        ]);

        write_action_log('api_order_reward', "用户 ID:{$userId} 打赏订单 ID:{$orderId}，金额:{$amount}");

        return $this->success(null, '打赏成功');
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