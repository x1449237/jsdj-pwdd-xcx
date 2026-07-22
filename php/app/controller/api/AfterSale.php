<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\AfterSaleMessage;
use app\model\AfterSaleSession;
use app\service\AfterSaleService;
use think\facade\Log;
use think\Request;

/**
 * 售后API控制器（用户端）
 */
class AfterSale extends BaseController
{
    /**
     * 我的售后会话列表
     */
    public function sessionList(Request $request)
    {
        $userId = request()->userId();

        $service = new AfterSaleService();
        $list = $service->getMySessions($userId);

        return $this->success($list);
    }

    /**
     * 创建售后申诉会话
     */
    public function createSession(Request $request)
    {
        $userId  = request()->userId();
        $orderId = $request->paramInt('order_id', 0);
        $reason  = $request->param('reason', '');
        $images  = $request->param('images', '');

        $error = $this->validateRequired([
            'order_id' => $orderId,
            'reason'   => $reason,
        ], ['order_id', 'reason']);
        if ($error) {
            return $this->error($error);
        }

        $imageArr = [];
        if (!empty($images)) {
            $imageArr = explode(',', $images);
        }

        try {
            $service = new AfterSaleService();
            $session = $service->createSession($orderId, $userId, $reason, $imageArr);

            $this->operationLog('api_after_sale_create', "创建售后申诉: 订单ID: {$orderId}");

            return $this->success($session, '售后申诉创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建售后申诉异常: ' . $e->getMessage());
            return $this->error('创建失败');
        }
    }

    /**
     * 售后会话详情
     */
    public function sessionDetail(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        $session = AfterSaleSession::find($sessionId);
        if (!$session) {
            return $this->error('售后会话不存在', 404);
        }

        if ($session->user_id != $userId) {
            // 检查是否是平台官方账号
            $platformService = new \app\service\PlatformService();
            if (!$platformService->hasPlatformPermission($userId)) {
                return $this->error('无权查看该会话', 403);
            }
        }

        $this->operationLog('api_after_sale_detail', "查看售后会话详情: ID: {$sessionId}");

        return $this->success($session->toArray());
    }

    /**
     * 售后消息列表
     */
    public function messageList(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);
        [$page, $limit] = $this->pageParams();

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        $session = AfterSaleSession::find($sessionId);
        if (!$session) {
            return $this->error('售后会话不存在', 404);
        }

        if ($session->user_id != $userId) {
            $platformService = new \app\service\PlatformService();
            if (!$platformService->hasPlatformPermission($userId)) {
                return $this->error('无权查看该会话消息', 403);
            }
        }

        $service = new AfterSaleService();
        $result = $service->getMessages($sessionId, $page, $limit);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    /**
     * 发送文字消息（同步检查关键词）
     */
    public function sendText(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);
        $content   = $request->param('content', '');

        $error = $this->validateRequired([
            'session_id' => $sessionId,
            'content'    => $content,
        ], ['session_id', 'content']);
        if ($error) {
            return $this->error($error);
        }

        if (mb_strlen($content) > 500) {
            return $this->error('消息内容不能超过500字');
        }

        try {
            $service = new AfterSaleService();
            $message = $service->sendMessage($sessionId, $userId, AfterSaleMessage::SENDER_USER, AfterSaleMessage::TYPE_TEXT, $content);

            write_action_log('api_after_sale_send_text', "发送售后文字消息: 会话ID: {$sessionId}, 用户ID: {$userId}");

            return $this->success($message, '发送成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('发送售后消息异常: ' . $e->getMessage());
            return $this->error('发送失败');
        }
    }

    /**
     * 发送语音消息
     */
    public function sendVoice(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);
        $voiceUrl  = $request->param('voice_url', '');
        $duration  = $request->paramInt('duration', 0);

        $error = $this->validateRequired([
            'session_id' => $sessionId,
            'voice_url'  => $voiceUrl,
        ], ['session_id', 'voice_url']);
        if ($error) {
            return $this->error($error);
        }

        if ($duration > 60) {
            return $this->error('语音消息不能超过60秒');
        }

        try {
            $service = new AfterSaleService();
            $message = $service->sendMessage($sessionId, $userId, AfterSaleMessage::SENDER_USER, AfterSaleMessage::TYPE_VOICE, $voiceUrl);

            write_action_log('api_after_sale_send_voice', "发送售后语音消息: 会话ID: {$sessionId}, 用户ID: {$userId}");

            return $this->success($message, '发送成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('发送售后语音消息异常: ' . $e->getMessage());
            return $this->error('发送失败');
        }
    }

    /**
     * 发送图片消息
     */
    public function sendImage(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);
        $imageUrl  = $request->param('image_url', '');

        $error = $this->validateRequired([
            'session_id' => $sessionId,
            'image_url'  => $imageUrl,
        ], ['session_id', 'image_url']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new AfterSaleService();
            $message = $service->sendMessage($sessionId, $userId, AfterSaleMessage::SENDER_USER, AfterSaleMessage::TYPE_IMAGE, $imageUrl);

            write_action_log('api_after_sale_send_image', "发送售后图片消息: 会话ID: {$sessionId}, 用户ID: {$userId}");

            return $this->success($message, '发送成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('发送售后图片消息异常: ' . $e->getMessage());
            return $this->error('发送失败');
        }
    }

    /**
     * 申请平台介入
     */
    public function requestIntervene(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        try {
            $service = new AfterSaleService();
            $service->requestManualIntervene($sessionId, $userId, 0);

            $this->operationLog('api_after_sale_intervene', "申请平台介入: 会话ID: {$sessionId}");

            return $this->success(null, '已申请平台介入');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('申请平台介入异常: ' . $e->getMessage());
            return $this->error('申请失败');
        }
    }
}