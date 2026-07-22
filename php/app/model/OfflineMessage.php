<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 离线消息模型
 * @property int    $id
 * @property int    $user_id       接收者
 * @property int    $from_user_id  发送者
 * @property int    $session_id
 * @property int    $msg_type      消息类型
 * @property string $content
 * @property string $extra
 * @property int    $is_pushed     0-未推送 1-已推送
 * @property int    $push_count    推送次数
 * @property string $create_time
 * @property string $update_time
 */
class OfflineMessage extends Model
{
    protected $name = 'offline_message';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

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
     * 关联接收者
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联会话
     */
    public function session()
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'id');
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 查询未推送
     */
    public function scopeNotPushed($query)
    {
        $query->where('is_pushed', 0);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}