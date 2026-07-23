<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 飞单拦截日志模型
 * @property int    $id
 * @property int    $session_id      会话ID
 * @property int    $session_type    会话类型: 1私聊 2群聊 3售后
 * @property int    $sender_id       发送者ID
 * @property int    $message_id      消息ID
 * @property int    $rule_id         命中规则ID
 * @property string $matched_content 匹配到的内容
 * @property string $level           风险等级: warning/mute/ban
 * @property int    $handled         是否已处理 0否 1是
 * @property string $handle_result   处理结果
 * @property string $handle_time     处理时间
 * @property int    $handler_id      处理人ID
 * @property string $create_time
 */
class ChatAntiFraudLog extends Model
{
    protected $name = 'chat_anti_fraud_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const SESSION_TYPE_PRIVATE   = 1;
    const SESSION_TYPE_GROUP     = 2;
    const SESSION_TYPE_AFTERSALE = 3;

    const HANDLED_NO  = 0;
    const HANDLED_YES = 1;

    public function scopeBySession($query, int $sessionId, int $sessionType)
    {
        $query->where('session_id', $sessionId)->where('session_type', $sessionType);
    }

    public function scopeBySender($query, int $senderId)
    {
        $query->where('sender_id', $senderId);
    }

    public function scopeUnhandled($query)
    {
        $query->where('handled', self::HANDLED_NO);
    }

    public function scopeByLevel($query, string $level)
    {
        $query->where('level', $level);
    }

    public function rule()
    {
        return $this->belongsTo(ChatAntiFraudRule::class, 'rule_id', 'id');
    }
}
