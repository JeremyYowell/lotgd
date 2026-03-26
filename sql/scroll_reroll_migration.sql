-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Second Chance Scroll — DB-backed daily tracking
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- Track scroll use in daily_state (same reset pattern as dragon_challenge_used)
-- Scroll is reusable: one use per day, does NOT disappear from inventory
ALTER TABLE `daily_state`
    ADD COLUMN `reroll_used_scroll` TINYINT(1) NOT NULL DEFAULT 0;
