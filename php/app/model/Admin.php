<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 管理员模型
 * @property int    $id
 * @property string $username
 * @property string $password
 * @property string $nickname
 * @property string $avatar
 * @property string $email
 * @property string $phone
 * @property int    $role_id
 * @property int    $status        0-禁用 1-正常
 * @property int    $login_fail_count
 * @property string $last_login_ip
 * @property string $last_login_time
 * @property string $create_time
 * @property string $update_time
 * @property string $delete_time
 */
class Admin extends Model
{
    protected $name = 'admin';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 隐藏字段
    protected $hidden = ['password', 'delete_time'];

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 密码修改器 - 自动加密
     */
    public function setPasswordAttr($value): string
    {
        return bcrypt_create($value);
    }

    /**
     * 手机号获取器 - 脱敏
     */
    public function getPhoneAttr($value): string
    {
        return mask_sensitive($value, 'phone');
    }

    /**
     * 邮箱获取器 - 脱敏
     */
    public function getEmailAttr($value): string
    {
        return mask_sensitive($value, 'email');
    }

    /**
     * 关联角色
     */
    public function role()
    {
        return $this->belongsTo(AdminRole::class, 'role_id', 'id');
    }

    /**
     * 关联密码历史
     */
    public function passwordHistory()
    {
        return $this->hasMany(AdminPasswordHistory::class, 'admin_id', 'id');
    }

    /**
     * 关联通行密钥
     */
    public function webauthn()
    {
        return $this->hasMany(AdminWebauthn::class, 'admin_id', 'id');
    }

    /**
     * 关联初始化日志
     */
    public function initLogs()
    {
        return $this->hasMany(InitLog::class, 'admin_id', 'id');
    }

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按用户名查询
     */
    public function scopeByUsername($query, string $username)
    {
        $query->where('username', $username);
    }

    /**
     * 按角色查询
     */
    public function scopeByRole($query, int $roleId)
    {
        $query->where('role_id', $roleId);
    }

    /**
     * 验证密码
     */
    public function verifyPassword(string $password): bool
    {
        return bcrypt_verify($password, $this->getData('password'));
    }
}