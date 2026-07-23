<?php
// +----------------------------------------------------------------------
// | API 路由定义 - 统一前缀 /api/v1
// | RESTful 风格，GET/POST/PUT/DELETE 方法正确对应
// +----------------------------------------------------------------------

use think\facade\Route;

// ========== 公共接口组（无需认证） ==========
Route::group('api/v1', function () {

    // ---------- 用户认证 ----------
    Route::post('user/login',          'api/User/login');
    Route::post('user/register',       'api/User/register');
    Route::post('user/wechat_login',   'api/User/wechatLogin');
    Route::post('user/wechat_phone',   'api/User/wechatPhone');
    Route::post('user/send_sms',       'api/User/sendSms');
    Route::post('user/reset_password', 'api/User/resetPassword');

    // ---------- 管理员认证 ----------
    Route::post('admin/login',         'api/ShopAdmin/login');
    Route::post('admin/refresh_token', 'api/ShopAdmin/refreshToken');

    // ---------- 公共配置 ----------
    Route::get('config/public',        'api/Common/config');
    Route::get('config/documents',     'api/Common/documents');
    Route::get('config/service_types', 'api/Common/serviceTypes');

    // ---------- 验证码 ----------
    Route::get('captcha/image',        'api/Captcha/image');
    Route::get('captcha/sms',          'api/Captcha/sms');

    // ---------- 文件上传 ----------
    Route::post('upload/image',        'api/Common/upload');
    Route::post('upload/file',         'api/Common/upload');
    Route::post('upload/voice',        'api/Common/upload');

    // ---------- 回调接口 ----------
    Route::post('callback/esign',      'api/Callback/esign');
    Route::post('callback/payment',    'api/Callback/payment');
    Route::post('callback/wechat',     'api/Callback/wechat');

    // ========== 用户端接口组（需要JWT user认证） ==========
    Route::group('', function () {

        // --- 用户信息 ---
        Route::get('user/profile',              'api/User/profile');
        Route::put('user/profile',              'api/User/updateProfile');
        Route::post('user/avatar',              'api/User/uploadAvatar');
        Route::post('user/bind_phone',          'api/User/bindPhone');
        Route::post('user/bind_wechat',         'api/User/bindWechat');
        Route::post('user/change_password',     'api/User/changePassword');
        Route::post('user/bind_invite',         'api/User/bindInviteCode');

        // --- 实名认证 ---
        Route::post('user/realname_auth',       'api/User/realVerify');
        Route::get('user/realname_status',      'api/User/realnameStatus');
        Route::post('user/face_verify_callback', 'api/User/faceVerifyCallback');

        // --- 活体检测 ---
        Route::post('user/liveness_check',      'api/User/livenessCheck');
        Route::get('user/liveness_result',      'api/User/livenessResult');

        // --- 电子签 ---
        Route::post('user/esign/apply',         'api/User/electronicSign');
        Route::get('user/esign/status',         'api/User/esignStatus');
        Route::get('user/esign/download',       'api/User/esignDownload');

        // --- 监护人验证 ---
        Route::post('user/guardian_verify',     'api/User/guardianVerify');

        // --- 手机号申诉 ---
        Route::post('user/phone_appeal',        'api/User/phoneAppeal');
        Route::get('user/appeal_list',          'api/User/appealList');
        Route::get('user/appeal_detail/:id',    'api/User/appealDetail');

        // --- 加入我们 ---
        Route::post('user/join_us',             'api/User/joinUs');

        // --- 订单 ---
        Route::post('order/create',             'api/Order/create');
        Route::get('order/list',                'api/Order/list');
        Route::get('order/detail/:id',          'api/Order/detail');
        Route::put('order/:id/cancel',          'api/Order/cancel');
        Route::put('order/:id/confirm',         'api/Order/confirmComplete');

        // --- 评价 ---
        Route::post('order/evaluate',           'api/Order/evaluate');

        // --- 打赏 ---
        Route::post('order/reward',             'api/Order/reward');

        // --- 聊天 ---
        Route::get('chat/session_list',         'api/Chat/sessionList');
        Route::get('chat/message_list/:session_id', 'api/Chat/messageList');
        Route::post('chat/send_text',           'api/Chat/sendText');
        Route::post('chat/send_voice',          'api/Chat/sendVoice');
        Route::post('chat/send_image',          'api/Chat/sendImage');
        Route::post('chat/recall',              'api/Chat/recall');
        Route::put('chat/read_status',          'api/Chat/readStatus');

        // --- 文件上传 ---
        Route::post('upload/upload',            'api/Common/upload');
        Route::post('upload/signed_url',        'api/Common/signedUrl');

        // --- OCR 识别 ---
        Route::post('ocr/id_card',              'api/Common/ocrIdCard');
        Route::post('ocr/bank_card',            'api/Common/ocrBankCard');
        Route::post('ocr/business_license',     'api/Common/ocrBusinessLicense');

        // --- 语音识别 ---
        Route::post('asr/recognize',            'api/Common/asrRecognize');
        Route::get('asr/result',                'api/Common/asrResult');

        // --- 通知消息 ---
        Route::get('notification/list',         'api/Common/notificationList');
        Route::put('notification/read',         'api/Common/notificationRead');
        Route::get('notification/unread_count', 'api/Common/notificationUnreadCount');

        // --- 操作日志 ---
        Route::get('log/action',                'api/Common/actionLog');

        // --- 群聊 ---
        Route::get('group/list',                'api/GroupChat/groupList');
        Route::post('group/create',             'api/GroupChat/createGroup');
        Route::get('group/detail',              'api/GroupChat/groupDetail');
        Route::get('group/messages',            'api/GroupChat/groupMessages');
        Route::post('group/send_text',          'api/GroupChat/sendText');
        Route::post('group/send_voice',         'api/GroupChat/sendVoice');
        Route::post('group/send_image',         'api/GroupChat/sendImage');
        Route::get('group/members',             'api/GroupChat/groupMembers');
        Route::post('group/announcement',       'api/GroupChat/updateAnnouncement');
        Route::post('group/mute',               'api/GroupChat/muteMember');
        Route::post('group/unmute',             'api/GroupChat/unmuteMember');
        Route::post('group/remove_member',      'api/GroupChat/removeMember');
        Route::post('group/dissolve',           'api/GroupChat/dissolveGroup');
        Route::post('group/add_member',         'api/GroupChat/addMember');

        // --- 售后申诉 ---
        Route::get('after_sale/list',           'api/AfterSale/sessionList');
        Route::post('after_sale/create',        'api/AfterSale/createSession');
        Route::get('after_sale/detail',         'api/AfterSale/sessionDetail');
        Route::get('after_sale/messages',       'api/AfterSale/messageList');
        Route::post('after_sale/send_text',     'api/AfterSale/sendText');
        Route::post('after_sale/send_voice',    'api/AfterSale/sendVoice');
        Route::post('after_sale/send_image',    'api/AfterSale/sendImage');
        Route::post('after_sale/request_intervene', 'api/AfterSale/requestIntervene');

        // --- 订阅消息上报 ---
        Route::post('subscribe/report',         'api.Subscribe/report');
        Route::get('subscribe/status',          'api.Subscribe/status');

        // --- UP主认证 ---
        Route::post('up_master/submit',         'api.UpMaster/submit');
        Route::get('up_master/my_status',       'api.UpMaster/myStatus');
        Route::get('up_master/my_badge',        'api.UpMaster/myBadge');
        Route::get('up_master/my_clubs',        'api.UpMaster/myClubs');
        Route::get('up_master/tier_configs',    'api.UpMaster/tierConfigs');

        // --- 俱乐部入驻 ---
        Route::get('club/check_switch',         'api.Club/checkSwitch');
        Route::post('club/generate_abbr',       'api.Club/generateAbbreviation');
        Route::post('club/submit',              'api.Club/submit');
        Route::post('club/resubmit',            'api.Club/resubmit');
        Route::post('club/pay_deposit',         'api.Club/payDeposit');
        Route::post('club/verify_transfer',     'api.Club/verifyCorporateTransfer');
        Route::get('club/my_status',            'api.Club/myStatus');
        Route::get('club/list',                 'api.Club/list');
        Route::get('club/detail',               'api.Club/detail');

    })->middleware(['auth']);

    // ========== 打手端接口组（需要JWT user认证 + 打手身份校验） ==========
    Route::group('player', function () {

        // --- 接单中心 ---
        Route::get('order_center',              'api/Player/orderCenter');
        Route::post('accept_order',             'api/Player/acceptOrder');
        Route::post('reject_order',             'api/Player/rejectOrder');

        // --- 我的订单 ---
        Route::get('my_orders',                 'api/Player/myOrders');

        // --- 服务管理 ---
        Route::get('order/:id',                 'api/Player/orderDetail');
        Route::put('order/:id/start',           'api/Player/startService');
        Route::put('order/:id/complete',        'api/Player/completeService');

        // --- 服务管理 ---
        Route::get('services',                  'api/Player/myServices');
        Route::put('service/:id',               'api/Player/updateService');

        // --- 收益 ---
        Route::get('income',                    'api/Player/income');

        // --- 评价申诉 ---
        Route::post('evaluation_appeal',        'api/Player/evaluationAppeal');

    })->middleware(['auth', 'auth_player']);

    // ========== 分销商端接口组（需要JWT user认证 + 分销商身份校验） ==========
    Route::group('distributor', function () {

        // --- 分销中心 ---
        Route::get('center',                    'api/Distributor/center');

        // --- 下级管理 ---
        Route::get('subordinates',              'api/Distributor/subordinates');

        // --- 佣金收益 ---
        Route::get('commission_list',           'api/Distributor/commissionList');
        Route::get('first_reward',              'api/Distributor/firstReward');

    })->middleware(['auth', 'auth_distributor']);

    // ========== 派单端接口组（需要JWT user认证 + 派单员身份校验） ==========
    Route::group('dispatcher', function () {

        // --- 派单中心 ---
        Route::get('dispatch_center',           'api/Dispatcher/dispatchCenter');
        Route::post('dispatch',                 'api/Dispatcher/dispatch');
        Route::get('dispatch_history',          'api/Dispatcher/dispatchHistory');

    })->middleware(['auth', 'auth_dispatcher']);

    // ========== 管理后台接口组（需要JWT admin认证） ==========
    Route::group('admin', function () {

        // --- 仪表盘 ---
        Route::get('dashboard',                 'admin/Dashboard/index');
        Route::get('dashboard/trends',          'admin/Dashboard/trends');
        Route::get('dashboard/pending',         'admin/Dashboard/pending');

        // --- 管理员认证 ---
        Route::post('auth/login',               'admin/Login/login');
        Route::post('auth/logout',              'admin/Login/logout');
        Route::post('auth/forget_password',     'admin/Login/forgetPassword');
        Route::post('auth/send_email_code',     'admin/Login/sendEmailCode');
        Route::post('auth/init_change_password','admin/Login/initChangePassword');
        Route::post('auth/init_bind_email',     'admin/Login/initBindEmail');
        Route::get('auth/init_status',          'admin/Login/checkInitStatus');
        Route::post('auth/webauthn_init',       'admin/Login/webauthnInit');
        Route::post('auth/webauthn_verify',     'admin/Login/webauthnVerify');

        // --- 管理员管理 ---
        Route::get('manager/list',              'admin/AdminManager/list');
        Route::post('manager/create',           'admin/AdminManager/create');
        Route::put('manager/update',            'admin/AdminManager/update');
        Route::delete('manager/delete',         'admin/AdminManager/delete');
        Route::put('manager/assign_role',       'admin/AdminManager/assignRole');
        Route::put('manager/manage_email',      'admin/AdminManager/manageEmail');
        Route::put('manager/manage_phone',      'admin/AdminManager/managePhone');
        Route::get('manager/login_status',      'admin/AdminManager/loginStatus');
        Route::get('manager/webauthn',          'admin/AdminManager/manageWebauthn');
        Route::put('manager/webauthn',          'admin/AdminManager/manageWebauthn');
        Route::delete('manager/webauthn',       'admin/AdminManager/manageWebauthn');

        // --- 用户管理 ---
        Route::get('user/list',                 'admin/User/list');
        Route::get('user/detail/:id',           'admin/User/detail');
        Route::put('user/ban',                  'admin/User/ban');
        Route::put('user/unban',                'admin/User/unban');
        Route::get('user/export',               'admin/User/export');
        Route::delete('user/force_unbind',      'admin/User/forceUnbind');

        // --- 订单管理 ---
        Route::get('order/list',                'admin/Order/list');
        Route::get('order/detail/:id',          'admin/Order/detail');
        Route::put('order/force_status',        'admin/Order/forceStatusChange');
        Route::put('order/refund',              'admin/Order/refund');
        Route::post('order/batch_operation',    'admin/Order/batchOperation');
        Route::get('order/large_fail',          'admin/Order/largeFailOrders');
        Route::get('order/confirm_batch',       'admin/Order/confirmBatch');

        // --- 申诉管理 ---
        Route::get('appeal/list',               'admin/Appeal/list');
        Route::get('appeal/detail/:id',         'admin/Appeal/detail');
        Route::post('appeal/communicate',       'admin/Appeal/communicate');
        Route::put('appeal/resolve',            'admin/Appeal/resolve');
        Route::get('appeal/reminders',          'admin/Appeal/reminders');

        // --- 入驻审核 ---
        Route::get('audit/player_list',         'admin/Audit/playerList');
        Route::get('audit/distributor_list',    'admin/Audit/distributorList');
        Route::get('audit/dispatcher_list',     'admin/Audit/dispatcherList');
        Route::get('audit/admin_list',          'admin/Audit/adminList');
        Route::get('audit/club_list',           'admin/Audit/clubList');
        Route::put('audit/approve',             'admin/Audit/approve');
        Route::put('audit/reject',              'admin/Audit/reject');
        Route::put('audit/force_offline',       'admin/Audit/forceOffline');
        Route::get('audit/level_income',        'admin/Audit/levelIncome');

        // --- 资金与提现 ---
        Route::get('finance/withdraw_list',     'admin/Finance/withdrawList');
        Route::put('finance/withdraw_audit',    'admin/Finance/withdrawAudit');
        Route::put('finance/withdraw_config',   'admin/Finance/withdrawConfig');
        Route::post('finance/bank_verify',      'admin/Finance/bankVerify');
        Route::put('finance/platform_fee',      'admin/Finance/platformFee');

        // --- 聊天审计 ---
        Route::get('chat/session_list',         'admin/ChatAudit/sessionList');
        Route::get('chat/message_list',         'admin/ChatAudit/messageList');
        Route::get('chat/asr_result',           'admin/ChatAudit/asrResult');
        Route::get('chat/ocr_result',           'admin/ChatAudit/ocrResult');
        Route::get('chat/nlp_result',           'admin/ChatAudit/nlpResult');
        Route::get('chat/risk_users',           'admin/ChatAudit/riskUsers');
        Route::put('chat/handle_risk',          'admin/ChatAudit/handleRiskUser');

        // --- 系统配置 ---
        Route::get('config/list',               'admin/SystemConfig/list');
        Route::put('config/update',             'admin/SystemConfig/update');
        Route::get('config/get',                'admin/SystemConfig/getConfig');

        // --- 平台文档管理（协议/政策/合同） ---
        Route::get('document/list',              'admin/Document/list');
        Route::post('document/upload',           'admin/Document/upload');
        Route::put('document/replace',           'admin/Document/replace');
        Route::delete('document/delete',         'admin/Document/delete');
        Route::put('document/toggle',            'admin/Document/toggle');
        Route::get('document/versions',          'admin/Document/versions');

        // --- 邀请码管理 ---
        Route::post('invite/generate',          'admin/Invite/generate');
        Route::get('invite/list',               'admin/Invite/list');
        Route::put('invite/void',               'admin/Invite/void');
        Route::get('invite/export',             'admin/Invite/export');

        // --- 灰度发布 ---
        Route::get('gray/list',                 'admin/GrayRelease/list');
        Route::post('gray/create',              'admin/GrayRelease/create');
        Route::put('gray/update',               'admin/GrayRelease/update');
        Route::put('gray/rollback',             'admin/GrayRelease/rollback');
        Route::get('gray/status',               'admin/GrayRelease/status');

        // --- 超时规则 ---
        Route::get('timeout/list',              'admin/TimeoutRule/list');
        Route::post('timeout/create',           'admin/TimeoutRule/create');
        Route::put('timeout/update',            'admin/TimeoutRule/update');
        Route::delete('timeout/delete',         'admin/TimeoutRule/delete');
        Route::put('timeout/toggle',            'admin/TimeoutRule/toggle');

        // --- 备份恢复 ---
        Route::get('backup/list',               'admin/Backup/list');
        Route::post('backup/create',            'admin/Backup/create');
        Route::post('backup/restore',           'admin/Backup/restore');
        Route::get('backup/download',           'admin/Backup/download');

        // --- 平台账号管理 ---
        Route::get('platform_account/list',     'admin/PlatformAccount/list');
        Route::post('platform_account/create',  'admin/PlatformAccount/create');
        Route::put('platform_account/disable',  'admin/PlatformAccount/disable');
        Route::put('platform_account/nickname', 'admin/PlatformAccount/updateNickname');

        // --- 售后关键词 ---
        Route::get('after_sale_keyword/list',       'admin/AfterSaleKeyword/list');
        Route::post('after_sale_keyword/create',    'admin/AfterSaleKeyword/create');
        Route::put('after_sale_keyword/update',     'admin/AfterSaleKeyword/update');
        Route::delete('after_sale_keyword/delete',  'admin/AfterSaleKeyword/delete');
        Route::put('after_sale_keyword/toggle',     'admin/AfterSaleKeyword/toggle');
        Route::post('after_sale_keyword/batch_import', 'admin/AfterSaleKeyword/batchImport');
        Route::post('after_sale_keyword/test',      'admin/AfterSaleKeyword/testMatch');
        Route::put('after_sale_keyword/switch',     'admin/AfterSaleKeyword/toggleSwitch');

        // --- 群聊监察 ---
        Route::get('group_monitor/list',            'admin/GroupChatMonitor/list');
        Route::get('group_monitor/detail',          'admin/GroupChatMonitor/detail');
        Route::get('group_monitor/messages',        'admin/GroupChatMonitor/messages');
        Route::post('group_monitor/dissolve',       'admin/GroupChatMonitor/dissolve');
        Route::post('group_monitor/mute',           'admin/GroupChatMonitor/muteMember');
        Route::post('group_monitor/unmute',         'admin/GroupChatMonitor/unmuteMember');
        Route::post('group_monitor/expel',          'admin/GroupChatMonitor/expelMember');
        Route::post('group_monitor/ban_user',       'admin/GroupChatMonitor/banUser');
        Route::post('group_monitor/freeze',         'admin/GroupChatMonitor/freezeAccount');
        Route::get('group_monitor/punishment_log',  'admin/GroupChatMonitor/punishmentLog');

        // --- 售后介入管理 ---
        Route::get('after_sale_manage/list',        'admin/AfterSaleManage/list');
        Route::get('after_sale_manage/detail',      'admin/AfterSaleManage/detail');
        Route::get('after_sale_manage/messages',    'admin/AfterSaleManage/messages');
        Route::get('after_sale_manage/intervene_log', 'admin/AfterSaleManage/interveneLog');
        Route::post('after_sale_manage/resolve',    'admin/AfterSaleManage/resolve');
        Route::get('after_sale_manage/export',      'admin/AfterSaleManage/export');

        // --- 订阅消息模板管理 ---
        Route::get('subscribe/template/list',       'admin.SubscribeMessage/templateList');
        Route::post('subscribe/template/create',    'admin.SubscribeMessage/templateCreate');
        Route::put('subscribe/template/update/:id', 'admin.SubscribeMessage/templateUpdate');
        Route::delete('subscribe/template/delete/:id','admin.SubscribeMessage/templateDelete');
        Route::put('subscribe/template/toggle/:id', 'admin.SubscribeMessage/templateToggle');
        Route::get('subscribe/log/list',            'admin.SubscribeMessage/logList');

        // --- UP主认证管理 ---
        Route::get('up_master/list',            'admin.UpMaster/list');
        Route::post('up_master/approve',        'admin.UpMaster/approve');
        Route::post('up_master/reject',         'admin.UpMaster/reject');
        Route::post('up_master/revoke',         'admin.UpMaster/revoke');

    })->middleware(['auth_admin']);

})->allowCrossDomain();

// ========== 健康检查 ==========
Route::get('health', function () {
    return json([
        'code'     => 0,
        'msg'      => 'ok',
        'data'     => [
            'status'    => 'running',
            'timestamp' => time(),
            'version'   => '1.0.0',
        ],
        'trace_id' => trace_id(),
    ]);
});

// ========== 404 处理 ==========
Route::miss(function () {
    return json([
        'code'     => 404,
        'msg'      => '接口不存在',
        'data'     => null,
        'trace_id' => trace_id(),
    ])->code(404);
});