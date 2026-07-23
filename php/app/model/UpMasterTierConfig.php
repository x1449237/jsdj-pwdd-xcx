<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * UP主认证等级配置
 * @property int    $id
 * @property int    $tier            等级 1-6
 * @property string $tier_name       等级名称
 * @property int    $fan_threshold   粉丝门槛
 * @property string $bg_color        底色 #值
 * @property string $highlight_color 高光色 #值
 * @property string $text_color      文字色
 * @property string $badge_size      small/large
 * @property string $visual_desc     视觉描述
 * @property string $effect_type     特效类型
 * @property int    $sort            排序
 */
class UpMasterTierConfig extends Model
{
    protected $name = 'up_master_tier_config';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    public function scopeSorted($query)
    {
        $query->order('sort', 'asc');
    }
}