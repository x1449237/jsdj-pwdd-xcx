<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 老带新奖励配置模型
 * @property int    $id
 * @property string $reward_type       balance/coupon
 * @property string $reward_value
 * @property string $condition_type    first_order/realname
 * @property string $condition_value
 * @property int    $status            0-禁用 1-启用
 * @property int    $sort
 * @property string $create_time
 * @property string $update_time
 */
class InviteRewardConfig extends Model
{
    protected $name = 'invite_reward_config';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const REWARD_TYPE_BALANCE = 'balance';
    const REWARD_TYPE_COUPON  = 'coupon';

    const CONDITION_FIRST_ORDER = 'first_order';
    const CONDITION_REALNAME    = 'realname';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByConditionType($query, string $type)
    {
        $query->where('condition_type', $type);
    }
}
