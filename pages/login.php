<?php
/**
 * pages/login.php
 */
require_once __DIR__ . '/../bootstrap.php';

// Already logged in — go to dashboard
if (Session::isLoggedIn()) {
    redirect('/pages/dashboard.php');
}

$errors = [];

// =========================================================================
// HANDLE POST
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username)) $errors[] = 'Please enter your username.';
    if (empty($password)) $errors[] = 'Please enter your password.';

    if (empty($errors)) {
        // Check for account lockout
        $lockoutKey  = 'login_attempts_' . md5($username);
        $attempts    = (int) Session::get($lockoutKey . '_count', 0);
        $lockedUntil = (int) Session::get($lockoutKey . '_until', 0);

        if ($lockedUntil > time()) {
            $waitMins = ceil(($lockedUntil - time()) / 60);
            $errors[] = "Too many failed attempts. Try again in {$waitMins} minute(s).";
        } else {
            $userModel = new User();
            $user      = $userModel->attemptLogin($username, $password);

            if ($user) {
                // Success — clear lockout, start session
                Session::delete($lockoutKey . '_count');
                Session::delete($lockoutKey . '_until');

                Session::login($user);
                Session::setFlash('success', 'Welcome back, ' . $user['username'] . '! The realm awaits.');
                redirect('/pages/dashboard.php');
            } else {
                // Failed — increment lockout counter
                $attempts++;
                Session::set($lockoutKey . '_count', $attempts);

                $remaining = MAX_LOGIN_ATTEMPTS - $attempts;

                if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                    Session::set($lockoutKey . '_until', time() + (LOGIN_LOCKOUT_MINUTES * 60));
                    $errors[] = 'Too many failed attempts. Your account is locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
                } else {
                    $errors[] = 'Invalid username or password. ' . $remaining . ' attempt(s) remaining.';
                }
            }
        }
    }
}

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Enter the Realm';
$bodyClass = 'page-auth';
$mainClass = 'centered';

ob_start();
?>

<div class="auth-wrap">

    <div class="auth-crest">
        <span class="auth-crest-icon">⚔️</span>
        <div class="auth-crest-title">Legends of the<br>Green Dollar</div>
        <div class="auth-crest-sub">Enter the Realm</div>
    </div>

    <div class="card card-gold">

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <div class="flash-error"><?= e($err) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <?= Session::csrfField() ?>

            <div class="form-group">
                <label for="username">Adventurer Name</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= e($_POST['username'] ?? '') ?>"
                    autocomplete="username"
                    autofocus
                    required
                    maxlength="50"
                >
            </div>

            <div class="form-group">
                <label for="password">Secret Passphrase</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-full">
                    ⚔ Enter the Realm
                </button>
            </div>

            <div class="text-center mt-2">
                <a href="<?= BASE_URL ?>/pages/forgot_password.php"
                   style="font-size:0.82rem;color:var(--color-text-muted)">
                    Forgot your passphrase?
                </a>
            </div>
        </form>

        <div class="auth-divider">or</div>

        <div class="text-center">
            <a href="<?= BASE_URL ?>/pages/register.php" class="btn btn-secondary btn-full">
                Begin Your Quest (Register)
            </a>
        </div>

    </div>

    <div class="auth-footer-link">
        New to the realm?
        <a href="<?= BASE_URL ?>/pages/register.php">Create your character</a>
    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
