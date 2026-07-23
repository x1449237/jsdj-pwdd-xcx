<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubMember extends Model
{
    protected $name = 'club_member';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const ROLE_FOUNDER = 'founder';
    const ROLE_MANAGER = 'manager';
    const ROLE_MEMBER  = 'member';

    const STATUS_NORMAL  = 1;
    const STATUS_QUIT    = 0;
    const STATUS_PENDING = 2;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeFounder($query)
    {
        $query->where('role', self::ROLE_FOUNDER);
    }

    public function scopeManager($query)
    {
        $query->where('role', 'in', [self::ROLE_FOUNDER, self::ROLE_MANAGER]);
    }

    public function scopeNormal($query)
    {
        $query->where('status', self::STATUS_NORMAL);
    }

    public static function getRoleMap(): array
    {
        return [
            self::ROLE_FOUNDER => '创始人',
            self::ROLE_MANAGER => '管理员',
            self::ROLE_MEMBER  => '普通成员',
        ];
    }

    public static function checkPermission(int $clubId, int $userId, string $permission): bool
    {
        $member = self::where('club_id', $clubId)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_NORMAL)
            ->find();

        if (!$member) {
            return false;
        }

        $role = $member->role;

        $permissionMap = [
            'founder' => ['*'],
            'manager' => [
                'member_audit', 'announcement_publish',
                'internal_order_assign', 'internal_order_publish',
                'dynamic_audit', 'coupon_manage'
            ],
            'member' => [
                'internal_order_accept', 'dynamic_publish'
            ],
        ];

        $perms = $permissionMap[$role] ?? [];
        return in_array('*', $perms) || in_array($permission, $perms);
    }

    public static function getUserRole(int $clubId, int $userId): ?string
    {
        $member = self::where('club_id', $clubId)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_NORMAL)
            ->find();

        return $member ? $member->role : null;
    }
}
