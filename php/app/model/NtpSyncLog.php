<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * NTP 同步日志模型
 * @property int    $id
 * @property string $ntp_server    NTP服务器地址
 * @property int    $offset        时间偏移（毫秒）
 * @property int    $delay         网络延迟（毫秒）
 * @property int    $status        0-失败 1-成功
 * @property string $error_message
 * @property string $create_time
 */
class NtpSyncLog extends Model
{
    protected $name = 'ntp_sync_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const STATUS_FAIL    = 0;
    const STATUS_SUCCESS = 1;

    /**
     * 查询成功记录
     */
    public function scopeSuccess($query)
    {
        $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }

    /**
     * 按时间范围查询
     */
    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }
}