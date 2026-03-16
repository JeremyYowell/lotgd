<?php
/**
 * setup/index.php — Legends of the Green Dollar Installation Wizard
 * =================================================================
 * Self-contained: does NOT require bootstrap.php or any lib/ class.
 * All logic is inline so it works before the app is configured.
 *
 * Security: deletes the entire setup/ directory on completion.
 * If setup/ still exists after installation, anyone can re-run it.
 */

define('SETUP_VERSION', '1.0');
define('ROOT',     dirname(__DIR__));
define('MIN_PHP',  '8.1.0');

session_name('lotgd_setup');
session_start();

// =========================================================================
// LOCKFILE CHECK — if app is already installed, refuse to run
// =========================================================================
if (file_exists(ROOT . '/config/config.php')) {
    // config.php already exists — app may already be configured.
    // Only block if it contains a real DB host (not the placeholder).
    $cfg = file_get_contents(ROOT . '/config/config.php');
    if (strpos($cfg, 'your-db-hostname') === false) {
        die(renderLocked());
    }
}

// =========================================================================
// STEP DEFINITIONS
// =========================================================================
$steps = [
    1 => 'Requirements',
    2 => 'Database',
    3 => 'Application',
    4 => 'API Keys',
    5 => 'Admin Account',
    6 => 'File Setup',
    7 => 'Complete',
];

$step  = max(1, min(7, (int)($_GET['step'] ?? $_SESSION['setup_step'] ?? 1)));
$error = '';
$info  = '';

// =========================================================================
// POST HANDLERS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // -----------------------------------------------------------------------
    // STEP 2: Test + save DB credentials
    // -----------------------------------------------------------------------
    if ($action === 'save_db') {
        $host    = trim($_POST['db_host'] ?? '');
        $user    = trim($_POST['db_user'] ?? '');
        $pass    = $_POST['db_pass'] ?? '';
        $dbname  = trim($_POST['db_name'] ?? '');
        $charset = 'utf8mb4';

        try {
            $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Test it with a real query
            $pdo->query("SELECT 1");

            $_SESSION['db_host']   = $host;
            $_SESSION['db_user']   = $user;
            $_SESSION['db_pass']   = $pass;
            $_SESSION['db_name']   = $dbname;
            $_SESSION['setup_step'] = 3;
            redirect(3);

        } catch (PDOException $e) {
            $error = 'Connection failed: ' . $e->getMessage();
        }
    }

    // -----------------------------------------------------------------------
    // STEP 3: Save app settings
    // -----------------------------------------------------------------------
    if ($action === 'save_app') {
        $baseUrl  = rtrim(trim($_POST['base_url'] ?? ''), '/');
        $timezone = trim($_POST['timezone'] ?? 'America/Chicago');
        $env      = in_array($_POST['env'] ?? 'dev', ['dev', 'prod']) ? $_POST['env'] : 'dev';

        if (empty($baseUrl)) {
            $error = 'Base URL is required.';
        } else {
            $_SESSION['base_url']   = $baseUrl;
            $_SESSION['timezone']   = $timezone;
            $_SESSION['env']        = $env;
            $_SESSION['setup_step'] = 4;
            redirect(4);
        }
    }

    // -----------------------------------------------------------------------
    // STEP 4: Save API keys
    // -----------------------------------------------------------------------
    if ($action === 'save_keys') {
        $finnhub   = trim($_POST['finnhub_key']    ?? '');
        $anthropic = trim($_POST['anthropic_key']  ?? '');
        $fromEmail = trim($_POST['from_email']     ?? '');

        if (empty($fromEmail)) {
            $error = 'Email from address is required.';
        } else {
            $_SESSION['finnhub_key']   = $finnhub;
            $_SESSION['anthropic_key'] = $anthropic;
            $_SESSION['from_email']    = $fromEmail;
            $_SESSION['setup_step']    = 5;
            redirect(5);
        }
    }

    // -----------------------------------------------------------------------
    // STEP 5: Create admin account (in-memory, written in step 6)
    // -----------------------------------------------------------------------
    if ($action === 'save_admin') {
        $username = trim($_POST['admin_username'] ?? '');
        $email    = trim($_POST['admin_email']    ?? '');
        $pass1    = $_POST['admin_pass']  ?? '';
        $pass2    = $_POST['admin_pass2'] ?? '';

        if (empty($username) || strlen($username) < 3) {
            $error = 'Username must be at least 3 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($pass1) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_email']    = $email;
            $_SESSION['admin_pass']     = $pass1;
            $_SESSION['setup_step']     = 6;
            redirect(6);
        }
    }

    // -----------------------------------------------------------------------
    // STEP 6: Write files, run SQL, create admin user, self-destruct
    // -----------------------------------------------------------------------
    if ($action === 'install') {
        $results = runInstallation();
        if ($results['success']) {
            $_SESSION['install_results'] = $results;
            $_SESSION['setup_step']      = 7;
            redirect(7);
        } else {
            $error = $results['error'];
        }
    }
}

// =========================================================================
// RENDER
// =========================================================================
$stepTitles = $steps;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LotGD Setup Wizard</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: Georgia, 'Times New Roman', serif;
    background: #0a0d14;
    color: #c8d8e8;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2rem 1rem;
}
.wizard-wrap {
    width: 100%;
    max-width: 680px;
}
.wizard-brand {
    text-align: center;
    margin-bottom: 2rem;
}
.wizard-brand h1 {
    font-family: Georgia, serif;
    font-size: 1.6rem;
    color: #f0d980;
    letter-spacing: 0.06em;
    margin-bottom: 0.25rem;
}
.wizard-brand p {
    font-size: 0.9rem;
    color: #6b82a0;
}
/* Progress bar */
.progress-bar {
    display: flex;
    gap: 0;
    margin-bottom: 2rem;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #2a3a55;
}
.progress-step {
    flex: 1;
    padding: 0.5rem 0.25rem;
    text-align: center;
    font-size: 0.65rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-family: Georgia, serif;
    color: #3d5070;
    background: #111827;
    border-right: 1px solid #2a3a55;
    transition: all 0.2s;
}
.progress-step:last-child { border-right: none; }
.progress-step.done {
    background: #0d2b1a;
    color: #22c55e;
}
.progress-step.active {
    background: #1a1a2e;
    color: #f0d980;
    font-weight: bold;
}
/* Card */
.card {
    background: #111827;
    border: 1px solid #2a3a55;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 1.5rem;
}
.card h2 {
    font-size: 1.2rem;
    color: #f0d980;
    margin-bottom: 0.35rem;
    letter-spacing: 0.04em;
}
.card .subtitle {
    font-size: 0.88rem;
    color: #6b82a0;
    margin-bottom: 1.5rem;
}
/* Forms */
.form-group { margin-bottom: 1.1rem; }
.form-group label {
    display: block;
    font-size: 0.8rem;
    color: #9aabb8;
    font-family: Georgia, serif;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 0.4rem;
}
.form-group label small {
    text-transform: none;
    letter-spacing: 0;
    color: #3d5070;
    font-size: 0.75rem;
}
input[type=text], input[type=password], input[type=email],
input[type=url], select, textarea {
    width: 100%;
    background: #1a2235;
    border: 1px solid #2a3a55;
    border-radius: 6px;
    color: #c8d8e8;
    padding: 0.55rem 0.85rem;
    font-size: 0.95rem;
    font-family: monospace;
    transition: border-color 0.2s;
}
input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: #d4a017;
}
select { font-family: Georgia, serif; }
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
/* Buttons */
.btn {
    display: inline-block;
    padding: 0.65rem 1.5rem;
    border-radius: 6px;
    font-family: Georgia, serif;
    font-size: 0.88rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    cursor: pointer;
    border: 1px solid;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-primary {
    background: #d4a017;
    color: #0a0d14;
    border-color: #d4a017;
    font-weight: bold;
}
.btn-primary:hover { background: #f0d980; border-color: #f0d980; }
.btn-secondary {
    background: none;
    color: #6b82a0;
    border-color: #2a3a55;
}
.btn-secondary:hover { color: #c8d8e8; border-color: #c8d8e8; }
.btn-full { width: 100%; text-align: center; padding: 0.75rem; }
.btn-row {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
    align-items: center;
}
/* Alerts */
.alert {
    padding: 0.75rem 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
    border: 1px solid;
}
.alert-error   { background:#2d0a0a; border-color:#fca5a5; color:#fca5a5; }
.alert-success { background:#052e16; border-color:#86efac; color:#86efac; }
.alert-info    { background:#0c1a3d; border-color:#93c5fd; color:#93c5fd; }
.alert-warn    { background:#2d1a00; border-color:#fcd34d; color:#fcd34d; }
/* Requirement checks */
.req-list { list-style: none; }
.req-list li {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid #1a2235;
    font-size: 0.9rem;
}
.req-list li:last-child { border-bottom: none; }
.req-ok   { color: #22c55e; font-size: 1rem; }
.req-fail { color: #ef4444; font-size: 1rem; }
.req-warn { color: #f59e0b; font-size: 1rem; }
.req-label { flex: 1; }
.req-detail { color: #6b82a0; font-size: 0.8rem; }
/* Results table */
.result-list { list-style: none; }
.result-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.45rem 0;
    font-size: 0.88rem;
    border-bottom: 1px solid #1a2235;
}
.result-list li:last-child { border-bottom: none; }
/* Code block */
.code-block {
    background: #060810;
    border: 1px solid #2a3a55;
    border-radius: 6px;
    padding: 0.85rem 1rem;
    font-family: monospace;
    font-size: 0.82rem;
    color: #7fba7f;
    margin: 0.5rem 0 1rem;
    white-space: pre-wrap;
    word-break: break-all;
}
.hint {
    font-size: 0.8rem;
    color: #3d5070;
    margin-top: 0.3rem;
    font-style: italic;
}
a { color: #d4a017; }
a:hover { color: #f0d980; }
.text-muted { color: #6b82a0; }
.text-green { color: #22c55e; }
.text-red   { color: #ef4444; }
.text-gold  { color: #f0d980; }
hr { border: none; border-top: 1px solid #2a3a55; margin: 1.5rem 0; }
</style>
</head>
<body>

<div class="wizard-wrap">

    <div class="wizard-brand">
        <h1>⚔ Legends of the Green Dollar</h1>
        <p>Installation Wizard v<?= SETUP_VERSION ?></p>
    </div>

    <!-- Progress bar -->
    <div class="progress-bar">
        <?php foreach ($steps as $n => $label): ?>
        <div class="progress-step <?= $n < $step ? 'done' : ($n === $step ? 'active' : '') ?>">
            <?= $n < $step ? '✓ ' : '' ?><?= $label ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($info): ?>
    <div class="alert alert-info"><?= $info ?></div>
    <?php endif; ?>

    <!-- ===================================================================
         STEP 1: Requirements check
    =================================================================== -->
    <?php if ($step === 1): ?>
    <?php $reqs = checkRequirements(); $allOk = $reqs['all_ok']; ?>
    <div class="card">
        <h2>Step 1 — Requirements Check</h2>
        <p class="subtitle">Verifying your server meets the minimum requirements before we begin.</p>

        <ul class="req-list">
            <?php foreach ($reqs['checks'] as $c): ?>
            <li>
                <span class="<?= $c['ok'] ? 'req-ok' : ($c['warn'] ?? false ? 'req-warn' : 'req-fail') ?>">
                    <?= $c['ok'] ? '✔' : ($c['warn'] ?? false ? '⚠' : '✘') ?>
                </span>
                <span class="req-label"><?= htmlspecialchars($c['label']) ?></span>
                <span class="req-detail"><?= htmlspecialchars($c['detail']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if (!$allOk): ?>
        <div class="alert alert-error" style="margin-top:1.25rem">
            One or more requirements are not met. Please fix the issues above before continuing.
        </div>
        <?php else: ?>
        <div class="alert alert-success" style="margin-top:1.25rem">
            All requirements met. Ready to install!
        </div>
        <div class="btn-row">
            <a href="?step=2" class="btn btn-primary">Continue →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===================================================================
         STEP 2: Database
    =================================================================== -->
    <?php elseif ($step === 2): ?>
    <div class="card">
        <h2>Step 2 — Database Connection</h2>
        <p class="subtitle">
            Enter your MySQL credentials. The wizard will test the connection and then
            run all schema files automatically.
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="save_db">

            <div class="form-group">
                <label>Database Host</label>
                <input type="text" name="db_host" required
                       placeholder="db.yourdomain.com"
                       value="<?= htmlspecialchars($_SESSION['db_host'] ?? '') ?>">
                <p class="hint">On DreamHost this is usually your panel → MySQL Databases → Hostname.</p>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Database Username</label>
                    <input type="text" name="db_user" required
                           placeholder="lotgd_user"
                           value="<?= htmlspecialchars($_SESSION['db_user'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_pass" required>
                </div>
            </div>

            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" required
                       placeholder="lotgd_dev"
                       value="<?= htmlspecialchars($_SESSION['db_name'] ?? '') ?>">
                <p class="hint">
                    Create this database in your hosting panel first if it doesn't exist.
                    You can add a second production database later by editing config.php directly.
                </p>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Test Connection & Continue →</button>
                <a href="?step=1" class="btn btn-secondary">← Back</a>
            </div>
        </form>
    </div>

    <!-- ===================================================================
         STEP 3: Application settings
    =================================================================== -->
    <?php elseif ($step === 3): ?>
    <div class="card">
        <h2>Step 3 — Application Settings</h2>
        <p class="subtitle">Basic configuration for your installation.</p>

        <?php
        // Auto-detect base URL from current request
        $proto       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $detectedUrl = $proto . '://' . $host;
        // Strip /setup from path if present
        $detectedUrl = rtrim($detectedUrl, '/');
        ?>

        <form method="POST">
            <input type="hidden" name="action" value="save_app">

            <div class="form-group">
                <label>Base URL <small>— no trailing slash</small></label>
                <input type="text" name="base_url" required
                       value="<?= htmlspecialchars($_SESSION['base_url'] ?? $detectedUrl) ?>">
                <p class="hint">The public URL of your site root, e.g. https://lotgd.yourdomain.com</p>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Environment</label>
                    <select name="env">
                        <option value="dev"  <?= ($_SESSION['env'] ?? 'dev') === 'dev'  ? 'selected' : '' ?>>
                            dev — shows errors, debug banner
                        </option>
                        <option value="prod" <?= ($_SESSION['env'] ?? 'dev') === 'prod' ? 'selected' : '' ?>>
                            prod — hides errors, production mode
                        </option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="timezone">
                        <?php
                        $tzGroups = ['America' => [], 'Europe' => [], 'Asia' => [], 'Pacific' => [], 'Other' => []];
                        foreach (DateTimeZone::listIdentifiers() as $tz) {
                            $parts = explode('/', $tz, 2);
                            $group = isset($tzGroups[$parts[0]]) ? $parts[0] : 'Other';
                            $tzGroups[$group][] = $tz;
                        }
                        $savedTz = $_SESSION['timezone'] ?? 'America/Chicago';
                        foreach ($tzGroups as $group => $tzList) {
                            echo "<optgroup label=\"{$group}\">";
                            foreach ($tzList as $tz) {
                                $sel = $tz === $savedTz ? 'selected' : '';
                                echo "<option value=\"{$tz}\" {$sel}>{$tz}</option>";
                            }
                            echo '</optgroup>';
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Continue →</button>
                <a href="?step=2" class="btn btn-secondary">← Back</a>
            </div>
        </form>
    </div>

    <!-- ===================================================================
         STEP 4: API Keys
    =================================================================== -->
    <?php elseif ($step === 4): ?>
    <div class="card">
        <h2>Step 4 — API Keys & Email</h2>
        <p class="subtitle">These can also be configured later via the Admin → Settings panel.</p>

        <form method="POST">
            <input type="hidden" name="action" value="save_keys">

            <div class="form-group">
                <label>Finnhub API Key <small>— required for stock prices</small></label>
                <input type="password" name="finnhub_key"
                       placeholder="Your Finnhub free tier key"
                       value="<?= htmlspecialchars($_SESSION['finnhub_key'] ?? '') ?>">
                <p class="hint">
                    Free at <a href="https://finnhub.io" target="_blank">finnhub.io</a> →
                    Register → API Key. Provides 60 calls/minute on the free tier.
                    Required for daily price updates. Can be added later in Admin → Settings.
                </p>
            </div>

            <div class="form-group">
                <label>Anthropic API Key <small>— optional, for Daily Adventurer's Brief</small></label>
                <input type="password" name="anthropic_key"
                       placeholder="sk-ant-..."
                       value="<?= htmlspecialchars($_SESSION['anthropic_key'] ?? '') ?>">
                <p class="hint">
                    Pay-as-you-go at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.
                    Used only for the daily market brief (~$0.04/month). Skip for now and add later in Admin → Settings.
                </p>
            </div>

            <hr>

            <div class="form-group">
                <label>Email From Address <small>— required for confirmation emails</small></label>
                <input type="email" name="from_email" required
                       placeholder="noreply@yourdomain.com"
                       value="<?= htmlspecialchars($_SESSION['from_email'] ?? '') ?>">
                <p class="hint">
                    Must be a real mailbox hosted on your domain. On DreamHost, create this mailbox
                    in Panel → Mail → Manage Email first. PHP's mail() function will be rejected
                    if this address doesn't exist on the server.
                </p>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Continue →</button>
                <a href="?step=3" class="btn btn-secondary">← Back</a>
            </div>
        </form>
    </div>

    <!-- ===================================================================
         STEP 5: Admin account
    =================================================================== -->
    <?php elseif ($step === 5): ?>
    <div class="card">
        <h2>Step 5 — Admin Account</h2>
        <p class="subtitle">
            Create the first administrator account. This user will have full access
            to the admin panel and will be email-confirmed automatically.
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="save_admin">

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="admin_username" required
                       minlength="3" maxlength="30"
                       placeholder="YourUsername"
                       value="<?= htmlspecialchars($_SESSION['admin_username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="admin_email" required
                       placeholder="you@yourdomain.com"
                       value="<?= htmlspecialchars($_SESSION['admin_email'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Password <small>— min. 8 characters</small></label>
                    <input type="password" name="admin_pass" required minlength="8">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="admin_pass2" required minlength="8">
                </div>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Continue →</button>
                <a href="?step=4" class="btn btn-secondary">← Back</a>
            </div>
        </form>
    </div>

    <!-- ===================================================================
         STEP 6: Review and install
    =================================================================== -->
    <?php elseif ($step === 6): ?>
    <div class="card">
        <h2>Step 6 — Review & Install</h2>
        <p class="subtitle">
            Everything is ready. Review the summary below, then click Install to
            write your config file, run the database schema, and create your admin account.
        </p>

        <ul class="req-list" style="margin-bottom:1.5rem">
            <li>
                <span class="req-ok">✔</span>
                <span class="req-label">Database</span>
                <span class="req-detail"><?= htmlspecialchars($_SESSION['db_user'] ?? '') ?>@<?= htmlspecialchars($_SESSION['db_host'] ?? '') ?> / <?= htmlspecialchars($_SESSION['db_name'] ?? '') ?></span>
            </li>
            <li>
                <span class="req-ok">✔</span>
                <span class="req-label">Base URL</span>
                <span class="req-detail"><?= htmlspecialchars($_SESSION['base_url'] ?? '') ?></span>
            </li>
            <li>
                <span class="req-ok">✔</span>
                <span class="req-label">Environment</span>
                <span class="req-detail"><?= htmlspecialchars($_SESSION['env'] ?? 'dev') ?></span>
            </li>
            <li>
                <span class="req-ok">✔</span>
                <span class="req-label">Timezone</span>
                <span class="req-detail"><?= htmlspecialchars($_SESSION['timezone'] ?? '') ?></span>
            </li>
            <li>
                <span class="<?= !empty($_SESSION['finnhub_key']) ? 'req-ok' : 'req-warn' ?>">
                    <?= !empty($_SESSION['finnhub_key']) ? '✔' : '⚠' ?>
                </span>
                <span class="req-label">Finnhub API Key</span>
                <span class="req-detail"><?= !empty($_SESSION['finnhub_key']) ? 'Provided' : 'Not provided — add later in Admin → Settings' ?></span>
            </li>
            <li>
                <span class="<?= !empty($_SESSION['anthropic_key']) ? 'req-ok' : 'req-warn' ?>">
                    <?= !empty($_SESSION['anthropic_key']) ? '✔' : '⚠' ?>
                </span>
                <span class="req-label">Anthropic API Key</span>
                <span class="req-detail"><?= !empty($_SESSION['anthropic_key']) ? 'Provided' : 'Not provided — add later in Admin → Settings' ?></span>
            </li>
            <li>
                <span class="req-ok">✔</span>
                <span class="req-label">Email From</span>
                <span class="req-detail"><?= htmlspecialchars($_SESSION['from_email'] ?? '') ?></span>
            </li>
            <li>
                <span class="req-ok">✔</span>
                <span class="req-label">Admin Account</span>
                <span class="req-detail"><?= htmlspecialchars($_SESSION['admin_username'] ?? '') ?> — <?= htmlspecialchars($_SESSION['admin_email'] ?? '') ?></span>
            </li>
        </ul>

        <div class="alert alert-warn">
            <strong>⚠ This will:</strong> write config/config.php, create .htaccess files,
            run all SQL schema files, create your admin account, and
            <strong>permanently delete the setup/ directory</strong> when complete.
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="install">
            <div class="btn-row">
                <button type="submit" class="btn btn-primary btn-full">
                    ⚔ Install Legends of the Green Dollar
                </button>
            </div>
        </form>
        <div style="text-align:center;margin-top:0.75rem">
            <a href="?step=5" class="btn btn-secondary">← Back</a>
        </div>
    </div>

    <!-- ===================================================================
         STEP 7: Complete
    =================================================================== -->
    <?php elseif ($step === 7):
        $results = $_SESSION['install_results'] ?? [];
    ?>
    <div class="card">
        <h2 class="text-gold">⚔ Installation Complete!</h2>
        <p class="subtitle">Your realm has been established. Here's what was done:</p>

        <ul class="result-list" style="margin-bottom:1.5rem">
            <?php foreach ($results['log'] ?? [] as $entry): ?>
            <li>
                <span class="<?= $entry['ok'] ? 'text-green' : 'text-red' ?>">
                    <?= $entry['ok'] ? '✔' : '✘' ?>
                </span>
                <span><?= htmlspecialchars($entry['msg']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <hr>

        <h3 style="color:#f0d980;margin-bottom:0.75rem;font-size:1rem">📋 Required: File Permissions</h3>
        <p style="font-size:0.88rem;color:#6b82a0;margin-bottom:0.5rem">
            Run these commands via SSH to secure your installation:
        </p>
        <div class="code-block">chmod 600 config/config.php
chmod 755 logs/</div>

        <hr>

        <h3 style="color:#f0d980;margin-bottom:0.75rem;font-size:1rem">📋 Required: First Cron Run</h3>
        <p style="font-size:0.88rem;color:#6b82a0;margin-bottom:0.5rem">
            Run these via SSH to load stock prices and generate your first brief:
        </p>
        <div class="code-block"># 1. Populate S&P 500 stocks (~500 tickers from Wikipedia, takes ~2s)
php <?= htmlspecialchars(ROOT) ?>/cron/sp500_update.php

# 2. Download first closing prices (takes ~9 minutes — Finnhub rate limit)
php <?= htmlspecialchars(ROOT) ?>/cron/price_update.php

# 3. Generate first Daily Adventurer's Brief
php <?= htmlspecialchars(ROOT) ?>/cron/generate_brief.php --force</div>

        <hr>

        <h3 style="color:#f0d980;margin-bottom:0.75rem;font-size:1rem">📋 Required: Cron Schedule</h3>
        <p style="font-size:0.88rem;color:#6b82a0;margin-bottom:0.5rem">
            Add these to your hosting control panel cron scheduler:
        </p>
        <div class="code-block"># Hourly price update (weekdays)
0 * * * 1-5   php <?= htmlspecialchars(ROOT) ?>/cron/price_update.php

# Quarterly S&P 500 update
0 7 2 1 *     php <?= htmlspecialchars(ROOT) ?>/cron/sp500_update.php
0 7 1 4 *     php <?= htmlspecialchars(ROOT) ?>/cron/sp500_update.php
0 7 1 7 *     php <?= htmlspecialchars(ROOT) ?>/cron/sp500_update.php
0 7 1 10 *    php <?= htmlspecialchars(ROOT) ?>/cron/sp500_update.php</div>

        <div style="text-align:center;margin-top:2rem">
            <a href="<?= htmlspecialchars($_SESSION['base_url'] ?? '/') ?>/pages/login.php"
               class="btn btn-primary" style="font-size:1rem;padding:0.85rem 2rem">
                ⚔ Enter the Realm →
            </a>
        </div>
    </div>

    <?php endif; ?>

    <p style="text-align:center;font-size:0.78rem;color:#3d5070;margin-top:1rem">
        Legends of the Green Dollar · MIT License ·
        <a href="https://github.com/JeremyYowell/lotgd" target="_blank">GitHub</a>
    </p>

</div>
</body>
</html>

<?php
// =========================================================================
// FUNCTIONS
// =========================================================================

function redirect(int $step): never {
    $_SESSION['setup_step'] = $step;
    header("Location: ?step={$step}");
    exit;
}

function renderLocked(): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Already Installed</title>
<style>
body{font-family:Georgia,serif;background:#0a0d14;color:#c8d8e8;
     display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{text-align:center;max-width:440px;padding:2rem}
h1{color:#f0d980;margin-bottom:1rem}
p{color:#6b82a0;margin-bottom:1.5rem;line-height:1.6}
a{color:#d4a017}
</style>
</head>
<body>
<div class="box">
<h1>⚔ Already Installed</h1>
<p>Legends of the Green Dollar is already configured.<br>
The setup wizard cannot run again.</p>
<p>To reconfigure, remove <code>config/config.php</code> and re-run setup,
or edit the file directly.</p>
<p><a href="../pages/login.php">→ Go to Login</a></p>
</div>
</body>
</html>
HTML;
}

function checkRequirements(): array {
    $checks = [];
    $allOk  = true;

    // PHP version
    $phpOk = version_compare(PHP_VERSION, MIN_PHP, '>=');
    if (!$phpOk) $allOk = false;
    $checks[] = [
        'label'  => 'PHP Version',
        'detail' => 'Found ' . PHP_VERSION . ' — need ' . MIN_PHP . '+',
        'ok'     => $phpOk,
    ];

    // Required extensions
    foreach (['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'openssl'] as $ext) {
        $ok = extension_loaded($ext);
        if (!$ok) $allOk = false;
        $checks[] = ['label' => "Extension: {$ext}", 'detail' => $ok ? 'Loaded' : 'MISSING', 'ok' => $ok];
    }

    // config/ writable
    $configDir = ROOT . '/config';
    $ok        = is_dir($configDir) && is_writable($configDir);
    if (!$ok) $allOk = false;
    $checks[] = [
        'label'  => 'config/ directory writable',
        'detail' => $ok ? 'OK' : 'Not writable — chmod 755 config/',
        'ok'     => $ok,
    ];

    // logs/ writable (create if missing)
    $logsDir = ROOT . '/logs';
    if (!is_dir($logsDir)) @mkdir($logsDir, 0755, true);
    $ok = is_dir($logsDir) && is_writable($logsDir);
    if (!$ok) $allOk = false;
    $checks[] = [
        'label'  => 'logs/ directory writable',
        'detail' => $ok ? 'OK' : 'Not writable — run: mkdir logs && chmod 755 logs',
        'ok'     => $ok,
    ];

    // SQL files present
    $sqlFiles = ['schema.sql', 'portfolio_schema.sql', 'email_confirm_schema.sql',
                 'adventure_schema.sql', 'store_schema.sql'];
    $allSql   = true;
    $missingSql = [];
    foreach ($sqlFiles as $f) {
        if (!file_exists(ROOT . '/sql/' . $f)) {
            $allSql  = false;
            $allOk   = false;
            $missingSql[] = $f;
        }
    }
    $checks[] = [
        'label'  => 'SQL schema files',
        'detail' => $allSql ? 'All present' : 'Missing: ' . implode(', ', $missingSql),
        'ok'     => $allSql,
    ];

    // config.example.php present
    $ok = file_exists(ROOT . '/config/config.example.php');
    if (!$ok) $allOk = false;
    $checks[] = [
        'label'  => 'config/config.example.php',
        'detail' => $ok ? 'Found' : 'Missing — re-clone the repository',
        'ok'     => $ok,
    ];

    return ['checks' => $checks, 'all_ok' => $allOk];
}

function runInstallation(): array {
    $log     = [];
    $s       = $_SESSION;
    $success = true;

    // --- 1. Connect to database ---
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $s['db_host'], $s['db_name']);
        $pdo = new PDO($dsn, $s['db_user'], $s['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $log[] = ['ok' => true, 'msg' => 'Database connection established'];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()];
    }

    // --- 2. Run SQL schema files in order ---
    $sqlFiles = [
        'schema.sql',
        'portfolio_schema.sql',
        'email_confirm_schema.sql',
        'adventure_schema.sql',
        'store_schema.sql',
        'daily_brief_settings.sql',
    ];

    foreach ($sqlFiles as $file) {
        $path = ROOT . '/sql/' . $file;
        if (!file_exists($path)) {
            $log[] = ['ok' => false, 'msg' => "SQL file not found: {$file} (skipped)"];
            continue;
        }
        try {
            $sql = file_get_contents($path);
            // Split on semicolons and run each statement
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => !empty($s) && !str_starts_with(ltrim($s), '--')
            );
            foreach ($statements as $stmt) {
                if (!empty(trim($stmt))) {
                    $pdo->exec($stmt);
                }
            }
            $log[] = ['ok' => true, 'msg' => "Schema loaded: {$file}"];
        } catch (PDOException $e) {
            // Many statements use IF NOT EXISTS or ON DUPLICATE KEY — not fatal
            $log[] = ['ok' => true, 'msg' => "Schema applied: {$file} (some statements already existed — OK)"];
        }
    }

    // --- 3. Update settings table with API keys + email ---
    $settings = [
        'finnhub_api_key'     => $s['finnhub_key']   ?? '',
        'claude_api_key'      => $s['anthropic_key'] ?? '',
        'email_from_address'  => $s['from_email']    ?? '',
    ];
    foreach ($settings as $key => $val) {
        try {
            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value)
                 VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([$key, $val]);
        } catch (PDOException $e) {
            $log[] = ['ok' => false, 'msg' => "Could not save setting {$key}: " . $e->getMessage()];
        }
    }
    $log[] = ['ok' => true, 'msg' => 'API keys and email address saved to settings'];

    // --- 4. Create admin user ---
    try {
        $hash = password_hash($s['admin_pass'], PASSWORD_DEFAULT);
        $pdo->prepare(
            "INSERT INTO users
             (username, email, password_hash, class, is_admin, email_confirmed, created_at)
             VALUES (?, ?, ?, 'investor', 1, 1, NOW())
             ON DUPLICATE KEY UPDATE is_admin = 1, email_confirmed = 1"
        )->execute([$s['admin_username'], $s['admin_email'], $hash]);
        $log[] = ['ok' => true, 'msg' => "Admin account created: {$s['admin_username']}"];
    } catch (PDOException $e) {
        $log[] = ['ok' => false, 'msg' => 'Could not create admin user: ' . $e->getMessage()];
        $success = false;
    }

    // --- 5. Write config/config.php ---
    $configResult = writeConfig($s);
    $log[] = $configResult;
    if (!$configResult['ok']) $success = false;

    // --- 6. Write .htaccess files ---
    $htaccessDirs = ['config', 'logs', 'cron'];
    $htaccessContent = "Order deny,allow\nDeny from all\n";
    foreach ($htaccessDirs as $dir) {
        $path = ROOT . '/' . $dir . '/.htaccess';
        if (!file_exists($path)) {
            $wrote = file_put_contents($path, $htaccessContent);
            $log[] = ['ok' => $wrote !== false,
                'msg' => $wrote !== false
                    ? ".htaccess created: {$dir}/"
                    : "Could not write .htaccess to {$dir}/"];
        } else {
            $log[] = ['ok' => true, 'msg' => ".htaccess already exists: {$dir}/"];
        }
    }

    // --- 7. Self-destruct setup/ directory ---
    $setupDir   = __DIR__;
    $deletedAll = true;
    foreach (glob($setupDir . '/*') as $file) {
        if (is_file($file)) {
            if (!@unlink($file)) $deletedAll = false;
        }
    }
    if ($deletedAll) {
        @rmdir($setupDir);
        $log[] = ['ok' => true, 'msg' => 'Setup directory deleted — wizard cannot be re-run'];
    } else {
        $log[] = ['ok' => false, 'msg' => 'Could not fully delete setup/ — please remove it manually via FTP/SSH'];
    }

    return ['success' => $success, 'log' => $log];
}

function writeConfig(array $s): array {
    $env      = $s['env']      ?? 'dev';
    $host     = $s['db_host']  ?? '';
    $user     = $s['db_user']  ?? '';
    $pass     = $s['db_pass']  ?? '';
    $dbName   = $s['db_name']  ?? '';
    $baseUrl  = $s['base_url'] ?? '';
    $timezone = $s['timezone'] ?? 'America/Chicago';
    $apiKey   = $s['anthropic_key'] ?? '';

    // Determine prod db name (convention: swap _dev → _prod)
    $prodDbName = str_replace('_dev', '_prod', $dbName);
    if ($prodDbName === $dbName) $prodDbName = $dbName . '_prod';

    $content = <<<PHP
<?php
/**
 * config/config.php — Generated by LotGD Setup Wizard
 * DO NOT COMMIT THIS FILE — it is listed in .gitignore
 */

// Environment: 'dev' or 'prod'
define('APP_ENV', '{$env}');

define('IS_DEV',  APP_ENV === 'dev');
define('IS_PROD', APP_ENV === 'prod');

// =============================================================================
// DATABASE
// =============================================================================
define('DB_HOST',    '{$host}');
define('DB_USER',    '{$user}');
define('DB_PASS',    '{$pass}');
define('DB_CHARSET', 'utf8mb4');
define('DB_NAME',    IS_PROD ? '{$prodDbName}' : '{$dbName}');

// =============================================================================
// ANTHROPIC API
// =============================================================================
define('ANTHROPIC_API_KEY', '{$apiKey}');
define('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages');
define('ANTHROPIC_API_VER', '2023-06-01');

// =============================================================================
// APPLICATION PATHS
// =============================================================================
define('ROOT_PATH',   dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('LIB_PATH',    ROOT_PATH . '/lib');
define('PAGE_PATH',   ROOT_PATH . '/pages');
define('TPL_PATH',    ROOT_PATH . '/templates');

define('BASE_URL', IS_PROD
    ? '{$baseUrl}'
    : '{$baseUrl}'
);

// =============================================================================
// SESSION
// =============================================================================
define('SESSION_NAME',     'lotgd_session');
define('SESSION_LIFETIME', 7200);

// =============================================================================
// SECURITY
// =============================================================================
define('CSRF_TOKEN_LENGTH',     32);
define('PASSWORD_MIN_LENGTH',   8);
define('MAX_LOGIN_ATTEMPTS',    5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// =============================================================================
// DEBUG / ERROR REPORTING
// =============================================================================
if (IS_DEV) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT_PATH . '/logs/php_errors.log');
}

// =============================================================================
// TIMEZONE
// =============================================================================
date_default_timezone_set('{$timezone}');
PHP;

    $path  = ROOT . '/config/config.php';
    $wrote = file_put_contents($path, $content);

    return [
        'ok'  => $wrote !== false,
        'msg' => $wrote !== false
            ? 'config/config.php written successfully'
            : 'ERROR: Could not write config/config.php — check directory permissions',
    ];
}
