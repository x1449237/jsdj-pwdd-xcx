<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 管理员操作日志模型
 * @property int    $id
 * @property int    $admin_id
 * @property string $username
 * @property string $module
 * @property string $action
 * @property string $ip
 * @property string $device
 * @property array  $params_json
 * @property int    $result
 * @property string $create_time
 */
class AdminOperationLog extends Model
{
    protected $name = 'admin_operation_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    public function getParamsJsonAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setParamsJsonAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    public function scopeByAdmin($query, int $adminId)
    {
        $query->where('admin_id', $adminId);
    }

    public function scopeByModule($query, string $module)
    {
        $query->where('module', $module);
    }

    public function scopeByIp($query, string $ip)
    {
        $query->where('ip', $ip);
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
