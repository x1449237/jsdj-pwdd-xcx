<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 子商户账户模型
 * @property int    $id
 * @property int    $user_id
 * @property int    $role
 * @property int    $account_type
 * @property string $account_no
 * @property string $account_name
 * @property string $bank_name
 * @property string $bank_branch
 * @property int    $is_verified
 * @property string $verify_time
 * @property int    $status
 * @property string $create_time
 * @property string $update_time
 */
class MerchantAccount extends Model
{
    protected $name = 'merchant_account';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['account_no'];

    const ROLE_PLAYER = 1;
    const ROLE_CLUB = 2;
    const ROLE_DISTRIBUTOR = 3;

    const TYPE_WECHAT = 1;
    const TYPE_ALIPAY = 2;
    const TYPE_BANK_CARD = 3;

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 账户号获取器 - 解密
     */
    public function getAccountNoAttr($value): string
    {
        return $value ? decrypt_data($value) : '';
    }

    /**
     * 账户号修改器 - 加密
     */
    public function setAccountNoAttr($value): string
    {
        return $value ? encrypt_data($value) : '';
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
     * 按角色查询
     */
    public function scopeByRole($query, int $role)
    {
        $query->where('role', $role);
    }

    /**
     * 按账户类型查询
     */
    public function scopeByType($query, int $type)
    {
        $query->where('account_type', $type);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    /**
     * 查询启用的
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}
