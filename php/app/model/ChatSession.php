<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 聊天会话模型
 * @property int    $id
 * @property string $session_sn    会话编号
 * @property int    $order_id
 * @property int    $user_id
 * @property int    $player_id
 * @property int    $status        0-已关闭 1-进行中
 * @property int    $unread_user   用户未读数
 * @property int    $unread_player 打手未读数
 * @property string $last_message  最后一条消息
 * @property string $last_time     最后消息时间
 * @property string $create_time
 * @property string $update_time
 */
class ChatSession extends Model
{
    protected $name = 'chat_session';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_CLOSED = 0;
    const STATUS_ACTIVE = 1;

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联打手
     */
    public function player()
    {
        return $this->belongsTo(User::class, 'player_id', 'id');
    }

    /**
     * 关联聊天消息
     */
    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'session_id', 'id');
    }

    /**
     * 查询进行中
     */
    public function scopeActive($query)
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按打手查询
     */
    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_id', $playerId);
    }

    /**
     * 按最后消息时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('last_time', 'desc');
    }
}