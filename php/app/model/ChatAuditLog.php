<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 聊天审计日志模型
 * @property int    $id
 * @property int    $session_id
 * @property int    $message_id
 * @property int    $user_id       发送者
 * @property string $content       原始内容
 * @property int    $audit_result  0-通过 1-拦截 2-替换
 * @property string $audit_detail  审计详情 JSON
 * @property string $create_time
 */
class ChatAuditLog extends Model
{
    protected $name = 'chat_audit_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    // 审计结果
    const RESULT_PASS   = 0;
    const RESULT_BLOCK  = 1;
    const RESULT_REPLACE = 2;

    /**
     * 审计详情获取器 - JSON 解码
     */
    public function getAuditDetailAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setAuditDetailAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关联会话
     */
    public function session()
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'id');
    }

    /**
     * 关联消息
     */
    public function message()
    {
        return $this->belongsTo(ChatMessage::class, 'message_id', 'id');
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 按会话查询
     */
    public function scopeBySession($query, int $sessionId)
    {
        $query->where('session_id', $sessionId);
    }

    /**
     * 查询被拦截的
     */
    public function scopeBlocked($query)
    {
        $query->where('audit_result', self::RESULT_BLOCK);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}