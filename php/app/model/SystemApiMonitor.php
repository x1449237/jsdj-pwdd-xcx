<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 第三方接口监控模型
 * @property int    $id
 * @property string $api_type
 * @property string $endpoint
 * @property int    $call_count
 * @property int    $success_count
 * @property int    $fail_count
 * @property int    $avg_time_ms
 * @property string $last_call_time
 * @property float  $alert_threshold
 * @property int    $status
 * @property string $create_time
 * @property string $update_time
 */
class SystemApiMonitor extends Model
{
    protected $name = 'system_api_monitor';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_NORMAL = 1;
    const STATUS_ABNORMAL = 0;

    const API_TYPE_LIVENESS = 'liveness';
    const API_TYPE_SMS = 'sms';
    const API_TYPE_OSS = 'oss';
    const API_TYPE_PROFIT_SHARE = 'profit_share';
    const API_TYPE_ASR = 'asr';
    const API_TYPE_OCR = 'ocr';

    public function getSuccessRateAttr(): float
    {
        if ($this->call_count == 0) {
            return 100.00;
        }
        return round(($this->success_count / $this->call_count) * 100, 2);
    }

    public function scopeByApiType($query, string $apiType)
    {
        $query->where('api_type', $apiType);
    }

    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    public function scopeAbnormal($query)
    {
        $query->where('status', self::STATUS_ABNORMAL);
    }
}
