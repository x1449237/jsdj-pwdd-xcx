<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 批量操作日志模型
 * @property int    $id
 * @property int    $admin_id
 * @property string $type
 * @property int    $total_count
 * @property int    $success_count
 * @property int    $fail_count
 * @property int    $amount
 * @property int    $status
 * @property string $confirm_method
 * @property string $create_time
 */
class BatchOperationLog extends Model
{
    protected $name = 'batch_operation_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const STATUS_PROCESSING = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_PARTIAL_FAIL = 2;
    const STATUS_ALL_FAIL = 3;

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    public function scopeByType($query, string $type)
    {
        $query->where('type', $type);
    }

    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
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
