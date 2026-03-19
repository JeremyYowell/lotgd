<?php
/**
 * pages/dashboard.php
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userModel = new User();
$adventure = new Adventure();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

if (!$user || $user['is_banned']) {
    Session::logout();
    Session::setFlash('error', 'Your account has been suspended.');
    redirect('/pages/login.php');
}

// =========================================================================
// DATA FOR DISPLAY
// =========================================================================
$dailyState  = $userModel->getDailyState($userId);
$actionLimit = (int) $db->getSetting('daily_action_limit', 10);

// Recent adventures (replaces financial entries feed)
$recentAdventures = $db->fetchAll(
    "SELECT al.*, ads.title AS scenario_title, ads.category
     FROM adventure_log al
     JOIN adventure_scenarios ads ON ads.id = al.scenario_id
     WHERE al.user_id = ?
     ORDER BY al.adventured_at DESC
     LIMIT 5",
    [$userId]
);

// Recent adventures (for the right column panel)
$recentAdventurePanel = $db->fetchAll(
    "SELECT al.*, ads.title AS scenario_title, ads.category
     FROM adventure_log al
     JOIN adventure_scenarios ads ON ads.id = al.scenario_id
     WHERE al.user_id = ?
     ORDER BY al.adventured_at DESC
     LIMIT 3",
    [$userId]
);

// Leaderboard
$leaderboard = $db->fetchAll(
    "SELECT * FROM leaderboard_cache ORDER BY `rank` ASC LIMIT 10"
);
if (empty($leaderboard)) {
    $leaderboard = $db->fetchAll(
        "SELECT
            ROW_NUMBER() OVER (ORDER BY wealth_score DESC, xp DESC) AS `rank`,
            id AS user_id, username, class, `level`, wealth_score, xp
         FROM users
         WHERE is_banned = 0
         ORDER BY wealth_score DESC, xp DESC
         LIMIT 10"
    );
}

// XP progress percentage
$xpProgress = $user['xp_to_next_level'] > 0
    ? min(100, (int) (($user['xp'] / $user['xp_to_next_level']) * 100))
    : 100;

$classIcons = [
    'investor'    => '📈',
    'debt_slayer' => '🗡️',
    'saver'       => '🏦',
    'entrepreneur'=> '🚀',
    'minimalist'  => '🧘',
];

$outcomeColors = [
    'crit_success' => '#fbbf24',
    'success'      => '#22c55e',
    'failure'      => '#f97316',
    'crit_failure' => '#ef4444',
];

$outcomeLabels = [
    'crit_success' => '⚡ Crit Success',
    'success'      => '✔ Success',
    'failure'      => '✘ Failure',
    'crit_failure' => '💀 Crit Failure',
];

$catIcons = [
    'shopping'   => '🛒',
    'work'       => '💼',
    'banking'    => '🏦',
    'investing'  => '📈',
    'housing'    => '🏠',
    'daily_life' => '☀️',
];

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Dashboard';
$bodyClass = 'page-dashboard';
$extraCss  = ['dashboard.css'];

ob_start();
?>

<?= renderFlash() ?>

<div class="dash-grid">

    <!-- ===================================================
         COL LEFT: Stats + Adventure CTA
    =================================================== -->
    <div class="dash-col-left">

        <!-- PLAYER STATS CARD -->
        <div class="card card-gold dash-stats">
            <div class="stats-header">
                <div class="stats-avatar"><?= $classIcons[$user['class']] ?? '⚔️' ?></div>
                <div class="stats-identity">
                    <div class="stats-username"><?= e($user['username']) ?></div>
                    <div class="stats-class"><?= ucwords(str_replace('_', ' ', $user['class'])) ?></div>
                </div>
                <div class="stats-level">
                    <span class="level-number"><?= $user['level'] ?></span>
                    <span class="level-label">LEVEL</span>
                </div>
            </div>

            <!-- XP Progress Bar -->
            <div class="xp-bar-wrap">
                <div class="xp-bar-labels">
                    <span><?= num($user['xp']) ?> XP</span>
                    <span class="text-muted" style="font-size:0.8rem;">
                        Next: <?= num($user['xp_to_next_level']) ?>
                    </span>
                </div>
                <div class="xp-bar-track">
                    <div class="xp-bar-fill" style="width:<?= $xpProgress ?>%"></div>
                </div>
            </div>

            <!-- Wealth Stats -->
            <div class="wealth-grid">
                <div class="wealth-stat">
                    <span class="wealth-value text-gold"><?= num($user['wealth_score']) ?></span>
                    <span class="wealth-label">Wealth Score</span>
                </div>
                <div class="wealth-stat">
                    <span class="wealth-value text-green"><?= money((float)$user['total_saved']) ?></span>
                    <span class="wealth-label">Total Saved</span>
                </div>
                <div class="wealth-stat">
                    <span class="wealth-value" style="color:#3b82f6">
                        <?= money((float)$user['total_invested']) ?>
                    </span>
                    <span class="wealth-label">Invested</span>
                </div>
                <div class="wealth-stat">
                    <span class="wealth-value" style="color:#f59e0b">
                        <?= number_format((float)$user['gold'], 0) ?> 🪙
                    </span>
                    <span class="wealth-label">Gold</span>
                </div>
            </div>
        </div>

        <!-- ADVENTURE CTA CARD -->
        <div class="card dash-adventure-cta <?= $dailyState['actions_remaining'] <= 0 ? 'adv-exhausted' : '' ?>">
            <?php if ($dailyState['actions_remaining'] <= 0): ?>
                <div class="adv-cta-icon">🌙</div>
                <h3 class="text-muted">You are weary, adventurer.</h3>
                <p class="text-muted" style="font-size:0.9rem;margin:0.5rem 0 0">
                    All actions spent for today. Return at dawn.
                </p>
            <?php else: ?>
                <div class="adv-cta-icon">⚔️</div>
                <h3>Ready for Adventure?</h3>
                <p class="text-muted" style="font-size:0.9rem;margin:0.5rem 0 1rem">
                    Face real-world financial challenges and earn XP and Gold.
                </p>
                <!-- Action pips -->
                <div class="action-pips" style="justify-content:center;margin-bottom:0.75rem">
                    <?php for ($i = 0; $i < $actionLimit; $i++):
                        $used = $i >= $dailyState['actions_remaining'];
                    ?>
                        <div class="action-pip <?= $used ? 'used' : 'available' ?>"></div>
                    <?php endfor; ?>
                </div>
                <div class="daily-count" style="text-align:center;margin-bottom:1rem">
                    <strong><?= $dailyState['actions_remaining'] ?></strong>
                    / <?= $actionLimit ?> actions remaining today
                </div>
                <a href="<?= BASE_URL ?>/pages/adventure.php" class="btn btn-primary btn-full">
                    ⚔ Go Adventuring
                </a>
            <?php endif; ?>
        </div>

        <!-- PORTFOLIO SNAPSHOT -->
        <?php
        $portSnapshot = $db->fetchOne(
            "SELECT pct_return, total_value_usd, beats_index, spx_pct_return
             FROM portfolio_snapshots
             WHERE user_id = ?
             ORDER BY snapshot_date DESC LIMIT 1",
            [$userId]
        );
        if ($portSnapshot):
            $ret = (float)$portSnapshot['pct_return'];
        ?>
        <div class="card dash-port-snap">
            <div class="card-header-row mb-2">
                <h3>📊 Portfolio</h3>
                <a href="<?= BASE_URL ?>/pages/portfolio.php"
                   class="text-muted" style="font-size:0.82rem;">View →</a>
            </div>
            <div class="wealth-grid">
                <div class="wealth-stat">
                    <span class="wealth-value <?= $ret >= 0 ? 'text-green' : 'text-red' ?>">
                        <?= $ret >= 0 ? '+' : '' ?><?= number_format($ret, 2) ?>%
                    </span>
                    <span class="wealth-label">Your Return</span>
                </div>
                <div class="wealth-stat">
                    <span class="wealth-value">
                        $<?= number_format((float)$portSnapshot['total_value_usd'], 0) ?>
                    </span>
                    <span class="wealth-label">Value</span>
                </div>
                <div class="wealth-stat" style="grid-column:span 2">
                    <span class="wealth-value <?= $portSnapshot['beats_index'] ? 'text-green' : 'text-red' ?>">
                        <?= $portSnapshot['beats_index'] ? '🏆 Beating S&P 500' : '📉 Behind S&P 500' ?>
                    </span>
                    <span class="wealth-label">
                        Index at <?= number_format((float)$portSnapshot['spx_pct_return'], 2) ?>%
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /col-left -->

    <!-- ===================================================
         COL RIGHT: Recent Adventures + Leaderboard
    =================================================== -->
    <div class="dash-col-right">

        <!-- RECENT ADVENTURES -->
        <div class="card dash-recent">
            <div class="card-header-row mb-3">
                <h3>⚔ Recent Adventures</h3>
                <a href="<?= BASE_URL ?>/pages/adventure.php"
                   class="text-muted" style="font-size:0.82rem;">Go Adventuring →</a>
            </div>

            <?php if (empty($recentAdventures)): ?>
                <p class="text-muted" style="font-size:0.9rem;">
                    No adventures yet. Head out into the world and face your first
                    financial challenge!
                    <br><br>
                    <a href="<?= BASE_URL ?>/pages/adventure.php" class="btn btn-primary">
                        ⚔ Go Adventuring
                    </a>
                </p>
            <?php else: ?>
                <div class="entries-list">
                    <?php foreach ($recentAdventures as $adv):
                        $color = $outcomeColors[$adv['outcome']] ?? '#888';
                        $label = $outcomeLabels[$adv['outcome']] ?? $adv['outcome'];
                        $icon  = $catIcons[$adv['category']] ?? '⚔';
                    ?>
                    <div class="entry-row">
                        <span class="entry-icon"><?= $icon ?></span>
                        <div class="entry-body">
                            <div class="entry-top">
                                <span class="entry-type" style="color:<?= $color ?>">
                                    <?= $label ?>
                                </span>
                                <span class="entry-amount text-muted" style="font-size:0.8rem;">
                                    Roll <?= $adv['final_roll'] ?> vs DC <?= $adv['difficulty'] ?>
                                </span>
                            </div>
                            <div class="entry-desc text-muted">
                                <?= e($adv['scenario_title']) ?>
                            </div>
                            <div class="entry-meta">
                                <span class="text-muted" style="font-size:0.78rem;">
                                    <?= $adv['xp_delta'] > 0 ? '+' . num($adv['xp_delta']) . ' XP' : '0 XP' ?>
                                    · <?= $adv['gold_delta'] >= 0 ? '+' . $adv['gold_delta'] : $adv['gold_delta'] ?> 🪙
                                    · <?= date('M j', strtotime($adv['adventured_at'])) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- LEADERBOARD TOP 10 -->
        <div class="card dash-leaderboard">
            <div class="card-header-row">
                <h3>🥇 Leaderboard</h3>
                <a href="<?= BASE_URL ?>/pages/leaderboard.php"
                   class="text-muted" style="font-size:0.82rem;">Full board →</a>
            </div>
            <table class="lb-table mt-2">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Adventurer</th>
                        <th>Lvl</th>
                        <th class="text-right">Wealth</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaderboard)): ?>
                    <tr>
                        <td colspan="4" class="text-muted"
                            style="padding:1rem;font-size:0.9rem;">
                            The board is empty — be the first legend!
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($leaderboard as $row):
                        $isMe = ((int)$row['user_id'] === $userId);
                    ?>
                    <tr class="lb-row <?= $isMe ? 'lb-me' : '' ?>">
                        <td class="lb-rank">
                            <?php if ($row['rank'] == 1)      echo '🥇';
                            elseif ($row['rank'] == 2)         echo '🥈';
                            elseif ($row['rank'] == 3)         echo '🥉';
                            else                               echo '#' . $row['rank']; ?>
                        </td>
                        <td class="lb-name">
                            <?= $classIcons[$row['class']] ?? '⚔️' ?>
                            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($row['username']) ?>"
                               style="color:var(--color-gold-light);text-decoration:none">
                                <?= e($row['username']) ?>
                            </a>
                            <?= $isMe ? '<span class="lb-you">(you)</span>' : '' ?>
                        </td>
                        <td class="lb-level"><?= $row['level'] ?></td>
                        <td class="lb-score text-right"><?= num($row['wealth_score']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /col-right -->

</div><!-- /dash-grid -->

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
