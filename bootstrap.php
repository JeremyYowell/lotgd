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

// Maintenance mode check
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
// Redirect unconfirmed users to the reminder page.
// Pages exempt from this check:
//   - confirm_email.php    (the confirmation handler itself)
//   - confirm_required.php (the reminder page)
//   - logout.php           (must always work)
//   - login.php            (guest page)
//   - register.php         (guest page)
// =========================================================================
$confirmationEnabled = (bool) $db->getSetting('email_confirmation_enabled', '1');

if ($confirmationEnabled && Session::isLoggedIn()) {
    $exemptPages = [
        'confirm_email.php',
        'confirm_required.php',
        'logout.php',
        'login.php',
        'register.php',
    ];
    $currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

    if (!in_array($currentPage, $exemptPages)) {
        // Check confirmation status (cached in session to avoid a DB hit on every page)
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

define('ENV_BANNER', IS_DEV
    ? '<div style="position:fixed;top:0;left:0;right:0;z-index:9999;background:#b91c1c;'
      . 'color:#fff;text-align:center;font-size:13px;font-family:monospace;padding:4px 0;">'
      . '⚠ DEV ENVIRONMENT — DB: ' . DB_NAME . '</div>'
    : ''
);

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
