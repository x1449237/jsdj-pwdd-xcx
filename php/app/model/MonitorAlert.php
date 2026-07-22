<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 监控告警模型
 * @property int    $id
 * @property string $alert_type    告警类型
 * @property string $alert_level   info/warning/critical
 * @property string $title         告警标题
 * @property string $content       告警内容
 * @property string $target        告警目标
 * @property string $metric_value  指标值
 * @property string $threshold     阈值
 * @property int    $status        0-未处理 1-已处理 2-已忽略
 * @property int    $handler_id    处理人
 * @property string $handle_time
 * @property string $create_time
 * @property string $update_time
 */
class MonitorAlert extends Model
{
    protected $name = 'monitor_alert';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 告警级别
    const LEVEL_INFO     = 'info';
    const LEVEL_WARNING  = 'warning';
    const LEVEL_CRITICAL = 'critical';

    // 状态
    const STATUS_UNPROCESSED = 0;
    const STATUS_PROCESSED   = 1;
    const STATUS_IGNORED     = 2;

    /**
     * 关联处理人
     */
    public function handler()
    {
        return $this->belongsTo(Admin::class, 'handler_id', 'id');
    }

    /**
     * 查询未处理
     */
    public function scopeUnprocessed($query)
    {
        $query->where('status', self::STATUS_UNPROCESSED);
    }

    /**
     * 按告警级别查询
     */
    public function scopeByLevel($query, string $level)
    {
        $query->where('alert_level', $level);
    }

    /**
     * 按告警类型查询
     */
    public function scopeByType($query, string $type)
    {
        $query->where('alert_type', $type);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}