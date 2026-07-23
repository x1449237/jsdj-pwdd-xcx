<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 文件消息模型
 * @property int    $id
 * @property int    $session_id   会话ID
 * @property int    $session_type 会话类型: 1私聊 2群聊 3售后
 * @property int    $sender_id    发送者ID
 * @property int    $message_id   关联消息ID
 * @property string $file_name    文件名
 * @property int    $file_size    文件大小(字节)
 * @property string $file_url     文件URL
 * @property string $file_type    文件类型: image/document/screenshot
 * @property string $file_ext     文件扩展名
 * @property string $create_time
 */
class ChatFileMessage extends Model
{
    protected $name = 'chat_file_message';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const SESSION_TYPE_PRIVATE   = 1;
    const SESSION_TYPE_GROUP     = 2;
    const SESSION_TYPE_AFTERSALE = 3;

    const TYPE_IMAGE      = 'image';
    const TYPE_DOCUMENT   = 'document';
    const TYPE_SCREENSHOT = 'screenshot';

    public function scopeBySession($query, int $sessionId, int $sessionType)
    {
        $query->where('session_id', $sessionId)->where('session_type', $sessionType);
    }

    public function scopeBySender($query, int $senderId)
    {
        $query->where('sender_id', $senderId);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('file_type', $type);
    }
}
