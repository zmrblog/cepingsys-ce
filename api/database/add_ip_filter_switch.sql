-- IP过滤开关功能 - 数据库迁移
-- 1. 创建IP拦截日志表
CREATE TABLE IF NOT EXISTS `ip_block_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL COMMENT '被拦截的IP地址',
    `request_path` VARCHAR(255) DEFAULT '' COMMENT '请求路径',
    `request_method` VARCHAR(10) DEFAULT 'GET' COMMENT '请求方法',
    `user_agent` VARCHAR(500) DEFAULT '' COMMENT '用户代理',
    `country_code` VARCHAR(5) DEFAULT '' COMMENT 'ISO国家代码',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '拦截时间',
    INDEX `idx_ip` (`ip_address`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='国外IP拦截日志';

-- 2. 添加全局IP过滤开关配置项
INSERT IGNORE INTO `system_configs` (`config_key`, `config_value`, `config_group`, `description`) VALUES
('ip_filter_enabled', '0', 'security', '全局IP过滤开关: 0关闭/1开启');
