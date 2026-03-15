-- =============================================================================
-- Email Confirmation — Schema Additions
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- Add confirmation columns to users table
ALTER TABLE `users`
    ADD COLUMN `email_confirmed`    TINYINT(1)      NOT NULL DEFAULT 0 AFTER `email`,
    ADD COLUMN `confirm_token`      VARCHAR(64)     DEFAULT NULL AFTER `email_confirmed`,
    ADD COLUMN `confirm_token_exp`  DATETIME        DEFAULT NULL AFTER `confirm_token`,
    ADD KEY `idx_confirm_token` (`confirm_token`);

-- Add settings for email confirmation
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('email_confirmation_enabled',  '1',                        'Require email confirmation before playing'),
('email_from_address',          'noreply@yourdomain.com',   'From address for system emails — CHANGE THIS'),
('email_from_name',             'Legends of the Green Dollar', 'From name for system emails'),
('email_confirm_xp_reward',     '10',                       'XP awarded for confirming email'),
('email_confirm_token_hours',   '48',                       'Hours before confirmation token expires')
ON DUPLICATE KEY UPDATE description = VALUES(description);
