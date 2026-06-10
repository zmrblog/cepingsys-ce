-- =====================================================
-- 年度考核测评系统 - 数据库初始化脚本
-- 数据库: MySQL 8.0+
-- 字符集: utf8mb4
-- 引擎: InnoDB
-- 创建时间: 2026-05-07
-- =====================================================

CREATE DATABASE IF NOT EXISTS `examine_system`
DEFAULT CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `examine_system`;

-- -----------------------------------------------------
-- 1. 单位表 (units)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `units` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `unit_name` VARCHAR(100) NOT NULL COMMENT '单位名称',
    `unit_code` VARCHAR(50) DEFAULT NULL COMMENT '单位编码',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序序号',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_unit_name` (`unit_name`),
    KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='单位/部门/班子';

-- -----------------------------------------------------
-- 2. 管理员表 (admins)
-- 三级权限: super(超级管理员) / template(模板管理员) / viewer(查看管理员)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL COMMENT '登录用户名',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'bcrypt密码哈希',
    `real_name` VARCHAR(50) DEFAULT NULL COMMENT '真实姓名',
    `role` ENUM('super','template','viewer') NOT NULL DEFAULT 'viewer' COMMENT '角色权限',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 0禁用 1启用',
    `last_login_at` TIMESTAMP NULL DEFAULT NULL COMMENT '最后登录时间',
    `last_login_ip` VARCHAR(45) DEFAULT NULL COMMENT '最后登录IP',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员账号';

-- -----------------------------------------------------
-- 3. 用户表 (users) - 测评人员
-- 一个用户只属于一个单位
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `unit_id` INT UNSIGNED DEFAULT NULL COMMENT '所属单位ID',
    `real_name` VARCHAR(50) DEFAULT NULL COMMENT '真实姓名',
    `name` VARCHAR(50) NOT NULL COMMENT '姓名',
    `phone` VARCHAR(20) DEFAULT NULL COMMENT '手机号',
    `position` VARCHAR(100) DEFAULT NULL COMMENT '职务',
    `user_type` ENUM('A','B') NOT NULL DEFAULT 'A' COMMENT '用户类型: A类/B类(用于加权计算)',
    `source` ENUM('admin','registered') NOT NULL DEFAULT 'admin' COMMENT '用户来源: admin后台添加/registered前台注册',
    `device_fingerprint` VARCHAR(64) DEFAULT NULL COMMENT '设备指纹',
    `password_hash` VARCHAR(255) DEFAULT NULL COMMENT '密码哈希(仅注册用户)',
    `security_question` TINYINT UNSIGNED DEFAULT NULL COMMENT '安全问题编号1-5(仅注册用户)',
    `security_answer` VARCHAR(255) DEFAULT NULL COMMENT '安全问题答案哈希(仅注册用户)',
    `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT '状态: active启用/disabled禁用',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_source_phone` (`source`, `phone`),
    KEY `idx_unit_id` (`unit_id`),
    KEY `idx_user_type` (`user_type`),
    KEY `idx_source` (`source`),
    KEY `idx_users_security_question` (`security_question`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='测评人员';

-- -----------------------------------------------------
-- 4. 测评模板表 (templates)
-- 仅支持两种类型: leader(干部民主测评) / team(班子民主测评)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_name` VARCHAR(100) NOT NULL COMMENT '模板名称',
    `template_type` ENUM('leader','team') NOT NULL COMMENT '模板类型: 干部测评/班子测评',
    `description` TEXT DEFAULT NULL COMMENT '模板说明',
    `is_default` TINYINT NOT NULL DEFAULT 0 COMMENT '是否默认模板: 0否 1是',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 0禁用 1启用',
    `created_by` INT UNSIGNED NOT NULL COMMENT '创建人(admins.id)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_template_type` (`template_type`),
    KEY `idx_created_by` (`created_by`),
    CONSTRAINT `fk_templates_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='测评模板';

-- -----------------------------------------------------
-- 5. 模板指标项表 (template_items)
-- 支持三种类型: radio(单选) / checkbox(多选) / textarea(文本域)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `template_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_id` INT UNSIGNED NOT NULL COMMENT '所属模板ID',
    `item_title` VARCHAR(200) NOT NULL COMMENT '指标标题',
      `item_description` VARCHAR(500) DEFAULT NULL COMMENT 'ָ������',
      `short_name` VARCHAR(50) DEFAULT NULL COMMENT 'ָ����',
      `item_type` ENUM('radio','checkbox','textarea') NOT NULL COMMENT '题型: 单选/多选/文本域',
    `options` JSON DEFAULT NULL COMMENT '选项(JSON数组)，radio和checkbox必填',
    `min_select` INT UNSIGNED DEFAULT NULL COMMENT '最少选择数(仅checkbox)',
    `max_select` INT UNSIGNED DEFAULT NULL COMMENT '最多选择数(仅checkbox)',
    `is_reverse` TINYINT NOT NULL DEFAULT 0 COMMENT '是否反向测评: 0否 1是',
    `reverse_options` JSON DEFAULT NULL COMMENT '反向选项标记(JSON数组，哪些选项算负面)',
    `required_example` TINYINT NOT NULL DEFAULT 0 COMMENT '是否要求填写事例: 0否 1是',
      `weight` INT DEFAULT 1 COMMENT 'Ȩ��',
      `is_scoring` TINYINT DEFAULT 1 COMMENT '�Ƿ�Ʒ�',
      `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序序号',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_template_id` (`template_id`),
    CONSTRAINT `fk_items_template` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='模板指标项';

-- -----------------------------------------------------
-- 6. 测评任务表 (examines)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `examines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `examine_name` VARCHAR(200) NOT NULL COMMENT '任务名称',
      `period` VARCHAR(50) DEFAULT NULL COMMENT '��������',
      `template_id` INT UNSIGNED NOT NULL COMMENT '使用模板ID',
    `unit_id` INT UNSIGNED NOT NULL COMMENT '所属单位ID',
    `start_time` DATETIME NOT NULL COMMENT '开始时间',
    `end_time` DATETIME NOT NULL COMMENT '结束时间',
    `weight_mode` ENUM('equal','custom') NOT NULL DEFAULT 'equal' COMMENT '加权模式: equal等权/custom自定义',
    `weight_a` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'A类权重(仅custom模式有效)',
    `weight_b` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'B类权重(仅custom模式有效)',
    `status` ENUM('draft','active','finished','archived') NOT NULL DEFAULT 'draft' COMMENT '状态: 草稿/进行中/已结束/已归档',
    `created_by` INT UNSIGNED NOT NULL COMMENT '创建人(admins.id)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_template_id` (`template_id`),
    KEY `idx_unit_id` (`unit_id`),
    KEY `idx_status` (`status`),
    KEY `idx_time_range` (`start_time`, `end_time`),
    CONSTRAINT `fk_examines_template` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_examines_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_examines_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='测评任务';

-- -----------------------------------------------------
-- 7. 测评对象表 (examine_targets)
-- target_type: team(班子) / leader(干部)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `examine_targets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `examine_id` INT UNSIGNED NOT NULL COMMENT '所属测评任务ID',
    `target_type` ENUM('team','leader') NOT NULL COMMENT '对象类型: 班子/干部',
    `target_name` VARCHAR(50) NOT NULL COMMENT '姓名或班子名',
    `position` VARCHAR(100) DEFAULT NULL COMMENT '职务(干部必填)',
    `unit_name` VARCHAR(100) DEFAULT NULL COMMENT '单位名称',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序序号',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_examine_id` (`examine_id`),
    CONSTRAINT `fk_targets_examine` FOREIGN KEY (`examine_id`) REFERENCES `examines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='测评对象';

-- -----------------------------------------------------
-- 8. 参评人员表 (examine_users)
-- 记录哪些用户参与了某次测评任务
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `examine_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `examine_id` INT UNSIGNED NOT NULL COMMENT '所属测评任务ID',
    `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID(users.id)',
    `status` ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending' COMMENT '状态: 待测评/进行中/已完成',
    `started_at` TIMESTAMP NULL DEFAULT NULL COMMENT '开始答题时间',
    `completed_at` TIMESTAMP NULL DEFAULT NULL COMMENT '完成时间',
    `device_fingerprint` VARCHAR(64) DEFAULT NULL COMMENT '答题设备指纹',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT '答题IP地址',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_examine_user` (`examine_id`, `user_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_eu_examine` FOREIGN KEY (`examine_id`) REFERENCES `examines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eu_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='参评人员';

-- -----------------------------------------------------
-- 9. 答案记录表 (examine_answers)
-- 每个用户对每个对象的每道题的答案单独记录
-- 支持逐题异步保存
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `examine_answers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `examine_id` INT UNSIGNED NOT NULL COMMENT '测评任务ID',
    `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
    `target_id` INT UNSIGNED NOT NULL COMMENT '测评对象ID(examine_targets.id)',
    `item_id` INT UNSIGNED NOT NULL COMMENT '指标项ID(template_items.id)',
    `answer_value` TEXT DEFAULT NULL COMMENT '答案值(radio:单选值 / checkbox:JSON数组 / textarea:文本)',
    `example_text` TEXT DEFAULT NULL COMMENT '事例说明(反向测评时填写)',
    `answered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '答题时间',
    `completed_at` TIMESTAMP NULL DEFAULT NULL COMMENT '完成时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_answer` (`examine_id`, `user_id`, `target_id`, `item_id`),
    KEY `idx_examine_user` (`examine_id`, `user_id`),
    KEY `idx_target_item` (`target_id`, `item_id`),
    KEY `idx_answered_at` (`answered_at`),
    CONSTRAINT `fk_answers_examine` FOREIGN KEY (`examine_id`) REFERENCES `examines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_answers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_answers_target` FOREIGN KEY (`target_id`) REFERENCES `examine_targets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_answers_item` FOREIGN KEY (`item_id`) REFERENCES `template_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答案记录';

-- -----------------------------------------------------
-- 10. 操作日志表 (operation_logs)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `operation_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id` INT UNSIGNED DEFAULT NULL COMMENT '操作管理员ID',
    `module` VARCHAR(50) NOT NULL COMMENT '操作模块',
    `action` VARCHAR(50) NOT NULL COMMENT '操作动作',
    `target_type` VARCHAR(50) DEFAULT NULL COMMENT '操作对象类型',
    `target_id` INT UNSIGNED DEFAULT NULL COMMENT '操作对象ID',
    `detail` TEXT DEFAULT NULL COMMENT '操作详情(JSON格式)',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT '操作IP',
    `user_agent` VARCHAR(500) DEFAULT NULL COMMENT '浏览器信息',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_id` (`admin_id`),
    KEY `idx_module` (`module`),
    KEY `idx_action` (`action`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

-- -----------------------------------------------------
-- 11. 验证码表 (captcha_codes) [已废弃 - 验证码功能已停用，保留表结构以备将来启用]
-- 用于登录验证码，有效期5分钟
-- -----------------------------------------------------
/*
CREATE TABLE IF NOT EXISTS `captcha_codes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(10) NOT NULL COMMENT '验证码文本',
    `session_id` VARCHAR(128) NOT NULL COMMENT '会话标识(IP+随机字符串)',
    `expires_at` DATETIME NOT NULL COMMENT '过期时间',
    `used` TINYINT NOT NULL DEFAULT 0 COMMENT '是否已使用: 0否 1是',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session` (`session_id`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='验证码';
*/

-- -----------------------------------------------------
-- 12. 系统配置表 (system_configs)
-- 用于存储系统级配置参数
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_configs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `config_key` VARCHAR(100) NOT NULL COMMENT '配置键',
    `config_value` TEXT DEFAULT NULL COMMENT '配置值',
    `config_group` VARCHAR(50) DEFAULT 'general' COMMENT '配置分组',
    `description` VARCHAR(200) DEFAULT NULL COMMENT '配置说明',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_config_key` (`config_key`),
    KEY `idx_config_group` (`config_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置';

-- 插入默认系统配置
INSERT IGNORE INTO `system_configs` (`config_key`, `config_value`, `config_group`, `description`) VALUES
('system_name', '年度考核测评系统', 'general', '系统名称'),
('jwt_expire_hours', '24', 'security', 'JWT令牌过期时间(小时)'),
('ip_filter_enabled', '1', 'security', '是否启用国外IP过滤'),
('upload_max_size', '10485760', 'upload', '上传文件最大大小(字节)'),
('login_max_attempts', '5', 'security', '登录最大尝试次数'),
('login_lockout_minutes', '5', 'security', '登录锁定时长(分钟)');
-- 注意: captcha_enabled 和 captcha_expire_minutes 已废弃(验证码功能停用)
-- 注意: jwt_secret 不再存储在数据库中，仅保留在 .env 配置文件中

-- -----------------------------------------------------
-- 13. 登录尝试记录表 (ip_attempts)
-- 用于登录频率限制，记录IP地址的失败尝试次数
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_attempts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL COMMENT '客户端IP地址',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '累计尝试次数',
    `last_attempt` DATETIME NOT NULL COMMENT '最后一次尝试时间',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ip_address` (`ip_address`),
    KEY `idx_last_attempt` (`last_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录尝试记录';

-- =====================================================
-- 审计账号表（独立子系统，不共享主系统认证）
-- =====================================================
CREATE TABLE IF NOT EXISTS `audit_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL COMMENT '审计账号',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'bcrypt密码哈希',
    `real_name` VARCHAR(50) DEFAULT NULL COMMENT '审计员姓名',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    `last_login_at` TIMESTAMP NULL DEFAULT NULL COMMENT '最后登录时间',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='审计账号表';

-- =====================================================
-- 创建索引优化查询性能
-- =================================================----

-- 复合索引用于常见查询场景
CREATE INDEX idx_examine_answers_query ON examine_answers (examine_id, target_id, item_id);
CREATE INDEX idx_examine_users_status ON examine_users (examine_id, status);
CREATE INDEX idx_examines_active ON examines (status, start_time, end_time);

-- =====================================================
-- 初始化完成提示
-- =====================================================
SELECT '✅ 数据库初始化完成！' AS message;
SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = 'examine_system';

-- -----------------------------------------------------
-- IP 拦截日志表 (ip_block_logs)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_block_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL COMMENT 'IP地址',
    `request_path` VARCHAR(255) DEFAULT NULL COMMENT '请求路径',
    `request_method` VARCHAR(10) DEFAULT NULL COMMENT '请求方法',
    `user_agent` VARCHAR(500) DEFAULT NULL COMMENT '用户代理',
    `country_code` VARCHAR(10) DEFAULT NULL COMMENT '国家代码',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '记录时间',
    PRIMARY KEY (`id`),
    KEY `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP拦截日志';
