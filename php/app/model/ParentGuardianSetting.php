<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 监护设置模型
 * @property int    $id
 * @property int    $bind_id
 * @property int    $monthly_limit
 * @property int    $allow_order
 * @property int    $allow_reward
 * @property int    $is_frozen
 * @property string $update_time
 * @property string $create_time
 */
class ParentGuardianSetting extends Model
{
    protected $name = 'parent_guardian_setting';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    public function bind()
    {
        return $this->belongsTo(ParentGuardianBind::class, 'bind_id', 'id');
    }

    public function scopeByBind($query, int $bindId)
    {
        $query->where('bind_id', $bindId);
    }

    public function isFrozen(): bool
    {
        return $this->getData('is_frozen') == 1;
    }

    public function allowOrder(): bool
    {
        return $this->getData('allow_order') == 1;
    }

    public function allowReward(): bool
    {
        return $this->getData('allow_reward') == 1;
    }
}
