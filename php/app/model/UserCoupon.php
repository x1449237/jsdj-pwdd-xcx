<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 用户优惠券模型
 * @property int    $id
 * @property int    $coupon_id
 * @property int    $user_id
 * @property string $status            unused/used/expired
 * @property string $receive_channel   register/activity/invite/admin
 * @property string $receive_time
 * @property string $use_time
 * @property int    $order_id
 * @property string $expire_time
 * @property string $create_time
 * @property string $update_time
 */
class UserCoupon extends Model
{
    protected $name = 'user_coupon';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_UNUSED  = 'unused';
    const STATUS_USED    = 'used';
    const STATUS_EXPIRED = 'expired';

    const CHANNEL_REGISTER = 'register';
    const CHANNEL_ACTIVITY = 'activity';
    const CHANNEL_INVITE   = 'invite';
    const CHANNEL_ADMIN    = 'admin';

    public function scopeUnused($query)
    {
        $query->where('status', self::STATUS_UNUSED);
    }

    public function scopeUsed($query)
    {
        $query->where('status', self::STATUS_USED);
    }

    public function scopeExpired($query)
    {
        $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeByUserId($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function coupon()
    {
        return $this->belongsTo(CouponTemplate::class, 'coupon_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function isUsable(): bool
    {
        if ($this->getData('status') != self::STATUS_UNUSED) {
            return false;
        }
        $expireTime = $this->getData('expire_time');
        if ($expireTime && strtotime($expireTime) < time()) {
            return false;
        }
        return true;
    }
}
