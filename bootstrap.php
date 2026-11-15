<?php
/**
 * bootstrap.php — Application entry point
 */

if (!file_exists(__DIR__ . '/config/config.php')) {
    header('Location: /setup/'); exit;
}
require_once __DIR__ . '/config/config.php';

spl_autoload_register(function (string $class): void {
    $file = LIB_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

Session::start();

$db = Database::getInstance();

// =========================================================================
// SESSION EXPIRY DETECTION
// Gracefully handle sessions that have expired mid-browse.
// If the session is too old, destroy it and redirect to login
// rather than letting PHP throw errors on protected pages.
// =========================================================================
if (Session::isLoggedIn()) {
    // Use last_activity_at for inactivity detection (sliding window).
    // Falls back to login_at for sessions created before this change.
    $lastActivity = (int) Session::get('last_activity_at', Session::get('login_at', 0));
    $maxAge       = SESSION_LIFETIME; // from config.php (default 7200 = 2 hours)

    if ($lastActivity > 0 && (time() - $lastActivity) > $maxAge) {
        // Inactive for longer than SESSION_LIFETIME — log out cleanly
        Session::logout();
        Session::start();
        Session::setFlash('info', 'Your session expired due to inactivity. Please log in again.');

        // Only redirect if not already on a guest page
        $guestPages = ['login.php', 'register.php', '404.php'];
        $current    = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
        if (!in_array($current, $guestPages)) {
            Session::redirect('/pages/login.php');
        }
    }
}

// =========================================================================
// MAINTENANCE MODE
// =========================================================================
$maintenanceMode = (bool) $db->getSetting('maintenance_mode', '0');
if ($maintenanceMode && !Session::isAdmin()) {
    $currentFile = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($currentFile !== 'maintenance.php') {
        http_response_code(503);
        require_once TPL_PATH . '/maintenance.php';
        exit;
    }
}

// =========================================================================
// EMAIL CONFIRMATION GATE
// =========================================================================
$confirmationEnabled = (bool) $db->getSetting('email_confirmation_enabled', '1');

if ($confirmationEnabled && Session::isLoggedIn()) {
    $exemptPages = [
        'confirm_email.php',
        'confirm_required.php',
        'forgot_password.php',
        'reset_password.php',
        'logout.php',
        'login.php',
        'register.php',
        'privacy.php',
        '404.php',
    ];
    $currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

    if (!in_array($currentPage, $exemptPages)) {
        // Always query DB — never cache in session.
        // Caching caused admin manual-confirms to be ignored until the user
        // explicitly logged out and back in.
        $confirmed = (bool) $db->fetchValue(
            "SELECT email_confirmed FROM users WHERE id = ?",
            [Session::userId()]
        );

        if (!$confirmed) {
            Session::redirect('/pages/confirm_required.php');
        }
    }
}

// =========================================================================
// ENV BANNER (footer, dev only)
// =========================================================================
define('ENV_BANNER', ''); // kept for backward compat, banner now in layout.php

// =========================================================================
// GLOBAL HELPERS
// =========================================================================

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float $amount): string {
    return '$' . number_format($amount, 2);
}

function num(int|float $n): string {
    return number_format($n);
}

function redirect(string $path): never {
    Session::redirect($path);
}

function renderFlash(): string {
    $messages = Session::getFlash();
    if (empty($messages)) return '';

    $colorMap = [
        'success' => '#166534;background:#dcfce7;border:#86efac',
        'error'   => '#991b1b;background:#fee2e2;border:#fca5a5',
        'warning' => '#92400e;background:#fef3c7;border:#fcd34d',
        'info'    => '#1e40af;background:#dbeafe;border:#93c5fd',
    ];

    $html = '';
    foreach ($messages as $msg) {
        $colors = $colorMap[$msg['type']] ?? $colorMap['info'];
        [$color, $bg, $border] = explode(';', $colors);
        $html .= sprintf(
            '<div style="color:%s;%s;border:1px solid %s;padding:10px 16px;'
            . 'border-radius:6px;margin:8px 0;font-size:14px;">%s</div>',
            $color, $bg, $border, e($msg['message'])
        );
    }
    return $html;
}

function appLog(string $level, string $message, array $context = []): void {
    if (!IS_DEV && $level === 'debug') return;
    $logDir = ROOT_PATH . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $line = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? json_encode($context) : ''
    );
    file_put_contents($logDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

// ── Cron heartbeats → obs (n8n workflow 20; Digital/AI-Agents/OBSERVABILITY_PLAN.md) ──
// Scripts that define CRON_RUNNING check in to obs/hb when they exit. A fatal error, or a
// call to obsCronFail() before exit(1), marks the run failed; n8n alerts on state changes
// and when a job goes quiet for 1.5x its interval. Needs OBS_URL + OBS_TOKEN in config.php.
// DreamHost's panel owns these crontab lines, so the heartbeat lives here, not in a wrapper.
$GLOBALS['OBS_CRON_FAIL'] = null;
function obsCronFail(string $why): void { $GLOBALS['OBS_CRON_FAIL'] = $why; }
if (defined('CRON_RUNNING') && PHP_SAPI === 'cli' && defined('OBS_URL') && defined('OBS_TOKEN') && OBS_TOKEN) {
    register_shutdown_function(function () {
        // minutes between expected check-ins (price_update skips weekends, hence ~33h)
        $intervals = ['price_update' => 2000, 'generate_brief' => 1440, 'sp500_update' => 44640];
        $name  = basename($_SERVER['argv'][0] ?? 'unknown', '.php');
        $err   = error_get_last();
        $fatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        $why   = $GLOBALS['OBS_CRON_FAIL']
              ?? ($fatal ? substr($err['message'], 0, 300) . ' @ ' . basename($err['file']) . ':' . $err['line'] : null);
        $body  = json_encode([
            'job'              => 'lotgd:' . str_replace('_', '-', $name),
            'status'           => $why ? 'fail' : 'ok',
            'interval_minutes' => $intervals[$name] ?? 1440,
            'detail'           => $why,
        ]);
        @file_get_contents(rtrim(OBS_URL, '/') . '/hb', false, stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Obs-Token: " . OBS_TOKEN . "\r\nUser-Agent: lotgd-cron/1",
            'content'       => $body,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]));
    });
}
