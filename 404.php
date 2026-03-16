<?php
/**
 * 404.php — Custom not found page
 * Also handles expired session redirects gracefully.
 */
require_once __DIR__ . '/bootstrap.php';

http_response_code(404);

// If they're not logged in, send straight to login
if (!Session::isLoggedIn()) {
    Session::setFlash('info', 'Your session has expired. Please log in again.');
    redirect('/pages/login.php');
}

// Logged-in user hit a missing page — send to dashboard
$pageTitle = 'Page Not Found';
$bodyClass = 'page-error';

ob_start();
?>

<div style="text-align:center;padding:5rem 1rem;max-width:520px;margin:0 auto">
    <div style="font-size:4rem;margin-bottom:1rem">🗺️</div>
    <h1 style="color:var(--color-gold-light);margin-bottom:0.75rem">Lost in the Realm</h1>
    <p class="text-muted" style="margin-bottom:2rem">
        The page you were looking for doesn't exist or has moved.
        Your quest continues elsewhere.
    </p>
    <a href="<?= BASE_URL ?>/pages/dashboard.php" class="btn btn-primary">
        ⚔ Return to Dashboard
    </a>
</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
