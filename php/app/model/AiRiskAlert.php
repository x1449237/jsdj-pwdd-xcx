<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * AI风险预警模型
 * @property int    $id
 * @property string $alert_type
 * @property int    $user_id
 * @property string $risk_level
 * @property string $description
 * @property array  $data_json
 * @property int    $status
 * @property int    $handler_id
 * @property string $handle_time
 * @property string $create_time
 */
class AiRiskAlert extends Model
{
    protected $name = 'ai_risk_alert';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const STATUS_PENDING = 0;
    const STATUS_PROCESSING = 1;
    const STATUS_HANDLED = 2;
    const STATUS_IGNORED = 3;

    const RISK_LEVEL_LOW = 'low';
    const RISK_LEVEL_MEDIUM = 'medium';
    const RISK_LEVEL_HIGH = 'high';

    const ALERT_TYPE_HIGH_REFUND_RATE = 'high_refund_rate';
    const ALERT_TYPE_SAME_IP_REGIST = 'same_ip_regist';
    const ALERT_TYPE_LARGE_WITHDRAW = 'large_withdraw';
    const ALERT_TYPE_MIDNIGHT_ORDER = 'midnight_order';
    const ALERT_TYPE_FREQUENCY_ORDER = 'frequency_order';

    public function getDataJsonAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setDataJsonAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function handler()
    {
        return $this->belongsTo(Admin::class, 'handler_id', 'id');
    }

    public function scopeByAlertType($query, string $alertType)
    {
        $query->where('alert_type', $alertType);
    }

    public function scopeByRiskLevel($query, string $riskLevel)
    {
        $query->where('risk_level', $riskLevel);
    }

    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    public function scopeByUserId($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}
