<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 申诉催办模型
 * @property int    $id
 * @property int    $appeal_id
 * @property int    $remind_count  催办次数
 * @property string $last_remind_time
 * @property string $create_time
 * @property string $update_time
 */
class AppealReminder extends Model
{
    protected $name = 'appeal_reminder';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * 关联申诉
     */
    public function appeal()
    {
        return $this->belongsTo(PhoneAppeal::class, 'appeal_id', 'id');
    }

    /**
     * 按申诉查询
     */
    public function scopeByAppeal($query, int $appealId)
    {
        $query->where('appeal_id', $appealId);
    }
}