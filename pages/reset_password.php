<?php
/**
 * pages/reset_password.php — Password reset via emailed token
 */
require_once __DIR__ . '/../bootstrap.php';

if (Session::isLoggedIn()) {
    redirect('/pages/dashboard.php');
}

$token  = trim($_GET['token'] ?? trim($_POST['token'] ?? ''));
$errors = [];

// =========================================================================
// VALIDATE TOKEN — look up before rendering anything
// =========================================================================
$reset = null;
if (!empty($token)) {
    $reset = $db->fetchOne(
        "SELECT pr.id, pr.user_id, pr.expires_at, u.username, u.email
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = ? AND pr.used_at IS NULL AND u.is_banned = 0",
        [$token]
    );
}

// ---- Invalid / already-used token ----
if (empty($token) || !$reset) {
    $pageTitle = 'Invalid Recovery Link';
    $bodyClass = 'page-auth';
    $mainClass = 'centered';
    ob_start();
    ?>
    <div class="auth-wrap">
        <div class="auth-crest">
            <span class="auth-crest-icon">🚫</span>
            <div class="auth-crest-title">Invalid Link</div>
            <div class="auth-crest-sub">This recovery scroll is invalid or has already been used</div>
        </div>
        <div class="card card-gold" style="text-align:center">
            <p class="text-muted mb-3">
                Recovery links can only be used once and expire after 1 hour.
            </p>
            <a href="<?= BASE_URL ?>/pages/forgot_password.php"
               class="btn btn-primary btn-full">
                🔑 Request a New Link
            </a>
            <div class="mt-2">
                <a href="<?= BASE_URL ?>/pages/login.php"
                   class="text-muted" style="font-size:0.88rem">
                    Back to login
                </a>
            </div>
        </div>
    </div>
    <?php
    $pageContent = ob_get_clean();
    require TPL_PATH . '/layout.php';
    exit;
}

// ---- Expired token ----
if (strtotime($reset['expires_at']) < time()) {
    $db->run("DELETE FROM password_resets WHERE token = ?", [$token]);

    $pageTitle = 'Recovery Link Expired';
    $bodyClass = 'page-auth';
    $mainClass = 'centered';
    ob_start();
    ?>
    <div class="auth-wrap">
        <div class="auth-crest">
            <span class="auth-crest-icon">⏳</span>
            <div class="auth-crest-title">Link Expired</div>
            <div class="auth-crest-sub">This recovery scroll has expired</div>
        </div>
        <div class="card card-gold" style="text-align:center">
            <p class="text-muted mb-3">
                Recovery links expire after 1 hour. Request a fresh one below.
            </p>
            <a href="<?= BASE_URL ?>/pages/forgot_password.php"
               class="btn btn-primary btn-full">
                🔑 Request a New Link
            </a>
            <div class="mt-2">
                <a href="<?= BASE_URL ?>/pages/login.php"
                   class="text-muted" style="font-size:0.88rem">
                    Back to login
                </a>
            </div>
        </div>
    </div>
    <?php
    $pageContent = ob_get_clean();
    require TPL_PATH . '/layout.php';
    exit;
}

// =========================================================================
// HANDLE POST — set new password
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Passphrase must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if ($password !== $password2) {
        $errors[] = 'Passphrases do not match.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $db->run(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$hash, $reset['user_id']]
        );

        // Mark the token used so it can't be replayed
        $db->run(
            "UPDATE password_resets SET used_at = NOW() WHERE token = ?",
            [$token]
        );

        appLog('info', 'Password reset completed', [
            'user_id'  => $reset['user_id'],
            'username' => $reset['username'],
        ]);

        Session::setFlash('success',
            'Your passphrase has been reset, ' . $reset['username'] . '. Welcome back!'
        );
        redirect('/pages/login.php');
    }
}

// =========================================================================
// RENDER — password entry form
// =========================================================================
$pageTitle = 'Reset Your Passphrase';
$bodyClass = 'page-auth';
$mainClass = 'centered';

ob_start();
?>

<div class="auth-wrap">

    <div class="auth-crest">
        <span class="auth-crest-icon">🔑</span>
        <div class="auth-crest-title">Reset Your Passphrase</div>
        <div class="auth-crest-sub">
            Choose a new secret for <strong><?= e($reset['username']) ?></strong>
        </div>
    </div>

    <div class="card card-gold">

        <?php foreach ($errors as $err): ?>
            <div class="flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <p class="text-muted mb-3" style="font-size:0.9rem">
            Choose a new passphrase. Must be at least <?= PASSWORD_MIN_LENGTH ?> characters.
        </p>

        <form method="POST" novalidate>
            <?= Session::csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="form-group">
                <label for="password">New Passphrase</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    autofocus
                    required
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                >
            </div>

            <div class="form-group">
                <label for="password2">Confirm New Passphrase</label>
                <input
                    type="password"
                    id="password2"
                    name="password2"
                    autocomplete="new-password"
                    required
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                >
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-full">
                    🔑 Set New Passphrase
                </button>
            </div>
        </form>

    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
