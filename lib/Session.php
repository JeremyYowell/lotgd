<?php
/**
 * =============================================================================
 * lib/Session.php — Session management
 * =============================================================================
 * Handles session start, authentication state, CSRF tokens, and flash messages.
 * Sessions are stored in PHP's default handler (files on DreamHost shared).
 * The user_sessions table logs active sessions for security auditing.
 * =============================================================================
 */

class Session {

    private static bool $started = false;

    /**
     * Start the session with secure settings.
     * Call once early in bootstrap.php — do not call directly elsewhere.
     */
    public static function start(): void {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => IS_PROD,   // HTTPS-only cookie in production
            'httponly' => true,       // Not accessible via JavaScript
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        // Regenerate session ID periodically to prevent fixation
        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
        }
    }

    // -------------------------------------------------------------------------
    // Auth state
    // -------------------------------------------------------------------------

    /**
     * Log a user in — stores their id and username in session.
     */
    public static function login(array $user): void {
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['is_admin']  = (bool) $user['is_admin'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_at']  = time();
    }

    /**
     * Log the current user out and destroy the session.
     */
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
        self::$started = false;
    }

    /**
     * Is the current visitor logged in?
     */
    public static function isLoggedIn(): bool {
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
    }

    /**
     * Is the current user an admin?
     */
    public static function isAdmin(): bool {
        return self::isLoggedIn() && !empty($_SESSION['is_admin']);
    }

    /**
     * Require the visitor to be logged in — redirect to login if not.
     */
    public static function requireLogin(string $redirect = '/login.php'): void {
        if (!self::isLoggedIn()) {
            self::setFlash('error', 'You must be logged in to access that page.');
            self::redirect($redirect);
        }
    }

    /**
     * Require the visitor to be an admin — redirect home if not.
     */
    public static function requireAdmin(): void {
        self::requireLogin();
        if (!self::isAdmin()) {
            self::setFlash('error', 'You do not have permission to access that page.');
            self::redirect('/index.php');
        }
    }

    /**
     * Get the logged-in user's ID, or null.
     */
    public static function userId(): ?int {
        return self::isLoggedIn() ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Get the logged-in user's username, or null.
     */
    public static function username(): ?string {
        return self::isLoggedIn() ? $_SESSION['username'] : null;
    }

    // -------------------------------------------------------------------------
    // CSRF protection
    // -------------------------------------------------------------------------

    /**
     * Generate (or retrieve existing) CSRF token for the current session.
     */
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify a submitted CSRF token. Dies with 403 on mismatch.
     */
    public static function verifyCsrf(string $submitted): void {
        if (!hash_equals(self::csrfToken(), $submitted)) {
            http_response_code(403);
            die('Invalid or expired form token. Please go back and try again.');
        }
    }

    /**
     * Verify CSRF from $_POST['csrf_token'] automatically.
     */
    public static function verifyCsrfPost(): void {
        self::verifyCsrf($_POST['csrf_token'] ?? '');
    }

    /**
     * Return an HTML hidden input field with the CSRF token.
     */
    public static function csrfField(): string {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(self::csrfToken(), ENT_QUOTES) . '">';
    }

    // -------------------------------------------------------------------------
    // Flash messages (survive one redirect)
    // -------------------------------------------------------------------------

    /**
     * Set a flash message.
     * $type: 'success' | 'error' | 'info' | 'warning'
     */
    public static function setFlash(string $type, string $message): void {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Get and clear all flash messages.
     * Returns an array of ['type' => ..., 'message' => ...] or empty array.
     */
    public static function getFlash(): array {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }

    /**
     * Does the session have pending flash messages?
     */
    public static function hasFlash(): bool {
        return !empty($_SESSION['flash']);
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    /**
     * Safe redirect helper.
     */
    public static function redirect(string $url): never {
        if (!headers_sent()) {
            header('Location: ' . BASE_URL . $url);
        } else {
            // Fallback if headers already sent
            echo '<script>window.location="' . htmlspecialchars($url, ENT_QUOTES) . '";</script>';
        }
        exit;
    }

    /**
     * Set an arbitrary session value.
     */
    public static function set(string $key, mixed $value): void {
        $_SESSION[$key] = $value;
    }

    /**
     * Get an arbitrary session value.
     */
    public static function get(string $key, mixed $default = null): mixed {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Delete an arbitrary session value.
     */
    public static function delete(string $key): void {
        unset($_SESSION[$key]);
    }
}
