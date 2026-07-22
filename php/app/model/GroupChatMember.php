<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 群聊成员模型
 * @property int    $id
 * @property int    $group_id
 * @property int    $user_id
 * @property int    $role          0-普通成员 1-管理员 2-创始人
 * @property int    $is_muted      0-否 1-是
 * @property string $mute_until
 * @property string $join_time
 * @property string $create_time
 */
class GroupChatMember extends Model
{
    protected $name = 'group_chat_member';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const ROLE_MEMBER  = 0;
    const ROLE_ADMIN   = 1;
    const ROLE_FOUNDER = 2;
}