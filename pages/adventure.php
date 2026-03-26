<?php
/**
 * pages/adventure.php — Go Adventuring
 *
 * Architecture: Database-backed adventure sessions.
 *
 * State is stored in the adventure_sessions table (one row per user,
 * upserted on each transition). This eliminates all PHP session timing
 * issues — MySQL writes are synchronous and durable.
 *
 * Action consumption rules:
 *  - Action is consumed when the player SUBMITS A CHOICE, not when
 *    the scenario is shown. This means browsing to /adventure.php or
 *    refreshing the scenario screen never costs an action.
 *  - If the player refreshes the result screen, no second action is spent.
 *  - If the player navigates away mid-scenario and comes back, their
 *    scenario is still waiting for them with no action spent yet.
 *
 * State machine:
 *  idle     → (start POST)   → scenario   [no action spent yet]
 *  scenario → (choose POST)  → result     [action spent HERE]
 *  result   → (continue POST)→ scenario   [action spent on next choose]
 *  result   → (done POST)    → idle + dashboard redirect
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userModel = new User();
$adventure = new Adventure();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

// =========================================================================
// LOAD CURRENT DB SESSION
// =========================================================================

/**
 * Get the current adventure session row for this user, or null if none.
 */
function getAdvSession(int $userId): ?array {
    global $db;
    $row = $db->fetchOne(
        "SELECT * FROM adventure_sessions WHERE user_id = ?",
        [$userId]
    );
    return $row ?: null;
}

/**
 * Save a new scenario to the DB session (upsert).
 * Does NOT consume an action.
 */
function saveScenarioSession(int $userId, array $scenario, array $choices): void {
    global $db;
    $db->run(
        "INSERT INTO adventure_sessions
             (user_id, state, scenario_id, choices_json, result_json, action_consumed)
         VALUES (?, 'scenario', ?, ?, NULL, 0)
         ON DUPLICATE KEY UPDATE
             state           = 'scenario',
             scenario_id     = VALUES(scenario_id),
             choices_json    = VALUES(choices_json),
             result_json     = NULL,
             action_consumed = 0,
             updated_at      = NOW()",
        [$userId, $scenario['id'], json_encode($choices)]
    );
}

/**
 * Save the result and mark action as consumed.
 */
function saveResultSession(int $userId, array $result): void {
    global $db;
    $db->run(
        "UPDATE adventure_sessions
         SET state = 'result', result_json = ?, action_consumed = 1, updated_at = NOW()
         WHERE user_id = ?",
        [json_encode($result), $userId]
    );
}

/**
 * Clear the session row entirely (done adventuring).
 */
function clearAdvSession(int $userId): void {
    global $db;
    $db->run("DELETE FROM adventure_sessions WHERE user_id = ?", [$userId]);
}

/**
 * The three Debt Dragon battle strategies (hardcoded — not DB-driven).
 * attack_bonus is added to the player's d20 roll every combat round.
 * Category is 'banking' so Debt Slayers receive their +3 class bonus on top.
 */
function getDragonChoices(): array {
    return [
        [
            'id'           => 1,
            'choice_text'  => 'Invoke the Debt Avalanche — strike the highest-rate debt first',
            'hint_text'    => 'Aggressive. Maximum attack power. High risk, high reward.',
            'attack_bonus' => 3,
            'style_label'  => 'Aggressive',
            'style_color'  => '#ef4444',
        ],
        [
            'id'           => 2,
            'choice_text'  => 'Deploy the Debt Snowball — build momentum with small wins',
            'hint_text'    => 'Balanced. Steady pressure. Reliable and consistent.',
            'attack_bonus' => 1,
            'style_label'  => 'Balanced',
            'style_color'  => '#f59e0b',
        ],
        [
            'id'           => 3,
            'choice_text'  => 'Negotiate a settlement — wear the dragon down diplomatically',
            'hint_text'    => 'Conservative. Lower attack, but sustainable over many rounds.',
            'attack_bonus' => -1,
            'style_label'  => 'Conservative',
            'style_color'  => '#22c55e',
        ],
    ];
}

/**
 * Initialise a Debt Dragon battle session. Rolls initiative and stats only —
 * actual round-by-round combat happens via dragon_attack POST actions.
 */
function initDragonFight(array $user, array $choice, Adventure $adventure): array {
    $level           = (int)$user['level'];
    $playerMaxHp     = User::maxHpForLevel($level);
    $dragonMaxHp     = $playerMaxHp + rand(5, 10);
    $dragonMod       = min(10, (int)floor($level / 4) + 2);
    $playerBaseMod   = $adventure->calculateModifier($level, $user['class'], 'banking');
    $playerAttackMod = $playerBaseMod + (int)$choice['attack_bonus'];
    $playerDefMod    = $playerBaseMod;

    $playerInit  = rand(1, 20) + $playerBaseMod;
    $dragonInit  = rand(1, 20) + $dragonMod;
    $playerFirst = ($playerInit >= $dragonInit);

    $initLine = $playerFirst
        ? "⚔ Initiative: You move first ({$playerInit} vs {$dragonInit})"
        : "🐉 Initiative: The Dragon strikes first ({$dragonInit} vs {$playerInit})";

    return [
        'choice'            => $choice,
        'player_hp'         => $playerMaxHp,
        'player_max_hp'     => $playerMaxHp,
        'dragon_hp'         => $dragonMaxHp,
        'dragon_max_hp'     => $dragonMaxHp,
        'player_attack_mod' => $playerAttackMod,
        'player_def_mod'    => $playerDefMod,
        'dragon_mod'        => $dragonMod,
        'player_first'      => $playerFirst,
        'round'             => 1,
        'log_lines'         => [$initLine],
    ];
}

// =========================================================================
// HANDLE POSTS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();
    $action = $_POST['action'] ?? '';

    // ------------------------------------------------------------------
    // START — pick a scenario, save to DB, show it (no action consumed)
    // ------------------------------------------------------------------
    if ($action === 'start') {
        $dailyState = $userModel->getDailyState($userId);

        if ($dailyState['actions_remaining'] <= 0) {
            Session::setFlash('error', 'No actions remaining today. Return at dawn.');
            redirect('/pages/adventure.php');
        }

        $scenarioRow = $adventure->pickScenario($userId, (int)$user['level']);

        if (!$scenarioRow) {
            Session::setFlash('info', 'No adventures available for your level right now.');
            redirect('/pages/adventure.php');
        }

        $choiceRows = $adventure->getChoices((int)$scenarioRow['id']);
        saveScenarioSession($userId, $scenarioRow, $choiceRows);

        // Redirect to GET so refresh doesn't re-POST
        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // CHOOSE — execute, consume action, save result to DB
    // ------------------------------------------------------------------
    if ($action === 'choose') {
        $choiceId   = (int)($_POST['choice_id'] ?? 0);
        $advSession = getAdvSession($userId);

        if (!$advSession || $advSession['state'] !== 'scenario') {
            Session::setFlash('error', 'No active adventure. Please start a new one.');
            clearAdvSession($userId);
            redirect('/pages/adventure.php');
        }

        // Validate choice belongs to this scenario
        $savedChoices = json_decode($advSession['choices_json'], true) ?? [];
        $validIds     = array_column($savedChoices, 'id');

        if (!$choiceId || !in_array($choiceId, $validIds)) {
            Session::setFlash('error', 'Invalid choice. Please try again.');
            redirect('/pages/adventure.php');
        }

        // Check actions before spending one
        $dailyState = $userModel->getDailyState($userId);
        if ($dailyState['actions_remaining'] <= 0) {
            clearAdvSession($userId);
            Session::setFlash('error', 'No actions remaining today.');
            redirect('/pages/adventure.php');
        }

        // Execute the adventure
        $freshUser  = $userModel->findById($userId);
        $execResult = $adventure->execute($userId, $choiceId, $freshUser);

        if (!$execResult['success']) {
            Session::setFlash('error', $execResult['error'] ?? 'Something went wrong.');
            clearAdvSession($userId);
            redirect('/pages/adventure.php');
        }

        // Consume action and save result atomically
        $userModel->consumeAction($userId);
        saveResultSession($userId, $execResult);

        // Redirect to GET — refresh shows result without re-executing
        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // CONTINUE — pick next scenario (no action consumed yet)
    // ------------------------------------------------------------------
    if ($action === 'continue') {
        $dailyState = $userModel->getDailyState($userId);

        if ($dailyState['actions_remaining'] <= 0) {
            clearAdvSession($userId);
            redirect('/pages/adventure.php');
        }

        $freshUser   = $userModel->findById($userId);
        $scenarioRow = $adventure->pickScenario($userId, (int)$freshUser['level']);

        if (!$scenarioRow) {
            clearAdvSession($userId);
            Session::setFlash('info', 'No new adventures available right now.');
            redirect('/pages/adventure.php');
        }

        $choiceRows = $adventure->getChoices((int)$scenarioRow['id']);
        saveScenarioSession($userId, $scenarioRow, $choiceRows);

        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // DONE — clear session, stay on adventure page (dragon may be waiting)
    // ------------------------------------------------------------------
    if ($action === 'done') {
        clearAdvSession($userId);
        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // DRAGON_START — save dragon challenge to DB session, show choices
    // ------------------------------------------------------------------
    if ($action === 'dragon_start') {
        $freshState = $userModel->getDailyState($userId);
        // Guard: exhausted and not yet used today
        if ($freshState['actions_remaining'] > 0 || !empty($freshState['dragon_challenge_used'])) {
            redirect('/pages/adventure.php');
        }
        $db->run(
            "INSERT INTO adventure_sessions
                 (user_id, state, scenario_id, choices_json, result_json, action_consumed)
             VALUES (?, 'dragon', 0, ?, NULL, 0)
             ON DUPLICATE KEY UPDATE
                 state           = 'dragon',
                 scenario_id     = 0,
                 choices_json    = VALUES(choices_json),
                 result_json     = NULL,
                 action_consumed = 0,
                 updated_at      = NOW()",
            [$userId, json_encode(getDragonChoices())]
        );
        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // DRAGON_CHOOSE — pick strategy, initialise round-by-round fight
    // ------------------------------------------------------------------
    if ($action === 'dragon_choose') {
        $dragonChoiceId = (int)($_POST['dragon_choice_id'] ?? 0);
        $advSession     = getAdvSession($userId);

        if (!$advSession || $advSession['state'] !== 'dragon') {
            clearAdvSession($userId);
            redirect('/pages/adventure.php');
        }

        $allChoices = json_decode($advSession['choices_json'], true) ?? [];
        $choice     = null;
        foreach ($allChoices as $dc) {
            if ((int)$dc['id'] === $dragonChoiceId) { $choice = $dc; break; }
        }
        if (!$choice) {
            redirect('/pages/adventure.php');
        }

        $freshUser  = $userModel->findById($userId);
        $fightState = initDragonFight($freshUser, $choice, $adventure);

        $db->run(
            "UPDATE adventure_sessions
             SET state = 'dragon_fighting', result_json = ?, updated_at = NOW()
             WHERE user_id = ?",
            [json_encode($fightState), $userId]
        );
        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // DRAGON_ATTACK — process one combat round, persist state or finish
    // ------------------------------------------------------------------
    if ($action === 'dragon_attack') {
        $advSession = getAdvSession($userId);

        if (!$advSession || $advSession['state'] !== 'dragon_fighting') {
            clearAdvSession($userId);
            redirect('/pages/adventure.php');
        }

        $fs = json_decode($advSession['result_json'], true);
        if (!$fs) {
            clearAdvSession($userId);
            redirect('/pages/adventure.php');
        }

        $round     = (int)$fs['round'];
        $maxRounds = 10;
        $lines     = [];

        if ($fs['player_first']) {
            // Player attacks
            $pAtk = rand(1, 20) + $fs['player_attack_mod'];
            $dDef = rand(1, 20) + $fs['dragon_mod'];
            if ($pAtk > $dDef) {
                $dmg = rand(2, 5);
                $fs['dragon_hp'] = max(0, $fs['dragon_hp'] - $dmg);
                $lines[] = "Round {$round} — ⚔ You attack ({$pAtk} vs {$dDef}): Hit! {$dmg} dmg → Dragon: {$fs['dragon_hp']}/{$fs['dragon_max_hp']} HP";
            } else {
                $lines[] = "Round {$round} — ⚔ You attack ({$pAtk} vs {$dDef}): Miss!";
            }
            if ($fs['dragon_hp'] > 0) {
                $dAtk = rand(1, 20) + $fs['dragon_mod'];
                $pDef = rand(1, 20) + $fs['player_def_mod'];
                if ($dAtk > $pDef) {
                    $dmg = rand(2, 5);
                    $fs['player_hp'] = max(0, $fs['player_hp'] - $dmg);
                    $lines[] = "Round {$round} — 🐉 Dragon counter-attacks ({$dAtk} vs {$pDef}): Hit! {$dmg} dmg → You: {$fs['player_hp']}/{$fs['player_max_hp']} HP";
                } else {
                    $lines[] = "Round {$round} — 🐉 Dragon counter-attacks ({$dAtk} vs {$pDef}): Miss!";
                }
            }
        } else {
            // Dragon attacks first
            $dAtk = rand(1, 20) + $fs['dragon_mod'];
            $pDef = rand(1, 20) + $fs['player_def_mod'];
            if ($dAtk > $pDef) {
                $dmg = rand(2, 5);
                $fs['player_hp'] = max(0, $fs['player_hp'] - $dmg);
                $lines[] = "Round {$round} — 🐉 Dragon strikes ({$dAtk} vs {$pDef}): Hit! {$dmg} dmg → You: {$fs['player_hp']}/{$fs['player_max_hp']} HP";
            } else {
                $lines[] = "Round {$round} — 🐉 Dragon strikes ({$dAtk} vs {$pDef}): Miss!";
            }
            if ($fs['player_hp'] > 0) {
                $pAtk = rand(1, 20) + $fs['player_attack_mod'];
                $dDef = rand(1, 20) + $fs['dragon_mod'];
                if ($pAtk > $dDef) {
                    $dmg = rand(2, 5);
                    $fs['dragon_hp'] = max(0, $fs['dragon_hp'] - $dmg);
                    $lines[] = "Round {$round} — ⚔ You counter-attack ({$pAtk} vs {$dDef}): Hit! {$dmg} dmg → Dragon: {$fs['dragon_hp']}/{$fs['dragon_max_hp']} HP";
                } else {
                    $lines[] = "Round {$round} — ⚔ You counter-attack ({$pAtk} vs {$dDef}): Miss!";
                }
            }
        }

        array_push($fs['log_lines'], ...$lines);
        $fs['round']++;

        $battleDone = ($fs['dragon_hp'] <= 0 || $fs['player_hp'] <= 0 || $fs['round'] > $maxRounds);

        if (!$battleDone) {
            $db->run(
                "UPDATE adventure_sessions SET result_json = ?, updated_at = NOW() WHERE user_id = ?",
                [json_encode($fs), $userId]
            );
        } else {
            if ($fs['dragon_hp'] <= 0)       { $battleResult = 'win'; }
            elseif ($fs['player_hp'] <= 0)   { $battleResult = 'loss'; }
            else {
                $battleResult = 'draw';
                $fs['log_lines'][] = "═══ 10 rounds — the Dragon retreats into the dungeon. ═══";
            }

            $goldDelta = 0; $actionRestored = false;

            if ($battleResult === 'win') {
                $db->run(
                    "UPDATE daily_state SET actions_remaining = actions_remaining + 1
                     WHERE user_id = ? AND state_date = ?",
                    [$userId, date('Y-m-d')]
                );
                $actionRestored = true;
                $hpPct     = $fs['player_max_hp'] > 0 ? $fs['player_hp'] / $fs['player_max_hp'] : 0;
                $goldDelta = (int)round(15 + $hpPct * 20);
                $db->run("UPDATE users SET gold = gold + ? WHERE id = ?", [$goldDelta, $userId]);
            } elseif ($battleResult === 'loss') {
                $goldDelta = -rand(5, 12);
                $db->run(
                    "UPDATE users SET gold = GREATEST(0, gold + ?) WHERE id = ?",
                    [$goldDelta, $userId]
                );
            }

            $db->run(
                "UPDATE daily_state SET dragon_challenge_used = 1
                 WHERE user_id = ? AND state_date = ?",
                [$userId, date('Y-m-d')]
            );

            $fs['battle_result']   = $battleResult;
            $fs['action_restored'] = $actionRestored;
            $fs['gold_delta']      = $goldDelta;

            $db->run(
                "UPDATE adventure_sessions
                 SET state = 'dragon_result', result_json = ?, updated_at = NOW()
                 WHERE user_id = ?",
                [json_encode($fs), $userId]
            );
        }
        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // REROLL — execute a second-chance re-roll of the last failed adventure
    // ------------------------------------------------------------------
    if ($action === 'reroll') {
        $logId = (int)($_POST['log_id'] ?? 0);

        // Verify log entry: must belong to this user and be a failure
        $logEntry = $db->fetchOne(
            "SELECT al.id, al.choice_id, al.xp_delta, al.gold_delta AS old_gold_delta,
                    al.outcome AS old_outcome,
                    ac.choice_text, ac.hint_text, ac.difficulty,
                    ac.base_xp, ac.base_gold,
                    ac.success_narrative, ac.failure_narrative,
                    ac.crit_success_narrative, ac.crit_failure_narrative,
                    s.title AS scenario_title, s.id AS scenario_id, s.category
             FROM adventure_log al
             JOIN adventure_choices ac ON ac.id = al.choice_id
             JOIN adventure_scenarios s ON s.id = al.scenario_id
             WHERE al.id = ? AND al.user_id = ?
               AND al.outcome IN ('failure','crit_failure')",
            [$logId, $userId]
        );

        // Clear the pending flag regardless — one shot
        Session::set('reroll_pending', null);

        if (!$logEntry) {
            Session::setFlash('error', 'The scroll found nothing to rewind. Your adventure log may have changed.');
            redirect('/pages/adventure.php');
        }

        // Fresh roll (same modifier logic as Adventure::execute)
        $storeObj  = new Store();
        $itemBonus = $storeObj->getRollBonus($userId, $logEntry['category']);
        $modifier  = $adventure->calculateModifier(
            (int)$user['level'], $user['class'], $logEntry['category']
        ) + $itemBonus;
        $rawRoll   = random_int(1, 20);
        $finalRoll = $rawRoll + $modifier;
        $outcome   = $adventure->determineOutcome($finalRoll, (int)$logEntry['difficulty'], $rawRoll);

        // Reverse old gold penalty (if any) then apply new rewards
        $freshUser = $userModel->findById($userId);
        $oldGoldDelta = (int)$logEntry['old_gold_delta'];
        if ($oldGoldDelta < 0) {
            // Restore what was lost on the original failure
            $db->run("UPDATE users SET gold = gold + ? WHERE id = ?", [abs($oldGoldDelta), $userId]);
            $freshUser = $userModel->findById($userId); // refresh after restore
        }

        $rewards = $adventure->calculateRewards(
            $outcome, (int)$logEntry['base_xp'], (int)$logEntry['base_gold'], (int)$freshUser['gold']
        );

        $xpResult = ['leveled_up' => false, 'new_level' => (int)$freshUser['level'], 'gold_awarded' => 0];
        if ($rewards['xp'] > 0) {
            $xpResult = $userModel->awardXp($userId, $rewards['xp']);
        }
        if ($rewards['gold'] > 0) {
            $db->run("UPDATE users SET gold = gold + ? WHERE id = ?", [$rewards['gold'], $userId]);
        } elseif ($rewards['gold'] < 0) {
            $db->run("UPDATE users SET gold = GREATEST(0, gold + ?) WHERE id = ?", [$rewards['gold'], $userId]);
        }

        $narrative = match($outcome) {
            'crit_success' => $logEntry['crit_success_narrative'],
            'success'      => $logEntry['success_narrative'],
            'failure'      => $logEntry['failure_narrative'],
            'crit_failure' => $logEntry['crit_failure_narrative'],
        };

        $rerollResult = [
            'scenario_title' => $logEntry['scenario_title'],
            'choice_text'    => $logEntry['choice_text'],
            'choice_id'      => (int)$logEntry['choice_id'],
            'outcome'        => $outcome,
            'roll'           => $rawRoll,
            'modifier'       => $modifier,
            'final_roll'     => $finalRoll,
            'difficulty'     => (int)$logEntry['difficulty'],
            'narrative'      => $narrative,
            'xp'             => $rewards['xp'],
            'gold'           => $rewards['gold'],
            'leveled_up'     => $xpResult['leveled_up'],
            'new_level'      => $xpResult['new_level'] ?? (int)$freshUser['level'],
            'gold_awarded'   => $xpResult['gold_awarded'] ?? 0,
            'is_reroll'      => true,
        ];

        $db->run(
            "INSERT INTO adventure_sessions
                 (user_id, state, scenario_id, choices_json, result_json, action_consumed)
             VALUES (?, 'result', ?, '[]', ?, 0)
             ON DUPLICATE KEY UPDATE
                 state = 'result',
                 scenario_id = VALUES(scenario_id),
                 choices_json = '[]',
                 result_json = VALUES(result_json),
                 action_consumed = 0,
                 updated_at = NOW()",
            [$userId, (int)$logEntry['scenario_id'], json_encode($rerollResult)]
        );

        redirect('/pages/adventure.php');
    }

    // ------------------------------------------------------------------
    // DRAGON_DISMISS — clear session, return to idle
    // ------------------------------------------------------------------
    if ($action === 'dragon_dismiss') {
        clearAdvSession($userId);
        redirect('/pages/adventure.php');
    }
}

// =========================================================================
// DETERMINE RENDER STATE FROM DB
// =========================================================================
$advSession = getAdvSession($userId);
$state      = 'idle';
$scenario   = null;
$choices    = null;
$result     = null;
$fightState = null;   // dragon_fighting live battle state

if ($advSession) {
    if ($advSession['state'] === 'scenario') {
        // Re-fetch scenario from DB to ensure fresh data
        $scenarioRow = $db->fetchOne(
            "SELECT * FROM adventure_scenarios WHERE id = ? AND is_active = 1",
            [$advSession['scenario_id']]
        );
        $choiceRows = json_decode($advSession['choices_json'], true) ?? [];

        if ($scenarioRow && !empty($choiceRows)) {
            $state    = 'scenario';
            $scenario = $scenarioRow;
            $choices  = $choiceRows;
        } else {
            // Scenario was deactivated or data corrupt — clear and go idle
            clearAdvSession($userId);
        }
    } elseif ($advSession['state'] === 'result' && $advSession['result_json']) {
        $resultData = json_decode($advSession['result_json'], true);
        if ($resultData) {
            $state  = 'result';
            $result = $resultData;
            // Refresh user after XP/gold changes
            $user = $userModel->findById($userId);
        } else {
            clearAdvSession($userId);
        }
    } elseif ($advSession['state'] === 'dragon') {
        $dragonChoiceRows = json_decode($advSession['choices_json'], true) ?? [];
        if (!empty($dragonChoiceRows)) {
            $state   = 'dragon';
            $choices = $dragonChoiceRows;
        } else {
            clearAdvSession($userId);
        }
    } elseif ($advSession['state'] === 'dragon_fighting' && $advSession['result_json']) {
        $fsData = json_decode($advSession['result_json'], true);
        if ($fsData) {
            $state      = 'dragon_fighting';
            $fightState = $fsData;
        } else {
            clearAdvSession($userId);
        }
    } elseif ($advSession['state'] === 'dragon_result' && $advSession['result_json']) {
        $resultData = json_decode($advSession['result_json'], true);
        if ($resultData) {
            $state  = 'dragon_result';
            $result = $resultData;
            $user   = $userModel->findById($userId);
        } else {
            clearAdvSession($userId);
        }
    }
}

// =========================================================================
// DATA
// =========================================================================
$dailyState  = $userModel->getDailyState($userId);
$actionLimit = (int)$db->getSetting('daily_action_limit', 10);
$voiceMode   = (bool)($user['voice_mode'] ?? false);

// Next midnight in server timezone (for action reset countdown)
$tz              = new DateTimeZone('America/Chicago');
$nextMidnight    = new DateTime('tomorrow midnight', $tz);
$nextMidnightTs  = $nextMidnight->getTimestamp();

// Load audio file map for current scenario if in scenario/result state
$audioMap = [];
if ($advSession && in_array($state, ['scenario', 'result'])) {
    $scenId = (int)($scenario['id'] ?? $advSession['scenario_id'] ?? 0);
    if ($scenId) {
        $audioRows = $db->fetchAll(
            "SELECT audio_type, choice_id, file_path
             FROM adventure_audio WHERE scenario_id = ?",
            [$scenId]
        );
        foreach ($audioRows as $ar) {
            $key = $ar['audio_type'] . '_' . ($ar['choice_id'] ?? 'null');
            $audioMap[$key] = BASE_URL . '/' . $ar['file_path'];
        }
    }
}

$recentLog = [];
if ($state === 'idle') {
    $recentLog = $adventure->getRecentLog($userId, 8);
}

$dragonAvailable = ($state === 'idle')
    && ($dailyState['actions_remaining'] <= 0)
    && empty($dailyState['dragon_challenge_used']);

// Second Chance Scroll: check session for a pending reroll
$rerollPending = null;
$rerollPendingId = Session::get('reroll_pending', null);
if ($rerollPendingId && $state === 'idle') {
    $rerollPending = $db->fetchOne(
        "SELECT al.id, s.title AS scenario_title, ac.choice_text
         FROM adventure_log al
         JOIN adventure_choices ac ON ac.id = al.choice_id
         JOIN adventure_scenarios s ON s.id = al.scenario_id
         WHERE al.id = ? AND al.user_id = ?
           AND al.outcome IN ('failure','crit_failure')",
        [(int)$rerollPendingId, $userId]
    );
    if (!$rerollPending) {
        // Log entry no longer valid — clear the stale flag silently
        Session::set('reroll_pending', null);
    }
}

$categoryMeta = [
    'shopping'   => ['icon' => '🛒', 'label' => 'Shopping',   'color' => '#ec4899'],
    'work'       => ['icon' => '💼', 'label' => 'Work',        'color' => '#a78bfa'],
    'banking'    => ['icon' => '🏦', 'label' => 'Banking',     'color' => '#f59e0b'],
    'investing'  => ['icon' => '📈', 'label' => 'Investing',   'color' => '#3b82f6'],
    'housing'    => ['icon' => '🏠', 'label' => 'Housing',     'color' => '#22c55e'],
    'daily_life' => ['icon' => '☀️', 'label' => 'Daily Life',  'color' => '#06b6d4'],
];

$outcomeLabels = [
    'crit_success' => ['label' => 'Critical Success!', 'color' => '#fbbf24', 'icon' => '⚡'],
    'success'      => ['label' => 'Success!',           'color' => '#22c55e', 'icon' => '✔'],
    'failure'      => ['label' => 'Failure',            'color' => '#f97316', 'icon' => '✘'],
    'crit_failure' => ['label' => 'Critical Failure!',  'color' => '#ef4444', 'icon' => '💀'],
];

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Go Adventuring';
$bodyClass = 'page-adventure';
$extraCss  = ['adventure.css', 'voice_toggle.css', 'pvp.css'];

ob_start();
?>

<div class="adv-wrap">

<?php if (!Onboarding::isDismissed($userId, 'onboard_adventure')): ?>
<div class="onboard-tip" data-onboard-flag="onboard_adventure">
    <span class="onboard-tip-icon">🎲</span>
    <div class="onboard-tip-body">
        <strong>How adventures work</strong>
        Each scenario is a d20 roll vs a hidden difficulty (DC). Your result = d20 + ⌊level÷5⌋ + class bonus.
        A natural 20 is always a critical success. You have <strong>10 adventures per day</strong> — they reset at midnight.
        Your class gives <strong>+3</strong> to rolls in its specialty category.
    </div>
    <button class="onboard-tip-dismiss" aria-label="Dismiss">✕</button>
</div>
<?php endif; ?>

    <div class="adv-header">
        <div>
            <h1>⚔ Go Adventuring</h1>
            <p class="text-muted">Face the financial challenges of the real world. Fortune favors the prepared.</p>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.5rem">
            <div class="adv-actions-badge">
                <span class="adv-actions-label">Actions Today</span>
                <span class="adv-actions-val <?= $dailyState['actions_remaining'] <= 0 ? 'text-red' : 'text-gold' ?>">
                    <?= $dailyState['actions_remaining'] ?> / <?= $actionLimit ?>
                </span>
            </div>
            <label class="voice-toggle-label" id="voice-toggle-wrap">
                <span class="voice-toggle-text">
                    🔊 Voice ·
                    <a href="https://elevenlabs.io" target="_blank" rel="noopener"
                       class="voice-powered-link"
                       onclick="event.stopPropagation()">
                        Powered by ElevenLabs ↗
                    </a>
                </span>
                <button type="button" id="voice-mode-btn"
                        class="voice-pill <?= $voiceMode ? 'voice-pill-on' : '' ?>"
                        onclick="toggleVoiceMode(this)"
                        aria-pressed="<?= $voiceMode ? 'true' : 'false' ?>">
                    <span class="voice-pill-knob"></span>
                </button>
            </label>
            <button type="button" id="voice-stop-btn"
                    onclick="stopAudio()"
                    style="display:none"
                    class="voice-stop-btn" title="Stop narration">
                ⏹ Stop
            </button>
        </div>
    </div>

    <?= renderFlash() ?>

    <?php if ($state === 'idle'): ?>
    <!-- =====================================================
         IDLE — ready to adventure or exhausted
    ===================================================== -->
    <div class="adv-layout">
        <div class="adv-main">
            <div class="card adv-ready-card <?= $dailyState['actions_remaining'] <= 0 ? 'adv-exhausted' : '' ?>">
                <?php if ($dailyState['actions_remaining'] <= 0): ?>
                    <div class="adv-rest-icon">🌙</div>
                    <h2 class="text-muted">You are weary, adventurer.</h2>
                    <p class="text-muted">You have spent all your actions for today. The tavern awaits. Return at dawn.</p>
                    <p class="text-muted" style="margin-top:0.75rem;font-size:0.9rem">
                        Actions reset in <strong id="adv-countdown" class="text-gold" style="font-family:var(--font-heading);letter-spacing:0.05em">--:--:--</strong>
                    </p>

                    <?php if ($dragonAvailable): ?>
                    <div class="dragon-challenge-card">
                        <div class="dragon-icon">🐉</div>
                        <h3 class="dragon-title">The Debt Dragon Stirs</h3>
                        <p class="dragon-desc">
                            Deep in the dungeon below the tavern, something ancient stirs. The Debt Dragon has awoken —
                            and it is hungry. One bold adventurer may challenge it before dawn.
                            Slay it and earn one more adventure today.
                        </p>
                        <p class="dragon-hint">One attempt per day. No actions required to enter.</p>
                        <form method="POST" style="margin-top:1rem">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="dragon_start">
                            <button type="submit" class="btn dragon-btn">
                                🐉 Descend into the Dungeon
                            </button>
                        </form>
                    </div>
                    <?php elseif (!empty($dailyState['dragon_challenge_used'])): ?>
                    <div class="dragon-challenge-card dragon-used">
                        <div class="dragon-icon" style="opacity:0.4">🐉</div>
                        <p class="text-muted" style="font-size:0.88rem;margin:0">
                            The Debt Dragon has been faced today. It will return tomorrow.
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php if ($rerollPending): ?>
                    <div class="scroll-chance-card">
                        <div class="scroll-icon">📜</div>
                        <div class="scroll-title">Second Chance Scroll</div>
                        <div class="scroll-desc">
                            A mystical scroll shimmers in your satchel. You may replay your last defeat:
                            <em><?= e($rerollPending['scenario_title']) ?></em>
                            — <em><?= e($rerollPending['choice_text']) ?></em>
                        </div>
                        <form method="POST" class="mt-2">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action"  value="reroll">
                            <input type="hidden" name="log_id"  value="<?= (int)$rerollPending['id'] ?>">
                            <button type="submit" class="btn scroll-btn">
                                📜 Unroll the Second Chance
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="adv-ready-icon">⚔️</div>
                    <h2>Ready for Adventure?</h2>
                    <p class="text-muted">
                        You will be presented with a real-world financial challenge.
                        Choose your approach wisely — your level and class affect your odds.
                    </p>
                    <div class="adv-class-bonus">
                        <?php
                        $bonusCats = Adventure::getBonusCategories($user['class']);
                        if (!empty($bonusCats)):
                            $catLabels = array_map(fn($c) => $categoryMeta[$c]['label'] ?? $c, $bonusCats);
                        ?>
                        <span class="class-bonus-tag">
                            Your class gets +3 on:
                            <strong class="text-gold"><?= implode(', ', $catLabels) ?></strong> encounters
                        </span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="mt-3">
                        <?= Session::csrfField() ?>
                        <input type="hidden" name="action" value="start">
                        <button type="submit" class="btn btn-primary btn-full adv-go-btn">
                            ⚔ Go Adventuring
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="adv-sidebar">
            <?php if (!empty($recentLog)): ?>
            <div class="card adv-log-card">
                <h3 class="mb-3">📜 Recent Adventures</h3>
                <div class="adv-log-list">
                    <?php foreach ($recentLog as $entry):
                        $ol      = $outcomeLabels[$entry['outcome']] ?? $outcomeLabels['failure'];
                        $catIcon = $categoryMeta[$entry['category'] ?? 'daily_life']['icon'] ?? '⚔';
                    ?>
                    <div class="adv-log-row">
                        <span class="adv-log-icon"><?= $catIcon ?></span>
                        <div class="adv-log-body">
                            <span class="adv-log-title"><?= e($entry['scenario_title']) ?></span>
                            <span class="adv-log-outcome" style="color:<?= $ol['color'] ?>">
                                <?= $ol['icon'] ?> <?= $ol['label'] ?>
                            </span>
                            <span class="adv-log-meta text-muted">
                                Roll <?= $entry['final_roll'] ?> vs DC <?= $entry['difficulty'] ?>
                                · <?= $entry['xp_delta'] > 0 ? '+' . $entry['xp_delta'] . ' XP' : '0 XP' ?>
                                · <?= $entry['gold_delta'] >= 0 ? '+' . $entry['gold_delta'] : $entry['gold_delta'] ?> 🪙
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($state === 'scenario' && $scenario && $choices): ?>
    <!-- =====================================================
         SCENARIO — show encounter + choices
         Action has NOT been consumed yet at this point.
    ===================================================== -->
    <?php
    $storeObj  = new Store();
    $itemBonus = $storeObj->getRollBonus($userId, $scenario['category']);
    $totalMod  = $adventure->calculateModifier(
        (int)$user['level'], $user['class'], $scenario['category'], $itemBonus
    );
    $cat = $categoryMeta[$scenario['category']] ?? $categoryMeta['daily_life'];

    // Check for scenario illustration (gracefully absent — no image = nothing shown)
    $scenarioImgFile = ROOT_PATH . '/assets/img/adventures/scenario_' . (int)$scenario['id'] . '.jpg';
    $scenarioImgUrl  = file_exists($scenarioImgFile)
        ? BASE_URL . '/assets/img/adventures/scenario_' . (int)$scenario['id'] . '.jpg'
        : null;
    ?>
    <div class="adv-encounter">

        <div class="encounter-category">
            <span><?= $cat['icon'] ?></span>
            <span style="color:<?= $cat['color'] ?>"><?= $cat['label'] ?></span>
        </div>

        <h2 class="encounter-title"><?= e($scenario['title']) ?></h2>

        <?php if ($scenarioImgUrl): ?>
        <div class="encounter-img-wrap">
            <img src="<?= e($scenarioImgUrl) ?>"
                 alt="<?= e($scenario['title']) ?>"
                 class="encounter-img"
                 loading="lazy"
                 onerror="this.parentElement.style.display='none'">
        </div>
        <?php endif; ?>

        <?php if ($scenario['flavor_text']): ?>
        <p class="encounter-flavor">"<?= e($scenario['flavor_text']) ?>"</p>
        <?php endif; ?>

        <p class="encounter-desc" id="adv-scene-desc"
           data-audio="<?= e($audioMap['title_desc_null'] ?? '') ?>"><?= nl2br(e($scenario['description'])) ?></p>

        <div class="encounter-modifier-hint">
            Your modifier: <strong class="text-gold">+<?= $totalMod ?></strong>
            (Level <?= $user['level'] ?>
            <?php if ($itemBonus > 0): ?>
                + class + <span style="color:#fbbf24">+<?= $itemBonus ?> gear</span>
            <?php else: ?>
                + class bonus
            <?php endif; ?>)
        </div>

        <div class="encounter-choices">
            <?php foreach ($choices as $choiceIdx => $choice):
                $choiceAudioKey = 'choice_text_' . $choice['id'];
            ?>
            <form method="POST" class="choice-form">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action"    value="choose">
                <input type="hidden" name="choice_id" value="<?= (int)$choice['id'] ?>">
                <button type="submit" class="choice-btn"
                        data-audio="<?= e($audioMap[$choiceAudioKey] ?? '') ?>">
                    <span class="choice-text"><?= e($choice['choice_text']) ?></span>
                    <?php if (!empty($choice['hint_text'])): ?>
                    <span class="choice-hint"><?= e($choice['hint_text']) ?></span>
                    <?php endif; ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>

        <p class="text-muted" style="font-size:0.78rem;margin-top:1.5rem;text-align:center">
            ⚠ Choosing an option will use one of your daily actions
            (<?= $dailyState['actions_remaining'] ?> remaining)
        </p>

    </div>

    <?php elseif ($state === 'result' && $result): ?>
    <!-- =====================================================
         RESULT — outcome, roll, narrative, rewards
    ===================================================== -->
    <?php $ol = $outcomeLabels[$result['outcome']] ?? $outcomeLabels['failure']; ?>
    <div class="adv-result <?= $result['outcome'] ?>-result">

        <?php if (!empty($result['is_reroll'])): ?>
        <div class="reroll-badge">📜 Second Chance Scroll — Replay</div>
        <?php endif; ?>

        <div class="result-outcome-badge" style="color:<?= $ol['color'] ?>;border-color:<?= $ol['color'] ?>">
            <?= $ol['icon'] ?> <?= $ol['label'] ?>
        </div>

        <h2 class="result-scenario-title"><?= e($result['scenario_title']) ?></h2>
        <p class="result-choice-echo text-muted">You chose: <em><?= e($result['choice_text']) ?></em></p>

        <div class="result-roll-display">
            <div class="roll-die">
                <span class="roll-number"><?= $result['final_roll'] ?></span>
                <span class="roll-label">Final Roll</span>
            </div>
            <div class="roll-breakdown">
                <span>d20: <strong><?= $result['roll'] ?></strong></span>
                <span>+ modifier: <strong><?= $result['modifier'] >= 0 ? '+' . $result['modifier'] : $result['modifier'] ?></strong></span>
                <span>vs DC: <strong><?= $result['difficulty'] ?></strong></span>
            </div>
        </div>

        <?php
        // Build audio key for this outcome
        $outcomeTypeMap = ['crit_success'=>'crit_success','success'=>'success',
                           'failure'=>'failure','crit_failure'=>'crit_failure'];
        $resultAudioType = $outcomeTypeMap[$result['outcome']] ?? '';
        $resultChoiceId  = $result['choice_id'] ?? null;
        $resultAudioKey  = $resultAudioType . '_' . ($resultChoiceId ?? 'null');
        $resultAudioUrl  = $audioMap[$resultAudioKey] ?? '';
        ?>
        <div class="result-narrative" id="adv-result-narrative"
             data-audio="<?= e($resultAudioUrl) ?>">
            <?= nl2br(e($result['narrative'])) ?>
        </div>

        <div class="result-rewards">
            <?php if ($result['xp'] > 0): ?>
                <span class="reward-badge xp-badge">+<?= num($result['xp']) ?> XP</span>
            <?php else: ?>
                <span class="reward-badge" style="color:#6b82a0;border-color:#2a3a55">0 XP</span>
            <?php endif; ?>

            <?php if ($result['gold'] > 0): ?>
                <span class="reward-badge" style="color:#f0d980;border-color:#8a6a1a;background:rgba(212,160,23,0.1)">
                    +<?= $result['gold'] ?> 🪙 Gold
                </span>
            <?php elseif ($result['gold'] < 0): ?>
                <span class="reward-badge" style="color:#fca5a5;border-color:#7f1d1d;background:rgba(239,68,68,0.1)">
                    <?= $result['gold'] ?> 🪙 Gold
                </span>
            <?php endif; ?>
        </div>

        <?php if ($result['leveled_up'] ?? false): ?>
        <!-- LEVEL UP BANNER -->
        <div class="levelup-banner">
            <div class="levelup-burst">🎉</div>
            <div class="levelup-title">Level Up!</div>
            <div class="levelup-level">Level <?= $result['new_level'] ?></div>
            <div class="levelup-rewards">
                <?php if (($result['gold_awarded'] ?? 0) > 0): ?>
                <span class="levelup-reward-pill">
                    +<?= num($result['gold_awarded']) ?> 🪙 Gold Reward
                </span>
                <?php endif; ?>
                <span class="levelup-reward-pill" style="color:#c8d8e8;border-color:#2a3a55">
                    Max HP → <?= User::maxHpForLevel($result['new_level']) ?>
                </span>
            </div>
            <p class="levelup-desc">
                Your adventures in the realm have made you stronger.
                New challenges await at Level <?= $result['new_level'] ?>.
            </p>
        </div>
        <?php endif; ?>

        <div class="result-actions mt-3">
            <?php if ($dailyState['actions_remaining'] > 0): ?>
                <form method="POST" style="display:inline">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="continue">
                    <button type="submit" class="btn btn-primary">
                        ⚔ Next Adventure
                        <span style="font-size:0.78rem;opacity:0.8;margin-left:0.35rem">
                            (<?= $dailyState['actions_remaining'] ?> remaining)
                        </span>
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" style="display:inline">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="done">
                    <button type="submit" class="btn btn-secondary">🌙 Rest for the Night</button>
                </form>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pages/dashboard.php"
               class="btn btn-secondary" style="margin-left:0.5rem">
                Dashboard
            </a>
        </div>

    </div>

    <?php elseif ($state === 'dragon_fighting' && $fightState): ?>
    <!-- =====================================================
         DRAGON_FIGHTING — round-by-round combat (PvP style)
    ===================================================== -->
    <?php
    $playerHpPct  = max(0, min(100, $fightState['player_max_hp'] > 0
        ? round($fightState['player_hp'] / $fightState['player_max_hp'] * 100) : 0));
    $dragonHpPct  = max(0, min(100, $fightState['dragon_max_hp'] > 0
        ? round($fightState['dragon_hp'] / $fightState['dragon_max_hp'] * 100) : 0));
    $playerHpColor = $playerHpPct > 50 ? '#22c55e' : ($playerHpPct > 25 ? '#f59e0b' : '#ef4444');
    $dragonHpColor = $dragonHpPct > 50 ? '#22c55e' : ($dragonHpPct > 25 ? '#f59e0b' : '#ef4444');
    $choice        = $fightState['choice'] ?? [];
    $bonusSign     = ($choice['attack_bonus'] ?? 0) >= 0 ? '+' : '';
    ?>
    <div class="pvp-arena dragon-arena">

        <div class="pvp-combatants">

            <div class="combatant-card you">
                <div class="combatant-icon"><?= ['investor'=>'📈','debt_slayer'=>'🗡️','saver'=>'🏦','entrepreneur'=>'🚀','minimalist'=>'🧘'][$user['class']] ?? '⚔️' ?></div>
                <div class="combatant-name"><?= e($user['username']) ?> <span class="text-muted">(you)</span></div>
                <div class="combatant-level text-muted">Level <?= $user['level'] ?></div>
                <div class="combatant-hp-wrap">
                    <div class="hp-meter-label">
                        <span style="color:<?= $playerHpColor ?>">
                            <?= $fightState['player_hp'] ?> / <?= $fightState['player_max_hp'] ?> HP
                        </span>
                        <span class="text-muted" style="font-size:0.7rem"><?= $playerHpPct ?>%</span>
                    </div>
                    <div class="hp-meter-track">
                        <div class="hp-meter-fill" style="width:<?= $playerHpPct ?>%;background:<?= $playerHpColor ?>"></div>
                    </div>
                    <?php if ($playerHpPct <= 25): ?>
                    <div class="hp-meter-danger">⚠ Critical HP!</div>
                    <?php endif; ?>
                </div>
                <div class="combatant-stats text-muted">
                    ⚔ +<?= $fightState['player_attack_mod'] ?> atk (<?= e($choice['style_label'] ?? '') ?>)
                    &nbsp;·&nbsp;
                    🛡 +<?= $fightState['player_def_mod'] ?> def
                </div>
            </div>

            <div class="pvp-vs">VS</div>

            <div class="combatant-card enemy">
                <div class="combatant-icon">🐉</div>
                <div class="combatant-name" style="color:#fca5a5">The Debt Dragon</div>
                <div class="combatant-level text-muted">Ancient Boss</div>
                <div class="combatant-hp-wrap">
                    <div class="hp-meter-label">
                        <span style="color:<?= $dragonHpColor ?>">
                            <?= $fightState['dragon_hp'] ?> / <?= $fightState['dragon_max_hp'] ?> HP
                        </span>
                        <span class="text-muted" style="font-size:0.7rem"><?= $dragonHpPct ?>%</span>
                    </div>
                    <div class="hp-meter-track">
                        <div class="hp-meter-fill" style="width:<?= $dragonHpPct ?>%;background:<?= $dragonHpColor ?>"></div>
                    </div>
                    <?php if ($dragonHpPct <= 25): ?>
                    <div class="hp-meter-danger">⚠ Nearly slain!</div>
                    <?php endif; ?>
                </div>
                <div class="combatant-stats text-muted">
                    ⚔ +<?= $fightState['dragon_mod'] ?> atk
                    &nbsp;·&nbsp;
                    🛡 +<?= $fightState['dragon_mod'] ?> def
                </div>
            </div>

        </div><!-- /combatants -->

        <div class="pvp-round-indicator">
            Round <?= $fightState['round'] ?> / 10
        </div>

        <?php if (!empty($fightState['log_lines'])): ?>
        <div class="pvp-log card">
            <h4 class="pvp-log-title">🐉 Combat Log</h4>
            <div class="pvp-log-body">
                <?php foreach (array_reverse($fightState['log_lines']) as $line):
                    $line = trim($line);
                    if (empty($line)) continue;
                    $lc = str_contains($line, 'Initiative') ? 'log-init'
                        : (str_contains($line, 'Hit!')  ? 'log-hit'
                        : (str_contains($line, 'Miss!') ? 'log-miss' : ''));
                ?>
                <div class="log-line <?= $lc ?>"><?= e($line) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="pvp-actions">
            <form method="POST">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action" value="dragon_attack">
                <button type="submit" class="btn btn-primary pvp-attack-btn">
                    ⚔ Attack
                    <span style="font-size:0.75rem;opacity:0.8;margin-left:0.35rem">
                        d20 + <?= $fightState['player_attack_mod'] ?>
                    </span>
                </button>
            </form>
        </div>

    </div><!-- /dragon-arena -->

    <?php elseif ($state === 'dragon' && $choices): ?>
    <!-- =====================================================
         DRAGON — battle strategy selection
    ===================================================== -->
    <?php
    $baseMod = $adventure->calculateModifier((int)$user['level'], $user['class'], 'banking');
    $playerMaxHp = User::maxHpForLevel((int)$user['level']);
    ?>
    <div class="adv-encounter dragon-encounter">

        <div class="encounter-category">
            <span>🐉</span>
            <span style="color:#ef4444">Debt Dragon — Boss Battle</span>
            <span style="color:var(--color-text-dim);font-size:0.65rem;margin-left:0.5rem">— Once Per Day</span>
        </div>

        <h2 class="encounter-title" style="color:#fca5a5">The Debt Dragon</h2>

        <p class="encounter-flavor">"Some debts are not merely financial. They are monsters."</p>

        <div class="dragon-battle-preview">
            <div class="battle-preview-side">
                <div class="battle-preview-label">You</div>
                <div class="battle-preview-hp"><?= $playerMaxHp ?> HP</div>
                <div class="battle-preview-mod">+<?= $baseMod ?> modifier
                    <?= $user['class'] === 'debt_slayer' ? '<span style="color:#ef4444">(+3 Debt Slayer)</span>' : '' ?>
                </div>
            </div>
            <div class="battle-preview-vs">⚔</div>
            <div class="battle-preview-side">
                <div class="battle-preview-label">🐉 Debt Dragon</div>
                <div class="battle-preview-hp"><?= $playerMaxHp + 5 ?>–<?= $playerMaxHp + 10 ?> HP</div>
                <div class="battle-preview-mod">+<?= min(10, (int)floor((int)$user['level'] / 4) + 2) ?> modifier</div>
            </div>
        </div>

        <p class="encounter-desc" style="margin:1.25rem 0 1.5rem">
            The dungeon reeks of compound interest. The Debt Dragon blocks the far door,
            scales glinting with accumulated fees and unpaid balances. Up to 10 rounds of
            combat await. Choose your battle strategy — it determines your attack bonus each round.
            Win to earn back an action. Lose and pay a small gold penalty.
        </p>

        <div class="encounter-choices">
            <?php foreach ($choices as $choice):
                $bonusSign = $choice['attack_bonus'] >= 0 ? '+' : '';
            ?>
            <form method="POST" class="choice-form">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action"           value="dragon_choose">
                <input type="hidden" name="dragon_choice_id" value="<?= (int)$choice['id'] ?>">
                <button type="submit" class="choice-btn dragon-choice-btn">
                    <span class="dragon-strategy-row">
                        <span class="dragon-style-badge" style="color:<?= e($choice['style_color']) ?>;border-color:<?= e($choice['style_color']) ?>">
                            <?= e($choice['style_label']) ?>
                        </span>
                        <span class="dragon-atk-mod" style="color:<?= e($choice['style_color']) ?>">
                            <?= $bonusSign . $choice['attack_bonus'] ?> ATK
                        </span>
                    </span>
                    <span class="choice-text"><?= e($choice['choice_text']) ?></span>
                    <span class="choice-hint"><?= e($choice['hint_text']) ?></span>
                </button>
            </form>
            <?php endforeach; ?>
        </div>

        <p class="text-muted" style="font-size:0.78rem;margin-top:1.5rem;text-align:center">
            ⚠ This is your one dragon battle for today. Choose wisely.
        </p>

    </div>

    <?php elseif ($state === 'dragon_result' && $result): ?>
    <!-- =====================================================
         DRAGON RESULT — PvP-style combat log
    ===================================================== -->
    <?php
    $battleResult = $result['battle_result'] ?? 'draw';
    $dragonOutcomeConfig = [
        'win'  => ['label' => 'Victory!',    'color' => '#fbbf24', 'icon' => '⚡', 'border' => '#8a6a1a'],
        'loss' => ['label' => 'Defeated!',   'color' => '#ef4444', 'icon' => '💀', 'border' => '#7f1d1d'],
        'draw' => ['label' => 'Standoff!',   'color' => '#a78bfa', 'icon' => '🤝', 'border' => '#4c3a8a'],
    ];
    $drOc   = $dragonOutcomeConfig[$battleResult] ?? $dragonOutcomeConfig['draw'];
    $choice = $result['choice'] ?? [];
    $roundsPlayed = max(0, ($result['round'] ?? 1) - 1);
    ?>
    <div class="adv-result dragon-result dragon-battle-result">

        <div class="result-outcome-badge" style="color:<?= $drOc['color'] ?>;border-color:<?= $drOc['border'] ?>">
            🐉 <?= $drOc['icon'] ?> <?= $drOc['label'] ?>
        </div>

        <h2 class="result-scenario-title">The Debt Dragon</h2>
        <p class="result-choice-echo text-muted">
            Strategy: <em><?= e($choice['style_label'] ?? $choice['choice_text'] ?? '') ?></em>
            · <?= $roundsPlayed ?> round<?= $roundsPlayed !== 1 ? 's' : '' ?>
        </p>

        <!-- Final HP bars -->
        <div class="dragon-hp-bars">
            <?php
            $rPlayerHp    = max(0, (int)($result['player_hp'] ?? $result['player_hp_end'] ?? 0));
            $rPlayerMaxHp = max(1, (int)($result['player_max_hp'] ?? 1));
            $rDragonHp    = max(0, (int)($result['dragon_hp'] ?? $result['dragon_hp_end'] ?? 0));
            $rDragonMaxHp = max(1, (int)($result['dragon_max_hp'] ?? 1));
            ?>
            <div class="dragon-hp-row">
                <span class="dragon-hp-label">You</span>
                <div class="dragon-hp-track">
                    <div class="dragon-hp-fill player-hp-fill"
                         style="width:<?= round($rPlayerHp / $rPlayerMaxHp * 100) ?>%"></div>
                </div>
                <span class="dragon-hp-val"><?= $rPlayerHp ?> / <?= $rPlayerMaxHp ?></span>
            </div>
            <div class="dragon-hp-row">
                <span class="dragon-hp-label">🐉 Dragon</span>
                <div class="dragon-hp-track">
                    <div class="dragon-hp-fill dragon-hp-fill-bar"
                         style="width:<?= round($rDragonHp / $rDragonMaxHp * 100) ?>%"></div>
                </div>
                <span class="dragon-hp-val"><?= $rDragonHp ?> / <?= $rDragonMaxHp ?></span>
            </div>
        </div>

        <!-- Full combat log (same format as PvP) -->
        <?php $logLines = $result['log_lines'] ?? []; ?>
        <?php if (!empty($logLines)): ?>
        <div class="pvp-log card" style="margin-top:1.25rem">
            <h4 class="pvp-log-title">🐉 Full Combat Log</h4>
            <div class="pvp-log-body">
                <?php foreach ($logLines as $line):
                    $line = trim($line);
                    if (empty($line)) continue;
                    $lc = str_contains($line, '═══') ? 'log-defeat'
                        : (str_contains($line, 'Initiative') ? 'log-init'
                        : (str_contains($line, 'Hit!')  ? 'log-hit'
                        : (str_contains($line, 'Miss!') ? 'log-miss' : '')));
                ?>
                <div class="log-line <?= $lc ?>"><?= e($line) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="result-rewards" style="margin-top:1.25rem">
            <?php if ($result['action_restored']): ?>
                <span class="reward-badge" style="color:#fbbf24;border-color:#8a6a1a;background:rgba(212,160,23,0.12)">
                    +1 ⚔ Action Restored
                </span>
            <?php else: ?>
                <span class="reward-badge" style="color:#6b82a0;border-color:#2a3a55">No action earned</span>
            <?php endif; ?>

            <?php if ($result['gold_delta'] > 0): ?>
                <span class="reward-badge" style="color:#f0d980;border-color:#8a6a1a;background:rgba(212,160,23,0.1)">
                    +<?= $result['gold_delta'] ?> 🪙 Gold
                </span>
            <?php elseif ($result['gold_delta'] < 0): ?>
                <span class="reward-badge" style="color:#fca5a5;border-color:#7f1d1d;background:rgba(239,68,68,0.1)">
                    <?= $result['gold_delta'] ?> 🪙 Gold lost
                </span>
            <?php endif; ?>
        </div>

        <div class="result-actions mt-3">
            <?php if ($result['action_restored']): ?>
                <form method="POST" style="display:inline">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="dragon_dismiss">
                    <button type="submit" class="btn btn-primary">
                        ⚔ Use My Restored Action
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" style="display:inline">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="dragon_dismiss">
                    <button type="submit" class="btn btn-secondary">🌙 Return to the Tavern</button>
                </form>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pages/dashboard.php"
               class="btn btn-secondary" style="margin-left:0.5rem">
                Dashboard
            </a>
        </div>

    </div>

    <?php endif; ?>

</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '<script>
var VOICE_MODE     = ' . ($voiceMode ? 'true' : 'false') . ';
var CSRF_TOKEN     = ' . json_encode($_SESSION['csrf_token'] ?? '') . ';
var VOICE_MODE_URL = ' . json_encode(BASE_URL . '/api/voice_mode.php') . ';
var currentAudio   = null; // track playing audio so we can stop it

// ── Toggle pill ────────────────────────────────────────────────────────────
function toggleVoiceMode(btn) {
    VOICE_MODE = !VOICE_MODE;
    btn.classList.toggle("voice-pill-on", VOICE_MODE);
    btn.setAttribute("aria-pressed", VOICE_MODE ? "true" : "false");
    if (!VOICE_MODE) {
        stopAudio();
    } else {
        // Trigger playback immediately when turned on
        var desc = document.getElementById("adv-scene-desc");
        var narr = document.getElementById("adv-result-narrative");
        if (narr) {
            playAudio(narr.getAttribute("data-audio"), null);
        } else if (desc) {
            var titleUrl = desc.getAttribute("data-audio");
            var choiceUrls = Array.from(document.querySelectorAll(".choice-btn[data-audio]"))
                               .map(function(b){ return b.getAttribute("data-audio"); })
                               .filter(function(u){ return u; });
            var step = 0;
            function playNext() {
                if (step < choiceUrls.length) { playAudio(choiceUrls[step], playNext); step++; }
            }
            if (titleUrl) { playAudio(titleUrl, playNext); } else { playNext(); }
        }
    }
    var form = new FormData();
    form.append("enabled", VOICE_MODE ? "1" : "0");
    form.append("csrf_token", CSRF_TOKEN);
    fetch(VOICE_MODE_URL, {method:"POST", body:form}).catch(function(){});
}

// ── Stop current audio ─────────────────────────────────────────────────────
function stopAudio() {
    if (currentAudio) {
        currentAudio.pause();
        currentAudio.currentTime = 0;
        currentAudio = null;
    }
    var stopBtn = document.getElementById("voice-stop-btn");
    if (stopBtn) stopBtn.style.display = "none";
}

// ── Play an audio URL ──────────────────────────────────────────────────────
function playAudio(url, onEnd) {
    if (!VOICE_MODE || !url) { if (onEnd) onEnd(); return null; }
    stopAudio(); // stop anything already playing
    var a = new Audio(url);
    currentAudio = a;
    var stopBtn = document.getElementById("voice-stop-btn");
    if (stopBtn) stopBtn.style.display = "";
    a.addEventListener("ended", function() {
        currentAudio = null;
        if (stopBtn) stopBtn.style.display = "none";
        if (onEnd) onEnd();
    });
    a.addEventListener("error", function() {
        currentAudio = null;
        if (stopBtn) stopBtn.style.display = "none";
        if (onEnd) onEnd();
    });
    a.play().catch(function() {
        currentAudio = null;
        if (stopBtn) stopBtn.style.display = "none";
        if (onEnd) onEnd();
    });
    return a;
}

// ── Midnight countdown (shown when out of actions) ─────────────────────────
(function() {
    var el = document.getElementById("adv-countdown");
    if (!el) return;
    var nextMidnight = ' . $nextMidnightTs . ';
    function tick() {
        var diff = nextMidnight - Math.floor(Date.now() / 1000);
        if (diff <= 0) { el.textContent = "00:00:00"; return; }
        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        el.textContent =
            String(h).padStart(2, "0") + ":" +
            String(m).padStart(2, "0") + ":" +
            String(s).padStart(2, "0");
    }
    tick();
    setInterval(tick, 1000);
})();

// ── On scenario screen: auto-play title/desc, then choices sequentially ────
document.addEventListener("DOMContentLoaded", function() {
    var desc = document.getElementById("adv-scene-desc");
    if (desc) {
        var titleUrl = desc.getAttribute("data-audio");
        var choices  = Array.from(document.querySelectorAll(".choice-btn[data-audio]"))
                           .map(function(b){ return b.getAttribute("data-audio"); })
                           .filter(function(u){ return u; });
        var step = 0;
        function playNext() {
            if (step < choices.length) {
                playAudio(choices[step], playNext);
                step++;
            }
        }
        if (titleUrl) {
            playAudio(titleUrl, playNext);
        } else {
            playNext();
        }
    }

    // ── On result screen: auto-play outcome narrative ──────────────────────
    var narr = document.getElementById("adv-result-narrative");
    if (narr) {
        var narrativeUrl = narr.getAttribute("data-audio");
        playAudio(narrativeUrl, null);
    }
});
</script>';

require TPL_PATH . '/layout.php';
?>
