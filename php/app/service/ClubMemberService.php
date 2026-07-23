<?php
declare(strict_types=1);

namespace app\service;

use app\model\ClubMember;
use app\model\UserVBadge;
use think\facade\Log;
use think\facade\Db;

class ClubMemberService
{
    public function addMember(int $clubId, int $userId, string $role = 'member'): array
    {
        $club = UserVBadge::find($clubId);
        if (!$club) {
            throw new \RuntimeException('俱乐部不存在');
        }
        if ($club->club_status !== UserVBadge::STATUS_ACTIVE) {
            throw new \RuntimeException('俱乐部未正常运营');
        }

        $exist = ClubMember::where('club_id', $clubId)
            ->where('user_id', $userId)
            ->find();
        if ($exist) {
            throw new \RuntimeException('该用户已是俱乐部成员');
        }

        Db::startTrans();
        try {
            $member = ClubMember::create([
                'club_id' => $clubId,
                'user_id' => $userId,
                'role'    => $role,
                'status'  => ClubMember::STATUS_NORMAL,
            ]);

            Db::commit();
            Log::info("俱乐部成员添加成功: club_id={$clubId}, user_id={$userId}, role={$role}");
            return $member->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function removeMember(int $clubId, int $userId, int $operatorId): bool
    {
        if (!ClubMember::checkPermission($clubId, $operatorId, 'member_audit')) {
            throw new \RuntimeException('无权限操作');
        }

        $member = ClubMember::where('club_id', $clubId)
            ->where('user_id', $userId)
            ->where('status', ClubMember::STATUS_NORMAL)
            ->find();

        if (!$member) {
            throw new \RuntimeException('成员不存在');
        }

        if ($member->role === ClubMember::ROLE_FOUNDER) {
            throw new \RuntimeException('不能移除创始人');
        }

        $member->status = ClubMember::STATUS_QUIT;
        $member->save();

        Log::info("俱乐部成员移除: club_id={$clubId}, user_id={$userId}, operator={$operatorId}");
        return true;
    }

    public function changeRole(int $clubId, int $userId, string $newRole, int $operatorId): bool
    {
        if (!ClubMember::checkPermission($clubId, $operatorId, 'member_audit')) {
            throw new \RuntimeException('无权限操作');
        }

        $operatorRole = ClubMember::getUserRole($clubId, $operatorId);
        if ($operatorRole !== ClubMember::ROLE_FOUNDER && $newRole === ClubMember::ROLE_FOUNDER) {
            throw new \RuntimeException('仅创始人可转让创始人身份');
        }

        $member = ClubMember::where('club_id', $clubId)
            ->where('user_id', $userId)
            ->where('status', ClubMember::STATUS_NORMAL)
            ->find();

        if (!$member) {
            throw new \RuntimeException('成员不存在');
        }

        $member->role = $newRole;
        $member->save();

        Log::info("俱乐部成员角色变更: club_id={$clubId}, user_id={$userId}, new_role={$newRole}");
        return true;
    }

    public function getMemberList(int $clubId, array $params = []): array
    {
        $page  = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 20;
        $role  = $params['role'] ?? '';
        $keyword = $params['keyword'] ?? '';

        $query = ClubMember::with(['user'])
            ->where('club_id', $clubId)
            ->where('status', ClubMember::STATUS_NORMAL)
            ->order('join_time', 'desc');

        if (!empty($role)) {
            $query->where('role', $role);
        }

        if (!empty($keyword)) {
            $query->whereHas('user', function ($q) use ($keyword) {
                $q->where('nickname', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['role_name'] = ClubMember::getRoleMap()[$item['role']] ?? $item['role'];
            unset($item['user']['password']);
            unset($item['user']['id_card']);
            unset($item['user']['phone_encrypted']);
        }

        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    public function getUserClubRole(int $userId): array
    {
        $members = ClubMember::with(['club'])
            ->where('user_id', $userId)
            ->where('status', ClubMember::STATUS_NORMAL)
            ->select()
            ->toArray();

        $result = [];
        foreach ($members as $member) {
            $result[] = [
                'club_id'   => $member['club_id'],
                'club_name' => $member['club']['club_name'] ?? '',
                'role'      => $member['role'],
                'role_name' => ClubMember::getRoleMap()[$member['role']] ?? $member['role'],
            ];
        }

        return $result;
    }

    public function joinApply(int $clubId, int $userId): array
    {
        $club = UserVBadge::find($clubId);
        if (!$club || $club->club_status !== UserVBadge::STATUS_ACTIVE) {
            throw new \RuntimeException('俱乐部不存在或未正常运营');
        }

        $exist = ClubMember::where('club_id', $clubId)
            ->where('user_id', $userId)
            ->find();
        if ($exist) {
            if ($exist->status == ClubMember::STATUS_PENDING) {
                throw new \RuntimeException('申请已提交，等待审核');
            }
            if ($exist->status == ClubMember::STATUS_NORMAL) {
                throw new \RuntimeException('已是俱乐部成员');
            }
        }

        $member = ClubMember::create([
            'club_id' => $clubId,
            'user_id' => $userId,
            'role'    => ClubMember::ROLE_MEMBER,
            'status'  => ClubMember::STATUS_PENDING,
        ]);

        Log::info("俱乐部加入申请: club_id={$clubId}, user_id={$userId}");
        return $member->toArray();
    }

    public function auditJoin(int $clubId, int $userId, int $auditorId, bool $pass): bool
    {
        if (!ClubMember::checkPermission($clubId, $auditorId, 'member_audit')) {
            throw new \RuntimeException('无权限审核');
        }

        $member = ClubMember::where('club_id', $clubId)
            ->where('user_id', $userId)
            ->where('status', ClubMember::STATUS_PENDING)
            ->find();

        if (!$member) {
            throw new \RuntimeException('申请不存在');
        }

        if ($pass) {
            $member->status = ClubMember::STATUS_NORMAL;
        } else {
            $member->status = ClubMember::STATUS_QUIT;
        }
        $member->save();

        Log::info("俱乐部加入审核: club_id={$clubId}, user_id={$userId}, pass=" . ($pass ? '1' : '0'));
        return true;
    }

    public function getMemberCount(int $clubId): int
    {
        return ClubMember::where('club_id', $clubId)
            ->where('status', ClubMember::STATUS_NORMAL)
            ->count();
    }
}
