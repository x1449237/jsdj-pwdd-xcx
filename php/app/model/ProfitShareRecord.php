<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 分账记录模型
 * @property int    $id
 * @property int    $order_id
 * @property string $order_no
 * @property int    $user_id
 * @property int    $role
 * @property int    $amount
 * @property string $ratio
 * @property int    $status
 * @property string $share_time
 * @property string $transaction_id
 * @property string $settle_batch_no
 * @property string $remark
 * @property string $create_time
 * @property string $update_time
 */
class ProfitShareRecord extends Model
{
    protected $name = 'profit_share_record';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const ROLE_PLAYER = 1;
    const ROLE_CLUB = 2;
    const ROLE_DISTRIBUTOR = 3;
    const ROLE_PLATFORM = 4;

    const STATUS_PENDING = 0;
    const STATUS_SETTLED = 1;
    const STATUS_FROZEN = 2;
    const STATUS_REFUNDED = 3;

    /**
     * 金额获取器 - 分转元
     */
    public function getAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 金额修改器 - 元转分
     */
    public function setAmountAttr($value): int
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
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按角色查询
     */
    public function scopeByRole($query, int $role)
    {
        $query->where('role', $role);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    /**
     * 按时间范围查询
     */
    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}
