<?php
/**
 * pages/adventure.php — Go Adventuring
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userModel = new User();
$adventure = new Adventure();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

// =========================================================================
// STATE MACHINE
// States: 'idle' | 'scenario' | 'result'
// Stored in session to survive the POST-redirect-GET cycle.
// =========================================================================
$state = Session::get('adv_state', 'idle');

// Result is stored in session after a POST so we can display it after redirect
$result   = Session::get('adv_result',   null);
$scenario = Session::get('adv_scenario', null);
$choices  = Session::get('adv_choices',  null);

// =========================================================================
// HANDLE POSTS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();
    $action = $_POST['action'] ?? '';

    // --- START: spend an action, pick a scenario ---
    if ($action === 'start') {
        $dailyState = $userModel->getDailyState($userId);

        if ($dailyState['actions_remaining'] <= 0) {
            Session::setFlash('error', 'You have no actions remaining today. Return at dawn.');
            redirect('/pages/adventure.php');
        }

        $scenarioRow = $adventure->pickScenario($userId, (int)$user['level']);

        if (!$scenarioRow) {
            Session::setFlash('error', 'No adventures are available for your level right now.');
            redirect('/pages/adventure.php');
        }

        $choiceRows = $adventure->getChoices((int)$scenarioRow['id']);

        // Consume the action NOW — even if they close the window
        $userModel->consumeAction($userId);

        Session::set('adv_state',    'scenario');
        Session::set('adv_scenario', $scenarioRow);
        Session::set('adv_choices',  $choiceRows);
        Session::delete('adv_result');

        redirect('/pages/adventure.php');
    }

    // --- CHOOSE: player picks an option ---
    if ($action === 'choose' && $state === 'scenario') {
        $choiceId = (int)($_POST['choice_id'] ?? 0);

        if (!$choiceId) {
            Session::setFlash('error', 'Please select a choice.');
            redirect('/pages/adventure.php');
        }

        // Validate choice belongs to current scenario
        $validIds = array_column($choices ?? [], 'id');
        if (!in_array($choiceId, $validIds)) {
            Session::setFlash('error', 'Invalid choice.');
            Session::set('adv_state', 'idle');
            redirect('/pages/adventure.php');
        }

        $freshUser = $userModel->findById($userId);
        $result    = $adventure->execute($userId, $choiceId, $freshUser);

        if (!$result['success']) {
            Session::setFlash('error', $result['error']);
            Session::set('adv_state', 'idle');
            redirect('/pages/adventure.php');
        }

        Session::set('adv_state',  'result');
        Session::set('adv_result', $result);

        redirect('/pages/adventure.php');
    }

    // --- CONTINUE: reset state, go again or return ---
    if ($action === 'continue') {
        Session::set('adv_state', 'idle');
        Session::delete('adv_result');
        Session::delete('adv_scenario');
        Session::delete('adv_choices');
        redirect('/pages/adventure.php');
    }
}

// =========================================================================
// DATA
// =========================================================================
$dailyState   = $userModel->getDailyState($userId);
$recentLog    = $adventure->getRecentLog($userId, 8);
$user         = $userModel->findById($userId); // refresh after possible XP/gold changes

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

    <!-- HEADER -->
    <div class="adv-header">
        <div>
            <h1>⚔ Go Adventuring</h1>
            <p class="text-muted">Face the financial challenges of the real world. Fortune favors the prepared.</p>
        </div>
        <div class="adv-actions-badge">
            <span class="adv-actions-label">Actions Today</span>
            <span class="adv-actions-val <?= $dailyState['actions_remaining'] <= 0 ? 'text-red' : 'text-gold' ?>">
                <?= $dailyState['actions_remaining'] ?> / <?= $db->getSetting('daily_action_limit', 10) ?>
            </span>
        </div>
    </div>

    <?= renderFlash() ?>

    <?php if ($state === 'idle'): ?>
    <!-- =====================================================
         IDLE STATE — Ready to adventure
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

        <!-- RECENT LOG -->
        <div class="adv-sidebar">
            <?php if (!empty($recentLog)): ?>
            <div class="card adv-log-card">
                <h3 class="mb-3">📜 Recent Adventures</h3>
                <div class="adv-log-list">
                    <?php foreach ($recentLog as $entry):
                        $ol = $outcomeLabels[$entry['outcome']] ?? $outcomeLabels['failure'];
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
         SCENARIO STATE — Show encounter + choices
    ===================================================== -->
    <?php $cat = $categoryMeta[$scenario['category']] ?? $categoryMeta['daily_life']; ?>
    <div class="adv-encounter">

        <div class="encounter-category">
            <span><?= $cat['icon'] ?></span>
            <span style="color:<?= $cat['color'] ?>"><?= $cat['label'] ?></span>
        </div>

        <h2 class="encounter-title"><?= e($scenario['title']) ?></h2>

        <?php if ($scenario['flavor_text']): ?>
        <p class="encounter-flavor">"<?= e($scenario['flavor_text']) ?>"</p>
        <?php endif; ?>

        <div class="encounter-description">
            <?= nl2br(e($scenario['description'])) ?>
        </div>

        <div class="encounter-choices">
            <h3 class="choices-heading">What do you do?</h3>
            <?php foreach ($choices as $choice): ?>
            <form method="POST" class="choice-form">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action"    value="choose">
                <input type="hidden" name="choice_id" value="<?= $choice['id'] ?>">
                <button type="submit" class="choice-btn">
                    <span class="choice-text"><?= e($choice['choice_text']) ?></span>
                    <?php if ($choice['hint_text']): ?>
                    <span class="choice-hint"><?= e($choice['hint_text']) ?></span>
                    <?php endif; ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>

        <div class="encounter-modifier-hint">
            Your modifier today: <strong class="text-gold">+<?= $adventure->calculateModifier(
                (int)$user['level'], $user['class'], $scenario['category']
            ) ?></strong>
            (Level <?= $user['level'] ?> + class bonus)
        </div>

    </div>

    <?php elseif ($state === 'result' && $result): ?>
    <!-- =====================================================
         RESULT STATE — Show roll result + narrative
    ===================================================== -->
    <?php $ol = $outcomeLabels[$result['outcome']] ?? $outcomeLabels['failure']; ?>
    <div class="adv-result <?= $result['outcome'] ?>-result">

        <div class="result-outcome-badge" style="color:<?= $ol['color'] ?>;border-color:<?= $ol['color'] ?>">
            <?= $ol['icon'] ?> <?= $ol['label'] ?>
        </div>

        <h2 class="result-scenario-title"><?= e($result['scenario_title']) ?></h2>
        <p class="result-choice-echo text-muted">You chose: <em><?= e($result['choice_text']) ?></em></p>

        <!-- Roll display -->
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

        <!-- Narrative -->
        <div class="result-narrative">
            <?= nl2br(e($result['narrative'])) ?>
        </div>

        <!-- Rewards -->
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

            <?php if ($result['leveled_up']): ?>
                <span class="reward-badge level-badge">🎉 LEVEL UP → <?= $result['new_level'] ?>!</span>
            <?php endif; ?>
        </div>

        <!-- Continue -->
        <form method="POST" class="mt-3">
            <?= Session::csrfField() ?>
            <input type="hidden" name="action" value="continue">
            <button type="submit" class="btn btn-primary">
                <?= $dailyState['actions_remaining'] > 0 ? '⚔ Adventure Again' : '🌙 Rest for the Night' ?>
            </button>
            <a href="<?= BASE_URL ?>/pages/dashboard.php" class="btn btn-secondary" style="margin-left:0.5rem">
                Return to Dashboard
            </a>
        </form>

    </div>

    <?php endif; ?>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
