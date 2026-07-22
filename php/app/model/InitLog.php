<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 初始化日志模型
 * @property int    $id
 * @property int    $admin_id
 * @property string $module       初始化的模块名
 * @property string $version      初始化版本
 * @property string $result       初始化结果
 * @property string $detail       详细信息 JSON
 * @property string $create_time
 */
class InitLog extends Model
{
    protected $name = 'init_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    /**
     * 详情获取器 - JSON 解码
     */
    public function getDetailAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    /**
     * 详情修改器 - JSON 编码
     */
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
     * 按模块查询
     */
    public function scopeByModule($query, string $module)
    {
        $query->where('module', $module);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}