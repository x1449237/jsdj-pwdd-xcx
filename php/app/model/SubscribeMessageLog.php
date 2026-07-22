<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 微信订阅消息发送日志
 * @property int    $id
 * @property int    $user_id
 * @property string $template_id
 * @property string $scene
 * @property string $openid
 * @property string $send_data
 * @property string $send_result
 * @property int    $is_success
 * @property string $error_msg
 * @property string $related_id
 * @property string $related_type
 * @property string $create_time
 */
class SubscribeMessageLog extends Model
{
    protected $name = 'subscribe_message_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    public function scopeSuccessful($query)
    {
        $query->where('is_success', 1);
    }

    public function scopeFailed($query)
    {
        $query->where('is_success', 0);
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByScene($query, string $scene)
    {
        $query->where('scene', $scene);
    }
}