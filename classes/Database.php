<?php
/**
 * Database Connection Class - Singleton Pattern
 * Pearl Land Commodities (PELCOMO) Sales System
 * 
 * Provides a centralized, reusable database connection.
 * Supports both MySQL and SQLite drivers.
 * 
 * Usage:
 *   require_once __DIR__ . '/classes/Database.php';
 *   $pdo = Database::getInstance()->getConnection();
 * 
 * Or use helper methods:
 *   $db = Database::getInstance();
 *   $result = $db->query("SELECT * FROM users");
 *   $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
 */

require_once __DIR__ . '/../config/db_bootstrap.php';

class Database {
    // Singleton instance
    private static $instance = null;

    // PDO connection
    private $pdo;

    // ─── Configuration ───────────────────────────────────────
    // Pulled from config/env.php (env vars / .env / XAMPP-style defaults).
    // Don't hardcode credentials here - see config/env.php for why.

    // Database driver: 'mysql' or 'sqlite'
    private $driver = 'mysql';

    // MySQL settings
    private $host     = DB_HOST;
    private $dbname   = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset  = DB_CHARSET;

    // SQLite settings
    private $sqlitePath = '';

    // ─── Constructor (private — use getInstance()) ───────────
    private function __construct() {
        $this->sqlitePath = __DIR__ . '/../sales_system.db';

        try {
            if ($this->driver === 'mysql') {
                pelcomo_ensure_schema();
                $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
                $this->pdo = new PDO($dsn, $this->username, $this->password);
            } else {
                $dsn = "sqlite:" . $this->sqlitePath;
                $this->pdo = new PDO($dsn);
                // Enable foreign keys for SQLite
                $this->pdo->exec("PRAGMA foreign_keys = ON");
            }

            // Common PDO settings
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        } catch (PDOException $e) {
            die("❌ Database connection failed: " . $e->getMessage());
        }

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    // ─── Public Methods ──────────────────────────────────────

    /**
     * Get the singleton Database instance.
     * Creates the connection on first call; reuses it on subsequent calls.
     *
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the raw PDO connection object.
     * Use this when you need direct PDO access.
     *
     * @return PDO
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Execute a query and return the PDOStatement.
     *
     * @param string $sql  The SQL query to execute
     * @return PDOStatement
     */
    public function query($sql) {
        return $this->pdo->query($sql);
    }

    /**
     * Prepare a statement for execution.
     *
     * @param string $sql  The SQL statement to prepare
     * @return PDOStatement
     */
    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }

    /**
     * Get the ID of the last inserted row.
     *
     * @return string
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    /**
     * Execute a raw SQL statement (e.g., CREATE TABLE, INSERT).
     *
     * @param string $sql  The SQL statement to execute
     * @return int  Number of affected rows
     */
    public function exec($sql) {
        return $this->pdo->exec($sql);
    }

    /**
     * Begin a database transaction.
     *
     * @return bool
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction.
     *
     * @return bool
     */
    public function commit() {
        return $this->pdo->commit();
    }

    /**
     * Roll back the current transaction.
     *
     * @return bool
     */
    public function rollBack() {
        return $this->pdo->rollBack();
    }

    /**
     * Get the current database driver name.
     *
     * @return string  'mysql' or 'sqlite'
     */
    public function getDriver() {
        return $this->driver;
    }
}
?>
