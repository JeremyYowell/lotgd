<?php
/**
 * pages/register.php
 */
require_once __DIR__ . '/../bootstrap.php';

if (Session::isLoggedIn()) {
    redirect('/pages/dashboard.php');
}

if (!(bool) $db->getSetting('registration_open', '1')) {
    Session::setFlash('error', 'The realm is not accepting new adventurers at this time.');
    redirect('/pages/login.php');
}

$errors = [];

$classes = [
    'investor'    => ['icon' => '📈', 'name' => 'The Investor',    'desc' => 'Bonus XP for every dollar invested'],
    'debt_slayer' => ['icon' => '🗡️',  'name' => 'Debt Slayer',    'desc' => 'Bonus XP for every dollar of debt paid'],
    'saver'       => ['icon' => '🏦', 'name' => 'The Saver',       'desc' => 'Bonus XP for building savings'],
    'entrepreneur'=> ['icon' => '🚀', 'name' => 'Entrepreneur',    'desc' => 'Bonus XP for new income sources'],
    'minimalist'  => ['icon' => '🧘', 'name' => 'The Minimalist',  'desc' => 'Bonus XP for cutting expenses'],
];

// =========================================================================
// HANDLE POST
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email']    ?? '');
    $password  = $_POST['password']      ?? '';
    $password2 = $_POST['password2']     ?? '';
    $class     = $_POST['class']         ?? 'saver';

    if (empty($username))  $errors[] = 'Adventurer name is required.';
    if (empty($email))     $errors[] = 'Email address is required.';
    if (empty($password))  $errors[] = 'A passphrase is required.';

    if (!empty($password) && !empty($password2) && $password !== $password2) {
        $errors[] = 'Passphrases do not match.';
    }

    if (!array_key_exists($class, $classes)) {
        $errors[] = 'Please choose a valid adventurer class.';
    }

    if (empty($errors)) {
        $userModel = new User();
        $result    = $userModel->register($username, $email, $password, $class);

        if ($result['success']) {
            // Log them in but the confirmation gate in bootstrap.php will
            // immediately redirect to confirm_required.php
            $newUser = $userModel->findById($result['user_id']);
            Session::login($newUser);
            Session::set('email_confirmed', false);  // pre-set gate cache

            Session::setFlash('info',
                'Welcome, ' . $username . '! A confirmation link has been sent to '
                . $email . '. Please check your inbox to activate your account.'
            );
            redirect('/pages/confirm_required.php');
        } else {
            $errors[] = $result['error'];
        }
    }
}

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Create Your Character';
$bodyClass = 'page-auth page-register';
$mainClass = 'centered';

$posted = [
    'username' => $_POST['username'] ?? '',
    'email'    => $_POST['email']    ?? '',
    'class'    => $_POST['class']    ?? 'saver',
];

ob_start();
?>

<div class="auth-wrap" style="max-width:520px;">

    <div class="auth-crest">
        <span class="auth-crest-icon">🏰</span>
        <div class="auth-crest-title">Create Your Character</div>
        <div class="auth-crest-sub">Begin your financial legend</div>
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
                <input type="text" id="username" name="username"
                       value="<?= e($posted['username']) ?>"
                       autocomplete="username" autofocus required
                       minlength="3" maxlength="50">
                <div class="form-hint">3–50 characters. Your public display name.</div>
            </div>

            <div class="form-group">
                <label for="email">Scroll Address (Email)</label>
                <input type="email" id="email" name="email"
                       value="<?= e($posted['email']) ?>"
                       autocomplete="email" required maxlength="255">
                <div class="form-hint">Used for account confirmation and recovery.</div>
            </div>

            <div class="form-group">
                <label for="password">Secret Passphrase</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required
                       minlength="<?= PASSWORD_MIN_LENGTH ?>">
                <div class="form-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters.</div>
            </div>

            <div class="form-group">
                <label for="password2">Confirm Passphrase</label>
                <input type="password" id="password2" name="password2"
                       autocomplete="new-password" required>
            </div>

            <div class="form-group">
                <label>Choose Your Class</label>
                <div class="class-grid">
                    <?php foreach ($classes as $key => $cls): ?>
                    <div class="class-option">
                        <input type="radio" id="class_<?= $key ?>" name="class"
                               value="<?= $key ?>"
                               <?= ($posted['class'] === $key) ? 'checked' : '' ?>>
                        <label for="class_<?= $key ?>">
                            <span class="class-icon"><?= $cls['icon'] ?></span>
                            <span class="class-name"><?= e($cls['name']) ?></span>
                            <span class="class-desc"><?= e($cls['desc']) ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-full">
                    🏰 Create My Character
                </button>
            </div>
        </form>
    </div>

    <div class="auth-footer-link">
        Already have a character?
        <a href="<?= BASE_URL ?>/pages/login.php">Login here</a>
    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
