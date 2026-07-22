<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 管理员通行密钥模型 (WebAuthn)
 * @property int    $id
 * @property int    $admin_id
 * @property string $credential_id
 * @property string $public_key
 * @property string $device_name
 * @property string $last_used_time
 * @property int    $status       0-禁用 1-正常
 * @property string $create_time
 * @property string $update_time
 */
class AdminWebauthn extends Model
{
    protected $name = 'admin_webauthn';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['public_key'];

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 关联管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按管理员查询
     */
    public function scopeByAdmin($query, int $adminId)
    {
        $query->where('admin_id', $adminId);
    }

    /**
     * 按凭证ID查询
     */
    public function scopeByCredentialId($query, string $credentialId)
    {
        $query->where('credential_id', $credentialId);
    }
}