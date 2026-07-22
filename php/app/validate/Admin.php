<?php
declare(strict_types=1);

namespace app\validate;

use think\Validate;

/**
 * 管理员验证器
 */
class Admin extends Validate
{
    /**
     * 登录验证规则
     * @var array
     */
    protected $rule = [
        'username'      => 'require|alphaDash|length:4,32',
        'password'      => 'require|length:8,32',
        'nickname'      => 'require|max:50',
        'role_id'       => 'integer|gt:0',
        'email'         => 'email|max:100',
        'phone'         => 'regex:/^1[3-9]\d{9}$/',
        'old_password'  => 'require|length:8,32',
        'new_password'  => 'require|length:8,32',
        'confirm_password' => 'require|confirm:new_password',
    ];

    /**
     * 验证提示信息
     * @var array
     */
    protected $message = [
        'username.require'       => '用户名不能为空',
        'username.alphaDash'     => '用户名只能包含字母、数字、下划线和短横线',
        'username.length'        => '用户名长度需在4-32个字符之间',
        'password.require'       => '密码不能为空',
        'password.length'        => '密码长度需在8-32个字符之间',
        'nickname.require'       => '昵称不能为空',
        'nickname.max'           => '昵称最多50个字符',
        'role_id.integer'        => '角色ID必须为整数',
        'role_id.gt'             => '角色ID必须大于0',
        'email.email'            => '邮箱格式不正确',
        'email.max'              => '邮箱最多100个字符',
        'phone.regex'            => '手机号格式不正确',
        'old_password.require'   => '原密码不能为空',
        'new_password.require'   => '新密码不能为空',
        'confirm_password.require' => '确认密码不能为空',
        'confirm_password.confirm' => '两次输入的密码不一致',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        // 登录场景
        'login' => ['username', 'password'],

        // 创建管理员场景
        'create' => ['username', 'password', 'nickname', 'role_id', 'email', 'phone'],

        // 密码修改场景
        'change_password' => ['old_password', 'new_password', 'confirm_password'],
    ];

    /**
     * 自定义密码强度验证规则
     * 要求：≥8位，大写+小写+数字+特殊字符至少三种
     * @param string $value
     * @param string $rule
     * @param array  $data
     * @return string|true
     */
    protected function checkPasswordStrength(string $value, string $rule, array $data = [])
    {
        if (strlen($value) < 8) {
            return '密码长度不能少于8位';
        }

        $types = 0;
        if (preg_match('/[A-Z]/', $value)) {
            $types++;
        }
        if (preg_match('/[a-z]/', $value)) {
            $types++;
        }
        if (preg_match('/[0-9]/', $value)) {
            $types++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $value)) {
            $types++;
        }

        if ($types < 3) {
            return '密码需包含大写字母、小写字母、数字、特殊字符中至少三种';
        }

        return true;
    }

    /**
     * 登录场景：覆盖父类 scene 以支持自定义规则
     * @return Admin
     */
    public function sceneLogin(): Admin
    {
        return $this->only(['username', 'password'])
            ->remove('password', 'length')
            ->append('password', 'checkPasswordStrength');
    }

    /**
     * 创建管理员场景
     * @return Admin
     */
    public function sceneCreate(): Admin
    {
        return $this->only(['username', 'password', 'nickname', 'role_id', 'email', 'phone'])
            ->remove('password', 'length')
            ->append('password', 'checkPasswordStrength');
    }

    /**
     * 密码修改场景
     * @return Admin
     */
    public function sceneChangePassword(): Admin
    {
        return $this->only(['old_password', 'new_password', 'confirm_password'])
            ->remove('new_password', 'length')
            ->append('new_password', 'checkPasswordStrength');
    }
}