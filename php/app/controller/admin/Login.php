<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\Admin;
use app\model\AdminWebauthn;
use app\model\EmailVerifyCode;
use app\model\InitLog;
use Firebase\JWT\JWT;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Log;
use think\Request;

/**
 * 管理员登录控制器
 */
class Login extends BaseController
{
    /**
     * 管理员登录 - 账号密码登录，返回JWT token
     */
    public function login(Request $request)
    {
        $username = $request->param('username', '');
        $password = $request->param('password', '');

        if (empty($username) || empty($password)) {
            return $this->error('用户名和密码不能为空');
        }

        // 查找管理员
        $admin = Admin::where('username', $username)->find();
        if (!$admin) {
            return $this->error('用户名或密码错误', 401);
        }

        // 检查状态
        if ($admin->getData('status') != Admin::STATUS_ENABLED) {
            return $this->error('账号已被禁用', 403);
        }

        // 验证密码
        if (!$admin->verifyPassword($password)) {
            // 记录失败次数
            $admin->login_fail_count = ($admin->getData('login_fail_count') ?? 0) + 1;
            $admin->save();

            $this->operationLog('admin_login', "管理员 {$username} 登录失败，失败次数: {$admin->login_fail_count}");
            return $this->error('用户名或密码错误', 401);
        }

        // 生成 JWT token
        $token = $this->generateToken($admin);

        // 更新登录信息
        $admin->last_login_ip = get_client_ip();
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->login_fail_count = 0;
        $admin->save();

        $adminInfo = $admin->hidden(['password'])->toArray();

        $this->operationLog('admin_login', "管理员 {$username} 登录成功");

        return $this->success([
            'token'       => $token,
            'admin_info'  => $adminInfo,
            'expires_in'  => Config::get('jwt.ttl', 7200),
        ], '登录成功');
    }

    /**
     * 生成 WebAuthn 挑战，返回QR码URL
     */
    public function webauthnInit(Request $request)
    {
        $username = $request->param('username', '');

        if (empty($username)) {
            return $this->error('用户名不能为空');
        }

        $admin = Admin::where('username', $username)->find();
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        // 生成挑战码
        $challenge = bin2hex(random_bytes(32));
        $challengeKey = 'webauthn_challenge:' . $admin->id;
        Cache::store('redis')->set($challengeKey, $challenge, 300);

        // 生成 QR 码 URL（使用 WebAuthn 登录页面 URL）
        $siteUrl = Config::get('app.app_host', 'https://admin.example.com');
        $qrUrl = $siteUrl . '/admin/webauthn-verify?admin_id=' . $admin->id . '&challenge=' . $challenge;

        // 获取已注册的通行密钥设备
        $devices = AdminWebauthn::where('admin_id', $admin->id)
            ->where('status', AdminWebauthn::STATUS_ENABLED)
            ->select()
            ->toArray();

        $this->operationLog('admin_webauthn_init', "管理员 {$username} 发起 WebAuthn 登录");

        return $this->success([
            'challenge'   => $challenge,
            'qr_url'      => $qrUrl,
            'devices'     => $devices,
            'admin_id'    => $admin->id,
        ], 'WebAuthn 挑战已生成');
    }

    /**
     * 验证 WebAuthn 签名
     */
    public function webauthnVerify(Request $request)
    {
        $adminId    = $request->param('admin_id', 0);
        $challenge  = $request->param('challenge', '');
        $credential = $request->param('credential', '');
        $signature  = $request->param('signature', '');

        if (empty($adminId) || empty($challenge) || empty($credential) || empty($signature)) {
            return $this->error('参数不完整');
        }

        // 验证挑战码
        $challengeKey = 'webauthn_challenge:' . $adminId;
        $storedChallenge = Cache::store('redis')->get($challengeKey);
        if (!$storedChallenge || $storedChallenge !== $challenge) {
            return $this->error('挑战码已过期或无效', 401);
        }

        // 查找凭证
        $webauthn = AdminWebauthn::where('admin_id', $adminId)
            ->where('credential_id', $credential)
            ->where('status', AdminWebauthn::STATUS_ENABLED)
            ->find();

        if (!$webauthn) {
            return $this->error('通行密钥无效', 401);
        }

        // 验证签名（使用公钥验证）
        $publicKey = $webauthn->getData('public_key');
        $verifyResult = $this->verifyWebauthnSignature($publicKey, $challenge, $signature);

        if (!$verifyResult) {
            return $this->error('签名验证失败', 401);
        }

        // 更新使用时间
        $webauthn->last_used_time = date('Y-m-d H:i:s');
        $webauthn->save();

        // 清除挑战码
        Cache::store('redis')->delete($challengeKey);

        // 生成 token
        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        $token = $this->generateToken($admin);

        $admin->last_login_ip = get_client_ip();
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->save();

        $this->operationLog('admin_webauthn_login', "管理员 {$admin->username} 通过 WebAuthn 登录成功");

        return $this->success([
            'token'       => $token,
            'admin_info'  => $admin->hidden(['password'])->toArray(),
            'expires_in'  => Config::get('jwt.ttl', 7200),
        ], 'WebAuthn 验证成功');
    }

    /**
     * 退出登录
     */
    public function logout(Request $request)
    {
        $token = $this->getTokenFromRequest($request);

        if ($token) {
            // 将 token 加入黑名单
            $key = 'token_blacklist:' . md5($token);
            $ttl = Config::get('jwt.ttl', 7200);
            Cache::store('redis')->set($key, 1, $ttl);
        }

        $this->operationLog('admin_logout', '管理员退出登录');

        return $this->success(null, '退出成功');
    }

    /**
     * 忘记密码 - 通过邮箱重置密码
     */
    public function forgetPassword(Request $request)
    {
        $email    = $request->param('email', '');
        $code     = $request->param('code', '');
        $password = $request->param('password', '');

        if (empty($email) || empty($code) || empty($password)) {
            return $this->error('参数不完整');
        }

        // 验证密码强度
        if (!$this->validatePasswordStrength($password)) {
            return $this->error('密码需≥8位，包含大写、小写、数字、特殊字符至少三种');
        }

        // 验证邮箱验证码
        if (!EmailVerifyCode::verify($email, $code, EmailVerifyCode::SCENE_RESET_PWD)) {
            return $this->error('验证码错误或已过期');
        }

        // 查找管理员
        $admin = Admin::where('email', $email)->find();
        if (!$admin) {
            return $this->error('该邮箱未绑定管理员账号');
        }

        // 更新密码
        $admin->password = $password;
        $admin->save();

        $this->operationLog('admin_reset_password', "管理员 {$admin->username} 通过邮箱重置密码");

        return $this->success(null, '密码重置成功');
    }

    /**
     * 发送邮箱验证码
     */
    public function sendEmailCode(Request $request)
    {
        $email = $request->param('email', '');
        $scene = $request->paramInt('scene', EmailVerifyCode::SCENE_RESET_PWD);

        if (empty($email)) {
            return $this->error('邮箱不能为空');
        }

        // 频控检查
        $rateKey = 'email_code_rate:' . $email;
        if (!rate_limit_check($rateKey, 1, 60)) {
            return $this->error('发送过于频繁，请60秒后重试', 429);
        }

        // 生成验证码
        $code = generate_code(6);

        // 保存验证码
        EmailVerifyCode::create([
            'email'       => $email,
            'code'        => $code,
            'scene'       => $scene,
            'used'        => 0,
            'expire_time' => date('Y-m-d H:i:s', time() + 300),
        ]);

        // 发送邮件（TODO: 接入邮件发送服务）
        Log::info("管理员邮箱验证码: {$email} -> {$code}");

        $this->operationLog('admin_send_email_code', "向 {$email} 发送验证码");

        return $this->success(null, '验证码已发送');
    }

    /**
     * 首次登录强制改密
     * 新密码需≥8位，包含大写、小写、数字、特殊字符至少三种
     */
    public function initChangePassword(Request $request)
    {
        $adminId = $this->adminId();
        $oldPassword = $request->param('old_password', '');
        $newPassword = $request->param('new_password', '');

        if (empty($oldPassword) || empty($newPassword)) {
            return $this->error('参数不完整');
        }

        if ($oldPassword === $newPassword) {
            return $this->error('新密码不能与旧密码相同');
        }

        if (!$this->validatePasswordStrength($newPassword)) {
            return $this->error('新密码需≥8位，包含大写、小写、数字、特殊字符至少三种');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        if (!$admin->verifyPassword($oldPassword)) {
            return $this->error('旧密码错误');
        }

        $admin->password = $newPassword;
        $admin->save();

        // 记录初始化日志
        InitLog::create([
            'admin_id' => $adminId,
            'module'   => 'change_password',
            'version'  => '1.0',
            'result'   => 'success',
            'detail'   => json_encode(['action' => '首次登录改密'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->operationLog('admin_init_change_password', "管理员 {$admin->username} 首次登录修改密码");

        return $this->success(null, '密码修改成功');
    }

    /**
     * 首次登录强制绑定邮箱
     */
    public function initBindEmail(Request $request)
    {
        $adminId = $this->adminId();
        $email   = $request->param('email', '');
        $code    = $request->param('code', '');

        if (empty($email) || empty($code)) {
            return $this->error('参数不完整');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱格式不正确');
        }

        // 验证邮箱验证码
        if (!EmailVerifyCode::verify($email, $code, EmailVerifyCode::SCENE_BIND)) {
            return $this->error('验证码错误或已过期');
        }

        // 检查邮箱是否已被绑定
        $exist = Admin::where('email', $email)->where('id', '<>', $adminId)->find();
        if ($exist) {
            return $this->error('该邮箱已被绑定');
        }

        $admin = Admin::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        $admin->email = $email;
        $admin->save();

        // 记录初始化日志
        InitLog::create([
            'admin_id' => $adminId,
            'module'   => 'bind_email',
            'version'  => '1.0',
            'result'   => 'success',
            'detail'   => json_encode(['email' => mask_sensitive($email, 'email')], JSON_UNESCAPED_UNICODE),
        ]);

        $this->operationLog('admin_init_bind_email', "管理员 {$admin->username} 首次登录绑定邮箱");

        return $this->success(null, '邮箱绑定成功');
    }

    /**
     * 检查初始化状态
     */
    public function checkInitStatus(Request $request)
    {
        $adminId = $this->adminId();
        $admin = Admin::find($adminId);

        if (!$admin) {
            return $this->error('管理员不存在', 404);
        }

        $status = [
            'need_change_password' => empty($admin->getData('last_login_time')), // 首次登录需改密
            'need_bind_email'      => empty($admin->getData('email')),
            'need_bind_webauthn'   => AdminWebauthn::where('admin_id', $adminId)
                ->where('status', AdminWebauthn::STATUS_ENABLED)
                ->count() == 0,
            'last_login_time'      => $admin->getData('last_login_time'),
        ];

        return $this->success($status);
    }

    /**
     * 生成 JWT Token
     * @param Admin $admin
     * @return string
     */
    private function generateToken(Admin $admin): string
    {
        $secret = Config::get('jwt.secret_key');
        $algorithm = Config::get('jwt.algorithm', 'HS256');
        $ttl = Config::get('jwt.ttl', 7200);

        $payload = [
            'iss'  => Config::get('jwt.issuer', 'game-platform'),
            'iat'  => time(),
            'exp'  => time() + $ttl,
            'type' => 'admin',
            'uid'  => $admin->id,
        ];

        return JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * 从请求中获取 Token
     * @param Request $request
     * @return string|null
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }
        return $request->param('token', '') ?: null;
    }

    /**
     * 验证 WebAuthn 签名
     * @param string $publicKey
     * @param string $challenge
     * @param string $signature
     * @return bool
     */
    private function verifyWebauthnSignature(string $publicKey, string $challenge, string $signature): bool
    {
        // 实际项目中使用 WebAuthn 库验证（如 web-auth/webauthn-lib）
        // 这里保留接口供后续实现
        try {
            $publicKeyResource = openssl_pkey_get_public($publicKey);
            if (!$publicKeyResource) {
                return false;
            }
            $result = openssl_verify($challenge, base64_decode($signature), $publicKeyResource, OPENSSL_ALGO_SHA256);
            openssl_free_key($publicKeyResource);
            return $result === 1;
        } catch (\Throwable $e) {
            Log::error('WebAuthn 签名验证异常: ' . $e->getMessage());
            return false;
        }
    }
}