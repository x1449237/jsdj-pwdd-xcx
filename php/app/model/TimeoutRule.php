<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 超时规则模型
 * @property int    $id
 * @property string $name
 * @property int    $order_type     订单类型
 * @property int    $timeout_minutes 超时分钟数
 * @property int    $action         超时动作 1-自动取消 2-自动完成 3-提醒
 * @property int    $status         0-禁用 1-正常
 * @property string $create_time
 * @property string $update_time
 */
class TimeoutRule extends Model
{
    protected $name = 'timeout_rule';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 超时动作
    const ACTION_CANCEL   = 1;
    const ACTION_COMPLETE = 2;
    const ACTION_NOTIFY   = 3;

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按订单类型查询
     */
    public function scopeByOrderType($query, int $orderType)
    {
        $query->where('order_type', $orderType);
    }
}