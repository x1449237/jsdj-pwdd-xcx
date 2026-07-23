<?php
declare(strict_types=1);

namespace app\service;

use app\model\MinorCurfewLog;
use app\model\MinorConsumeWarning;
use app\model\Order as OrderModel;
use app\model\ParentConsumeReport;
use app\model\ParentGuardianBind;
use app\model\ParentGuardianSetting;
use app\model\RealnameCache;
use app\model\User as UserModel;
use think\facade\Db;
use think\facade\Log;

/**
 * 未成年人保护服务
 * 负责宵禁校验、消费预警、活体缓存、家长监护逻辑
 */
class MinorProtectService
{
    private const CURFEW_START_HOUR = 22;
    private const CURFEW_END_HOUR   = 8;

    private const WARNING_80_THRESHOLD  = 0.8;
    private const WARNING_100_THRESHOLD = 1.0;

    private const LIVENESS_CACHE_DAYS = 7;

    private const DEFAULT_MONTHLY_LIMIT = 50000;

    /**
     * 检查是否在宵禁时间内
     * 22:00 - 次日 08:00
     */
    public function isCurfewTime(): bool
    {
        $currentHour = (int) date('H');
        return $currentHour >= self::CURFEW_START_HOUR || $currentHour < self::CURFEW_END_HOUR;
    }

    /**
     * 宵禁校验
     * @param int    $userId
     * @param string $actionType
     * @return array [pass, message]
     */
    public function checkCurfew(int $userId, string $actionType): array
    {
        $user = UserModel::find($userId);
        if (!$user) {
            return ['pass' => false, 'message' => '用户不存在'];
        }

        if (!$user->getData('is_minor')) {
            return ['pass' => true, 'message' => ''];
        }

        if (!$this->isCurfewTime()) {
            return ['pass' => true, 'message' => ''];
        }

        try {
            MinorCurfewLog::create([
                'user_id'     => $userId,
                'action_type' => $actionType,
                'blocked_at'  => date('Y-m-d H:i:s'),
                'ip'          => get_client_ip(),
                'device_info' => request()->header('User-Agent', ''),
            ]);
        } catch (\Throwable $e) {
            Log::error('宵禁日志记录失败: ' . $e->getMessage());
        }

        $actionMap = [
            'order'      => '下单',
            'pay'        => '支付',
            'reward'     => '打赏',
            'join_group' => '加入群聊',
        ];
        $actionName = $actionMap[$actionType] ?? '操作';

        return [
            'pass'    => false,
            'message' => "宵禁时间（22:00-次日08:00）未成年人无法{$actionName}，请在监护人陪同下或白天进行",
        ];
    }

    /**
     * 获取用户当月消费总额
     */
    public function getMonthConsumeAmount(int $userId): int
    {
        $month = date('Y-m');
        $start = date('Y-m-01 00:00:00');
        $end   = date('Y-m-t 23:59:59');

        $orderAmount = OrderModel::where('user_id', $userId)
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->where('status', 'in', [OrderModel::STATUS_PAID, OrderModel::STATUS_DISPATCHING, OrderModel::STATUS_PLAYING, OrderModel::STATUS_COMPLETED])
            ->sum('paid_amount');

        return (int) $orderAmount;
    }

    /**
     * 消费限额校验
     * @param int    $userId
     * @param string $amount
     * @param string $actionType
     * @return array [pass, message, need_guardian_verify]
     */
    public function checkConsumeLimit(int $userId, string $amount, string $actionType = 'order'): array
    {
        $user = UserModel::find($userId);
        if (!$user || !$user->getData('is_minor')) {
            return ['pass' => true, 'message' => '', 'need_guardian_verify' => false];
        }

        $bind = ParentGuardianBind::where('child_user_id', $userId)
            ->where('status', ParentGuardianBind::STATUS_BOUND)
            ->find();

        $monthlyLimit = $bind && $bind->setting ? $bind->setting->getData('monthly_limit') : self::DEFAULT_MONTHLY_LIMIT;

        if ($monthlyLimit <= 0) {
            return ['pass' => true, 'message' => '', 'need_guardian_verify' => false];
        }

        $currentAmount = $this->getMonthConsumeAmount($userId);
        $newTotal      = bc_add((string) $currentAmount, $amount);

        if (bccomp($newTotal, (string) $monthlyLimit, 2) <= 0) {
            $this->checkAndSendWarning($userId, $currentAmount, $monthlyLimit, $bind);
            return ['pass' => true, 'message' => '', 'need_guardian_verify' => false];
        }

        $ratio = bc_div($newTotal, (string) $monthlyLimit, 4);
        if (bccomp($ratio, (string) self::WARNING_100_THRESHOLD, 4) >= 0) {
            return [
                'pass'                 => false,
                'message'              => '本月消费已达限额，需要监护人二次人脸验证后才能继续',
                'need_guardian_verify' => true,
            ];
        }

        return ['pass' => true, 'message' => '', 'need_guardian_verify' => false];
    }

    /**
     * 检查并发送消费预警
     */
    private function checkAndSendWarning(int $userId, int $currentAmount, int $limit, ?ParentGuardianBind $bind): void
    {
        if ($limit <= 0) return;

        $month = date('Y-m');
        $ratio = $currentAmount / $limit;

        try {
            if ($ratio >= self::WARNING_80_THRESHOLD && $ratio < self::WARNING_100_THRESHOLD) {
                $exist = MinorConsumeWarning::where('user_id', $userId)
                    ->where('month', $month)
                    ->where('warning_level', MinorConsumeWarning::LEVEL_80_PERCENT)
                    ->find();
                if (!$exist) {
                    MinorConsumeWarning::create([
                        'user_id'          => $userId,
                        'month'            => $month,
                        'consume_amount'   => $currentAmount,
                        'warning_level'    => MinorConsumeWarning::LEVEL_80_PERCENT,
                        'sent_at'          => date('Y-m-d H:i:s'),
                        'guardian_openid'  => $bind ? $bind->getData('parent_openid') : '',
                    ]);
                    Log::info("消费预警80%: user_id={$userId}, amount={$currentAmount}, limit={$limit}");
                }
            }
        } catch (\Throwable $e) {
            Log::error('消费预警检查失败: ' . $e->getMessage());
        }
    }

    /**
     * 检查活体缓存是否有效
     * @param int    $userId
     * @param string $cacheKey
     * @return bool
     */
    public function checkLivenessCache(int $userId, string $cacheKey): bool
    {
        try {
            $cache = RealnameCache::where('user_id', $userId)
                ->where('cache_key', $cacheKey)
                ->where('expire_time', '>', date('Y-m-d H:i:s'))
                ->find();

            return $cache !== null;
        } catch (\Throwable $e) {
            Log::error('活体缓存检查失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 设置活体缓存（7天有效）
     */
    public function setLivenessCache(int $userId, string $cacheKey): bool
    {
        try {
            $expireTime = date('Y-m-d H:i:s', strtotime('+' . self::LIVENESS_CACHE_DAYS . ' days'));

            $cache = RealnameCache::where('user_id', $userId)
                ->where('cache_key', $cacheKey)
                ->find();

            if ($cache) {
                $cache->expire_time = $expireTime;
                $cache->save();
            } else {
                RealnameCache::create([
                    'user_id'     => $userId,
                    'cache_key'   => $cacheKey,
                    'expire_time' => $expireTime,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('活体缓存设置失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 绑定家长监护
     */
    public function bindGuardian(int $childUserId, string $parentOpenid, string $parentPhone = ''): array
    {
        Db::startTrans();
        try {
            $child = UserModel::find($childUserId);
            if (!$child) {
                Db::rollback();
                return ['success' => false, 'message' => '孩子账号不存在'];
            }

            $exist = ParentGuardianBind::where('child_user_id', $childUserId)
                ->where('parent_openid', $parentOpenid)
                ->find();

            if ($exist && $exist->getData('status') == ParentGuardianBind::STATUS_BOUND) {
                Db::rollback();
                return ['success' => false, 'message' => '已绑定，请勿重复绑定'];
            }

            if ($exist) {
                $exist->status     = ParentGuardianBind::STATUS_BOUND;
                $exist->bind_time  = date('Y-m-d H:i:s');
                $exist->parent_phone = $parentPhone;
                $exist->save();
                $bindId = $exist->id;
            } else {
                $bind = ParentGuardianBind::create([
                    'child_user_id' => $childUserId,
                    'parent_openid' => $parentOpenid,
                    'parent_phone'  => $parentPhone,
                    'bind_time'     => date('Y-m-d H:i:s'),
                    'status'        => ParentGuardianBind::STATUS_BOUND,
                ]);
                $bindId = $bind->id;
            }

            $setting = ParentGuardianSetting::where('bind_id', $bindId)->find();
            if (!$setting) {
                ParentGuardianSetting::create([
                    'bind_id'       => $bindId,
                    'monthly_limit' => self::DEFAULT_MONTHLY_LIMIT,
                    'allow_order'   => 1,
                    'allow_reward'  => 1,
                    'is_frozen'     => 0,
                ]);
            }

            Db::commit();
            return ['success' => true, 'message' => '绑定成功', 'bind_id' => $bindId];
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('家长监护绑定失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '绑定失败，请稍后重试'];
        }
    }

    /**
     * 解绑家长监护
     */
    public function unbindGuardian(int $bindId, string $parentOpenid): array
    {
        Db::startTrans();
        try {
            $bind = ParentGuardianBind::find($bindId);
            if (!$bind) {
                Db::rollback();
                return ['success' => false, 'message' => '绑定记录不存在'];
            }

            if ($bind->getData('parent_openid') !== $parentOpenid) {
                Db::rollback();
                return ['success' => false, 'message' => '无权限操作'];
            }

            $bind->status = ParentGuardianBind::STATUS_UNBOUND;
            $bind->save();

            Db::commit();
            return ['success' => true, 'message' => '解绑成功'];
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('家长监护解绑失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '解绑失败，请稍后重试'];
        }
    }

    /**
     * 获取家长监护设置
     */
    public function getGuardianSetting(int $bindId): ?array
    {
        $bind = ParentGuardianBind::with(['setting'])->find($bindId);
        if (!$bind) return null;

        return [
            'bind_id'       => $bind->id,
            'child_user_id' => $bind->getData('child_user_id'),
            'monthly_limit' => $bind->setting ? $bind->setting->getData('monthly_limit') : 0,
            'allow_order'   => $bind->setting ? $bind->setting->getData('allow_order') : 1,
            'allow_reward'  => $bind->setting ? $bind->setting->getData('allow_reward') : 1,
            'is_frozen'     => $bind->setting ? $bind->setting->getData('is_frozen') : 0,
        ];
    }

    /**
     * 更新监护设置
     */
    public function updateGuardianSetting(int $bindId, string $parentOpenid, array $data): array
    {
        try {
            $bind = ParentGuardianBind::find($bindId);
            if (!$bind) {
                return ['success' => false, 'message' => '绑定记录不存在'];
            }

            if ($bind->getData('parent_openid') !== $parentOpenid) {
                return ['success' => false, 'message' => '无权限操作'];
            }

            $setting = ParentGuardianSetting::where('bind_id', $bindId)->find();
            if (!$setting) {
                $setting = ParentGuardianSetting::create([
                    'bind_id' => $bindId,
                ]);
            }

            if (isset($data['monthly_limit'])) {
                $setting->monthly_limit = (int) $data['monthly_limit'];
            }
            if (isset($data['allow_order'])) {
                $setting->allow_order = (int) $data['allow_order'];
            }
            if (isset($data['allow_reward'])) {
                $setting->allow_reward = (int) $data['allow_reward'];
            }
            if (isset($data['is_frozen'])) {
                $setting->is_frozen = (int) $data['is_frozen'];
            }

            $setting->save();

            return ['success' => true, 'message' => '设置已更新'];
        } catch (\Throwable $e) {
            Log::error('监护设置更新失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '设置更新失败'];
        }
    }

    /**
     * 获取家长绑定列表（家长视角）
     */
    public function getParentBindList(string $parentOpenid): array
    {
        $list = ParentGuardianBind::with(['child', 'setting'])
            ->where('parent_openid', $parentOpenid)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            if (isset($item['child'])) {
                unset($item['child']['openid'], $item['child']['unionid'], $item['child']['id_card']);
            }
            $item['monthly_limit'] = $item['setting']['monthly_limit'] ?? 0;
            $item['allow_order']   = $item['setting']['allow_order'] ?? 1;
            $item['allow_reward']  = $item['setting']['allow_reward'] ?? 1;
            $item['is_frozen']     = $item['setting']['is_frozen'] ?? 0;
            unset($item['setting']);
        }

        return $list;
    }

    /**
     * 生成月度消费报告
     */
    public function generateMonthlyReport(int $bindId, string $month): array
    {
        try {
            $bind = ParentGuardianBind::find($bindId);
            if (!$bind) {
                return ['success' => false, 'message' => '绑定记录不存在'];
            }

            $childUserId = $bind->getData('child_user_id');
            $start = date('Y-m-01 00:00:00', strtotime($month . '-01'));
            $end   = date('Y-m-t 23:59:59', strtotime($month . '-01'));

            $orders = OrderModel::where('user_id', $childUserId)
                ->where('create_time', '>=', $start)
                ->where('create_time', '<=', $end)
                ->where('status', 'in', [OrderModel::STATUS_PAID, OrderModel::STATUS_DISPATCHING, OrderModel::STATUS_PLAYING, OrderModel::STATUS_COMPLETED])
                ->order('create_time', 'desc')
                ->select()
                ->toArray();

            $totalAmount = 0;
            foreach ($orders as $order) {
                $totalAmount += (int) $order['paid_amount'];
            }

            $reportData = [
                'total_amount' => $totalAmount,
                'order_count'  => count($orders),
                'orders'       => array_slice($orders, 0, 50),
            ];

            $report = ParentConsumeReport::where('bind_id', $bindId)
                ->where('month', $month)
                ->find();

            if ($report) {
                $report->total_amount     = $totalAmount;
                $report->order_count      = count($orders);
                $report->report_data_json = $reportData;
                $report->save();
            } else {
                ParentConsumeReport::create([
                    'bind_id'          => $bindId,
                    'month'            => $month,
                    'total_amount'     => $totalAmount,
                    'order_count'      => count($orders),
                    'report_data_json' => $reportData,
                ]);
            }

            return [
                'success'      => true,
                'month'        => $month,
                'total_amount' => $totalAmount,
                'order_count'  => count($orders),
                'orders'       => $orders,
            ];
        } catch (\Throwable $e) {
            Log::error('月度消费报告生成失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '报告生成失败'];
        }
    }

    /**
     * 检查家长是否允许操作
     */
    public function checkParentPermission(int $userId, string $action): array
    {
        $user = UserModel::find($userId);
        if (!$user || !$user->getData('is_minor')) {
            return ['pass' => true, 'message' => ''];
        }

        $bind = ParentGuardianBind::where('child_user_id', $userId)
            ->where('status', ParentGuardianBind::STATUS_BOUND)
            ->with(['setting'])
            ->find();

        if (!$bind || !$bind->setting) {
            return ['pass' => true, 'message' => ''];
        }

        if ($bind->setting->isFrozen()) {
            return ['pass' => false, 'message' => '账号已被监护人冻结，请联系监护人解冻'];
        }

        if ($action === 'order' && !$bind->setting->allowOrder()) {
            return ['pass' => false, 'message' => '监护人已关闭下单权限'];
        }

        if ($action === 'reward' && !$bind->setting->allowReward()) {
            return ['pass' => false, 'message' => '监护人已关闭打赏权限'];
        }

        return ['pass' => true, 'message' => ''];
    }

    /**
     * 获取聊天记录摘要（家长视角）
     */
    public function getChatSummary(int $bindId, string $parentOpenid): array
    {
        try {
            $bind = ParentGuardianBind::find($bindId);
            if (!$bind || $bind->getData('parent_openid') !== $parentOpenid) {
                return ['success' => false, 'message' => '无权限操作'];
            }

            $childUserId = $bind->getData('child_user_id');
            $start = date('Y-m-d H:i:s', strtotime('-30 days'));

            $sessions = Db::name('chat_session')
                ->where(function ($q) use ($childUserId) {
                    $q->where('user_id', $childUserId)->whereOr('player_id', $childUserId);
                })
                ->where('last_time', '>=', $start)
                ->order('last_time', 'desc')
                ->limit(20)
                ->select()
                ->toArray();

            $summary = [];
            foreach ($sessions as $session) {
                $otherUserId = $session['user_id'] == $childUserId ? $session['player_id'] : $session['user_id'];
                $otherUser = UserModel::find($otherUserId);
                $msgCount = Db::name('chat_message')
                    ->where('session_id', $session['id'])
                    ->where('create_time', '>=', $start)
                    ->count();

                $summary[] = [
                    'session_id'  => $session['id'],
                    'other_user'  => $otherUser ? [
                        'nickname' => $otherUser->getData('nickname'),
                        'avatar'   => $otherUser->getData('avatar'),
                    ] : null,
                    'msg_count'   => $msgCount,
                    'last_time'   => $session['last_time'],
                    'last_message' => mb_substr($session['last_message'] ?? '', 0, 50),
                ];
            }

            return ['success' => true, 'summary' => $summary];
        } catch (\Throwable $e) {
            Log::error('聊天摘要获取失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '获取失败'];
        }
    }
}
