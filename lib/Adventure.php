<?php
/**
 * lib/Adventure.php — Adventure system engine
 */

class Adventure {

    private Database $db;

    // Class bonus categories
    private const CLASS_BONUSES = [
        'investor'    => ['investing'],
        'debt_slayer' => ['banking'],
        'saver'       => ['shopping'],
        'entrepreneur'=> ['work'],
        'minimalist'  => ['daily_life'],
    ];

    private const CLASS_BONUS_VALUE = 3;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =========================================================================
    // SCENARIO SELECTION
    // =========================================================================

    /**
     * Pick a random active scenario appropriate for the player's level.
     * Avoids repeating the last 3 scenarios the player has seen.
     */
    public function pickScenario(int $userId, int $level): array|false {
        // Get recent scenario IDs to avoid repeats
        $recent = $this->db->fetchAll(
            "SELECT scenario_id FROM adventure_log
             WHERE user_id = ? ORDER BY adventured_at DESC LIMIT 3",
            [$userId]
        );
        $recentIds = array_column($recent, 'scenario_id');

        $exclude = $recentIds
            ? 'AND id NOT IN (' . implode(',', array_map('intval', $recentIds)) . ')'
            : '';

        return $this->db->fetchOne(
            "SELECT * FROM adventure_scenarios
             WHERE is_active = 1
               AND min_level <= ?
               AND max_level >= ?
               {$exclude}
             ORDER BY RAND()
             LIMIT 1",
            [$level, $level]
        );
    }

    /**
     * Get all choices for a scenario.
     */
    public function getChoices(int $scenarioId): array {
        return $this->db->fetchAll(
            "SELECT * FROM adventure_choices
             WHERE scenario_id = ? ORDER BY sort_order ASC",
            [$scenarioId]
        );
    }

    // =========================================================================
    // ROLL ENGINE
    // =========================================================================

    /**
     * Calculate the modifier for a player given their level and class,
     * and the category of the scenario.
     */
    public function calculateModifier(int $level, string $class, string $category): int {
        $levelMod = (int) floor($level / 5);   // +1 per 5 levels, max +10

        $classMod = 0;
        $bonusCategories = self::CLASS_BONUSES[$class] ?? [];
        if (in_array($category, $bonusCategories)) {
            $classMod = self::CLASS_BONUS_VALUE;
        }

        return $levelMod + $classMod;
    }

    /**
     * Roll a d20 and return the raw value.
     */
    public function rollD20(): int {
        return random_int(1, 20);
    }

    /**
     * Determine outcome from roll vs difficulty.
     *
     * crit_success  = natural 20 on the d20 (before modifiers)
     * success       = final roll >= DC
     * failure       = final roll < DC, miss by less than 5
     * crit_failure  = final roll misses DC by 5+
     *
     * The raw d20 roll must be passed separately so we can detect a natural 20.
     */
    public function determineOutcome(int $finalRoll, int $difficulty, int $rawRoll = 0): string {
        // Natural 20 on the die = critical success regardless of DC
        if ($rawRoll === 20) {
            return 'crit_success';
        }
        $diff = $finalRoll - $difficulty;
        return match(true) {
            $diff >= 0  => 'success',
            $diff >= -4 => 'failure',
            default     => 'crit_failure',
        };
    }

    // =========================================================================
    // REWARD CALCULATION
    // =========================================================================

    /**
     * Calculate XP and Gold delta based on outcome and base values.
     * Returns ['xp' => int, 'gold' => int]
     * Gold is negative on failure outcomes.
     */
    public function calculateRewards(string $outcome, int $baseXp, int $baseGold, int $currentGold): array {
        return match($outcome) {
            'crit_success'  => [
                'xp'   => (int) round($baseXp   * 1.5),
                'gold' => (int) round($baseGold  * 1.5),
            ],
            'success'       => [
                'xp'   => $baseXp,
                'gold' => $baseGold,
            ],
            'failure'       => [
                'xp'   => 0,
                'gold' => -min($currentGold, (int) round($baseGold * 0.25)),
            ],
            'crit_failure'  => [
                'xp'   => 0,
                'gold' => -min($currentGold, (int) round($baseGold * 0.5)),
            ],
            default => ['xp' => 0, 'gold' => 0],
        };
    }

    // =========================================================================
    // EXECUTE ADVENTURE
    // =========================================================================

    /**
     * Execute a complete adventure action.
     * Called after the player has selected a choice.
     *
     * Returns full result array with narrative and rewards.
     */
    public function execute(int $userId, int $choiceId, array $user): array {
        $choice = $this->db->fetchOne(
            "SELECT ac.*, ads.category, ads.title AS scenario_title, ads.id AS scenario_id
             FROM adventure_choices ac
             JOIN adventure_scenarios ads ON ads.id = ac.scenario_id
             WHERE ac.id = ?",
            [$choiceId]
        );

        if (!$choice) {
            return ['success' => false, 'error' => 'Invalid choice.'];
        }

        // Roll
        $roll     = $this->rollD20();
        $modifier = $this->calculateModifier(
            (int)$user['level'],
            $user['class'],
            $choice['category']
        );
        $finalRoll = $roll + $modifier;
        $outcome   = $this->determineOutcome($finalRoll, (int)$choice['difficulty'], $roll);

        // Rewards
        $rewards = $this->calculateRewards(
            $outcome,
            (int)$choice['base_xp'],
            (int)$choice['base_gold'],
            (int)$user['gold']
        );

        // Narrative
        $narrative = match($outcome) {
            'crit_success' => $choice['crit_success_narrative'],
            'success'      => $choice['success_narrative'],
            'failure'      => $choice['failure_narrative'],
            'crit_failure' => $choice['crit_failure_narrative'],
        };

        // Apply rewards
        $this->db->beginTransaction();
        try {
            // XP
            $userModel = new User();
            $xpResult  = ['leveled_up' => false, 'new_level' => $user['level']];
            if ($rewards['xp'] > 0) {
                $xpResult = $userModel->awardXp($userId, $rewards['xp']);
            }

            // Gold (add or subtract, floor at 0)
            if ($rewards['gold'] !== 0) {
                if ($rewards['gold'] > 0) {
                    $this->db->run(
                        "UPDATE users SET gold = gold + ? WHERE id = ?",
                        [$rewards['gold'], $userId]
                    );
                } else {
                    $this->db->run(
                        "UPDATE users SET gold = GREATEST(0, gold + ?) WHERE id = ?",
                        [$rewards['gold'], $userId]
                    );
                }
            }

            // Log
            $this->db->run(
                "INSERT INTO adventure_log
                 (user_id, scenario_id, choice_id, roll, modifier, final_roll,
                  difficulty, outcome, xp_delta, gold_delta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId, (int)$choice['scenario_id'], $choiceId,
                    $roll, $modifier, $finalRoll,
                    (int)$choice['difficulty'], $outcome,
                    $rewards['xp'], $rewards['gold'],
                ]
            );

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollBack();
            appLog('error', 'Adventure execute failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Something went wrong. Please try again.'];
        }

        return [
            'success'       => true,
            'outcome'       => $outcome,
            'roll'          => $roll,
            'modifier'      => $modifier,
            'final_roll'    => $finalRoll,
            'difficulty'    => (int)$choice['difficulty'],
            'narrative'     => $narrative,
            'xp'            => $rewards['xp'],
            'gold'          => $rewards['gold'],
            'leveled_up'    => $xpResult['leveled_up'],
            'new_level'     => $xpResult['new_level'],
            'gold_awarded'  => $xpResult['gold_awarded'] ?? 0,
            'choice_text'   => $choice['choice_text'],
            'scenario_title'=> $choice['scenario_title'],
            'choice_id'     => $choiceId,
        ];
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    /**
     * Get the bonus categories for a given class (used in templates).
     */
    public static function getBonusCategories(string $class): array {
        return self::CLASS_BONUSES[$class] ?? [];
    }

    // =========================================================================
    // HISTORY
    // =========================================================================

    public function getRecentLog(int $userId, int $limit = 10): array {
        return $this->db->fetchAll(
            "SELECT al.*, ads.title AS scenario_title, ac.choice_text
             FROM adventure_log al
             JOIN adventure_scenarios ads ON ads.id = al.scenario_id
             JOIN adventure_choices ac    ON ac.id  = al.choice_id
             WHERE al.user_id = ?
             ORDER BY al.adventured_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }
}
