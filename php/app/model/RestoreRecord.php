<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 恢复记录模型
 * @property int    $id
 * @property int    $admin_id
 * @property int    $backup_id
 * @property int    $status        0-处理中 1-成功 2-失败
 * @property string $error_message
 * @property string $create_time
 * @property string $update_time
 */
class RestoreRecord extends Model
{
    protected $name = 'restore_record';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PROCESS = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_FAIL    = 2;

    /**
     * 关联管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    /**
     * 关联备份记录
     */
    public function backup()
    {
        return $this->belongsTo(BackupRecord::class, 'backup_id', 'id');
    }

    /**
     * 查询成功
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
}