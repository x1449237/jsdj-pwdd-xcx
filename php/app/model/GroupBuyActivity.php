<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 拼团活动模型
 * @property int    $id
 * @property int    $game_id
 * @property string $name
 * @property string $original_price
 * @property string $group_price
 * @property int    $min_people
 * @property int    $max_people
 * @property int    $duration_hours
 * @property int    $status            0-禁用 1-启用
 * @property int    $sort
 * @property string $create_time
 * @property string $update_time
 */
class GroupBuyActivity extends Model
{
    protected $name = 'group_buy_activity';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByGame($query, int $gameId)
    {
        $query->where('game_id', $gameId);
    }

    public function groupOrders()
    {
        return $this->hasMany(GroupBuyOrder::class, 'activity_id', 'id');
    }
}
