<?php
/**
 * templates/layout.php — Master page layout
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Legends of the Green Dollar') ?> — LotGD</title>
    <meta name="robots" content="<?= IS_DEV ? 'noindex,nofollow' : 'index,follow' ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel+Decorative:wght@700&family=Cinzel:wght@400;600&family=Crimson+Pro:ital,wght@0,300;0,400;0,600;1,300;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <?php foreach ($extraCss ?? [] as $cssFile): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/<?= e($cssFile) ?>">
    <?php endforeach; ?>
    <?= $extraHead ?? '' ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">

<nav class="site-nav">
    <a href="<?= BASE_URL ?>/index.php" class="nav-brand">
        <span class="nav-brand-icon">⚔</span>
        <span class="nav-brand-text">LotGD</span>
    </a>

    <button class="nav-hamburger" id="nav-hamburger" aria-label="Menu"
            onclick="document.querySelector('.site-nav').classList.toggle('nav-open')">
        <span></span><span></span><span></span>
    </button>

    <script>
    // Close hamburger menu on scroll
    window.addEventListener('scroll', function() {
        document.querySelector('.site-nav').classList.remove('nav-open');
    }, {passive: true});
    </script>

    <?php if (Session::isLoggedIn()): ?>
    <div class="nav-links">
        <a href="<?= BASE_URL ?>/pages/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/pages/adventure.php">Adventure</a>
        <a href="<?= BASE_URL ?>/pages/leaderboard.php">Leaderboard</a>
        <a href="<?= BASE_URL ?>/pages/tavern.php">Tavern</a>
        <a href="<?= BASE_URL ?>/pages/portfolio.php">Portfolio</a>
        <a href="<?= BASE_URL ?>/pages/store.php">Store</a>
        <a href="<?= BASE_URL ?>/pages/pvp.php">PvP</a>
        <?php if (Session::isAdmin()):
            // Alert badge: open bug reports + unhealthy crons
            try {
                $openBugs = (int) $db->fetchValue("SELECT COUNT(*) FROM bug_reports WHERE status = 'open'");
            } catch (Throwable $e) {
                $openBugs = 0; // table may not exist yet on this environment
            }
            $lastPrice   = $db->getSetting('portfolio_last_price_update', '');
            $cronStale   = $lastPrice && (time() - strtotime($lastPrice)) > 7200
                           && date('N') <= 5  // weekday only
                           && (int)date('G') >= 10 && (int)date('G') <= 18;
            $alertCount  = $openBugs + ($cronStale ? 1 : 0);
        ?>
        <a href="<?= BASE_URL ?>/admin/index.php" class="nav-admin">
            Admin<?php if ($alertCount > 0): ?><span class="nav-alert-badge"><?= $alertCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
    </div>
    <div class="nav-user">
        <span class="nav-username"><?= e(Session::username()) ?></span>
        <a href="<?= BASE_URL ?>/pages/logout.php" class="nav-logout">Logout</a>
    </div>
    <?php else: ?>
    <div class="nav-links">
        <a href="<?= BASE_URL ?>/pages/login.php">Login</a>
        <a href="<?= BASE_URL ?>/pages/register.php" class="nav-cta">Join the Realm</a>
    </div>
    <?php endif; ?>
</nav>

<main class="site-main <?= e($mainClass ?? '') ?>">
    <?= renderFlash() ?>
    <?= $pageContent ?? '' ?>
</main>

<footer class="site-footer">
    <?php if (IS_DEV): ?>
    <div style="background:#b91c1c;color:#fff;text-align:center;font-size:12px;
                font-family:monospace;padding:4px 0;margin-bottom:0.75rem;
                border-radius:4px;">
        ⚠ DEV ENVIRONMENT — DB: <?= DB_NAME ?>
    </div>
    <?php endif; ?>
    <p>
        Legends of the Green Dollar &nbsp;·&nbsp;
        <?= IS_DEV ? '<span class="env-tag">' . APP_ENV . '</span>' : '&copy; ' . date('Y') ?>
        &nbsp;·&nbsp; <a href="<?= BASE_URL ?>/pages/privacy.php" class="footer-privacy-link">Privacy Policy</a>
        <?php if (Session::isLoggedIn()): ?>
        &nbsp;·&nbsp; <a href="<?= BASE_URL ?>/pages/bug_report.php" class="footer-bug-link">🐛 Report a Bug</a>
        <?php endif; ?>
    </p>
</footer>

<?= $extraScripts ?? '' ?>
</body>
</html>
