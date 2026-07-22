<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 邮件验证码模型
 * @property int    $id
 * @property string $email
 * @property string $code
 * @property int    $scene         使用场景 1-注册 2-登录 3-绑定 4-找回密码
 * @property int    $used          0-未使用 1-已使用
 * @property string $expire_time
 * @property string $create_time
 * @property string $update_time
 */
class EmailVerifyCode extends Model
{
    protected $name = 'email_verify_code';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 场景
    const SCENE_REGISTER   = 1;
    const SCENE_LOGIN      = 2;
    const SCENE_BIND       = 3;
    const SCENE_RESET_PWD  = 4;

    /**
     * 邮箱获取器 - 脱敏
     */
    public function getEmailAttr($value): string
    {
        return mask_sensitive($value, 'email');
    }

    /**
     * 按邮箱查询
     */
    public function scopeByEmail($query, string $email)
    {
        $query->where('email', $email);
    }

    /**
     * 按场景查询
     */
    public function scopeByScene($query, int $scene)
    {
        $query->where('scene', $scene);
    }

    /**
     * 查询未使用
     */
    public function scopeUnused($query)
    {
        $query->where('used', 0);
    }

    /**
     * 查询未过期
     */
    public function scopeNotExpired($query)
    {
        $query->where('expire_time', '>', date('Y-m-d H:i:s'));
    }

    /**
     * 验证验证码是否有效
     */
    public static function verify(string $email, string $code, int $scene): bool
    {
        $record = self::where('email', $email)
            ->where('code', $code)
            ->where('scene', $scene)
            ->where('used', 0)
            ->where('expire_time', '>', date('Y-m-d H:i:s'))
            ->find();

        if ($record) {
            $record->used = 1;
            $record->save();
            return true;
        }
        return false;
    }
}