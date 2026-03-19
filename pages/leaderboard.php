<?php
/**
 * pages/leaderboard.php — Full Rankings
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userModel = new User();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

// =========================================================================
// SORTING
// =========================================================================
$validSorts = ['wealth_score', 'xp', 'level', 'total_saved', 'total_invested', 'total_debt_paid', 'login_streak'];
$sort       = in_array($_GET['sort'] ?? '', $validSorts) ? $_GET['sort'] : 'wealth_score';

$sortLabels = [
    'wealth_score'   => ['label' => 'Wealth Score',  'icon' => '👑'],
    'xp'             => ['label' => 'Total XP',       'icon' => '⭐'],
    'level'          => ['label' => 'Level',          'icon' => '🏅'],
    'total_saved'    => ['label' => 'Total Saved',    'icon' => '🏦'],
    'total_invested' => ['label' => 'Total Invested', 'icon' => '📈'],
    'total_debt_paid'=> ['label' => 'Debt Slain',     'icon' => '🗡️'],
    'login_streak'   => ['label' => 'Login Streak',   'icon' => '🔥'],
];

// =========================================================================
// DATA
// =========================================================================

// Full leaderboard — top 100, sorted by chosen column
// `rank` and `level` are reserved words in MySQL 8 — backtick both
$allPlayers = $db->fetchAll(
    "SELECT
        ROW_NUMBER() OVER (ORDER BY `{$sort}` DESC) AS position,
        id AS user_id, username, class, `level`, xp,
        wealth_score, total_saved, total_invested, total_debt_paid,
        login_streak, created_at
     FROM users
     WHERE is_banned = 0
     ORDER BY `{$sort}` DESC
     LIMIT 100"
);

// Find the current user's position in this sort
$myPosition = null;
foreach ($allPlayers as $i => $row) {
    if ((int)$row['user_id'] === $userId) {
        $myPosition = $i + 1;
        break;
    }
}

// If user is outside top 100, get their actual rank
if ($myPosition === null) {
    $myRank = $db->fetchValue(
        "SELECT COUNT(*) + 1 FROM users
         WHERE is_banned = 0 AND `{$sort}` > (SELECT `{$sort}` FROM users WHERE id = ?)",
        [$userId]
    );
    $myPosition = $myRank ?: '100+';
}

// Total active players
$totalPlayers = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM users WHERE is_banned = 0"
);

// Class distribution for the fun stats panel
$classStats = $db->fetchAll(
    "SELECT class, COUNT(*) AS cnt FROM users WHERE is_banned = 0 GROUP BY class ORDER BY cnt DESC"
);

// Top performer per category (for hall of fame strip)
$hallOfFame = [
    'wealth_score'    => $db->fetchOne("SELECT username, wealth_score AS val    FROM users WHERE is_banned=0 ORDER BY wealth_score    DESC LIMIT 1"),
    'total_saved'     => $db->fetchOne("SELECT username, total_saved AS val     FROM users WHERE is_banned=0 ORDER BY total_saved     DESC LIMIT 1"),
    'total_debt_paid' => $db->fetchOne("SELECT username, total_debt_paid AS val FROM users WHERE is_banned=0 ORDER BY total_debt_paid DESC LIMIT 1"),
    'login_streak'    => $db->fetchOne("SELECT username, login_streak AS val    FROM users WHERE is_banned=0 ORDER BY login_streak    DESC LIMIT 1"),
];

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

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Leaderboard';
$bodyClass = 'page-leaderboard';
$extraCss  = ['leaderboard.css'];

ob_start();
?>

<div class="lb-wrap">

    <!-- PAGE HEADER -->
    <div class="lb-header">
        <div>
            <h1>👑 Hall of Legends</h1>
            <p class="text-muted"><?= num($totalPlayers) ?> adventurer<?= $totalPlayers !== 1 ? 's' : '' ?> competing for glory</p>
        </div>
        <div class="my-rank-badge">
            <span class="my-rank-label">Your Rank</span>
            <span class="my-rank-val"><?= is_int($myPosition) ? '#' . $myPosition : $myPosition ?></span>
            <span class="my-rank-sort"><?= $sortLabels[$sort]['icon'] ?> <?= $sortLabels[$sort]['label'] ?></span>
        </div>
    </div>

    <!-- HALL OF FAME STRIP -->
    <div class="fame-strip">
        <?php
        $fameItems = [
            ['label' => 'Wealthiest',    'icon' => '👑', 'key' => 'wealth_score',    'fmt' => 'num'],
            ['label' => 'Top Saver',     'icon' => '🏦', 'key' => 'total_saved',     'fmt' => 'money'],
            ['label' => 'Debt Slayer',   'icon' => '🗡️',  'key' => 'total_debt_paid', 'fmt' => 'money'],
            ['label' => 'Most Devoted',  'icon' => '🔥', 'key' => 'login_streak',    'fmt' => 'days'],
        ];
        foreach ($fameItems as $fi):
            $hof = $hallOfFame[$fi['key']] ?? null;
            if (!$hof) continue;
            $val = match($fi['fmt']) {
                'money' => money((float)$hof['val']),
                'days'  => $hof['val'] . ' day' . ($hof['val'] != 1 ? 's' : ''),
                default => num((int)$hof['val']),
            };
        ?>
        <div class="fame-item">
            <span class="fame-icon"><?= $fi['icon'] ?></span>
            <span class="fame-label"><?= $fi['label'] ?></span>
            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($hof['username']) ?>" class="fame-name text-gold"><?= e($hof['username']) ?></a>
            <span class="fame-val text-muted"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- SORT TABS -->
    <div class="sort-tabs">
        <?php foreach ($sortLabels as $key => $sl): ?>
        <a
            href="?sort=<?= $key ?>"
            class="sort-tab <?= $sort === $key ? 'active' : '' ?>"
        ><?= $sl['icon'] ?> <?= $sl['label'] ?></a>
        <?php endforeach; ?>
    </div>

    <!-- LEADERBOARD TABLE -->
    <div class="card lb-card">
        <table class="lb-full-table">
            <thead>
                <tr>
                    <th class="col-rank">#</th>
                    <th class="col-player">Adventurer</th>
                    <th class="col-level">Lvl</th>
                    <th class="col-score <?= $sort === 'wealth_score'    ? 'sorted' : '' ?>">
                        <a href="?sort=wealth_score">👑 Wealth</a>
                    </th>
                    <th class="col-xp <?= $sort === 'xp' ? 'sorted' : '' ?>">
                        <a href="?sort=xp">⭐ XP</a>
                    </th>
                    <th class="col-saved <?= $sort === 'total_saved' ? 'sorted' : '' ?>">
                        <a href="?sort=total_saved">🏦 Saved</a>
                    </th>
                    <th class="col-debt <?= $sort === 'total_debt_paid' ? 'sorted' : '' ?>">
                        <a href="?sort=total_debt_paid">🗡️ Debt Slain</a>
                    </th>
                    <th class="col-streak <?= $sort === 'login_streak' ? 'sorted' : '' ?>">
                        <a href="?sort=login_streak">🔥 Streak</a>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allPlayers)): ?>
                <tr>
                    <td colspan="8" class="lb-empty">
                        No adventurers yet. Be the first legend!
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($allPlayers as $row):
                    $isMe  = ((int)$row['user_id'] === $userId);
                    $pos   = (int)$row['position'];
                    $medal = match(true) {
                        $pos === 1 => '🥇',
                        $pos === 2 => '🥈',
                        $pos === 3 => '🥉',
                        default    => '#' . $pos,
                    };
                ?>
                <tr class="lb-row <?= $isMe ? 'lb-me' : '' ?> <?= $pos <= 3 ? 'lb-top3' : '' ?>">
                    <td class="col-rank">
                        <span class="rank-val"><?= $medal ?></span>
                    </td>
                    <td class="col-player">
                        <span class="player-icon"><?= $classIcons[$row['class']] ?? '⚔️' ?></span>
                        <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($row['username']) ?>"
                           class="player-name"><?= e($row['username']) ?></a>
                        <?php if ($isMe): ?>
                            <span class="you-tag">you</span>
                        <?php endif; ?>
                        <span class="player-class text-muted"><?= $classLabels[$row['class']] ?? '' ?></span>
                    </td>
                    <td class="col-level">
                        <span class="level-chip"><?= $row['level'] ?></span>
                    </td>
                    <td class="col-score <?= $sort === 'wealth_score' ? 'sorted-col' : '' ?>">
                        <?= num($row['wealth_score']) ?>
                    </td>
                    <td class="col-xp <?= $sort === 'xp' ? 'sorted-col' : '' ?>">
                        <?= num($row['xp']) ?>
                    </td>
                    <td class="col-saved <?= $sort === 'total_saved' ? 'sorted-col' : '' ?>">
                        <?= money((float)$row['total_saved']) ?>
                    </td>
                    <td class="col-debt <?= $sort === 'total_debt_paid' ? 'sorted-col' : '' ?>">
                        <?= money((float)$row['total_debt_paid']) ?>
                    </td>
                    <td class="col-streak <?= $sort === 'login_streak' ? 'sorted-col' : '' ?>">
                        <?= $row['login_streak'] > 0 ? $row['login_streak'] . 'd' : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- CLASS DISTRIBUTION -->
    <div class="class-dist card">
        <h3 class="mb-3">⚔ Adventurer Classes</h3>
        <div class="class-dist-grid">
            <?php foreach ($classStats as $cs):
                $pct = $totalPlayers > 0 ? (int)(($cs['cnt'] / $totalPlayers) * 100) : 0;
            ?>
            <div class="class-dist-row">
                <span class="class-dist-icon"><?= $classIcons[$cs['class']] ?? '⚔️' ?></span>
                <span class="class-dist-name"><?= $classLabels[$cs['class']] ?? $cs['class'] ?></span>
                <div class="class-dist-bar-wrap">
                    <div class="class-dist-bar" style="width:<?= $pct ?>%"></div>
                </div>
                <span class="class-dist-count text-muted"><?= $cs['cnt'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
