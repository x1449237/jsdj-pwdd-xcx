<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 俱乐部入驻/V标身份模型
 */
class UserVBadge extends Model
{
    protected $name = 'user_v_badge';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 审核状态
    const AUDIT_PENDING     = 0; // 待审核
    const AUDIT_PASSED      = 1; // 审核通过
    const AUDIT_REJECTED    = 2; // 直接驳回
    const AUDIT_NEED_MORE   = 3; // 补充资料

    // 俱乐部类型
    const TYPE_ENTERPRISE = 'blue_v';   // 企业级俱乐部
    const TYPE_PERSONAL   = 'green_v';  // 个人级俱乐部

    // 俱乐部状态
    const STATUS_PENDING   = 'pending';   // 审核中
    const STATUS_ACTIVE    = 'active';    // 正常运营
    const STATUS_FROZEN    = 'frozen';    // 冻结
    const STATUS_CLOSED    = 'closed';    // 停业
    const STATUS_CANCELLED = 'cancelled'; // 注销

    // 保证金状态
    const DEPOSIT_UNPAID = 0; // 未缴
    const DEPOSIT_PAID   = 1; // 已缴
    const DEPOSIT_REFUND = 2; // 已退

    // 活体认证状态
    const LIVENESS_PENDING = 0; // 未认证
    const LIVENESS_PASSED  = 1; // 通过
    const LIVENESS_FAILED  = 2; // 失败

    // 对公打款验证状态
    const VERIFY_PENDING  = 0; // 未验证
    const VERIFY_WAITING  = 1; // 待确认
    const VERIFY_PASSED   = 2; // 通过
    const VERIFY_FAILED   = 3; // 失败

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeActive($query)
    {
        $query->where('is_active', 1)
              ->where('audit_status', self::AUDIT_PASSED)
              ->where('club_status', self::STATUS_ACTIVE);
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

    public static function getStatusMap(): array
    {
        return [
            self::STATUS_PENDING   => '审核中',
            self::STATUS_ACTIVE    => '正常运营',
            self::STATUS_FROZEN    => '冻结',
            self::STATUS_CLOSED    => '停业',
            self::STATUS_CANCELLED => '注销',
        ];
    }
}