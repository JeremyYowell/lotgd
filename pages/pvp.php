<?php
/**
 * pages/pvp.php — PvP Combat
 *
 * States: idle → fighting → result
 * State is held in the pvp_sessions DB table (same pattern as adventure_sessions).
 * POST handlers compute next state and redirect to GET.
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userModel = new User();
$pvp       = new Pvp();
$store     = new Store();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

// =========================================================================
// POST HANDLERS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();
    $action = $_POST['action'] ?? '';

    // --- Challenge: start a new fight ---
    if ($action === 'challenge') {
        $defenderId = (int)($_POST['defender_id'] ?? 0);

        if (!$defenderId || $defenderId === $userId) {
            Session::setFlash('error', 'Invalid target.');
            redirect('/pages/pvp.php');
        }

        $defender = $userModel->findById($defenderId);
        if (!$defender || $defender['is_banned']) {
            Session::setFlash('error', 'Target not found.');
            redirect('/pages/pvp.php');
        }

        if ((int)$defender['level'] < (int)$user['level']) {
            Session::setFlash('error', 'You can only challenge players of equal or higher level.');
            redirect('/pages/pvp.php');
        }

        if ($pvp->alreadyFoughtToday($userId, $defenderId)) {
            Session::setFlash('error', 'You have already challenged ' . e($defender['username']) . ' today.');
            redirect('/pages/pvp.php');
        }

        if ($pvp->getActiveSession($userId)) {
            Session::setFlash('error', 'Please dismiss your current fight result before starting a new one.');
            redirect('/pages/pvp.php');
        }

        $pvp->startFight($user, $defender);
        redirect('/pages/pvp.php');
    }

    // --- Attack ---
    if ($action === 'attack') {
        $result = $pvp->doAttack($userId);
        if (isset($result['error'])) {
            Session::setFlash('error', $result['error']);
        }
        redirect('/pages/pvp.php');
    }

    // --- Flee ---
    if ($action === 'flee') {
        $result = $pvp->doFlee($userId);
        if (isset($result['error'])) {
            Session::setFlash('error', $result['error']);
        }
        redirect('/pages/pvp.php');
    }

    // --- Dismiss result ---
    if ($action === 'dismiss') {
        $db->run(
            "DELETE FROM pvp_sessions WHERE challenger_id = ? AND state = 'finished'",
            [$userId]
        );
        redirect('/pages/pvp.php');
    }
}

// =========================================================================
// HANDLE GET ?challenge= — pre-select a target from profile link
// =========================================================================
$preselect = null;
if (!empty($_GET['challenge']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $preselectId  = (int)$_GET['challenge'];
    if ($preselectId && $preselectId !== $userId) {
        $preselectRow = $userModel->findById($preselectId);
        if ($preselectRow && !$preselectRow['is_banned']) {
            if ((int)$preselectRow['level'] < (int)$user['level']) {
                Session::setFlash('error', 'You can only challenge players of equal or higher level.');
            } else {
                $preselect = $preselectRow;
            }
        }
    }
}

// =========================================================================
// DETERMINE RENDER STATE
// =========================================================================
$session  = $pvp->getActiveSession($userId);
$state    = 'idle';
$defender = null;
$finished = null;

if ($session) {
    $defender = $userModel->findById((int)$session['defender_id']);
    if ($session['state'] === 'active') {
        $state = 'fighting';
    } else {
        $state = 'result';
        // Enrich session with data needed for result display
        // Pull rounds and XP from the pvp_log row for this fight
        $logRow = $db->fetchOne(
            "SELECT pl.rounds, pl.challenger_xp, pl.defender_xp, ud.username AS defender_name
             FROM pvp_log pl
             JOIN users ud ON ud.id = pl.defender_id
             WHERE pl.challenger_id = ?
             ORDER BY pl.fought_at DESC LIMIT 1",
            [$userId]
        );
        $finished = array_merge($session, [
            'rounds'         => $logRow['rounds']       ?? 1,
            'challenger_xp'  => $logRow['challenger_xp'] ?? 0,
            'defender_xp'    => $logRow['defender_xp']   ?? 0,
            'defender_name'  => $logRow['defender_name'] ?? ($defender['username'] ?? 'Unknown'),
        ]);
    }
}

// =========================================================================
// DATA
// =========================================================================
$myStats         = $pvp->getCombatantStats($user);
$myPvpStats      = $pvp->getStats($userId);
$recentFights    = $pvp->getRecentFights($userId, 5);
$targets         = [];
$defenderStats   = null;

if ($state === 'idle') {
    $targets = $pvp->getChallengeableTargets($userId, (int)$user['level']);
}

if ($state === 'fighting' && $defender) {
    $defenderStats = $pvp->getCombatantStats($defender);
}

$classIcons = [
    'investor'    => '📈',
    'debt_slayer' => '🗡️',
    'saver'       => '🏦',
    'entrepreneur'=> '🚀',
    'minimalist'  => '🧘',
];

$resultLabels = [
    'challenger_win' => ['label' => 'Victory!',      'color' => '#22c55e', 'icon' => '🏆'],
    'defender_win'   => ['label' => 'Defeated',       'color' => '#ef4444', 'icon' => '💀'],
    'draw'           => ['label' => 'Draw',            'color' => '#f59e0b', 'icon' => '⚖'],
    'fled'           => ['label' => 'You Fled',        'color' => '#6b82a0', 'icon' => '🏃'],
];

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'PvP Combat';
$bodyClass = 'page-pvp';
$extraCss  = ['pvp.css'];

ob_start();
?>

<div class="pvp-wrap">

    <div class="pvp-header">
        <div>
            <h1>⚔ PvP Combat</h1>
            <p class="text-muted">Challenge fellow adventurers to honorable combat.</p>
        </div>
        <?php if ($myPvpStats['wins'] + $myPvpStats['losses'] + $myPvpStats['draws'] > 0): ?>
        <div class="pvp-honor-badge">
            <span class="pvp-honor-label">Honor Record</span>
            <span class="pvp-honor-val">
                <span class="text-green"><?= $myPvpStats['wins'] ?>W</span>
                <span class="text-muted"> / </span>
                <span class="text-red"><?= $myPvpStats['losses'] ?>L</span>
                <span class="text-muted"> / </span>
                <span style="color:#f59e0b"><?= $myPvpStats['draws'] ?>D</span>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <?= renderFlash() ?>

    <?php if ($state === 'fighting' && $session && $defender && $defenderStats): ?>
    <!-- ================================================================
         FIGHTING STATE
    ================================================================ -->
    <div class="pvp-arena">

        <!-- Combatant cards -->
        <div class="pvp-combatants">

            <div class="combatant-card you">
                <div class="combatant-icon"><?= $classIcons[$user['class']] ?? '⚔️' ?></div>
                <div class="combatant-name"><?= e($user['username']) ?> <span class="text-muted">(you)</span></div>
                <div class="combatant-level text-muted">Level <?= $user['level'] ?></div>
                <div class="combatant-hp-wrap">
                    <?php
                    $challHpPct = max(0, min(100, round(($session['challenger_hp'] / $session['max_challenger_hp']) * 100)));
                    $challColor = $challHpPct > 50 ? '#22c55e' : ($challHpPct > 25 ? '#f59e0b' : '#ef4444');
                    ?>
                    <div class="hp-meter-label">
                        <span style="color:<?= $challColor ?>">
                            <?= $session['challenger_hp'] ?> / <?= $session['max_challenger_hp'] ?> HP
                        </span>
                        <span class="text-muted" style="font-size:0.7rem"><?= $challHpPct ?>%</span>
                    </div>
                    <div class="hp-meter-track">
                        <div class="hp-meter-fill" style="width:<?= $challHpPct ?>%;background:<?= $challColor ?>"></div>
                    </div>
                    <?php if ($challHpPct <= 25): ?>
                    <div class="hp-meter-danger">⚠ Critical HP!</div>
                    <?php endif; ?>
                </div>
                <div class="combatant-stats text-muted">
                    ⚔ +<?= $myStats['attack_mod'] ?> atk
                    &nbsp;·&nbsp;
                    🛡 +<?= $myStats['defense_mod'] ?> def
                </div>
            </div>

            <div class="pvp-vs">VS</div>

            <div class="combatant-card enemy">
                <div class="combatant-icon"><?= $classIcons[$defender['class']] ?? '⚔️' ?></div>
                <div class="combatant-name">
                    <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($defender['username']) ?>">
                        <?= e($defender['username']) ?>
                    </a>
                </div>
                <div class="combatant-level text-muted">Level <?= $defender['level'] ?></div>
                <div class="combatant-hp-wrap">
                    <?php
                    $defHpPct  = max(0, min(100, round(($session['defender_hp'] / $session['max_defender_hp']) * 100)));
                    $defColor  = $defHpPct > 50 ? '#22c55e' : ($defHpPct > 25 ? '#f59e0b' : '#ef4444');
                    ?>
                    <div class="hp-meter-label">
                        <span style="color:<?= $defColor ?>">
                            <?= $session['defender_hp'] ?> / <?= $session['max_defender_hp'] ?> HP
                        </span>
                        <span class="text-muted" style="font-size:0.7rem"><?= $defHpPct ?>%</span>
                    </div>
                    <div class="hp-meter-track">
                        <div class="hp-meter-fill" style="width:<?= $defHpPct ?>%;background:<?= $defColor ?>"></div>
                    </div>
                    <?php if ($defHpPct <= 25): ?>
                    <div class="hp-meter-danger">⚠ Critical HP!</div>
                    <?php endif; ?>
                </div>
                <div class="combatant-stats text-muted">
                    ⚔ +<?= $defenderStats['attack_mod'] ?> atk
                    &nbsp;·&nbsp;
                    🛡 +<?= $defenderStats['defense_mod'] ?> def
                </div>
            </div>

        </div><!-- /combatants -->

        <!-- Round indicator -->
        <div class="pvp-round-indicator">
            Round <?= $session['round'] ?> / <?= Pvp::MAX_ROUNDS ?>
        </div>

        <!-- Combat log -->
        <?php if (!empty(trim($session['combat_log']))): ?>
        <div class="pvp-log card">
            <h4 class="pvp-log-title">⚔ Combat Log</h4>
            <div class="pvp-log-body">
                <?php
                $logLines = array_filter(explode("\n", trim($session['combat_log'])));
                foreach (array_reverse($logLines) as $line):
                    $line = trim($line);
                    if (empty($line)) continue;
                    $lineClass = str_contains($line, 'defeated') || str_contains($line, 'falls') ? 'log-defeat'
                        : (str_contains($line, 'Hit!') ? 'log-hit'
                        : (str_contains($line, 'Miss!') ? 'log-miss'
                        : (str_contains($line, 'Initiative') ? 'log-init' : '')));
                ?>
                <div class="log-line <?= $lineClass ?>"><?= e($line) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action buttons -->
        <div class="pvp-actions">
            <form method="POST" style="display:inline">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action" value="attack">
                <button type="submit" class="btn btn-primary pvp-attack-btn">
                    ⚔ Attack
                    <span style="font-size:0.75rem;opacity:0.8;margin-left:0.35rem">
                        d20 + <?= $myStats['attack_mod'] ?>
                    </span>
                </button>
            </form>
            <form method="POST" style="display:inline">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action" value="flee">
                <button type="submit" class="btn btn-secondary">
                    🏃 Flee
                    <span style="font-size:0.75rem;opacity:0.7;margin-left:0.35rem">
                        DC <?= Pvp::FLEE_DC ?>
                    </span>
                </button>
            </form>
        </div>

    </div><!-- /pvp-arena -->

    <?php elseif ($state === 'result' && $finished): ?>
    <!-- ================================================================
         RESULT STATE
    ================================================================ -->
    <?php
    $rl      = $resultLabels[$finished['result']] ?? $resultLabels['draw'];
    $isWin   = $finished['result'] === 'challenger_win';
    $isDraw  = $finished['result'] === 'draw';
    $isFled  = $finished['result'] === 'fled';
    $challXp = (int)$finished['challenger_xp'];
    $defName = $finished['defender_name'] ?? ($defender ? $defender['username'] : 'your opponent');
    ?>
    <div class="pvp-result-wrap">

        <div class="pvp-result-banner pvp-result-<?= $finished['result'] ?>">
            <div class="pvp-result-icon"><?= $rl['icon'] ?></div>
            <div class="pvp-result-title" style="color:<?= $rl['color'] ?>"><?= $rl['label'] ?></div>
            <?php if ($isWin): ?>
                <div class="pvp-result-subtitle">
                    You defeated <strong><?= e($defName) ?></strong>
                    in <?= (int)($finished['rounds'] ?? 1) ?> round<?= ($finished['rounds'] ?? 1) != 1 ? 's' : '' ?>!
                </div>
            <?php elseif ($isDraw): ?>
                <div class="pvp-result-subtitle">
                    You and <strong><?= e($defName) ?></strong> fought to exhaustion.
                </div>
            <?php elseif ($isFled): ?>
                <div class="pvp-result-subtitle">
                    You fled from <strong><?= e($defName) ?></strong>.
                </div>
            <?php else: ?>
                <div class="pvp-result-subtitle">
                    <strong><?= e($defName) ?></strong> bested you in combat.
                </div>
            <?php endif; ?>
        </div>

        <div class="pvp-result-rewards card">
            <h3 class="pvp-section-title">⚔ Battle Rewards</h3>
            <div class="pvp-reward-row">
                <span class="pvp-reward-icon">⭐</span>
                <span class="pvp-reward-label">Experience</span>
                <span class="pvp-reward-val <?= $challXp > 0 ? 'text-gold' : 'text-muted' ?>">
                    <?= $challXp > 0 ? '+' . num($challXp) . ' XP' : 'No XP earned' ?>
                </span>
            </div>
            <div class="pvp-reward-row">
                <span class="pvp-reward-icon">📜</span>
                <span class="pvp-reward-label">Honor Record</span>
                <span class="pvp-reward-val text-muted">
                    <?= $myPvpStats['wins'] ?>W / <?= $myPvpStats['losses'] ?>L / <?= $myPvpStats['draws'] ?>D
                </span>
            </div>
        </div>

        <div class="pvp-log card">
            <h4 class="pvp-log-title">⚔ Full Combat Log</h4>
            <div class="pvp-log-body">
                <?php
                $logLines = array_filter(explode("
", trim($finished['combat_log'])));
                foreach ($logLines as $line):
                    $line = trim($line);
                    if (empty($line)) continue;
                    $lc = (str_contains($line, 'victorious') || str_contains($line, 'wins') || str_contains($line, 'defeated') || str_contains($line, 'falls') || str_contains($line, 'draw') || str_contains($line, '═'))
                        ? 'log-defeat'
                        : (str_contains($line, 'Hit!') ? 'log-hit'
                        : (str_contains($line, 'Miss!') ? 'log-miss'
                        : (str_contains($line, 'Initiative') || str_contains($line, 'Round') || str_contains($line, 'Flee') ? 'log-init' : '')));
                ?>
                <div class="log-line <?= $lc ?>"><?= e($line) ?></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="pvp-result-actions">
            <form method="POST" style="display:inline">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action" value="dismiss">
                <button type="submit" class="btn btn-primary">⚔ Fight Again</button>
            </form>
            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode(Session::username()) ?>"
               class="btn btn-secondary">My Profile</a>
            <a href="<?= BASE_URL ?>/pages/dashboard.php"
               class="btn btn-secondary">Dashboard</a>
        </div>

    </div>
    <?php else: ?>
    <!-- ================================================================
         IDLE STATE — target list + recent fights
    ================================================================ -->
    <div class="pvp-layout">
        <div class="pvp-main">

            <!-- Your combat stats -->
            <div class="card pvp-mystats">
                <h3 class="pvp-section-title">Your Combat Stats</h3>
                <div class="pvp-stat-row">
                    <span class="text-muted">Max HP</span>
                    <strong><?= $myStats['max_hp'] ?></strong>
                </div>
                <div class="pvp-stat-row">
                    <span class="text-muted">Attack Modifier</span>
                    <strong>+<?= $myStats['attack_mod'] ?></strong>
                </div>
                <div class="pvp-stat-row">
                    <span class="text-muted">Defense Modifier</span>
                    <strong>+<?= $myStats['defense_mod'] ?></strong>
                </div>
                <div class="pvp-stat-row">
                    <span class="text-muted">Damage Range</span>
                    <strong><?= $myStats['damage_range'] ?></strong>
                </div>
                <div class="pvp-stat-row">
                    <span class="text-muted">Flee DC</span>
                    <strong><?= Pvp::FLEE_DC ?> (roll d20 + <?= (int)floor($user['level']/5) ?>)</strong>
                </div>
            </div>

            <!-- Target list -->
            <div class="card pvp-targets">
                <h3 class="pvp-section-title">
                    ⚔ Available Challengers
                    <span class="text-muted" style="font-weight:normal;font-size:0.8rem">
                        — same level or higher only
                    </span>
                </h3>

                <?php
                // If arriving from a profile challenge link, scroll to that target
                if ($preselect):
                ?>
                <div class="pvp-preselect-notice">
                    ⚔ Ready to challenge
                    <strong style="color:var(--color-gold-light)"><?= e($preselect['username']) ?></strong>
                    — find them below or pick a different opponent.
                </div>
                <?php endif; ?>
                <?php if (empty($targets)): ?>
                <p class="text-muted" style="font-size:0.9rem">
                    No challengeable adventurers available right now.
                    You may have challenged everyone eligible today, or no players are at your level or higher.
                </p>
                <?php else: ?>
                <div class="pvp-target-list">
                    <?php foreach ($targets as $target):
                        $tStats = $pvp->getCombatantStats($target);
                        $tPvp   = $pvp->getStats((int)$target['id']);
                    ?>
                    <?php
                    $isPreselected = $preselect && (int)$target['id'] === (int)$preselect['id'];
                    ?>
                    <div class="pvp-target-row <?= $isPreselected ? 'pvp-target-highlighted' : '' ?>"
                         <?= $isPreselected ? 'id="pvp-preselect-target"' : '' ?>>
                        <div class="pvp-target-info">
                            <span class="pvp-target-icon"><?= $classIcons[$target['class']] ?? '⚔️' ?></span>
                            <div class="pvp-target-body">
                                <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($target['username']) ?>"
                                   class="pvp-target-name">
                                    <?= e($target['username']) ?>
                                </a>
                                <span class="pvp-target-meta text-muted">
                                    Level <?= $target['level'] ?>
                                    &nbsp;·&nbsp;
                                    <?= $tPvp['wins'] ?>W / <?= $tPvp['losses'] ?>L
                                    &nbsp;·&nbsp;
                                    <?= $tStats['max_hp'] ?> HP
                                    &nbsp;·&nbsp;
                                    ⚔ +<?= $tStats['attack_mod'] ?>
                                    &nbsp;·&nbsp;
                                    🛡 +<?= $tStats['defense_mod'] ?>
                                </span>
                            </div>
                        </div>
                        <form method="POST">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action"      value="challenge">
                            <input type="hidden" name="defender_id" value="<?= $target['id'] ?>">
                            <button type="submit" class="btn btn-primary pvp-challenge-btn">
                                ⚔ Challenge
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /pvp-main -->

        <!-- Sidebar: recent fights -->
        <div class="pvp-sidebar">
            <?php if (!empty($recentFights)): ?>
            <div class="card pvp-recent">
                <h3 class="pvp-section-title">Recent Fights</h3>
                <div class="pvp-recent-list">
                    <?php foreach ($recentFights as $fight):
                        $iAmChallenger = ((int)$fight['challenger_id'] === $userId);
                        $opponent      = $iAmChallenger ? $fight['defender_name'] : $fight['challenger_name'];
                        $myResult      = match($fight['result']) {
                            'challenger_win' => $iAmChallenger ? 'win'  : 'loss',
                            'defender_win'   => $iAmChallenger ? 'loss' : 'win',
                            'draw'           => 'draw',
                            'fled'           => $iAmChallenger ? 'fled' : 'win',
                        };
                        $rc = match($myResult) {
                            'win'  => ['color' => '#22c55e', 'label' => 'W'],
                            'loss' => ['color' => '#ef4444', 'label' => 'L'],
                            'draw' => ['color' => '#f59e0b', 'label' => 'D'],
                            'fled' => ['color' => '#6b82a0', 'label' => 'F'],
                        };
                    ?>
                    <div class="pvp-recent-row">
                        <span class="pvp-recent-badge" style="color:<?= $rc['color'] ?>;border-color:<?= $rc['color'] ?>">
                            <?= $rc['label'] ?>
                        </span>
                        <div class="pvp-recent-body">
                            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($opponent) ?>"
                               class="pvp-recent-name">
                                <?= e($opponent) ?>
                            </a>
                            <span class="text-muted" style="font-size:0.72rem">
                                <?= $fight['rounds'] ?> round<?= $fight['rounds'] > 1 ? 's' : '' ?>
                                &nbsp;·&nbsp;
                                <?= date('M j', strtotime($fight['fought_at'])) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Rules card -->
            <div class="card pvp-rules">
                <h3 class="pvp-section-title">⚔ Rules of Combat</h3>
                <ul class="pvp-rules-list">
                    <li>Challenge players at your level or higher only</li>
                    <li>One challenge per player per day</li>
                    <li>Initiative roll each round determines who attacks first</li>
                    <li>Up to <?= Pvp::MAX_ROUNDS ?> rounds — then a draw</li>
                    <li>You may attempt to flee — success requires a roll of DC <?= Pvp::FLEE_DC ?></li>
                    <li>Victory earns <?= Pvp::XP_WIN ?>+ XP. Draw earns <?= Pvp::XP_DRAW ?> XP.</li>
                    <li>Beating a higher-level opponent earns bonus XP</li>
                </ul>
            </div>

        </div><!-- /pvp-sidebar -->
    </div>

    <?php endif; ?>

</div><!-- /pvp-wrap -->

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
