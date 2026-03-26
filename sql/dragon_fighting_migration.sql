-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Debt Dragon: add dragon_fighting state to adventure_sessions ENUM
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

ALTER TABLE `adventure_sessions`
    MODIFY `state` ENUM('scenario','result','dragon','dragon_fighting','dragon_result')
        NOT NULL DEFAULT 'scenario';
