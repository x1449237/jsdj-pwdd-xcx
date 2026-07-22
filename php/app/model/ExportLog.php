<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 导出日志模型
 * @property int    $id
 * @property int    $admin_id
 * @property string $export_type   导出类型
 * @property string $file_name     文件名
 * @property string $file_path     文件路径
 * @property int    $total_count   总条数
 * @property int    $status        0-处理中 1-成功 2-失败
 * @property string $params        查询参数 JSON
 * @property string $create_time
 * @property string $update_time
 */
class ExportLog extends Model
{
    protected $name = 'export_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_PROCESS = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_FAIL    = 2;

    /**
     * 查询参数获取器 - JSON 解码
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
     * 关联管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    /**
     * 按管理员查询
     */
    public function scopeByAdmin($query, int $adminId)
    {
        $query->where('admin_id', $adminId);
    }

    /**
     * 按导出类型查询
     */
    public function scopeByType($query, string $type)
    {
        $query->where('export_type', $type);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}