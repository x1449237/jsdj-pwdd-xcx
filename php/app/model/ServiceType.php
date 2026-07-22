<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 游戏服务类型模型
 * @property int    $id
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property int    $sort        排序
 * @property int    $status      0-禁用 1-正常
 * @property string $create_time
 * @property string $update_time
 */
class ServiceType extends Model
{
    protected $name = 'service_type';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 关联打手服务配置
     */
    public function playerServices()
    {
        return $this->hasMany(PlayerService::class, 'service_type_id', 'id');
    }

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按排序正序
     */
    public function scopeSorted($query)
    {
        $query->order('sort', 'asc');
    }
}