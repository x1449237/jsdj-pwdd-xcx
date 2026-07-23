<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 抽奖活动模型
 * @property int    $id
 * @property string $name
 * @property string $type           wheel
 * @property string $cost_type      free/balance/points
 * @property string $cost_value
 * @property int    $daily_limit
 * @property int    $total_limit
 * @property string $start_time
 * @property string $end_time
 * @property int    $status         0-禁用 1-启用
 * @property int    $sort
 * @property string $create_time
 * @property string $update_time
 */
class LotteryActivity extends Model
{
    protected $name = 'lottery_activity';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const TYPE_WHEEL = 'wheel';

    const COST_TYPE_FREE    = 'free';
    const COST_TYPE_BALANCE = 'balance';
    const COST_TYPE_POINTS  = 'points';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeActive($query)
    {
        $now = date('Y-m-d H:i:s');
        $query->where('status', self::STATUS_ENABLED)
            ->where(function ($q) use ($now) {
                $q->whereNull('start_time')->whereOr('start_time', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_time')->whereOr('end_time', '>=', $now);
            });
    }

    public function isActive(): bool
    {
        if ($this->getData('status') != self::STATUS_ENABLED) {
            return false;
        }
        $now = time();
        $startTime = $this->getData('start_time');
        $endTime = $this->getData('end_time');
        if ($startTime && strtotime($startTime) > $now) {
            return false;
        }
        if ($endTime && strtotime($endTime) < $now) {
            return false;
        }
        return true;
    }

    public function prizes()
    {
        return $this->hasMany(LotteryPrize::class, 'activity_id', 'id')
            ->where('status', LotteryPrize::STATUS_ENABLED)
            ->order('sort', 'asc');
    }

    public function records()
    {
        return $this->hasMany(LotteryRecord::class, 'activity_id', 'id');
    }
}
