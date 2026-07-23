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

-- 预置违规词（游戏代练、外挂、上分、破解、线下交易、赌博）
INSERT INTO `sensitive_word` (`word`, `category`, `match_type`, `regex_pattern`, `action`) VALUES
('游戏代练', 'fraud', 3, '(游戏代练|代练|代打|陪练)', 1),
('外挂', 'fraud', 3, '(外挂|辅助|脚本|作弊器|挂逼)', 1),
('上分', 'fraud', 3, '(上分|掉分|刷分|代上分)', 1),
('破解', 'fraud', 3, '(破解|破解版|汉化|盗版|私服)', 1),
('线下交易', 'fraud', 3, '(线下交易|私下交易|当面交易|现金交易|不走平台)', 1),
('赌博', 'fraud', 3, '(赌博|赌钱|博彩|彩票|六合彩|时时彩)', 1),
('转账', 'fraud', 3, '(转账|支付宝|微信支付|银行卡|打款|汇款)', 1),
('红包', 'fraud', 3, '(发红包|红包|扫码支付|二维码)', 1);

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
('club_join_switch', '1', 'bool', '俱乐部入驻总开关（关闭后前端同步隐藏所有入驻入口，已入驻俱乐部不受影响）', 1),
('club_personal_deposit', '0', 'int', '个人俱乐部保证金金额（元，0=免保证金）', 1),
('club_enterprise_deposit', '0', 'int', '企业俱乐部保证金金额（元，0=免保证金）', 1);

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
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID（俱乐部创始人）',
  `badge_type` VARCHAR(32) NOT NULL COMMENT 'blue_v企业级 / green_v个人级',
  `badge_display` VARCHAR(32) NOT NULL DEFAULT 'blue_v' COMMENT 'blue_v / green_v',
  `club_name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '俱乐部中文全称',
  `abbreviation` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '拼音首字母大写缩写（订单号前缀，全局唯一）',
  `audit_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1通过 2驳回 3补充资料',
  `audit_remark` VARCHAR(255) DEFAULT '' COMMENT '审核备注',
  `audit_time` DATETIME DEFAULT NULL COMMENT '审核通过时间',
  `auditor_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '审核人ID',
  `is_active` TINYINT(1) DEFAULT 0 COMMENT '是否点亮V标',
  `deposit_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '应缴保证金',
  `deposit_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0未缴 1已缴 2已退',
  `deposit_pay_time` DATETIME DEFAULT NULL COMMENT '保证金缴纳时间',
  `deposit_transaction_id` VARCHAR(64) DEFAULT '' COMMENT '保证金支付交易号',
  -- 入驻人基础信息
  `real_name` VARCHAR(64) DEFAULT '' COMMENT '真实姓名',
  `id_card` VARCHAR(18) DEFAULT '' COMMENT '身份证号',
  `phone` VARCHAR(11) DEFAULT '' COMMENT '实名手机号',
  `address_province` VARCHAR(32) DEFAULT '',
  `address_city` VARCHAR(32) DEFAULT '',
  `address_district` VARCHAR(32) DEFAULT '',
  `address_detail` VARCHAR(255) DEFAULT '' COMMENT '乡镇街道/小区/楼栋/单元/楼层/户号',
  -- 实名认证
  `id_card_front` VARCHAR(512) DEFAULT '' COMMENT '身份证正面照片',
  `id_card_back` VARCHAR(512) DEFAULT '' COMMENT '身份证反面照片',
  `liveness_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0未认证 1通过 2失败',
  `liveness_time` DATETIME DEFAULT NULL COMMENT '活体认证时间',
  -- 合同
  `contract_file` VARCHAR(512) DEFAULT '' COMMENT '已签署合同PDF',
  -- 企业专属字段
  `is_enterprise` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0个人 1企业',
  `business_license` VARCHAR(512) DEFAULT '' COMMENT '营业执照照片',
  `corporate_bank` VARCHAR(255) DEFAULT '' COMMENT '开户银行',
  `corporate_account` VARCHAR(64) DEFAULT '' COMMENT '对公账号',
  `handle_type` VARCHAR(32) DEFAULT 'self' COMMENT 'self本人 / agent代办',
  `agent_name` VARCHAR(64) DEFAULT '' COMMENT '代办人姓名',
  `agent_id_card` VARCHAR(18) DEFAULT '' COMMENT '代办人身份证号',
  `agent_id_card_front` VARCHAR(512) DEFAULT '' COMMENT '代办人身份证正面',
  `agent_id_card_back` VARCHAR(512) DEFAULT '' COMMENT '代办人身份证反面',
  `agent_authorization` VARCHAR(512) DEFAULT '' COMMENT '代办授权协议PDF',
  `verification_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT '对公打款验证金额',
  `verification_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0未验证 1待确认 2通过 3失败',
  `verification_receipt` VARCHAR(512) DEFAULT '' COMMENT '打款凭证',
  -- 状态
  `club_status` VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/active/frozen/closed/cancelled',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_abbreviation` (`abbreviation`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_badge_type` (`badge_type`),
  KEY `idx_audit_status` (`audit_status`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_club_status` (`club_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部入驻/V标身份表';

-- ============================================================
-- 57.1 俱乐部缩写全局封存表（所有历史缩写永久锁定，不可复用）
-- ============================================================
DROP TABLE IF EXISTS `club_abbreviations`;
CREATE TABLE `club_abbreviations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `abbreviation` VARCHAR(20) NOT NULL COMMENT '拼音首字母缩写',
  `club_name` VARCHAR(128) NOT NULL COMMENT '俱乐部中文名称',
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '关联俱乐部ID',
  `club_status` VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active/frozen/closed/cancelled',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_abbreviation` (`abbreviation`),
  KEY `idx_club_id` (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部缩写全局封存表（终身不可复用）';

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

-- ============================================================
-- 38. 平台文档表（协议/政策/合同）
-- 仅超级管理员可上传、替换、删除，限定PDF格式
-- ============================================================
DROP TABLE IF EXISTS `platform_documents`;
CREATE TABLE `platform_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_type` VARCHAR(32) NOT NULL COMMENT '文档类型: agreement(协议)/policy(政策)/contract(合同)',
  `title` VARCHAR(128) NOT NULL COMMENT '文档标题',
  `file_url` VARCHAR(512) NOT NULL COMMENT 'PDF文件存储路径',
  `file_name` VARCHAR(255) NOT NULL COMMENT '原始文件名',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本号(替换时自动递增)',
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '上传/更新管理员ID',
  `is_active` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用',
  `is_deleted` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否逻辑删除(0=正常, 1=已删除)',
  `deleted_at` DATETIME DEFAULT NULL COMMENT '逻辑删除时间',
  `deleted_by` BIGINT UNSIGNED DEFAULT NULL COMMENT '删除操作人',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_type` (`doc_type`),
  KEY `idx_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台文档表（协议/政策/合同）';

-- ============================================================
-- 39. 平台文档版本历史表
-- 每次替换时自动保存旧版本，支持历史版本回溯查看
-- ============================================================
DROP TABLE IF EXISTS `document_versions`;
CREATE TABLE `document_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` BIGINT UNSIGNED NOT NULL COMMENT '关联文档ID',
  `version` INT UNSIGNED NOT NULL COMMENT '版本号',
  `file_url` VARCHAR(512) NOT NULL COMMENT 'PDF文件存储路径',
  `file_name` VARCHAR(255) NOT NULL COMMENT '原始文件名',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '操作管理员ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document_id` (`document_id`),
  KEY `idx_document_version` (`document_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台文档版本历史表';

-- ============================================================
-- 73. 飞单风控规则表
-- 检测微信/QQ/手机号/线下转账/银行卡等脱离平台行为
-- ============================================================
DROP TABLE IF EXISTS `chat_anti_fraud_rule`;
CREATE TABLE `chat_anti_fraud_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_type` VARCHAR(32) NOT NULL COMMENT '规则类型: wechat/qq/phone/offline_transfer/bank_card',
  `rule_name` VARCHAR(64) NOT NULL COMMENT '规则名称',
  `pattern` VARCHAR(512) NOT NULL COMMENT '匹配规则（正则表达式）',
  `level` VARCHAR(16) NOT NULL DEFAULT 'warning' COMMENT '风险等级: warning/mute/ban',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rule_type` (`rule_type`),
  KEY `idx_level` (`level`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='飞单风控规则表';

-- 预置飞单风控规则
INSERT INTO `chat_anti_fraud_rule` (`rule_type`, `rule_name`, `pattern`, `level`, `sort`) VALUES
('wechat', '微信号检测', '/微信|wx|weixin|加我微信|v信|vx|微聊|加个微/iu', 'warning', 1),
('wechat', '微信号格式', '/[a-zA-Z][a-zA-Z0-9_-]{5,19}/', 'mute', 2),
('qq', 'QQ号检测', '/QQ|qq|扣扣|企鹅|加Q/iu', 'warning', 3),
('qq', 'QQ号格式', '/[1-9]\\d{4,10}/', 'mute', 4),
('phone', '手机号检测', '/手机号|电话|联系电话|手机|致电/iu', 'warning', 5),
('phone', '手机号格式', '/1[3-9]\\d{9}/', 'mute', 6),
('offline_transfer', '线下转账', '/线下|私下|转账|支付宝|红包|微信转账|银行卡|打款|汇款/iu', 'mute', 7),
('offline_transfer', '脱离平台', '/绕过平台|不走平台|脱离平台|私下交易|线下交易|加微信聊/iu', 'ban', 8),
('bank_card', '银行卡号', '/\\d{16,19}/', 'mute', 9);

-- ============================================================
-- 74. 飞单拦截日志表
-- 记录所有命中飞单风控规则的消息
-- ============================================================
DROP TABLE IF EXISTS `chat_anti_fraud_log`;
CREATE TABLE `chat_anti_fraud_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '会话ID',
  `session_type` TINYINT NOT NULL DEFAULT 1 COMMENT '会话类型: 1私聊 2群聊 3售后',
  `sender_id` BIGINT UNSIGNED NOT NULL COMMENT '发送者ID',
  `message_id` BIGINT UNSIGNED NOT NULL COMMENT '消息ID',
  `rule_id` BIGINT UNSIGNED NOT NULL COMMENT '命中规则ID',
  `matched_content` VARCHAR(255) DEFAULT '' COMMENT '匹配到的内容',
  `level` VARCHAR(16) NOT NULL DEFAULT 'warning' COMMENT '风险等级: warning/mute/ban',
  `handled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已处理 0否 1是',
  `handle_result` VARCHAR(255) DEFAULT '' COMMENT '处理结果',
  `handle_time` DATETIME DEFAULT NULL COMMENT '处理时间',
  `handler_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '处理人ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_rule_id` (`rule_id`),
  KEY `idx_level` (`level`),
  KEY `idx_handled` (`handled`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='飞单拦截日志表';

-- ============================================================
-- 75. 消息撤回记录表
-- 记录撤回的消息，云端永久留存
-- ============================================================
DROP TABLE IF EXISTS `chat_message_revoke`;
CREATE TABLE `chat_message_revoke` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '会话ID',
  `session_type` TINYINT NOT NULL DEFAULT 1 COMMENT '会话类型: 1私聊 2群聊 3售后',
  `message_id` BIGINT UNSIGNED NOT NULL COMMENT '消息ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '撤回用户ID',
  `msg_type` TINYINT NOT NULL DEFAULT 1 COMMENT '消息类型',
  `original_content` TEXT COMMENT '原始消息内容',
  `revoke_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '撤回时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_revoke_time` (`revoke_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息撤回记录表';

-- ============================================================
-- 76. 文件消息表
-- 记录聊天中的文件消息（图片/文档/截图）
-- ============================================================
DROP TABLE IF EXISTS `chat_file_message`;
CREATE TABLE `chat_file_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '会话ID',
  `session_type` TINYINT NOT NULL DEFAULT 1 COMMENT '会话类型: 1私聊 2群聊 3售后',
  `sender_id` BIGINT UNSIGNED NOT NULL COMMENT '发送者ID',
  `message_id` BIGINT UNSIGNED NOT NULL COMMENT '关联消息ID',
  `file_name` VARCHAR(255) NOT NULL COMMENT '文件名',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `file_url` VARCHAR(512) NOT NULL COMMENT '文件URL',
  `file_type` VARCHAR(32) NOT NULL COMMENT '文件类型: image/document/screenshot',
  `file_ext` VARCHAR(16) DEFAULT '' COMMENT '文件扩展名',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_file_type` (`file_type`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件消息表';

-- ============================================================
-- 77. 群公告定时推送表
-- 俱乐部管理员可设定时推送公告
-- ============================================================
DROP TABLE IF EXISTS `group_announcement_schedule`;
CREATE TABLE `group_announcement_schedule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL COMMENT '群ID',
  `title` VARCHAR(128) NOT NULL COMMENT '公告标题',
  `content` TEXT NOT NULL COMMENT '公告内容',
  `schedule_time` DATETIME NOT NULL COMMENT '定时发送时间',
  `is_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已发送 0否 1是',
  `send_time` DATETIME DEFAULT NULL COMMENT '实际发送时间',
  `creator_id` BIGINT UNSIGNED NOT NULL COMMENT '创建者ID',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1正常 0取消',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_schedule_time` (`schedule_time`),
  KEY `idx_is_sent` (`is_sent`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群公告定时推送表';

-- ============================================================
-- 78. 快捷服务卡片表
-- 一键发报价/套餐/预约卡片
-- ============================================================
DROP TABLE IF EXISTS `chat_quick_card`;
CREATE TABLE `chat_quick_card` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(32) NOT NULL COMMENT '卡片类型: price/package/appointment',
  `title` VARCHAR(128) NOT NULL COMMENT '卡片标题',
  `content` TEXT NOT NULL COMMENT '卡片内容',
  `action` VARCHAR(64) DEFAULT '' COMMENT '点击动作',
  `params_json` JSON DEFAULT NULL COMMENT '动作参数JSON',
  `icon` VARCHAR(255) DEFAULT '' COMMENT '卡片图标',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='快捷服务卡片表';

-- 预置快捷卡片
INSERT INTO `chat_quick_card` (`type`, `title`, `content`, `action`, `icon`, `sort`) VALUES
('price', '报价咨询', '点击查看当前服务报价，支持多种套餐选择', 'view_price', '💰', 1),
('package', '套餐推荐', '为您推荐最受欢迎的服务套餐', 'view_package', '📦', 2),
('appointment', '立即预约', '一键预约服务，快速安排时间', 'make_appointment', '📅', 3);

-- ============================================================
-- 79. 语音ASR缓存表
-- 售后会话语音自动转文字缓存
-- ============================================================
DROP TABLE IF EXISTS `chat_asr_cache`;
CREATE TABLE `chat_asr_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL COMMENT '消息ID',
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '会话ID',
  `session_type` TINYINT NOT NULL DEFAULT 1 COMMENT '会话类型: 1私聊 2群聊 3售后',
  `voice_url` VARCHAR(512) NOT NULL COMMENT '语音文件URL',
  `asr_text` TEXT NOT NULL COMMENT 'ASR转文字结果',
  `confidence` DECIMAL(5,2) DEFAULT 0 COMMENT '置信度',
  `provider` VARCHAR(32) DEFAULT '' COMMENT 'ASR服务商',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message_id` (`message_id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='语音ASR缓存表';

-- ============================================================
-- 80. 售后举证上传记录表
-- 售后会话中用户上传的举证材料
-- ============================================================
DROP TABLE IF EXISTS `chat_upload_evidence_log`;
CREATE TABLE `chat_upload_evidence_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL COMMENT '售后会话ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '上传用户ID',
  `file_url` VARCHAR(512) NOT NULL COMMENT '文件URL',
  `file_name` VARCHAR(255) DEFAULT '' COMMENT '文件名',
  `file_size` BIGINT UNSIGNED DEFAULT 0 COMMENT '文件大小(字节)',
  `file_type` VARCHAR(32) DEFAULT 'image' COMMENT '文件类型: image/video/document',
  `description` VARCHAR(512) DEFAULT '' COMMENT '举证说明',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='售后举证上传记录表';

-- ============================================================
-- 40. 优惠券模板表
-- ============================================================
DROP TABLE IF EXISTS `coupon_template`;
CREATE TABLE `coupon_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT '优惠券名称',
  `type` VARCHAR(32) NOT NULL COMMENT '类型: full_reduction(满减)/new_user(新人)/compensation(补偿)/club_exclusive(俱乐部专属)',
  `value` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '优惠金额/折扣值',
  `min_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '最低消费金额门槛',
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发放总数量',
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已使用数量',
  `validity_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '有效期天数(0=按起止时间)',
  `start_time` DATETIME DEFAULT NULL COMMENT '生效开始时间',
  `end_time` DATETIME DEFAULT NULL COMMENT '生效结束时间',
  `applicable_scope` VARCHAR(32) NOT NULL DEFAULT 'all' COMMENT '适用范围: all(全部)/game(指定游戏)/club(指定俱乐部)',
  `applicable_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '适用范围ID(游戏ID/俱乐部ID)',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 0-禁用 1-启用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优惠券模板表';

-- ============================================================
-- 41. 用户优惠券表
-- ============================================================
DROP TABLE IF EXISTS `user_coupon`;
CREATE TABLE `user_coupon` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` BIGINT UNSIGNED NOT NULL COMMENT '优惠券模板ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `status` VARCHAR(32) NOT NULL DEFAULT 'unused' COMMENT '状态: unused(未使用)/used(已使用)/expired(已过期)',
  `receive_channel` VARCHAR(32) DEFAULT '' COMMENT '领取渠道: register(注册)/activity(活动)/invite(邀请)/admin(后台发放)',
  `receive_time` DATETIME DEFAULT NULL COMMENT '领取时间',
  `use_time` DATETIME DEFAULT NULL COMMENT '使用时间',
  `order_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '使用订单ID',
  `expire_time` DATETIME DEFAULT NULL COMMENT '过期时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_coupon_id` (`coupon_id`),
  KEY `idx_status` (`status`),
  KEY `idx_receive_time` (`receive_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户优惠券表';

-- ============================================================
-- 42. 充值活动表
-- ============================================================
DROP TABLE IF EXISTS `recharge_activity`;
CREATE TABLE `recharge_activity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT '活动名称',
  `recharge_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '充值金额',
  `bonus_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '赠送金额',
  `bonus_type` VARCHAR(32) NOT NULL DEFAULT 'balance' COMMENT '赠送类型: balance(余额)/coupon(优惠券)',
  `bonus_coupon_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '赠送优惠券ID(bonus_type=coupon时)',
  `start_time` DATETIME DEFAULT NULL COMMENT '活动开始时间',
  `end_time` DATETIME DEFAULT NULL COMMENT '活动结束时间',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 0-禁用 1-启用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='充值活动表';

-- ============================================================
-- 43. 用户充值记录表
-- ============================================================
DROP TABLE IF EXISTS `user_recharge_log`;
CREATE TABLE `user_recharge_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '充值金额',
  `bonus_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '赠送金额',
  `activity_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '活动ID',
  `pay_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '支付状态 0-待支付 1-已支付 2-已失败',
  `transaction_id` VARCHAR(128) DEFAULT '' COMMENT '微信支付订单号',
  `out_trade_no` VARCHAR(64) DEFAULT '' COMMENT '商户订单号',
  `pay_time` DATETIME DEFAULT NULL COMMENT '支付时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_pay_status` (`pay_status`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户充值记录表';

-- ============================================================
-- 44. 老带新奖励配置表
-- ============================================================
DROP TABLE IF EXISTS `invite_reward_config`;
CREATE TABLE `invite_reward_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reward_type` VARCHAR(32) NOT NULL COMMENT '奖励类型: balance(余额)/coupon(优惠券)',
  `reward_value` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '奖励值(余额金额或优惠券ID)',
  `condition_type` VARCHAR(32) NOT NULL COMMENT '触发条件: first_order(首单完成)/realname(实名完成)',
  `condition_value` VARCHAR(128) DEFAULT '' COMMENT '条件值(如首单金额门槛)',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 0-禁用 1-启用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_condition_type` (`condition_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='老带新奖励配置表';

-- ============================================================
-- 45. 邀请奖励记录表
-- ============================================================
DROP TABLE IF EXISTS `invite_reward_log`;
CREATE TABLE `invite_reward_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inviter_user_id` BIGINT UNSIGNED NOT NULL COMMENT '邀请人用户ID',
  `invitee_user_id` BIGINT UNSIGNED NOT NULL COMMENT '被邀请人用户ID',
  `reward_type` VARCHAR(32) NOT NULL COMMENT '奖励类型: balance(余额)/coupon(优惠券)',
  `reward_value` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '奖励值',
  `condition_type` VARCHAR(32) NOT NULL COMMENT '触发条件类型',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '状态 0-待发放 1-已发放 2-发放失败',
  `reward_time` DATETIME DEFAULT NULL COMMENT '发放时间',
  `remark` VARCHAR(255) DEFAULT '' COMMENT '备注',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inviter_user_id` (`inviter_user_id`),
  KEY `idx_invitee_user_id` (`invitee_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请奖励记录表';

-- ============================================================
-- 46. 抽奖活动表
-- ============================================================
DROP TABLE IF EXISTS `lottery_activity`;
CREATE TABLE `lottery_activity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT '活动名称',
  `type` VARCHAR(32) NOT NULL DEFAULT 'wheel' COMMENT '类型: wheel(转盘)',
  `cost_type` VARCHAR(32) NOT NULL DEFAULT 'free' COMMENT '消耗类型: free(免费)/balance(余额)/points(积分)',
  `cost_value` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '消耗值',
  `daily_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每日抽奖次数限制(0=不限制)',
  `total_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '总抽奖次数限制(0=不限制)',
  `start_time` DATETIME DEFAULT NULL COMMENT '活动开始时间',
  `end_time` DATETIME DEFAULT NULL COMMENT '活动结束时间',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 0-禁用 1-启用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖活动表';

-- ============================================================
-- 47. 抽奖奖品表
-- ============================================================
DROP TABLE IF EXISTS `lottery_prize`;
CREATE TABLE `lottery_prize` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL COMMENT '活动ID',
  `name` VARCHAR(128) NOT NULL COMMENT '奖品名称',
  `type` VARCHAR(32) NOT NULL COMMENT '类型: coupon(优惠券)/free_time(免费时长)/balance(余额)/thank(谢谢参与)',
  `value` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '奖品值(金额/时长/优惠券ID)',
  `probability` DECIMAL(5,4) NOT NULL DEFAULT '0.0000' COMMENT '中奖概率(0-1)',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序(转盘位置)',
  `image` VARCHAR(512) DEFAULT '' COMMENT '奖品图片',
  `stock` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '库存(0=不限)',
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已中出数量',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 0-禁用 1-启用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_id` (`activity_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖奖品表';

-- ============================================================
-- 48. 抽奖记录表
-- ============================================================
DROP TABLE IF EXISTS `lottery_record`;
CREATE TABLE `lottery_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL COMMENT '活动ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `prize_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '奖品ID',
  `prize_name` VARCHAR(128) DEFAULT '' COMMENT '奖品名称',
  `prize_type` VARCHAR(32) DEFAULT '' COMMENT '奖品类型',
  `is_win` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否中奖',
  `draw_time` DATETIME DEFAULT NULL COMMENT '抽奖时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_activity_id` (`activity_id`),
  KEY `idx_draw_time` (`draw_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖记录表';

-- ============================================================
-- 49. 拼团活动表
-- ============================================================
DROP TABLE IF EXISTS `group_buy_activity`;
CREATE TABLE `group_buy_activity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '游戏ID',
  `name` VARCHAR(128) NOT NULL COMMENT '活动名称',
  `original_price` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '原价',
  `group_price` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '拼团价',
  `min_people` INT UNSIGNED NOT NULL DEFAULT 2 COMMENT '最少人数',
  `max_people` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT '最多人数',
  `duration_hours` INT UNSIGNED NOT NULL DEFAULT 24 COMMENT '拼团时长(小时)',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 0-禁用 1-启用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_game_id` (`game_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='拼团活动表';

-- ============================================================
-- 50. 拼团订单表
-- ============================================================
DROP TABLE IF EXISTS `group_buy_order`;
CREATE TABLE `group_buy_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL COMMENT '活动ID',
  `leader_user_id` BIGINT UNSIGNED NOT NULL COMMENT '团长用户ID',
  `current_people` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '当前人数',
  `max_people` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT '最大人数',
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT '状态: pending(拼团中)/success(拼团成功)/failed(拼团失败)/canceled(已取消)',
  `expire_time` DATETIME DEFAULT NULL COMMENT '过期时间',
  `success_time` DATETIME DEFAULT NULL COMMENT '成功时间',
  `group_chat_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '拼团群聊ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_id` (`activity_id`),
  KEY `idx_leader_user_id` (`leader_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expire_time` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='拼团订单表';

-- ============================================================
-- 51. 拼团成员表
-- ============================================================
DROP TABLE IF EXISTS `group_buy_member`;
CREATE TABLE `group_buy_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL COMMENT '拼团订单ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `order_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联订单ID',
  `is_leader` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否团长',
  `join_time` DATETIME DEFAULT NULL COMMENT '加入时间',
  `status` VARCHAR(32) NOT NULL DEFAULT 'joined' COMMENT '状态: joined(已加入)/paid(已支付)/refunded(已退款)',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='拼团成员表';

-- ============================================================
-- 40. 订单表 - 2024年1月分表示例
-- 分表规则：按月分表，表名 order_archive_YYYYMM
-- 分表依据：create_time 字段的年月
-- ============================================================
DROP TABLE IF EXISTS `order_archive_202401`;
CREATE TABLE `order_archive_202401` (
  `id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `order_sn` VARCHAR(64) NOT NULL COMMENT '订单号',
  `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
  `player_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '打手ID',
  `service_type_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '服务类型ID',
  `game_name` VARCHAR(128) DEFAULT '' COMMENT '游戏名称',
  `order_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单金额（分）',
  `paid_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '实付金额（分）',
  `discount_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '优惠金额（分）',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单状态',
  `remark` VARCHAR(512) DEFAULT '' COMMENT '订单备注',
  `paid_time` DATETIME DEFAULT NULL COMMENT '支付时间',
  `completed_time` DATETIME DEFAULT NULL COMMENT '完成时间',
  `canceled_time` DATETIME DEFAULT NULL COMMENT '取消时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` DATETIME DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表-202401分表';

-- 订单表 - 2024年2月分表示例
DROP TABLE IF EXISTS `order_archive_202402`;
CREATE TABLE `order_archive_202402` LIKE `order_archive_202401`;
ALTER TABLE `order_archive_202402` COMMENT = '订单表-202402分表';

-- ============================================================
-- 41. 聊天消息表 - 2024年1月分表示例
-- 分表规则：按月分表，表名 chat_message_archive_YYYYMM
-- 分表依据：create_time 字段的年月
-- ============================================================
DROP TABLE IF EXISTS `chat_message_archive_202401`;
CREATE TABLE `chat_message_archive_202401` (
  `id` BIGINT UNSIGNED NOT NULL COMMENT '消息ID',
  `session_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话ID',
  `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发送者ID',
  `to_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '接收者ID',
  `msg_type` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '消息类型 0文本 1图片 2语音 3系统',
  `content` TEXT COMMENT '消息内容',
  `extra` JSON DEFAULT NULL COMMENT '附加信息JSON',
  `is_read` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已读 0未读 1已读',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态 0隐藏 1正常',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_to_user_id` (`to_user_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_session_create` (`session_id`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天消息表-202401分表';

-- 聊天消息表 - 2024年2月分表示例
DROP TABLE IF EXISTS `chat_message_archive_202402`;
CREATE TABLE `chat_message_archive_202402` LIKE `chat_message_archive_202401`;
ALTER TABLE `chat_message_archive_202402` COMMENT = '聊天消息表-202402分表';

-- ============================================================
-- 42. Redis缓存命中率统计表
-- 用于监控缓存效果，优化缓存策略
-- ============================================================
DROP TABLE IF EXISTS `redis_cache_hit_log`;
CREATE TABLE `redis_cache_hit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '缓存键前缀',
  `hit` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '命中次数',
  `total` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '总请求次数',
  `hit_rate` DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT '命中率',
  `stat_date` DATE NOT NULL COMMENT '统计日期',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cache_key_date` (`cache_key`, `stat_date`),
  KEY `idx_stat_date` (`stat_date`),
  KEY `idx_hit_rate` (`hit_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Redis缓存命中率统计表';

-- ============================================================
-- 43. 接口限流日志表
-- 记录限流触发情况，用于分析和封禁高频IP
-- ============================================================
DROP TABLE IF EXISTS `api_rate_limit_log`;
CREATE TABLE `api_rate_limit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '请求IP',
  `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
  `endpoint` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '接口路径',
  `hits` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '窗口内请求次数',
  `blocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被封禁 0否 1是',
  `block_until` DATETIME DEFAULT NULL COMMENT '封禁到期时间',
  `user_type` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户类型 0普通用户 1打手 2管理员',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_blocked` (`blocked`),
  KEY `idx_endpoint` (`endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='接口限流日志表';

-- ============================================================
-- 44. 分布式锁日志表
-- 记录分布式锁的获取和释放，用于排查死锁问题
-- ============================================================
DROP TABLE IF EXISTS `distributed_lock_log`;
CREATE TABLE `distributed_lock_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lock_key` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '锁键名',
  `holder` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '持有者标识（进程ID/请求ID）',
  `acquire_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '获取时间',
  `release_time` DATETIME DEFAULT NULL COMMENT '释放时间',
  `timeout` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '锁超时时间（秒）',
  `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '重试次数',
  `wait_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '等待时间（毫秒）',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态 1持有中 2已释放 3超时释放',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_lock_key` (`lock_key`),
  KEY `idx_holder` (`holder`),
  KEY `idx_status` (`status`),
  KEY `idx_acquire_time` (`acquire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分布式锁日志表';

-- ============================================================
-- 45. 慢查询日志表
-- 记录执行时间超过阈值的SQL，用于性能优化
-- ============================================================
DROP TABLE IF EXISTS `slow_query_log`;
CREATE TABLE `slow_query_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trace_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '链路追踪ID',
  `sql` TEXT NOT NULL COMMENT 'SQL语句',
  `params` TEXT COMMENT '参数JSON',
  `execute_time` DECIMAL(10,3) NOT NULL DEFAULT 0.000 COMMENT '执行时间（毫秒）',
  `rows` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '影响行数',
  `endpoint` VARCHAR(255) DEFAULT '' COMMENT '请求接口',
  `ip` VARCHAR(64) DEFAULT '' COMMENT '请求IP',
  `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_trace_id` (`trace_id`),
  KEY `idx_execute_time` (`execute_time`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_endpoint` (`endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='慢查询日志表';

-- ============================================================
-- 40. 仲裁举证模板表
-- ============================================================
DROP TABLE IF EXISTS `arbitration_evidence_tpl`;
CREATE TABLE `arbitration_evidence_tpl` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dispute_type` VARCHAR(32) NOT NULL COMMENT '纠纷类型: player_late/negative_service/player_refund/demand_change/other',
  `title` VARCHAR(128) NOT NULL COMMENT '模板标题',
  `description` VARCHAR(512) DEFAULT '' COMMENT '模板描述',
  `required_items_json` JSON DEFAULT NULL COMMENT '必传举证项JSON',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `sort` INT UNSIGNED DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dispute_type` (`dispute_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='仲裁举证模板表';

INSERT INTO `arbitration_evidence_tpl` (`dispute_type`, `title`, `description`, `required_items_json`, `status`, `sort`) VALUES
('player_late', '打手迟到举证', '请上传约定时间截图、聊天记录等证明打手迟到的材料', '[{"key":"time_screenshot","label":"约定时间截图","required":true,"type":"image"},{"key":"chat_record","label":"聊天记录截图","required":true,"type":"image"},{"key":"video","label":"录屏视频（可选）","required":false,"type":"video"}]', 1, 1),
('negative_service', '消极服务举证', '请上传战绩截图、聊天记录、录屏等证明消极服务的材料', '[{"key":"record_screenshot","label":"战绩截图","required":true,"type":"image"},{"key":"chat_record","label":"聊天记录截图","required":true,"type":"image"},{"key":"video","label":"录屏视频","required":false,"type":"video"}]', 1, 2),
('player_refund', '玩家无故退款举证', '请上传服务完成证明、聊天记录等材料', '[{"key":"service_proof","label":"服务完成证明","required":true,"type":"image"},{"key":"chat_record","label":"聊天记录截图","required":true,"type":"image"}]', 1, 3),
('demand_change', '需求变更纠纷举证', '请上传原始需求截图、变更聊天记录等材料', '[{"key":"original_demand","label":"原始需求截图","required":true,"type":"image"},{"key":"change_record","label":"需求变更聊天记录","required":true,"type":"image"}]', 1, 4),
('other', '其他纠纷举证', '请上传相关证明材料', '[{"key":"description","label":"纠纷描述","required":true,"type":"text"},{"key":"evidence","label":"证明材料","required":true,"type":"image"}]', 1, 5);

-- ============================================================
-- 41. 仲裁判责规则库表
-- ============================================================
DROP TABLE IF EXISTS `arbitration_rule`;
CREATE TABLE `arbitration_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_type` VARCHAR(32) NOT NULL COMMENT '规则类型: player_late/negative_service/player_unprovoked_refund/demand_change/fraud',
  `fault_side` VARCHAR(16) NOT NULL COMMENT '责任方: player/buyer/both',
  `penalty_type` VARCHAR(32) NOT NULL COMMENT '处罚类型: refund_ratio/deduct_credit/deduct_deposit/ban_account',
  `penalty_value` VARCHAR(64) DEFAULT '' COMMENT '处罚值（比例/分数/金额/天数）',
  `description` VARCHAR(512) DEFAULT '' COMMENT '规则描述',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rule_type` (`rule_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='仲裁判责规则库表';

INSERT INTO `arbitration_rule` (`rule_type`, `fault_side`, `penalty_type`, `penalty_value`, `description`, `status`) VALUES
('player_late', 'player', 'refund_ratio', '100', '打手迟到超过30分钟，全额退款', 1),
('player_late', 'player', 'deduct_credit', '10', '打手迟到扣信用分10分', 1),
('negative_service', 'player', 'refund_ratio', '50', '消极服务，退款50%', 1),
('negative_service', 'player', 'deduct_credit', '20', '消极服务扣信用分20分', 1),
('negative_service', 'player', 'deduct_deposit', '10000', '严重消极服务扣除保证金100元', 1),
('player_unprovoked_refund', 'buyer', 'deduct_credit', '5', '玩家无故退款扣信用分5分', 1),
('player_unprovoked_refund', 'buyer', 'refund_ratio', '30', '玩家无故退款仅退30%', 1),
('demand_change', 'both', 'refund_ratio', '50', '需求变更双方各担50%', 1),
('fraud', 'player', 'ban_account', 'forever', '欺诈行为永久封号', 1),
('fraud', 'player', 'deduct_deposit', 'all', '欺诈扣除全部保证金', 1);

-- ============================================================
-- 42. 仲裁案件表
-- ============================================================
DROP TABLE IF EXISTS `arbitration_case`;
CREATE TABLE `arbitration_case` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单ID',
  `session_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '售后会话ID',
  `applicant_id` BIGINT UNSIGNED NOT NULL COMMENT '申请人ID',
  `respondent_id` BIGINT UNSIGNED NOT NULL COMMENT '被申请人ID',
  `dispute_type` VARCHAR(32) NOT NULL COMMENT '纠纷类型',
  `description` TEXT COMMENT '纠纷描述',
  `evidence_json` JSON DEFAULT NULL COMMENT '举证材料JSON',
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT '状态: pending/processing/resolved',
  `result` TEXT COMMENT '处理结果',
  `handler_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '处理人ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finish_time` DATETIME DEFAULT NULL COMMENT '结案时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_applicant_id` (`applicant_id`),
  KEY `idx_respondent_id` (`respondent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='仲裁案件表';

-- ============================================================
-- 43. 仲裁举证材料表
-- ============================================================
DROP TABLE IF EXISTS `arbitration_evidence`;
CREATE TABLE `arbitration_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL COMMENT '案件ID',
  `uploader_id` BIGINT UNSIGNED NOT NULL COMMENT '上传人ID',
  `type` VARCHAR(16) NOT NULL COMMENT '类型: image/video/audio/text',
  `file_url` VARCHAR(512) DEFAULT '' COMMENT '文件URL',
  `description` VARCHAR(255) DEFAULT '' COMMENT '描述',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_case_id` (`case_id`),
  KEY `idx_uploader_id` (`uploader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='仲裁举证材料表';

-- ============================================================
-- 44. 打手服务保证金表
-- ============================================================
DROP TABLE IF EXISTS `service_deposit`;
CREATE TABLE `service_deposit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `player_user_id` BIGINT UNSIGNED NOT NULL COMMENT '打手用户ID',
  `amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '保证金总额（分）',
  `status` VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT '状态: active/frozen/withdrawn',
  `freeze_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '冻结金额（分）',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_player_user_id` (`player_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='打手服务保证金表';

-- ============================================================
-- 45. 保证金流水表
-- ============================================================
DROP TABLE IF EXISTS `service_deposit_log`;
CREATE TABLE `service_deposit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `player_user_id` BIGINT UNSIGNED NOT NULL COMMENT '打手用户ID',
  `type` VARCHAR(16) NOT NULL COMMENT '类型: deposit/deduct/refund/freeze/unfreeze',
  `amount` BIGINT NOT NULL DEFAULT 0 COMMENT '变动金额（分），正为加，负为减',
  `balance` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动后余额（分）',
  `related_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联ID（订单ID/案件ID等）',
  `description` VARCHAR(255) DEFAULT '' COMMENT '描述',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_player_user_id` (`player_user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='保证金流水表';

-- ============================================================
-- 46. 代练违禁规则表
-- ============================================================
DROP TABLE IF EXISTS `anti_boosting_rule`;
CREATE TABLE `anti_boosting_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `keyword` VARCHAR(64) NOT NULL COMMENT '关键词',
  `level` VARCHAR(16) NOT NULL COMMENT '级别: warn/intercept/ban',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keyword` (`keyword`),
  KEY `idx_level` (`level`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='代练违禁规则表';

INSERT INTO `anti_boosting_rule` (`keyword`, `level`, `status`) VALUES
('代练', 'intercept', 1),
('上分', 'intercept', 1),
('代打', 'intercept', 1),
('外挂', 'ban', 1),
('破解', 'ban', 1),
('线下交易', 'ban', 1),
('赌博', 'ban', 1),
('段位代练', 'intercept', 1),
('战力代刷', 'intercept', 1),
('刷段位', 'intercept', 1),
('刷战力', 'intercept', 1),
('代练上分', 'intercept', 1),
('私下交易', 'ban', 1),
('加微信', 'warn', 1),
('加QQ', 'warn', 1);

-- ============================================================
-- 47. 代练拦截日志表
-- ============================================================
DROP TABLE IF EXISTS `anti_boosting_log`;
CREATE TABLE `anti_boosting_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(16) NOT NULL COMMENT '来源: order/chat/private_chat/group_chat',
  `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源ID（订单ID/会话ID等）',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `matched_keyword` VARCHAR(64) NOT NULL COMMENT '匹配到的关键词',
  `level` VARCHAR(16) NOT NULL COMMENT '级别: warn/intercept/ban',
  `handled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已处理',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_level` (`level`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='代练拦截日志表';

-- ============================================================
-- 48. 分角色协议版本表
-- ============================================================
DROP TABLE IF EXISTS `agreement_role_version`;
CREATE TABLE `agreement_role_version` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role` VARCHAR(16) NOT NULL COMMENT '角色: player/buyer/distributor/club',
  `agreement_type` VARCHAR(32) NOT NULL COMMENT '协议类型: user_service/privacy/club_entry/player_entry',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本号',
  `content` TEXT COMMENT '协议内容',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否当前生效版本',
  `publish_time` DATETIME DEFAULT NULL COMMENT '发布时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_type_version` (`role`, `agreement_type`, `version`),
  KEY `idx_role` (`role`),
  KEY `idx_agreement_type` (`agreement_type`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分角色协议版本表';

INSERT INTO `agreement_role_version` (`role`, `agreement_type`, `version`, `content`, `is_active`, `publish_time`) VALUES
('buyer', 'user_service', 1, '玩家用户服务协议...', 1, NOW()),
('buyer', 'privacy', 1, '玩家隐私政策...', 1, NOW()),
('player', 'user_service', 1, '打手用户服务协议...', 1, NOW()),
('player', 'privacy', 1, '打手隐私政策...', 1, NOW()),
('player', 'player_entry', 1, '打手入驻协议...', 1, NOW()),
('distributor', 'user_service', 1, '分销商服务协议...', 1, NOW()),
('distributor', 'privacy', 1, '分销商隐私政策...', 1, NOW()),
('club', 'user_service', 1, '俱乐部服务协议...', 1, NOW()),
('club', 'privacy', 1, '俱乐部隐私政策...', 1, NOW()),
('club', 'club_entry', 1, '俱乐部入驻协议...', 1, NOW());

-- ============================================================
-- 49. 协议签署记录表
-- ============================================================
DROP TABLE IF EXISTS `agreement_sign_log`;
CREATE TABLE `agreement_sign_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `role` VARCHAR(16) NOT NULL COMMENT '角色: player/buyer/distributor/club',
  `agreement_type` VARCHAR(32) NOT NULL COMMENT '协议类型',
  `version` INT UNSIGNED NOT NULL COMMENT '签署版本号',
  `sign_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签署时间',
  `ip` VARCHAR(64) DEFAULT '' COMMENT '签署IP',
  `device` VARCHAR(128) DEFAULT '' COMMENT '设备信息',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role_type_version` (`role`, `agreement_type`, `version`),
  KEY `idx_sign_time` (`sign_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='协议签署记录表';

-- ============================================================
-- 40. 订单类型配置表
-- ============================================================
DROP TABLE IF EXISTS `order_type_config`;
CREATE TABLE `order_type_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(32) NOT NULL COMMENT '订单类型标识: instant即时/appointment预约/team车队/teaching教学',
  `name` VARCHAR(64) NOT NULL COMMENT '类型名称',
  `icon` VARCHAR(255) DEFAULT '' COMMENT '图标URL',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `sort` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type` (`type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单类型配置表';

INSERT INTO `order_type_config` (`type`, `name`, `icon`, `status`, `sort`) VALUES
('instant', '即时单', '', 1, 1),
('appointment', '预约单', '', 1, 2),
('team', '车队单', '', 1, 3),
('teaching', '教学单', '', 1, 4);

-- ============================================================
-- 41. 打手标签表
-- ============================================================
DROP TABLE IF EXISTS `player_tag`;
CREATE TABLE `player_tag` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `player_user_id` BIGINT UNSIGNED NOT NULL COMMENT '打手用户ID',
  `tag_type` VARCHAR(32) NOT NULL COMMENT '标签类型: game游戏/position位置/voice声线/rank段位/skill擅长',
  `tag_value` VARCHAR(128) NOT NULL COMMENT '标签值',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_player_id` (`player_user_id`),
  KEY `idx_tag_type` (`tag_type`),
  KEY `idx_player_type` (`player_user_id`, `tag_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='打手标签表';

-- ============================================================
-- 42. 游戏列表表
-- ============================================================
DROP TABLE IF EXISTS `game_list`;
CREATE TABLE `game_list` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL COMMENT '游戏名称',
  `icon` VARCHAR(255) DEFAULT '' COMMENT '游戏图标',
  `category` VARCHAR(32) DEFAULT '' COMMENT '游戏分类: moba/fps/rpg等',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `sort` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='游戏列表表';

-- ============================================================
-- 43. 服务计时存证表
-- ============================================================
DROP TABLE IF EXISTS `order_service_timer`;
CREATE TABLE `order_service_timer` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `start_time` DATETIME DEFAULT NULL COMMENT '开始时间',
  `pause_time` DATETIME DEFAULT NULL COMMENT '暂停时间',
  `total_seconds` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计服务秒数',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '状态 0未开始 1进行中 2已暂停 3已结束',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_id` (`order_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='服务计时存证表';

-- ============================================================
-- 44. 履约凭证表
-- ============================================================
DROP TABLE IF EXISTS `order_evidence`;
CREATE TABLE `order_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `uploader_id` BIGINT UNSIGNED NOT NULL COMMENT '上传者用户ID',
  `type` VARCHAR(32) NOT NULL COMMENT '类型: gameplay_video录屏/rank_screenshot战绩截图/other其他',
  `file_url` VARCHAR(512) NOT NULL COMMENT '文件URL',
  `description` VARCHAR(255) DEFAULT '' COMMENT '描述',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_uploader_id` (`uploader_id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='履约凭证表';

-- ============================================================
-- 45. 中途退单规则表
-- ============================================================
DROP TABLE IF EXISTS `order_refund_rule`;
CREATE TABLE `order_refund_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL COMMENT '规则名称',
  `minutes_threshold` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分钟阈值（服务时长）',
  `refund_ratio` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '退款比例 0-100',
  `description` VARCHAR(255) DEFAULT '' COMMENT '规则描述',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='中途退单规则表';

INSERT INTO `order_refund_rule` (`name`, `minutes_threshold`, `refund_ratio`, `description`, `status`) VALUES
('10分钟内全额退款', 10, 100.00, '服务开始10分钟内可全额退款', 1),
('30分钟内退70%', 30, 70.00, '服务超过10分钟但30分钟内退70%', 1),
('60分钟内退50%', 60, 50.00, '服务超过30分钟但60分钟内退50%', 1),
('超过60分钟不退', 999999, 0.00, '服务超过60分钟不予退款', 1);

-- ============================================================
-- 46. 竞价抢单表
-- ============================================================
DROP TABLE IF EXISTS `order_bid`;
CREATE TABLE `order_bid` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `player_user_id` BIGINT UNSIGNED NOT NULL COMMENT '竞价打手ID',
  `bid_price` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '竞价价格（分）',
  `bid_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '竞价时间',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '状态 0竞价中 1中标 2未中标 3已取消',
  `is_winner` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否中标',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_player_id` (`player_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_order_status` (`order_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='竞价抢单表';

-- ============================================================
-- 47. 预约单信息表
-- ============================================================
DROP TABLE IF EXISTS `order_appointment`;
CREATE TABLE `order_appointment` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `appoint_time` DATETIME NOT NULL COMMENT '预约时间',
  `player_user_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '指定打手ID（可选）',
  `is_confirmed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '打手是否确认',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_id` (`order_id`),
  KEY `idx_appoint_time` (`appoint_time`),
  KEY `idx_player_id` (`player_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='预约单信息表';

-- ============================================================
-- 48. 套餐配置表
-- ============================================================
DROP TABLE IF EXISTS `order_package`;
CREATE TABLE `order_package` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT '套餐名称',
  `game_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联游戏ID',
  `type` VARCHAR(32) NOT NULL COMMENT '套餐类型: duration时长型/games局数型',
  `duration_hours` DECIMAL(5,1) NOT NULL DEFAULT 0.0 COMMENT '时长（小时）',
  `games_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '局数',
  `price` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '套餐价格（分）',
  `original_price` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '原价（分）',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `sort` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_game_id` (`game_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='套餐配置表';

-- ============================================================
-- 49. 收藏打手表
-- ============================================================
DROP TABLE IF EXISTS `player_favorite`;
CREATE TABLE `player_favorite` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `player_user_id` BIGINT UNSIGNED NOT NULL COMMENT '打手用户ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_player` (`user_id`, `player_user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_player_id` (`player_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏打手表';

-- ============================================================
-- 73. 俱乐部成员表
-- ============================================================
DROP TABLE IF EXISTS `club_member`;
CREATE TABLE `club_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `role` VARCHAR(32) NOT NULL DEFAULT 'member' COMMENT '角色 founder/manager/member',
  `join_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '加入时间',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1正常 0已退出 2待审核',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_club_user` (`club_id`, `user_id`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部成员表';

-- ============================================================
-- 74. 俱乐部内部订单表
-- ============================================================
DROP TABLE IF EXISTS `club_internal_order`;
CREATE TABLE `club_internal_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `order_no` VARCHAR(32) NOT NULL COMMENT '内部订单号',
  `title` VARCHAR(255) NOT NULL COMMENT '订单标题',
  `reward` BIGINT NOT NULL DEFAULT 0 COMMENT '赏金（分）',
  `player_user_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '接单打手用户ID',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待接单 1已接单 2进行中 3待验收 4已完成 5已取消',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_player_user_id` (`player_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部内部订单表';

-- ============================================================
-- 75. 俱乐部优惠券表
-- ============================================================
DROP TABLE IF EXISTS `club_coupon`;
CREATE TABLE `club_coupon` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `name` VARCHAR(128) NOT NULL COMMENT '优惠券名称',
  `type` VARCHAR(32) NOT NULL DEFAULT 'discount' COMMENT '类型 discount/new_user',
  `value` BIGINT NOT NULL DEFAULT 0 COMMENT '优惠金额（分）或折扣值',
  `min_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '最低使用金额（分）',
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发放总量',
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已使用数量',
  `start_time` DATETIME DEFAULT NULL COMMENT '生效开始时间',
  `end_time` DATETIME DEFAULT NULL COMMENT '生效结束时间',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_time` (`start_time`, `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部优惠券表';

-- ============================================================
-- 76. 用户领券记录表
-- ============================================================
DROP TABLE IF EXISTS `club_coupon_user`;
CREATE TABLE `club_coupon_user` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` BIGINT UNSIGNED NOT NULL COMMENT '优惠券ID',
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1未使用 2已使用 3已过期',
  `used_order_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '使用的订单ID',
  `receive_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '领取时间',
  `use_time` DATETIME DEFAULT NULL COMMENT '使用时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coupon_id` (`coupon_id`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  UNIQUE KEY `uk_coupon_user` (`coupon_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户领券记录表';

-- ============================================================
-- 77. 俱乐部战绩/动态表
-- ============================================================
DROP TABLE IF EXISTS `club_dynamic`;
CREATE TABLE `club_dynamic` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `player_user_id` BIGINT UNSIGNED NOT NULL COMMENT '发布打手用户ID',
  `type` VARCHAR(32) NOT NULL DEFAULT 'record' COMMENT '类型 record(战绩)/dynamic(动态)',
  `title` VARCHAR(255) DEFAULT '' COMMENT '标题',
  `content` TEXT COMMENT '内容',
  `images_json` JSON DEFAULT NULL COMMENT '图片JSON数组',
  `video_url` VARCHAR(512) DEFAULT '' COMMENT '视频URL',
  `like_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数',
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览数',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1已通过 2已驳回',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_player_user_id` (`player_user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部战绩/动态表';

-- ============================================================
-- 78. 俱乐部分店/分区表
-- ============================================================
DROP TABLE IF EXISTS `club_branch`;
CREATE TABLE `club_branch` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `name` VARCHAR(128) NOT NULL COMMENT '分店/分区名称',
  `game_id` VARCHAR(64) DEFAULT '' COMMENT '游戏ID',
  `manager_user_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '分店长用户ID',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1正常 0已关闭',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_game_id` (`game_id`),
  KEY `idx_manager_user_id` (`manager_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部分店/分区表';

-- ============================================================
-- 79. 俱乐部日统计表
-- ============================================================
DROP TABLE IF EXISTS `club_daily_stat`;
CREATE TABLE `club_daily_stat` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `stat_date` DATE NOT NULL COMMENT '统计日期',
  `order_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单数',
  `total_revenue` BIGINT NOT NULL DEFAULT 0 COMMENT '总营收（分）',
  `player_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '活跃打手数',
  `new_member_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '新增成员数',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_club_date` (`club_id`, `stat_date`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部日统计表';

-- ============================================================
-- 80. 保证金阶梯配置表
-- ============================================================
DROP TABLE IF EXISTS `club_deposit_tier`;
CREATE TABLE `club_deposit_tier` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_type` VARCHAR(32) NOT NULL DEFAULT 'blue_v' COMMENT '俱乐部类型 blue_v/green_v',
  `tier_name` VARCHAR(64) NOT NULL COMMENT '阶梯名称',
  `revenue_threshold` BIGINT NOT NULL DEFAULT 0 COMMENT '月流水阈值（分）',
  `deposit_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '对应保证金（分）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_type` (`club_type`),
  KEY `idx_status` (`status`),
  KEY `idx_revenue_threshold` (`revenue_threshold`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='保证金阶梯配置表';

-- 预置保证金阶梯配置
INSERT INTO `club_deposit_tier` (`club_type`, `tier_name`, `revenue_threshold`, `deposit_amount`, `status`) VALUES
('blue_v', '入门级', 0, 100000, 1),
('blue_v', '进阶级', 500000, 80000, 1),
('blue_v', '精英级', 2000000, 50000, 1),
('blue_v', '领袖级', 5000000, 30000, 1),
('green_v', '入门级', 0, 50000, 1),
('green_v', '进阶级', 200000, 30000, 1),
('green_v', '精英级', 1000000, 20000, 1),
('green_v', '领袖级', 3000000, 10000, 1);

-- ============================================================
-- 81. 俱乐部公告表
-- ============================================================
DROP TABLE IF EXISTS `club_announcement`;
CREATE TABLE `club_announcement` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` BIGINT UNSIGNED NOT NULL COMMENT '俱乐部ID',
  `title` VARCHAR(255) NOT NULL COMMENT '公告标题',
  `content` TEXT COMMENT '公告内容',
  `is_top` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否置顶',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_id` (`club_id`),
  KEY `idx_is_top` (`is_top`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='俱乐部公告表';

-- ============================================================
-- 40. 分账规则表
-- ============================================================
DROP TABLE IF EXISTS `profit_share_rule`;
CREATE TABLE `profit_share_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT '规则名称',
  `type` TINYINT NOT NULL DEFAULT 1 COMMENT '规则类型 1-默认 2-按服务类型 3-按俱乐部',
  `service_type_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '服务类型ID',
  `club_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '俱乐部ID',
  `player_ratio` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '打手比例%',
  `club_ratio` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '俱乐部比例%',
  `distributor_ratio` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '分销商比例%',
  `platform_ratio` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '平台比例%',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否默认规则',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分账规则表';

-- 默认分账规则
INSERT INTO `profit_share_rule` (`name`, `type`, `player_ratio`, `club_ratio`, `distributor_ratio`, `platform_ratio`, `is_default`, `status`) VALUES
('默认分账规则', 1, 60.00, 10.00, 5.00, 25.00, 1, 1);

-- ============================================================
-- 41. 分账记录表
-- ============================================================
DROP TABLE IF EXISTS `profit_share_record`;
CREATE TABLE `profit_share_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `order_no` VARCHAR(64) NOT NULL COMMENT '订单号',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `role` TINYINT NOT NULL COMMENT '角色 1-打手 2-俱乐部 3-分销商 4-平台',
  `amount` BIGINT NOT NULL DEFAULT 0 COMMENT '分账金额（分）',
  `ratio` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '分账比例%',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态 0-待结算 1-已结算 2-已冻结 3-已退款',
  `share_time` DATETIME DEFAULT NULL COMMENT '分账时间',
  `transaction_id` VARCHAR(128) DEFAULT '' COMMENT '微信支付交易号',
  `settle_batch_no` VARCHAR(64) DEFAULT '' COMMENT '结算批次号',
  `remark` VARCHAR(255) DEFAULT '' COMMENT '备注',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分账记录表';

-- ============================================================
-- 42. 退款反向分账表
-- ============================================================
DROP TABLE IF EXISTS `profit_share_refund`;
CREATE TABLE `profit_share_refund` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `refund_id` BIGINT UNSIGNED NOT NULL COMMENT '退款单ID',
  `refund_no` VARCHAR(64) DEFAULT '' COMMENT '退款单号',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `role` TINYINT NOT NULL COMMENT '角色 1-打手 2-俱乐部 3-分销商 4-平台',
  `refund_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '退款追回金额（分）',
  `origin_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '原分账金额（分）',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态 0-处理中 1-已完成 2-失败',
  `operator` BIGINT UNSIGNED DEFAULT 0 COMMENT '操作人ID',
  `remark` VARCHAR(255) DEFAULT '' COMMENT '备注',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_refund_id` (`refund_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='退款反向分账表';

-- ============================================================
-- 43. 个税配置表
-- ============================================================
DROP TABLE IF EXISTS `tax_config`;
CREATE TABLE `tax_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role` TINYINT NOT NULL COMMENT '角色 1-打手 2-俱乐部 3-分销商',
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '税率%',
  `threshold` BIGINT NOT NULL DEFAULT 0 COMMENT '起征点（分）',
  `quick_deduction` BIGINT NOT NULL DEFAULT 0 COMMENT '速算扣除数（分）',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个税配置表';

-- 默认个税配置
INSERT INTO `tax_config` (`role`, `tax_rate`, `threshold`, `quick_deduction`, `status`) VALUES
(1, 20.00, 80000, 0, 1),
(2, 25.00, 0, 0, 1),
(3, 20.00, 80000, 0, 1);

-- ============================================================
-- 44. 个税代扣记录表
-- ============================================================
DROP TABLE IF EXISTS `tax_record`;
CREATE TABLE `tax_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `role` TINYINT NOT NULL COMMENT '角色 1-打手 2-俱乐部 3-分销商',
  `amount` BIGINT NOT NULL DEFAULT 0 COMMENT '计税金额（分）',
  `tax_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '代扣税额（分）',
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '适用税率%',
  `threshold` BIGINT NOT NULL DEFAULT 0 COMMENT '起征点（分）',
  `month` VARCHAR(7) NOT NULL COMMENT '所属月份 YYYY-MM',
  `withdraw_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联提现ID',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态 1-已代扣 2-已申报 3-已完税',
  `certificate_no` VARCHAR(128) DEFAULT '' COMMENT '完税凭证号',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_month` (`month`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个税代扣记录表';

-- ============================================================
-- 45. 子商户账户表
-- ============================================================
DROP TABLE IF EXISTS `merchant_account`;
CREATE TABLE `merchant_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `role` TINYINT NOT NULL COMMENT '角色 1-打手 2-俱乐部 3-分销商',
  `account_type` TINYINT NOT NULL COMMENT '账户类型 1-微信 2-支付宝 3-银行卡',
  `account_no` VARCHAR(255) NOT NULL COMMENT '账户号（加密存储）',
  `account_name` VARCHAR(128) NOT NULL COMMENT '账户姓名/名称',
  `bank_name` VARCHAR(128) DEFAULT '' COMMENT '银行名称',
  `bank_branch` VARCHAR(128) DEFAULT '' COMMENT '开户支行',
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已验证',
  `verify_time` DATETIME DEFAULT NULL COMMENT '验证时间',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role` (`role`),
  KEY `idx_account_type` (`account_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='子商户账户表';

-- ============================================================
-- 46. 提现批次表
-- ============================================================
DROP TABLE IF EXISTS `withdraw_batch`;
CREATE TABLE `withdraw_batch` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_no` VARCHAR(64) NOT NULL COMMENT '批次号',
  `total_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '总金额（分）',
  `total_count` INT NOT NULL DEFAULT 0 COMMENT '总笔数',
  `success_count` INT NOT NULL DEFAULT 0 COMMENT '成功笔数',
  `fail_count` INT NOT NULL DEFAULT 0 COMMENT '失败笔数',
  `success_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '成功金额（分）',
  `fail_amount` BIGINT NOT NULL DEFAULT 0 COMMENT '失败金额（分）',
  `channel` TINYINT NOT NULL DEFAULT 1 COMMENT '提现渠道 1-微信 2-支付宝 3-银行卡',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态 0-待处理 1-处理中 2-已完成 3-部分失败 4-全部失败',
  `operator` BIGINT UNSIGNED DEFAULT 0 COMMENT '操作人ID',
  `operator_name` VARCHAR(64) DEFAULT '' COMMENT '操作人姓名',
  `process_time` DATETIME DEFAULT NULL COMMENT '处理时间',
  `complete_time` DATETIME DEFAULT NULL COMMENT '完成时间',
  `remark` VARCHAR(255) DEFAULT '' COMMENT '备注',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_no` (`batch_no`),
  KEY `idx_status` (`status`),
  KEY `idx_channel` (`channel`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提现批次表';

-- ============================================================
-- 40. 宵禁拦截日志表
-- 记录未成年人在宵禁时间被拦截的操作
-- ============================================================
DROP TABLE IF EXISTS `minor_curfew_log`;
CREATE TABLE `minor_curfew_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `action_type` VARCHAR(32) NOT NULL COMMENT '操作类型: order(下单)/pay(支付)/reward(打赏)/join_group(进群)',
  `blocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '拦截时间',
  `ip` VARCHAR(64) DEFAULT '' COMMENT '请求IP',
  `device_info` VARCHAR(255) DEFAULT '' COMMENT '设备信息',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_blocked_at` (`blocked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='宵禁拦截日志表';

-- ============================================================
-- 41. 消费预警记录表
-- 未成年人月消费达到阈值时的预警记录
-- ============================================================
DROP TABLE IF EXISTS `minor_consume_warning`;
CREATE TABLE `minor_consume_warning` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `month` VARCHAR(7) NOT NULL COMMENT '月份 YYYY-MM',
  `consume_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当月消费金额(分)',
  `warning_level` TINYINT NOT NULL DEFAULT 1 COMMENT '预警等级: 1-80%阈值提醒 2-100%需二次验证',
  `sent_at` DATETIME DEFAULT NULL COMMENT '发送时间',
  `guardian_openid` VARCHAR(128) DEFAULT '' COMMENT '监护人openid',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_month` (`user_id`, `month`),
  KEY `idx_warning_level` (`warning_level`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消费预警记录表';

-- ============================================================
-- 42. 家长监护绑定表
-- 家长与未成年人账号的绑定关系
-- ============================================================
DROP TABLE IF EXISTS `parent_guardian_bind`;
CREATE TABLE `parent_guardian_bind` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `child_user_id` BIGINT UNSIGNED NOT NULL COMMENT '孩子用户ID',
  `parent_openid` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '家长微信openid',
  `parent_phone` VARCHAR(20) DEFAULT '' COMMENT '家长手机号',
  `bind_time` DATETIME DEFAULT NULL COMMENT '绑定时间',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0-待确认 1-已绑定 2-已解绑',
  `expire_time` DATETIME DEFAULT NULL COMMENT '过期时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_child_parent` (`child_user_id`, `parent_openid`),
  KEY `idx_parent_openid` (`parent_openid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='家长监护绑定表';

-- ============================================================
-- 43. 监护设置表
-- 家长对孩子账号的监护配置
-- ============================================================
DROP TABLE IF EXISTS `parent_guardian_setting`;
CREATE TABLE `parent_guardian_setting` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bind_id` BIGINT UNSIGNED NOT NULL COMMENT '绑定ID',
  `monthly_limit` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '月消费限额(分)，0表示不限制',
  `allow_order` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否允许下单',
  `allow_reward` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否允许打赏',
  `is_frozen` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否冻结账号',
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bind_id` (`bind_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='监护设置表';

-- ============================================================
-- 44. 消费账单月报表
-- 未成年人月度消费账单
-- ============================================================
DROP TABLE IF EXISTS `parent_consume_report`;
CREATE TABLE `parent_consume_report` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bind_id` BIGINT UNSIGNED NOT NULL COMMENT '绑定ID',
  `month` VARCHAR(7) NOT NULL COMMENT '月份 YYYY-MM',
  `total_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '总消费金额(分)',
  `order_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单数量',
  `report_data_json` JSON DEFAULT NULL COMMENT '账单详情JSON',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bind_month` (`bind_id`, `month`),
  KEY `idx_month` (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消费账单月报表';

-- ============================================================
-- 45. 活体缓存表
-- 活体检测7天缓存，减少第三方接口调用
-- ============================================================
DROP TABLE IF EXISTS `realname_cache`;
CREATE TABLE `realname_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `cache_key` VARCHAR(64) NOT NULL COMMENT '缓存key(如: liveness_verify)',
  `expire_time` DATETIME NOT NULL COMMENT '过期时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_cache` (`user_id`, `cache_key`),
  KEY `idx_expire_time` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活体缓存表';

-- ============================================================
-- 81. 管理员操作日志表
-- ============================================================
DROP TABLE IF EXISTS `admin_operation_log`;
CREATE TABLE `admin_operation_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '管理员ID',
  `username` VARCHAR(64) DEFAULT '' COMMENT '管理员用户名',
  `module` VARCHAR(64) NOT NULL COMMENT '操作模块',
  `action` VARCHAR(64) NOT NULL COMMENT '操作动作',
  `ip` VARCHAR(64) DEFAULT '' COMMENT '操作IP',
  `device` VARCHAR(255) DEFAULT '' COMMENT '设备信息',
  `params_json` JSON DEFAULT NULL COMMENT '请求参数JSON',
  `result` TINYINT DEFAULT 1 COMMENT '操作结果 1成功 0失败',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_module` (`module`),
  KEY `idx_action` (`action`),
  KEY `idx_ip` (`ip`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员操作日志表';

-- ============================================================
-- 82. 批量操作日志表
-- ============================================================
DROP TABLE IF EXISTS `batch_operation_log`;
CREATE TABLE `batch_operation_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT '操作管理员ID',
  `type` VARCHAR(32) NOT NULL COMMENT '操作类型 order_cancel/order_complete/user_ban等',
  `total_count` INT UNSIGNED DEFAULT 0 COMMENT '总数量',
  `success_count` INT UNSIGNED DEFAULT 0 COMMENT '成功数量',
  `fail_count` INT UNSIGNED DEFAULT 0 COMMENT '失败数量',
  `amount` BIGINT DEFAULT 0 COMMENT '涉及金额（分）',
  `status` TINYINT DEFAULT 0 COMMENT '状态 0处理中 1成功 2部分失败 3全部失败',
  `confirm_method` VARCHAR(32) DEFAULT 'qr' COMMENT '确认方式 qr/sms/password',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='批量操作日志表';

-- ============================================================
-- 83. AI风险预警表
-- ============================================================
DROP TABLE IF EXISTS `ai_risk_alert`;
CREATE TABLE `ai_risk_alert` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type` VARCHAR(32) NOT NULL COMMENT '预警类型 high_refund_rate/same_ip_regist/large_withdraw/midnight_order/frequency_order',
  `user_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '关联用户ID',
  `risk_level` VARCHAR(16) NOT NULL DEFAULT 'medium' COMMENT '风险等级 low/medium/high',
  `description` VARCHAR(512) DEFAULT '' COMMENT '风险描述',
  `data_json` JSON DEFAULT NULL COMMENT '风险数据JSON',
  `status` TINYINT DEFAULT 0 COMMENT '状态 0待处理 1处理中 2已处理 3已忽略',
  `handler_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '处理人ID',
  `handle_time` DATETIME DEFAULT NULL COMMENT '处理时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_risk_level` (`risk_level`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI风险预警表';

-- ============================================================
-- 84. 优惠券模板表
-- ============================================================
DROP TABLE IF EXISTS `marketing_coupon_template`;
CREATE TABLE `marketing_coupon_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT '优惠券名称',
  `type` VARCHAR(32) NOT NULL DEFAULT 'discount' COMMENT '类型 discount满减 coupon代金券 free_free包邮',
  `discount_type` VARCHAR(32) DEFAULT 'fixed' COMMENT '折扣类型 fixed固定金额 percentage百分比',
  `discount_value` DECIMAL(10,2) DEFAULT 0.00 COMMENT '折扣值（元或百分比）',
  `min_amount` BIGINT DEFAULT 0 COMMENT '最低使用金额（分）',
  `total_count` INT UNSIGNED DEFAULT 0 COMMENT '发放总量',
  `used_count` INT UNSIGNED DEFAULT 0 COMMENT '已使用数量',
  `receive_count` INT UNSIGNED DEFAULT 0 COMMENT '已领取数量',
  `per_user_limit` INT DEFAULT 1 COMMENT '每人限领数量',
  `valid_type` VARCHAR(32) DEFAULT 'fixed' COMMENT '有效期类型 fixed固定时间段 relative领取后N天',
  `valid_days` INT DEFAULT 0 COMMENT '领取后有效天数（relative时使用）',
  `start_time` DATETIME DEFAULT NULL COMMENT '生效开始时间',
  `end_time` DATETIME DEFAULT NULL COMMENT '生效结束时间',
  `apply_scope` VARCHAR(32) DEFAULT 'all' COMMENT '适用范围 all全部指定商品指定分类',
  `scope_ids` JSON DEFAULT NULL COMMENT '适用范围ID列表',
  `description` VARCHAR(512) DEFAULT '' COMMENT '使用说明',
  `status` TINYINT DEFAULT 1 COMMENT '状态 0未启用 1启用 2已过期 3已关闭',
  `creator_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '创建人ID',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优惠券模板表';

-- ============================================================
-- 85. 第三方接口监控表
-- ============================================================
DROP TABLE IF EXISTS `system_api_monitor`;
CREATE TABLE `system_api_monitor` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_type` VARCHAR(32) NOT NULL COMMENT '接口类型 liveness/sms/oss/profit_share/asr/ocr',
  `endpoint` VARCHAR(255) DEFAULT '' COMMENT '接口地址',
  `call_count` BIGINT UNSIGNED DEFAULT 0 COMMENT '总调用次数',
  `success_count` BIGINT UNSIGNED DEFAULT 0 COMMENT '成功次数',
  `fail_count` BIGINT UNSIGNED DEFAULT 0 COMMENT '失败次数',
  `avg_time_ms` INT UNSIGNED DEFAULT 0 COMMENT '平均耗时（毫秒）',
  `last_call_time` DATETIME DEFAULT NULL COMMENT '最后调用时间',
  `alert_threshold` DECIMAL(5,2) DEFAULT 95.00 COMMENT '告警阈值（成功率百分比，低于则告警）',
  `status` TINYINT DEFAULT 1 COMMENT '状态 0异常 1正常',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_type` (`api_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='第三方接口监控表';

-- 预置接口监控数据
INSERT INTO `system_api_monitor` (`api_type`, `endpoint`, `alert_threshold`, `status`) VALUES
('liveness', '', 95.00, 1),
('sms', '', 95.00, 1),
('oss', '', 99.00, 1),
('profit_share', '', 98.00, 1),
('asr', '', 90.00, 1),
('ocr', '', 90.00, 1);

-- ============================================================
-- 86. 慢查询日志表
-- ============================================================
DROP TABLE IF EXISTS `system_slow_query_log`;
CREATE TABLE `system_slow_query_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sql_text` TEXT COMMENT 'SQL语句',
  `exec_time_ms` INT UNSIGNED DEFAULT 0 COMMENT '执行耗时（毫秒）',
  `rows_examined` BIGINT UNSIGNED DEFAULT 0 COMMENT '扫描行数',
  `db_name` VARCHAR(64) DEFAULT '' COMMENT '数据库名',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exec_time_ms` (`exec_time_ms`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='慢查询日志表';

-- ============================================================
-- 87. 数据大屏快照表
-- ============================================================
DROP TABLE IF EXISTS `data_dashboard_snapshot`;
CREATE TABLE `data_dashboard_snapshot` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_type` VARCHAR(32) NOT NULL COMMENT '快照类型 realtime/daily/hourly',
  `data_json` JSON DEFAULT NULL COMMENT '快照数据JSON',
  `snapshot_time` DATETIME NOT NULL COMMENT '快照时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snapshot_type` (`snapshot_type`),
  KEY `idx_snapshot_time` (`snapshot_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据大屏快照表';