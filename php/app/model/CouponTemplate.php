<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 优惠券模板模型
 * @property int    $id
 * @property string $name
 * @property string $type                full_reduction/new_user/compensation/club_exclusive
 * @property string $value
 * @property string $min_amount
 * @property int    $total_count
 * @property int    $used_count
 * @property int    $validity_days
 * @property string $start_time
 * @property string $end_time
 * @property string $applicable_scope    all/game/club
 * @property int    $applicable_id
 * @property int    $status              0-禁用 1-启用
 * @property int    $sort
 * @property string $create_time
 * @property string $update_time
 */
class CouponTemplate extends Model
{
    protected $name = 'coupon_template';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const TYPE_FULL_REDUCTION  = 'full_reduction';
    const TYPE_NEW_USER        = 'new_user';
    const TYPE_COMPENSATION    = 'compensation';
    const TYPE_CLUB_EXCLUSIVE  = 'club_exclusive';

    const SCOPE_ALL  = 'all';
    const SCOPE_GAME = 'game';
    const SCOPE_CLUB = 'club';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('type', $type);
    }

    public function isAvailable(): bool
    {
        if ($this->getData('status') != self::STATUS_ENABLED) {
            return false;
        }
        $now = time();
        $startTime = $this->getData('start_time');
        $endTime = $this->getData('end_time');
        if ($startTime && strtotime($startTime) > $now) {
            return false;
        }
        if ($endTime && strtotime($endTime) < $now) {
            return false;
        }
        $totalCount = $this->getData('total_count');
        if ($totalCount > 0 && $this->getData('used_count') >= $totalCount) {
            return false;
        }
        return true;
    }

    public function userCoupons()
    {
        return $this->hasMany(UserCoupon::class, 'coupon_id', 'id');
    }
}
