<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 打赏模型
 * @property int    $id
 * @property int    $order_id
 * @property int    $user_id       打赏人
 * @property int    $player_id     被打赏打手
 * @property string $amount        打赏金额
 * @property string $message       打赏留言
 * @property int    $status        0-失败 1-成功
 * @property string $create_time
 * @property string $update_time
 */
class Reward extends Model
{
    protected $name = 'reward';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_FAIL    = 0;
    const STATUS_SUCCESS = 1;

    /**
     * 金额获取器 - 分转元
     */
    public function getAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function setAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 关联打赏人
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联被打赏打手
     */
    public function player()
    {
        return $this->belongsTo(User::class, 'player_id', 'id');
    }

    /**
     * 按打手查询
     */
    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_id', $playerId);
    }

    /**
     * 查询成功
     */
    public function scopeSuccess($query)
    {
        $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}