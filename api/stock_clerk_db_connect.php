<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

// The Stock Clerk's own mini-app (pages/dashboards/stock-dashboard.php) keeps
// its sample/QC/Purchase Order workflow in a separate database,
// stock_clerk_db, on the same MySQL server as the main app (pearl_land_db).
// Endpoints that need to read/write that data from an authenticated main-app
// session (supplier or manager dashboards) share this connection helper
// instead of each redefining it.
const STOCK_CLERK_DB_NAME = 'stock_clerk_db';

function get_stock_clerk_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . STOCK_CLERK_DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function stock_clerk_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Mirrors the schema stock-dashboard.php's installDatabase() creates, so
 * endpoints that read/write these tables from the main app work whether or
 * not that dashboard has been opened/installed yet.
 */
function ensure_sample_tables(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sample_requests (
            id VARCHAR(20) PRIMARY KEY,
            supplier VARCHAR(100),
            material VARCHAR(100),
            qty INT,
            request_date DATE,
            status VARCHAR(50),
            sample_sent BOOLEAN DEFAULT FALSE
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS qc_reports (
            id INT PRIMARY KEY AUTO_INCREMENT,
            sample_id VARCHAR(20),
            supplier VARCHAR(100),
            material VARCHAR(100),
            test_date DATE,
            result VARCHAR(20),
            remarks TEXT,
            price DECIMAL(10,2),
            quality_score DECIMAL(3,1)
        )
    ');
}

function ensure_purchase_orders_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id VARCHAR(20) PRIMARY KEY,
            sample_id VARCHAR(20),
            supplier VARCHAR(100),
            material VARCHAR(100),
            qty INT,
            unit_price DECIMAL(10,2),
            total DECIMAL(10,2),
            delivery_date DATE,
            terms VARCHAR(50),
            status VARCHAR(50)
        )
    ');
}

/**
 * Mirrors the schema stock-dashboard.php's installDatabase() creates for
 * grns, so endpoints that read/write it from the main app (e.g. the Account
 * Clerk's supplier-payment screen) work whether or not that dashboard has
 * been opened/installed yet.
 */
function ensure_grns_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS grns (
            id VARCHAR(20) PRIMARY KEY,
            po_id VARCHAR(20),
            supplier VARCHAR(100),
            material VARCHAR(100),
            ordered_qty INT,
            received_qty INT,
            unit_price DECIMAL(10,2),
            total_amount DECIMAL(10,2),
            received_date DATE,
            inspector VARCHAR(100),
            remarks TEXT,
            status VARCHAR(20)
        )
    ');
}
