<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubCouponUser extends Model
{
    protected $name = 'club_coupon_user';
    protected $autoWriteTimestamp = false;

    const STATUS_UNUSED = 1;
    const STATUS_USED   = 2;
    const STATUS_EXPIRED = 3;

    public function coupon()
    {
        return $this->belongsTo(ClubCoupon::class, 'coupon_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeUnused($query)
    {
        $query->where('status', self::STATUS_UNUSED);
    }

    public function scopeUsed($query)
    {
        $query->where('status', self::STATUS_USED);
    }
}
