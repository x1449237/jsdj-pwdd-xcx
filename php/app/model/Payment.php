<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 支付记录模型
 * @property int    $id
 * @property int    $order_id
 * @property int    $user_id
 * @property string $payment_sn     支付流水号
 * @property string $amount         支付金额
 * @property int    $payment_method 支付方式 1-微信 2-支付宝 3-余额
 * @property int    $status         0-失败 1-成功 2-处理中
 * @property string $transaction_id 第三方交易号
 * @property string $pay_time
 * @property string $callback_data  回调数据 JSON
 * @property string $create_time
 * @property string $update_time
 */
class Payment extends Model
{
    protected $name = 'payment';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 支付方式
    const METHOD_WECHAT  = 1;
    const METHOD_ALIPAY  = 2;
    const METHOD_BALANCE = 3;

    // 状态
    const STATUS_FAIL     = 0;
    const STATUS_SUCCESS  = 1;
    const STATUS_PROCESS  = 2;

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
     * 回调数据获取器 - JSON 解码
     */
    public function getCallbackDataAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    /**
     * 回调数据修改器 - JSON 编码
     */
    public function setCallbackDataAttr($value): string
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
     * 按订单查询
     */
    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }

    /**
     * 按支付流水号查询
     */
    public function scopeByPaymentSn($query, string $paymentSn)
    {
        $query->where('payment_sn', $paymentSn);
    }

    /**
     * 查询成功记录
     */
    public function scopeSuccess($query)
    {
        $query->where('status', self::STATUS_SUCCESS);
    }
}