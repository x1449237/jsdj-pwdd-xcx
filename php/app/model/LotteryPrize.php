<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 抽奖奖品模型
 * @property int    $id
 * @property int    $activity_id
 * @property string $name
 * @property string $type           coupon/free_time/balance/thank
 * @property string $value
 * @property string $probability
 * @property int    $sort
 * @property string $image
 * @property int    $stock
 * @property int    $used_count
 * @property int    $status         0-禁用 1-启用
 * @property string $create_time
 * @property string $update_time
 */
class LotteryPrize extends Model
{
    protected $name = 'lottery_prize';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const TYPE_COUPON    = 'coupon';
    const TYPE_FREE_TIME = 'free_time';
    const TYPE_BALANCE   = 'balance';
    const TYPE_THANK     = 'thank';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByActivity($query, int $activityId)
    {
        $query->where('activity_id', $activityId);
    }

    public function activity()
    {
        return $this->belongsTo(LotteryActivity::class, 'activity_id', 'id');
    }

    public function hasStock(): bool
    {
        $stock = $this->getData('stock');
        if ($stock <= 0) {
            return true;
        }
        return $this->getData('used_count') < $stock;
    }
}
