<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 群聊模型
 * @property int    $id
 * @property string $group_name
 * @property string $avatar
 * @property int    $group_type
 * @property int    $creator_id
 * @property int    $status        0-正常 1-已解散
 * @property string $announcement
 * @property int    $is_muted_all  0-否 1-是
 * @property string $create_time
 * @property string $update_time
 */
class GroupChat extends Model
{
    protected $name = 'group_chat';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_NORMAL    = 0;
    const STATUS_DISSOLVED = 1;

    public function scopeNormal($query)
    {
        $query->where('status', self::STATUS_NORMAL);
    }
}