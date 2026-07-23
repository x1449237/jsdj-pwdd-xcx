<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 抽奖记录模型
 * @property int    $id
 * @property int    $activity_id
 * @property int    $user_id
 * @property int    $prize_id
 * @property string $prize_name
 * @property string $prize_type
 * @property int    $is_win
 * @property string $draw_time
 * @property string $create_time
 */
class LotteryRecord extends Model
{
    protected $name = 'lottery_record';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    public function scopeByUserId($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByActivity($query, int $activityId)
    {
        $query->where('activity_id', $activityId);
    }

    public function scopeWin($query)
    {
        $query->where('is_win', 1);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function activity()
    {
        return $this->belongsTo(LotteryActivity::class, 'activity_id', 'id');
    }

    public function prize()
    {
        return $this->belongsTo(LotteryPrize::class, 'prize_id', 'id');
    }
}
