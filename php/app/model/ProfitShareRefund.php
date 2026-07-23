<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 退款反向分账模型
 * @property int    $id
 * @property int    $order_id
 * @property int    $refund_id
 * @property string $refund_no
 * @property int    $user_id
 * @property int    $role
 * @property int    $refund_amount
 * @property int    $origin_amount
 * @property int    $status
 * @property int    $operator
 * @property string $remark
 * @property string $create_time
 * @property string $update_time
 */
class ProfitShareRefund extends Model
{
    protected $name = 'profit_share_refund';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const ROLE_PLAYER = 1;
    const ROLE_CLUB = 2;
    const ROLE_DISTRIBUTOR = 3;
    const ROLE_PLATFORM = 4;

    const STATUS_PROCESSING = 0;
    const STATUS_COMPLETED = 1;
    const STATUS_FAILED = 2;

    /**
     * 金额获取器 - 分转元
     */
    public function getRefundAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getOriginAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 金额修改器 - 元转分
     */
    public function setRefundAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setOriginAmountAttr($value): int
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
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 按订单查询
     */
    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }

    /**
     * 按退款单查询
     */
    public function scopeByRefund($query, int $refundId)
    {
        $query->where('refund_id', $refundId);
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}
