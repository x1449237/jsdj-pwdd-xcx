<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 慢查询日志模型
 * @property int    $id
 * @property string $sql_text
 * @property int    $exec_time_ms
 * @property int    $rows_examined
 * @property string $db_name
 * @property string $create_time
 */
class SystemSlowQueryLog extends Model
{
    protected $name = 'system_slow_query_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    public function scopeByDbName($query, string $dbName)
    {
        $query->where('db_name', $dbName);
    }

    public function scopeSlowThan($query, int $ms)
    {
        $query->where('exec_time_ms', '>=', $ms);
    }

    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }

    public function scopeSlowest($query)
    {
        $query->order('exec_time_ms', 'desc');
    }
}
