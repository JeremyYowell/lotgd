<?php
/**
 * pages/forgot_password.php — Password reset request
 */
require_once __DIR__ . '/../bootstrap.php';

if (Session::isLoggedIn()) {
    redirect('/pages/dashboard.php');
}

$sent   = false;
$errors = [];

// =========================================================================
// HANDLE POST
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $identifier = trim($_POST['identifier'] ?? '');

    if (empty($identifier)) {
        $errors[] = 'Please enter your adventurer name or scroll address.';
    } else {
        // Look up by username OR email — never reveal which matched (security)
        $user = $db->fetchOne(
            "SELECT id, username, email, is_banned
             FROM users
             WHERE (username = ? OR email = ?) AND is_banned = 0
             LIMIT 1",
            [$identifier, $identifier]
        );

        if ($user) {
            // Delete any previous unused tokens for this user
            $db->run(
                "DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL",
                [$user['id']]
            );

            // Generate a new token, valid for 1 hour
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            $db->run(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
                [$user['id'], $token, $expiresAt]
            );

            $mailer = new Mailer();
            $mailer->sendPasswordReset($user['email'], $user['username'], $token);

            appLog('info', 'Password reset requested', [
                'user_id'  => $user['id'],
                'username' => $user['username'],
            ]);
        }

        // Always show the same message — don't reveal whether the account exists
        $sent = true;
    }
}

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Forgot Passphrase';
$bodyClass = 'page-auth';
$mainClass = 'centered';

ob_start();
?>

<div class="auth-wrap">

    <div class="auth-crest">
        <span class="auth-crest-icon">🔑</span>
        <div class="auth-crest-title">Lost Your Passphrase?</div>
        <div class="auth-crest-sub">The realm's scribes can help</div>
    </div>

    <div class="card card-gold">

        <?php if ($sent): ?>

            <div style="text-align:center;padding:0.5rem 0 1rem">
                <div style="font-size:2.5rem;margin-bottom:0.75rem">📜</div>
                <p style="font-size:1rem;margin-bottom:0.5rem">
                    If an account with that name or address exists, a
                    <strong class="text-gold">recovery scroll</strong> has been dispatched.
                </p>
                <p class="text-muted" style="font-size:0.88rem">
                    Check your inbox — the link expires in <strong>1 hour</strong>.
                    Check your spam folder if it doesn't arrive shortly.
                </p>
            </div>

            <div class="mt-3">
                <a href="<?= BASE_URL ?>/pages/login.php" class="btn btn-secondary btn-full">
                    ← Back to Login
                </a>
            </div>

        <?php else: ?>

            <?php foreach ($errors as $err): ?>
                <div class="flash-error"><?= e($err) ?></div>
            <?php endforeach; ?>

            <p class="text-muted mb-3" style="font-size:0.9rem">
                Enter your adventurer name or scroll address and we'll send you a
                link to reset your passphrase.
            </p>

            <form method="POST" novalidate>
                <?= Session::csrfField() ?>

                <div class="form-group">
                    <label for="identifier">Adventurer Name or Scroll Address</label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= e($_POST['identifier'] ?? '') ?>"
                        autocomplete="username email"
                        autofocus
                        required
                    >
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary btn-full">
                        🔑 Send Recovery Scroll
                    </button>
                </div>
            </form>

            <div class="auth-divider">or</div>

            <div class="text-center">
                <a href="<?= BASE_URL ?>/pages/login.php" class="btn btn-secondary btn-full">
                    ← Back to Login
                </a>
            </div>

        <?php endif; ?>

    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
