<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 邀请奖励记录模型
 * @property int    $id
 * @property int    $inviter_user_id
 * @property int    $invitee_user_id
 * @property string $reward_type       balance/coupon
 * @property string $reward_value
 * @property string $condition_type
 * @property int    $status            0-待发放 1-已发放 2-发放失败
 * @property string $reward_time
 * @property string $remark
 * @property string $create_time
 * @property string $update_time
 */
class InviteRewardLog extends Model
{
    protected $name = 'invite_reward_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PENDING = 0;
    const STATUS_GRANTED = 1;
    const STATUS_FAILED  = 2;

    public function scopeByInviter($query, int $userId)
    {
        $query->where('inviter_user_id', $userId);
    }

    public function scopeByInvitee($query, int $userId)
    {
        $query->where('invitee_user_id', $userId);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_user_id', 'id');
    }

    public function invitee()
    {
        return $this->belongsTo(User::class, 'invitee_user_id', 'id');
    }
}
