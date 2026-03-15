-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- MySQL Database Schema
-- =============================================================================
-- ENVIRONMENT CONFIGURATION
-- Change the @env variable below before executing:
--   'dev'  = development instance (prefix: lotgd_dev_)
--   'prod' = production instance  (prefix: lotgd_prod_)
--
-- HOW TO USE:
--   1. Set @env below to 'dev' or 'prod'
--   2. Run this entire file in your MySQL client or phpMyAdmin
--   3. Tables will be created with the correct prefix automatically
--
-- NOTE: MySQL does not support dynamic table name creation via SET/PREPARE
--       in a single portable script. Instead, both environments are defined
--       below as separate blocks. Comment out the one you do NOT need,
--       or run each block against its respective database.
-- =============================================================================

-- =============================================================================
-- SHARED: settings table pattern (one per environment database)
-- Each environment lives in its own MySQL database on DreamHost:
--   Database 1: lotgd_dev
--   Database 2: lotgd_prod
-- The `settings` table in each DB holds the env designation and all config.
-- =============================================================================


-- =============================================================================
-- BLOCK A: DEVELOPMENT ENVIRONMENT
-- Run this block against your `lotgd_dev` database
-- Comment out this entire block if running against prod
-- =============================================================================

-- ------------------------------------
-- settings
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(100)    NOT NULL,
    `setting_value` TEXT            NOT NULL,
    `description`   VARCHAR(255)    DEFAULT NULL,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core environment settings (change these per database)
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('env',                     'dev',                          'Environment designation: dev or prod'),
('app_name',                'Legends of the Green Dollar',  'Application display name'),
('app_version',             '0.1.0',                        'Current application version'),
('debug_mode',              '1',                            '1 = debug on, 0 = debug off (force 0 in prod)'),
('anthropic_model',         'claude-sonnet-4-20250514',     'Claude model to use for API calls'),
('anthropic_max_tokens',    '1000',                         'Max tokens per Claude API response'),
('daily_action_limit',      '10',                           'Number of actions a player gets per day'),
('big_move_limit',          '1',                            'Number of big financial moves per day'),
('xp_per_dollar_saved',     '10',                           'XP awarded per dollar added to savings'),
('xp_per_dollar_invested',  '15',                           'XP awarded per dollar invested'),
('xp_per_dollar_debt_paid', '12',                           'XP awarded per dollar of debt paid'),
('xp_per_challenge',        '500',                          'Base XP for completing a challenge'),
('pvp_cooldown_hours',      '8',                            'Hours before a player can be attacked again'),
('max_level',               '50',                           'Maximum player level'),
('session_lifetime_minutes','120',                          'PHP session lifetime in minutes'),
('maintenance_mode',        '0',                            '1 = site locked to admins only'),
('registration_open',       '1',                            '1 = new registrations allowed'),
('api_rate_limit_per_hour', '20',                           'Max Claude API calls per user per hour');

-- ------------------------------------
-- users
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`          VARCHAR(50)     NOT NULL,
    `email`             VARCHAR(255)    NOT NULL,
    `password_hash`     VARCHAR(255)    NOT NULL,
    `class`             ENUM(
                            'investor',
                            'debt_slayer',
                            'saver',
                            'entrepreneur',
                            'minimalist'
                        )               NOT NULL DEFAULT 'saver',
    `level`             SMALLINT        NOT NULL DEFAULT 1,
    `xp`                BIGINT          NOT NULL DEFAULT 0,
    `xp_to_next_level`  BIGINT          NOT NULL DEFAULT 1000,
    `wealth_score`      BIGINT          NOT NULL DEFAULT 0,
    `gold`              INT             NOT NULL DEFAULT 100 COMMENT 'In-game currency for cosmetics/perks',
    `total_saved`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `total_invested`    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `total_debt_paid`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `avatar_id`         TINYINT         NOT NULL DEFAULT 1,
    `title`             VARCHAR(100)    DEFAULT NULL COMMENT 'Earned display title e.g. Master of Compound Interest',
    `is_admin`          TINYINT(1)      NOT NULL DEFAULT 0,
    `is_banned`         TINYINT(1)      NOT NULL DEFAULT 0,
    `ban_reason`        VARCHAR(255)    DEFAULT NULL,
    `last_login`        TIMESTAMP       NULL DEFAULT NULL,
    `login_streak`      SMALLINT        NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email` (`email`),
    KEY `idx_level` (`level`),
    KEY `idx_wealth_score` (`wealth_score` DESC),
    KEY `idx_xp` (`xp` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- user_sessions (server-side session store)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `session_id`    VARCHAR(128)    NOT NULL,
    `user_id`       INT UNSIGNED    NOT NULL,
    `ip_address`    VARCHAR(45)     DEFAULT NULL,
    `user_agent`    VARCHAR(512)    DEFAULT NULL,
    `payload`       TEXT            DEFAULT NULL,
    `last_activity` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`session_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_last_activity` (`last_activity`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- daily_state (resets every day at midnight server time)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `daily_state` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `state_date`        DATE            NOT NULL,
    `actions_remaining` TINYINT         NOT NULL DEFAULT 10,
    `big_move_used`     TINYINT(1)      NOT NULL DEFAULT 0,
    `dungeon_runs`      TINYINT         NOT NULL DEFAULT 0,
    `sage_queries`      TINYINT         NOT NULL DEFAULT 0,
    `pvp_attacks_made`  TINYINT         NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_date` (`user_id`, `state_date`),
    KEY `idx_state_date` (`state_date`),
    CONSTRAINT `fk_daily_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- financial_entries (the core player input log)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `financial_entries` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `entry_type`    ENUM(
                        'savings',
                        'investment',
                        'debt_payment',
                        'income',
                        'expense_cut'
                    )               NOT NULL,
    `amount`        DECIMAL(12,2)   NOT NULL,
    `description`   VARCHAR(255)    DEFAULT NULL COMMENT 'Player-entered note e.g. Paid off Visa',
    `xp_awarded`    INT             NOT NULL DEFAULT 0,
    `is_big_move`   TINYINT(1)      NOT NULL DEFAULT 0,
    `narrative`     TEXT            DEFAULT NULL COMMENT 'Claude-generated story result for this entry',
    `logged_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_entry_type` (`entry_type`),
    KEY `idx_logged_at` (`logged_at`),
    CONSTRAINT `fk_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- challenges
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `challenges` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(150)    NOT NULL,
    `description`   TEXT            NOT NULL,
    `flavor_text`   TEXT            DEFAULT NULL COMMENT 'Fantasy-flavored lore for the challenge',
    `category`      ENUM(
                        'savings',
                        'investment',
                        'debt',
                        'budgeting',
                        'income',
                        'mindset'
                    )               NOT NULL,
    `difficulty`    ENUM(
                        'squire',
                        'knight',
                        'champion',
                        'legend'
                    )               NOT NULL DEFAULT 'squire',
    `xp_reward`     INT             NOT NULL DEFAULT 500,
    `gold_reward`   INT             NOT NULL DEFAULT 50,
    `target_amount` DECIMAL(12,2)   DEFAULT NULL COMMENT 'Dollar target if applicable',
    `duration_days` SMALLINT        DEFAULT NULL COMMENT 'Days to complete, NULL = no limit',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `is_recurring`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = resets and can be done again',
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category`),
    KEY `idx_difficulty` (`difficulty`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- user_challenges (progress tracking)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `user_challenges` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `challenge_id`      INT UNSIGNED    NOT NULL,
    `status`            ENUM(
                            'active',
                            'completed',
                            'abandoned'
                        )               NOT NULL DEFAULT 'active',
    `progress_amount`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `started_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      TIMESTAMP       NULL DEFAULT NULL,
    `abandoned_at`      TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_challenge_id` (`challenge_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_uchallenge_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uchallenge_challenge` FOREIGN KEY (`challenge_id`) REFERENCES `challenges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- pvp_log (player vs player battles)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `pvp_log` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `attacker_id`       INT UNSIGNED    NOT NULL,
    `defender_id`       INT UNSIGNED    NOT NULL,
    `outcome`           ENUM(
                            'attacker_win',
                            'defender_win',
                            'draw'
                        )               NOT NULL,
    `attacker_xp_delta` INT             NOT NULL DEFAULT 0 COMMENT 'Positive = gained, negative = lost',
    `defender_xp_delta` INT             NOT NULL DEFAULT 0,
    `attacker_gold_delta` INT           NOT NULL DEFAULT 0,
    `defender_gold_delta` INT           NOT NULL DEFAULT 0,
    `battle_stat`       VARCHAR(100)    DEFAULT NULL COMMENT 'Which stat was compared e.g. savings_rate',
    `narrative`         TEXT            DEFAULT NULL COMMENT 'Claude-generated battle story',
    `fought_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attacker` (`attacker_id`),
    KEY `idx_defender` (`defender_id`),
    KEY `idx_fought_at` (`fought_at`),
    CONSTRAINT `fk_pvp_attacker` FOREIGN KEY (`attacker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pvp_defender` FOREIGN KEY (`defender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- tavern_messages (community board)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `tavern_messages` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `message`       TEXT            NOT NULL,
    `is_pinned`     TINYINT(1)      NOT NULL DEFAULT 0,
    `is_deleted`    TINYINT(1)      NOT NULL DEFAULT 0,
    `posted_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_posted_at` (`posted_at` DESC),
    CONSTRAINT `fk_tavern_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- api_call_log (track Claude API usage and costs)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `api_call_log` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    DEFAULT NULL,
    `call_type`         ENUM(
                            'dungeon_narrative',
                            'pvp_narrative',
                            'sage_query',
                            'challenge_generation',
                            'onboarding'
                        )               NOT NULL,
    `prompt_tokens`     INT             NOT NULL DEFAULT 0,
    `completion_tokens` INT             NOT NULL DEFAULT 0,
    `model`             VARCHAR(60)     NOT NULL,
    `success`           TINYINT(1)      NOT NULL DEFAULT 1,
    `error_message`     VARCHAR(512)    DEFAULT NULL,
    `called_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_call_type` (`call_type`),
    KEY `idx_called_at` (`called_at`),
    CONSTRAINT `fk_api_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- achievements
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `achievements` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `code`          VARCHAR(80)     NOT NULL COMMENT 'Internal unique code e.g. first_savings',
    `name`          VARCHAR(150)    NOT NULL,
    `description`   VARCHAR(255)    NOT NULL,
    `flavor_text`   VARCHAR(255)    DEFAULT NULL,
    `icon`          VARCHAR(80)     DEFAULT NULL,
    `xp_reward`     INT             NOT NULL DEFAULT 100,
    `gold_reward`   INT             NOT NULL DEFAULT 25,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- user_achievements
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `user_achievements` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `achievement_id`INT UNSIGNED    NOT NULL,
    `earned_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_achievement` (`user_id`, `achievement_id`),
    CONSTRAINT `fk_uachieve_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uachieve_achieve` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- leaderboard_cache (refreshed by cron, avoids heavy queries on page load)
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `leaderboard_cache` (
    `rank`          SMALLINT        NOT NULL,
    `user_id`       INT UNSIGNED    NOT NULL,
    `username`      VARCHAR(50)     NOT NULL,
    `class`         VARCHAR(20)     NOT NULL,
    `level`         SMALLINT        NOT NULL,
    `wealth_score`  BIGINT          NOT NULL,
    `xp`            BIGINT          NOT NULL,
    `total_saved`   DECIMAL(12,2)   NOT NULL,
    `total_debt_paid` DECIMAL(12,2) NOT NULL,
    `refreshed_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`rank`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED DATA: Starter challenges
-- =============================================================================
INSERT INTO `challenges` (`name`, `description`, `flavor_text`, `category`, `difficulty`, `xp_reward`, `gold_reward`, `target_amount`, `duration_days`, `is_recurring`) VALUES
('First Blood Savings',         'Add any amount to your savings account.',                           'The dungeon gates creak open. Your first coin has been cast into the vault.',       'savings',    'squire',    500,  50,   NULL,    NULL, 0),
('The Squire\'s Emergency Fund', 'Save $500 in an emergency fund.',                                  'A wise knight never rides without a reserve. Build yours.',                        'savings',    'squire',    1000, 100,  500.00,  NULL, 0),
('Knight\'s Reserve',           'Save $1,000 in an emergency fund.',                                 'The castle walls grow thicker with every gold piece stored.',                      'savings',    'knight',    2000, 200,  1000.00, NULL, 0),
('Slayer\'s First Strike',      'Make a payment toward any debt beyond the minimum.',                'The dragon of debt does not fall in one blow — but this is the first.',            'debt',       'squire',    500,  50,   NULL,    NULL, 0),
('Debt Dragon: Wounded',        'Pay off $500 in debt.',                                             'The beast recoils. It is not slain, but it bleeds.',                              'debt',       'knight',    1500, 150,  500.00,  NULL, 0),
('Debt Dragon: Slain',          'Pay off $2,500 in debt.',                                           'The dragon falls. The kingdom breathes easier tonight.',                           'debt',       'champion',  5000, 500,  2500.00, NULL, 0),
('The First Seed',              'Make your first investment of any amount.',                         'You have planted the first seed in the Forest of Compound Interest.',              'investment', 'squire',    750,  75,   NULL,    NULL, 0),
('The Investor\'s Path',        'Invest $500 in a retirement or brokerage account.',                 'The path to the mountain of wealth begins with a single step.',                    'investment', 'knight',    2000, 200,  500.00,  NULL, 0),
('Budget Warrior',              'Track every expense for 7 consecutive days.',                       'To know your enemy, you must first count their soldiers.',                         'budgeting',  'squire',    600,  60,   NULL,    7,    1),
('The Frugal Knight',           'Cut a recurring expense from your budget.',                         'A warrior who needs less is a warrior who fears less.',                            'budgeting',  'squire',    500,  50,   NULL,    NULL, 0),
('Income Adventurer',           'Log a new source of income (side hustle, raise, bonus).',           'New roads to the treasury have been discovered.',                                  'income',     'squire',    800,  80,   NULL,    NULL, 0),
('The Mindful Coin',            'Go 30 days without an impulse purchase over $25.',                  'The rarest armor is the one forged from patience.',                                'mindset',    'champion',  3000, 300,  NULL,    30,   1);

-- =============================================================================
-- SEED DATA: Starter achievements
-- =============================================================================
INSERT INTO `achievements` (`code`, `name`, `description`, `flavor_text`, `xp_reward`, `gold_reward`) VALUES
('first_login',         'Hearthstone',              'Log in for the first time.',                        'Every legend begins with a single step through the door.',     100,  10),
('first_entry',         'First Coin Cast',          'Log your first financial entry.',                   'The vault opens.',                                             200,  20),
('level_5',             'Apprentice of Finance',    'Reach level 5.',                                    'Your training has only begun.',                                500,  50),
('level_10',            'Journeyman',               'Reach level 10.',                                   'The road grows clearer.',                                      1000, 100),
('level_25',            'Champion of the Green',    'Reach level 25.',                                   'Few dare venture this far.',                                   3000, 300),
('level_50',            'Legend of the Green Dollar','Reach the maximum level.',                         'Your name will echo through the halls of fiscal history.',     10000,1000),
('streak_7',            'The Faithful',             'Log in 7 days in a row.',                           'Discipline is the true armor.',                               500,  50),
('streak_30',           'The Devoted',              'Log in 30 days in a row.',                          'A month of resolve. The kingdom notices.',                     2000, 200),
('first_pvp_win',       'Bested in Battle',         'Win your first PvP financial duel.',                'Their spreadsheet trembled before yours.',                     300,  30),
('debt_free',           'Dragonslayer',             'Reach $0 in tracked debt.',                         'The great debt dragon has been vanquished. Rest, hero.',       5000, 500),
('saved_1000',          'The Vault Keeper',         'Save a cumulative $1,000.',                         'The vault grows heavy.',                                       1000, 100),
('saved_10000',         'Keeper of the Grand Vault','Save a cumulative $10,000.',                        'Legends are written in numbers like these.',                   5000, 500),
('invested_first',      'Seed Planter',             'Make your first investment.',                       'The forest of compound interest stirs.',                       300,  30),
('tavern_10',           'Voice of the Tavern',      'Post 10 messages in the tavern.',                   'The crowd leans in when you speak.',                           200,  20);

-- =============================================================================
-- END OF SCHEMA
-- =============================================================================
-- Remember: run this file against `lotgd_dev` for development,
--           and against `lotgd_prod` for production.
-- The `settings` table row env='dev' / env='prod' is your runtime flag.
-- In PHP: SELECT setting_value FROM settings WHERE setting_key = 'env'
-- =============================================================================
