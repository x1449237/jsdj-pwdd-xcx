<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\ChatAuditLog;
use app\model\ChatMessage;
use app\model\ChatSession;
use app\model\RiskUser;
use think\Request;

/**
 * 聊天审计与AI风控控制器
 */
class ChatAudit extends BaseController
{
    /**
     * 聊天会话列表
     */
    public function sessionList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword  = $request->param('keyword', '');
        $status   = $request->param('status', '');
        $userId   = $request->paramInt('user_id', 0);
        $playerId = $request->paramInt('player_id', 0);
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = ChatSession::order('last_time', 'desc');

        if (!empty($keyword)) {
            $query->where('session_sn', 'like', "%{$keyword}%");
        }

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        if ($playerId > 0) {
            $query->where('player_id', $playerId);
        }

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 附加审计统计
        foreach ($list as &$item) {
            $item['blocked_count'] = ChatAuditLog::where('session_id', $item['id'])
                ->where('audit_result', ChatAuditLog::RESULT_BLOCK)
                ->count();
            $item['total_messages'] = $item['messages'] ?? 0;
        }

        $this->operationLog('admin_chat_session_list', '查看聊天会话列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 聊天消息列表
     */
    public function messageList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $sessionId = $request->paramInt('session_id', 0);
        $msgType   = $request->param('msg_type', '');
        $auditResult = $request->param('audit_result', '');

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        $query = ChatMessage::where('session_id', $sessionId)
            ->where('status', ChatMessage::STATUS_NORMAL)
            ->order('create_time', 'asc');

        if ($msgType !== '') {
            $query->where('msg_type', (int)$msgType);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 附加审计信息
        foreach ($list as &$item) {
            $auditLog = ChatAuditLog::where('message_id', $item['id'])->find();
            $item['audit'] = $auditLog ? $auditLog->toArray() : null;
        }

        $this->operationLog('admin_chat_message_list', "查看聊天消息，会话ID:{$sessionId}");

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 查看 ASR 转文字结果
     */
    public function asrResult(Request $request)
    {
        $messageId = $request->paramInt('message_id', 0);

        if ($messageId <= 0) {
            return $this->error('消息ID无效');
        }

        $message = ChatMessage::find($messageId);
        if (!$message) {
            return $this->error('消息不存在', 404);
        }

        if ($message->getData('msg_type') != ChatMessage::TYPE_VOICE) {
            return $this->error('该消息不是语音消息');
        }

        $extra = $message->extra;
        $asrResult = $extra['asr_result'] ?? null;

        if (!$asrResult) {
            return $this->error('该消息尚未进行ASR转写');
        }

        $this->operationLog('admin_chat_asr_result', "查看ASR结果，消息ID:{$messageId}");

        return $this->success([
            'message_id'  => $messageId,
            'session_id'  => $message->getData('session_id'),
            'asr_text'    => $asrResult['text'] ?? '',
            'confidence'  => $asrResult['confidence'] ?? 0,
            'create_time' => $asrResult['create_time'] ?? '',
        ]);
    }

    /**
     * 查看图片 OCR 结果
     */
    public function ocrResult(Request $request)
    {
        $messageId = $request->paramInt('message_id', 0);

        if ($messageId <= 0) {
            return $this->error('消息ID无效');
        }

        $message = ChatMessage::find($messageId);
        if (!$message) {
            return $this->error('消息不存在', 404);
        }

        if ($message->getData('msg_type') != ChatMessage::TYPE_IMAGE) {
            return $this->error('该消息不是图片消息');
        }

        $extra = $message->extra;
        $ocrResult = $extra['ocr_result'] ?? null;

        if (!$ocrResult) {
            return $this->error('该消息尚未进行OCR识别');
        }

        $this->operationLog('admin_chat_ocr_result', "查看OCR结果，消息ID:{$messageId}");

        return $this->success([
            'message_id'  => $messageId,
            'session_id'  => $message->getData('session_id'),
            'ocr_text'    => $ocrResult['text'] ?? '',
            'objects'     => $ocrResult['objects'] ?? [],
            'create_time' => $ocrResult['create_time'] ?? '',
        ]);
    }

    /**
     * 查看 NLP 过滤结果
     */
    public function nlpResult(Request $request)
    {
        $messageId = $request->paramInt('message_id', 0);

        if ($messageId <= 0) {
            return $this->error('消息ID无效');
        }

        $auditLog = ChatAuditLog::where('message_id', $messageId)
            ->order('create_time', 'desc')
            ->find();

        if (!$auditLog) {
            return $this->error('该消息尚未进行NLP过滤');
        }

        $this->operationLog('admin_chat_nlp_result', "查看NLP结果，消息ID:{$messageId}");

        return $this->success([
            'message_id'   => $messageId,
            'session_id'   => $auditLog->getData('session_id'),
            'audit_result' => $auditLog->getData('audit_result'),
            'audit_detail' => $auditLog->audit_detail,
            'create_time'  => $auditLog->getData('create_time'),
        ]);
    }

    /**
     * AI 风险用户列表
     */
    public function riskUsers(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $riskLevel = $request->param('risk_level', '');
        $status    = $request->param('status', '');
        $riskType  = $request->param('risk_type', '');

        $query = RiskUser::order('create_time', 'desc');

        if (!empty($riskLevel)) {
            $query->where('risk_level', $riskLevel);
        }

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if (!empty($riskType)) {
            $query->where('risk_type', $riskType);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_chat_risk_users', '查看AI风险用户列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 处理风险用户
     */
    public function handleRiskUser(Request $request)
    {
        $id           = $request->paramInt('id', 0);
        $action       = $request->param('action', ''); // ban/warn/ignore
        $handleResult = $request->param('handle_result', '');

        if ($id <= 0) {
            return $this->error('风险记录ID无效');
        }

        $riskUser = RiskUser::find($id);
        if (!$riskUser) {
            return $this->error('风险记录不存在', 404);
        }

        if ($riskUser->getData('status') == RiskUser::STATUS_PROCESSED) {
            return $this->error('该风险记录已处理');
        }

        $userId = $riskUser->getData('user_id');

        switch ($action) {
            case 'ban':
                $user = \app\model\User::find($userId);
                if ($user) {
                    $user->status = \app\model\User::STATUS_DISABLED;
                    $user->save();
                }
                $riskUser->handle_result = $handleResult ?: '封禁用户';
                break;
            case 'warn':
                $riskUser->handle_result = $handleResult ?: '警告用户';
                break;
            case 'ignore':
                $riskUser->handle_result = $handleResult ?: '忽略';
                break;
            default:
                return $this->error('无效操作，可选: ban/warn/ignore');
        }

        $riskUser->status = RiskUser::STATUS_PROCESSED;
        $riskUser->save();

        $this->operationLog('admin_chat_handle_risk', "处理风险用户 ID:{$id}，操作: {$action}");

        return $this->success(null, '风险用户已处理');
    }
}