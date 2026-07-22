<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 评价模型
 * @property int    $id
 * @property int    $order_id
 * @property int    $user_id
 * @property int    $player_id     被评价打手
 * @property int    $rating        评分 1-5
 * @property string $content       评价内容
 * @property string $tags          评价标签 JSON
 * @property int    $is_anonymous  0-实名 1-匿名
 * @property int    $status        0-隐藏 1-显示
 * @property string $create_time
 * @property string $update_time
 */
class Evaluation extends Model
{
    protected $name = 'evaluation';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_HIDDEN = 0;
    const STATUS_SHOW   = 1;

    /**
     * 标签获取器 - JSON 解码
     */
    public function getTagsAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setTagsAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联被评价打手
     */
    public function player()
    {
        return $this->belongsTo(User::class, 'player_id', 'id');
    }

    /**
     * 查询显示
     */
    public function scopeVisible($query)
    {
        $query->where('status', self::STATUS_SHOW);
    }

    /**
     * 按打手查询
     */
    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_id', $playerId);
    }

    /**
     * 按评分查询
     */
    public function scopeByRating($query, int $rating)
    {
        $query->where('rating', $rating);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}