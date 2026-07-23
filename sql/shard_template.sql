-- ============================================================
-- MySQL 分表建表模板
-- 按月份分表：order_archive_YYYYMM / chat_message_archive_YYYYMM
-- 使用说明：替换 {{YYYYMM}} 为实际年月，如 202401
-- ============================================================

-- ============================================================
-- 订单表 - 按月分表模板
-- 主表：order
-- 分表名规则：order_archive_YYYYMM
-- 分表依据：create_time 字段的年月
-- ============================================================
DROP TABLE IF EXISTS `order_archive_{{YYYYMM}}`;
CREATE TABLE `order_archive_{{YYYYMM}}` (
  `id` BIGINT UNSIGNED NOT NULL COMMENT '订单ID',
  `order_sn` VARCHAR(64) NOT NULL COMMENT '订单号',
  `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
  `player_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '打手ID',
  `service_type_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '服务类型ID',
  `game_name` VARCHAR(128) DEFAULT '' COMMENT '游戏名称',
  `game_server` VARCHAR(128) DEFAULT '' COMMENT '游戏区服',
  `game_role` VARCHAR(128) DEFAULT '' COMMENT '游戏角色',
  `order_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单金额（分）',
  `paid_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '实付金额（分）',
  `discount_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '优惠金额（分）',
  `platform_fee` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平台抽成（分）',
  `player_income` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '打手收入（分）',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单状态 0待支付 1已支付 2进行中 3已完成 4已取消 5退款中 6已退款 7已超时 8派单中',
  `remark` VARCHAR(512) DEFAULT '' COMMENT '订单备注',
  `cancel_reason` VARCHAR(512) DEFAULT '' COMMENT '取消原因',
  `paid_time` DATETIME DEFAULT NULL COMMENT '支付时间',
  `start_time` DATETIME DEFAULT NULL COMMENT '开始时间',
  `completed_time` DATETIME DEFAULT NULL COMMENT '完成时间',
  `canceled_time` DATETIME DEFAULT NULL COMMENT '取消时间',
  `settle_time` DATETIME DEFAULT NULL COMMENT '结算时间',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` DATETIME DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_paid_time` (`paid_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表-{{YYYYMM}}分表';

-- ============================================================
-- 聊天消息表 - 按月分表模板
-- 主表：chat_message
-- 分表名规则：chat_message_archive_YYYYMM
-- 分表依据：create_time 字段的年月
-- ============================================================
DROP TABLE IF EXISTS `chat_message_archive_{{YYYYMM}}`;
CREATE TABLE `chat_message_archive_{{YYYYMM}}` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天消息表-{{YYYYMM}}分表';

-- ============================================================
-- 分表操作示例
-- ============================================================

-- 1. 查询：根据时间路由到对应分表
-- SELECT * FROM order_archive_202401 WHERE user_id = 123 AND create_time >= '2024-01-01';

-- 2. 插入：根据 create_time 年月插入对应分表
-- INSERT INTO order_archive_202401 (...) VALUES (...);

-- 3. 跨月查询：使用 UNION ALL
-- SELECT * FROM order_archive_202401 WHERE user_id = 123
-- UNION ALL
-- SELECT * FROM order_archive_202402 WHERE user_id = 123;

-- 4. 自动建表（每月1号凌晨执行）
-- CREATE TABLE IF NOT EXISTS order_archive_YYYYMM LIKE order;
-- CREATE TABLE IF NOT EXISTS chat_message_archive_YYYYMM LIKE chat_message;
