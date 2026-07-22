<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 管理员密码历史模型
 * @property int    $id
 * @property int    $admin_id
 * @property string $password_hash
 * @property string $create_time
 */
class AdminPasswordHistory extends Model
{
    protected $name = 'admin_password_history';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    protected $hidden = ['password_hash'];

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
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }

    /**
     * 验证密码是否存在于历史记录中
     */
    public static function isInHistory(int $adminId, string $password, int $limit = 5): bool
    {
        $histories = self::where('admin_id', $adminId)
            ->order('create_time', 'desc')
            ->limit($limit)
            ->select();

        foreach ($histories as $history) {
            if (bcrypt_verify($password, $history->getData('password_hash'))) {
                return true;
            }
        }
        return false;
    }
}