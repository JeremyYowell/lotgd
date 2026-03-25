-- =============================================================================
-- Adventure Voice Mode — run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- Track generated audio files per scenario/choice
CREATE TABLE IF NOT EXISTS `adventure_audio` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `scenario_id`  INT UNSIGNED  NOT NULL,
    `choice_id`    INT UNSIGNED  DEFAULT NULL,  -- NULL = title/desc audio
    `audio_type`   ENUM('title_desc','choice_text','success','failure',
                        'crit_success','crit_failure') NOT NULL,
    `file_path`    VARCHAR(255)  NOT NULL,
    `char_count`   INT           NOT NULL DEFAULT 0,
    `generated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_audio` (`scenario_id`, `choice_id`, `audio_type`),
    CONSTRAINT `fk_advaudio_scenario` FOREIGN KEY (`scenario_id`)
        REFERENCES `adventure_scenarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add voice mode preference to users table
ALTER TABLE `users`
    ADD COLUMN `voice_mode` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `login_streak`;

-- Add ElevenLabs settings (safe to run multiple times)
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('elevenlabs_api_key',         '',                        'ElevenLabs API key'),
('elevenlabs_voice_id',        'RILOU7YmBhvwJGDGjNmP',   'ElevenLabs voice ID'),
('elevenlabs_model_id',        'eleven_multilingual_v2',  'ElevenLabs model'),
('elevenlabs_stability',       '0.5',                     'Voice stability (0-1)'),
('elevenlabs_similarity_boost','0.75',                    'Voice similarity boost (0-1)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
