<?php
/**
 * =============================================================================
 * lib/Database.php — PDO database connection (singleton)
 * =============================================================================
 * Usage:
 *   $db = Database::getInstance();
 *   $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
 *   $stmt->execute([$id]);
 *   $user = $stmt->fetch();
 * =============================================================================
 */

class Database {

    private static ?Database $instance = null;
    private PDO $pdo;

    /**
     * Private constructor — use getInstance() instead.
     */
    private function __construct() {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the real error, show a safe message to users
            error_log('[Database] Connection failed: ' . $e->getMessage());
            if (IS_DEV) {
                die('<pre>Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</pre>');
            } else {
                die('A database error occurred. Please try again later.');
            }
        }
    }

    /**
     * Get the single Database instance.
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prevent cloning of the singleton.
     */
    private function __clone() {}

    // -------------------------------------------------------------------------
    // Proxy commonly used PDO methods directly on this object
    // -------------------------------------------------------------------------

    public function prepare(string $sql): PDOStatement {
        return $this->pdo->prepare($sql);
    }

    public function query(string $sql): PDOStatement {
        return $this->pdo->query($sql);
    }

    public function exec(string $sql): int|false {
        return $this->pdo->exec($sql);
    }

    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    public function rollBack(): bool {
        return $this->pdo->rollBack();
    }

    public function quote(string $string): string {
        return $this->pdo->quote($string);
    }

    // -------------------------------------------------------------------------
    // Convenience helpers
    // -------------------------------------------------------------------------

    /**
     * Prepare + execute in one call. Returns the executed PDOStatement.
     *
     * $rows = $db->run("SELECT * FROM users WHERE level > ?", [5])->fetchAll();
     */
    public function run(string $sql, array $params = []): PDOStatement {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row.
     *
     * $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
     */
    public function fetchOne(string $sql, array $params = []): array|false {
        return $this->run($sql, $params)->fetch();
    }

    /**
     * Fetch all rows.
     *
     * $users = $db->fetchAll("SELECT * FROM users WHERE level = ?", [10]);
     */
    public function fetchAll(string $sql, array $params = []): array {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single column value from the first row.
     *
     * $count = $db->fetchValue("SELECT COUNT(*) FROM users");
     */
    public function fetchValue(string $sql, array $params = []): mixed {
        return $this->run($sql, $params)->fetchColumn();
    }

    /**
     * Get a setting from the settings table.
     * Cached in memory for the request lifetime.
     */
    private static array $settingsCache = [];

    public function getSetting(string $key, mixed $default = null): mixed {
        if (isset(self::$settingsCache[$key])) {
            return self::$settingsCache[$key];
        }
        $value = $this->fetchValue(
            "SELECT setting_value FROM settings WHERE setting_key = ?",
            [$key]
        );
        $result = ($value !== false) ? $value : $default;
        self::$settingsCache[$key] = $result;
        return $result;
    }

    /**
     * Update a setting in the settings table.
     */
    public function setSetting(string $key, string $value): void {
        $this->run(
            "INSERT INTO settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
        self::$settingsCache[$key] = $value;
    }
}
