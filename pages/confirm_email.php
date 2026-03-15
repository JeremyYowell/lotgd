<?php
/**
 * pages/confirm_email.php — Email confirmation handler
 */
require_once __DIR__ . '/../bootstrap.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    Session::setFlash('error', 'Invalid confirmation link.');
    redirect('/pages/login.php');
}

// Look up token
$user = $db->fetchOne(
    "SELECT * FROM users
     WHERE confirm_token = ? AND email_confirmed = 0",
    [$token]
);

if (!$user) {
    // Could be already confirmed or invalid token
    $alreadyConfirmed = $db->fetchOne(
        "SELECT id FROM users WHERE confirm_token = ? AND email_confirmed = 1",
        [$token]
    );

    if ($alreadyConfirmed) {
        Session::setFlash('info', 'Your account is already confirmed. Please log in.');
    } else {
        Session::setFlash('error', 'This confirmation link is invalid or has already been used.');
    }
    redirect('/pages/login.php');
}

// Check expiry
if ($user['confirm_token_exp'] && strtotime($user['confirm_token_exp']) < time()) {
    $pageTitle = 'Link Expired';
    $bodyClass = 'page-auth';
    $mainClass = 'centered';
    ob_start();
    ?>
    <div class="auth-wrap">
        <div class="auth-crest">
            <span class="auth-crest-icon">⏳</span>
            <div class="auth-crest-title">Link Expired</div>
            <div class="auth-crest-sub">Your confirmation link has expired</div>
        </div>
        <div class="card card-gold" style="text-align:center">
            <p class="text-muted mb-3">
                Confirmation links expire after
                <?= $db->getSetting('email_confirm_token_hours', 48) ?> hours.
                Request a new one below.
            </p>
            <a href="<?= BASE_URL ?>/pages/resend_confirm.php"
               class="btn btn-primary btn-full">
                Resend Confirmation Email
            </a>
            <div class="mt-2">
                <a href="<?= BASE_URL ?>/pages/login.php" class="text-muted"
                   style="font-size:0.88rem">Back to login</a>
            </div>
        </div>
    </div>
    <?php
    $pageContent = ob_get_clean();
    require TPL_PATH . '/layout.php';
    exit;
}

// =========================================================================
// CONFIRM THE ACCOUNT
// =========================================================================
$xpReward = (int) $db->getSetting('email_confirm_xp_reward', 10);

$db->run(
    "UPDATE users
     SET email_confirmed   = 1,
         confirm_token     = NULL,
         confirm_token_exp = NULL
     WHERE id = ?",
    [$user['id']]
);

// Award XP
$userModel = new User();
$userModel->awardXp((int)$user['id'], $xpReward);

appLog('info', 'Email confirmed', ['user_id' => $user['id'], 'username' => $user['username']]);

// Log them in automatically if not already
if (!Session::isLoggedIn()) {
    $freshUser = $userModel->findById((int)$user['id']);
    Session::login($freshUser);
}

// =========================================================================
// RENDER SUCCESS
// =========================================================================
$pageTitle = 'Account Confirmed!';
$bodyClass = 'page-auth';
$mainClass = 'centered';

ob_start();
?>

<div class="auth-wrap">
    <div class="auth-crest">
        <span class="auth-crest-icon">🎉</span>
        <div class="auth-crest-title">Account Confirmed!</div>
        <div class="auth-crest-sub">Your legend begins</div>
    </div>

    <div class="card card-gold" style="text-align:center">
        <p style="font-size:1.1rem;margin-bottom:0.5rem">
            Welcome to the realm, <strong class="text-gold"><?= e($user['username']) ?></strong>!
        </p>
        <p class="text-muted mb-3">
            Your scroll address has been verified.
        </p>

        <div class="confirm-reward">
            <span class="reward-badge xp-badge" style="font-size:0.9rem;padding:0.4rem 1rem">
                +<?= $xpReward ?> XP — Email Confirmed!
            </span>
        </div>

        <div class="mt-3">
            <a href="<?= BASE_URL ?>/pages/dashboard.php" class="btn btn-primary btn-full">
                ⚔ Enter the Realm
            </a>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
