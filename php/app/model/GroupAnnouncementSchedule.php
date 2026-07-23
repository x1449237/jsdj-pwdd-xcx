<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 群公告定时推送模型
 * @property int    $id
 * @property int    $group_id      群ID
 * @property string $title         公告标题
 * @property string $content       公告内容
 * @property string $schedule_time 定时发送时间
 * @property int    $is_sent       是否已发送 0否 1是
 * @property string $send_time     实际发送时间
 * @property int    $creator_id    创建者ID
 * @property int    $status        状态 1正常 0取消
 * @property string $create_time
 * @property string $update_time
 */
class GroupAnnouncementSchedule extends Model
{
    protected $name = 'group_announcement_schedule';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const NOT_SENT = 0;
    const SENT     = 1;

    const STATUS_CANCELLED = 0;
    const STATUS_NORMAL    = 1;

    public function scopeByGroup($query, int $groupId)
    {
        $query->where('group_id', $groupId);
    }

    public function scopePending($query)
    {
        $query->where('is_sent', self::NOT_SENT)->where('status', self::STATUS_NORMAL);
    }

    public function scopeSent($query)
    {
        $query->where('is_sent', self::SENT);
    }
}
