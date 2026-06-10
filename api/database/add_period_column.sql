ALTER TABLE `examines` ADD COLUMN `period` VARCHAR(20) DEFAULT NULL COMMENT '考核周期: 2025年度/2026年第一季度等' AFTER `examine_name`;
ALTER TABLE `examines` ADD INDEX `idx_period` (`period`);