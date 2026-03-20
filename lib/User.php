<?php
/**
 * lib/User.php — User model and game logic
 */

class User {

    private Database $db;

    private const BASE_XP        = 250;
    private const LEVEL_EXPONENT = 1.8;

    // HP per level: base 20 + (level-1) * 2
    public static function maxHpForLevel(int $level): int {
        return 20 + (max(1, $level) - 1) * 2;
    }

    // Gold reward for reaching a new level
    public static function goldRewardForLevel(int $level): int {
        return $level * 50;
    }

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =========================================================================
    // LOOKUP
    // =========================================================================

    public function findById(int $id): array|false {
        return $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    }

    public function findByUsername(string $username): array|false {
        return $this->db->fetchOne("SELECT * FROM users WHERE username = ?", [$username]);
    }

    public function findByEmail(string $email): array|false {
        return $this->db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
    }

    // =========================================================================
    // REGISTRATION & AUTH
    // =========================================================================

    /**
     * Register a new user.
     * Generates a confirmation token and sends the confirmation email.
     * Returns ['success' => bool, 'user_id' => int, 'error' => string]
     */
    public function register(string $username, string $email, string $password, string $class = 'saver'): array {
        if (strlen($username) < 3 || strlen($username) > 50) {
            return ['success' => false, 'error' => 'Username must be 3–50 characters.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.'];
        }
        if (!in_array($class, ['investor', 'debt_slayer', 'saver', 'entrepreneur', 'minimalist'])) {
            $class = 'saver';
        }

        if ($this->findByUsername($username)) {
            return ['success' => false, 'error' => 'That username is already taken.'];
        }
        if ($this->findByEmail($email)) {
            return ['success' => false, 'error' => 'An account with that email already exists.'];
        }

        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $xpToNext = $this->xpForLevel(2);

        // Generate confirmation token
        $token     = bin2hex(random_bytes(32));
        $tokenExp  = date('Y-m-d H:i:s',
            time() + ((int)$this->db->getSetting('email_confirm_token_hours', 48) * 3600)
        );

        $this->db->run(
            "INSERT INTO users
             (username, email, email_confirmed, confirm_token, confirm_token_exp,
              password_hash, class, xp_to_next_level)
             VALUES (?, ?, 0, ?, ?, ?, ?, ?)",
            [$username, $email, $token, $tokenExp, $hash, $class, $xpToNext]
        );

        $userId = (int) $this->db->lastInsertId();

        // Send confirmation email
        $mailer = new Mailer();
        $sent   = $mailer->sendConfirmation($email, $username, $token);

        if (!$sent) {
            appLog('error', 'Confirmation email failed on registration', [
                'user_id' => $userId, 'email' => $email
            ]);
        }

        appLog('info', 'New user registered', [
            'user_id'  => $userId,
            'username' => $username,
            'env'      => APP_ENV,
            'email_sent' => $sent,
        ]);

        return ['success' => true, 'user_id' => $userId];
    }

    public function attemptLogin(string $username, string $password): array|false {
        $user = $this->findByUsername($username);
        if (!$user || $user['is_banned']) return false;
        if (!password_verify($password, $user['password_hash'])) return false;

        $this->updateLoginStreak($user);
        return $this->findById($user['id']);
    }

    // =========================================================================
    // XP & LEVELING
    // =========================================================================

    public function awardXp(int $userId, int $xp): array {
        $user = $this->findById($userId);
        if (!$user) return ['xp_gained' => 0, 'leveled_up' => false, 'new_level' => 1];

        $maxLevel  = (int) $this->db->getSetting('max_level', 50);
        $newXp     = $user['xp'] + $xp;
        $level     = (int) $user['level'];
        $leveledUp = false;

        $goldAwarded = 0;
        while ($level < $maxLevel && $newXp >= $this->xpForLevel($level + 1)) {
            $level++;
            $leveledUp   = true;
            $goldAwarded += self::goldRewardForLevel($level);
        }

        $xpToNext = $this->xpForLevel($level + 1);

        $this->db->run(
            "UPDATE users SET xp = ?, `level` = ?, xp_to_next_level = ?, updated_at = NOW() WHERE id = ?",
            [$newXp, $level, $xpToNext, $userId]
        );

        if ($leveledUp) {
            // Award Gold bonus for leveling up
            if ($goldAwarded > 0) {
                $this->db->run(
                    "UPDATE users SET gold = gold + ? WHERE id = ?",
                    [$goldAwarded, $userId]
                );
            }
            $this->checkLevelAchievements($userId, $level);
            appLog('info', 'User leveled up', [
                'user_id'   => $userId,
                'new_level' => $level,
                'gold'      => $goldAwarded,
            ]);
        }

        return [
            'xp_gained'    => $xp,
            'leveled_up'   => $leveledUp,
            'new_level'    => $level,
            'gold_awarded' => $goldAwarded,
        ];
    }

    public function xpForLevel(int $level): int {
        return (int) (self::BASE_XP * pow($level, self::LEVEL_EXPONENT));
    }

    // =========================================================================
    // WEALTH SCORE
    // =========================================================================

    public function recalculateWealthScore(int $userId): int {
        $totals = $this->db->fetchOne(
            "SELECT
               SUM(CASE WHEN entry_type = 'savings'      THEN amount ELSE 0 END) AS saved,
               SUM(CASE WHEN entry_type = 'investment'   THEN amount ELSE 0 END) AS invested,
               SUM(CASE WHEN entry_type = 'debt_payment' THEN amount ELSE 0 END) AS debt_paid
             FROM financial_entries WHERE user_id = ?",
            [$userId]
        );

        $score = (int)(
            ($totals['saved']     ?? 0) * 10 +
            ($totals['invested']  ?? 0) * 15 +
            ($totals['debt_paid'] ?? 0) * 12
        );

        $this->db->run(
            "UPDATE users
             SET wealth_score    = ?,
                 total_saved     = COALESCE(?, 0),
                 total_invested  = COALESCE(?, 0),
                 total_debt_paid = COALESCE(?, 0),
                 updated_at = NOW()
             WHERE id = ?",
            [$score, $totals['saved'], $totals['invested'], $totals['debt_paid'], $userId]
        );

        return $score;
    }

    // =========================================================================
    // DAILY STATE
    // =========================================================================

    public function getDailyState(int $userId): array {
        $today = date('Y-m-d');
        $state = $this->db->fetchOne(
            "SELECT * FROM daily_state WHERE user_id = ? AND state_date = ?",
            [$userId, $today]
        );

        if (!$state) {
            $actionLimit = (int) $this->db->getSetting('daily_action_limit', 10);
            $this->db->run(
                "INSERT INTO daily_state (user_id, state_date, actions_remaining) VALUES (?, ?, ?)",
                [$userId, $today, $actionLimit]
            );
            $state = $this->db->fetchOne(
                "SELECT * FROM daily_state WHERE user_id = ? AND state_date = ?",
                [$userId, $today]
            );
        }

        return $state;
    }

    public function consumeAction(int $userId): bool {
        $state = $this->getDailyState($userId);
        if ($state['actions_remaining'] <= 0) return false;
        $this->db->run(
            "UPDATE daily_state SET actions_remaining = actions_remaining - 1
             WHERE user_id = ? AND state_date = ?",
            [$userId, date('Y-m-d')]
        );
        return true;
    }

    public function useBigMove(int $userId): bool {
        $state = $this->getDailyState($userId);
        if ($state['big_move_used']) return false;
        $this->db->run(
            "UPDATE daily_state SET big_move_used = 1 WHERE user_id = ? AND state_date = ?",
            [$userId, date('Y-m-d')]
        );
        return true;
    }

    // =========================================================================
    // ACHIEVEMENTS
    // =========================================================================

    public function awardAchievement(int $userId, string $code): bool {
        $achievement = $this->db->fetchOne(
            "SELECT * FROM achievements WHERE code = ?", [$code]
        );
        if (!$achievement) return false;

        $already = $this->db->fetchValue(
            "SELECT COUNT(*) FROM user_achievements WHERE user_id = ? AND achievement_id = ?",
            [$userId, $achievement['id']]
        );
        if ($already) return false;

        $this->db->run(
            "INSERT INTO user_achievements (user_id, achievement_id) VALUES (?, ?)",
            [$userId, $achievement['id']]
        );

        $this->awardXp($userId, (int) $achievement['xp_reward']);
        $this->db->run(
            "UPDATE users SET gold = gold + ? WHERE id = ?",
            [$achievement['gold_reward'], $userId]
        );

        return true;
    }

    private function checkLevelAchievements(int $userId, int $level): void {
        $map = [5 => 'level_5', 10 => 'level_10', 25 => 'level_25', 50 => 'level_50'];
        if (isset($map[$level])) {
            $this->awardAchievement($userId, $map[$level]);
        }
    }

    // =========================================================================
    // LEADERBOARD CACHE REFRESH
    // =========================================================================

    public function refreshLeaderboard(): void {
        $this->db->exec("TRUNCATE TABLE leaderboard_cache");
        $this->db->exec(
            "INSERT INTO leaderboard_cache
                (`rank`, user_id, username, class, `level`, wealth_score, xp, total_saved, total_debt_paid)
             SELECT
                ROW_NUMBER() OVER (ORDER BY wealth_score DESC, xp DESC),
                id, username, class, `level`, wealth_score, xp,
                total_saved, total_debt_paid
             FROM users
             WHERE is_banned = 0
             ORDER BY wealth_score DESC, xp DESC
             LIMIT 100"
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function updateLoginStreak(array $user): void {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $lastLogin = $user['last_login'] ? date('Y-m-d', strtotime($user['last_login'])) : null;
        $streak    = ($lastLogin === $yesterday) ? $user['login_streak'] + 1 : 1;

        $this->db->run(
            "UPDATE users SET last_login = NOW(), login_streak = ? WHERE id = ?",
            [$streak, $user['id']]
        );

        if ($streak >= 7)  $this->awardAchievement($user['id'], 'streak_7');
        if ($streak >= 30) $this->awardAchievement($user['id'], 'streak_30');
    }
}
