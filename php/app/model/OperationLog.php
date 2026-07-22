<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 操作日志模型
 * @property int    $id
 * @property int    $admin_id
 * @property string $action        操作动作
 * @property string $content       操作内容
 * @property string $ip
 * @property string $url
 * @property string $method        HTTP方法
 * @property string $user_agent
 * @property string $request_data  请求数据 JSON
 * @property string $create_time
 */
class OperationLog extends Model
{
    protected $name = 'operation_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    /**
     * 请求数据获取器 - JSON 解码
     */
    public function getRequestDataAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setRequestDataAttr($value): string
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
     * 按操作查询
     */
    public function scopeByAction($query, string $action)
    {
        $query->where('action', $action);
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