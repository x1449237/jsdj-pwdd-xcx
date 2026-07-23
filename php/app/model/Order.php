<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 订单模型
 * @property int    $id
 * @property string $order_sn
 * @property int    $user_id
 * @property int    $player_id        打手ID
 * @property int    $service_type_id
 * @property string $game_name
 * @property string $order_type       订单类型: instant/appointment/team/teaching
 * @property string $order_amount    订单金额
 * @property string $paid_amount     实付金额
 * @property string $discount_amount 优惠金额
 * @property int    $package_id       套餐ID
 * @property int    $is_bid           是否竞价单
 * @property int    $status          订单状态
 * @property string $remark
 * @property string $paid_time
 * @property string $start_time       服务开始时间
 * @property string $completed_time
 * @property string $canceled_time
 * @property string $cancel_reason
 * @property string $create_time
 * @property string $update_time
 * @property string $delete_time
 */
class Order extends Model
{
    protected $name = 'order';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['delete_time'];

    // 订单状态常量
    const STATUS_PENDING      = 0;  // 待支付
    const STATUS_PAID         = 1;  // 已支付
    const STATUS_PLAYING      = 2;  // 进行中
    const STATUS_COMPLETED    = 3;  // 已完成
    const STATUS_CANCELED     = 4;  // 已取消
    const STATUS_REFUNDING    = 5;  // 退款中
    const STATUS_REFUNDED     = 6;  // 已退款
    const STATUS_TIMEOUT      = 7;  // 已超时
    const STATUS_DISPATCHING  = 8;  // 派单中
    const STATUS_APPOINTING   = 9;  // 待确认预约
    const STATUS_BIDDING      = 10; // 竞价中

    // 订单类型常量
    const TYPE_INSTANT     = 'instant';     // 即时单
    const TYPE_APPOINTMENT = 'appointment'; // 预约单
    const TYPE_TEAM        = 'team';        // 车队单
    const TYPE_TEACHING    = 'teaching';    // 教学单

    /**
     * 金额获取器 - 分转元
     */
    public function getOrderAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getPaidAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getDiscountAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 金额修改器 - 元转分
     */
    public function setOrderAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setPaidAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setDiscountAmountAttr($value): int
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
     * 关联打手
     */
    public function player()
    {
        return $this->belongsTo(User::class, 'player_id', 'id');
    }

    /**
     * 关联订单状态日志
     */
    public function statusLogs()
    {
        return $this->hasMany(OrderStatusLog::class, 'order_id', 'id');
    }

    /**
     * 关联支付记录
     */
    public function payment()
    {
        return $this->hasOne(Payment::class, 'order_id', 'id');
    }

    /**
     * 关联评价
     */
    public function evaluation()
    {
        return $this->hasOne(Evaluation::class, 'order_id', 'id');
    }

    /**
     * 关联打赏
     */
    public function reward()
    {
        return $this->hasOne(Reward::class, 'order_id', 'id');
    }

    /**
     * 关联聊天会话
     */
    public function chatSession()
    {
        return $this->hasOne(ChatSession::class, 'order_id', 'id');
    }

    /**
     * 关联派单记录
     */
    public function dispatchRecords()
    {
        return $this->hasMany(DispatchRecord::class, 'order_id', 'id');
    }

    /**
     * 关联服务计时
     */
    public function serviceTimer()
    {
        return $this->hasOne(OrderServiceTimer::class, 'order_id', 'id');
    }

    /**
     * 关联履约凭证
     */
    public function evidences()
    {
        return $this->hasMany(OrderEvidence::class, 'order_id', 'id');
    }

    /**
     * 关联竞价记录
     */
    public function bids()
    {
        return $this->hasMany(OrderBid::class, 'order_id', 'id');
    }

    /**
     * 关联预约信息
     */
    public function appointment()
    {
        return $this->hasOne(OrderAppointment::class, 'order_id', 'id');
    }

    /**
     * 关联套餐
     */
    public function package()
    {
        return $this->belongsTo(OrderPackage::class, 'package_id', 'id');
    }

    /**
     * 关联分账记录
     */
    public function profitShareRecords()
    {
        return $this->hasMany(ProfitShareRecord::class, 'order_id', 'id');
    }

    /**
     * 关联退款分账记录
     */
    public function profitShareRefunds()
    {
        return $this->hasMany(ProfitShareRefund::class, 'order_id', 'id');
    }

    // ===================== Scope =====================

    /**
     * 按订单号查询
     */
    public function scopeByOrderSn($query, string $orderSn)
    {
        $query->where('order_sn', $orderSn);
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按打手查询
     */
    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_id', $playerId);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    /**
     * 查询进行中的订单
     */
    public function scopeActive($query)
    {
        $query->whereIn('status', [self::STATUS_PAID, self::STATUS_PLAYING, self::STATUS_DISPATCHING]);
    }

    /**
     * 按时间范围查询
     */
    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    /**
     * 按订单类型查询
     */
    public function scopeByType($query, string $type)
    {
        $query->where('order_type', $type);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}