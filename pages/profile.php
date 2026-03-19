<?php
/**
 * pages/profile.php — Public player profile
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$username = trim($_GET['user'] ?? '');
if (empty($username)) {
    redirect('/pages/leaderboard.php');
}

// Load profile user
$profileUser = $db->fetchOne(
    "SELECT id, username, class, `level`, xp, xp_to_next_level,
            gold, login_streak, created_at, is_banned
     FROM users WHERE username = ? AND is_banned = 0",
    [$username]
);

if (!$profileUser) {
    Session::setFlash('error', 'Adventurer not found.');
    redirect('/pages/leaderboard.php');
}

$profileId = (int)$profileUser['id'];
$isOwnProfile = ($profileId === Session::userId());

// Adventure stats summary
$advStats = $db->fetchOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN outcome IN ('success','crit_success') THEN 1 ELSE 0 END) AS wins,
        SUM(CASE WHEN outcome = 'crit_success' THEN 1 ELSE 0 END) AS crits,
        SUM(CASE WHEN outcome IN ('failure','crit_failure') THEN 1 ELSE 0 END) AS losses,
        SUM(CASE WHEN outcome = 'crit_failure' THEN 1 ELSE 0 END) AS crit_failures,
        SUM(xp_delta) AS total_xp_earned,
        SUM(CASE WHEN gold_delta > 0 THEN gold_delta ELSE 0 END) AS total_gold_earned
     FROM adventure_log WHERE user_id = ?",
    [$profileId]
);

$winRate = ($advStats['total'] > 0)
    ? round(($advStats['wins'] / $advStats['total']) * 100)
    : 0;

// Category breakdown
$categoryStats = $db->fetchAll(
    "SELECT ads.category,
            COUNT(*) AS played,
            SUM(CASE WHEN al.outcome IN ('success','crit_success') THEN 1 ELSE 0 END) AS wins
     FROM adventure_log al
     JOIN adventure_scenarios ads ON ads.id = al.scenario_id
     WHERE al.user_id = ?
     GROUP BY ads.category
     ORDER BY played DESC",
    [$profileId]
);

// Portfolio snapshot
$portfolio = $db->fetchOne(
    "SELECT pct_return, total_value_usd, beats_index, spx_pct_return, snapshot_date
     FROM portfolio_snapshots
     WHERE user_id = ?
     ORDER BY snapshot_date DESC LIMIT 1",
    [$profileId]
);

// Portfolio inception date (first trade)
$firstTrade = $db->fetchValue(
    "SELECT MIN(traded_at) FROM portfolio_trades WHERE user_id = ?",
    [$profileId]
);

// Leaderboard position (by portfolio return)
$lbPosition = null;
if ($portfolio) {
    $lbPosition = (int)$db->fetchValue(
        "SELECT COUNT(*) + 1
         FROM portfolio_snapshots ps
         JOIN users u ON u.id = ps.user_id
         WHERE ps.snapshot_date = ?
           AND u.is_banned = 0
           AND ps.pct_return > ?",
        [$portfolio['snapshot_date'], $portfolio['pct_return']]
    );
}

// Achievements
$achievements = $db->fetchAll(
    "SELECT a.name, a.description, a.icon, a.xp_reward, a.gold_reward, ua.earned_at
     FROM user_achievements ua
     JOIN achievements a ON a.id = ua.achievement_id
     WHERE ua.user_id = ?
     ORDER BY ua.earned_at DESC",
    [$profileId]
);

// Equipped gear
$store    = new Store();
$equipped = $store->getEquipped($profileId);

// XP progress
$xpProgress = ($profileUser['xp_to_next_level'] > 0)
    ? min(100, (int)(($profileUser['xp'] / $profileUser['xp_to_next_level']) * 100))
    : 100;

$classIcons = [
    'investor'    => '📈',
    'debt_slayer' => '🗡️',
    'saver'       => '🏦',
    'entrepreneur'=> '🚀',
    'minimalist'  => '🧘',
];

$classLabels = [
    'investor'    => 'Investor',
    'debt_slayer' => 'Debt Slayer',
    'saver'       => 'Saver',
    'entrepreneur'=> 'Entrepreneur',
    'minimalist'  => 'Minimalist',
];

$categoryMeta = [
    'shopping'   => ['icon' => '🛒', 'label' => 'Shopping'],
    'work'       => ['icon' => '💼', 'label' => 'Work'],
    'banking'    => ['icon' => '🏦', 'label' => 'Banking'],
    'investing'  => ['icon' => '📈', 'label' => 'Investing'],
    'housing'    => ['icon' => '🏠', 'label' => 'Housing'],
    'daily_life' => ['icon' => '☀️', 'label' => 'Daily Life'],
];

$slotIcons  = ['tool' => '🔧', 'armor' => '🛡️', 'weapon' => '⚔️'];
$slotLabels = ['tool' => 'Tool', 'armor' => 'Armor', 'weapon' => 'Weapon'];

$pageTitle = e($profileUser['username']) . "'s Profile";
$bodyClass = 'page-profile';
$extraCss  = ['profile.css'];

ob_start();
?>

<div class="profile-wrap">

    <!-- HEADER CARD -->
    <div class="card profile-header-card">
        <div class="profile-avatar">
            <?= $classIcons[$profileUser['class']] ?? '⚔️' ?>
        </div>
        <div class="profile-identity">
            <h1 class="profile-username"><?= e($profileUser['username']) ?></h1>
            <div class="profile-class">
                <?= $classLabels[$profileUser['class']] ?? $profileUser['class'] ?>
                <?php if ($isOwnProfile): ?>
                    <span class="profile-you-tag">you</span>
                <?php endif; ?>
            </div>
            <div class="profile-since text-muted">
                Adventurer since <?= date('F Y', strtotime($profileUser['created_at'])) ?>
            </div>
        </div>
        <div class="profile-level-block">
            <span class="profile-level-num"><?= $profileUser['level'] ?></span>
            <span class="profile-level-label">LEVEL</span>
            <div class="profile-xp-bar-wrap">
                <div class="profile-xp-bar" style="width:<?= $xpProgress ?>%"></div>
            </div>
            <span class="profile-xp-text text-muted">
                <?= num($profileUser['xp']) ?> / <?= num($profileUser['xp_to_next_level']) ?> XP
            </span>
        </div>
    </div>

    <div class="profile-grid">

        <!-- LEFT COLUMN -->
        <div class="profile-col-left">

            <!-- ADVENTURE STATS -->
            <div class="card profile-section">
                <h3 class="profile-section-title">⚔ Adventure Record</h3>
                <div class="profile-stat-grid">
                    <div class="pstat-box">
                        <span class="pstat-val"><?= num($advStats['total'] ?? 0) ?></span>
                        <span class="pstat-label">Adventures</span>
                    </div>
                    <div class="pstat-box">
                        <span class="pstat-val text-green"><?= $winRate ?>%</span>
                        <span class="pstat-label">Win Rate</span>
                    </div>
                    <div class="pstat-box">
                        <span class="pstat-val text-gold"><?= num($advStats['crits'] ?? 0) ?></span>
                        <span class="pstat-label">Crit Successes</span>
                    </div>
                    <div class="pstat-box">
                        <span class="pstat-val text-red"><?= num($advStats['crit_failures'] ?? 0) ?></span>
                        <span class="pstat-label">Crit Failures</span>
                    </div>
                    <div class="pstat-box">
                        <span class="pstat-val"><?= num($advStats['wins'] ?? 0) ?></span>
                        <span class="pstat-label">Victories</span>
                    </div>
                    <div class="pstat-box">
                        <span class="pstat-val"><?= num($advStats['losses'] ?? 0) ?></span>
                        <span class="pstat-label">Defeats</span>
                    </div>
                </div>

                <?php if (!empty($categoryStats)): ?>
                <div class="category-breakdown">
                    <h4 class="profile-subsection-title">By Category</h4>
                    <?php foreach ($categoryStats as $cs):
                        $meta    = $categoryMeta[$cs['category']] ?? ['icon' => '⚔', 'label' => $cs['category']];
                        $catRate = $cs['played'] > 0 ? round(($cs['wins'] / $cs['played']) * 100) : 0;
                        $barPct  = $catRate;
                    ?>
                    <div class="cat-row">
                        <span class="cat-icon"><?= $meta['icon'] ?></span>
                        <span class="cat-label"><?= $meta['label'] ?></span>
                        <div class="cat-bar-wrap">
                            <div class="cat-bar" style="width:<?= $barPct ?>%"></div>
                        </div>
                        <span class="cat-pct text-muted"><?= $catRate ?>%</span>
                        <span class="cat-played text-muted">(<?= $cs['played'] ?>)</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- PORTFOLIO -->
            <div class="card profile-section">
                <h3 class="profile-section-title">📊 Portfolio</h3>
                <?php if ($portfolio): ?>
                <div class="profile-stat-grid">
                    <div class="pstat-box" style="grid-column:span 2">
                        <?php $ret = (float)$portfolio['pct_return']; ?>
                        <span class="pstat-val <?= $ret >= 0 ? 'text-green' : 'text-red' ?>" style="font-size:2rem">
                            <?= $ret >= 0 ? '+' : '' ?><?= number_format($ret, 2) ?>%
                        </span>
                        <span class="pstat-label">Total Return</span>
                    </div>
                    <div class="pstat-box">
                        <span class="pstat-val">$<?= number_format((float)$portfolio['total_value_usd'], 0) ?></span>
                        <span class="pstat-label">Portfolio Value</span>
                    </div>
                    <div class="pstat-box">
                        <span class="pstat-val <?= $portfolio['beats_index'] ? 'text-green' : 'text-red' ?>">
                            <?= $portfolio['beats_index'] ? '🏆 Yes' : '📉 No' ?>
                        </span>
                        <span class="pstat-label">Beating SPY</span>
                    </div>
                    <?php if ($lbPosition): ?>
                    <div class="pstat-box">
                        <span class="pstat-val text-gold">#<?= $lbPosition ?></span>
                        <span class="pstat-label">Leaderboard</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($firstTrade): ?>
                    <div class="pstat-box">
                        <span class="pstat-val" style="font-size:0.9rem"><?= date('M j, Y', strtotime($firstTrade)) ?></span>
                        <span class="pstat-label">First Trade</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="margin-top:0.75rem;font-size:0.8rem;color:var(--color-text-muted)">
                    SPY benchmark: <?= number_format((float)$portfolio['spx_pct_return'], 2) ?>%
                    · Updated <?= date('M j', strtotime($portfolio['snapshot_date'])) ?>
                </div>
                <?php else: ?>
                <p class="text-muted" style="font-size:0.9rem">No portfolio activity yet.</p>
                <?php endif; ?>
            </div>

            <!-- LOGIN STREAK -->
            <?php if ((int)$profileUser['login_streak'] > 0): ?>
            <div class="card profile-section">
                <h3 class="profile-section-title">🔥 Login Streak</h3>
                <div class="streak-display">
                    <span class="streak-num"><?= $profileUser['login_streak'] ?></span>
                    <span class="streak-label">consecutive days</span>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="profile-col-right">

            <!-- EQUIPPED GEAR -->
            <div class="card profile-section">
                <h3 class="profile-section-title">🎒 Equipped Gear</h3>
                <?php if (empty($equipped)): ?>
                    <p class="text-muted" style="font-size:0.9rem">No gear equipped.</p>
                <?php else: ?>
                <div class="gear-slots">
                    <?php foreach (['tool', 'armor', 'weapon'] as $slot): ?>
                    <div class="gear-slot">
                        <span class="gear-slot-icon"><?= $slotIcons[$slot] ?></span>
                        <div class="gear-slot-body">
                            <span class="gear-slot-label text-muted"><?= $slotLabels[$slot] ?></span>
                            <?php if (isset($equipped[$slot])): ?>
                                <a href="<?= BASE_URL ?>/pages/item.php?id=<?= $equipped[$slot]['item_id'] ?>"
                                   class="gear-item-name">
                                    <?= e($equipped[$slot]['name']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:0.85rem">Empty</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ACHIEVEMENTS -->
            <div class="card profile-section">
                <h3 class="profile-section-title">
                    🏅 Achievements
                    <span class="achievement-count text-muted"><?= count($achievements) ?></span>
                </h3>
                <?php if (empty($achievements)): ?>
                    <p class="text-muted" style="font-size:0.9rem">No achievements earned yet.</p>
                <?php else: ?>
                <div class="achievement-list">
                    <?php foreach ($achievements as $ach): ?>
                    <div class="achievement-row">
                        <span class="achievement-icon"><?= $ach['icon'] ?? '🏅' ?></span>
                        <div class="achievement-body">
                            <span class="achievement-name"><?= e($ach['name']) ?></span>
                            <span class="achievement-desc text-muted"><?= e($ach['description']) ?></span>
                            <span class="achievement-date text-muted" style="font-size:0.72rem">
                                <?= date('M j, Y', strtotime($ach['earned_at'])) ?>
                                <?php if ($ach['xp_reward'] > 0): ?>
                                    · +<?= num($ach['xp_reward']) ?> XP
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </div><!-- /profile-grid -->

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
