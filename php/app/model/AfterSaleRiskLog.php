<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 售后风控命中日志模型
 * @property int    $id
 * @property int    $session_id        会话ID
 * @property int    $order_id          订单ID
 * @property int    $message_id        消息ID
 * @property int    $sender_id         发送者ID
 * @property string $hit_keywords      命中关键词(JSON)
 * @property string $content_summary   内容摘要
 * @property int    $is_handled        是否已处理
 * @property string $handle_time       处理时间
 * @property string $create_time
 */
class AfterSaleRiskLog extends Model
{
    protected $name = 'after_sale_risk_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    /**
     * 关联售后会话
     */
    public function session()
    {
        return $this->belongsTo(AfterSaleSession::class, 'session_id', 'id');
    }

    /**
     * 按会话查询
     */
    public function scopeBySession($query, int $sessionId)
    {
        $query->where('session_id', $sessionId);
    }

    /**
     * 按订单查询
     */
    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }

    /**
     * 查询未处理
     */
    public function scopeUnhandled($query)
    {
        $query->where('is_handled', 0);
    }
}