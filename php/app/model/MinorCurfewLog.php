<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 宵禁拦截日志模型
 * @property int    $id
 * @property int    $user_id
 * @property string $action_type
 * @property string $blocked_at
 * @property string $ip
 * @property string $device_info
 * @property string $create_time
 */
class MinorCurfewLog extends Model
{
    protected $name = 'minor_curfew_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const ACTION_ORDER      = 'order';
    const ACTION_PAY        = 'pay';
    const ACTION_REWARD     = 'reward';
    const ACTION_JOIN_GROUP = 'join_group';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByAction($query, string $actionType)
    {
        $query->where('action_type', $actionType);
    }

    public function scopeBlockedBetween($query, string $start, string $end)
    {
        $query->whereBetween('blocked_at', [$start, $end]);
    }
}
