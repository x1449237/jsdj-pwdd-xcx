<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 消费预警记录模型
 * @property int    $id
 * @property int    $user_id
 * @property string $month
 * @property int    $consume_amount
 * @property int    $warning_level
 * @property string $sent_at
 * @property string $guardian_openid
 * @property string $create_time
 */
class MinorConsumeWarning extends Model
{
    protected $name = 'minor_consume_warning';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const LEVEL_80_PERCENT  = 1;
    const LEVEL_100_PERCENT = 2;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByMonth($query, string $month)
    {
        $query->where('month', $month);
    }

    public function scopeByLevel($query, int $level)
    {
        $query->where('warning_level', $level);
    }
}
