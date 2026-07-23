<?php
declare(strict_types=1);

namespace app\service;

use app\model\ClubInternalOrder;
use app\model\ClubCoupon;
use app\model\ClubCouponUser;
use app\model\ClubDynamic;
use app\model\ClubBranch;
use app\model\ClubDailyStat;
use app\model\ClubDepositTier;
use app\model\ClubAnnouncement;
use app\model\ClubMember;
use app\model\UserVBadge;
use think\facade\Log;
use think\facade\Db;

class ClubOperationService
{
    // ========== 内部订单 ==========

    public function publishInternalOrder(int $clubId, int $publisherId, array $data): array
    {
        if (!ClubMember::checkPermission($clubId, $publisherId, 'internal_order_publish')) {
            throw new \RuntimeException('无权限发布内部订单');
        }

        $orderNo = ClubInternalOrder::generateOrderNo($clubId);

        $order = ClubInternalOrder::create([
            'club_id'        => $clubId,
            'order_no'       => $orderNo,
            'title'          => $data['title'] ?? '',
            'reward'         => (int) ($data['reward'] ?? 0),
            'status'         => ClubInternalOrder::STATUS_PENDING,
        ]);

        Log::info("俱乐部内部订单发布: club_id={$clubId}, order_no={$orderNo}");
        return $order->toArray();
    }

    public function acceptInternalOrder(int $orderId, int $userId): array
    {
        $order = ClubInternalOrder::find($orderId);
        if (!$order) {
            throw new \RuntimeException('订单不存在');
        }
        if ($order->status != ClubInternalOrder::STATUS_PENDING) {
            throw new \RuntimeException('订单状态不支持接单');
        }
        if (!ClubMember::checkPermission($order->club_id, $userId, 'internal_order_accept')) {
            throw new \RuntimeException('仅俱乐部成员可接单');
        }

        $order->player_user_id = $userId;
        $order->status = ClubInternalOrder::STATUS_ACCEPTED;
        $order->save();

        Log::info("内部订单接单: order_id={$orderId}, user_id={$userId}");
        return $order->toArray();
    }

    public function getInternalOrderList(int $clubId, array $params = []): array
    {
        $page   = $params['page'] ?? 1;
        $limit  = $params['limit'] ?? 20;
        $status = $params['status'] ?? null;

        $query = ClubInternalOrder::with(['player'])
            ->where('club_id', $clubId)
            ->order('create_time', 'desc');

        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $statusMap = ClubInternalOrder::getStatusMap();
        foreach ($list as &$item) {
            $item['status_name'] = $statusMap[$item['status']] ?? '';
            $item['reward_yuan'] = bcdiv((string) $item['reward'], '100', 2);
        }

        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    // ========== 优惠券 ==========

    public function createCoupon(int $clubId, int $operatorId, array $data): array
    {
        if (!ClubMember::checkPermission($clubId, $operatorId, 'coupon_manage')) {
            throw new \RuntimeException('无权限管理优惠券');
        }

        $coupon = ClubCoupon::create([
            'club_id'     => $clubId,
            'name'        => $data['name'] ?? '',
            'type'        => $data['type'] ?? 'discount',
            'value'       => (int) ($data['value'] ?? 0),
            'min_amount'  => (int) ($data['min_amount'] ?? 0),
            'total_count' => (int) ($data['total_count'] ?? 0),
            'used_count'  => 0,
            'start_time'  => $data['start_time'] ?? null,
            'end_time'    => $data['end_time'] ?? null,
            'status'      => ClubCoupon::STATUS_ENABLED,
        ]);

        Log::info("俱乐部优惠券创建: club_id={$clubId}, coupon_id={$coupon->id}");
        return $coupon->toArray();
    }

    public function receiveCoupon(int $couponId, int $userId): array
    {
        $coupon = ClubCoupon::find($couponId);
        if (!$coupon || $coupon->status != ClubCoupon::STATUS_ENABLED) {
            throw new \RuntimeException('优惠券不可用');
        }

        if ($coupon->total_count > 0 && $coupon->used_count >= $coupon->total_count) {
            throw new \RuntimeException('优惠券已领完');
        }

        $exist = ClubCouponUser::where('coupon_id', $couponId)
            ->where('user_id', $userId)
            ->find();
        if ($exist) {
            throw new \RuntimeException('您已领取过该优惠券');
        }

        Db::startTrans();
        try {
            ClubCouponUser::create([
                'coupon_id' => $couponId,
                'club_id'   => $coupon->club_id,
                'user_id'   => $userId,
                'status'    => ClubCouponUser::STATUS_UNUSED,
            ]);

            $coupon->used_count = Db::raw('used_count + 1');
            $coupon->save();

            Db::commit();
            Log::info("优惠券领取: coupon_id={$couponId}, user_id={$userId}");
            return ['success' => true];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function getCouponList(int $clubId, array $params = []): array
    {
        $page  = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 20;
        $type  = $params['type'] ?? '';

        $query = ClubCoupon::where('club_id', $clubId)
            ->order('create_time', 'desc');

        if (!empty($type)) {
            $query->where('type', $type);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $typeMap = ClubCoupon::getTypeMap();
        foreach ($list as &$item) {
            $item['type_name'] = $typeMap[$item['type']] ?? $item['type'];
            $item['value_yuan'] = bcdiv((string) $item['value'], '100', 2);
            $item['min_amount_yuan'] = bcdiv((string) $item['min_amount'], '100', 2);
        }

        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    public function getUserCoupons(int $userId, int $clubId = 0): array
    {
        $query = ClubCouponUser::with(['coupon'])
            ->where('user_id', $userId)
            ->order('receive_time', 'desc');

        if ($clubId > 0) {
            $query->where('club_id', $clubId);
        }

        $list = $query->select()->toArray();

        foreach ($list as &$item) {
            if (isset($item['coupon'])) {
                $item['coupon']['value_yuan'] = bcdiv((string) $item['coupon']['value'], '100', 2);
                $item['coupon']['min_amount_yuan'] = bcdiv((string) $item['coupon']['min_amount'], '100', 2);
            }
        }

        return $list;
    }

    // ========== 动态/战绩 ==========

    public function publishDynamic(int $clubId, int $userId, array $data): array
    {
        if (!ClubMember::checkPermission($clubId, $userId, 'dynamic_publish')) {
            throw new \RuntimeException('仅俱乐部成员可发布动态');
        }

        $dynamic = ClubDynamic::create([
            'club_id'        => $clubId,
            'player_user_id' => $userId,
            'type'           => $data['type'] ?? 'dynamic',
            'title'          => $data['title'] ?? '',
            'content'        => $data['content'] ?? '',
            'images_json'    => !empty($data['images']) ? json_encode($data['images']) : null,
            'video_url'      => $data['video_url'] ?? '',
            'status'         => ClubDynamic::STATUS_PENDING,
        ]);

        Log::info("俱乐部动态发布: club_id={$clubId}, dynamic_id={$dynamic->id}");
        return $dynamic->toArray();
    }

    public function getDynamicList(int $clubId, array $params = []): array
    {
        $page   = $params['page'] ?? 1;
        $limit  = $params['limit'] ?? 20;
        $type   = $params['type'] ?? '';
        $status = $params['status'] ?? ClubDynamic::STATUS_APPROVED;

        $query = ClubDynamic::with(['player'])
            ->where('club_id', $clubId)
            ->order('create_time', 'desc');

        if (!empty($type)) {
            $query->where('type', $type);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $typeMap = ClubDynamic::getTypeMap();
        foreach ($list as &$item) {
            $item['type_name'] = $typeMap[$item['type']] ?? $item['type'];
            $item['images'] = !empty($item['images_json']) ? json_decode($item['images_json'], true) : [];
            unset($item['images_json']);
            if (isset($item['player'])) {
                unset($item['player']['password']);
                unset($item['player']['id_card']);
            }
        }

        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    public function auditDynamic(int $dynamicId, int $auditorId, bool $pass): bool
    {
        $dynamic = ClubDynamic::find($dynamicId);
        if (!$dynamic) {
            throw new \RuntimeException('动态不存在');
        }

        if (!ClubMember::checkPermission($dynamic->club_id, $auditorId, 'dynamic_audit')) {
            throw new \RuntimeException('无权限审核');
        }

        $dynamic->status = $pass ? ClubDynamic::STATUS_APPROVED : ClubDynamic::STATUS_REJECTED;
        $dynamic->save();

        Log::info("动态审核: dynamic_id={$dynamicId}, pass=" . ($pass ? '1' : '0'));
        return true;
    }

    // ========== 分店/分区 ==========

    public function getBranchList(int $clubId): array
    {
        $list = ClubBranch::with(['manager'])
            ->where('club_id', $clubId)
            ->where('status', ClubBranch::STATUS_ACTIVE)
            ->order('create_time', 'desc')
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            if (isset($item['manager'])) {
                unset($item['manager']['password']);
                unset($item['manager']['id_card']);
            }
        }

        return $list;
    }

    // ========== 统计数据 ==========

    public function getDashboardData(int $clubId): array
    {
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');

        $todayStat = ClubDailyStat::where('club_id', $clubId)
            ->where('stat_date', $today)
            ->find();

        $monthStats = ClubDailyStat::where('club_id', $clubId)
            ->where('stat_date', '>=', $monthStart)
            ->select();

        $monthRevenue = 0;
        $monthOrders = 0;
        $trend = [];

        foreach ($monthStats as $stat) {
            $monthRevenue += $stat->total_revenue;
            $monthOrders += $stat->order_count;
            $trend[] = [
                'date'         => $stat->stat_date,
                'order_count'  => $stat->order_count,
                'revenue'      => $stat->total_revenue,
                'revenue_yuan' => bcdiv((string) $stat->total_revenue, '100', 2),
            ];
        }

        $memberCount = ClubMember::where('club_id', $clubId)
            ->where('status', ClubMember::STATUS_NORMAL)
            ->count();

        $pendingOrderCount = ClubInternalOrder::where('club_id', $clubId)
            ->where('status', ClubInternalOrder::STATUS_PENDING)
            ->count();

        return [
            'today' => [
                'order_count'  => $todayStat ? $todayStat->order_count : 0,
                'revenue'      => $todayStat ? $todayStat->total_revenue : 0,
                'revenue_yuan' => $todayStat ? bcdiv((string) $todayStat->total_revenue, '100', 2) : '0.00',
            ],
            'month' => [
                'order_count'  => $monthOrders,
                'revenue'      => $monthRevenue,
                'revenue_yuan' => bcdiv((string) $monthRevenue, '100', 2),
            ],
            'member_count'        => $memberCount,
            'pending_order_count' => $pendingOrderCount,
            'trend'               => $trend,
        ];
    }

    public function getTrendData(int $clubId, int $days = 7): array
    {
        $endDate   = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $stats = ClubDailyStat::where('club_id', $clubId)
            ->where('stat_date', '>=', $startDate)
            ->where('stat_date', '<=', $endDate)
            ->order('stat_date', 'asc')
            ->select()
            ->toArray();

        $dateMap = [];
        foreach ($stats as $stat) {
            $dateMap[$stat['stat_date']] = $stat;
        }

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $stat = $dateMap[$date] ?? [
                'order_count'   => 0,
                'total_revenue' => 0,
                'player_count'  => 0,
            ];
            $result[] = [
                'date'         => $date,
                'order_count'  => $stat['order_count'] ?? 0,
                'revenue'      => $stat['total_revenue'] ?? 0,
                'revenue_yuan' => bcdiv((string) ($stat['total_revenue'] ?? 0), '100', 2),
                'player_count' => $stat['player_count'] ?? 0,
            ];
        }

        return array_reverse($result);
    }

    // ========== 公告 ==========

    public function getAnnouncementList(int $clubId, int $page = 1, int $limit = 10): array
    {
        $query = ClubAnnouncement::where('club_id', $clubId)
            ->order('is_top', 'desc')
            ->order('create_time', 'desc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    public function publishAnnouncement(int $clubId, int $operatorId, array $data): array
    {
        if (!ClubMember::checkPermission($clubId, $operatorId, 'announcement_publish')) {
            throw new \RuntimeException('无权限发布公告');
        }

        $announcement = ClubAnnouncement::create([
            'club_id' => $clubId,
            'title'   => $data['title'] ?? '',
            'content' => $data['content'] ?? '',
            'is_top'  => (int) ($data['is_top'] ?? 0),
        ]);

        Log::info("俱乐部公告发布: club_id={$clubId}, announcement_id={$announcement->id}");
        return $announcement->toArray();
    }

    // ========== 缩写备选推荐 ==========

    public function generateAbbrAlternatives(string $clubName): array
    {
        $alternatives = [];
        $baseAbbr = $this->pinyinAbbr($clubName);

        $suffixes = ['', 'Club', 'Gaming', 'Team', 'Pro', 'VIP', 'Top', 'King'];
        $prefixes = ['', 'Super', 'Top', 'King', 'Pro'];

        foreach ($prefixes as $prefix) {
            foreach ($suffixes as $suffix) {
                $abbr = strtoupper(substr($prefix . $baseAbbr . $suffix, 0, 8));
                if (!in_array($abbr, $alternatives)) {
                    $alternatives[] = $abbr;
                }
                if (count($alternatives) >= 10) break 2;
            }
        }

        $available = [];
        foreach ($alternatives as $abbr) {
            $occupied = \app\model\ClubAbbreviation::isOccupied($abbr);
            if (!$occupied) {
                $available[] = $abbr;
            }
            if (count($available) >= 3) break;
        }

        return $available;
    }

    private function pinyinAbbr(string $chinese): string
    {
        if (empty($chinese)) return '';
        $abbr = '';
        $len = mb_strlen($chinese, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($chinese, $i, 1, 'UTF-8');
            $abbr .= $this->getFirstLetter($char);
        }
        return strtoupper($abbr);
    }

    private function getFirstLetter(string $char): string
    {
        if (preg_match('/^[a-zA-Z0-9]$/', $char)) {
            return strtoupper($char);
        }

        $fchar = ord($char[0]);
        if ($fchar >= ord('A') && $fchar <= ord('Z')) return $char;
        if ($fchar >= ord('a') && $fchar <= ord('z')) return strtoupper($char);

        $s1 = iconv('UTF-8', 'GBK//IGNORE', $char);
        if (!$s1 || strlen($s1) < 2) return '';

        $s2 = ord($s1[0]) * 256 + ord($s1[1]);
        if ($s2 < 0xB0A1) return '';

        if ($s2 >= 0xB0C5 && $s2 <= 0xB2C0) return 'A';
        if ($s2 >= 0xB2C1 && $s2 <= 0xB4ED) return 'B';
        if ($s2 >= 0xB4EE && $s2 <= 0xB6E9) return 'C';
        if ($s2 >= 0xB6EA && $s2 <= 0xB7A1) return 'D';
        if ($s2 >= 0xB7A2 && $s2 <= 0xB8C0) return 'E';
        if ($s2 >= 0xB8C1 && $s2 <= 0xB9FD) return 'F';
        if ($s2 >= 0xB9FE && $s2 <= 0xBBF6) return 'G';
        if ($s2 >= 0xBBF7 && $s2 <= 0xBFA5) return 'H';
        if ($s2 >= 0xBFA6 && $s2 <= 0xC0AA) return 'J';
        if ($s2 >= 0xC0AB && $s2 <= 0xC2E7) return 'K';
        if ($s2 >= 0xC2E8 && $s2 <= 0xC4C2) return 'L';
        if ($s2 >= 0xC4C3 && $s2 <= 0xC5B5) return 'M';
        if ($s2 >= 0xC5B6 && $s2 <= 0xC5BD) return 'N';
        if ($s2 >= 0xC5BE && $s2 <= 0xC6D9) return 'O';
        if ($s2 >= 0xC6DA && $s2 <= 0xC8BA) return 'P';
        if ($s2 >= 0xC8BB && $s2 <= 0xC8F5) return 'Q';
        if ($s2 >= 0xC8F6 && $s2 <= 0xCBF9) return 'R';
        if ($s2 >= 0xCBFA && $s2 <= 0xCDD9) return 'S';
        if ($s2 >= 0xCDDA && $s2 <= 0xCEF3) return 'T';
        if ($s2 >= 0xCEF4 && $s2 <= 0xD188) return 'W';
        if ($s2 >= 0xD1B9 && $s2 <= 0xD4D0) return 'X';
        if ($s2 >= 0xD4D1 && $s2 <= 0xD7F9) return 'Y';
        if ($s2 >= 0xD7FA && $s2 <= 0xD7FA) return 'Z';

        return '';
    }
}
