<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * UP主认证等级
 * @property int    $id
 * @property int    $user_id          用户ID
 * @property int    $club_id          所属俱乐部ID（user_v_badge.id）
 * @property int    $tier             等级 1青铜 2进阶 3高阶 4精英 5巨匠 6至尊
 * @property string $tier_name        等级名称
 * @property int    $fan_count        粉丝数
 * @property int    $fan_count_verified 核验粉丝数
 * @property string $platform         主平台
 * @property string $platform_account_id 平台账号ID
 * @property string $platform_account_url 平台主页链接
 * @property string $screenshot_urls  截图凭证(JSON)
 * @property string $video_url        录屏视频URL
 * @property int    $audit_status     0待审核 1通过 2驳回
 * @property string $audit_remark     审核备注
 * @property string $audit_time       审核时间
 * @property int    $auditor_id       审核人ID
 * @property int    $is_active        是否点亮
 * @property string $badge_text       徽标文字
 * @property string $badge_color      徽标底色
 * @property string $badge_size       徽标尺寸 small/large
 * @property string $create_time
 * @property string $update_time
 */
class UpMasterCertification extends Model
{
    protected $name = 'up_master_certification';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 等级常量
    const TIER_BRONZE   = 1; // 青铜UP主
    const TIER_ADVANCED = 2; // 进阶UP主
    const TIER_HIGH     = 3; // 高阶UP主
    const TIER_ELITE    = 4; // 精英UP主
    const TIER_MASTER   = 5; // 巨匠UP主
    const TIER_SUPREME  = 6; // 至尊UP主

    // 审核状态
    const AUDIT_PENDING  = 0;
    const AUDIT_PASSED   = 1;
    const AUDIT_REJECTED = 2;

    // 等级名称映射
    public static function getTierMap(): array
    {
        return [
            self::TIER_BRONZE   => '青铜UP主',
            self::TIER_ADVANCED => '进阶UP主',
            self::TIER_HIGH     => '高阶UP主',
            self::TIER_ELITE    => '精英UP主',
            self::TIER_MASTER   => '巨匠UP主',
            self::TIER_SUPREME  => '至尊UP主',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function scopeActive($query)
    {
        $query->where('is_active', 1)->where('audit_status', self::AUDIT_PASSED);
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeByTier($query, int $tier)
    {
        $query->where('tier', $tier);
    }

    public function scopePending($query)
    {
        $query->where('audit_status', self::AUDIT_PENDING);
    }
}