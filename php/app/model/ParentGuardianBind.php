<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 家长监护绑定模型
 * @property int    $id
 * @property int    $child_user_id
 * @property string $parent_openid
 * @property string $parent_phone
 * @property string $bind_time
 * @property int    $status
 * @property string $expire_time
 * @property string $create_time
 * @property string $update_time
 */
class ParentGuardianBind extends Model
{
    protected $name = 'parent_guardian_bind';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PENDING  = 0;
    const STATUS_BOUND    = 1;
    const STATUS_UNBOUND  = 2;

    protected $hidden = [];

    public function getParentPhoneAttr($value): string
    {
        return mask_sensitive($value, 'phone');
    }

    public function child()
    {
        return $this->belongsTo(User::class, 'child_user_id', 'id');
    }

    public function setting()
    {
        return $this->hasOne(ParentGuardianSetting::class, 'bind_id', 'id');
    }

    public function consumeReports()
    {
        return $this->hasMany(ParentConsumeReport::class, 'bind_id', 'id');
    }

    public function scopeByChild($query, int $childUserId)
    {
        $query->where('child_user_id', $childUserId);
    }

    public function scopeByParent($query, string $parentOpenid)
    {
        $query->where('parent_openid', $parentOpenid);
    }

    public function scopeBound($query)
    {
        $query->where('status', self::STATUS_BOUND);
    }

    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function isBound(): bool
    {
        return $this->getData('status') == self::STATUS_BOUND;
    }
}
