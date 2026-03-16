<?php
/**
 * bootstrap.php — Application entry point
 */

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
    $loginAt  = (int) Session::get('login_at', 0);
    $maxAge   = SESSION_LIFETIME; // from config.php (default 7200 = 2 hours)

    if ($loginAt > 0 && (time() - $loginAt) > $maxAge) {
        // Session has exceeded lifetime — log out cleanly
        Session::logout();
        Session::start();
        Session::setFlash('info', 'Your session expired. Please log in again.');

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
        'logout.php',
        'login.php',
        'register.php',
        '404.php',
    ];
    $currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

    if (!in_array($currentPage, $exemptPages)) {
        $confirmed = Session::get('email_confirmed', null);
        if ($confirmed === null) {
            $confirmed = (bool) $db->fetchValue(
                "SELECT email_confirmed FROM users WHERE id = ?",
                [Session::userId()]
            );
            Session::set('email_confirmed', $confirmed);
        }

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
