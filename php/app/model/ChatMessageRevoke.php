<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 消息撤回记录模型
 * @property int    $id
 * @property int    $session_id       会话ID
 * @property int    $session_type     会话类型: 1私聊 2群聊 3售后
 * @property int    $message_id       消息ID
 * @property int    $user_id          撤回用户ID
 * @property int    $msg_type         消息类型
 * @property string $original_content 原始消息内容
 * @property string $revoke_time      撤回时间
 * @property string $create_time
 */
class ChatMessageRevoke extends Model
{
    protected $name = 'chat_message_revoke';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const SESSION_TYPE_PRIVATE   = 1;
    const SESSION_TYPE_GROUP     = 2;
    const SESSION_TYPE_AFTERSALE = 3;

    public function scopeBySession($query, int $sessionId, int $sessionType)
    {
        $query->where('session_id', $sessionId)->where('session_type', $sessionType);
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByMessage($query, int $messageId)
    {
        $query->where('message_id', $messageId);
    }
}
