<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 管理员角色模型
 * @property int    $id
 * @property string $name
 * @property string $description
 * @property string $permissions  JSON格式权限列表
 * @property int    $status       0-禁用 1-正常
 * @property string $create_time
 * @property string $update_time
 */
class AdminRole extends Model
{
    protected $name = 'admin_role';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 权限获取器 - JSON 解码
     */
    public function getPermissionsAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    /**
     * 权限修改器 - JSON 编码
     */
    public function setPermissionsAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关联管理员
     */
    public function admins()
    {
        return $this->hasMany(Admin::class, 'role_id', 'id');
    }

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按名称查询
     */
    public function scopeByName($query, string $name)
    {
        $query->where('name', $name);
    }
}