<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubCoupon extends Model
{
    protected $name = 'club_coupon';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_DISCOUNT  = 'discount';
    const TYPE_NEW_USER  = 'new_user';

    const STATUS_ENABLED  = 1;
    const STATUS_DISABLED = 0;

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function userCoupons()
    {
        return $this->hasMany(ClubCouponUser::class, 'coupon_id', 'id');
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeValid($query)
    {
        $now = date('Y-m-d H:i:s');
        $query->where('status', self::STATUS_ENABLED)
              ->where(function ($q) use ($now) {
                  $q->whereNull('start_time')
                    ->whereOr('start_time', '<=', $now);
              })
              ->where(function ($q) use ($now) {
                  $q->whereNull('end_time')
                    ->whereOr('end_time', '>=', $now);
              });
    }

    public static function getTypeMap(): array
    {
        return [
            self::TYPE_DISCOUNT => '满减券',
            self::TYPE_NEW_USER => '新人券',
        ];
    }
}
