<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 邀请码绑定记录模型
 * @property int    $id
 * @property int    $invite_code_id
 * @property int    $user_id        被邀请人ID
 * @property int    $inviter_id     邀请人ID
 * @property string $bind_time
 * @property string $create_time
 */
class InviteBindLog extends Model
{
    protected $name = 'invite_bind_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    /**
     * 关联邀请码
     */
    public function inviteCode()
    {
        return $this->belongsTo(InviteCode::class, 'invite_code_id', 'id');
    }

    /**
     * 关联被邀请人
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联邀请人
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id', 'id');
    }

    /**
     * 按邀请码查询
     */
    public function scopeByInviteCode($query, int $inviteCodeId)
    {
        $query->where('invite_code_id', $inviteCodeId);
    }

    /**
     * 按邀请人查询
     */
    public function scopeByInviter($query, int $inviterId)
    {
        $query->where('inviter_id', $inviterId);
    }

    /**
     * 按被邀请人查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按时间范围查询
     */
    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }
}