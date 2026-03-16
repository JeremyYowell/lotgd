-- =============================================================================
-- Settings additions — Claude API key in DB, bonus award tracking
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('claude_api_key',              '',    'Anthropic Claude API key — overrides config.php if set'),
('portfolio_bonus_last_awarded','',    'Date monthly portfolio bonuses were last awarded (YYYY-MM-DD)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
