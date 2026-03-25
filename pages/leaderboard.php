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
$validSorts = ['pct_return', 'xp', 'level', 'login_streak', 'pvp_wins'];
$sort       = in_array($_GET['sort'] ?? '', $validSorts) ? $_GET['sort'] : 'pct_return';

$sortLabels = [
    'pct_return'  => ['label' => 'Portfolio Return', 'icon' => '📈'],
    'xp'          => ['label' => 'Total XP',         'icon' => '⭐'],
    'level'       => ['label' => 'Level',            'icon' => '🏅'],
    'login_streak'=> ['label' => 'Login Streak',     'icon' => '🔥'],
    'pvp_wins'    => ['label' => 'PvP Wins',         'icon' => '⚔'],
];

// =========================================================================
// DATA
// =========================================================================

// Latest portfolio snapshot date
$latestSnapshotDate = $db->fetchValue(
    "SELECT MAX(snapshot_date) FROM portfolio_snapshots"
);

// Build the main leaderboard query — join portfolio snapshots + pvp_stats
$snapshotDate = $latestSnapshotDate ?: date('Y-m-d');

if ($sort === 'pct_return') {
    $allPlayers = $db->fetchAll(
        "SELECT
            ROW_NUMBER() OVER (ORDER BY COALESCE(ps.pct_return, -9999) DESC, u.xp DESC) AS position,
            u.id AS user_id, u.username, u.class, u.`level`, u.xp, u.login_streak,
            ps.pct_return, ps.total_value_usd, ps.beats_index, ps.spx_pct_return,
            COALESCE(pv.wins, 0)   AS pvp_wins,
            COALESCE(pv.losses, 0) AS pvp_losses,
            COALESCE(pv.draws, 0)  AS pvp_draws
         FROM users u
         LEFT JOIN portfolio_snapshots ps ON ps.user_id = u.id AND ps.snapshot_date = ?
         LEFT JOIN pvp_stats pv ON pv.user_id = u.id
         WHERE u.is_banned = 0
         ORDER BY COALESCE(ps.pct_return, -9999) DESC, u.xp DESC
         LIMIT 100",
        [$snapshotDate]
    );
} elseif ($sort === 'pvp_wins') {
    $allPlayers = $db->fetchAll(
        "SELECT
            ROW_NUMBER() OVER (ORDER BY COALESCE(pv.wins, 0) DESC, u.xp DESC) AS position,
            u.id AS user_id, u.username, u.class, u.`level`, u.xp, u.login_streak,
            ps.pct_return, ps.total_value_usd, ps.beats_index, ps.spx_pct_return,
            COALESCE(pv.wins, 0)   AS pvp_wins,
            COALESCE(pv.losses, 0) AS pvp_losses,
            COALESCE(pv.draws, 0)  AS pvp_draws
         FROM users u
         LEFT JOIN portfolio_snapshots ps ON ps.user_id = u.id AND ps.snapshot_date = ?
         LEFT JOIN pvp_stats pv ON pv.user_id = u.id
         WHERE u.is_banned = 0
         ORDER BY COALESCE(pv.wins, 0) DESC, u.xp DESC
         LIMIT 100",
        [$snapshotDate]
    );
} else {
    // Sort by a users table column
    $allPlayers = $db->fetchAll(
        "SELECT
            ROW_NUMBER() OVER (ORDER BY u.`{$sort}` DESC) AS position,
            u.id AS user_id, u.username, u.class, u.`level`, u.xp, u.login_streak,
            ps.pct_return, ps.total_value_usd, ps.beats_index, ps.spx_pct_return,
            COALESCE(pv.wins, 0)   AS pvp_wins,
            COALESCE(pv.losses, 0) AS pvp_losses,
            COALESCE(pv.draws, 0)  AS pvp_draws
         FROM users u
         LEFT JOIN portfolio_snapshots ps ON ps.user_id = u.id AND ps.snapshot_date = ?
         LEFT JOIN pvp_stats pv ON pv.user_id = u.id
         WHERE u.is_banned = 0
         ORDER BY u.`{$sort}` DESC
         LIMIT 100",
        [$snapshotDate]
    );
}

// Find current user's position
$myPosition = null;
foreach ($allPlayers as $i => $row) {
    if ((int)$row['user_id'] === $userId) {
        $myPosition = $i + 1;
        break;
    }
}
if ($myPosition === null) $myPosition = '100+';

// Total active players
$totalPlayers = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM users WHERE is_banned = 0"
);

// Class distribution
$classStats = $db->fetchAll(
    "SELECT class, COUNT(*) AS cnt FROM users WHERE is_banned = 0
     GROUP BY class ORDER BY cnt DESC"
);

// Hall of fame — updated to use portfolio and adventure stats
$hallOfFame = [
    'pct_return'   => $db->fetchOne(
        "SELECT u.username, ps.pct_return AS val
         FROM portfolio_snapshots ps JOIN users u ON u.id = ps.user_id
         WHERE ps.snapshot_date = ? AND u.is_banned = 0
         ORDER BY ps.pct_return DESC LIMIT 1",
        [$latestSnapshotDate ?: date('Y-m-d')]
    ),
    'xp'           => $db->fetchOne(
        "SELECT username, xp AS val FROM users WHERE is_banned = 0 ORDER BY xp DESC LIMIT 1"
    ),
    'adventures'   => $db->fetchOne(
        "SELECT u.username, COUNT(*) AS val
         FROM adventure_log al JOIN users u ON u.id = al.user_id
         WHERE u.is_banned = 0
         GROUP BY al.user_id ORDER BY val DESC LIMIT 1"
    ),
    'login_streak' => $db->fetchOne(
        "SELECT username, login_streak AS val FROM users WHERE is_banned = 0
         ORDER BY login_streak DESC LIMIT 1"
    ),
    'pvp_wins'     => $db->fetchOne(
        "SELECT u.username, pv.wins AS val
         FROM pvp_stats pv JOIN users u ON u.id = pv.user_id
         WHERE u.is_banned = 0
         ORDER BY pv.wins DESC LIMIT 1"
    ),
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
            <p class="text-muted">
                <?= num($totalPlayers) ?> adventurer<?= $totalPlayers !== 1 ? 's' : '' ?> competing for glory
            </p>
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
            ['label' => 'Top Portfolio',  'icon' => '📈', 'key' => 'pct_return',   'fmt' => 'pct'],
            ['label' => 'Most XP',        'icon' => '⭐', 'key' => 'xp',           'fmt' => 'num'],
            ['label' => 'Most Adventures','icon' => '⚔',  'key' => 'adventures',   'fmt' => 'num'],
            ['label' => 'Most Devoted',   'icon' => '🔥', 'key' => 'login_streak', 'fmt' => 'days'],
            ['label' => 'PvP Champion',   'icon' => '🗡️', 'key' => 'pvp_wins',     'fmt' => 'wins'],
        ];
        foreach ($fameItems as $fi):
            $hof = $hallOfFame[$fi['key']] ?? null;
            if (!$hof) continue;
            $val = match($fi['fmt']) {
                'pct'   => (((float)$hof['val'] >= 0) ? '+' : '') . number_format((float)$hof['val'], 2) . '%',
                'money' => money((float)$hof['val']),
                'days'  => $hof['val'] . ' day' . ($hof['val'] != 1 ? 's' : ''),
                'wins'  => $hof['val'] . ' win' . ($hof['val'] != 1 ? 's' : ''),
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
        <a href="?sort=<?= $key ?>"
           class="sort-tab <?= $sort === $key ? 'active' : '' ?>">
            <?= $sl['icon'] ?> <?= $sl['label'] ?>
        </a>
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
                    <th class="col-score <?= $sort === 'pct_return' ? 'sorted' : '' ?>">
                        <a href="?sort=pct_return">📈 Portfolio</a>
                    </th>
                    <th class="col-xp <?= $sort === 'xp' ? 'sorted' : '' ?>">
                        <a href="?sort=xp">⭐ XP</a>
                    </th>
                    <th class="col-streak <?= $sort === 'login_streak' ? 'sorted' : '' ?>">
                        <a href="?sort=login_streak">🔥 Streak</a>
                    </th>
                    <th class="col-pvp <?= $sort === 'pvp_wins' ? 'sorted' : '' ?>">
                        <a href="?sort=pvp_wins">⚔ PvP</a>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allPlayers)): ?>
                <tr>
                    <td colspan="7" class="lb-empty">
                        No adventurers yet. Be the first legend!
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($allPlayers as $row):
                    $isMe  = ((int)$row['user_id'] === $userId);
                    $pos   = (int)$row['position'];
                    $ret   = $row['pct_return'];
                    $medal = match(true) {
                        $pos === 1 => '🥇',
                        $pos === 2 => '🥈',
                        $pos === 3 => '🥉',
                        default    => '#' . $pos,
                    };
                ?>
                <tr class="lb-row <?= $isMe ? 'lb-me' : '' ?> <?= $pos <= 3 ? 'lb-top3' : '' ?>">
                    <td class="col-rank"><span class="rank-val"><?= $medal ?></span></td>
                    <td class="col-player">
                        <span class="player-icon"><?= $classIcons[$row['class']] ?? '⚔️' ?></span>
                        <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($row['username']) ?>" class="player-name"><?= e($row['username']) ?></a>
                        <?php if ($isMe): ?><span class="you-tag">you</span><?php endif; ?>
                        <span class="player-class text-muted"><?= $classLabels[$row['class']] ?? '' ?></span>
                    </td>
                    <td class="col-level">
                        <span class="level-chip"><?= $row['level'] ?></span>
                    </td>
                    <td class="col-score <?= $sort === 'pct_return' ? 'sorted-col' : '' ?>">
                        <?php if ($ret !== null): ?>
                            <span class="<?= (float)$ret >= 0 ? 'text-green' : 'text-red' ?>">
                                <?= (float)$ret >= 0 ? '+' : '' ?><?= number_format((float)$ret, 2) ?>%
                            </span>
                            <?= $row['beats_index'] ? ' 🏆' : '' ?>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:0.78rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-xp <?= $sort === 'xp' ? 'sorted-col' : '' ?>">
                        <?= num($row['xp']) ?>
                    </td>
                    <td class="col-streak <?= $sort === 'login_streak' ? 'sorted-col' : '' ?>">
                        <?= $row['login_streak'] > 0 ? $row['login_streak'] . 'd' : '—' ?>
                    </td>
                    <td class="col-pvp <?= $sort === 'pvp_wins' ? 'sorted-col' : '' ?>">
                        <?php if ((int)$row['pvp_wins'] + (int)$row['pvp_losses'] + (int)$row['pvp_draws'] > 0): ?>
                            <span class="pvp-record">
                                <span style="color:var(--color-green)"><?= (int)$row['pvp_wins'] ?>W</span>
                                <span class="text-dim">·</span>
                                <span style="color:var(--color-red)"><?= (int)$row['pvp_losses'] ?>L</span>
                                <span class="text-dim">·</span>
                                <span style="color:#f59e0b"><?= (int)$row['pvp_draws'] ?>D</span>
                            </span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:0.78rem;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- CLASS DISTRIBUTION -->
    <div class="card class-dist">
        <h3 class="mb-3">⚔ Adventurer Classes</h3>
        <div class="class-dist-grid">
            <?php foreach ($classStats as $cs):
                $pct = $totalPlayers > 0
                    ? (int)(($cs['cnt'] / $totalPlayers) * 100) : 0;
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
