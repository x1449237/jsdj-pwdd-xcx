<?php
declare(strict_types=1);

namespace app\validate;

use think\Validate;

/**
 * 用户验证器
 */
class User extends Validate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        // 注册
        'phone'          => 'require|regex:/^1[3-9]\d{9}$/',
        'sms_code'       => 'require|length:6',
        'nickname'       => 'require|max:50',
        'avatar'         => 'max:500',
        'code'           => 'require|length:6',

        // 实名认证
        'real_name'      => 'require|length:2,20|chs',
        'id_card'        => 'require|regex:/^\d{17}[\dXx]$/',
        'id_card_front'  => 'require|max:500',
        'id_card_back'   => 'require|max:500',

        // 手机号申诉
        'old_phone'      => 'require|regex:/^1[3-9]\d{9}$/',
        'new_phone'      => 'require|regex:/^1[3-9]\d{9}$/',
        'reason'         => 'require|min:10|max:500',
        'evidence'       => 'array',
    ];

    /**
     * 验证提示信息
     * @var array
     */
    protected $message = [
        'phone.require'           => '手机号不能为空',
        'phone.regex'             => '手机号格式不正确',
        'sms_code.require'        => '短信验证码不能为空',
        'sms_code.length'         => '短信验证码为6位数字',
        'nickname.require'        => '昵称不能为空',
        'nickname.max'            => '昵称最多50个字符',
        'avatar.max'              => '头像URL最多500个字符',
        'code.require'            => '验证码不能为空',
        'code.length'             => '验证码为6位数字',

        'real_name.require'       => '真实姓名不能为空',
        'real_name.length'        => '真实姓名长度需在2-20个字符之间',
        'real_name.chs'           => '真实姓名需为中文',
        'id_card.require'         => '身份证号不能为空',
        'id_card.regex'           => '身份证号格式不正确',
        'id_card_front.require'   => '身份证正面照不能为空',
        'id_card_front.max'       => '身份证正面照URL最多500个字符',
        'id_card_back.require'    => '身份证反面照不能为空',
        'id_card_back.max'        => '身份证反面照URL最多500个字符',

        'old_phone.require'       => '原手机号不能为空',
        'old_phone.regex'         => '原手机号格式不正确',
        'new_phone.require'       => '新手机号不能为空',
        'new_phone.regex'         => '新手机号格式不正确',
        'reason.require'          => '申诉原因不能为空',
        'reason.min'              => '申诉原因不能少于10个字',
        'reason.max'              => '申诉原因不能超过500字',
        'evidence.array'          => '证据格式不正确',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        // 注册场景
        'register' => ['phone', 'sms_code', 'nickname'],

        // 实名认证场景
        'realname' => ['real_name', 'id_card'],

        // 手机号申诉场景
        'phone_appeal' => ['old_phone', 'new_phone', 'reason'],
    ];

    /**
     * 自定义身份证号校验规则
     * 校验18位身份证号格式（含校验位）
     * @param string $value
     * @param string $rule
     * @param array  $data
     * @return string|true
     */
    protected function checkIdCard(string $value, string $rule, array $data = [])
    {
        // 18位身份证号格式校验
        if (!preg_match('/^\d{17}[\dXx]$/', $value)) {
            return '身份证号格式不正确';
        }

        // 校验出生日期
        $birthday = substr($value, 6, 8);
        $year  = (int) substr($birthday, 0, 4);
        $month = (int) substr($birthday, 4, 2);
        $day   = (int) substr($birthday, 6, 2);

        if ($year < 1900 || $year > (int) date('Y')) {
            return '身份证号出生年份不合法';
        }
        if ($month < 1 || $month > 12) {
            return '身份证号出生月份不合法';
        }
        if ($day < 1 || $day > 31) {
            return '身份证号出生日期不合法';
        }

        // 校验最后一位校验码
        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $checkCodes = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];

        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int) $value[$i] * $weights[$i];
        }
        $mod = $sum % 11;

        if (strtoupper($value[17]) !== $checkCodes[$mod]) {
            return '身份证号校验位不正确';
        }

        return true;
    }

    /**
     * 自定义新旧手机号不能相同
     * @param string $value
     * @param string $rule
     * @param array  $data
     * @return string|true
     */
    protected function checkPhoneDifferent(string $value, string $rule, array $data = [])
    {
        if (isset($data['old_phone']) && $data['old_phone'] === $value) {
            return '新手机号不能与原手机号相同';
        }
        return true;
    }

    /**
     * 注册场景
     * @return User
     */
    public function sceneRegister(): User
    {
        return $this->only(['phone', 'sms_code', 'nickname']);
    }

    /**
     * 实名认证场景
     * @return User
     */
    public function sceneRealname(): User
    {
        return $this->only(['real_name', 'id_card'])
            ->remove('id_card', 'regex')
            ->append('id_card', 'checkIdCard');
    }

    /**
     * 手机号申诉场景
     * @return User
     */
    public function scenePhoneAppeal(): User
    {
        return $this->only(['old_phone', 'new_phone', 'reason'])
            ->append('new_phone', 'checkPhoneDifferent');
    }
}