<?php
/**
 * lib/Onboarding.php — First-visit onboarding tip state
 * =============================================================================
 * Tracks which dismissible onboarding banners each player has seen and closed.
 * State is persisted in the user_dismissals table.
 *
 * Usage:
 *   if (!Onboarding::isDismissed($userId, 'onboard_adventure')) { ... }
 *   Onboarding::dismiss($userId, 'onboard_adventure');
 */
class Onboarding {

    /** Valid flag names — any flag not in this list is rejected by the API. */
    public const VALID_FLAGS = [
        'onboard_dashboard',
        'onboard_adventure',
        'onboard_portfolio',
        'onboard_store',
        'onboard_pvp',
        'onboard_leaderboard',
        'onboard_tavern',
    ];

    // -------------------------------------------------------------------------

    /**
     * Check whether a user has dismissed a given onboarding flag.
     * Returns false (show the tip) if the table doesn't exist yet.
     */
    public static function isDismissed(int $userId, string $flag): bool {
        try {
            $db = Database::getInstance();
            return (bool) $db->fetchValue(
                "SELECT 1 FROM user_dismissals WHERE user_id = ? AND flag = ? LIMIT 1",
                [$userId, $flag]
            );
        } catch (Throwable $e) {
            // Migration not yet run — treat every tip as undismissed
            appLog('warning', '[Onboarding] isDismissed query failed (migration pending?): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Record that a user has dismissed an onboarding flag.
     * Safe to call multiple times — uses INSERT IGNORE.
     * Silently no-ops if the table doesn't exist yet.
     */
    public static function dismiss(int $userId, string $flag): void {
        try {
            $db = Database::getInstance();
            $db->run(
                "INSERT IGNORE INTO user_dismissals (user_id, flag) VALUES (?, ?)",
                [$userId, $flag]
            );
        } catch (Throwable $e) {
            appLog('warning', '[Onboarding] dismiss query failed (migration pending?): ' . $e->getMessage());
        }
    }
}
