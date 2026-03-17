-- =============================================================================
-- Adventure Sessions — DB-backed state for the adventure workflow
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

CREATE TABLE IF NOT EXISTS `adventure_sessions` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED    NOT NULL,
    `state`           ENUM('scenario','result') NOT NULL DEFAULT 'scenario',
    `scenario_id`     INT UNSIGNED    NOT NULL,
    `choices_json`    TEXT            NOT NULL,
    `result_json`     TEXT            DEFAULT NULL,
    `action_consumed` TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_session` (`user_id`),
    CONSTRAINT `fk_advsess_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
