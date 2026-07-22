<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 聊天消息模型
 * @property int    $id
 * @property int    $session_id
 * @property int    $user_id       发送者
 * @property int    $to_user_id    接收者
 * @property int    $msg_type      0-文本 1-图片 2-语音 3-系统消息
 * @property string $content       消息内容
 * @property string $extra         附加信息 JSON
 * @property int    $is_read       0-未读 1-已读
 * @property int    $status        0-隐藏 1-正常
 * @property string $create_time
 */
class ChatMessage extends Model
{
    protected $name = 'chat_message';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    // 消息类型
    const TYPE_TEXT   = 0;
    const TYPE_IMAGE  = 1;
    const TYPE_VOICE  = 2;
    const TYPE_SYSTEM = 3;

    const STATUS_HIDDEN = 0;
    const STATUS_NORMAL = 1;

    /**
     * 附加信息获取器 - JSON 解码
     */
    public function getExtraAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setExtraAttr($value): string
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
     * 关联发送者
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联接收者
     */
    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_user_id', 'id');
    }

    /**
     * 按会话查询
     */
    public function scopeBySession($query, int $sessionId)
    {
        $query->where('session_id', $sessionId);
    }

    /**
     * 查询正常状态
     */
    public function scopeNormal($query)
    {
        $query->where('status', self::STATUS_NORMAL);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }

    /**
     * 按时间正序
     */
    public function scopeOldest($query)
    {
        $query->order('create_time', 'asc');
    }
}