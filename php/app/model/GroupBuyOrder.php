<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 拼团订单模型
 * @property int    $id
 * @property int    $activity_id
 * @property int    $leader_user_id
 * @property int    $current_people
 * @property int    $max_people
 * @property string $status            pending/success/failed/canceled
 * @property string $expire_time
 * @property string $success_time
 * @property int    $group_chat_id
 * @property string $create_time
 * @property string $update_time
 */
class GroupBuyOrder extends Model
{
    protected $name = 'group_buy_order';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PENDING  = 'pending';
    const STATUS_SUCCESS  = 'success';
    const STATUS_FAILED   = 'failed';
    const STATUS_CANCELED = 'canceled';

    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSuccess($query)
    {
        $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeByActivity($query, int $activityId)
    {
        $query->where('activity_id', $activityId);
    }

    public function scopeByLeader($query, int $userId)
    {
        $query->where('leader_user_id', $userId);
    }

    public function activity()
    {
        return $this->belongsTo(GroupBuyActivity::class, 'activity_id', 'id');
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_user_id', 'id');
    }

    public function members()
    {
        return $this->hasMany(GroupBuyMember::class, 'group_id', 'id');
    }

    public function isExpired(): bool
    {
        $expireTime = $this->getData('expire_time');
        if (!$expireTime) {
            return false;
        }
        return strtotime($expireTime) < time();
    }

    public function isFull(): bool
    {
        return $this->getData('current_people') >= $this->getData('max_people');
    }
}
