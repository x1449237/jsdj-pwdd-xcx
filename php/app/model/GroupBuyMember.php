<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 拼团成员模型
 * @property int    $id
 * @property int    $group_id
 * @property int    $user_id
 * @property int    $order_id
 * @property int    $is_leader
 * @property string $join_time
 * @property string $status            joined/paid/refunded
 * @property string $create_time
 * @property string $update_time
 */
class GroupBuyMember extends Model
{
    protected $name = 'group_buy_member';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_JOINED   = 'joined';
    const STATUS_PAID     = 'paid';
    const STATUS_REFUNDED = 'refunded';

    public function scopeByGroup($query, int $groupId)
    {
        $query->where('group_id', $groupId);
    }

    public function scopeByUserId($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function group()
    {
        return $this->belongsTo(GroupBuyOrder::class, 'group_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
