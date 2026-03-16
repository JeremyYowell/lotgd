<?php
/**
 * 404.php — Custom not found page
 * Auto-redirects after 5 seconds.
 */
require_once __DIR__ . '/bootstrap.php';

http_response_code(404);

$destination = Session::isLoggedIn()
    ? BASE_URL . '/pages/dashboard.php'
    : BASE_URL . '/pages/login.php';

if (!Session::isLoggedIn()) {
    Session::setFlash('info', 'Your session has expired. Please log in again.');
}

$pageTitle = 'Page Not Found';
$bodyClass = 'page-error';
$extraHead = '<meta http-equiv="refresh" content="5;url=' . $destination . '">';

ob_start();
?>

<div style="text-align:center;padding:5rem 1rem;max-width:520px;margin:0 auto">
    <div style="font-size:4rem;margin-bottom:1rem">🗺️</div>
    <h1 style="color:var(--color-gold-light);margin-bottom:0.75rem">Lost in the Realm</h1>
    <p class="text-muted" style="margin-bottom:0.75rem">
        The page you were looking for doesn't exist or has moved.
    </p>
    <p class="text-muted" style="margin-bottom:2rem;font-size:0.9rem">
        Redirecting in <strong id="countdown" class="text-gold">5</strong> seconds…
    </p>
    <a href="<?= $destination ?>" class="btn btn-primary">
        <?= Session::isLoggedIn() ? '⚔ Return to Dashboard' : '⚔ Go to Login' ?>
    </a>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '<script>
let n = 5;
const el = document.getElementById("countdown");
const t = setInterval(() => {
    n--;
    if (el) el.textContent = n;
    if (n <= 0) {
        clearInterval(t);
        window.location = "' . $destination . '";
    }
}, 1000);
</script>';

require TPL_PATH . '/layout.php';
?>
