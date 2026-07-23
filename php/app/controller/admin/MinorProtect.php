<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\MinorConsumeWarning;
use app\model\MinorCurfewLog;
use app\model\ParentGuardianBind;
use app\model\ParentGuardianSetting;
use app\model\SystemConfig as SystemConfigModel;
use app\model\User as UserModel;
use think\facade\Db;
use think\Request;

/**
 * 未成年人保护后台控制器
 */
class MinorProtect extends BaseController
{
    /**
     * 获取宵禁配置
     */
    public function getCurfewConfig(Request $request)
    {
        $configKeys = [
            'curfew_start_hour',
            'curfew_end_hour',
            'curfew_enabled',
            'minor_monthly_default_limit',
            'warning_80_threshold',
            'warning_100_threshold',
        ];

        $configs = [];
        foreach ($configKeys as $key) {
            $config = SystemConfigModel::where('key', $key)->find();
            $configs[$key] = $config ? $config->getData('value') : '';
        }

        $defaults = [
            'curfew_start_hour'          => 22,
            'curfew_end_hour'            => 8,
            'curfew_enabled'             => 1,
            'minor_monthly_default_limit' => 50000,
            'warning_80_threshold'       => 0.8,
            'warning_100_threshold'      => 1.0,
        ];

        foreach ($defaults as $key => $default) {
            if ($configs[$key] === '' || $configs[$key] === null) {
                $configs[$key] = $default;
            }
        }

        $this->operationLog('admin_minor_curfew_config_get', '查看宵禁配置');

        return $this->success($configs);
    }

    /**
     * 更新宵禁配置
     */
    public function updateCurfewConfig(Request $request)
    {
        $startHour = $request->paramInt('curfew_start_hour', 22);
        $endHour = $request->paramInt('curfew_end_hour', 8);
        $enabled = $request->paramInt('curfew_enabled', 1);
        $defaultLimit = $request->paramInt('minor_monthly_default_limit', 50000);
        $warning80 = $request->param('warning_80_threshold', '0.8');
        $warning100 = $request->param('warning_100_threshold', '1.0');

        if ($startHour < 0 || $startHour > 23) {
            return $this->error('开始时间无效');
        }
        if ($endHour < 0 || $endHour > 23) {
            return $this->error('结束时间无效');
        }
        if ($defaultLimit < 0) {
            return $this->error('默认限额无效');
        }

        $configs = [
            ['key' => 'curfew_start_hour', 'value' => $startHour, 'type' => 'int', 'group' => 'minor_protect', 'description' => '宵禁开始时间(小时)'],
            ['key' => 'curfew_end_hour', 'value' => $endHour, 'type' => 'int', 'group' => 'minor_protect', 'description' => '宵禁结束时间(小时)'],
            ['key' => 'curfew_enabled', 'value' => $enabled, 'type' => 'int', 'group' => 'minor_protect', 'description' => '宵禁是否启用'],
            ['key' => 'minor_monthly_default_limit', 'value' => $defaultLimit, 'type' => 'int', 'group' => 'minor_protect', 'description' => '未成年人默认月消费限额(分)'],
            ['key' => 'warning_80_threshold', 'value' => $warning80, 'type' => 'float', 'group' => 'minor_protect', 'description' => '80%消费预警阈值'],
            ['key' => 'warning_100_threshold', 'value' => $warning100, 'type' => 'float', 'group' => 'minor_protect', 'description' => '100%消费预警阈值'],
        ];

        foreach ($configs as $item) {
            $config = SystemConfigModel::where('key', $item['key'])->find();
            if ($config) {
                $config->value = $item['value'];
                $config->save();
            } else {
                SystemConfigModel::create($item);
            }
        }

        $this->operationLog('admin_minor_curfew_config_update', '更新宵禁配置');

        return $this->success(null, '配置更新成功');
    }

    /**
     * 宵禁拦截日志列表
     */
    public function curfewLogList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $userId = $request->paramInt('user_id', 0);
        $actionType = $request->param('action_type', '');
        $startDate = $request->param('start_date', '');
        $endDate = $request->param('end_date', '');

        $query = MinorCurfewLog::with(['user'])->order('id', 'desc');

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if (!empty($actionType)) {
            $query->where('action_type', $actionType);
        }
        if (!empty($startDate)) {
            $query->where('blocked_at', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('blocked_at', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            if (isset($item['user'])) {
                unset($item['user']['openid'], $item['user']['unionid'], $item['user']['id_card']);
            }
        }

        $this->operationLog('admin_minor_curfew_log_list', '查看宵禁拦截日志');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 消费预警日志列表
     */
    public function warningLogList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $userId = $request->paramInt('user_id', 0);
        $month = $request->param('month', '');
        $warningLevel = $request->paramInt('warning_level', 0);

        $query = MinorConsumeWarning::with(['user'])->order('id', 'desc');

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if (!empty($month)) {
            $query->where('month', $month);
        }
        if ($warningLevel > 0) {
            $query->where('warning_level', $warningLevel);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            if (isset($item['user'])) {
                unset($item['user']['openid'], $item['user']['unionid'], $item['user']['id_card']);
            }
        }

        $this->operationLog('admin_minor_warning_log_list', '查看消费预警日志');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 监护绑定列表
     */
    public function guardianList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $childUserId = $request->paramInt('child_user_id', 0);
        $parentPhone = $request->param('parent_phone', '');
        $status = $request->paramInt('status', -1);

        $query = ParentGuardianBind::with(['child', 'setting'])->order('id', 'desc');

        if ($childUserId > 0) {
            $query->where('child_user_id', $childUserId);
        }
        if (!empty($parentPhone)) {
            $query->where('parent_phone', 'like', "%{$parentPhone}%");
        }
        if ($status >= 0) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            if (isset($item['child'])) {
                unset($item['child']['openid'], $item['child']['unionid'], $item['child']['id_card']);
            }
            $item['monthly_limit'] = $item['setting']['monthly_limit'] ?? 0;
            $item['is_frozen']     = $item['setting']['is_frozen'] ?? 0;
            unset($item['setting']);
        }

        $this->operationLog('admin_minor_guardian_list', '查看监护绑定列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 风险用户列表（未成年人 + 高消费）
     */
    public function riskUserList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword = $request->param('keyword', '');
        $minAmount = $request->paramInt('min_amount', 0);

        $query = UserModel::where('is_minor', 1)
            ->where('is_real_verified', 1)
            ->order('id', 'desc');

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('nickname', 'like', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $users = $query->page($page, $limit)->select()->toArray();

        $month = date('Y-m');
        $start = date('Y-m-01 00:00:00');
        $end   = date('Y-m-t 23:59:59');

        $list = [];
        foreach ($users as $user) {
            $userId = $user['id'];

            $orderAmount = Db::name('order')
                ->where('user_id', $userId)
                ->where('create_time', '>=', $start)
                ->where('create_time', '<=', $end)
                ->where('status', 'in', [2, 3, 4, 5])
                ->sum('paid_amount');

            $curfewCount = MinorCurfewLog::where('user_id', $userId)
                ->where('blocked_at', '>=', $start)
                ->count();

            $warningCount = MinorConsumeWarning::where('user_id', $userId)
                ->where('month', $month)
                ->count();

            $bind = ParentGuardianBind::where('child_user_id', $userId)
                ->where('status', ParentGuardianBind::STATUS_BOUND)
                ->find();

            $item = $user;
            unset($item['openid'], $item['unionid'], $item['id_card']);
            $item['month_consume']  = (int) $orderAmount;
            $item['curfew_count']   = $curfewCount;
            $item['warning_count']  = $warningCount;
            $item['has_guardian']   = $bind ? 1 : 0;

            if ($minAmount > 0 && $orderAmount < $minAmount) {
                continue;
            }

            $list[] = $item;
        }

        $this->operationLog('admin_minor_risk_user_list', '查看风险用户列表');

        return $this->page($list, count($list), $page, $limit);
    }

    /**
     * 强制解绑监护
     */
    public function forceUnbind(Request $request)
    {
        $bindId = $request->paramInt('bind_id', 0);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $bind = ParentGuardianBind::find($bindId);
        if (!$bind) {
            return $this->error('绑定记录不存在', 404);
        }

        $bind->status = ParentGuardianBind::STATUS_UNBOUND;
        $bind->save();

        $this->operationLog('admin_minor_force_unbind', "强制解绑监护 bind_id:{$bindId}");

        return $this->success(null, '已强制解绑');
    }

    /**
     * 获取宵禁统计数据
     */
    public function curfewStats(Request $request)
    {
        $days = $request->paramInt('days', 7);
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $end   = date('Y-m-d 23:59:59');

        $totalBlocked = MinorCurfewLog::where('blocked_at', '>=', $start)
            ->where('blocked_at', '<=', $end)
            ->count();

        $uniqueUsers = MinorCurfewLog::where('blocked_at', '>=', $start)
            ->where('blocked_at', '<=', $end)
            ->distinct(true)
            ->count('user_id');

        $actionStats = MinorCurfewLog::where('blocked_at', '>=', $start)
            ->where('blocked_at', '<=', $end)
            ->field('action_type, COUNT(*) as count')
            ->group('action_type')
            ->select()
            ->toArray();

        $dailyStats = MinorCurfewLog::where('blocked_at', '>=', $start)
            ->where('blocked_at', '<=', $end)
            ->field("DATE_FORMAT(blocked_at, '%Y-%m-%d') as date, COUNT(*) as count")
            ->group('date')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        $this->operationLog('admin_minor_curfew_stats', '查看宵禁统计');

        return $this->success([
            'total_blocked' => $totalBlocked,
            'unique_users'  => $uniqueUsers,
            'action_stats'  => $actionStats,
            'daily_stats'   => $dailyStats,
        ]);
    }
}
