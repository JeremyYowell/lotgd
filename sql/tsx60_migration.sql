-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- TSX 60 / Multi-Exchange Support Migration
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- Add exchange column so each quarterly update script only manages its own stocks.
-- Without this, the sp500_update cron would deactivate all TSX 60 stocks every quarter.
ALTER TABLE `stocks`
    ADD COLUMN `exchange` VARCHAR(10) NOT NULL DEFAULT 'SP500'
        COMMENT 'SP500, TSX60, etc.'
        AFTER `sub_industry`;

-- Tag all existing stocks as S&P 500 (they were all seeded by sp500_update.php)
UPDATE `stocks` SET `exchange` = 'SP500';

-- New settings entries for TSX 60 tracking
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('portfolio_last_tsx60_update', '', 'Timestamp of last TSX 60 constituent update')
ON DUPLICATE KEY UPDATE description = VALUES(description);
