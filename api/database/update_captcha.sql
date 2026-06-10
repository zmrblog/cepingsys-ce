ALTER TABLE captcha_codes 
ADD COLUMN phone VARCHAR(20) DEFAULT NULL COMMENT '手机号' AFTER code,
ADD COLUMN type VARCHAR(20) DEFAULT 'login' COMMENT '验证码类型: login/reset' AFTER expires_at,
ADD INDEX idx_phone_type (phone, type),
ADD INDEX idx_expires (expires_at);
