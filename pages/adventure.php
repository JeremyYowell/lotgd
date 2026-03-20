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
    // DONE — clear session, go to dashboard
    // ------------------------------------------------------------------
    if ($action === 'done') {
        clearAdvSession($userId);
        redirect('/pages/dashboard.php');
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
    }
}

// =========================================================================
// DATA
// =========================================================================
$dailyState  = $userModel->getDailyState($userId);
$actionLimit = (int)$db->getSetting('daily_action_limit', 10);

$recentLog = [];
if ($state === 'idle') {
    $recentLog = $adventure->getRecentLog($userId, 8);
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
$extraCss  = ['adventure.css'];

ob_start();
?>

<div class="adv-wrap">

    <div class="adv-header">
        <div>
            <h1>⚔ Go Adventuring</h1>
            <p class="text-muted">Face the financial challenges of the real world. Fortune favors the prepared.</p>
        </div>
        <div class="adv-actions-badge">
            <span class="adv-actions-label">Actions Today</span>
            <span class="adv-actions-val <?= $dailyState['actions_remaining'] <= 0 ? 'text-red' : 'text-gold' ?>">
                <?= $dailyState['actions_remaining'] ?> / <?= $actionLimit ?>
            </span>
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
    ?>
    <div class="adv-encounter">

        <div class="encounter-category">
            <span><?= $cat['icon'] ?></span>
            <span style="color:<?= $cat['color'] ?>"><?= $cat['label'] ?></span>
        </div>

        <h2 class="encounter-title"><?= e($scenario['title']) ?></h2>

        <?php if ($scenario['flavor_text']): ?>
        <p class="encounter-flavor">"<?= e($scenario['flavor_text']) ?>"</p>
        <?php endif; ?>

        <p class="encounter-desc"><?= nl2br(e($scenario['description'])) ?></p>

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
            <?php foreach ($choices as $choice): ?>
            <form method="POST" class="choice-form">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action"    value="choose">
                <input type="hidden" name="choice_id" value="<?= (int)$choice['id'] ?>">
                <button type="submit" class="choice-btn">
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

        <div class="result-narrative">
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

    <?php endif; ?>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
