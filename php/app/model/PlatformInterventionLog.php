<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 平台介入记录模型
 * @property int    $id
 * @property int    $session_id            会话ID
 * @property string $session_type          会话类型
 * @property int    $order_id              订单ID
 * @property int    $trigger_type          触发类型 1=关键词 2=玩家申请 3=客服申请
 * @property int    $trigger_user_id       触发用户ID
 * @property string $trigger_detail        触发详情(JSON)
 * @property int    $intervene_account_id  介入平台账号ID
 * @property string $intervene_time        介入时间
 * @property string $resolve_result        解决结果
 * @property string $resolve_action        解决措施
 * @property string $resolve_time          解决时间
 * @property int    $resolve_operator_id   解决操作人ID
 * @property int    $status                状态 1=介入中 2=已处理 3=已关闭
 * @property string $create_time
 * @property string $update_time
 */
class PlatformInterventionLog extends Model
{
    protected $name = 'platform_intervention_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TRIGGER_KEYWORD = 1;
    const TRIGGER_PLAYER  = 2;
    const TRIGGER_CLUB    = 3;

    const STATUS_INTERVENING = 1;
    const STATUS_HANDLED     = 2;
    const STATUS_CLOSED      = 3;

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
     * 查询进行中
     */
    public function scopeActive($query)
    {
        $query->where('status', self::STATUS_INTERVENING);
    }
}