<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\ChatMessage;
use app\model\ChatSession;
use app\model\Order as OrderModel;
use app\model\RiskControlLog;
use app\model\SensitiveWord;
use app\model\User as UserModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

/**
 * 聊天相关控制器（微信小程序端）
 */
class Chat extends BaseController
{
    /**
     * 聊天列表
     */
    public function sessionList(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();

        // 查询用户参与的聊天会话（作为用户或打手）
        $query = ChatSession::where(function ($q) use ($userId) {
            $q->where('user_id', $userId)->whereOr('player_id', $userId);
        })->order('last_time', 'desc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充对方信息
        foreach ($list as &$item) {
            $otherUserId = ($item['user_id'] == $userId) ? $item['player_id'] : $item['user_id'];
            $otherUser = UserModel::find($otherUserId);
            $item['other_user'] = $otherUser ? $otherUser->hidden(['openid', 'unionid', 'id_card'])->toArray() : null;
            $item['unread'] = ($item['user_id'] == $userId) ? $item['unread_user'] : $item['unread_player'];
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 聊天消息列表
     */
    public function messageList(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);
        [$page, $limit] = $this->pageParams();

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        // 验证会话权限
        $session = ChatSession::where('id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereOr('player_id', $userId);
            })->find();
        if (!$session) {
            return $this->error('会话不存在', 404);
        }

        // 查询消息（按时间正序，即旧→新）
        $query = ChatMessage::where('session_id', $sessionId)
            ->where('status', ChatMessage::STATUS_NORMAL)
            ->order('create_time', 'asc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 发送文字消息（敏感词过滤）
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

        // 验证会话权限
        $session = ChatSession::where('id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereOr('player_id', $userId);
            })->find();
        if (!$session) {
            return $this->error('会话不存在', 404);
        }

        if ($session->getData('status') == ChatSession::STATUS_CLOSED) {
            return $this->error('会话已关闭');
        }

        // 敏感词过滤
        $filteredContent = $this->filterSensitiveWords($content);
        if ($filteredContent === false) {
            // 包含禁止词
            $this->writeRiskLog($userId, 'chat_sensitive_blocked', 'high', [
                'session_id' => $sessionId,
                'content'    => mb_substr($content, 0, 50),
            ]);
            return $this->error('消息包含违规内容，发送失败');
        }

        // 确定接收者
        $toUserId = ($session->getData('user_id') == $userId)
            ? $session->getData('player_id')
            : $session->getData('user_id');

        // 创建消息
        $message = ChatMessage::create([
            'session_id'  => $sessionId,
            'user_id'     => $userId,
            'to_user_id'  => $toUserId,
            'msg_type'    => ChatMessage::TYPE_TEXT,
            'content'     => $filteredContent,
            'is_read'     => 0,
            'status'      => ChatMessage::STATUS_NORMAL,
        ]);

        // 更新会话信息
        $session->last_message = mb_substr($filteredContent, 0, 50);
        $session->last_time    = date('Y-m-d H:i:s');
        if ($toUserId == $session->getData('player_id')) {
            $session->unread_player = $session->getData('unread_player') + 1;
        } else {
            $session->unread_user = $session->getData('unread_user') + 1;
        }
        $session->save();

        write_action_log('api_chat_send_text', "用户 ID:{$userId} 发送文字消息，会话:{$sessionId}");

        return $this->success($message->toArray(), '发送成功');
    }

    /**
     * 发送语音消息（MP3录制，触发ASR+NLP）
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

        // 验证会话权限
        $session = ChatSession::where('id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereOr('player_id', $userId);
            })->find();
        if (!$session) {
            return $this->error('会话不存在', 404);
        }

        if ($session->getData('status') == ChatSession::STATUS_CLOSED) {
            return $this->error('会话已关闭');
        }

        // 确定接收者
        $toUserId = ($session->getData('user_id') == $userId)
            ? $session->getData('player_id')
            : $session->getData('user_id');

        // 创建消息
        $message = ChatMessage::create([
            'session_id'  => $sessionId,
            'user_id'     => $userId,
            'to_user_id'  => $toUserId,
            'msg_type'    => ChatMessage::TYPE_VOICE,
            'content'     => $voiceUrl,
            'extra'       => [
                'duration' => $duration,
                'asr_text' => '', // 待ASR异步处理
            ],
            'is_read'     => 0,
            'status'      => ChatMessage::STATUS_NORMAL,
        ]);

        // 更新会话信息
        $session->last_message = '[语音消息]';
        $session->last_time    = date('Y-m-d H:i:s');
        if ($toUserId == $session->getData('player_id')) {
            $session->unread_player = $session->getData('unread_player') + 1;
        } else {
            $session->unread_user = $session->getData('unread_user') + 1;
        }
        $session->save();

        // 触发ASR+NLP异步处理（此处标记，实际由队列或异步任务处理）
        $this->triggerAsrNlp($message->id, $voiceUrl);

        write_action_log('api_chat_send_voice', "用户 ID:{$userId} 发送语音消息，会话:{$sessionId}");

        return $this->success($message->toArray(), '发送成功');
    }

    /**
     * 发送图片消息（触发OCR）
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

        // 验证会话权限
        $session = ChatSession::where('id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereOr('player_id', $userId);
            })->find();
        if (!$session) {
            return $this->error('会话不存在', 404);
        }

        if ($session->getData('status') == ChatSession::STATUS_CLOSED) {
            return $this->error('会话已关闭');
        }

        // 确定接收者
        $toUserId = ($session->getData('user_id') == $userId)
            ? $session->getData('player_id')
            : $session->getData('user_id');

        // 创建消息
        $message = ChatMessage::create([
            'session_id'  => $sessionId,
            'user_id'     => $userId,
            'to_user_id'  => $toUserId,
            'msg_type'    => ChatMessage::TYPE_IMAGE,
            'content'     => $imageUrl,
            'extra'       => [
                'ocr_text' => '', // 待OCR异步处理
            ],
            'is_read'     => 0,
            'status'      => ChatMessage::STATUS_NORMAL,
        ]);

        // 更新会话信息
        $session->last_message = '[图片消息]';
        $session->last_time    = date('Y-m-d H:i:s');
        if ($toUserId == $session->getData('player_id')) {
            $session->unread_player = $session->getData('unread_player') + 1;
        } else {
            $session->unread_user = $session->getData('unread_user') + 1;
        }
        $session->save();

        // 触发OCR异步处理
        $this->triggerOcr($message->id, $imageUrl);

        write_action_log('api_chat_send_image', "用户 ID:{$userId} 发送图片消息，会话:{$sessionId}");

        return $this->success($message->toArray(), '发送成功');
    }

    /**
     * 撤回消息
     */
    public function recall(Request $request)
    {
        $userId    = request()->userId();
        $messageId = $request->paramInt('message_id', 0);

        if ($messageId <= 0) {
            return $this->error('消息ID无效');
        }

        try {
            $chatService = new \app\service\ChatService();
            $chatService->recallMessage($messageId, $userId);
            return $this->success(null, '消息已撤回');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 标记已读
     */
    public function readStatus(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        $session = ChatSession::where('id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereOr('player_id', $userId);
            })->find();
        if (!$session) {
            return $this->error('会话不存在', 404);
        }

        // 标记该会话中发给当前用户的消息为已读
        ChatMessage::where('session_id', $sessionId)
            ->where('to_user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        // 清除未读数
        if ($session->getData('user_id') == $userId) {
            $session->unread_user = 0;
        } else {
            $session->unread_player = 0;
        }
        $session->save();

        return $this->success(null, '已标记已读');
    }

    // ===================== 私有辅助方法 =====================

    /**
     * 敏感词过滤
     * @param string $content
     * @return string|false 返回过滤后内容，包含禁止词返回 false
     */
    private function filterSensitiveWords(string $content)
    {
        try {
            // 获取所有启用中的敏感词
            $words = SensitiveWord::where('status', SensitiveWord::STATUS_ENABLED)->select();

            $filtered = $content;

            foreach ($words as $word) {
                $level = $word->getData('level');
                $wordText = $word->getData('word');

                if (mb_strpos($filtered, $wordText) !== false) {
                    if ($level == SensitiveWord::LEVEL_FORBIDDEN) {
                        // 禁止词，直接拒绝
                        return false;
                    } elseif ($level == SensitiveWord::LEVEL_REPLACE) {
                        // 替换词
                        $replacement = $word->getData('replacement') ?: '***';
                        $filtered = str_replace($wordText, $replacement, $filtered);
                    } elseif ($level == SensitiveWord::LEVEL_SENSITIVE) {
                        // 敏感词，替换为***
                        $filtered = str_replace($wordText, '***', $filtered);
                    }
                }
            }

            return $filtered;
        } catch (\Throwable $e) {
            Log::error('敏感词过滤异常: ' . $e->getMessage());
            return $content;
        }
    }

    /**
     * 触发ASR+NLP异步处理
     * @param int    $messageId
     * @param string $voiceUrl
     */
    private function triggerAsrNlp(int $messageId, string $voiceUrl): void
    {
        // 异步任务：语音识别 + 自然语言处理
        // 实际由队列 / 定时任务处理
        try {
            Log::info("触发ASR+NLP异步处理: message_id={$messageId}, voice_url={$voiceUrl}");
            // 写入队列或触发异步任务
        } catch (\Throwable $e) {
            Log::error('触发ASR+NLP异常: ' . $e->getMessage());
        }
    }

    /**
     * 触发OCR异步处理
     * @param int    $messageId
     * @param string $imageUrl
     */
    private function triggerOcr(int $messageId, string $imageUrl): void
    {
        // 异步任务：图片 OCR 文字识别
        try {
            Log::info("触发OCR异步处理: message_id={$messageId}, image_url={$imageUrl}");
            // 写入队列或触发异步任务
        } catch (\Throwable $e) {
            Log::error('触发OCR异常: ' . $e->getMessage());
        }
    }

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

    /**
     * 发送文件消息
     */
    public function sendFile(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);
        $fileUrl   = $request->param('file_url', '');
        $fileName  = $request->param('file_name', '');
        $fileSize  = $request->paramInt('file_size', 0);
        $fileType  = $request->param('file_type', 'document');

        $error = $this->validateRequired([
            'session_id' => $sessionId,
            'file_url'   => $fileUrl,
            'file_name'  => $fileName,
        ], ['session_id', 'file_url', 'file_name']);
        if ($error) {
            return $this->error($error);
        }

        $session = ChatSession::where('id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereOr('player_id', $userId);
            })->find();
        if (!$session) {
            return $this->error('会话不存在', 404);
        }

        if ($session->getData('status') == ChatSession::STATUS_CLOSED) {
            return $this->error('会话已关闭');
        }

        $chatService = new \app\service\ChatService();
        if ($chatService->isUserMuted($userId)) {
            return $this->error('您已被禁言，无法发送消息');
        }

        $toUserId = ($session->getData('user_id') == $userId)
            ? $session->getData('player_id')
            : $session->getData('user_id');

        $message = ChatMessage::create([
            'session_id'  => $sessionId,
            'user_id'     => $userId,
            'to_user_id'  => $toUserId,
            'msg_type'    => ChatMessage::TYPE_FILE,
            'content'     => $fileUrl,
            'extra'       => [
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'file_type' => $fileType,
            ],
            'is_read'     => 0,
            'status'      => ChatMessage::STATUS_NORMAL,
        ]);

        try {
            $fileService = new \app\service\ChatFileService();
            $fileService->uploadFile(
                $sessionId,
                \app\model\ChatFileMessage::SESSION_TYPE_PRIVATE,
                $userId,
                $message->id,
                $fileUrl,
                $fileName,
                $fileSize,
                $fileType
            );
        } catch (\Throwable $e) {
            Log::error('文件消息记录失败: ' . $e->getMessage());
        }

        $session->last_message = '[文件] ' . $fileName;
        $session->last_time    = date('Y-m-d H:i:s');
        if ($toUserId == $session->getData('player_id')) {
            $session->unread_player = $session->getData('unread_player') + 1;
        } else {
            $session->unread_user = $session->getData('unread_user') + 1;
        }
        $session->save();

        write_action_log('api_chat_send_file', "用户 ID:{$userId} 发送文件消息，会话:{$sessionId}");

        return $this->success($message->toArray(), '发送成功');
    }

    /**
     * 获取快捷卡片列表
     */
    public function quickCardList(Request $request)
    {
        $type = $request->param('type', '');

        $chatService = new \app\service\ChatService();
        $list = $chatService->getQuickCards($type ?: null);

        return $this->success($list);
    }

    /**
     * 发送快捷卡片消息
     */
    public function sendQuickCard(Request $request)
    {
        $userId    = request()->userId();
        $sessionId = $request->paramInt('session_id', 0);
        $cardId    = $request->paramInt('card_id', 0);

        $error = $this->validateRequired([
            'session_id' => $sessionId,
            'card_id'    => $cardId,
        ], ['session_id', 'card_id']);
        if ($error) {
            return $this->error($error);
        }

        $card = \app\model\ChatQuickCard::where('id', $cardId)
            ->where('status', \app\model\ChatQuickCard::STATUS_ENABLED)
            ->find();
        if (!$card) {
            return $this->error('快捷卡片不存在');
        }

        $session = ChatSession::where('id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereOr('player_id', $userId);
            })->find();
        if (!$session) {
            return $this->error('会话不存在', 404);
        }

        if ($session->getData('status') == ChatSession::STATUS_CLOSED) {
            return $this->error('会话已关闭');
        }

        $chatService = new \app\service\ChatService();
        if ($chatService->isUserMuted($userId)) {
            return $this->error('您已被禁言，无法发送消息');
        }

        $toUserId = ($session->getData('user_id') == $userId)
            ? $session->getData('player_id')
            : $session->getData('user_id');

        $message = ChatMessage::create([
            'session_id'  => $sessionId,
            'user_id'     => $userId,
            'to_user_id'  => $toUserId,
            'msg_type'    => ChatMessage::TYPE_CARD,
            'content'     => $card->title,
            'extra'       => [
                'card_id'     => $card->id,
                'card_type'   => $card->type,
                'card_title'  => $card->title,
                'card_content' => $card->content,
                'card_action' => $card->action,
                'card_params' => $card->params_json,
                'card_icon'   => $card->icon,
            ],
            'is_read'     => 0,
            'status'      => ChatMessage::STATUS_NORMAL,
        ]);

        $session->last_message = '[卡片] ' . $card->title;
        $session->last_time    = date('Y-m-d H:i:s');
        if ($toUserId == $session->getData('player_id')) {
            $session->unread_player = $session->getData('unread_player') + 1;
        } else {
            $session->unread_user = $session->getData('unread_user') + 1;
        }
        $session->save();

        write_action_log('api_chat_send_quick_card', "用户 ID:{$userId} 发送快捷卡片，会话:{$sessionId}, card_id:{$cardId}");

        return $this->success($message->toArray(), '发送成功');
    }
}