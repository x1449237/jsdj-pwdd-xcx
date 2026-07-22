<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 打手服务配置模型
 * @property int    $id
 * @property int    $user_id
 * @property int    $service_type_id
 * @property string $game_name     游戏名称
 * @property string $game_rank     段位/等级
 * @property string $price         服务价格
 * @property int    $status        0-下线 1-在线 2-忙碌
 * @property string $description   服务描述
 * @property int    $order_count   接单数
 * @property float  $rating        评分
 * @property string $create_time
 * @property string $update_time
 */
class PlayerService extends Model
{
    protected $name = 'player_service';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_OFFLINE = 0;
    const STATUS_ONLINE  = 1;
    const STATUS_BUSY    = 2;

    /**
     * 价格获取器 - 分转元
     */
    public function getPriceAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 价格修改器 - 元转分
     */
    public function setPriceAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联服务类型
     */
    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id', 'id');
    }

    /**
     * 查询在线
     */
    public function scopeOnline($query)
    {
        $query->where('status', self::STATUS_ONLINE);
    }

    /**
     * 按服务类型查询
     */
    public function scopeByServiceType($query, int $typeId)
    {
        $query->where('service_type_id', $typeId);
    }

    /**
     * 按评分排序
     */
    public function scopeByRating($query, string $direction = 'desc')
    {
        $query->order('rating', $direction);
    }
}