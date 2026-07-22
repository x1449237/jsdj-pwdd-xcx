<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 监护人验证模型
 * @property int    $id
 * @property int    $user_id
 * @property string $guardian_name
 * @property string $guardian_phone
 * @property string $guardian_id_card
 * @property string $relationship   关系
 * @property int    $status         0-未验证 1-已验证 2-已过期
 * @property string $verify_time
 * @property string $expire_time
 * @property string $create_time
 * @property string $update_time
 */
class GuardianVerify extends Model
{
    protected $name = 'guardian_verify';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['guardian_id_card'];

    // 状态
    const STATUS_UNVERIFIED = 0;
    const STATUS_VERIFIED   = 1;
    const STATUS_EXPIRED    = 2;

    /**
     * 监护人姓名获取器 - 脱敏
     */
    public function getGuardianNameAttr($value): string
    {
        return mask_sensitive($value, 'name');
    }

    /**
     * 监护人手机号获取器 - 脱敏
     */
    public function getGuardianPhoneAttr($value): string
    {
        return mask_sensitive($value, 'phone');
    }

    /**
     * 监护人身份证号获取器 - 脱敏
     */
    public function getGuardianIdCardAttr($value): string
    {
        return mask_sensitive($value, 'id_card');
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 查询已验证
     */
    public function scopeVerified($query)
    {
        $query->where('status', self::STATUS_VERIFIED);
    }

    /**
     * 查询未过期
     */
    public function scopeNotExpired($query)
    {
        $query->where('status', '<>', self::STATUS_EXPIRED)
            ->where('expire_time', '>', date('Y-m-d H:i:s'));
    }
}