<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 定时任务日志模型
 * @property int    $id
 * @property string $job_name      任务名称
 * @property string $job_class     任务类
 * @property string $params        参数 JSON
 * @property int    $status        0-执行中 1-成功 2-失败
 * @property string $result        执行结果
 * @property string $error_message
 * @property float  $exec_time     执行耗时（秒）
 * @property string $start_time
 * @property string $end_time
 * @property string $create_time
 */
class CronJobLog extends Model
{
    protected $name = 'cron_job_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    // 状态
    const STATUS_RUNNING = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_FAIL    = 2;

    /**
     * 参数获取器 - JSON 解码
     */
    public function getParamsAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setParamsAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 按任务名称查询
     */
    public function scopeByJobName($query, string $name)
    {
        $query->where('job_name', $name);
    }

    /**
     * 查询失败记录
     */
    public function scopeFailed($query)
    {
        $query->where('status', self::STATUS_FAIL);
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