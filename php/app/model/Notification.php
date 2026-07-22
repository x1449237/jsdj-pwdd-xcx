<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 消息通知模型
 * @property int    $id
 * @property int    $user_id
 * @property string $title
 * @property string $content
 * @property int    $type          1-系统 2-订单 3-聊天 4-财务 5-活动
 * @property int    $is_read       0-未读 1-已读
 * @property string $related_id    关联ID
 * @property string $related_type  关联类型
 * @property string $read_time
 * @property string $create_time
 * @property string $update_time
 */
class Notification extends Model
{
    protected $name = 'notification';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 通知类型
    const TYPE_SYSTEM  = 1;
    const TYPE_ORDER   = 2;
    const TYPE_CHAT    = 3;
    const TYPE_FINANCE = 4;
    const TYPE_ACTIVITY = 5;

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 查询未读
     */
    public function scopeUnread($query)
    {
        $query->where('is_read', 0);
    }

    /**
     * 按类型查询
     */
    public function scopeByType($query, int $type)
    {
        $query->where('type', $type);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}