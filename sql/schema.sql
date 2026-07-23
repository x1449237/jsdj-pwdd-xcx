-- ============================================================
-- 游戏陪玩平台 - 完整数据库设计
-- MySQL 8.0+
-- 字符集: utf8mb4 / 排序规则: utf8mb4_unicode_ci
-- 金额单位: BIGINT 存储分
-- ============================================================

CREATE DATABASE IF NOT EXISTS `game_platform` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `game_platform`;

-- ============================================================
-- 1. 超级管理员表
-- ============================================================
DROP TABLE IF EXISTS `admin`;
CREATE TABLE `admin` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL COMMENT '管理员账号',
  `nickname` VARCHAR(64) DEFAULT '' COMMENT '管理员昵称',
  `password` VARCHAR(255) NOT NULL COMMENT '密码（bcrypt）',
  `email` VARCHAR(128) DEFAULT '' COMMENT '绑定邮箱',
  `phone` VARCHAR(20) DEFAULT '' COMMENT '手机号',
  `avatar` VARCHAR(512) DEFAULT '' COMMENT '头像',
  `role_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '角色ID',
  `is_super` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否超级管理员',
  `is_first_login` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否首次登录（需改密+绑邮箱）',
  `init_completed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首次初始化是否完成',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1正常 0禁用',
  `last_login_ip` VARCHAR(64) DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` DATETIME DEFAULT NULL COMMENT '最后登录时间',
  `login_count` INT UNSIGNED DEFAULT 0 COMMENT '登录次数',
  `wechat_subscribed` TINYINT(1) DEFAULT 0 COMMENT '公众号关注状态',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` DATETIME DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='超级管理员表';

-- 初始化超级管理员账号
INSERT INTO `admin` (`username`, `nickname`, `password`, `is_super`, `is_first_login`, `init_completed`) VALUES
('admin',  '超级管理员', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1, 0),
('admin2', '超级管理员2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1, 0);
-- 密码: 1234567 (bcrypt hash)

-- ============================================================
-- 2. 管理员角色表 (RBAC)
-- ============================================================
DROP TABLE IF EXISTS `admin_role`;
CREATE TABLE `admin_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL COMMENT '角色名称',
  `description` VARCHAR(255) DEFAULT '' COMMENT '角色描述',
  `permissions` JSON DEFAULT NULL COMMENT '权限JSON [{"module":"user","actions":["view","edit"]}]',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员角色表';

-- 默认角色
INSERT INTO `admin_role` (`name`, `description`, `permissions`) VALUES
('超级管理员', '拥有全部权限', '{"all":true}'),
('运营管理员', '用户管理+订单管理+资金管理', '{"user":["view","edit","ban"],"order":["view","edit","refund"],"withdraw":["view","audit"],"appeal":["view","handle"]}'),
('客服管理员', '用户管理+申诉处理+聊天审计', '{"user":["view"],"appeal":["view","handle"],"chat":["view","audit"]}'),
('财务管理员', '资金管理+提现审核', '{"withdraw":["view","audit"],"order":["view"],"finance":["view","export"]}');

-- ============================================================
-- 3. 管理员密码历史表
-- ============================================================
DROP TABLE IF EXISTS `admin_password_history`;
CREATE TABLE `admin_password_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '管理员ID',
  `password` VARCHAR(255) NOT NULL COMMENT '历史密码hash',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员密码历史表（保留最近5次）';

-- ============================================================
-- 4. WebAuthn 通行密钥表
-- ============================================================
DROP TABLE IF EXISTS `admin_webauthn`;
CREATE TABLE `admin_webauthn` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `credential_id` VARCHAR(255) NOT NULL COMMENT '凭证ID',
  `public_key` TEXT NOT NULL COMMENT '公钥',
  `device_name` VARCHAR(128) DEFAULT '' COMMENT '设备名称',
  `last_used_time` DATETIME DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_credential_id` (`credential_id`),
  KEY `idx_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebAuthn通行密钥表';

-- ============================================================
-- 5. 用户表（玩家/打手/分销商/派单员/内置管理员）
-- ============================================================
DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1玩家 2打手 3分销商 4派单员 5内置管理员',
  `openid` VARCHAR(128) DEFAULT '' COMMENT '微信openid',
  `unionid` VARCHAR(128) DEFAULT '' COMMENT '微信unionid',
  `nickname` VARCHAR(64) DEFAULT '' COMMENT '昵称',
  `avatar` VARCHAR(512) DEFAULT '' COMMENT '头像',
  `phone` VARCHAR(20) DEFAULT '' COMMENT '手机号',
  `phone_encrypted` VARCHAR(255) DEFAULT '' COMMENT '手机号加密存储',
  `is_phone_abandoned` TINYINT(1) DEFAULT 0 COMMENT '手机号是否被二次放号回收',
  `password` VARCHAR(255) DEFAULT '' COMMENT '密码（内置管理员）',
  `email` VARCHAR(128) DEFAULT '' COMMENT '邮箱',
  `gender` TINYINT DEFAULT 0 COMMENT '0未知 1男 2女',
  `birthday` DATE DEFAULT NULL COMMENT '出生日期',
  `real_name` VARCHAR(64) DEFAULT '' COMMENT '真实姓名',
  `id_card` VARCHAR(255) DEFAULT '' COMMENT '身份证号（加密）',
  `is_real_verified` TINYINT(1) DEFAULT 0 COMMENT '是否实名认证',
  `is_minor` TINYINT(1) DEFAULT 0 COMMENT '是否未成年人',
  `age` INT DEFAULT 0 COMMENT '年龄',
  `credit_score` INT DEFAULT 100 COMMENT '信用分 初始100',
  `balance` BIGINT NOT NULL DEFAULT 0 COMMENT '余额（分）',
  `frozen_balance` BIGINT NOT NULL DEFAULT 0 COMMENT '冻结金额（分）',
  `total_income` BIGINT NOT NULL DEFAULT 0 COMMENT '累计收入（分）',
  `invite_code` VARCHAR(32) DEFAULT '' COMMENT '我的邀请码',
  `invite_code_bind` VARCHAR(32) DEFAULT '' COMMENT '绑定的邀请码（终身有效）',
  `parent_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '上级分销商ID',
  `grandparent_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '上上级分销商ID',
  `distributor_level` TINYINT DEFAULT 0 COMMENT '分销商等级 1一级 2二级',
  `online_status` TINYINT DEFAULT 0 COMMENT '在线状态 0离线 1在线 2忙碌',
  `is_active` TINYINT DEFAULT 1 COMMENT '活跃探针状态 1活跃 0非活跃',
  `last_active_time` DATETIME DEFAULT NULL COMMENT '最后活跃时间',
  `last_login_ip` VARCHAR(64) DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` DATETIME DEFAULT NULL COMMENT '最后登录时间',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `ban_reason` VARCHAR(255) DEFAULT '' COMMENT '封禁原因',
  `ban_until` DATETIME DEFAULT NULL COMMENT '封禁截止时间',
  `is_blacklist` TINYINT(1) DEFAULT 0 COMMENT '是否黑名单',
  `blacklist_reason` VARCHAR(255) DEFAULT '' COMMENT '黑名单原因',
  `blacklist_until` DATETIME DEFAULT NULL COMMENT '黑名单截止时间',
  `service_agreed` TINYINT(1) DEFAULT 0 COMMENT '同意服务协议',
  `privacy_agreed` TINYINT(1) DEFAULT 0 COMMENT '同意隐私政策',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_openid` (`openid`),
  KEY `idx_phone` (`phone`),
  KEY `idx_user_type` (`user_type`),
  KEY `idx_status` (`status`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_invite_code` (`invite_code`),
  KEY `idx_invite_code_bind` (`invite_code_bind`),
  KEY `idx_is_real_verified` (`is_real_verified`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ============================================================
-- 6. 实名认证活体检测记录表
-- ============================================================
DROP TABLE IF EXISTS `real_verify_log`;
CREATE TABLE `real_verify_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `real_name` VARCHAR(64) NOT NULL COMMENT '姓名',
  `id_card` VARCHAR(255) NOT NULL COMMENT '身份证号（加密）',
  `face_session_id` VARCHAR(128) DEFAULT '' COMMENT '活体检测会话ID',
  `face_result` JSON DEFAULT NULL COMMENT '活体检测结果JSON',
  `compare_score` DECIMAL(5,2) DEFAULT NULL COMMENT '人脸比对分数',
  `is_minor` TINYINT(1) DEFAULT 0 COMMENT '是否未成年',
  `age` INT DEFAULT 0 COMMENT '计算年龄',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待处理 1通过 2失败',
  `fail_reason` VARCHAR(255) DEFAULT '' COMMENT '失败原因',
  `request_ip` VARCHAR(64) DEFAULT '' COMMENT '请求IP',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_id_card` (`id_card`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='实名认证活体检测记录表';

-- ============================================================
-- 7. 活体检测频控表
-- ============================================================
DROP TABLE IF EXISTS `face_verify_rate_limit`;
CREATE TABLE `face_verify_rate_limit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT 0,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `count` INT UNSIGNED NOT NULL DEFAULT 1,
  `verify_date` DATE NOT NULL COMMENT '检测日期',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`, `verify_date`),
  KEY `idx_ip_date` (`ip`, `verify_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活体检测频控表';

-- ============================================================
-- 8. 未成年人监护人验证表
-- ============================================================
DROP TABLE IF EXISTS `guardian_verify`;
CREATE TABLE `guardian_verify` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '未成年人用户ID',
  `guardian_name` VARCHAR(64) NOT NULL COMMENT '监护人姓名',
  `guardian_id_card` VARCHAR(255) NOT NULL COMMENT '监护人身份证号（加密）',
  `guardian_phone` VARCHAR(20) DEFAULT '' COMMENT '监护人手机号',
  `face_session_id` VARCHAR(128) DEFAULT '' COMMENT '监护人活体会话ID',
  `face_result` JSON DEFAULT NULL COMMENT '活体结果',
  `order_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联订单ID',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待验证 1通过 2失败',
  `fail_reason` VARCHAR(255) DEFAULT '' COMMENT '失败原因',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='监护人活体验证表';

-- ============================================================
-- 9. CA电子签名记录表
-- ============================================================
DROP TABLE IF EXISTS `electronic_signature`;
CREATE TABLE `electronic_signature` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '关联订单ID',
  `face_session_id` VARCHAR(128) DEFAULT '' COMMENT '活体检测会话ID（司法存证）',
  `sign_data` JSON DEFAULT NULL COMMENT '签名数据',
  `certificate_sn` VARCHAR(128) DEFAULT '' COMMENT 'CFCA证书序列号',
  `sign_content` TEXT COMMENT '签署内容（免责声明全文）',
  `sign_time` DATETIME DEFAULT NULL COMMENT '签署时间',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待签署 1已签署 2已过期',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CA电子签名记录表';

-- ============================================================
-- 10. 邀请码表
-- ============================================================
DROP TABLE IF EXISTS `invite_code`;
CREATE TABLE `invite_code` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL COMMENT '邀请码',
  `role_type` TINYINT NOT NULL COMMENT '绑定角色 2打手 3分销商 4派单员 5内置管理员',
  `batch_no` VARCHAR(64) DEFAULT '' COMMENT '批次号',
  `max_use_count` INT UNSIGNED DEFAULT 1 COMMENT '最大使用次数',
  `used_count` INT UNSIGNED DEFAULT 0 COMMENT '已使用次数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1有效 2已用完 3已作废',
  `creator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '创建者管理员ID',
  `expire_time` DATETIME DEFAULT NULL COMMENT '过期时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_role_type` (`role_type`),
  KEY `idx_status` (`status`),
  KEY `idx_batch_no` (`batch_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请码表';

-- ============================================================
-- 11. 邀请码绑定记录表
-- ============================================================
DROP TABLE IF EXISTS `invite_bind_log`;
CREATE TABLE `invite_bind_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `invite_code` VARCHAR(32) NOT NULL,
  `bind_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1首次绑定 2身份升级',
  `old_user_type` TINYINT DEFAULT 0 COMMENT '旧身份类型',
  `new_user_type` TINYINT NOT NULL COMMENT '新身份类型',
  `operator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '解绑操作者（超级管理员）',
  `unbind_reason` VARCHAR(255) DEFAULT '' COMMENT '解绑原因',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_invite_code` (`invite_code`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请码绑定记录表';

-- ============================================================
-- 12. 游戏服务类型表
-- ============================================================
DROP TABLE IF EXISTS `service_type`;
CREATE TABLE `service_type` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT '服务名称',
  `icon` VARCHAR(512) DEFAULT '' COMMENT '图标',
  `description` VARCHAR(512) DEFAULT '' COMMENT '服务描述',
  `game_name` VARCHAR(64) NOT NULL COMMENT '游戏名称',
  `base_price` BIGINT NOT NULL DEFAULT 0 COMMENT '基础价格（分）',
  `price_unit` VARCHAR(32) DEFAULT '局' COMMENT '计价单位 局/小时',
  `sort` INT DEFAULT 0 COMMENT '排序',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1上架 0下架',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_game_name` (`game_name`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='游戏服务类型表';

-- ============================================================
-- 13. 打手服务配置表
-- ============================================================
DROP TABLE IF EXISTS `player_service`;
CREATE TABLE `player_service` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '打手用户ID',
  `service_type_id` BIGINT UNSIGNED NOT NULL COMMENT '服务类型ID',
  `price` BIGINT NOT NULL DEFAULT 0 COMMENT '自定义价格（分）',
  `game_rank` VARCHAR(64) DEFAULT '' COMMENT '游戏段位',
  `game_region` VARCHAR(64) DEFAULT '' COMMENT '游戏大区',
  `game_id` VARCHAR(64) DEFAULT '' COMMENT '游戏ID',
  `tags` JSON DEFAULT NULL COMMENT '标签 ["国服","连胜"]',
  `description` VARCHAR(512) DEFAULT '' COMMENT '个人描述',
  `good_rate` DECIMAL(3,2) DEFAULT 0.00 COMMENT '好评率',
  `order_count` INT UNSIGNED DEFAULT 0 COMMENT '完成订单数',
  `avg_accept_time` INT UNSIGNED DEFAULT 0 COMMENT '平均接单速度（秒）',
  `online_hours` DECIMAL(10,2) DEFAULT 0.00 COMMENT '在线时长（小时）',
  `weight_score` DECIMAL(10,4) DEFAULT 0.0000 COMMENT '权重分',
  `reject_count` INT UNSIGNED DEFAULT 0 COMMENT '当日拒单次数',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1在线 0下架',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_service_type_id` (`service_type_id`),
  KEY `idx_status` (`status`),
  KEY `idx_weight_score` (`weight_score`),
  KEY `idx_good_rate` (`good_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='打手服务配置表';

-- ============================================================
-- 14. 订单表
-- ============================================================
DROP TABLE IF EXISTS `order`;
CREATE TABLE `order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(32) NOT NULL COMMENT '订单编号',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '下单用户ID',
  `player_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '打手用户ID',
  `service_type_id` BIGINT UNSIGNED NOT NULL COMMENT '服务类型ID',
  `player_service_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '打手服务ID',
  `game_name` VARCHAR(64) DEFAULT '' COMMENT '游戏名称',
  `service_name` VARCHAR(128) DEFAULT '' COMMENT '服务名称',
  `amount` BIGINT NOT NULL DEFAULT 0 COMMENT '订单金额（分）',
  `discount_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '优惠金额（分）',
  `paid_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '实付金额（分）',
  `platform_fee` BIGINT NOT NULL DEFAULT 0 COMMENT '平台抽成（分）',
  `player_income` BIGINT NOT NULL DEFAULT 0 COMMENT '打手收入（分）',
  `distributor_commission` BIGINT NOT NULL DEFAULT 0 COMMENT '分销佣金（分）',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待接单 1已接单 2进行中 3待验收 4已完成 5待结算 6已结算 7已取消 8已退款 9大额验证失败 10超时终止',
  `cancel_reason` VARCHAR(255) DEFAULT '' COMMENT '取消原因',
  `refund_reason` VARCHAR(255) DEFAULT '' COMMENT '退款原因',
  `is_large_amount` TINYINT(1) DEFAULT 0 COMMENT '是否大额订单（需监护人验证）',
  `is_guardian_verified` TINYINT(1) DEFAULT 0 COMMENT '是否通过监护人验证',
  `is_signed` TINYINT(1) DEFAULT 0 COMMENT '是否电子签',
  `remark` VARCHAR(512) DEFAULT '' COMMENT '订单备注',
  `dispatch_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '派单员ID',
  `dispatch_time` DATETIME DEFAULT NULL COMMENT '派单时间',
  `accept_time` DATETIME DEFAULT NULL COMMENT '接单时间',
  `start_time` DATETIME DEFAULT NULL COMMENT '开始服务时间',
  `complete_time` DATETIME DEFAULT NULL COMMENT '完成时间',
  `settle_time` DATETIME DEFAULT NULL COMMENT '结算时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `idx_status` (`status`),
  KEY `idx_dispatch_id` (`dispatch_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_settle_time` (`settle_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表';

-- ============================================================
-- 15. 订单状态流转日志表
-- ============================================================
DROP TABLE IF EXISTS `order_status_log`;
CREATE TABLE `order_status_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `from_status` TINYINT NOT NULL COMMENT '变更前状态',
  `to_status` TINYINT NOT NULL COMMENT '变更后状态',
  `operator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '操作人ID',
  `operator_type` TINYINT DEFAULT 0 COMMENT '操作人类型 1用户 2打手 3管理员',
  `remark` VARCHAR(512) DEFAULT '' COMMENT '备注',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单状态流转日志表';

-- ============================================================
-- 16. 支付记录表
-- ============================================================
DROP TABLE IF EXISTS `payment`;
CREATE TABLE `payment` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_no` VARCHAR(32) NOT NULL COMMENT '支付流水号',
  `order_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` BIGINT NOT NULL COMMENT '支付金额（分）',
  `pay_type` VARCHAR(32) NOT NULL DEFAULT 'wechat' COMMENT '支付方式 wechat',
  `transaction_id` VARCHAR(64) DEFAULT '' COMMENT '微信支付交易号',
  `idempotent_key` VARCHAR(128) NOT NULL COMMENT '幂等键',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待支付 1支付成功 2支付失败 3已退款',
  `refund_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '退款金额（分）',
  `refund_time` DATETIME DEFAULT NULL COMMENT '退款时间',
  `pay_time` DATETIME DEFAULT NULL COMMENT '支付成功时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_no` (`payment_no`),
  UNIQUE KEY `uk_idempotent_key` (`idempotent_key`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付记录表';

-- ============================================================
-- 17. 提现申请表
-- ============================================================
DROP TABLE IF EXISTS `withdraw`;
CREATE TABLE `withdraw` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `withdraw_no` VARCHAR(32) NOT NULL COMMENT '提现单号',
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` BIGINT NOT NULL COMMENT '提现金额（分）',
  `fee` BIGINT NOT NULL DEFAULT 0 COMMENT '手续费（分）',
  `actual_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '实际到账金额（分）',
  `bank_name` VARCHAR(128) DEFAULT '' COMMENT '银行名称',
  `bank_card_no` VARCHAR(255) NOT NULL COMMENT '银行卡号（加密）',
  `account_name` VARCHAR(64) NOT NULL COMMENT '开户人姓名',
  `id_card` VARCHAR(255) NOT NULL COMMENT '身份证号（加密）',
  `three_element_verified` TINYINT(1) DEFAULT 0 COMMENT '三要素验证 0未验证 1通过 2失败',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1审核通过 2审核拒绝 3已打款',
  `audit_remark` VARCHAR(255) DEFAULT '' COMMENT '审核备注',
  `auditor_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '审核人ID',
  `audit_time` DATETIME DEFAULT NULL COMMENT '审核时间',
  `freeze_days` INT DEFAULT 3 COMMENT '冻结天数 T+3',
  `unfreeze_time` DATETIME DEFAULT NULL COMMENT '解冻时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_withdraw_no` (`withdraw_no`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_unfreeze_time` (`unfreeze_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提现申请表';

-- ============================================================
-- 18. 提现配置表
-- ============================================================
DROP TABLE IF EXISTS `withdraw_config`;
CREATE TABLE `withdraw_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `min_amount` BIGINT NOT NULL DEFAULT 1000 COMMENT '最低提现金额（分）',
  `max_amount` BIGINT NOT NULL DEFAULT 500000 COMMENT '最高提现金额（分）',
  `fee_rate` DECIMAL(5,4) DEFAULT 0.0000 COMMENT '手续费率',
  `freeze_days` INT DEFAULT 3 COMMENT 'T+N冻结天数',
  `min_interval_hours` INT DEFAULT 24 COMMENT '提现间隔（小时）',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提现配置表';

INSERT INTO `withdraw_config` (`min_amount`, `max_amount`, `fee_rate`, `freeze_days`, `min_interval_hours`) VALUES
(1000, 500000, 0.0000, 3, 24);

-- ============================================================
-- 19. 分销佣金记录表
-- ============================================================
DROP TABLE IF EXISTS `distributor_commission`;
CREATE TABLE `distributor_commission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '分销商用户ID',
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `from_user_id` BIGINT UNSIGNED NOT NULL COMMENT '下级用户ID',
  `level` TINYINT NOT NULL COMMENT '分销层级 1一级 2二级',
  `commission_rate` DECIMAL(5,4) NOT NULL COMMENT '佣金比例',
  `commission_amount` BIGINT NOT NULL COMMENT '佣金金额（分）',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待发放 1已发放 2已取消',
  `settle_time` DATETIME DEFAULT NULL COMMENT '发放时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_from_user_id` (`from_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分销佣金记录表';

-- ============================================================
-- 20. 分销首单奖励记录表
-- ============================================================
DROP TABLE IF EXISTS `distributor_first_reward`;
CREATE TABLE `distributor_first_reward` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '分销商ID',
  `from_user_id` BIGINT UNSIGNED NOT NULL COMMENT '下级用户ID',
  `reward_amount` BIGINT NOT NULL COMMENT '奖励金额（分）',
  `is_qualified` TINYINT(1) DEFAULT 0 COMMENT '是否达标（实名+3单）',
  `qualify_order_count` INT DEFAULT 0 COMMENT '达标订单数',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0未达标 1已发放',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_from_user_id` (`from_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分销首单奖励记录表';

-- ============================================================
-- 21. 评价表
-- ============================================================
DROP TABLE IF EXISTS `evaluation`;
CREATE TABLE `evaluation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '评价人ID',
  `player_id` BIGINT UNSIGNED NOT NULL COMMENT '被评价打手ID',
  `rating` TINYINT NOT NULL COMMENT '评分 1-5',
  `content` VARCHAR(512) DEFAULT '' COMMENT '评价内容',
  `tags` JSON DEFAULT NULL COMMENT '标签',
  `is_anonymous` TINYINT(1) DEFAULT 0 COMMENT '是否匿名',
  `cooling_period` TINYINT(1) DEFAULT 1 COMMENT '冷静期 1待展示 0已展示',
  `cooling_end_time` DATETIME DEFAULT NULL COMMENT '冷静期结束时间',
  `appeal_status` TINYINT DEFAULT 0 COMMENT '0无申诉 1申诉中 2申诉成功 3申诉失败',
  `appeal_reason` VARCHAR(512) DEFAULT '' COMMENT '申诉原因',
  `appeal_result` VARCHAR(512) DEFAULT '' COMMENT '申诉结果',
  `auditor_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '审核人ID',
  `audit_time` DATETIME DEFAULT NULL COMMENT '审核时间',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1正常 0已删除',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `idx_rating` (`rating`),
  KEY `idx_cooling_period` (`cooling_period`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='评价表';

-- ============================================================
-- 22. 打赏记录表
-- ============================================================
DROP TABLE IF EXISTS `reward`;
CREATE TABLE `reward` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '打赏人ID',
  `player_id` BIGINT UNSIGNED NOT NULL COMMENT '被打赏打手ID',
  `amount` BIGINT NOT NULL COMMENT '打赏金额（分）',
  `message` VARCHAR(255) DEFAULT '' COMMENT '打赏留言',
  `payment_no` VARCHAR(32) DEFAULT '' COMMENT '支付流水号',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待支付 1已支付',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_player_id` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='打赏记录表';

-- ============================================================
-- 23. 超时规则引擎配置表
-- ============================================================
DROP TABLE IF EXISTS `timeout_rule`;
CREATE TABLE `timeout_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL COMMENT '规则名称',
  `rule_type` VARCHAR(32) NOT NULL COMMENT '规则类型 accept_timeout/complete_timeout/verify_timeout',
  `from_status` TINYINT NOT NULL COMMENT '触发状态',
  `to_status` TINYINT NOT NULL COMMENT '目标状态',
  `timeout_seconds` INT UNSIGNED NOT NULL COMMENT '超时秒数',
  `is_preset` TINYINT(1) DEFAULT 1 COMMENT '是否预置规则',
  `sort` INT DEFAULT 0 COMMENT '排序',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rule_type` (`rule_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='超时规则引擎配置表';

-- 预置超时规则
INSERT INTO `timeout_rule` (`name`, `rule_type`, `from_status`, `to_status`, `timeout_seconds`, `is_preset`) VALUES
('待接单超时30分钟', 'accept_timeout', 0, 10, 1800, 1),
('进行中超时24小时', 'complete_timeout', 2, 10, 86400, 1),
('待验收超时72小时', 'verify_timeout', 3, 4, 259200, 1);

-- ============================================================
-- 24. IM聊天会话表（订单内双向临时会话：玩家↔打手）
-- session_type: 1=订单私聊 2=群聊 3=售后申诉
-- ============================================================
DROP TABLE IF EXISTS `chat_session`;
CREATE TABLE `chat_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_no` VARCHAR(64) NOT NULL COMMENT '会话编号',
  `session_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1订单私聊 2群聊 3售后申诉',
  `order_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联订单ID',
  `user_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '用户ID（玩家）',
  `player_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '打手ID',
  `group_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联群聊ID（session_type=2时）',
  `last_message` VARCHAR(512) DEFAULT '' COMMENT '最后一条消息摘要',
  `last_message_type` TINYINT DEFAULT 1 COMMENT '1文字 2语音 3图片 4系统消息',
  `last_message_time` DATETIME DEFAULT NULL COMMENT '最后消息时间',
  `unread_user_count` INT UNSIGNED DEFAULT 0 COMMENT '用户未读数',
  `unread_player_count` INT UNSIGNED DEFAULT 0 COMMENT '打手未读数',
  `is_platform_intervened` TINYINT(1) DEFAULT 0 COMMENT '是否平台已介入（仅售后会话）',
  `intervene_time` DATETIME DEFAULT NULL COMMENT '平台介入时间',
  `intervene_type` TINYINT DEFAULT 0 COMMENT '介入方式 1关键词自动 2人工申请',
  `intervene_status` TINYINT DEFAULT 0 COMMENT '介入状态 0未介入 1介入中 2已解除',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1活跃 0已关闭',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_no` (`session_no`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_session_type` (`session_type`),
  KEY `idx_last_message_time` (`last_message_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IM聊天会话表（三大会话体系）';

-- ============================================================
-- 25. IM聊天消息表
-- ============================================================
DROP TABLE IF EXISTS `chat_message`;
CREATE TABLE `chat_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL COMMENT '发送者ID',
  `sender_type` TINYINT NOT NULL COMMENT '1用户 2打手',
  `receiver_id` BIGINT UNSIGNED NOT NULL COMMENT '接收者ID',
  `message_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1文字 2语音 3图片 4系统消息',
  `content` TEXT COMMENT '文字内容',
  `media_url` VARCHAR(512) DEFAULT '' COMMENT '媒体文件URL',
  `media_duration` INT UNSIGNED DEFAULT 0 COMMENT '语音时长（秒）',
  `media_signed_url` VARCHAR(512) DEFAULT '' COMMENT '带签名的媒体URL',
  `sign_expire_time` DATETIME DEFAULT NULL COMMENT '签名过期时间',
  `asr_text` TEXT COMMENT 'ASR转文字内容',
  `ocr_text` TEXT COMMENT 'OCR识别内容',
  `nlp_result` JSON DEFAULT NULL COMMENT 'NLP敏感词过滤结果',
  `is_blocked` TINYINT(1) DEFAULT 0 COMMENT '是否被敏感词拦截',
  `block_reason` VARCHAR(255) DEFAULT '' COMMENT '拦截原因',
  `is_read` TINYINT(1) DEFAULT 0 COMMENT '是否已读',
  `read_time` DATETIME DEFAULT NULL COMMENT '已读时间',
  `is_recalled` TINYINT(1) DEFAULT 0 COMMENT '是否撤回',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_receiver_id` (`receiver_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IM聊天消息表';

-- ============================================================
-- 26. 离线消息表（Redis Sorted Set 备份）
-- ============================================================
DROP TABLE IF EXISTS `offline_message`;
CREATE TABLE `offline_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收者ID',
  `message_id` BIGINT UNSIGNED NOT NULL COMMENT '消息ID',
  `is_pushed` TINYINT(1) DEFAULT 0 COMMENT '是否已推送',
  `push_time` DATETIME DEFAULT NULL COMMENT '推送时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_pushed` (`is_pushed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='离线消息表';

-- ============================================================
-- 27. 敏感词库表
-- ============================================================
DROP TABLE IF EXISTS `sensitive_word`;
CREATE TABLE `sensitive_word` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `word` VARCHAR(128) NOT NULL COMMENT '敏感词',
  `category` VARCHAR(32) NOT NULL DEFAULT 'general' COMMENT '分类 general/contact/fraud',
  `match_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1精确匹配 2正则匹配 3变体匹配',
  `regex_pattern` VARCHAR(255) DEFAULT '' COMMENT '正则表达式',
  `action` TINYINT NOT NULL DEFAULT 1 COMMENT '1拦截 2替换 3仅记录',
  `replace_text` VARCHAR(32) DEFAULT '***' COMMENT '替换文本',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_word` (`word`),
  KEY `idx_category` (`category`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='敏感词库表';

-- 预置防飞单正则
INSERT INTO `sensitive_word` (`word`, `category`, `match_type`, `regex_pattern`, `action`) VALUES
('扣扣', 'contact', 3, '(扣扣|抠抠|寇寇)', 1),
('QQ', 'contact', 3, '(Q{1,2}|q{1,2}|扣扣号|企鹅)', 1),
('微信', 'contact', 3, '(微信|威信|薇信|v信|VX|vx|WX|wx)', 1),
('手机', 'contact', 3, '(手机|电话|号码|联系|+86|1[3-9]\\d{9})', 1),
('贰叁肆', 'contact', 3, '(贰叁肆|二三四|234|②③④)', 1);

-- ============================================================
-- 28. 聊天审计日志表
-- ============================================================
DROP TABLE IF EXISTS `chat_audit_log`;
CREATE TABLE `chat_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `audit_type` VARCHAR(32) NOT NULL COMMENT 'asr/ocr/nlp/regex',
  `audit_result` JSON DEFAULT NULL COMMENT '审计结果JSON',
  `is_risk` TINYINT(1) DEFAULT 0 COMMENT '是否风险',
  `risk_level` TINYINT DEFAULT 0 COMMENT '风险等级 1低 2中 3高',
  `action` VARCHAR(32) DEFAULT '' COMMENT '处理动作 block/freeze/record',
  `freeze_until` DATETIME DEFAULT NULL COMMENT '冻结截止时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_is_risk` (`is_risk`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天审计日志表';

-- ============================================================
-- 29. AI风险用户表
-- ============================================================
DROP TABLE IF EXISTS `risk_user`;
CREATE TABLE `risk_user` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `risk_type` VARCHAR(32) NOT NULL COMMENT 'fraud/spam/abuse',
  `risk_level` TINYINT NOT NULL COMMENT '1低 2中 3高',
  `risk_score` DECIMAL(5,2) DEFAULT 0.00 COMMENT '风险评分',
  `risk_reason` VARCHAR(512) DEFAULT '' COMMENT '风险原因',
  `detected_at` DATETIME DEFAULT NULL COMMENT '检测时间',
  `is_handled` TINYINT(1) DEFAULT 0 COMMENT '是否已处理',
  `handler_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '处理人ID',
  `handle_remark` VARCHAR(255) DEFAULT '' COMMENT '处理备注',
  `handle_time` DATETIME DEFAULT NULL COMMENT '处理时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_risk_type` (`risk_type`),
  KEY `idx_risk_level` (`risk_level`),
  KEY `idx_is_handled` (`is_handled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI风险用户表';

-- ============================================================
-- 30. WebSocket连接记录表
-- ============================================================
DROP TABLE IF EXISTS `ws_connection`;
CREATE TABLE `ws_connection` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `connection_id` VARCHAR(64) NOT NULL COMMENT '连接ID',
  `client_id` VARCHAR(128) DEFAULT '' COMMENT '客户端标识',
  `ip` VARCHAR(64) DEFAULT '' COMMENT '连接IP',
  `user_agent` VARCHAR(512) DEFAULT '' COMMENT '客户端信息',
  `platform` VARCHAR(32) DEFAULT '' COMMENT '平台 mini_program/web',
  `last_ping_time` DATETIME DEFAULT NULL COMMENT '最后心跳时间',
  `is_online` TINYINT(1) DEFAULT 1 COMMENT '是否在线',
  `connect_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `disconnect_time` DATETIME DEFAULT NULL COMMENT '断开时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_connection_id` (`connection_id`),
  KEY `idx_is_online` (`is_online`),
  KEY `idx_last_ping_time` (`last_ping_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebSocket连接记录表';

-- ============================================================
-- 31. 手机号二次放号申诉表
-- ============================================================
DROP TABLE IF EXISTS `phone_appeal`;
CREATE TABLE `phone_appeal` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appeal_no` VARCHAR(32) NOT NULL COMMENT '申诉编号',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '新用户ID',
  `phone` VARCHAR(20) NOT NULL COMMENT '申诉手机号',
  `old_user_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '旧号主用户ID',
  `video_url` VARCHAR(512) DEFAULT '' COMMENT '运营商录屏URL',
  `operator_name` VARCHAR(64) DEFAULT '' COMMENT '运营商名称',
  `cert_owner_name` VARCHAR(64) DEFAULT '' COMMENT '实名认证姓名',
  `cert_owner_id_card` VARCHAR(255) DEFAULT '' COMMENT '实名认证身份证号',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1审核通过 2审核拒绝',
  `audit_remark` VARCHAR(255) DEFAULT '' COMMENT '审核备注',
  `auditor_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '审核人ID',
  `audit_time` DATETIME DEFAULT NULL COMMENT '审核时间',
  `reject_count` INT UNSIGNED DEFAULT 0 COMMENT '累计驳回次数',
  `lock_until` DATETIME DEFAULT NULL COMMENT 'Redis锁定期',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_appeal_no` (`appeal_no`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='手机号二次放号申诉表';

-- ============================================================
-- 32. 申诉沟通记录表
-- ============================================================
DROP TABLE IF EXISTS `appeal_communication`;
CREATE TABLE `appeal_communication` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appeal_id` BIGINT UNSIGNED NOT NULL,
  `appeal_type` VARCHAR(32) NOT NULL COMMENT 'phone/evaluation/other',
  `sender_id` BIGINT UNSIGNED NOT NULL COMMENT '发送者ID',
  `sender_type` TINYINT NOT NULL COMMENT '1用户 2管理员',
  `content` TEXT COMMENT '沟通内容',
  `attachments` JSON DEFAULT NULL COMMENT '附件',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appeal_id` (`appeal_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='申诉沟通记录表';

-- ============================================================
-- 33. 申诉催办记录表
-- ============================================================
DROP TABLE IF EXISTS `appeal_reminder`;
CREATE TABLE `appeal_reminder` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appeal_id` BIGINT UNSIGNED NOT NULL,
  `appeal_type` VARCHAR(32) NOT NULL,
  `reminder_level` TINYINT NOT NULL COMMENT '1订阅消息 2后台红点 3催办',
  `is_sent` TINYINT(1) DEFAULT 0 COMMENT '是否已发送',
  `sent_time` DATETIME DEFAULT NULL COMMENT '发送时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appeal_id` (`appeal_id`),
  KEY `idx_is_sent` (`is_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='申诉催办记录表';

-- ============================================================
-- 34. 系统配置表（热更新）
-- ============================================================
DROP TABLE IF EXISTS `system_config`;
CREATE TABLE `system_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_key` VARCHAR(64) NOT NULL COMMENT '配置键',
  `config_value` TEXT COMMENT '配置值',
  `config_type` VARCHAR(32) DEFAULT 'string' COMMENT 'string/int/json/bool',
  `description` VARCHAR(255) DEFAULT '' COMMENT '配置说明',
  `is_hot_update` TINYINT(1) DEFAULT 1 COMMENT '是否热更新',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

INSERT INTO `system_config` (`config_key`, `config_value`, `config_type`, `description`, `is_hot_update`) VALUES
('platform_fee_rate', '0.20', 'float', '平台抽成比例', 1),
('large_amount_threshold', '5000', 'int', '大额订单阈值（分）', 1),
('minor_single_limit', '5000', 'int', '未成年人单笔限额（分）', 1),
('minor_monthly_limit', '20000', 'int', '未成年人月累计限额（分）', 1),
('real_verify_switch', '1', 'bool', '实名认证开关', 1),
('ai_risk_switch', '1', 'bool', 'AI风控开关', 1),
('customer_service_wechat', 'wxid_abc123def456', 'string', '客服微信号', 1),
('service_agreement_url', '', 'string', '用户服务协议URL', 1),
('privacy_policy_url', '', 'string', '隐私政策URL', 1),
('api_version', '1.0.0', 'string', 'API版本号', 1),
('gray_whitelist', '[]', 'json', '灰度白名单用户ID', 1),
('gray_ratio', '0', 'float', '灰度放量比例', 1),
('refund_daily_limit_rate', '0.50', 'float', '单日退款熔断比例', 1),
('rate_limit_batch_per_second', '100', 'int', '批量操作每秒限流', 1),
('club_join_switch', '1', 'bool', '俱乐部入驻总开关（关闭后前端同步隐藏所有入驻入口，已入驻俱乐部不受影响）', 1);

-- ============================================================
-- 35. 操作日志表
-- ============================================================
DROP TABLE IF EXISTS `operation_log`;
CREATE TABLE `operation_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '管理员ID',
  `module` VARCHAR(64) NOT NULL COMMENT '操作模块',
  `action` VARCHAR(64) NOT NULL COMMENT '操作动作',
  `target_id` VARCHAR(64) DEFAULT '' COMMENT '操作对象ID',
  `content` TEXT COMMENT '操作内容JSON',
  `ip` VARCHAR(64) DEFAULT '' COMMENT '操作IP',
  `user_agent` VARCHAR(512) DEFAULT '' COMMENT '客户端信息',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_module` (`module`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志表';

-- ============================================================
-- 36. 导出日志表（含隐形水印）
-- ============================================================
DROP TABLE IF EXISTS `export_log`;
CREATE TABLE `export_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '导出人ID',
  `export_type` VARCHAR(64) NOT NULL COMMENT '导出类型',
  `file_name` VARCHAR(255) NOT NULL COMMENT '文件名',
  `file_path` VARCHAR(512) NOT NULL COMMENT '文件路径',
  `row_count` INT UNSIGNED DEFAULT 0 COMMENT '导出行数',
  `watermark_info` VARCHAR(255) DEFAULT '' COMMENT '隐形水印信息',
  `ip` VARCHAR(64) DEFAULT '' COMMENT '导出IP',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_export_type` (`export_type`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导出日志表';

-- ============================================================
-- 37. 风控日志表
-- ============================================================
DROP TABLE IF EXISTS `risk_control_log`;
CREATE TABLE `risk_control_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(32) NOT NULL COMMENT 'join_us_click/face_verify/refund/chat_block',
  `event_detail` JSON DEFAULT NULL COMMENT '事件详情',
  `ip` VARCHAR(64) DEFAULT '' COMMENT 'IP',
  `risk_level` TINYINT DEFAULT 0 COMMENT '风险等级',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风控日志表';

-- ============================================================
-- 38. 小程序内置管理端店铺配置表
-- ============================================================
DROP TABLE IF EXISTS `shop_config`;
CREATE TABLE `shop_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` BIGINT UNSIGNED NOT NULL COMMENT '内置管理员用户ID',
  `shop_name` VARCHAR(128) DEFAULT '' COMMENT '店铺名称',
  `shop_logo` VARCHAR(512) DEFAULT '' COMMENT '店铺Logo',
  `shop_banner` VARCHAR(512) DEFAULT '' COMMENT '店铺Banner',
  `shop_description` VARCHAR(512) DEFAULT '' COMMENT '店铺描述',
  `theme_color` VARCHAR(16) DEFAULT '#FF6B35' COMMENT '主题色',
  `announcement` VARCHAR(512) DEFAULT '' COMMENT '公告',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_user_id` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店铺装饰配置表';

-- ============================================================
-- 39. 备份记录表
-- ============================================================
DROP TABLE IF EXISTS `backup_record`;
CREATE TABLE `backup_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `backup_type` VARCHAR(32) NOT NULL COMMENT 'full/incremental',
  `file_name` VARCHAR(255) NOT NULL COMMENT '备份文件名',
  `file_path` VARCHAR(512) NOT NULL COMMENT '备份文件路径',
  `file_size` BIGINT UNSIGNED DEFAULT 0 COMMENT '文件大小（字节）',
  `encryption_key_id` VARCHAR(128) DEFAULT '' COMMENT 'AES-256加密密钥ID',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0进行中 1成功 2失败',
  `error_msg` VARCHAR(512) DEFAULT '' COMMENT '错误信息',
  `operator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '操作人ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backup_type` (`backup_type`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='备份记录表';

-- ============================================================
-- 40. 恢复记录表
-- ============================================================
DROP TABLE IF EXISTS `restore_record`;
CREATE TABLE `restore_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `backup_id` BIGINT UNSIGNED NOT NULL COMMENT '备份记录ID',
  `restore_point` DATETIME NOT NULL COMMENT '恢复时间点',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0进行中 1成功 2失败',
  `error_msg` VARCHAR(512) DEFAULT '' COMMENT '错误信息',
  `operator_id` BIGINT UNSIGNED NOT NULL COMMENT '操作人ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backup_id` (`backup_id`),
  KEY `idx_operator_id` (`operator_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='恢复记录表';

-- ============================================================
-- 41. 灰度发布配置表
-- ============================================================
DROP TABLE IF EXISTS `gray_release`;
CREATE TABLE `gray_release` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_version` VARCHAR(16) NOT NULL COMMENT 'API版本号',
  `whitelist` JSON DEFAULT NULL COMMENT '白名单用户ID',
  `ratio` DECIMAL(5,4) DEFAULT 0.0000 COMMENT '放量比例',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待发布 1灰度中 2全量 3已回滚',
  `error_rate` DECIMAL(5,4) DEFAULT 0.0000 COMMENT '错误率',
  `auto_rollback` TINYINT(1) DEFAULT 1 COMMENT '错误率飙升自动回滚',
  `rollback_threshold` DECIMAL(5,4) DEFAULT 0.0500 COMMENT '回滚阈值',
  `publish_time` DATETIME DEFAULT NULL COMMENT '发布时间',
  `rollback_time` DATETIME DEFAULT NULL COMMENT '回滚时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_version` (`api_version`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='灰度发布配置表';

-- ============================================================
-- 42. 监控告警记录表
-- ============================================================
DROP TABLE IF EXISTS `monitor_alert`;
CREATE TABLE `monitor_alert` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type` VARCHAR(32) NOT NULL COMMENT 'ws_connection/disk_usage/version_mismatch/api_error',
  `alert_level` TINYINT NOT NULL COMMENT '1信息 2警告 3严重',
  `alert_content` JSON DEFAULT NULL COMMENT '告警内容',
  `is_resolved` TINYINT(1) DEFAULT 0 COMMENT '是否已解决',
  `resolved_time` DATETIME DEFAULT NULL COMMENT '解决时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_alert_level` (`alert_level`),
  KEY `idx_is_resolved` (`is_resolved`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='监控告警记录表';

-- ============================================================
-- 43. 邮件验证码表
-- ============================================================
DROP TABLE IF EXISTS `email_verify_code`;
CREATE TABLE `email_verify_code` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(128) NOT NULL COMMENT '邮箱',
  `code` VARCHAR(10) NOT NULL COMMENT '验证码',
  `type` VARCHAR(32) NOT NULL DEFAULT 'bind' COMMENT 'bind/forget_password/reset',
  `is_used` TINYINT(1) DEFAULT 0 COMMENT '是否已使用',
  `expire_time` DATETIME NOT NULL COMMENT '过期时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_type` (`type`),
  KEY `idx_expire_time` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮件验证码表';

-- ============================================================
-- 44. 初始化日志表
-- ============================================================
DROP TABLE IF EXISTS `init_log`;
CREATE TABLE `init_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '管理员ID',
  `init_type` VARCHAR(32) NOT NULL COMMENT 'password_change/email_bind/init_complete',
  `init_detail` JSON DEFAULT NULL COMMENT '初始化详情',
  `ip` VARCHAR(64) DEFAULT '' COMMENT 'IP',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_init_type` (`init_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='初始化日志表';

-- ============================================================
-- 45. 三级催办降级记录表
-- ============================================================
DROP TABLE IF EXISTS `reminder_escalation`;
CREATE TABLE `reminder_escalation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_type` VARCHAR(32) NOT NULL COMMENT 'appeal/withdraw/order',
  `target_id` BIGINT UNSIGNED NOT NULL COMMENT '目标ID',
  `current_level` TINYINT NOT NULL DEFAULT 1 COMMENT '当前催办级别 1订阅消息 2红点 3催办',
  `level_1_time` DATETIME DEFAULT NULL COMMENT '订阅消息时间',
  `level_2_time` DATETIME DEFAULT NULL COMMENT '红点时间',
  `level_3_time` DATETIME DEFAULT NULL COMMENT '催办时间',
  `is_completed` TINYINT(1) DEFAULT 0 COMMENT '是否已处理',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_is_completed` (`is_completed`),
  KEY `idx_current_level` (`current_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='三级催办降级记录表';

-- ============================================================
-- 46. 消息通知表
-- ============================================================
DROP TABLE IF EXISTS `notification`;
CREATE TABLE `notification` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收用户ID',
  `user_type` TINYINT NOT NULL COMMENT '1用户 2管理员',
  `title` VARCHAR(128) NOT NULL COMMENT '通知标题',
  `content` VARCHAR(512) DEFAULT '' COMMENT '通知内容',
  `type` VARCHAR(32) NOT NULL COMMENT 'system/order/chat/withdraw/appeal',
  `target_id` VARCHAR(64) DEFAULT '' COMMENT '关联目标ID',
  `is_read` TINYINT(1) DEFAULT 0 COMMENT '是否已读',
  `read_time` DATETIME DEFAULT NULL COMMENT '已读时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息通知表';

-- ============================================================
-- 47. 派单记录表
-- ============================================================
DROP TABLE IF EXISTS `dispatch_record`;
CREATE TABLE `dispatch_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `dispatcher_id` BIGINT UNSIGNED NOT NULL COMMENT '派单员ID',
  `player_id` BIGINT UNSIGNED NOT NULL COMMENT '指派的打手ID',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待接单 1已接单 2已拒绝 3超时',
  `dispatch_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '派单时间',
  `accept_time` DATETIME DEFAULT NULL COMMENT '接单时间',
  `reject_reason` VARCHAR(255) DEFAULT '' COMMENT '拒绝原因',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_dispatcher_id` (`dispatcher_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='派单记录表';

-- ============================================================
-- 48. 加入我们点击记录表
-- ============================================================
DROP TABLE IF EXISTS `join_us_log`;
CREATE TABLE `join_us_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `click_date` DATE NOT NULL COMMENT '点击日期',
  `click_count` INT UNSIGNED DEFAULT 1 COMMENT '当日点击次数',
  `ip` VARCHAR(64) DEFAULT '' COMMENT 'IP',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_click_date` (`click_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='加入我们点击记录表';

-- ============================================================
-- 49. 定时任务日志表
-- ============================================================
DROP TABLE IF EXISTS `cron_job_log`;
CREATE TABLE `cron_job_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_name` VARCHAR(64) NOT NULL COMMENT '任务名称',
  `job_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0执行中 1成功 2失败',
  `execution_time` DECIMAL(10,4) DEFAULT 0.0000 COMMENT '执行耗时（秒）',
  `error_msg` VARCHAR(1024) DEFAULT '' COMMENT '错误信息',
  `start_time` DATETIME DEFAULT NULL COMMENT '开始时间',
  `end_time` DATETIME DEFAULT NULL COMMENT '结束时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_name` (`job_name`),
  KEY `idx_job_status` (`job_status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务日志表';

-- ============================================================
-- 50. 打手活跃探针日志表
-- ============================================================
DROP TABLE IF EXISTS `player_probe_log`;
CREATE TABLE `player_probe_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '打手用户ID',
  `probe_type` VARCHAR(32) NOT NULL COMMENT 'ping/pong/miss',
  `probe_time` DATETIME NOT NULL COMMENT '探针时间',
  `miss_count` INT UNSIGNED DEFAULT 0 COMMENT '连续miss次数',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否活跃',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_probe_time` (`probe_time`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='打手活跃探针日志表';

-- ============================================================
-- 51. 熔断记录表
-- ============================================================
DROP TABLE IF EXISTS `circuit_breaker`;
CREATE TABLE `circuit_breaker` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `breaker_type` VARCHAR(32) NOT NULL COMMENT 'refund/payment/api',
  `trigger_value` DECIMAL(10,4) NOT NULL COMMENT '触发值',
  `threshold` DECIMAL(10,4) NOT NULL COMMENT '阈值',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0关闭 1半开 2全开',
  `open_time` DATETIME DEFAULT NULL COMMENT '熔断开启时间',
  `close_time` DATETIME DEFAULT NULL COMMENT '熔断关闭时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_breaker_type` (`breaker_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='熔断记录表';

-- ============================================================
-- 52. NTP时间同步记录表
-- ============================================================
DROP TABLE IF EXISTS `ntp_sync_log`;
CREATE TABLE `ntp_sync_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offset_seconds` DECIMAL(10,6) NOT NULL COMMENT '时间偏差（秒）',
  `is_corrected` TINYINT(1) DEFAULT 0 COMMENT '是否已校正',
  `ntp_server` VARCHAR(128) DEFAULT '' COMMENT 'NTP服务器',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='NTP时间同步记录表';

-- ============================================================
-- 53. 第三方API调用日志表
-- ============================================================
DROP TABLE IF EXISTS `third_party_api_log`;
CREATE TABLE `third_party_api_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_name` VARCHAR(64) NOT NULL COMMENT 'face_verify/electronic_sign/asr/ocr',
  `request_id` VARCHAR(128) NOT NULL COMMENT '请求ID',
  `request_data` JSON DEFAULT NULL COMMENT '请求数据（脱敏）',
  `response_data` JSON DEFAULT NULL COMMENT '响应数据（脱敏）',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0请求中 1成功 2失败',
  `retry_count` INT UNSIGNED DEFAULT 0 COMMENT '重试次数',
  `duration_ms` INT UNSIGNED DEFAULT 0 COMMENT '耗时（毫秒）',
  `error_msg` VARCHAR(512) DEFAULT '' COMMENT '错误信息',
  `is_alerted` TINYINT(1) DEFAULT 0 COMMENT '是否已告警',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_name` (`api_name`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='第三方API调用日志表';

-- ============================================================
-- 54. 第三方API补录记录表
-- ============================================================
DROP TABLE IF EXISTS `third_party_retry_queue`;
CREATE TABLE `third_party_retry_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_name` VARCHAR(64) NOT NULL,
  `request_data` JSON NOT NULL COMMENT '请求数据',
  `retry_count` INT UNSIGNED DEFAULT 0 COMMENT '已重试次数',
  `max_retry` INT UNSIGNED DEFAULT 3 COMMENT '最大重试次数',
  `next_retry_time` DATETIME DEFAULT NULL COMMENT '下次重试时间',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待重试 1重试成功 2重试失败',
  `error_msg` VARCHAR(512) DEFAULT '' COMMENT '错误信息',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_name` (`api_name`),
  KEY `idx_status` (`status`),
  KEY `idx_next_retry_time` (`next_retry_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='第三方API补录重试队列表';

-- ============================================================
-- 55. 批量操作扫码确认表
-- ============================================================
DROP TABLE IF EXISTS `batch_operation_confirm`;
CREATE TABLE `batch_operation_confirm` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '操作管理员ID',
  `operation_type` VARCHAR(32) NOT NULL COMMENT '操作类型',
  `operation_data` JSON DEFAULT NULL COMMENT '操作数据摘要',
  `qr_token` VARCHAR(128) NOT NULL COMMENT '扫码Token',
  `confirm_admin_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '确认管理员ID',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待确认 1已确认 2已过期 3已取消',
  `expire_time` DATETIME NOT NULL COMMENT '过期时间',
  `confirm_time` DATETIME DEFAULT NULL COMMENT '确认时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_qr_token` (`qr_token`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='批量操作扫码确认表';

-- ============================================================
-- 56. 平台官方账号表
-- 仅超级管理员 Web 后台可创建，拥有全平台最高权限
-- ============================================================
DROP TABLE IF EXISTS `platform_official_account`;
CREATE TABLE `platform_official_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_no` VARCHAR(32) NOT NULL COMMENT '平台账号编号（OFFICIAL_xxxxx）',
  `nickname` VARCHAR(64) NOT NULL DEFAULT '平台官方' COMMENT '昵称',
  `avatar` VARCHAR(512) DEFAULT '' COMMENT '头像',
  `v_badge` TINYINT NOT NULL DEFAULT 1 COMMENT 'V标类型 1大金V（平台官方）',
  `v_badge_display` VARCHAR(32) DEFAULT 'golden_v' COMMENT 'V标展示标识 golden_v/blue_v/green_v',
  `is_system` TINYINT(1) DEFAULT 1 COMMENT '是否系统账号',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1正常 0停用',
  `creator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '创建者管理员ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_no` (`account_no`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台官方账号表';

-- 初始化默认平台官方账号
INSERT INTO `platform_official_account` (`account_no`, `nickname`, `v_badge`, `v_badge_display`, `is_system`, `creator_id`) VALUES
('OFFICIAL_00001', '平台官方', 1, 'golden_v', 1, 0);

-- ============================================================
-- 57. 用户V标身份表
-- 记录企业级/个人级俱乐部审核通过后的V标状态
-- ============================================================
DROP TABLE IF EXISTS `user_v_badge`;
CREATE TABLE `user_v_badge` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `badge_type` VARCHAR(32) NOT NULL COMMENT 'blue_v企业级 / green_v个人级',
  `badge_display` VARCHAR(32) NOT NULL DEFAULT 'blue_v' COMMENT 'blue_v / green_v',
  `club_name` VARCHAR(128) DEFAULT '' COMMENT '俱乐部名称',
  `audit_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1通过 2驳回',
  `audit_time` DATETIME DEFAULT NULL COMMENT '审核通过时间',
  `auditor_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '审核人ID',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否点亮',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_badge_type` (`badge_type`),
  KEY `idx_audit_status` (`audit_status`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户V标身份表';

-- ============================================================
-- 58. 俱乐部群聊表
-- 仅俱乐部创始人/管理员可创建，平台官方账号自动入驻
-- ============================================================
DROP TABLE IF EXISTS `club_group_chat`;
CREATE TABLE `club_group_chat` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_no` VARCHAR(32) NOT NULL COMMENT '群聊编号',
  `group_name` VARCHAR(128) NOT NULL COMMENT '群聊名称',
  `group_avatar` VARCHAR(512) DEFAULT '' COMMENT '群头像',
  `group_type` TINYINT NOT NULL COMMENT '群类型 1闲聊群 2福利群 3售后群',
  `group_type_label` VARCHAR(32) NOT NULL COMMENT '群类型标签',
  `creator_id` BIGINT UNSIGNED NOT NULL COMMENT '创建者用户ID',
  `announcement` VARCHAR(1024) DEFAULT '' COMMENT '群公告',
  `member_count` INT UNSIGNED DEFAULT 0 COMMENT '成员数量',
  `max_member_count` INT UNSIGNED DEFAULT 500 COMMENT '最大成员数',
  `is_muted_all` TINYINT(1) DEFAULT 0 COMMENT '是否全员禁言',
  `platform_account_id` BIGINT UNSIGNED NOT NULL COMMENT '入驻的平台官方账号ID',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1正常 0已解散',
  `dissolve_reason` VARCHAR(255) DEFAULT '' COMMENT '解散原因',
  `dissolve_time` DATETIME DEFAULT NULL COMMENT '解散时间',
  `dissolve_operator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '解散操作人（平台账号ID）',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_no` (`group_no`),
  KEY `idx_creator_id` (`creator_id`),
  KEY `idx_group_type` (`group_type`),
  KEY `idx_platform_account_id` (`platform_account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部群聊表';

-- ============================================================
-- 59. 群聊成员表
-- ============================================================
DROP TABLE IF EXISTS `group_chat_member`;
CREATE TABLE `group_chat_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL COMMENT '群聊ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `user_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1普通成员 2俱乐部管理员 3俱乐部创始人 4平台官方账号',
  `nickname_in_group` VARCHAR(64) DEFAULT '' COMMENT '群内昵称',
  `is_muted` TINYINT(1) DEFAULT 0 COMMENT '是否被禁言',
  `mute_until` DATETIME DEFAULT NULL COMMENT '禁言截止时间',
  `mute_operator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '禁言操作人',
  `is_blacklist` TINYINT(1) DEFAULT 0 COMMENT '是否限制入群黑名单',
  `join_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入群时间',
  `leave_time` DATETIME DEFAULT NULL COMMENT '退群时间',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1在群 0已退群',
  PRIMARY KEY (`id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_user_type` (`user_type`),
  KEY `idx_status` (`status`),
  UNIQUE KEY `uk_group_user` (`group_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群聊成员表';

-- ============================================================
-- 60. 群聊消息表
-- ============================================================
DROP TABLE IF EXISTS `group_chat_message`;
CREATE TABLE `group_chat_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL COMMENT '群聊ID',
  `sender_id` BIGINT UNSIGNED NOT NULL COMMENT '发送者ID',
  `sender_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1普通成员 2管理员 3创始人 4平台官方',
  `message_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1文字 2图片 3语音 4系统消息 5公告',
  `content` TEXT COMMENT '文字内容',
  `media_url` VARCHAR(512) DEFAULT '' COMMENT '媒体文件URL',
  `media_duration` INT UNSIGNED DEFAULT 0 COMMENT '语音时长（秒）',
  `asr_text` TEXT COMMENT 'ASR转文字内容',
  `ocr_text` TEXT COMMENT 'OCR识别内容',
  `is_read` TINYINT(1) DEFAULT 0 COMMENT '是否已读',
  `is_recalled` TINYINT(1) DEFAULT 0 COMMENT '是否撤回',
  `is_blocked` TINYINT(1) DEFAULT 0 COMMENT '是否被拦截',
  `block_reason` VARCHAR(255) DEFAULT '' COMMENT '拦截原因',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_message_type` (`message_type`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群聊消息表';

-- ============================================================
-- 61. 售后申诉会话表
-- 订单结束后玩家发起售后申诉时自动创建
-- ============================================================
DROP TABLE IF EXISTS `after_sale_session`;
CREATE TABLE `after_sale_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_no` VARCHAR(32) NOT NULL COMMENT '售后会话编号（AS_xxxxx）',
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '关联订单ID（永久绑定）',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '申诉玩家ID',
  `club_service_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '俱乐部客服ID',
  `platform_account_id` BIGINT UNSIGNED NOT NULL COMMENT '平台官方账号ID（强制入驻）',
  `appeal_reason` VARCHAR(512) DEFAULT '' COMMENT '申诉原因',
  `appeal_images` JSON DEFAULT NULL COMMENT '申诉图片凭证',
  `is_keyword_triggered` TINYINT(1) DEFAULT 0 COMMENT '是否关键词触发自动介入',
  `is_manual_intervene` TINYINT(1) DEFAULT 0 COMMENT '是否人工申请介入',
  `intervene_initiator` TINYINT DEFAULT 0 COMMENT '介入发起人 1玩家 2客服',
  `intervene_time` DATETIME DEFAULT NULL COMMENT '平台介入时间',
  `intervene_status` TINYINT DEFAULT 0 COMMENT '0未介入 1介入中 2已解除',
  `intervene_resolve_time` DATETIME DEFAULT NULL COMMENT '介入解除时间',
  `intervene_resolve_operator` BIGINT UNSIGNED DEFAULT 0 COMMENT '解除操作管理员ID',
  `resolve_result` VARCHAR(512) DEFAULT '' COMMENT '处理结果',
  `is_risk_high` TINYINT(1) DEFAULT 0 COMMENT '是否高风险待处理工单',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1进行中 2已解决 3已关闭',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_no` (`session_no`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_club_service_id` (`club_service_id`),
  KEY `idx_platform_account_id` (`platform_account_id`),
  KEY `idx_intervene_status` (`intervene_status`),
  KEY `idx_status` (`status`),
  KEY `idx_is_risk_high` (`is_risk_high`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='售后申诉会话表';

-- ============================================================
-- 62. 售后申诉消息表
-- 售后会话中的聊天记录，永久关联订单
-- ============================================================
DROP TABLE IF EXISTS `after_sale_message`;
CREATE TABLE `after_sale_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '售后会话ID',
  `sender_id` BIGINT UNSIGNED NOT NULL COMMENT '发送者ID',
  `sender_type` TINYINT NOT NULL COMMENT '1玩家 2俱乐部客服 3平台官方',
  `message_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1文字 2图片 3语音 4系统消息',
  `content` TEXT COMMENT '文字内容',
  `media_url` VARCHAR(512) DEFAULT '' COMMENT '媒体文件URL',
  `media_duration` INT UNSIGNED DEFAULT 0 COMMENT '语音时长（秒）',
  `asr_text` TEXT COMMENT 'ASR转文字（仅风控用，不对外展示）',
  `ocr_text` TEXT COMMENT 'OCR识别内容',
  `is_from_platform` TINYINT(1) DEFAULT 0 COMMENT '是否平台官方发送',
  `platform_msg_label` VARCHAR(32) DEFAULT '' COMMENT '平台官方专属标识',
  `is_keyword_hit` TINYINT(1) DEFAULT 0 COMMENT '是否命中售后风控关键词',
  `hit_keywords` JSON DEFAULT NULL COMMENT '命中的关键词列表',
  `is_read` TINYINT(1) DEFAULT 0 COMMENT '是否已读',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_is_keyword_hit` (`is_keyword_hit`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='售后申诉消息表';

-- ============================================================
-- 63. 售后风控关键词库表
-- 仅针对售后会话生效，后台可自定义维护
-- ============================================================
DROP TABLE IF EXISTS `after_sale_keyword`;
CREATE TABLE `after_sale_keyword` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `word` VARCHAR(128) NOT NULL COMMENT '关键词',
  `category` VARCHAR(32) NOT NULL DEFAULT 'general' COMMENT '分类 fraud/abuse/refund/threat',
  `match_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1精确匹配 2模糊匹配 3正则匹配',
  `regex_pattern` VARCHAR(255) DEFAULT '' COMMENT '正则表达式',
  `is_enabled` TINYINT(1) DEFAULT 1 COMMENT '是否启用',
  `hit_count` INT UNSIGNED DEFAULT 0 COMMENT '命中次数',
  `creator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '创建者管理员ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_word` (`word`),
  KEY `idx_category` (`category`),
  KEY `idx_is_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='售后风控关键词库表';

-- 预置售后关键词
INSERT INTO `after_sale_keyword` (`word`, `category`, `match_type`, `is_enabled`) VALUES
('骗钱', 'fraud', 1, 1),
('诈骗', 'fraud', 1, 1),
('退款', 'refund', 1, 1),
('投诉', 'threat', 1, 1),
('报警', 'threat', 1, 1),
('起诉', 'threat', 1, 1),
('假货', 'fraud', 1, 1),
('骗子', 'fraud', 1, 1);

-- ============================================================
-- 64. 售后风控关键词总开关配置
-- ============================================================
INSERT INTO `system_config` (`config_key`, `config_value`, `config_type`, `description`, `is_hot_update`) VALUES
('after_sale_keyword_switch', '1', 'bool', '售后关键词自动介入总开关', 1),
('after_sale_test_mode', '0', 'bool', '售后测试模式（仅记录日志，不弹窗不推送）', 1);

-- ============================================================
-- 65. 平台介入记录表
-- 记录所有平台介入的触发、处理过程、结果
-- ============================================================
DROP TABLE IF EXISTS `platform_intervention_log`;
CREATE TABLE `platform_intervention_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '售后会话ID',
  `session_type` VARCHAR(32) NOT NULL DEFAULT 'after_sale' COMMENT '会话类型',
  `order_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联订单ID',
  `trigger_type` TINYINT NOT NULL COMMENT '触发方式 1关键词自动 2玩家申请 3客服申请',
  `trigger_user_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '触发者用户ID',
  `trigger_detail` JSON DEFAULT NULL COMMENT '触发详情（关键词/申请内容）',
  `intervene_account_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '介入的平台账号ID',
  `intervene_time` DATETIME DEFAULT NULL COMMENT '介入时间',
  `resolve_result` VARCHAR(1024) DEFAULT '' COMMENT '处理结果',
  `resolve_action` VARCHAR(64) DEFAULT '' COMMENT '处理动作 mediation/refund/penalty/dismiss',
  `resolve_time` DATETIME DEFAULT NULL COMMENT '处理时间',
  `resolve_operator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '处理管理员ID',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1介入中 2已处理 3已关闭',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_trigger_type` (`trigger_type`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台介入记录表';

-- ============================================================
-- 66. 平台处罚记录表
-- 记录平台对群聊/用户的所有处罚操作
-- ============================================================
DROP TABLE IF EXISTS `platform_punishment_log`;
CREATE TABLE `platform_punishment_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_type` VARCHAR(32) NOT NULL COMMENT '处罚对象类型 user/group',
  `target_id` BIGINT UNSIGNED NOT NULL COMMENT '处罚对象ID',
  `punishment_type` VARCHAR(32) NOT NULL COMMENT '处罚类型 mute/ban/freeze/expel/group_dissolve',
  `punishment_detail` VARCHAR(512) DEFAULT '' COMMENT '处罚详情',
  `duration_type` VARCHAR(32) NOT NULL DEFAULT 'temporary' COMMENT 'temporary/permanent',
  `start_time` DATETIME DEFAULT NULL COMMENT '开始时间',
  `end_time` DATETIME DEFAULT NULL COMMENT '结束时间',
  `operator_account_id` BIGINT UNSIGNED NOT NULL COMMENT '操作平台账号ID',
  `reason` VARCHAR(512) DEFAULT '' COMMENT '处罚原因',
  `is_revoked` TINYINT(1) DEFAULT 0 COMMENT '是否已撤销',
  `revoke_time` DATETIME DEFAULT NULL COMMENT '撤销时间',
  `revoke_operator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '撤销操作人',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_punishment_type` (`punishment_type`),
  KEY `idx_operator_account_id` (`operator_account_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台处罚记录表';

-- ============================================================
-- 67. 售后风控命中日志表
-- 记录售后会话中关键词命中详情
-- ============================================================
DROP TABLE IF EXISTS `after_sale_risk_log`;
CREATE TABLE `after_sale_risk_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '售后会话ID',
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `message_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '消息ID',
  `sender_id` BIGINT UNSIGNED NOT NULL COMMENT '发送人ID',
  `hit_keywords` JSON NOT NULL COMMENT '命中的关键词列表',
  `content_summary` VARCHAR(512) DEFAULT '' COMMENT '内容摘要',
  `is_handled` TINYINT(1) DEFAULT 0 COMMENT '是否已处理',
  `handle_time` DATETIME DEFAULT NULL COMMENT '处理时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_is_handled` (`is_handled`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='售后风控命中日志表';

-- ============================================================
-- 68. 微信订阅消息模板配置表
-- 配置微信小程序订阅消息模板ID及对应场景
-- ============================================================
DROP TABLE IF EXISTS `subscribe_message_template`;
CREATE TABLE `subscribe_message_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` VARCHAR(64) NOT NULL COMMENT '微信模板ID',
  `template_name` VARCHAR(64) NOT NULL COMMENT '模板名称',
  `scene` VARCHAR(32) NOT NULL COMMENT '场景标识 appeal_notify/order_notify/chat_notify/platform_intervene/after_sale_remind',
  `scene_name` VARCHAR(64) NOT NULL COMMENT '场景名称',
  `fields` JSON DEFAULT NULL COMMENT '模板参数字段映射',
  `is_enabled` TINYINT(1) DEFAULT 1 COMMENT '是否启用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_template_id` (`template_id`),
  KEY `idx_scene` (`scene`),
  KEY `idx_is_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='微信订阅消息模板配置表';

-- 预置订阅消息模板（需替换为实际申请的模板ID）
INSERT INTO `subscribe_message_template` (`template_id`, `template_name`, `scene`, `scene_name`, `fields`, `is_enabled`) VALUES
('TEMPLATE_ID_PLACEHOLDER_01', '申诉处理通知', 'appeal_notify', '申诉通知', '{"thing1": "申诉类型", "thing2": "处理状态", "time3": "处理时间", "thing4": "备注"}', 1),
('TEMPLATE_ID_PLACEHOLDER_02', '订单状态变更', 'order_notify', '订单通知', '{"thing1": "订单编号", "thing2": "订单状态", "amount3": "订单金额", "thing4": "备注"}', 1),
('TEMPLATE_ID_PLACEHOLDER_03', '新消息提醒', 'chat_notify', '聊天通知', '{"thing1": "发送者", "thing2": "消息摘要", "time3": "发送时间", "thing4": "备注"}', 1),
('TEMPLATE_ID_PLACEHOLDER_04', '平台介入通知', 'platform_intervene', '平台介入', '{"thing1": "订单编号", "thing2": "介入原因", "time3": "介入时间", "thing4": "处理指引"}', 1),
('TEMPLATE_ID_PLACEHOLDER_05', '售后处理提醒', 'after_sale_remind', '售后提醒', '{"thing1": "售后单号", "thing2": "处理进度", "time3": "提交时间", "thing4": "备注"}', 1);

-- ============================================================
-- 69. 微信订阅消息发送日志表
-- ============================================================
DROP TABLE IF EXISTS `subscribe_message_log`;
CREATE TABLE `subscribe_message_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收用户ID',
  `template_id` VARCHAR(64) NOT NULL COMMENT '模板ID',
  `scene` VARCHAR(32) NOT NULL COMMENT '场景标识',
  `openid` VARCHAR(64) DEFAULT '' COMMENT '微信openid',
  `send_data` JSON DEFAULT NULL COMMENT '发送的模板数据',
  `send_result` JSON DEFAULT NULL COMMENT '微信API返回结果',
  `is_success` TINYINT(1) DEFAULT 0 COMMENT '是否成功',
  `error_msg` VARCHAR(512) DEFAULT '' COMMENT '错误信息',
  `related_id` VARCHAR(64) DEFAULT '' COMMENT '关联业务ID',
  `related_type` VARCHAR(32) DEFAULT '' COMMENT '关联业务类型',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_scene` (`scene`),
  KEY `idx_is_success` (`is_success`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_related` (`related_id`, `related_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='微信订阅消息发送日志表';

-- ============================================================
-- 70. 微信小程序配置（system_config 补充）
-- ============================================================
INSERT INTO `system_config` (`config_key`, `config_value`, `config_type`, `description`, `is_hot_update`) VALUES
('wechat_appid', 'wx0000000000000000', 'string', '微信小程序AppID', 1),
('wechat_secret', '', 'string', '微信小程序AppSecret', 1),
('subscribe_message_switch', '1', 'bool', '订阅消息推送总开关', 1),
('subscribe_message_retry', '3', 'int', '订阅消息发送重试次数', 1),
('subscribe_message_timeout', '3', 'int', '订阅消息HTTP请求超时（秒）', 1);
-- 索引优化建议：
-- 1. 所有外键关联字段均已建立索引
-- 2. 频繁查询的 status、create_time 字段均已建立索引
-- 3. 金额字段统一使用 BIGINT 存储分，使用 bcmath 运算
-- 4. 敏感数据（密码、身份证、银行卡）使用加密存储
-- 5. 软删除使用 delete_time 字段
-- 6. 所有表使用 InnoDB 引擎支持事务
-- 7. 新增12张表支持三大会话体系+平台介入+V标身份
-- 8. 新增2张表支持六档UP主认证体系
-- ============================================================

-- ============================================================
-- 71. UP主认证等级表
-- 六档认证体系：青铜→进阶→高阶→精英→巨匠→至尊
-- ============================================================
DROP TABLE IF EXISTS `up_master_certification`;
CREATE TABLE `up_master_certification` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '所属俱乐部ID（user_v_badge.id），必须通过俱乐部申请',
  `tier` TINYINT NOT NULL COMMENT '等级 1青铜 2进阶 3高阶 4精英 5巨匠 6至尊',
  `tier_name` VARCHAR(32) NOT NULL COMMENT '等级名称',
  `fan_count` INT UNSIGNED DEFAULT 0 COMMENT '粉丝数',
  `fan_count_verified` INT UNSIGNED DEFAULT 0 COMMENT '核验粉丝数',
  `platform` VARCHAR(64) DEFAULT '' COMMENT '主平台 抖音/快手/B站/小红书/微信视频号',
  `platform_account_id` VARCHAR(128) DEFAULT '' COMMENT '平台账号ID',
  `platform_account_url` VARCHAR(255) DEFAULT '' COMMENT '平台主页链接',
  `screenshot_urls` JSON DEFAULT NULL COMMENT '粉丝数截图凭证（个人主页截图）',
  `video_url` VARCHAR(512) DEFAULT '' COMMENT '录屏视频URL（从手机桌面→进入平台→个人主页）',
  `audit_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1通过 2驳回',
  `audit_remark` VARCHAR(255) DEFAULT '' COMMENT '审核备注',
  `audit_time` DATETIME DEFAULT NULL COMMENT '审核时间',
  `auditor_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '审核人ID（超级管理员）',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否点亮',
  `badge_text` VARCHAR(8) DEFAULT 'UP' COMMENT '徽标文字',
  `badge_color` VARCHAR(32) DEFAULT '' COMMENT '徽标底色 #值',
  `badge_size` VARCHAR(8) DEFAULT 'small' COMMENT 'small/large',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_tier` (`tier`),
  KEY `idx_audit_status` (`audit_status`),
  KEY `idx_is_active` (`is_active`),
  UNIQUE KEY `uk_user_active` (`user_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='UP主认证等级表';

-- ============================================================
-- 72. UP主认证等级配置表
-- 六档配置常量，含色值、粉丝门槛、徽标尺寸
-- ============================================================
DROP TABLE IF EXISTS `up_master_tier_config`;
CREATE TABLE `up_master_tier_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tier` TINYINT NOT NULL COMMENT '等级 1-6',
  `tier_name` VARCHAR(32) NOT NULL COMMENT '等级名称',
  `fan_threshold` INT UNSIGNED NOT NULL COMMENT '粉丝门槛',
  `bg_color` VARCHAR(16) NOT NULL COMMENT '底色 #值',
  `highlight_color` VARCHAR(16) DEFAULT '' COMMENT '高光/反光色 #值',
  `text_color` VARCHAR(16) DEFAULT '#FFFFFF' COMMENT '文字色',
  `badge_size` VARCHAR(8) NOT NULL DEFAULT 'small' COMMENT 'small/large',
  `visual_desc` VARCHAR(255) DEFAULT '' COMMENT '视觉描述',
  `effect_type` VARCHAR(32) DEFAULT '' COMMENT '特效类型 edge_reflect/matte/gradient/glow/pearl/flow',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tier` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='UP主认证等级配置表';

-- 六档预置数据
INSERT INTO `up_master_tier_config` (`tier`, `tier_name`, `fan_threshold`, `bg_color`, `highlight_color`, `text_color`, `badge_size`, `visual_desc`, `effect_type`, `sort`) VALUES
(1, '青铜UP主',  100,      '#1A1A1A', '#8C8C8C', '#FFFFFF', 'small', '圆形徽标，内含"UP"，纯黑底色，边缘带反光灰细描边', 'edge_reflect', 1),
(2, '进阶UP主',  5000,    '#B8B8B8', '#808080', '#404040', 'small', '圆形徽标，内含"UP"，银灰底色，整体磨砂反光质感', 'matte', 2),
(3, '高阶UP主',  10000,   '#7B2FBE', '#A855F7', '#FFFFFF', 'small', '圆形徽标，内含"UP"，亮紫底色，带高光渐变反光', 'gradient', 3),
(4, '精英UP主',  100000,  '#E87A2A', '#F5A623', '#FFFFFF', 'large', '圆形徽标，内含"UP"，活力橙底色，强高光反光，边缘带淡淡光晕', 'glow', 4),
(5, '巨匠UP主',  1000000, '#C44A6C', '#D4789B', '#FFFFFF', 'large', '圆形徽标，内含"UP"，玫瑰红底色，带细密珠光质感', 'pearl', 5),
(6, '至尊UP主',  10000000,'#8B1A2B', '#FF4040', '#FFFFFF', 'large', '圆形徽标，内含"UP"，暗夜红底色，带动态流光特效', 'flow', 6);