-- =============================================================================
-- Onboarding dismissal flags
-- Tracks which per-page first-visit tips each user has dismissed.
-- Run against both lotgd_dev and lotgd_prod.
-- =============================================================================

CREATE TABLE user_dismissals (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    flag            VARCHAR(64)     NOT NULL,
    dismissed_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dismissal_user_flag (user_id, flag),
    CONSTRAINT fk_dismissal_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
);
