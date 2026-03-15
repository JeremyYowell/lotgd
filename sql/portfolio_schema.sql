-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Portfolio Module — Schema Additions
-- Run against BOTH lotgd_dev and lotgd_prod
-- =============================================================================

-- ------------------------------------
-- stocks (S&P 500 constituent list)
-- Populated by cron/sp500_update.php
-- Updated quarterly on first business day
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `stocks` (
    `ticker`        VARCHAR(10)     NOT NULL,
    `company_name`  VARCHAR(150)    NOT NULL,
    `sector`        VARCHAR(100)    DEFAULT NULL,
    `sub_industry`  VARCHAR(150)    DEFAULT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `added_at`      DATE            DEFAULT NULL,
    `removed_at`    DATE            DEFAULT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`ticker`),
    KEY `idx_is_active` (`is_active`),
    KEY `idx_sector` (`sector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- stock_prices
-- One row per ticker per trading day
-- '^GSPC' ticker = S&P 500 index itself
-- Populated nightly by cron/price_update.php
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `stock_prices` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `ticker`        VARCHAR(10)     NOT NULL,
    `price_date`    DATE            NOT NULL,
    `close_price`   DECIMAL(12,4)   NOT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticker_date` (`ticker`, `price_date`),
    KEY `idx_ticker` (`ticker`),
    KEY `idx_price_date` (`price_date` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- portfolio_holdings
-- Current open positions per player
-- One row per user+ticker combo
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio_holdings` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `ticker`            VARCHAR(10)     NOT NULL,
    `shares`            DECIMAL(12,6)   NOT NULL DEFAULT 0.000000,
    `avg_cost_basis`    DECIMAL(12,4)   NOT NULL DEFAULT 0.0000 COMMENT 'avg USD price paid per share',
    `gold_invested`     DECIMAL(12,4)   NOT NULL DEFAULT 0.0000 COMMENT 'total Gold spent on this position',
    `first_purchased`   DATE            DEFAULT NULL,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_ticker` (`user_id`, `ticker`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_holdings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- portfolio_trades
-- Full immutable trade history
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio_trades` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `ticker`            VARCHAR(10)     NOT NULL,
    `trade_type`        ENUM('buy','sell') NOT NULL,
    `shares`            DECIMAL(12,6)   NOT NULL,
    `price_per_share`   DECIMAL(12,4)   NOT NULL COMMENT 'previous close price used for execution',
    `gold_amount`       DECIMAL(12,4)   NOT NULL COMMENT 'Gold debited (buy) or credited (sell)',
    `traded_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_ticker` (`ticker`),
    KEY `idx_traded_at` (`traded_at` DESC),
    CONSTRAINT `fk_trades_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- portfolio_snapshots
-- Daily per-player snapshot built by cron
-- Drives the portfolio leaderboard
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio_snapshots` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `snapshot_date`     DATE            NOT NULL,
    `total_value_usd`   DECIMAL(14,4)   NOT NULL DEFAULT 0.0000 COMMENT 'current market value of all holdings',
    `gold_equivalent`   DECIMAL(14,4)   NOT NULL DEFAULT 0.0000 COMMENT 'total_value_usd / 1000',
    `cost_basis_usd`    DECIMAL(14,4)   NOT NULL DEFAULT 0.0000 COMMENT 'total USD invested',
    `pct_return`        DECIMAL(10,4)   NOT NULL DEFAULT 0.0000 COMMENT '% gain/loss since first trade',
    `spx_pct_return`    DECIMAL(10,4)   NOT NULL DEFAULT 0.0000 COMMENT 'SPX % return over same period',
    `beats_index`       TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_date` (`user_id`, `snapshot_date`),
    KEY `idx_snapshot_date` (`snapshot_date` DESC),
    KEY `idx_pct_return` (`pct_return` DESC),
    CONSTRAINT `fk_snapshots_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- portfolio_leaderboard_cache
-- Rebuilt nightly by cron
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio_leaderboard_cache` (
    `position`          SMALLINT        NOT NULL,
    `user_id`           INT UNSIGNED    NOT NULL,
    `username`          VARCHAR(50)     NOT NULL,
    `class`             VARCHAR(20)     NOT NULL,
    `pct_return`        DECIMAL(10,4)   NOT NULL,
    `total_value_usd`   DECIMAL(14,4)   NOT NULL,
    `beats_index`       TINYINT(1)      NOT NULL DEFAULT 0,
    `spx_pct_return`    DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    `refreshed_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`position`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_pct_return` (`pct_return` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- New settings rows for portfolio module
-- ------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('portfolio_enabled',           '1',            'Enable/disable the portfolio module'),
('gold_to_usd_rate',            '1000',         '1 Gold = this many USD'),
('portfolio_monthly_bonus',     '100',          'Gold awarded for beating SPX in a calendar month'),
('finnhub_api_key',             'YOUR_KEY_HERE','Finnhub API key for price data'),
('spx_inception_date',          '',             'Date of first price download — set automatically by cron'),
('spx_inception_price',         '',             'SPX closing price on inception date — set automatically'),
('portfolio_last_price_update', '',             'Timestamp of last successful price cron run'),
('portfolio_last_sp500_update', '',             'Timestamp of last S&P 500 constituent update'),
('sp500_update_schedule',       'quarterly',    'How often to update S&P 500 constituent list')
ON DUPLICATE KEY UPDATE description = VALUES(description);
