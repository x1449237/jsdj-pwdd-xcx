<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 平台官方账号模型
 * @property int    $id
 * @property string $account_no       账号编号
 * @property string $nickname         昵称
 * @property string $avatar           头像
 * @property int    $v_badge          大金V标识 1=大金V
 * @property string $v_badge_display  V标展示 golden_v/blue_v/green_v
 * @property int    $is_system        是否系统账号
 * @property int    $status           状态 1=正常 0=禁用
 * @property int    $creator_id       创建者ID
 * @property string $create_time
 * @property string $update_time
 */
class PlatformOfficialAccount extends Model
{
    protected $name = 'platform_official_account';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_NORMAL   = 1;
    const STATUS_DISABLED = 0;

    const V_BADGE_GOLDEN = 1;

    /**
     * 关联创建者（Admin）
     */
    public function creator()
    {
        return $this->belongsTo(Admin::class, 'creator_id', 'id');
    }

    /**
     * 查询正常状态
     */
    public function scopeActive($query)
    {
        $query->where('status', self::STATUS_NORMAL);
    }

    /**
     * 按账号编号查询
     */
    public function scopeByAccountNo($query, string $accountNo)
    {
        $query->where('account_no', $accountNo);
    }
}