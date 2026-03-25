-- =============================================================================
-- PvP initiative migration
-- Adds initiative_order column to pvp_sessions to persist attack order.
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

ALTER TABLE `pvp_sessions`
    ADD COLUMN `initiative_order` ENUM('challenger','defender') DEFAULT NULL
    AFTER `round`;
