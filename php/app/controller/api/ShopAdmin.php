<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\Admin;
use app\model\Order as OrderModel;
use app\model\RiskControlLog;
use app\model\RiskUser;
use app\model\ShopConfig;
use app\model\User as UserModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

/**
 * 小程序内置管理端控制器（微信小程序端）
 */
class ShopAdmin extends BaseController
{
    /**
     * 内置管理员登录（账号密码）
     */
    public function login(Request $request)
    {
        $username = $request->param('username', '');
        $password = $request->param('password', '');

        $error = $this->validateRequired([
            'username' => $username,
            'password' => $password,
        ], ['username', 'password']);
        if ($error) {
            return $this->error($error);
        }

        // 登录失败次数限制
        $failKey = 'shopadmin_login_fail:' . $username;
        $failCount = (int) Cache::get($failKey, 0);
        if ($failCount >= 5) {
            return $this->error('登录失败次数过多，请15分钟后再试');
        }

        $admin = Admin::where('username', $username)->find();
        if (!$admin) {
            $this->incrementLoginFail($username);
            return $this->error('账号或密码错误');
        }

        if ($admin->getData('status') == Admin::STATUS_DISABLED) {
            return $this->error('账号已被禁用');
        }

        if (!$admin->verifyPassword($password)) {
            $this->incrementLoginFail($username);
            return $this->error('账号或密码错误');
        }

        // 清除失败计数
        Cache::delete($failKey);

        // 更新登录信息
        $admin->last_login_ip   = get_client_ip();
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->login_fail_count = 0;
        $admin->save();

        // 生成 token
        $token = $this->generateAdminToken($admin->id);

        // 检查是否首次登录
        $needChangePassword = false;
        $initLog = \app\model\InitLog::where('admin_id', $admin->id)
            ->order('create_time', 'desc')
            ->find();
        if (!$initLog || $initLog->getData('status') == 0) {
            $needChangePassword = true;
        }

        write_action_log('api_shopadmin_login', "管理员 {$username} ID:{$admin->id} 登录");

        return $this->success([
            'token'                => $token,
            'admin'                => $admin->hidden(['password'])->toArray(),
            'need_change_password' => $needChangePassword,
        ], '登录成功');
    }

    /**
     * 首次登录修改密码
     */
    public function changePassword(Request $request)
    {
        $adminId = request()->adminId();
        $oldPassword = $request->param('old_password', '');
        $newPassword = $request->param('new_password', '');

        $error = $this->validateRequired([
            'old_password' => $oldPassword,
            'new_password' => $newPassword,
        ], ['old_password', 'new_password']);
        if ($error) {
            return $this->error($error);
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        if (!$admin->verifyPassword($oldPassword)) {
            return $this->error('原密码错误');
        }

        if ($oldPassword === $newPassword) {
            return $this->error('新密码不能与原密码相同');
        }

        if (!$this->validatePasswordStrength($newPassword)) {
            return $this->error('密码需≥8位，包含大写、小写、数字、特殊字符至少三种');
        }

        // 检查密码历史
        $historyCount = $admin->passwordHistory()->count();
        if ($historyCount > 0) {
            $recentPasswords = $admin->passwordHistory()
                ->order('create_time', 'desc')
                ->limit(3)
                ->select();
            foreach ($recentPasswords as $history) {
                if (bcrypt_verify($newPassword, $history->getData('password_hash'))) {
                    return $this->error('新密码不能与最近3次使用过的密码相同');
                }
            }
        }

        // 保存密码历史
        \app\model\AdminPasswordHistory::create([
            'admin_id'      => $adminId,
            'password_hash' => $admin->getData('password'),
        ]);

        // 更新密码
        $admin->password = $newPassword;
        $admin->save();

        write_action_log('api_shopadmin_change_password', "管理员 ID:{$adminId} 修改密码");

        return $this->success(null, '密码修改成功');
    }

    /**
     * 本店订单管理（含大额验证失败查看）
     */
    public function orderList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword   = $request->param('keyword', '');
        $status    = $request->param('status', '');
        $userId    = $request->paramInt('user_id', 0);
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = OrderModel::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('order_sn', 'like', "%{$keyword}%")
                  ->whereOr('game_name', 'like', "%{$keyword}%");
            });
        }

        if ($status !== '') {
            $query->where('status', (int) $status);
        }

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充用户信息
        foreach ($list as &$item) {
            $order = OrderModel::find($item['id']);
            $item['user'] = $order->user()->find()?->hidden(['openid', 'unionid', 'id_card'])->toArray();
            $item['player'] = $order->player()->find()?->hidden(['openid', 'unionid', 'id_card'])->toArray();
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 大额验证失败订单（只读）
     */
    public function largeFailOrders(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $largeThreshold = config_get('order.large_amount_threshold', 500);
        $largeThresholdFen = yuan_to_fen((string) $largeThreshold);

        $query = OrderModel::where('order_amount', '>=', $largeThresholdFen)
            ->where('status', 'in', [OrderModel::STATUS_PENDING, OrderModel::STATUS_CANCELED])
            ->order('id', 'desc');

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充风控日志
        foreach ($list as &$item) {
            $riskLog = RiskControlLog::where('user_id', $item['user_id'])
                ->where('event', 'face_verify')
                ->order('create_time', 'desc')
                ->find();
            $item['risk_log'] = $riskLog ? $riskLog->toArray() : null;
        }

        write_action_log('api_shopadmin_large_fail', '查看大额验证失败订单');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 店铺装饰配置
     */
    public function shopConfig(Request $request)
    {
        $config = ShopConfig::order('id', 'desc')->find();
        if (!$config) {
            return $this->success([]);
        }

        return $this->success($config->toArray());
    }

    /**
     * 更新店铺配置
     */
    public function updateShopConfig(Request $request)
    {
        $shopName   = $request->param('shop_name', '');
        $logo       = $request->param('logo', '');
        $contactPhone = $request->param('contact_phone', '');
        $contactEmail = $request->param('contact_email', '');
        $serviceQq  = $request->param('service_qq', '');
        $serviceWechat = $request->param('service_wechat', '');
        $workingHours = $request->param('working_hours', '');
        $notice     = $request->param('notice', '');
        $agreement  = $request->param('agreement/a', []);

        $config = ShopConfig::order('id', 'desc')->find();
        if (!$config) {
            $config = new ShopConfig();
        }

        if ($shopName !== '') $config->shop_name = $shopName;
        if ($logo !== '') $config->logo = $logo;
        if ($contactPhone !== '') $config->contact_phone = $contactPhone;
        if ($contactEmail !== '') $config->contact_email = $contactEmail;
        if ($serviceQq !== '') $config->service_qq = $serviceQq;
        if ($serviceWechat !== '') $config->service_wechat = $serviceWechat;
        if ($workingHours !== '') $config->working_hours = $workingHours;
        if ($notice !== '') $config->notice = $notice;
        if (!empty($agreement)) $config->agreement = $agreement;

        $config->save();

        write_action_log('api_shopadmin_update_config', "更新店铺配置");

        return $this->success($config->toArray(), '配置更新成功');
    }

    /**
     * AI风险用户查看（只读）
     */
    public function riskUsers(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $riskLevel = $request->param('risk_level', '');

        $query = RiskUser::order('id', 'desc');

        if (!empty($riskLevel)) {
            $query->where('risk_level', $riskLevel);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充用户信息
        foreach ($list as &$item) {
            $user = UserModel::find($item['user_id']);
            if ($user) {
                $item['user'] = $user->hidden(['openid', 'unionid', 'id_card'])->toArray();
            }
        }

        write_action_log('api_shopadmin_risk_users', '查看AI风险用户');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 本店数据统计
     */
    public function shopData(Request $request)
    {
        $today     = date('Y-m-d');
        $startDate = $request->param('start_date', $today);
        $endDate   = $request->param('end_date', $today);

        // 订单统计
        $totalOrders = OrderModel::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])->count();
        $pendingOrders = OrderModel::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('status', OrderModel::STATUS_PENDING)->count();
        $completedOrders = OrderModel::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('status', OrderModel::STATUS_COMPLETED)->count();
        $canceledOrders = OrderModel::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('status', OrderModel::STATUS_CANCELED)->count();

        // 金额统计
        $totalAmount = OrderModel::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('status', 'in', [OrderModel::STATUS_COMPLETED, OrderModel::STATUS_PLAYING])
            ->sum('order_amount');

        // 用户统计
        $newUsers = UserModel::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])->count();
        $totalUsers = UserModel::count();

        // 打手统计
        $totalPlayers = UserModel::where('user_type', UserModel::TYPE_PLAYER)->count();
        $onlinePlayers = \app\model\PlayerService::where('status', \app\model\PlayerService::STATUS_ONLINE)->count();

        // 风险用户统计
        $riskUserCount = RiskUser::where('status', RiskUser::STATUS_UNPROCESSED)->count();

        return $this->success([
            'orders' => [
                'total'     => $totalOrders,
                'pending'   => $pendingOrders,
                'completed' => $completedOrders,
                'canceled'  => $canceledOrders,
            ],
            'amount' => [
                'total' => $totalAmount ? fen_to_yuan((int) $totalAmount) : '0.00',
            ],
            'users' => [
                'new'   => $newUsers,
                'total' => $totalUsers,
            ],
            'players' => [
                'total'  => $totalPlayers,
                'online' => $onlinePlayers,
            ],
            'risk_users' => $riskUserCount,
            'date_range' => [
                'start' => $startDate,
                'end'   => $endDate,
            ],
        ]);
    }

    // ===================== 私有辅助方法 =====================

    /**
     * 生成管理员 JWT Token
     * @param int $adminId
     * @return string
     */
    private function generateAdminToken(int $adminId): string
    {
        $payload = [
            'iss'  => config_get('jwt.issuer', 'game-platform'),
            'iat'  => time(),
            'exp'  => time() + config_get('jwt.ttl', 7200),
            'type' => 'admin',
            'uid'  => $adminId,
        ];

        $secret    = config_get('jwt.secret_key');
        $algorithm = config_get('jwt.algorithm', 'HS256');
        return \Firebase\JWT\JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * 增加登录失败计数
     * @param string $username
     */
    private function incrementLoginFail(string $username): void
    {
        $failKey = 'shopadmin_login_fail:' . $username;
        $failCount = (int) Cache::get($failKey, 0);
        Cache::set($failKey, $failCount + 1, 900);
    }
}