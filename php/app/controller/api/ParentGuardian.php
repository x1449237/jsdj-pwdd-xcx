<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\ParentGuardianBind;
use app\model\User as UserModel;
use app\service\MinorProtectService;
use think\facade\Log;
use think\Request;

/**
 * 家长监护控制器（小程序端，家长视角）
 */
class ParentGuardian extends BaseController
{
    private $minorProtectService;

    public function __construct()
    {
        $this->minorProtectService = new MinorProtectService();
    }

    /**
     * 获取家长绑定列表
     */
    public function bindList(Request $request)
    {
        $userId = request()->userId();
        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $parentOpenid = $user->getData('openid');
        $list = $this->minorProtectService->getParentBindList($parentOpenid);

        return $this->success($list);
    }

    /**
     * 绑定监护人
     * 通过孩子用户ID绑定（需要孩子已实名认证且为未成年人）
     */
    public function bind(Request $request)
    {
        $userId = request()->userId();
        $childUserId = $request->paramInt('child_user_id', 0);
        $verifyCode = $request->param('verify_code', '');

        $error = $this->validateRequired([
            'child_user_id' => $childUserId,
            'verify_code'   => $verifyCode,
        ], ['child_user_id', 'verify_code']);
        if ($error) {
            return $this->error($error);
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $child = UserModel::find($childUserId);
        if (!$child) {
            return $this->error('孩子账号不存在', 404);
        }

        if (!$child->getData('is_real_verified')) {
            return $this->error('孩子账号尚未实名认证');
        }

        if (!$child->getData('is_minor')) {
            return $this->error('孩子账号不是未成年人账号');
        }

        $cacheKey = 'parent_bind_code:' . $childUserId;
        $cachedCode = cache($cacheKey);
        if (!$cachedCode || $cachedCode !== $verifyCode) {
            return $this->error('验证码错误或已过期');
        }

        cache($cacheKey, null);

        $parentOpenid = $user->getData('openid');
        $parentPhone  = $user->getData('phone');

        $result = $this->minorProtectService->bindGuardian($childUserId, $parentOpenid, $parentPhone);

        if (!$result['success']) {
            return $this->error($result['message']);
        }

        write_action_log('parent_guardian_bind', "家长 ID:{$userId} 绑定孩子 ID:{$childUserId}");

        return $this->success(['bind_id' => $result['bind_id']], '绑定成功');
    }

    /**
     * 发送绑定验证码
     */
    public function sendBindCode(Request $request)
    {
        $userId = request()->userId();
        $childUserId = $request->paramInt('child_user_id', 0);

        if ($childUserId <= 0) {
            return $this->error('孩子用户ID不能为空');
        }

        $child = UserModel::find($childUserId);
        if (!$child) {
            return $this->error('孩子账号不存在', 404);
        }

        $code = str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey = 'parent_bind_code:' . $childUserId;
        cache($cacheKey, $code, 300);

        Log::info("家长监护绑定验证码: child_user_id={$childUserId}, code={$code}");

        return $this->success(['expire_in' => 300], '验证码已发送');
    }

    /**
     * 解绑
     */
    public function unbind(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $parentOpenid = $user->getData('openid');
        $result = $this->minorProtectService->unbindGuardian($bindId, $parentOpenid);

        if (!$result['success']) {
            return $this->error($result['message']);
        }

        write_action_log('parent_guardian_unbind', "家长 ID:{$userId} 解绑 bind_id:{$bindId}");

        return $this->success(null, '解绑成功');
    }

    /**
     * 获取监护设置
     */
    public function getSetting(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $bind = ParentGuardianBind::find($bindId);
        if (!$bind || $bind->getData('parent_openid') !== $user->getData('openid')) {
            return $this->error('无权限查看', 403);
        }

        $setting = $this->minorProtectService->getGuardianSetting($bindId);
        if (!$setting) {
            return $this->error('设置不存在', 404);
        }

        return $this->success($setting);
    }

    /**
     * 更新月消费限额
     */
    public function updateMonthlyLimit(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);
        $monthlyLimit = $request->param('monthly_limit', '0');

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        if (!is_numeric($monthlyLimit) || $monthlyLimit < 0) {
            return $this->error('限额金额无效');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $result = $this->minorProtectService->updateGuardianSetting(
            $bindId,
            $user->getData('openid'),
            ['monthly_limit' => (int) $monthlyLimit]
        );

        if (!$result['success']) {
            return $this->error($result['message']);
        }

        write_action_log('parent_guardian_update_limit', "家长 ID:{$userId} 更新限额 bind_id:{$bindId}, limit:{$monthlyLimit}");

        return $this->success(null, '限额已更新');
    }

    /**
     * 切换下单权限
     */
    public function toggleOrder(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);
        $allow = $request->paramInt('allow', 1);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $result = $this->minorProtectService->updateGuardianSetting(
            $bindId,
            $user->getData('openid'),
            ['allow_order' => $allow]
        );

        if (!$result['success']) {
            return $this->error($result['message']);
        }

        write_action_log('parent_guardian_toggle_order', "家长 ID:{$userId} 切换下单权限 bind_id:{$bindId}, allow:{$allow}");

        return $this->success(null, '设置已更新');
    }

    /**
     * 切换打赏权限
     */
    public function toggleReward(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);
        $allow = $request->paramInt('allow', 1);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $result = $this->minorProtectService->updateGuardianSetting(
            $bindId,
            $user->getData('openid'),
            ['allow_reward' => $allow]
        );

        if (!$result['success']) {
            return $this->error($result['message']);
        }

        write_action_log('parent_guardian_toggle_reward', "家长 ID:{$userId} 切换打赏权限 bind_id:{$bindId}, allow:{$allow}");

        return $this->success(null, '设置已更新');
    }

    /**
     * 一键冻结/解冻账号
     */
    public function toggleFreeze(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);
        $isFrozen = $request->paramInt('is_frozen', 0);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $result = $this->minorProtectService->updateGuardianSetting(
            $bindId,
            $user->getData('openid'),
            ['is_frozen' => $isFrozen]
        );

        if (!$result['success']) {
            return $this->error($result['message']);
        }

        write_action_log('parent_guardian_freeze', "家长 ID:{$userId} 冻结账号 bind_id:{$bindId}, is_frozen:{$isFrozen}");

        return $this->success(null, $isFrozen ? '账号已冻结' : '账号已解冻');
    }

    /**
     * 查看月度消费账单
     */
    public function consumeReport(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);
        $month = $request->param('month', date('Y-m'));

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $bind = ParentGuardianBind::find($bindId);
        if (!$bind || $bind->getData('parent_openid') !== $user->getData('openid')) {
            return $this->error('无权限查看', 403);
        }

        $report = $this->minorProtectService->generateMonthlyReport($bindId, $month);

        if (!$report['success']) {
            return $this->error($report['message']);
        }

        return $this->success($report);
    }

    /**
     * 查看聊天记录摘要
     */
    public function chatSummary(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $result = $this->minorProtectService->getChatSummary($bindId, $user->getData('openid'));

        if (!$result['success']) {
            return $this->error($result['message']);
        }

        return $this->success($result['summary']);
    }

    /**
     * 获取孩子信息
     */
    public function childInfo(Request $request)
    {
        $userId = request()->userId();
        $bindId = $request->paramInt('bind_id', 0);

        if ($bindId <= 0) {
            return $this->error('绑定ID不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $bind = ParentGuardianBind::with(['child'])->find($bindId);
        if (!$bind || $bind->getData('parent_openid') !== $user->getData('openid')) {
            return $this->error('无权限查看', 403);
        }

        $child = $bind->child;
        if (!$child) {
            return $this->error('孩子账号不存在', 404);
        }

        $monthConsume = $this->minorProtectService->getMonthConsumeAmount($child->id);

        $childData = $child->hidden(['openid', 'unionid', 'id_card', 'delete_time'])->toArray();
        $childData['month_consume'] = $monthConsume;

        return $this->success($childData);
    }
}
