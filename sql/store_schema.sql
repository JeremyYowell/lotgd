-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Item Store — Schema + Seed Data
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- ------------------------------------
-- store_items
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `store_items` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100)    NOT NULL,
    `description`       VARCHAR(255)    NOT NULL,
    `flavor_text`       VARCHAR(255)    DEFAULT NULL,
    `category`          ENUM('tool','armor','weapon','consumable') NOT NULL,
    `slot`              ENUM('tool','armor','weapon','consumable') NOT NULL,
    `effect_type`       ENUM(
                            'roll_bonus',
                            'failure_reduction',
                            'xp_boost',
                            'action_restore',
                            'roll_boost_once',
                            'reroll_once'
                        )               NOT NULL,
    `effect_value`      DECIMAL(6,3)    NOT NULL DEFAULT 1.000
                        COMMENT 'roll_bonus=flat int, failure_reduction=multiplier, xp_boost=multiplier, action_restore=int',
    `effect_category`   VARCHAR(50)     DEFAULT NULL
                        COMMENT 'scenario category this applies to, NULL = all',
    `price`             INT UNSIGNED    NOT NULL,
    `level_req`         TINYINT         NOT NULL DEFAULT 1,
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
    `sort_order`        TINYINT         NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category`),
    KEY `idx_level_req` (`level_req`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- user_inventory
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `user_inventory` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `item_id`       INT UNSIGNED    NOT NULL,
    `quantity`      TINYINT         NOT NULL DEFAULT 1
                    COMMENT 'Always 1 for equipped items, 1-5 for consumables',
    `equipped`      TINYINT(1)      NOT NULL DEFAULT 1
                    COMMENT '1 = active/equipped, consumables are always 1 until used',
    `acquired_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_item` (`user_id`, `item_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_item_id` (`item_id`),
    CONSTRAINT `fk_inv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inv_item` FOREIGN KEY (`item_id`) REFERENCES `store_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED DATA: Store Items
-- =============================================================================

-- -------------------------------------------------------
-- TOOLS (+roll bonus by scenario category)
-- -------------------------------------------------------
INSERT INTO `store_items`
    (name, description, flavor_text, category, slot, effect_type, effect_value,
     effect_category, price, level_req, sort_order) VALUES
(
    'Pocket Calculator',
    '+1 to all Banking encounter rolls.',
    'Numbers don''t lie. Neither does compound interest.',
    'tool', 'tool', 'roll_bonus', 1, 'banking', 500, 1, 10
),
(
    'Consumer Reports Subscription',
    '+2 to all Shopping encounter rolls.',
    'Knowledge is the only armor against a clever salesman.',
    'tool', 'tool', 'roll_bonus', 2, 'shopping', 800, 3, 20
),
(
    'Budget Spreadsheet Template',
    '+1 to all Daily Life encounter rolls.',
    'A line item for everything. Even the unexpected.',
    'tool', 'tool', 'roll_bonus', 1, 'daily_life', 500, 1, 30
),
(
    'Negotiation Manual',
    '+2 to all Work encounter rolls.',
    'Never accept the first offer. This book explains why in 400 pages.',
    'tool', 'tool', 'roll_bonus', 2, 'work', 1000, 5, 40
),
(
    'Graphing Calculator',
    '+2 to all Investing encounter rolls.',
    'The market is just math. Very expensive, terrifying math.',
    'tool', 'tool', 'roll_bonus', 2, 'investing', 1000, 5, 50
),
(
    'Real Estate Guide',
    '+2 to all Housing encounter rolls.',
    'Location, location, and knowing what comps are going for.',
    'tool', 'tool', 'roll_bonus', 2, 'housing', 1000, 5, 60
),
(
    'Financial News Subscription',
    '+3 to all Investing encounter rolls.',
    'Information asymmetry is the oldest advantage in the market.',
    'tool', 'tool', 'roll_bonus', 3, 'investing', 2000, 12, 70
),
(
    'CFA Study Materials',
    '+2 to ALL encounter rolls regardless of category.',
    'Level III. You survived. Nothing in the realm can break you now.',
    'tool', 'tool', 'roll_bonus', 2, NULL, 4000, 25, 80
);

-- -------------------------------------------------------
-- ARMOR (failure penalty reduction)
-- -------------------------------------------------------
INSERT INTO `store_items`
    (name, description, flavor_text, category, slot, effect_type, effect_value,
     effect_category, price, level_req, sort_order) VALUES
(
    'Frugality Cloak',
    'Reduces Gold penalty from Shopping failures by 25%.',
    'They cannot take what you have trained yourself not to want.',
    'armor', 'armor', 'failure_reduction', 0.25, 'shopping', 1200, 5, 10
),
(
    'Impulse Shield',
    'Reduces Gold penalty from all critical failures by 25%.',
    'The pause between desire and decision. Forged from discipline.',
    'armor', 'armor', 'failure_reduction', 0.25, NULL, 1500, 8, 20
),
(
    'Debt Ward',
    'Reduces Gold penalty from ALL failures and critical failures by 20%.',
    'Scarred by past mistakes. Armored by the lessons learned.',
    'armor', 'armor', 'failure_reduction', 0.20, NULL, 2500, 15, 30
),
(
    'Emergency Fund Plate',
    'Reduces Gold penalty from all failures by 30%.',
    'Three to six months of protection, forged into steel.',
    'armor', 'armor', 'failure_reduction', 0.30, NULL, 4000, 22, 40
);

-- -------------------------------------------------------
-- WEAPONS (+XP on success)
-- -------------------------------------------------------
INSERT INTO `store_items`
    (name, description, flavor_text, category, slot, effect_type, effect_value,
     effect_category, price, level_req, sort_order) VALUES
(
    'Negotiation Blade',
    '+15% XP from Work encounter successes.',
    'Every conversation is a contract. Know your leverage.',
    'weapon', 'weapon', 'xp_boost', 0.15, 'work', 2000, 10, 10
),
(
    'Market Timing Sword',
    '+15% XP from Investing encounter successes.',
    'Impossible to wield perfectly. Devastating when you get close.',
    'weapon', 'weapon', 'xp_boost', 0.15, 'investing', 2000, 10, 20
),
(
    'Compound Interest Staff',
    '+10% XP from ALL encounter successes.',
    'Slow at first. Then suddenly, overwhelmingly powerful.',
    'weapon', 'weapon', 'xp_boost', 0.10, NULL, 3500, 20, 30
),
(
    'Index Fund Axe',
    '+20% XP from ALL encounter successes and critical successes.',
    'Diversified, low-cost, and surprisingly deadly.',
    'weapon', 'weapon', 'xp_boost', 0.20, NULL, 6000, 30, 40
);

-- -------------------------------------------------------
-- CONSUMABLES (one-time use, replenish daily)
-- -------------------------------------------------------
INSERT INTO `store_items`
    (name, description, flavor_text, category, slot, effect_type, effect_value,
     effect_category, price, level_req, sort_order) VALUES
(
    'Action Potion',
    'Immediately restores 2 daily adventure actions.',
    'Brewed from caffeine, ambition, and a questionable work-life balance.',
    'consumable', 'consumable', 'action_restore', 2, NULL, 300, 1, 10
),
(
    'Lucky Charm',
    'Adds +3 to your very next adventure roll.',
    'A rabbit''s foot, a four-leaf clover, and a well-researched thesis.',
    'consumable', 'consumable', 'roll_boost_once', 3, NULL, 250, 1, 20
),
(
    'Second Chance Scroll',
    'Re-roll your most recent failed adventure. Once per day.',
    'The market corrects. So can you.',
    'consumable', 'consumable', 'reroll_once', 1, NULL, 500, 5, 30
);

-- Settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('store_enabled', '1', 'Enable the item store module'),
('consumable_daily_limit', '5', 'Max quantity of each consumable a player can hold')
ON DUPLICATE KEY UPDATE description = VALUES(description);
