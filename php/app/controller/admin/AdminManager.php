<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\Admin;
use app\model\AdminRole;
use app\model\AdminWebauthn;
use think\facade\Log;
use think\Request;

/**
 * 管理员管理控制器
 */
class AdminManager extends BaseController
{
    /**
     * 管理员列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword  = $request->param('keyword', '');
        $status   = $request->param('status', '');
        $roleId   = $request->paramInt('role_id', 0);

        $query = Admin::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                  ->whereOr('nickname', 'like', "%{$keyword}%")
                  ->whereOr('email', 'like', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%");
            });
        }

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if ($roleId > 0) {
            $query->where('role_id', $roleId);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->hidden(['password'])->toArray();

        $this->operationLog('admin_manager_list', '查看管理员列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建管理员
     */
    public function create(Request $request)
    {
        $username  = $request->param('username', '');
        $password  = $request->param('password', '');
        $nickname  = $request->param('nickname', '');
        $roleId    = $request->paramInt('role_id', 0);
        $email     = $request->param('email', '');
        $phone     = $request->param('phone', '');

        if (empty($username) || empty($password)) {
            return $this->error('用户名和密码不能为空');
        }

        if (!$this->validatePasswordStrength($password)) {
            return $this->error('密码需≥8位，包含大写、小写、数字、特殊字符至少三种');
        }

        // 检查用户名唯一性
        $exist = Admin::where('username', $username)->find();
        if ($exist) {
            return $this->error('用户名已存在');
        }

        // 检查角色是否存在
        if ($roleId > 0) {
            $role = AdminRole::find($roleId);
            if (!$role) {
                return $this->error('角色不存在', 404);
            }
        }

        $admin = Admin::create([
            'username' => $username,
            'password' => $password,
            'nickname' => $nickname ?: $username,
            'role_id'  => $roleId,
            'email'    => $email,
            'phone'    => $phone,
            'status'   => Admin::STATUS_ENABLED,
        ]);

        $this->operationLog('admin_create', "创建管理员: {$username}，ID: {$admin->id}");

        return $this->success($admin->hidden(['password'])->toArray(), '管理员创建成功');
    }

    /**
     * 更新管理员
     */
    public function update(Request $request)
    {
        $id       = $request->paramInt('id', 0);
        $nickname = $request->param('nickname', '');
        $roleId   = $request->paramInt('role_id', 0);
        $status   = $request->param('status', '');

        if ($id <= 0) {
            return $this->error('管理员ID无效');
        }

        $admin = Admin::find($id);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        if (!empty($nickname)) {
            $admin->nickname = $nickname;
        }

        if ($roleId > 0) {
            $role = AdminRole::find($roleId);
            if (!$role) {
                return $this->error('角色不存在', 404);
            }
            $admin->role_id = $roleId;
        }

        if ($status !== '') {
            $admin->status = (int)$status;
        }

        $admin->save();

        $this->operationLog('admin_update', "更新管理员: {$admin->username}，ID: {$id}");

        return $this->success($admin->hidden(['password'])->toArray(), '管理员更新成功');
    }

    /**
     * 删除管理员
     */
    public function delete(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('管理员ID无效');
        }

        if ($id == $this->adminId()) {
            return $this->error('不能删除自己');
        }

        $admin = Admin::find($id);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        // 软删除
        $admin->delete();

        $this->operationLog('admin_delete', "删除管理员: {$admin->username}，ID: {$id}");

        return $this->success(null, '管理员已删除');
    }

    /**
     * 分配角色
     */
    public function assignRole(Request $request)
    {
        $adminId = $request->paramInt('admin_id', 0);
        $roleId  = $request->paramInt('role_id', 0);

        if ($adminId <= 0 || $roleId <= 0) {
            return $this->error('参数无效');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        $role = AdminRole::find($roleId);
        if (!$role) {
            return $this->error('角色不存在', 404);
        }

        $admin->role_id = $roleId;
        $admin->save();

        $this->operationLog('admin_assign_role', "为管理员 {$admin->username} 分配角色: {$role->name}");

        return $this->success(null, '角色分配成功');
    }

    /**
     * 管理邮箱
     */
    public function manageEmail(Request $request)
    {
        $adminId = $request->paramInt('admin_id', 0);
        $email   = $request->param('email', '');

        if ($adminId <= 0) {
            return $this->error('管理员ID无效');
        }

        if (empty($email)) {
            return $this->error('邮箱不能为空');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱格式不正确');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        // 检查邮箱是否已被其他管理员绑定
        $exist = Admin::where('email', $email)->where('id', '<>', $adminId)->find();
        if ($exist) {
            return $this->error('该邮箱已被其他管理员绑定');
        }

        $admin->email = $email;
        $admin->save();

        $this->operationLog('admin_manage_email', "管理管理员 {$admin->username} 的邮箱");

        return $this->success(null, '邮箱更新成功');
    }

    /**
     * 管理手机号
     */
    public function managePhone(Request $request)
    {
        $adminId = $request->paramInt('admin_id', 0);
        $phone   = $request->param('phone', '');

        if ($adminId <= 0) {
            return $this->error('管理员ID无效');
        }

        if (empty($phone)) {
            return $this->error('手机号不能为空');
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return $this->error('手机号格式不正确');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        $exist = Admin::where('phone', $phone)->where('id', '<>', $adminId)->find();
        if ($exist) {
            return $this->error('该手机号已被其他管理员绑定');
        }

        $admin->phone = $phone;
        $admin->save();

        $this->operationLog('admin_manage_phone', "管理管理员 {$admin->username} 的手机号");

        return $this->success(null, '手机号更新成功');
    }

    /**
     * 查看登录/签到/公众号关注状态
     */
    public function loginStatus(Request $request)
    {
        $adminId = $request->paramInt('admin_id', 0);

        if ($adminId <= 0) {
            return $this->error('管理员ID无效');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        $status = [
            'admin_id'          => $admin->id,
            'username'          => $admin->username,
            'last_login_ip'     => $admin->getData('last_login_ip'),
            'last_login_time'   => $admin->getData('last_login_time'),
            'login_fail_count'  => $admin->getData('login_fail_count'),
            'account_status'    => $admin->getData('status') == Admin::STATUS_ENABLED ? '正常' : '已禁用',
            'webauthn_devices'  => AdminWebauthn::where('admin_id', $adminId)->count(),
            'has_email'         => !empty($admin->getData('email')),
            'has_phone'         => !empty($admin->getData('phone')),
        ];

        $this->operationLog('admin_login_status', "查看管理员 {$admin->username} 的登录状态");

        return $this->success($status);
    }

    /**
     * 管理通行密钥设备
     */
    public function manageWebauthn(Request $request)
    {
        $adminId = $request->paramInt('admin_id', 0);
        $action  = $request->param('action', 'list'); // list/delete/disable

        if ($adminId <= 0) {
            return $this->error('管理员ID无效');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        switch ($action) {
            case 'list':
                $devices = AdminWebauthn::where('admin_id', $adminId)
                    ->order('create_time', 'desc')
                    ->select()
                    ->toArray();
                return $this->success($devices);

            case 'delete':
                $deviceId = $request->paramInt('device_id', 0);
                $device = AdminWebauthn::where('id', $deviceId)
                    ->where('admin_id', $adminId)
                    ->find();
                if (!$device) {
                    return $this->error('设备不存在', 404);
                }
                $device->delete();

                $this->operationLog('admin_webauthn_delete', "删除管理员 {$admin->username} 的通行密钥设备 ID:{$deviceId}");
                return $this->success(null, '设备已删除');

            case 'disable':
                $deviceId = $request->paramInt('device_id', 0);
                $device = AdminWebauthn::where('id', $deviceId)
                    ->where('admin_id', $adminId)
                    ->find();
                if (!$device) {
                    return $this->error('设备不存在', 404);
                }
                $device->status = AdminWebauthn::STATUS_DISABLED;
                $device->save();

                $this->operationLog('admin_webauthn_disable', "禁用管理员 {$admin->username} 的通行密钥设备 ID:{$deviceId}");
                return $this->success(null, '设备已禁用');

            default:
                return $this->error('不支持的操作');
        }
    }
}