<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 分账规则模型
 * @property int    $id
 * @property string $name
 * @property int    $type
 * @property int    $service_type_id
 * @property int    $club_id
 * @property string $player_ratio
 * @property string $club_ratio
 * @property string $distributor_ratio
 * @property string $platform_ratio
 * @property int    $is_default
 * @property int    $status
 * @property string $create_time
 * @property string $update_time
 */
class ProfitShareRule extends Model
{
    protected $name = 'profit_share_rule';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_DEFAULT = 1;
    const TYPE_SERVICE = 2;
    const TYPE_CLUB = 3;

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 按类型查询
     */
    public function scopeByType($query, int $type)
    {
        $query->where('type', $type);
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
     * 查询默认规则
     */
    public function scopeDefault($query)
    {
        $query->where('is_default', 1);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}
