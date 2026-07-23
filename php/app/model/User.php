<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 用户模型
 * @property int    $id
 * @property string $openid
 * @property string $unionid
 * @property string $nickname
 * @property string $avatar
 * @property string $phone
 * @property string $real_name
 * @property string $id_card
 * @property int    $gender        0-未知 1-男 2-女
 * @property string $birthday
 * @property int    $is_real_name  0-未认证 1-已认证
 * @property int    $is_guardian   0-无需监护人 1-已绑定监护人
 * @property int    $level         用户等级
 * @property string $balance       余额
 * @property string $frozen_balance 冻结余额
 * @property int    $points        积分
 * @property int    $status        0-禁用 1-正常
 * @property int    $user_type     0-普通用户 1-VIP 2-打手
 * @property string $last_login_ip
 * @property string $last_login_time
 * @property string $create_time
 * @property string $update_time
 * @property string $delete_time
 */
class User extends Model
{
    protected $name = 'user';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['openid', 'unionid', 'id_card', 'delete_time'];

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    // 性别常量
    const GENDER_UNKNOWN = 0;
    const GENDER_MALE    = 1;
    const GENDER_FEMALE  = 2;

    // 用户类型
    const TYPE_NORMAL = 0;
    const TYPE_VIP    = 1;
    const TYPE_PLAYER = 2;

    /**
     * 手机号获取器 - 脱敏
     */
    public function getPhoneAttr($value): string
    {
        return mask_sensitive($value, 'phone');
    }

    /**
     * 真实姓名获取器 - 脱敏
     */
    public function getRealNameAttr($value): string
    {
        return mask_sensitive($value, 'name');
    }

    /**
     * 身份证号获取器 - 脱敏
     */
    public function getIdCardAttr($value): string
    {
        return mask_sensitive($value, 'id_card');
    }

    /**
     * 关联实名认证记录
     */
    public function realVerifyLogs()
    {
        return $this->hasMany(RealVerifyLog::class, 'user_id', 'id');
    }

    /**
     * 关联监护人验证
     */
    public function guardianVerifys()
    {
        return $this->hasMany(GuardianVerify::class, 'user_id', 'id');
    }

    /**
     * 关联打手服务配置
     */
    public function playerServices()
    {
        return $this->hasMany(PlayerService::class, 'user_id', 'id');
    }

    /**
     * 关联邀请绑定记录
     */
    public function inviteBindLogs()
    {
        return $this->hasMany(InviteBindLog::class, 'user_id', 'id');
    }

    /**
     * 关联订单
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    /**
     * 关联评价
     */
    public function evaluations()
    {
        return $this->hasMany(Evaluation::class, 'user_id', 'id');
    }

    /**
     * 关联提现记录
     */
    public function withdraws()
    {
        return $this->hasMany(Withdraw::class, 'user_id', 'id');
    }

    /**
     * 关联聊天会话 (作为发起方)
     */
    public function chatSessions()
    {
        return $this->hasMany(ChatSession::class, 'user_id', 'id');
    }

    /**
     * 关联聊天消息
     */
    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class, 'user_id', 'id');
    }

    /**
     * 关联消息通知
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id', 'id');
    }

    /**
     * 关联风险用户记录
     */
    public function riskUser()
    {
        return $this->hasOne(RiskUser::class, 'user_id', 'id');
    }

    /**
     * 关联加入我们记录
     */
    public function joinUsLogs()
    {
        return $this->hasMany(JoinUsLog::class, 'user_id', 'id');
    }

    /**
     * 关联 WebSocket 连接
     */
    public function wsConnections()
    {
        return $this->hasMany(WsConnection::class, 'user_id', 'id');
    }

    /**
     * 关联手机号申诉
     */
    public function phoneAppeals()
    {
        return $this->hasMany(PhoneAppeal::class, 'user_id', 'id');
    }

    /**
     * 关联分销佣金
     */
    public function commissions()
    {
        return $this->hasMany(DistributorCommission::class, 'user_id', 'id');
    }

    /**
     * 关联打赏
     */
    public function rewards()
    {
        return $this->hasMany(Reward::class, 'user_id', 'id');
    }

    // ===================== Scope =====================

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按 OpenID 查询
     */
    public function scopeByOpenid($query, string $openid)
    {
        $query->where('openid', $openid);
    }

    /**
     * 按手机号查询
     */
    public function scopeByPhone($query, string $phone)
    {
        $query->where('phone', $phone);
    }

    /**
     * 已实名认证
     */
    public function scopeRealNamed($query)
    {
        $query->where('is_real_name', 1);
    }

    /**
     * 按用户类型查询
     */
    public function scopeByType($query, int $type)
    {
        $query->where('user_type', $type);
    }

    /**
     * 按等级查询
     */
    public function scopeByLevel($query, int $level)
    {
        $query->where('level', $level);
    }


    /**
     * 按等级范围查询
     */
    public function scopeLevelBetween($query, int $min, int $max)
    {
        $query->whereBetween('level', [$min, $max]);
    }

    /**
     * 按注册时间范围查询
     */
    public function scopeCreatedBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    /**
     * 关联家长监护绑定（作为孩子）
     */
    public function guardianBinds()
    {
        return $this->hasMany(ParentGuardianBind::class, 'child_user_id', 'id');
    }

    /**
     * 检查用户是否可用
     */
    public function isEnabled(): bool
    {
        return $this->getData('status') == self::STATUS_ENABLED;
    }

    /**
     * 是否未成年人
     */
    public function isMinor(): bool
    {
        return $this->getData('is_minor') == 1;
    }

    /**
     * 是否已实名认证
     */
    public function isRealVerified(): bool
    {
        return $this->getData('is_real_verified') == 1;
    }

    /**
     * 查询未成年用户
     */
    public function scopeMinor($query)
    {
        $query->where('is_minor', 1);
    }

    /**
     * 查询已实名认证用户
     */
    public function scopeRealVerified($query)
    {
        $query->where('is_real_verified', 1);
    }
}