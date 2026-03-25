-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Debt Dragon Mini-Game Migration
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- Expand the adventure_sessions state enum to include dragon challenge states
ALTER TABLE `adventure_sessions`
    MODIFY `state` ENUM('scenario','result','dragon','dragon_result')
        NOT NULL DEFAULT 'scenario';

-- Track whether the player has attempted the Debt Dragon challenge today
ALTER TABLE `daily_state`
    ADD COLUMN `dragon_challenge_used` TINYINT(1) NOT NULL DEFAULT 0;
