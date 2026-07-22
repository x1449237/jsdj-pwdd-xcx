<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 用户V标模型
 * @property int    $id
 * @property int    $user_id
 * @property int    $v_badge_type
 * @property string $v_badge_display
 * @property string $create_time
 * @property string $update_time
 */
class UserVBadge extends Model
{
    protected $name = 'user_v_badge';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
}