<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 用户V标/俱乐部入驻模型
 * @property int    $id
 * @property int    $user_id          用户ID（俱乐部创始人）
 * @property string $badge_type       blue_v企业级 / green_v个人级
 * @property string $badge_display    显示类型
 * @property string $club_name        俱乐部名称
 * @property int    $audit_status     0待审核 1通过 2驳回
 * @property string $audit_time       审核通过时间
 * @property int    $auditor_id       审核人ID
 * @property int    $is_active        是否点亮
 * @property string $create_time
 * @property string $update_time
 */
class UserVBadge extends Model
{
    protected $name = 'user_v_badge';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 审核状态
    const AUDIT_PENDING  = 0;
    const AUDIT_PASSED   = 1;
    const AUDIT_REJECTED = 2;

    // 俱乐部类型
    const TYPE_ENTERPRISE = 'blue_v';   // 企业级俱乐部
    const TYPE_PERSONAL   = 'green_v';  // 个人级俱乐部

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeActive($query)
    {
        $query->where('is_active', 1)->where('audit_status', self::AUDIT_PASSED);
    }

    public function scopePending($query)
    {
        $query->where('audit_status', self::AUDIT_PENDING);
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public static function getTypeMap(): array
    {
        return [
            self::TYPE_ENTERPRISE => '企业级俱乐部',
            self::TYPE_PERSONAL   => '个人级俱乐部',
        ];
    }
}