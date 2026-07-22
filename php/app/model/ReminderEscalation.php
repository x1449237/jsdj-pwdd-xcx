<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 催办降级模型
 * @property int    $id
 * @property int    $appeal_id
 * @property int    $escalation_level 降级层级 1/2/3
 * @property int    $status           0-待处理 1-已处理
 * @property string $assigned_to      指派给
 * @property string $handle_time
 * @property string $create_time
 * @property string $update_time
 */
class ReminderEscalation extends Model
{
    protected $name = 'reminder_escalation';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PENDING   = 0;
    const STATUS_PROCESSED = 1;

    /**
     * 关联申诉
     */
    public function appeal()
    {
        return $this->belongsTo(PhoneAppeal::class, 'appeal_id', 'id');
    }

    /**
     * 查询待处理
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 按层级查询
     */
    public function scopeByLevel($query, int $level)
    {
        $query->where('escalation_level', $level);
    }
}