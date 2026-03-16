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
    'adventures'     => (int) $db->fetchValue("SELECT COUNT(*) FROM adventure_log"),
    'tavern_posts'   => (int) $db->fetchValue("SELECT COUNT(*) FROM tavern_messages WHERE is_deleted = 0"),
    'active_stocks'  => (int) $db->fetchValue("SELECT COUNT(*) FROM stocks WHERE is_active = 1"),
    'last_brief_date'=> $db->getSetting('daily_brief_date',            'Never'),
    'last_price_run' => $db->getSetting('portfolio_last_price_update', 'Never'),
    'last_sp500_run' => $db->getSetting('portfolio_last_sp500_update', 'Never'),
];

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
        <div class="admin-stat"><span class="as-val"><?= num($stats['adventures']) ?></span><span class="as-label">Adventures</span></div>
        <div class="admin-stat"><span class="as-val"><?= num($stats['tavern_posts']) ?></span><span class="as-label">Tavern Posts</span></div>
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
        <a href="<?= BASE_URL ?>/admin/cron.php" class="admin-nav-card">
            <span class="anc-icon">🕐</span>
            <span class="anc-title">Cron Health</span>
            <span class="anc-desc">Last run times, log tails</span>
        </a>
    </div>

    <!-- CRON STATUS QUICK VIEW -->
    <div class="card admin-cron-quick">
        <h3 class="mb-2">🕐 Cron Status</h3>
        <div class="cron-quick-row">
            <span class="text-muted">Last price update:</span>
            <strong><?= $stats['last_price_run'] === 'Never'
                ? '<span class="text-red">Never</span>'
                : e($stats['last_price_run']) ?></strong>
        </div>
        <div class="cron-quick-row">
            <span class="text-muted">Daily brief generated:</span>
            <strong><?= $stats['last_brief_date'] === 'Never'
                ? '<span class="text-red">Never</span>'
                : e($stats['last_brief_date']) ?></strong>
        </div>
        <div class="cron-quick-row">
            <span class="text-muted">Last S&P 500 update:</span>
            <strong><?= $stats['last_sp500_run'] === 'Never'
                ? '<span class="text-red">Never</span>'
                : e($stats['last_sp500_run']) ?></strong>
        </div>
    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
