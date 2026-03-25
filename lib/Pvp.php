<?php
/**
 * lib/Pvp.php — PvP Combat Engine
 *
 * Combat model:
 *   - Initiative rolled ONCE at fight start, stored in pvp_sessions.initiative_order
 *   - Each Attack press = one "round" — attack/counter until a HIT lands on either side
 *     (misses are resolved automatically, no extra button presses needed)
 *   - Each round ends after the first hit or after both sides have attacked once (all miss)
 *   - Up to MAX_ROUNDS rounds per fight
 *   - Flee: roll d20 + modifier vs DC 12
 */
class Pvp {

    private Database $db;

    const XP_WIN        = 50;
    const XP_DRAW       = 15;
    const XP_LOSS       = 5;
    const XP_FLEE       = 0;
    const XP_LEVEL_BONUS= 10;
    const MAX_ROUNDS    = 10;
    const FLEE_DC       = 12;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =========================================================================
    // PUBLIC — QUERY HELPERS
    // =========================================================================

    public function getChallengeableTargets(int $challengerId, int $challengerLevel): array {
        $today = date('Y-m-d');
        return $this->db->fetchAll(
            "SELECT u.id, u.username, u.class, u.`level`, u.xp,
                    COALESCE(ps.wins, 0) AS pvp_wins,
                    COALESCE(ps.losses, 0) AS pvp_losses
             FROM users u
             LEFT JOIN pvp_stats ps ON ps.user_id = u.id
             WHERE u.id != ?
               AND u.is_banned = 0
               AND u.`level` >= ?
               AND u.id NOT IN (
                   SELECT defender_id FROM pvp_log
                   WHERE challenger_id = ? AND DATE(fought_at) = ?
               )
             ORDER BY u.`level` ASC, u.username ASC
             LIMIT 50",
            [$challengerId, $challengerLevel, $challengerId, $today]
        );
    }

    public function alreadyFoughtToday(int $challengerId, int $defenderId): bool {
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM pvp_log
             WHERE challenger_id = ? AND defender_id = ? AND DATE(fought_at) = CURDATE()",
            [$challengerId, $defenderId]
        ) > 0;
    }

    public function getActiveSession(int $challengerId): ?array {
        $row = $this->db->fetchOne(
            "SELECT * FROM pvp_sessions WHERE challenger_id = ?",
            [$challengerId]
        );
        return $row ?: null;
    }

    public function getStats(int $userId): array {
        $row = $this->db->fetchOne("SELECT * FROM pvp_stats WHERE user_id = ?", [$userId]);
        return $row ?: ['wins' => 0, 'losses' => 0, 'draws' => 0, 'fled' => 0, 'xp_earned' => 0];
    }

    public function getRecentFights(int $userId, int $limit = 10): array {
        return $this->db->fetchAll(
            "SELECT pl.*,
                    uc.username AS challenger_name, uc.class AS challenger_class,
                    ud.username AS defender_name,   ud.class AS defender_class
             FROM pvp_log pl
             JOIN users uc ON uc.id = pl.challenger_id
             JOIN users ud ON ud.id = pl.defender_id
             WHERE pl.challenger_id = ? OR pl.defender_id = ?
             ORDER BY pl.fought_at DESC
             LIMIT ?",
            [$userId, $userId, $limit]
        );
    }

    // =========================================================================
    // PUBLIC — COMBAT ACTIONS
    // =========================================================================

    /**
     * Start a new fight. Rolls initiative once and stores it.
     */
    public function startFight(array $challenger, array $defender): array {
        $this->db->run(
            "DELETE FROM pvp_sessions WHERE challenger_id = ?",
            [$challenger['id']]
        );

        $challMaxHp = $this->calcMaxHp($challenger);
        $defMaxHp   = $this->calcMaxHp($defender);

        // Roll initiative ONCE — determines attack order for the entire fight
        $challInit = rand(1, 20) + $this->calcAttackMod($challenger);
        $defInit   = rand(1, 20) + $this->calcAttackMod($defender);
        $initiative = $challInit >= $defInit ? 'challenger' : 'defender';

        $initLog = "[Initiative] {$challenger['username']}: {$challInit} vs "
                 . "{$defender['username']}: {$defInit}. "
                 . ($initiative === 'challenger'
                    ? "{$challenger['username']} has the initiative for this battle!"
                    : "{$defender['username']} has the initiative for this battle!");

        $this->db->run(
            "INSERT INTO pvp_sessions
             (challenger_id, defender_id, round, initiative_order,
              challenger_hp, defender_hp, max_challenger_hp, max_defender_hp,
              combat_log, state)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, 'active')",
            [
                $challenger['id'], $defender['id'],
                $initiative,
                $challMaxHp, $defMaxHp,
                $challMaxHp, $defMaxHp,
                $initLog,
            ]
        );

        return $this->getActiveSession((int)$challenger['id']);
    }

    /**
     * Execute one round of combat.
     *
     * A "round" = one press of the Attack button.
     * We resolve attacks in initiative order. The round continues resolving
     * until a hit lands on either side, then stops so the player can see
     * the result and decide to attack again or flee.
     * If both sides miss entirely, we still advance the round counter.
     */
    public function doAttack(int $challengerId): array {
        $session    = $this->getActiveSession($challengerId);
        if (!$session || $session['state'] !== 'active') {
            return ['error' => 'No active fight.'];
        }

        $challenger = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$challengerId]);
        $defender   = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$session['defender_id']]);

        $challHp    = (int)$session['challenger_hp'];
        $defHp      = (int)$session['defender_hp'];
        $round      = (int)$session['round'];
        $log        = $session['combat_log'];
        $initiative = $session['initiative_order'] ?? 'challenger';

        $roundLog = "\n--- Round {$round} ---";

        // Determine attack order from stored initiative
        $challGoesFirst = ($initiative === 'challenger');

        if ($challGoesFirst) {
            // Challenger attacks
            [$challHp, $defHp, $log1, $hit1] = $this->resolveAttack($challenger, $defender, $challHp, $defHp);
            $roundLog .= $log1;

            // Defender counterattacks (only if still alive, and only if no hit yet — 
            // we always let both sides attack once per round regardless of hit)
            if ($defHp > 0) {
                [$defHp, $challHp, $log2, $hit2] = $this->resolveAttack($defender, $challenger, $defHp, $challHp);
                $roundLog .= $log2;
            }
        } else {
            // Defender attacks first
            [$defHp, $challHp, $log1, $hit1] = $this->resolveAttack($defender, $challenger, $defHp, $challHp);
            $roundLog .= $log1;

            if ($challHp > 0) {
                [$challHp, $defHp, $log2, $hit2] = $this->resolveAttack($challenger, $defender, $challHp, $defHp);
                $roundLog .= $log2;
            }
        }

        $log .= $roundLog;

        // Check end conditions
        $finished = false;
        $result   = null;

        if ($defHp <= 0 && $challHp <= 0) {
            $result = 'draw'; $finished = true;
        } elseif ($defHp <= 0) {
            $result = 'challenger_win'; $finished = true;
        } elseif ($challHp <= 0) {
            $result = 'defender_win'; $finished = true;
        } elseif ($round >= self::MAX_ROUNDS) {
            $result = 'draw'; $finished = true;
            $log   .= "\n\nThe battle has raged for {$round} rounds — both fighters are exhausted. Draw!";
        }

        if ($finished) {
            return $this->finishFight($session, $challenger, $defender, $challHp, $defHp, $result, $log, $round);
        }

        $this->db->run(
            "UPDATE pvp_sessions
             SET round = ?, challenger_hp = ?, defender_hp = ?, combat_log = ?
             WHERE challenger_id = ?",
            [$round + 1, $challHp, $defHp, $log, $challengerId]
        );

        return $this->getActiveSession($challengerId) + ['finished' => false];
    }

    /**
     * Challenger attempts to flee.
     */
    public function doFlee(int $challengerId): array {
        $session    = $this->getActiveSession($challengerId);
        if (!$session || $session['state'] !== 'active') {
            return ['error' => 'No active fight.'];
        }

        $challenger = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$challengerId]);
        $defender   = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$session['defender_id']]);

        $fleeMod  = (int)floor($challenger['level'] / 5);
        $fleeRoll = rand(1, 20) + $fleeMod;
        $log      = $session['combat_log'];
        $challHp  = (int)$session['challenger_hp'];
        $defHp    = (int)$session['defender_hp'];

        $log .= "\n--- Flee Attempt ---";
        $log .= "\n{$challenger['username']} attempts to flee! Roll: {$fleeRoll} vs DC " . self::FLEE_DC . ". ";

        if ($fleeRoll >= self::FLEE_DC) {
            $log .= "{$challenger['username']} escapes!";
            return $this->finishFight(
                $session, $challenger, $defender,
                $challHp, $defHp, 'fled', $log, (int)$session['round']
            );
        }

        // Flee failed — defender gets a free hit
        $log .= "Failed! {$defender['username']} lands a free strike!";
        $damage  = $this->calcDamage($defender);
        $challHp = max(0, $challHp - $damage);
        $log    .= "\n  {$defender['username']} deals {$damage} damage. {$challenger['username']} has {$challHp} HP.";

        if ($challHp <= 0) {
            $log .= " {$challenger['username']} falls!";
            return $this->finishFight(
                $session, $challenger, $defender,
                $challHp, $defHp, 'defender_win', $log, (int)$session['round']
            );
        }

        $this->db->run(
            "UPDATE pvp_sessions
             SET round = round + 1, challenger_hp = ?, combat_log = ?
             WHERE challenger_id = ?",
            [$challHp, $log, $challengerId]
        );

        return $this->getActiveSession($challengerId) + ['finished' => false, 'flee_failed' => true];
    }

    // =========================================================================
    // PRIVATE — COMBAT RESOLUTION
    // =========================================================================

    /**
     * Resolve one attack.
     * Returns [attacker_hp, defender_hp, log, hit_landed].
     * $atkHp is passed through unchanged (attacker HP doesn't change from attacking).
     */
    private function resolveAttack(array $attacker, array $defender, int $atkHp, int $defHp): array {
        $atkRoll = rand(1, 20) + $this->calcAttackMod($attacker);
        $defRoll = rand(1, 20) + $this->calcDefenseMod($defender);
        $log     = "\n  {$attacker['username']} attacks ({$atkRoll}) vs {$defender['username']} defends ({$defRoll}). ";
        $hit     = false;

        if ($atkRoll > $defRoll) {
            $dmg   = $this->calcDamage($attacker);
            $defHp = max(0, $defHp - $dmg);
            $flavor = $this->getHitFlavor($attacker, $defender);
            $log  .= "Hit! {$flavor} ({$dmg} damage — {$defender['username']} has {$defHp} HP)";
            if ($defHp <= 0) $log .= " {$defender['username']} is defeated!";
            $hit = true;
        } else {
            $flavor = $this->getMissFlavor($attacker, $defender);
            $log .= "Miss! {$flavor}";
        }

        return [$atkHp, $defHp, $log, $hit];
    }

    /**
     * Return a class-themed flavor string for a landed hit.
     */
    private function getHitFlavor(array $attacker, array $defender): string {
        $a = $attacker['username'];
        $d = $defender['username'];

        $pool = match($attacker['class'] ?? '') {
            'investor' => [
                "{$a} deploys a 30-year compound interest projection",
                "{$a} slaps {$d} with a perfectly diversified index fund portfolio",
                "{$a} weaponizes the Rule of 72",
                "{$a} cites their year-to-date returns and it stings",
                "{$a} summons the power of low-cost index funds",
            ],
            'debt_slayer' => [
                "{$a} pulls out {$d}'s credit history report — it is not pretty",
                "{$a} flourishes a laminated debt payoff chart",
                "{$a} summons the Debt Avalanche and it lands with full force",
                "{$a} deploys a flawless 800 credit score directly at {$d}",
                "{$a} hits {$d} with a fully paid-off car title",
            ],
            'saver' => [
                "{$a} rubs a 70% savings rate right in {$d}'s face",
                "{$a} brandishes a thrift store find — paid \$4, worth \$40",
                "{$a} presents {$d} with a fully funded 6-month emergency fund",
                "{$a} shows {$d} their coupon binder — it is devastating",
                "{$a} sells a refurbished curb-pickup lamp for pure profit",
            ],
            'entrepreneur' => [
                "{$a} flips a refurbished curb-pickup couch for a fat profit and channels it",
                "{$a} announces a well-timed promotion with a raise — the flex is lethal",
                "{$a} drops a side hustle P&L statement on {$d}",
                "{$a} closes a client deal worth more than {$d}'s monthly budget",
                "{$a} hits {$d} with a fully automated income stream",
            ],
            'minimalist' => [
                "{$a} strikes {$d} with a zero-based budget so tight it cuts deep",
                "{$a} owns only 43 possessions — one of them just hit {$d}",
                "{$a} cancels {$d}'s streaming subscriptions mid-battle",
                "{$a} channels pure intentional living into a devastating blow",
                "{$a} hits {$d} with a lifestyle so lean it has no drag",
            ],
            default => [
                "{$a} lands a decisive financial blow on {$d}",
                "{$a} strikes with surprising fiscal discipline",
                "{$a} channels their money wisdom into a powerful hit",
            ],
        };

        return $pool[array_rand($pool)];
    }

    /**
     * Return a class-themed flavor string for a missed attack.
     */
    private function getMissFlavor(array $attacker, array $defender): string {
        $a = $attacker['username'];
        $d = $defender['username'];

        $pool = match($attacker['class'] ?? '') {
            'investor' => [
                "{$a}'s market timing attempt fails spectacularly.",
                "{$a} tries to explain expense ratios — {$d} doesn't care.",
                "{$a}'s hot stock tip doesn't pan out.",
            ],
            'debt_slayer' => [
                "{$a} waves the debt payoff chart but {$d} already paid theirs off.",
                "{$a}'s credit score recitation misses the mark.",
                "{$a}'s balance transfer offer is declined.",
            ],
            'saver' => [
                "{$a}'s frugal finesse fails to connect.",
                "{$a}'s coupon expires at the critical moment.",
                "{$a} reaches for the emergency fund but hesitates.",
            ],
            'entrepreneur' => [
                "{$a}'s pitch deck fails to impress.",
                "{$a}'s side hustle stalls at the worst time.",
                "{$a}'s revenue projections turn out to be optimistic.",
            ],
            'minimalist' => [
                "{$a} brings too little force — minimalism has its limits.",
                "{$a} reaches for a weapon but already decluttered it.",
                "{$a}'s intentional stillness is a little too still.",
            ],
            default => [
                "{$a}'s attack goes wide.",
                "{$d} sidesteps the attempt.",
                "{$a} overreaches and misses.",
            ],
        };

        return $pool[array_rand($pool)];
    }

    /**
     * Close out the fight, award XP, update stats and log.
     */
    private function finishFight(
        array $session, array $chall, array $def,
        int $challHp, int $defHp, string $result, string $log, int $rounds
    ): array {
        $levelDiff = max(0, (int)$def['level'] - (int)$chall['level']);
        $challXp   = 0;
        $defXp     = 0;

        $log .= "\n\n═══════════════════════════";
        switch ($result) {
            case 'challenger_win':
                $challXp = self::XP_WIN + ($levelDiff * self::XP_LEVEL_BONUS);
                $defXp   = self::XP_LOSS;
                $log    .= "\n{$chall['username']} is victorious after {$rounds} round" . ($rounds !== 1 ? 's' : '') . "!";
                $log    .= "\n+{$challXp} XP awarded.";
                break;
            case 'defender_win':
                $defXp   = self::XP_WIN;
                $challXp = self::XP_LOSS;
                $log    .= "\n{$def['username']} wins after {$rounds} round" . ($rounds !== 1 ? 's' : '') . "!";
                $log    .= "\n+{$defXp} XP awarded to {$def['username']}.";
                break;
            case 'draw':
                $challXp = self::XP_DRAW;
                $defXp   = self::XP_DRAW;
                $log    .= "\nThe battle ends in a draw after {$rounds} round" . ($rounds !== 1 ? 's' : '') . "!";
                $log    .= "\n+" . self::XP_DRAW . " XP awarded to both fighters.";
                break;
            case 'fled':
                $challXp = self::XP_FLEE;
                $defXp   = self::XP_LOSS;
                $log    .= "\n{$chall['username']} fled the battle. No XP earned.";
                break;
        }
        $log .= "\n═══════════════════════════";

        $this->db->beginTransaction();
        try {
            if ($challXp > 0) (new User())->awardXp((int)$chall['id'], $challXp);
            if ($defXp > 0)   (new User())->awardXp((int)$def['id'],   $defXp);

            $this->db->run(
                "INSERT INTO pvp_log
                 (challenger_id, defender_id, result, rounds, challenger_xp, defender_xp, combat_log)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$chall['id'], $def['id'], $result, $rounds, $challXp, $defXp, $log]
            );

            $challStatCol = match($result) {
                'challenger_win' => 'wins',
                'defender_win'   => 'losses',
                'draw'           => 'draws',
                'fled'           => 'fled',
            };
            $this->db->run(
                "INSERT INTO pvp_stats (user_id, {$challStatCol}, xp_earned)
                 VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE {$challStatCol} = {$challStatCol} + 1,
                                         xp_earned = xp_earned + ?",
                [$chall['id'], $challXp, $challXp]
            );

            $defStatCol = match($result) {
                'challenger_win' => 'losses',
                'defender_win'   => 'wins',
                'draw'           => 'draws',
                'fled'           => 'wins',
            };
            $this->db->run(
                "INSERT INTO pvp_stats (user_id, {$defStatCol}, xp_earned)
                 VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE {$defStatCol} = {$defStatCol} + 1,
                                         xp_earned = xp_earned + ?",
                [$def['id'], $defXp, $defXp]
            );

            $this->db->run(
                "UPDATE pvp_sessions
                 SET state = 'finished', result = ?, challenger_hp = ?, defender_hp = ?,
                     combat_log = ?
                 WHERE challenger_id = ?",
                [$result, $challHp, $defHp, $log, $chall['id']]
            );

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            appLog('error', 'PvP finishFight failed', ['error' => $e->getMessage()]);
        }

        return [
            'finished'        => true,
            'result'          => $result,
            'challenger_hp'   => $challHp,
            'defender_hp'     => $defHp,
            'max_challenger_hp'=> (int)$session['max_challenger_hp'],
            'max_defender_hp'  => (int)$session['max_defender_hp'],
            'challenger_xp'   => $challXp,
            'defender_xp'     => $defXp,
            'combat_log'      => $log,
            'rounds'          => $rounds,
            'challenger_name' => $chall['username'],
            'defender_name'   => $def['username'],
        ];
    }

    // =========================================================================
    // PRIVATE — STAT CALCULATORS
    // =========================================================================

    private function calcMaxHp(array $user): int {
        $level      = max(1, (int)$user['level']);
        $baseHp     = 20 + ($level - 1) * 2;
        $store      = new Store();
        $equipped   = $store->getEquipped((int)$user['id']);
        $armorBonus = 0;
        if (isset($equipped['armor']) && $equipped['armor']['effect_type'] === 'failure_reduction') {
            $armorBonus = (int)round((1 - (float)$equipped['armor']['effect_value']) * 10);
        }
        return $baseHp + $armorBonus;
    }

    private function calcAttackMod(array $user): int {
        $store    = new Store();
        $equipped = $store->getEquipped((int)$user['id']);
        $bonus    = (isset($equipped['weapon']) && $equipped['weapon']['effect_type'] === 'xp_boost') ? 2 : 0;
        return (int)floor($user['level'] / 5) + $bonus;
    }

    private function calcDefenseMod(array $user): int {
        $store    = new Store();
        $equipped = $store->getEquipped((int)$user['id']);
        $bonus    = isset($equipped['armor']) ? 2 : 0;
        return (int)floor($user['level'] / 5) + $bonus;
    }

    private function calcDamage(array $user): int {
        $store    = new Store();
        $equipped = $store->getEquipped((int)$user['id']);
        $bonus    = isset($equipped['weapon']) ? (int)$equipped['weapon']['effect_value'] : 0;
        return rand(3, 8) + $bonus;
    }

    public function getCombatantStats(array $user): array {
        $store    = new Store();
        $equipped = $store->getEquipped((int)$user['id']);
        $wpnBonus = isset($equipped['weapon']) ? (int)$equipped['weapon']['effect_value'] : 0;
        return [
            'max_hp'      => $this->calcMaxHp($user),
            'attack_mod'  => $this->calcAttackMod($user),
            'defense_mod' => $this->calcDefenseMod($user),
            'damage_range'=> '3–' . (8 + $wpnBonus),
        ];
    }
}
