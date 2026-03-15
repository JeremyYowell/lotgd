<?php
/**
 * =============================================================================
 * LEGENDS OF THE GREEN DOLLAR
 * config/config.example.php — Configuration Template
 * =============================================================================
 * SETUP INSTRUCTIONS:
 *   1. Copy this file to config/config.php
 *      cp config/config.example.php config/config.php
 *   2. Fill in your actual values below
 *   3. Never commit config/config.php — it is listed in .gitignore
 * =============================================================================
 */

// -------------------------------------------------------------------------
// ENVIRONMENT
// Change to 'prod' on your production server.
// -------------------------------------------------------------------------
define('APP_ENV', 'dev');

define('IS_DEV',  APP_ENV === 'dev');
define('IS_PROD', APP_ENV === 'prod');

// =============================================================================
// DATABASE CONFIGURATION
// =============================================================================
define('DB_HOST',    'your-db-hostname.example.com');   // e.g. db.yourdomain.com
define('DB_USER',    'your_db_username');
define('DB_PASS',    'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// Database name is selected by environment automatically
define('DB_NAME', IS_PROD ? 'lotgd_prod' : 'lotgd_dev');

// =============================================================================
// ANTHROPIC API (optional — only needed if re-enabling Claude features)
// =============================================================================
define('ANTHROPIC_API_KEY', 'your_anthropic_api_key_here');
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

// Public-facing base URL — no trailing slash
define('BASE_URL', IS_PROD
    ? 'https://yourdomain.com'      // <-- your production domain
    : 'http://localhost/lotgd'      // <-- your local/dev URL
);

// =============================================================================
// SESSION
// =============================================================================
define('SESSION_NAME',     'lotgd_session');
define('SESSION_LIFETIME', 7200);

// =============================================================================
// SECURITY
// =============================================================================
define('CSRF_TOKEN_LENGTH',    32);
define('PASSWORD_MIN_LENGTH',  8);
define('MAX_LOGIN_ATTEMPTS',   5);
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
date_default_timezone_set('America/Chicago');   // adjust to your timezone
