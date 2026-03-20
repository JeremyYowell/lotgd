<?php
/**
 * lib/Pvp.php — PvP Combat Engine
 *
 * Combat model:
 *   - Up to 10 rounds per fight
 *   - Each round: initiative roll (d20 + modifier) determines who attacks first
 *   - Attacker rolls d20 + attack_mod vs defender's d20 + defense_mod
 *   - If attacker roll > defender roll → hit → deal damage
 *   - Damage = rand(3, 8) + weapon_bonus
 *   - Defender auto-counterattacks in same round (if still alive)
 *   - Challenger may choose to Flee instead of attacking
 *   - Fight ends: one side reaches 0 HP, flee succeeds, or 10 rounds elapsed (draw)
 *
 * Stats:
 *   HP:             Base 20 + armor_hp_bonus
 *   Attack mod:     floor(level/5) + weapon_roll_bonus + class_pvp_bonus
 *   Defense mod:    floor(level/5) + armor_roll_bonus
 *   Damage:         rand(3,8) + weapon_damage_bonus
 *   Flee DC:        12 — roll d20 + floor(level/5) to escape
 */
class Pvp {

    private Database $db;

    // XP rewards
    const XP_WIN   = 50;
    const XP_DRAW  = 15;
    const XP_LOSS  = 5;
    const XP_FLEE  = 0;

    // XP bonus per level difference (attacker beating higher-level defender)
    const XP_LEVEL_BONUS = 10;

    // Max rounds before draw
    const MAX_ROUNDS = 10;

    // Flee DC
    const FLEE_DC = 12;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =========================================================================
    // PUBLIC — QUERY HELPERS
    // =========================================================================

    /**
     * Get list of challengeable players (same level or higher, not banned,
     * not already challenged today by this user, not self).
     */
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

    /**
     * Check if this challenger already fought this defender today.
     */
    public function alreadyFoughtToday(int $challengerId, int $defenderId): bool {
        $count = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM pvp_log
             WHERE challenger_id = ? AND defender_id = ? AND DATE(fought_at) = CURDATE()",
            [$challengerId, $defenderId]
        );
        return $count > 0;
    }

    /**
     * Get active session for this challenger, or null.
     */
    public function getActiveSession(int $challengerId): ?array {
        $row = $this->db->fetchOne(
            "SELECT * FROM pvp_sessions WHERE challenger_id = ? AND state = 'active'",
            [$challengerId]
        );
        return $row ?: null;
    }

    /**
     * Get PvP stats for a user.
     */
    public function getStats(int $userId): array {
        $row = $this->db->fetchOne(
            "SELECT * FROM pvp_stats WHERE user_id = ?",
            [$userId]
        );
        return $row ?: ['wins' => 0, 'losses' => 0, 'draws' => 0, 'fled' => 0, 'xp_earned' => 0];
    }

    /**
     * Get recent PvP fight history for a user (as challenger or defender).
     */
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
     * Start a new fight. Returns the initial session state.
     */
    public function startFight(array $challenger, array $defender): array {
        // Clear any stale session for this challenger
        $this->db->run(
            "DELETE FROM pvp_sessions WHERE challenger_id = ?",
            [$challenger['id']]
        );

        $challMaxHp = $this->calcMaxHp($challenger);
        $defMaxHp   = $this->calcMaxHp($defender);

        $this->db->run(
            "INSERT INTO pvp_sessions
             (challenger_id, defender_id, round, challenger_hp, defender_hp,
              max_challenger_hp, max_defender_hp, combat_log, state)
             VALUES (?, ?, 1, ?, ?, ?, ?, '', 'active')",
            [
                $challenger['id'], $defender['id'],
                $challMaxHp, $defMaxHp,
                $challMaxHp, $defMaxHp,
            ]
        );

        return $this->getActiveSession((int)$challenger['id']);
    }

    /**
     * Execute one round: challenger attacks.
     * Returns updated session with combat_log appended.
     */
    public function doAttack(int $challengerId): array {
        $session    = $this->getActiveSession($challengerId);
        if (!$session || $session['state'] !== 'active') {
            return ['error' => 'No active fight.'];
        }

        $challenger = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$challengerId]);
        $defender   = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$session['defender_id']]);

        $challHp = (int)$session['challenger_hp'];
        $defHp   = (int)$session['defender_hp'];
        $round   = (int)$session['round'];
        $log     = $session['combat_log'];

        $roundLog = $this->resolveRound($challenger, $defender, $challHp, $defHp, false);

        $challHp = $roundLog['challenger_hp'];
        $defHp   = $roundLog['defender_hp'];
        $log    .= $roundLog['log'];

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
            $log   .= "\n[Round {$round}] The fight has gone on too long — both adventurers collapse in exhaustion. Draw!";
        }

        if ($finished) {
            return $this->finishFight($session, $challenger, $defender, $challHp, $defHp, $result, $log, $round);
        }

        // Advance round
        $this->db->run(
            "UPDATE pvp_sessions
             SET round = ?, challenger_hp = ?, defender_hp = ?, combat_log = ?
             WHERE challenger_id = ?",
            [$round + 1, $challHp, $defHp, $log, $challengerId]
        );

        return $this->getActiveSession($challengerId) + ['round_log' => $roundLog['log'], 'finished' => false];
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

        $log .= "\n[Round {$session['round']}] {$challenger['username']} attempts to flee! ";
        $log .= "Flee roll: {$fleeRoll} vs DC " . self::FLEE_DC . ". ";

        if ($fleeRoll >= self::FLEE_DC) {
            $log .= "{$challenger['username']} escapes!";
            return $this->finishFight(
                $session, $challenger, $defender,
                (int)$session['challenger_hp'], (int)$session['defender_hp'],
                'fled', $log, (int)$session['round']
            );
        }

        // Flee failed — defender gets a free hit
        $log .= "Failed! {$defender['username']} lands a free strike!";
        $challHp = (int)$session['challenger_hp'];
        $defHp   = (int)$session['defender_hp'];

        $damage   = $this->calcDamage($defender);
        $challHp  = max(0, $challHp - $damage);
        $log     .= " {$defender['username']} deals {$damage} damage. "
                  . "{$challenger['username']} has {$challHp} HP remaining.";

        if ($challHp <= 0) {
            $log .= " {$challenger['username']} falls!";
            return $this->finishFight(
                $session, $challenger, $defender,
                $challHp, $defHp, 'defender_win', $log, (int)$session['round']
            );
        }

        $round = (int)$session['round'] + 1;
        $this->db->run(
            "UPDATE pvp_sessions
             SET round = ?, challenger_hp = ?, combat_log = ?
             WHERE challenger_id = ?",
            [$round, $challHp, $log, $challengerId]
        );

        return $this->getActiveSession($challengerId) + ['round_log' => $log, 'finished' => false, 'flee_failed' => true];
    }

    // =========================================================================
    // PRIVATE — COMBAT RESOLUTION
    // =========================================================================

    /**
     * Resolve one full round of combat (initiative → first attack → counterattack).
     * Modifies $challHp and $defHp by reference indirectly via return.
     */
    private function resolveRound(array $chall, array $def, int $challHp, int $defHp, bool $isFlee): array {
        $round = ''; // log for this round

        // Initiative
        $challInit = rand(1, 20) + $this->calcAttackMod($chall);
        $defInit   = rand(1, 20) + $this->calcAttackMod($def);

        $challGoesFirst = $challInit >= $defInit; // challenger wins ties

        $round .= "\n[Initiative] {$chall['username']}: {$challInit} vs {$def['username']}: {$defInit}. ";
        $round .= $challGoesFirst
            ? "{$chall['username']} strikes first!"
            : "{$def['username']} strikes first!";

        // Execute in initiative order
        if ($challGoesFirst) {
            [$challHp, $defHp, $log1] = $this->resolveAttack($chall, $def, $challHp, $defHp);
            $round .= $log1;
            if ($defHp > 0) {
                [$defHp, $challHp, $log2] = $this->resolveAttack($def, $chall, $defHp, $challHp);
                $round .= $log2;
            }
        } else {
            [$defHp, $challHp, $log1] = $this->resolveAttack($def, $chall, $defHp, $challHp);
            $round .= $log1;
            if ($challHp > 0) {
                [$challHp, $defHp, $log2] = $this->resolveAttack($chall, $def, $challHp, $defHp);
                $round .= $log2;
            }
        }

        return [
            'challenger_hp' => $challHp,
            'defender_hp'   => $defHp,
            'log'           => $round,
        ];
    }

    /**
     * One attack: attacker rolls vs defender. Returns [attacker_hp, defender_hp, log].
     */
    private function resolveAttack(array $attacker, array $defender, int $atkHp, int $defHp): array {
        $atkRoll = rand(1, 20) + $this->calcAttackMod($attacker);
        $defRoll = rand(1, 20) + $this->calcDefenseMod($defender);
        $log     = "\n  {$attacker['username']} attacks ({$atkRoll}) vs {$defender['username']} defends ({$defRoll}). ";

        if ($atkRoll > $defRoll) {
            $dmg   = $this->calcDamage($attacker);
            $defHp = max(0, $defHp - $dmg);
            $log  .= "Hit! {$dmg} damage. {$defender['username']} has {$defHp} HP.";
            if ($defHp <= 0) $log .= " {$defender['username']} is defeated!";
        } else {
            $log .= "Miss!";
        }

        return [$atkHp, $defHp, $log];
    }

    /**
     * Close out the fight, award XP, update stats and log.
     */
    private function finishFight(
        array $session, array $chall, array $def,
        int $challHp, int $defHp, string $result, string $log, int $rounds
    ): array {
        // Calculate XP
        $levelDiff   = max(0, (int)$def['level'] - (int)$chall['level']);
        $challXp     = 0;
        $defXp       = 0;

        switch ($result) {
            case 'challenger_win':
                $challXp = self::XP_WIN + ($levelDiff * self::XP_LEVEL_BONUS);
                $defXp   = self::XP_LOSS;
                $log    .= "\n\n{$chall['username']} is victorious! +"  . $challXp . " XP.";
                break;
            case 'defender_win':
                $defXp   = self::XP_WIN;
                $challXp = self::XP_LOSS;
                $log    .= "\n\n{$def['username']} wins! +" . $defXp . " XP.";
                break;
            case 'draw':
                $challXp = self::XP_DRAW;
                $defXp   = self::XP_DRAW;
                $log    .= "\n\nDraw! Both adventurers earn +" . self::XP_DRAW . " XP.";
                break;
            case 'fled':
                $challXp = self::XP_FLEE;
                $defXp   = self::XP_LOSS; // defender gets minimal XP for opponent fleeing
                $log    .= "\n\n{$chall['username']} fled. No XP earned.";
                break;
        }

        $this->db->beginTransaction();
        try {
            // Award XP
            if ($challXp > 0) {
                $userModel = new User();
                $userModel->awardXp((int)$chall['id'], $challXp);
            }
            if ($defXp > 0) {
                $userModel = new User();
                $userModel->awardXp((int)$def['id'], $defXp);
            }

            // Write pvp_log
            $this->db->run(
                "INSERT INTO pvp_log
                 (challenger_id, defender_id, result, rounds, challenger_xp, defender_xp, combat_log)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$chall['id'], $def['id'], $result, $rounds, $challXp, $defXp, $log]
            );

            // Update pvp_stats for challenger
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

            // Update pvp_stats for defender
            $defStatCol = match($result) {
                'challenger_win' => 'losses',
                'defender_win'   => 'wins',
                'draw'           => 'draws',
                'fled'           => 'wins', // defender wins if challenger flees
            };
            $this->db->run(
                "INSERT INTO pvp_stats (user_id, {$defStatCol}, xp_earned)
                 VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE {$defStatCol} = {$defStatCol} + 1,
                                         xp_earned = xp_earned + ?",
                [$def['id'], $defXp, $defXp]
            );

            // Mark session finished
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
            'finished'       => true,
            'result'         => $result,
            'challenger_hp'  => $challHp,
            'defender_hp'    => $defHp,
            'challenger_xp'  => $challXp,
            'defender_xp'    => $defXp,
            'combat_log'     => $log,
            'rounds'         => $rounds,
            'challenger_name'=> $chall['username'],
            'defender_name'  => $def['username'],
        ];
    }

    // =========================================================================
    // PRIVATE — STAT CALCULATORS
    // =========================================================================

    private function calcMaxHp(array $user): int {
        // Base HP from level (20 + (level-1) * 2)
        $baseHp = User::maxHpForLevel((int)$user['level']);
        // Armor bonus on top
        $store      = new Store();
        $equipped   = $store->getEquipped((int)$user['id']);
        $armorBonus = 0;
        if (isset($equipped['armor'])) {
            if ($equipped['armor']['effect_type'] === 'failure_reduction') {
                $armorBonus = (int)round((1 - (float)$equipped['armor']['effect_value']) * 10);
            }
        }
        return $baseHp + $armorBonus;
    }

    private function calcAttackMod(array $user): int {
        $store    = new Store();
        $equipped = $store->getEquipped((int)$user['id']);
        $weaponBonus = 0;
        if (isset($equipped['weapon'])) {
            if ($equipped['weapon']['effect_type'] === 'xp_boost') {
                $weaponBonus = 2; // weapons give +2 attack
            }
        }
        return (int)floor($user['level'] / 5) + $weaponBonus;
    }

    private function calcDefenseMod(array $user): int {
        $store    = new Store();
        $equipped = $store->getEquipped((int)$user['id']);
        $armorBonus = 0;
        if (isset($equipped['armor'])) {
            $armorBonus = 2; // any armor gives +2 defense
        }
        return (int)floor($user['level'] / 5) + $armorBonus;
    }

    private function calcDamage(array $user): int {
        $store    = new Store();
        $equipped = $store->getEquipped((int)$user['id']);
        $bonus = 0;
        if (isset($equipped['weapon'])) {
            $bonus = (int)$equipped['weapon']['effect_value'];
        }
        return rand(3, 8) + $bonus;
    }

    /**
     * Build a display-friendly stats block for a combatant.
     */
    public function getCombatantStats(array $user): array {
        return [
            'max_hp'      => $this->calcMaxHp($user),
            'attack_mod'  => $this->calcAttackMod($user),
            'defense_mod' => $this->calcDefenseMod($user),
            'damage_range'=> (3 + 0) . '–' . (8 + (function() use ($user) {
                $store    = new Store();
                $equipped = $store->getEquipped((int)$user['id']);
                return isset($equipped['weapon']) ? (int)$equipped['weapon']['effect_value'] : 0;
            })()),
        ];
    }
}
