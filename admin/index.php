<?php
/**
 * admin/index.php — Admin Panel Dashboard
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

// Quick stats
$stats = [
    'total_users'    => (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE is_banned = 0"),
    'unconfirmed'    => (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE email_confirmed = 0"),
    'banned'         => (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE is_banned = 1"),
    'total_entries'  => (int) $db->fetchValue("SELECT COUNT(*) FROM financial_entries"),
    'adventures'     => (int) $db->fetchValue("SELECT COUNT(*) FROM adventure_log"),
    'tavern_posts'   => (int) $db->fetchValue("SELECT COUNT(*) FROM tavern_messages WHERE is_deleted = 0"),
    'active_stocks'  => (int) $db->fetchValue("SELECT COUNT(*) FROM stocks WHERE is_active = 1"),
    'open_bugs'      => (int) $db->fetchValue("SELECT COUNT(*) FROM bug_reports WHERE status = 'open'"),
    'last_price_run' => $db->getSetting('portfolio_last_price_update', 'Never'),
    'last_sp500_run' => $db->getSetting('portfolio_last_sp500_update', 'Never'),
    'last_tsx60_run' => $db->getSetting('portfolio_last_tsx60_update', 'Never'),
];

// Cron health checks
$cronAlerts = [];
if ($stats['last_price_run'] !== 'Never') {
    $age = time() - strtotime($stats['last_price_run']);
    if ($age > 7200 && date('N') <= 5 && (int)date('G') >= 10 && (int)date('G') <= 18) {
        $cronAlerts[] = '⚠ Price update is over 2 hours old during market hours.';
    }
}
if ($stats['last_sp500_run'] !== 'Never') {
    if ((time() - strtotime($stats['last_sp500_run'])) > 100 * 86400) {
        $cronAlerts[] = '⚠ S&P 500 constituent update is over 100 days old.';
    }
} elseif ($stats['last_sp500_run'] === 'Never') {
    $cronAlerts[] = '⚠ S&P 500 constituent update has never run.';
}

$pageTitle = 'Admin Panel';
$bodyClass = 'page-admin';
$extraCss  = ['admin.css'];

ob_start();
?>

<div class="admin-wrap">
    <div class="admin-header">
        <h1>🛡 Admin Panel</h1>
        <span class="env-tag"><?= APP_ENV ?> — <?= DB_NAME ?></span>
    </div>

    <!-- STAT STRIP -->
    <div class="admin-stat-grid">
        <div class="admin-stat"><span class="as-val"><?= num($stats['total_users']) ?></span><span class="as-label">Active Users</span></div>
        <div class="admin-stat"><span class="as-val text-red"><?= $stats['unconfirmed'] ?></span><span class="as-label">Unconfirmed</span></div>
        <div class="admin-stat"><span class="as-val text-red"><?= $stats['banned'] ?></span><span class="as-label">Banned</span></div>
        <div class="admin-stat"><span class="as-val"><?= num($stats['total_entries']) ?></span><span class="as-label">Log Entries</span></div>
        <div class="admin-stat"><span class="as-val"><?= num($stats['adventures']) ?></span><span class="as-label">Adventures</span></div>
        <div class="admin-stat"><span class="as-val"><?= $stats['active_stocks'] ?></span><span class="as-label">S&P Stocks</span></div>
    </div>

    <!-- NAV GRID -->
    <div class="admin-nav-grid">
        <a href="<?= BASE_URL ?>/admin/users.php" class="admin-nav-card">
            <span class="anc-icon">👤</span>
            <span class="anc-title">User Management</span>
            <span class="anc-desc">View, ban, confirm, delete users</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/settings.php" class="admin-nav-card">
            <span class="anc-icon">⚙</span>
            <span class="anc-title">Settings Editor</span>
            <span class="anc-desc">Edit all game settings in-browser</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/adventures.php" class="admin-nav-card">
            <span class="anc-icon">⚔</span>
            <span class="anc-title">Adventure Manager</span>
            <span class="anc-desc">Add, edit, disable scenarios</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/audio.php" class="admin-nav-card">
            <span class="anc-icon">🔊</span>
            <span class="anc-title">Audio Manager</span>
            <span class="anc-desc">Generate ElevenLabs voice audio</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/cron.php" class="admin-nav-card">
            <span class="anc-icon">🕐</span>
            <span class="anc-title">Cron Health</span>
            <span class="anc-desc">Last run times, log tails</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/bug_reports.php" class="admin-nav-card <?= $stats['open_bugs'] > 0 ? 'anc-alert' : '' ?>">
            <span class="anc-icon">🐛<?= $stats['open_bugs'] > 0 ? '<span class="anc-badge">' . $stats['open_bugs'] . '</span>' : '' ?></span>
            <span class="anc-title">Bug Reports</span>
            <span class="anc-desc"><?= $stats['open_bugs'] > 0 ? $stats['open_bugs'] . ' open report' . ($stats['open_bugs'] !== 1 ? 's' : '') : 'No open reports' ?></span>
        </a>
    </div>

    <?php if (!empty($cronAlerts)): ?>
    <div class="card" style="border-color:#7f1d1d;background:rgba(127,29,29,0.12);margin-bottom:1.25rem;padding:1rem 1.25rem">
        <h3 style="color:#fca5a5;margin-bottom:0.65rem;font-size:0.9rem">⚠ Cron Alerts</h3>
        <?php foreach ($cronAlerts as $alert): ?>
        <div style="font-size:0.88rem;color:#fca5a5;margin-bottom:0.25rem"><?= e($alert) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- CRON STATUS QUICK VIEW -->
    <div class="card admin-cron-quick">
        <h3 class="mb-2">🕐 Cron Status</h3>
        <div class="cron-quick-row">
            <span class="text-muted">Last price update:</span>
            <strong><?= $stats['last_price_run'] === 'Never' ? '<span class="text-red">Never</span>' : e($stats['last_price_run']) ?></strong>
        </div>
        <div class="cron-quick-row">
            <span class="text-muted">Last S&P 500 update:</span>
            <strong><?= $stats['last_sp500_run'] === 'Never' ? '<span class="text-red">Never</span>' : e($stats['last_sp500_run']) ?></strong>
        </div>
        <div class="cron-quick-row">
            <span class="text-muted">Last TSX 60 update:</span>
            <strong><?= $stats['last_tsx60_run'] === 'Never' ? '<span class="text-red">Never</span>' : e($stats['last_tsx60_run']) ?></strong>
        </div>
    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
