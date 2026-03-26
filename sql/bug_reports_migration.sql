-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Bug Report System
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bug_reports` (
    `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED   NOT NULL,
    `subject`     VARCHAR(200)   NOT NULL,
    `description` TEXT           NOT NULL,
    `page_url`    VARCHAR(500)   DEFAULT NULL COMMENT 'URL or page where the bug occurred',
    `severity`    ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    `status`      ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
    `admin_note`  TEXT           DEFAULT NULL,
    `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME       DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at` DESC),
    CONSTRAINT `fk_br_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
