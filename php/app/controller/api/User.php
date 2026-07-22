<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\ElectronicSignature;
use app\model\GuardianVerify;
use app\model\InviteBindLog;
use app\model\InviteCode;
use app\model\JoinUsLog;
use app\model\PhoneAppeal;
use app\model\RealVerifyLog;
use app\model\RiskControlLog;
use app\model\User as UserModel;
use think\facade\Cache;
use think\facade\Log;
use think\Request;

/**
 * 用户相关控制器（微信小程序端）
 */
class User extends BaseController
{
    /**
     * 微信登录
     * wx.login 获取 code，换取 openid，手机号快速验证
     */
    public function wxLogin(Request $request)
    {
        $code          = $request->param('code', '');
        $phoneCode     = $request->param('phone_code', ''); // getPhoneNumber 返回的 code
        $nickname      = $request->param('nickname', '');
        $avatar        = $request->param('avatar', '');
        $inviteCode    = $request->param('invite_code', '');

        if (empty($code)) {
            return $this->error('登录凭证 code 不能为空');
        }

        try {
            // 调用微信接口换取 openid 和 session_key
            $appId  = config_get('wechat.app_id');
            $secret = config_get('wechat.app_secret');
            $wxUrl  = "https://api.weixin.qq.com/sns/jscode2session?appid={$appId}&secret={$secret}&js_code={$code}&grant_type=authorization_code";

            $wxResult = json_decode(file_get_contents($wxUrl), true);
            if (!isset($wxResult['openid'])) {
                Log::error('微信登录换取openid失败: ' . json_encode($wxResult, JSON_UNESCAPED_UNICODE));
                return $this->error('微信登录失败，请重试', 500);
            }

            $openid     = $wxResult['openid'];
            $sessionKey = $wxResult['session_key'] ?? '';
            $unionid    = $wxResult['unionid'] ?? '';

            // 查找或创建用户
            $user = UserModel::where('openid', $openid)->find();
            if (!$user) {
                $user = UserModel::create([
                    'openid'          => $openid,
                    'unionid'         => $unionid,
                    'nickname'        => $nickname ?: '微信用户',
                    'avatar'          => $avatar ?: '',
                    'status'          => UserModel::STATUS_ENABLED,
                    'last_login_time' => date('Y-m-d H:i:s'),
                    'last_login_ip'   => get_client_ip(),
                ]);
            } else {
                if ($user->getData('status') == UserModel::STATUS_DISABLED) {
                    return $this->error('账号已被禁用，请联系客服', 403);
                }

                $user->last_login_time = date('Y-m-d H:i:s');
                $user->last_login_ip   = get_client_ip();
                if ($nickname) $user->nickname = $nickname;
                if ($avatar) $user->avatar = $avatar;
                $user->save();
            }

            // 手机号快速验证
            $phone = '';
            if (!empty($phoneCode)) {
                $phoneInfo = $this->getWxPhoneNumber($phoneCode);
                if ($phoneInfo && !empty($phoneInfo['phoneNumber'])) {
                    $phone = $phoneInfo['phoneNumber'];
                    $user->phone = $phone;
                    $user->save();
                }
            }

            // 生成 token
            $token = $this->generateJwtToken($user->id);

            $userData = $user->hidden(['openid', 'unionid', 'id_card'])->toArray();
            $userData['has_phone'] = !empty($user->getData('phone'));

            // 记录风控日志
            $this->writeRiskLog($user->id, 'user_login', 'low', [
                'ip'       => get_client_ip(),
                'openid'   => $openid,
            ]);

            write_action_log('api_user_login', "用户登录 ID:{$user->id}");

            return $this->success([
                'token' => $token,
                'user'  => $userData,
            ], '登录成功');
        } catch (\Throwable $e) {
            Log::error('微信登录异常: ' . $e->getMessage());
            return $this->error('登录失败，请稍后重试', 500);
        }
    }

    /**
     * 注册（绑定手机号，昵称头像）
     */
    public function register(Request $request)
    {
        $userId   = request()->userId();
        $phone    = $request->param('phone', '');
        $smsCode  = $request->param('sms_code', '');
        $nickname = $request->param('nickname', '');
        $avatar   = $request->param('avatar', '');

        $error = $this->validateRequired(['phone' => $phone, 'sms_code' => $smsCode], ['phone', 'sms_code']);
        if ($error) {
            return $this->error($error);
        }

        // 验证短信验证码
        $cacheKey = 'sms_register:' . $phone;
        $cachedCode = Cache::get($cacheKey);
        if (!$cachedCode || $cachedCode !== $smsCode) {
            return $this->error('短信验证码错误或已过期');
        }

        // 检查手机号是否已被绑定
        $existUser = UserModel::where('phone', $phone)->where('id', '<>', $userId)->find();
        if ($existUser) {
            return $this->error('该手机号已被其他账号绑定');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $user->phone    = $phone;
        $user->nickname = $nickname ?: $user->getData('nickname');
        $user->avatar   = $avatar ?: $user->getData('avatar');
        $user->save();

        // 清除验证码缓存
        Cache::delete($cacheKey);

        $this->writeRiskLog($userId, 'user_register', 'low', [
            'phone' => mask_sensitive($phone, 'phone'),
        ]);

        write_action_log('api_user_register', "用户注册绑定手机号 ID:{$userId}");

        return $this->success($user->hidden(['openid', 'unionid', 'id_card'])->toArray(), '注册成功');
    }

    /**
     * 绑定邀请码（打手/分销商/派单员注册或身份升级）
     */
    public function bindInviteCode(Request $request)
    {
        $userId = request()->userId();
        $code   = $request->param('code', '');

        if (empty($code)) {
            return $this->error('邀请码不能为空');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 检查是否已绑定过邀请码
        $existBind = InviteBindLog::where('user_id', $userId)->find();
        if ($existBind) {
            return $this->error('您已绑定过邀请码，不可重复绑定');
        }

        // 查找邀请码
        $inviteCode = InviteCode::where('code', $code)->find();
        if (!$inviteCode) {
            return $this->error('邀请码不存在');
        }

        if (!$inviteCode->isAvailable()) {
            return $this->error('邀请码已失效');
        }

        // 不能绑定自己创建的邀请码
        if ($inviteCode->getData('creator_id') == $userId) {
            return $this->error('不能绑定自己的邀请码');
        }

        // 创建绑定记录
        InviteBindLog::create([
            'invite_code_id' => $inviteCode->id,
            'user_id'        => $userId,
            'inviter_id'     => $inviteCode->getData('creator_id'),
            'bind_time'      => date('Y-m-d H:i:s'),
        ]);

        // 更新邀请码使用次数
        $inviteCode->use_count = $inviteCode->getData('use_count') + 1;
        $inviteCode->save();

        // 根据邀请码类型升级用户身份（由邀请码创建者身份决定）
        $inviter = UserModel::find($inviteCode->getData('creator_id'));
        if ($inviter) {
            $inviterType = $inviter->getData('user_type');
            // 更新被邀请人身份
            $user->user_type = $inviterType;
            $user->save();
        }

        $this->writeRiskLog($userId, 'bind_invite_code', 'low', [
            'invite_code' => $code,
            'inviter_id'  => $inviteCode->getData('creator_id'),
        ]);

        write_action_log('api_bind_invite_code', "用户 ID:{$userId} 绑定邀请码: {$code}");

        return $this->success(null, '邀请码绑定成功');
    }

    /**
     * 获取个人信息
     */
    public function profile(Request $request)
    {
        $userId = request()->userId();

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $userData = $user->hidden(['openid', 'unionid'])->toArray();

        // 补充关联数据
        $userData['order_count']   = $user->orders()->count();
        $userData['is_real_name']  = $user->getData('is_real_name');
        $userData['is_guardian']   = $user->getData('is_guardian');
        $userData['has_signature'] = ElectronicSignature::where('user_id', $userId)
            ->where('status', ElectronicSignature::STATUS_SIGNED)->count() > 0;
        $userData['invite_info']   = $user->inviteBindLogs()->order('create_time', 'desc')->find();

        return $this->success($userData);
    }

    /**
     * 更新个人信息
     */
    public function updateProfile(Request $request)
    {
        $userId   = request()->userId();
        $nickname = $request->param('nickname', '');
        $avatar   = $request->param('avatar', '');
        $gender   = $request->paramInt('gender', -1);

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        if ($nickname !== '') {
            $user->nickname = $nickname;
        }
        if ($avatar !== '') {
            $user->avatar = $avatar;
        }
        if (in_array($gender, [0, 1, 2])) {
            $user->gender = $gender;
        }

        $user->save();

        write_action_log('api_update_profile', "用户 ID:{$userId} 更新个人信息");

        return $this->success($user->hidden(['openid', 'unionid', 'id_card'])->toArray(), '更新成功');
    }

    /**
     * 实名认证（活体检测：输入姓名+身份证号，调用第三方活体API）
     */
    public function realVerify(Request $request)
    {
        $userId   = request()->userId();
        $realName = $request->param('real_name', '');
        $idCard   = $request->param('id_card', '');

        $error = $this->validateRequired(['real_name' => $realName, 'id_card' => $idCard], ['real_name', 'id_card']);
        if ($error) {
            return $this->error($error);
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        if ($user->getData('is_real_name') == 1) {
            return $this->error('您已完成实名认证');
        }

        // 检查今日验证次数限制
        $todayCount = RealVerifyLog::where('user_id', $userId)
            ->where('create_time', '>=', date('Y-m-d') . ' 00:00:00')
            ->count();
        if ($todayCount >= 5) {
            return $this->error('今日验证次数已达上限，请明天再试');
        }

        // 校验身份证号格式
        if (!preg_match('/^\d{17}[\dXx]$/', $idCard)) {
            return $this->error('身份证号格式不正确');
        }

        // 调用第三方活体检测API
        $verifyResult = $this->faceVerifyApi($realName, $idCard);

        $verifyLog = RealVerifyLog::create([
            'user_id'       => $userId,
            'real_name'     => $realName,
            'id_card'       => $idCard,
            'verify_type'   => RealVerifyLog::TYPE_FACE,
            'status'        => $verifyResult['success'] ? RealVerifyLog::STATUS_SUCCESS : RealVerifyLog::STATUS_FAIL,
            'verify_result' => $verifyResult,
            'verify_time'   => $verifyResult['success'] ? date('Y-m-d H:i:s') : null,
        ]);

        if ($verifyResult['success']) {
            $user->real_name   = $realName;
            $user->id_card     = $idCard;
            $user->is_real_name = 1;
            $user->save();

            $this->writeRiskLog($userId, 'real_verify', 'low', [
                'real_name' => mask_sensitive($realName, 'name'),
                'id_card'   => mask_sensitive($idCard, 'id_card'),
                'result'    => 'success',
            ]);
        } else {
            $this->writeRiskLog($userId, 'real_verify', 'medium', [
                'real_name' => mask_sensitive($realName, 'name'),
                'result'    => 'fail',
                'reason'    => $verifyResult['message'] ?? '验证失败',
            ]);
        }

        write_action_log('api_real_verify', "用户 ID:{$userId} 实名认证，结果: " . ($verifyResult['success'] ? '成功' : '失败'));

        return $this->success([
            'success'     => $verifyResult['success'],
            'message'     => $verifyResult['message'] ?? '',
            'verify_log_id' => $verifyLog->id,
        ], $verifyResult['success'] ? '实名认证成功' : '实名认证失败');
    }

    /**
     * 活体检测回调
     */
    public function faceVerifyCallback(Request $request)
    {
        $bizId    = $request->param('biz_id', '');
        $result   = $request->param('result', '');
        $passCode = $request->param('pass_code', '');
        $rawData  = $request->param('raw_data', '');

        if (empty($bizId)) {
            return $this->error('业务ID不能为空');
        }

        // 验证签名（防止伪造回调）
        $expectedSign = md5($bizId . $result . config_get('faceverify.callback_secret', ''));
        if ($passCode !== $expectedSign) {
            Log::warning('活体检测回调签名验证失败，biz_id: ' . $bizId);
            return $this->error('签名验证失败', 403);
        }

        Log::info('活体检测回调: ' . json_encode([
            'biz_id'   => $bizId,
            'result'   => $result,
            'raw_data' => $rawData,
        ], JSON_UNESCAPED_UNICODE));

        write_action_log('api_face_verify_callback', "活体检测回调 biz_id:{$bizId} result:{$result}");

        return $this->success(null, '回调接收成功');
    }

    /**
     * 未成年人监护人活体验证
     */
    public function guardianVerify(Request $request)
    {
        $userId         = request()->userId();
        $guardianName   = $request->param('guardian_name', '');
        $guardianPhone  = $request->param('guardian_phone', '');
        $guardianIdCard = $request->param('guardian_id_card', '');
        $relationship   = $request->param('relationship', '');

        $error = $this->validateRequired([
            'guardian_name'    => $guardianName,
            'guardian_phone'   => $guardianPhone,
            'guardian_id_card' => $guardianIdCard,
            'relationship'     => $relationship,
        ], ['guardian_name', 'guardian_phone', 'guardian_id_card', 'relationship']);
        if ($error) {
            return $this->error($error);
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 检查是否已有有效的监护人验证
        $existVerify = GuardianVerify::where('user_id', $userId)
            ->where('status', GuardianVerify::STATUS_VERIFIED)
            ->where('expire_time', '>', date('Y-m-d H:i:s'))
            ->find();
        if ($existVerify) {
            return $this->error('已有有效的监护人验证');
        }

        // 调用监护人活体检测API
        $verifyResult = $this->faceVerifyApi($guardianName, $guardianIdCard);

        $guardianVerify = GuardianVerify::create([
            'user_id'          => $userId,
            'guardian_name'    => $guardianName,
            'guardian_phone'   => $guardianPhone,
            'guardian_id_card' => $guardianIdCard,
            'relationship'     => $relationship,
            'status'           => $verifyResult['success'] ? GuardianVerify::STATUS_VERIFIED : GuardianVerify::STATUS_UNVERIFIED,
            'verify_time'      => $verifyResult['success'] ? date('Y-m-d H:i:s') : null,
            'expire_time'      => $verifyResult['success'] ? date('Y-m-d H:i:s', strtotime('+1 year')) : null,
        ]);

        if ($verifyResult['success']) {
            $user->is_guardian = 1;
            $user->save();

            $this->writeRiskLog($userId, 'guardian_verify', 'low', [
                'guardian_name' => mask_sensitive($guardianName, 'name'),
                'relationship'  => $relationship,
                'result'        => 'success',
            ]);
        }

        write_action_log('api_guardian_verify', "用户 ID:{$userId} 监护人验证，结果: " . ($verifyResult['success'] ? '成功' : '失败'));

        return $this->success([
            'success' => $verifyResult['success'],
            'message' => $verifyResult['message'] ?? '',
        ], $verifyResult['success'] ? '监护人验证成功' : '监护人验证失败');
    }

    /**
     * CA电子签名（CFCA证书）
     */
    public function electronicSign(Request $request)
    {
        $userId   = request()->userId();
        $signType = $request->param('sign_type', ElectronicSignature::TYPE_AGREEMENT);
        $content  = $request->param('content', '');

        if (empty($content)) {
            return $this->error('签名内容不能为空');
        }

        if (!in_array($signType, [ElectronicSignature::TYPE_GUARDIAN, ElectronicSignature::TYPE_AGREEMENT])) {
            return $this->error('签名类型无效');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        if (!$user->getData('is_real_name')) {
            return $this->error('请先完成实名认证');
        }

        try {
            // 调用CFCA电子签名API
            $contractId = $this->cfcaSignApi($user, $signType, $content);

            ElectronicSignature::create([
                'user_id'      => $userId,
                'sign_type'    => $signType,
                'sign_content' => $content,
                'contract_id'  => $contractId,
                'status'       => ElectronicSignature::STATUS_SIGNED,
                'sign_time'    => date('Y-m-d H:i:s'),
            ]);

            $this->writeRiskLog($userId, 'electronic_sign', 'low', [
                'sign_type'   => $signType,
                'contract_id' => $contractId,
            ]);

            write_action_log('api_electronic_sign', "用户 ID:{$userId} 电子签名，类型:{$signType}");

            return $this->success([
                'contract_id' => $contractId,
                'sign_time'   => date('Y-m-d H:i:s'),
            ], '电子签名成功');
        } catch (\Throwable $e) {
            Log::error('CFCA电子签名异常: ' . $e->getMessage());
            return $this->error('电子签名失败，请稍后重试', 500);
        }
    }

    /**
     * 手机号二次放号申诉
     */
    public function phoneAppeal(Request $request)
    {
        $userId   = request()->userId();
        $oldPhone = $request->param('old_phone', '');
        $newPhone = $request->param('new_phone', '');
        $reason   = $request->param('reason', '');
        $evidence = $request->param('evidence/a', []);

        $error = $this->validateRequired([
            'old_phone' => $oldPhone,
            'new_phone' => $newPhone,
            'reason'    => $reason,
        ], ['old_phone', 'new_phone', 'reason']);
        if ($error) {
            return $this->error($error);
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $oldPhone) || !preg_match('/^1[3-9]\d{9}$/', $newPhone)) {
            return $this->error('手机号格式不正确');
        }

        if ($oldPhone === $newPhone) {
            return $this->error('新手机号不能与原手机号相同');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 检查是否有进行中的申诉
        $pendingAppeal = PhoneAppeal::where('user_id', $userId)
            ->where('status', PhoneAppeal::STATUS_PENDING)
            ->find();
        if ($pendingAppeal) {
            return $this->error('您有一项申诉正在处理中，请等待审核结果');
        }

        PhoneAppeal::create([
            'user_id'   => $userId,
            'old_phone' => $oldPhone,
            'new_phone' => $newPhone,
            'reason'    => $reason,
            'evidence'  => $evidence,
            'status'    => PhoneAppeal::STATUS_PENDING,
        ]);

        $this->writeRiskLog($userId, 'phone_appeal', 'medium', [
            'old_phone' => mask_sensitive($oldPhone, 'phone'),
            'new_phone' => mask_sensitive($newPhone, 'phone'),
        ]);

        write_action_log('api_phone_appeal', "用户 ID:{$userId} 提交手机号申诉");

        return $this->success(null, '申诉已提交，请等待审核');
    }

    /**
     * 申诉列表
     */
    public function appealList(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();

        $query = PhoneAppeal::where('user_id', $userId)->order('id', 'desc');
        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 申诉详情
     */
    public function appealDetail(Request $request)
    {
        $userId = request()->userId();
        $id     = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('申诉ID无效');
        }

        $appeal = PhoneAppeal::where('user_id', $userId)->where('id', $id)->find();
        if (!$appeal) {
            return $this->error('申诉不存在', 404);
        }

        $appealData = $appeal->toArray();
        $appealData['communications'] = $appeal->communications()->order('create_time', 'asc')->select()->toArray();

        return $this->success($appealData);
    }

    /**
     * 加入我们（获取客服微信号脱敏显示，记录点击次数）
     */
    public function joinUs(Request $request)
    {
        $userId = request()->userId();

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 获取客服微信号
        $serviceWechat = config_get('shop.service_wechat', '');
        $maskedWechat  = $serviceWechat ? mask_sensitive($serviceWechat, 'default') : '';

        // 记录点击
        JoinUsLog::create([
            'user_id'        => $userId,
            'real_name'      => $user->getData('real_name') ?? '',
            'phone'          => $user->getData('phone') ?? '',
            'age'            => 0,
            'game_experience'=> '',
            'reason'         => '小程序端点击加入我们',
            'status'         => JoinUsLog::STATUS_PENDING,
        ]);

        write_action_log('api_join_us', "用户 ID:{$userId} 点击加入我们");

        return $this->success([
            'service_wechat' => $maskedWechat,
            'message'        => '请添加客服微信了解更多',
        ]);
    }

    // ===================== 私有辅助方法 =====================

    /**
     * 获取微信手机号
     * @param string $code
     * @return array|null
     */
    private function getWxPhoneNumber(string $code): ?array
    {
        try {
            $accessToken = $this->getWxAccessToken();
            if (!$accessToken) {
                return null;
            }

            $url = "https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token={$accessToken}";
            $result = $this->httpPost($url, json_encode(['code' => $code]));

            $data = json_decode($result, true);
            if (isset($data['phone_info'])) {
                return $data['phone_info'];
            }

            Log::warning('获取微信手机号失败: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
            return null;
        } catch (\Throwable $e) {
            Log::error('获取微信手机号异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取微信 access_token
     * @return string|null
     */
    private function getWxAccessToken(): ?string
    {
        $cacheKey = 'wx_access_token';
        $token    = Cache::get($cacheKey);
        if ($token) {
            return $token;
        }

        try {
            $appId  = config_get('wechat.app_id');
            $secret = config_get('wechat.app_secret');
            $url    = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appId}&secret={$secret}";

            $result = json_decode(file_get_contents($url), true);
            if (isset($result['access_token'])) {
                Cache::set($cacheKey, $result['access_token'], $result['expires_in'] - 300);
                return $result['access_token'];
            }

            Log::error('获取微信access_token失败: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            return null;
        } catch (\Throwable $e) {
            Log::error('获取微信access_token异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 生成 JWT Token
     * @param int $userId
     * @return string
     */
    private function generateJwtToken(int $userId): string
    {
        $payload = [
            'iss'  => config_get('jwt.issuer', 'game-platform'),
            'iat'  => time(),
            'exp'  => time() + config_get('jwt.ttl', 7200),
            'type' => 'user',
            'uid'  => $userId,
        ];

        $secret    = config_get('jwt.secret_key');
        $algorithm = config_get('jwt.algorithm', 'HS256');
        return \Firebase\JWT\JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * 调用活体检测API
     * @param string $realName
     * @param string $idCard
     * @return array
     */
    private function faceVerifyApi(string $realName, string $idCard): array
    {
        try {
            $apiUrl    = config_get('faceverify.api_url', '');
            $apiKey    = config_get('faceverify.api_key', '');
            $apiSecret = config_get('faceverify.api_secret', '');

            if (empty($apiUrl)) {
                // 模拟返回（开发环境）
                Log::info('活体检测模拟调用: real_name=' . mask_sensitive($realName, 'name'));
                return ['success' => true, 'message' => '验证通过'];
            }

            $bizId = generate_sn('FV');
            $timestamp = (string) time();
            $sign = md5($apiKey . $timestamp . $apiSecret);

            $response = $this->httpPost($apiUrl, json_encode([
                'biz_id'    => $bizId,
                'real_name' => $realName,
                'id_card'   => $idCard,
                'timestamp' => $timestamp,
                'sign'      => $sign,
            ], JSON_UNESCAPED_UNICODE), [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ]);

            $result = json_decode($response, true);
            return [
                'success' => ($result['code'] ?? 1) === 0,
                'message' => $result['message'] ?? '验证失败',
                'biz_id'  => $bizId,
                'raw'     => $result,
            ];
        } catch (\Throwable $e) {
            Log::error('活体检测API异常: ' . $e->getMessage());
            return ['success' => false, 'message' => '验证服务异常，请稍后重试'];
        }
    }

    /**
     * 调用CFCA电子签名API
     * @param UserModel $user
     * @param string $signType
     * @param string $content
     * @return string contract_id
     */
    private function cfcaSignApi(UserModel $user, string $signType, string $content): string
    {
        $apiUrl = config_get('cfca.api_url', '');
        $apiKey = config_get('cfca.api_key', '');

        if (empty($apiUrl)) {
            // 模拟返回（开发环境）
            $contractId = generate_sn('CFCA');
            Log::info('CFCA电子签名模拟调用: user_id=' . $user->id . ', sign_type=' . $signType);
            return $contractId;
        }

        $response = $this->httpPost($apiUrl, json_encode([
            'user_id'    => (string) $user->id,
            'real_name'  => $user->getData('real_name'),
            'id_card'    => $user->getData('id_card'),
            'sign_type'  => $signType,
            'content'    => $content,
            'timestamp'  => (string) time(),
        ], JSON_UNESCAPED_UNICODE), [
            'Content-Type: application/json',
            'X-Api-Key: ' . $apiKey,
        ]);

        $result = json_decode($response, true);
        if (empty($result['contract_id'])) {
            throw new \RuntimeException('CFCA签名返回无效: ' . json_encode($result));
        }

        return $result['contract_id'];
    }

    /**
     * 写入风控日志
     * @param int    $userId
     * @param string $event
     * @param string $riskLevel
     * @param array  $detail
     */
    private function writeRiskLog(int $userId, string $event, string $riskLevel, array $detail = []): void
    {
        try {
            RiskControlLog::create([
                'user_id'    => $userId,
                'event'      => $event,
                'risk_level' => $riskLevel,
                'detail'     => $detail,
                'result'     => RiskControlLog::RESULT_PASS,
            ]);
        } catch (\Throwable $e) {
            Log::error('风控日志写入失败: ' . $e->getMessage());
        }
    }

    /**
     * HTTP POST 请求
     * @param string $url
     * @param string $body
     * @param array  $headers
     * @return string
     */
    private function httpPost(string $url, string $body, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("HTTP {$httpCode}: {$response}");
        }

        return $response;
    }
}