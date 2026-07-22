<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 批量操作确认模型
 * @property int    $id
 * @property int    $admin_id
 * @property string $batch_sn      批次号
 * @property string $action        操作类型
 * @property int    $target_count  目标数量
 * @property int    $processed_count 已处理数量
 * @property int    $status        0-待确认 1-执行中 2-完成 3-失败
 * @property string $detail        详情 JSON
 * @property string $confirm_time
 * @property string $create_time
 * @property string $update_time
 */
class BatchOperationConfirm extends Model
{
    protected $name = 'batch_operation_confirm';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_PENDING  = 0;
    const STATUS_RUNNING  = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_FAIL     = 3;

    /**
     * 详情获取器 - JSON 解码
     */
    public function getDetailAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setDetailAttr($value): string
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
     * 查询待确认
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 按管理员查询
     */
    public function scopeByAdmin($query, int $adminId)
    {
        $query->where('admin_id', $adminId);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}