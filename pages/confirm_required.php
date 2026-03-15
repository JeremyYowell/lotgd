<?php
/**
 * pages/confirm_required.php
 * Shown to logged-in users who have not yet confirmed their email.
 * All protected pages redirect here until confirmation is complete.
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userModel = new User();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

// If already confirmed, go to dashboard
if ($user['email_confirmed']) {
    redirect('/pages/dashboard.php');
}

$resent  = false;
$error   = '';

// =========================================================================
// HANDLE RESEND REQUEST
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    Session::verifyCsrfPost();

    // Throttle: only allow resend once per 5 minutes
    $lastSent = Session::get('confirm_last_resend', 0);
    if (time() - $lastSent < 300) {
        $wait  = 300 - (time() - $lastSent);
        $error = "Please wait {$wait} more second(s) before requesting another email.";
    } else {
        // Generate fresh token
        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + ((int)$db->getSetting('email_confirm_token_hours', 48) * 3600));

        $db->run(
            "UPDATE users SET confirm_token = ?, confirm_token_exp = ? WHERE id = ?",
            [$token, $expiry, $userId]
        );

        $mailer = new Mailer();
        $sent   = $mailer->sendConfirmationResend($user['email'], $user['username'], $token);

        if ($sent) {
            Session::set('confirm_last_resend', time());
            $resent = true;
            appLog('info', 'Confirmation email resent', ['user_id' => $userId]);
        } else {
            $error = 'Failed to send email. Please try again shortly.';
        }
    }
}

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Confirm Your Email';
$bodyClass = 'page-auth';
$mainClass = 'centered';

ob_start();
?>

<div class="auth-wrap">

    <div class="auth-crest">
        <span class="auth-crest-icon">📜</span>
        <div class="auth-crest-title">Confirm Your<br>Scroll Address</div>
        <div class="auth-crest-sub">One step before your legend begins</div>
    </div>

    <div class="card card-gold">

        <?php if ($resent): ?>
            <div class="flash-success">
                ✓ A new confirmation scroll has been sent to <strong><?= e($user['email']) ?></strong>.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <p style="text-align:center;margin-bottom:1rem">
            We sent a confirmation link to:<br>
            <strong class="text-gold"><?= e($user['email']) ?></strong>
        </p>

        <p class="text-muted" style="text-align:center;font-size:0.9rem;margin-bottom:1.5rem">
            Click the link in that email to verify your account and
            earn <strong class="text-gold">+<?= $db->getSetting('email_confirm_xp_reward', 10) ?> XP</strong>.
            Check your spam folder if you don't see it.
        </p>

        <form method="POST">
            <?= Session::csrfField() ?>
            <button type="submit" name="resend" value="1"
                    class="btn btn-secondary btn-full">
                Resend Confirmation Email
            </button>
        </form>

        <div class="auth-divider">or</div>

        <div style="text-align:center;font-size:0.88rem">
            <a href="<?= BASE_URL ?>/pages/logout.php" class="text-muted">
                Log out and use a different account
            </a>
        </div>

    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
