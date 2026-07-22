<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 处罚日志模型
 * @property int    $id
 * @property string $target_type    user / group
 * @property int    $target_id
 * @property string $punishment_type  mute / ban / freeze / dissolve
 * @property string $duration_type
 * @property int    $duration_seconds
 * @property int    $operator_account_id
 * @property string $reason
 * @property int    $status
 * @property string $create_time
 */
class PunishmentLog extends Model
{
    protected $name = 'punishment_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const TARGET_USER  = 'user';
    const TARGET_GROUP = 'group';

    const TYPE_MUTE     = 'mute';
    const TYPE_BAN      = 'ban';
    const TYPE_FREEZE   = 'freeze';
    const TYPE_DISSOLVE = 'dissolve';
}