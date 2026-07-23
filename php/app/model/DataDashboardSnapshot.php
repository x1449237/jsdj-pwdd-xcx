<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 数据大屏快照模型
 * @property int    $id
 * @property string $snapshot_type
 * @property array  $data_json
 * @property string $snapshot_time
 * @property string $create_time
 */
class DataDashboardSnapshot extends Model
{
    protected $name = 'data_dashboard_snapshot';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const SNAPSHOT_REALTIME = 'realtime';
    const SNAPSHOT_HOURLY = 'hourly';
    const SNAPSHOT_DAILY = 'daily';

    public function getDataJsonAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setDataJsonAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('snapshot_type', $type);
    }

    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('snapshot_time', [$start, $end]);
    }

    public function scopeLatest($query)
    {
        $query->order('snapshot_time', 'desc');
    }
}
