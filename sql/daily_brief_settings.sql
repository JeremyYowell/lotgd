-- =============================================================================
-- Daily Adventurer's Brief — Settings Additions
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('daily_brief_html',          '',    'Cached HTML output of the daily brief — generated nightly by cron'),
('daily_brief_date',          '',    'Date the brief was last generated (YYYY-MM-DD)'),
('daily_brief_generated_at',  '',    'Full timestamp of last generation'),
('daily_brief_enabled',       '1',   'Show the daily brief on the dashboard')
ON DUPLICATE KEY UPDATE description = VALUES(description);
