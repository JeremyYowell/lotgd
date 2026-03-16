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

<nav class="site-nav" id="site-nav">
    <a href="<?= BASE_URL ?>/index.php" class="nav-brand">
        <span class="nav-brand-icon">⚔</span>
        <span class="nav-brand-text">LotGD</span>
    </a>

    <?php if (Session::isLoggedIn()): ?>

    <!-- Hamburger button — visible on mobile only -->
    <button class="nav-hamburger" id="nav-hamburger"
            aria-label="Toggle navigation" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>

    <!-- Nav links + user — collapses on mobile -->
    <div class="nav-collapse" id="nav-collapse">
        <div class="nav-links">
            <a href="<?= BASE_URL ?>/pages/dashboard.php">Dashboard</a>
            <a href="<?= BASE_URL ?>/pages/adventure.php">Adventure</a>
            <a href="<?= BASE_URL ?>/pages/leaderboard.php">Leaderboard</a>
            <a href="<?= BASE_URL ?>/pages/tavern.php">Tavern</a>
            <a href="<?= BASE_URL ?>/pages/portfolio.php">Portfolio</a>
            <?php if (Session::isAdmin()): ?>
            <a href="<?= BASE_URL ?>/admin/index.php" class="nav-admin">Admin</a>
            <?php endif; ?>
        </div>
        <div class="nav-user">
            <span class="nav-username"><?= e(Session::username()) ?></span>
            <a href="<?= BASE_URL ?>/pages/logout.php" class="nav-logout">Logout</a>
        </div>
    </div>

    <?php else: ?>

    <!-- Guest nav — only 2 links, no hamburger needed, but wrap for consistency -->
    <div class="nav-collapse" id="nav-collapse">
        <div class="nav-links">
            <a href="<?= BASE_URL ?>/pages/login.php">Login</a>
            <a href="<?= BASE_URL ?>/pages/register.php" class="nav-cta">Join the Realm</a>
        </div>
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
    </p>
</footer>

<?= $extraScripts ?? '' ?>

<script>
// Mobile nav toggle
const hamburger = document.getElementById('nav-hamburger');
const collapse  = document.getElementById('nav-collapse');

if (hamburger && collapse) {
    hamburger.addEventListener('click', () => {
        const open = collapse.classList.toggle('nav-open');
        hamburger.classList.toggle('is-open', open);
        hamburger.setAttribute('aria-expanded', open);
    });

    // Close menu when a link is tapped
    collapse.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            collapse.classList.remove('nav-open');
            hamburger.classList.remove('is-open');
            hamburger.setAttribute('aria-expanded', 'false');
        });
    });

    // Close menu when tapping outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#site-nav')) {
            collapse.classList.remove('nav-open');
            hamburger.classList.remove('is-open');
            hamburger.setAttribute('aria-expanded', 'false');
        }
    });
}
</script>

</body>
</html>
