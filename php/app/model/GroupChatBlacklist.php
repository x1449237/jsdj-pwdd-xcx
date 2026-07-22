<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 群聊黑名单模型
 * @property int    $id
 * @property int    $group_id
 * @property int    $user_id
 * @property int    $operator_id
 * @property string $create_time
 */
class GroupChatBlacklist extends Model
{
    protected $name = 'group_chat_blacklist';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;
}