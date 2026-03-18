-- =============================================================================
-- Email driver settings — run against both lotgd_dev and lotgd_prod
-- =============================================================================

INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('email_driver',   'php',    'Email sending driver: php or resend'),
('resend_api_key', '',       'Resend.com API key (get free at resend.com)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
