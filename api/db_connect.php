<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db_bootstrap.php';

function get_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    pelcomo_ensure_schema();

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
    }

    return array_merge($_GET, $_POST);
}

function respond(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, array $data = []): void
{
    respond(false, $message, $data, $status);
}

function require_method(array $methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        fail('Method not allowed', 405);
    }
}

function require_fields(array $input, array $fields): void
{
    foreach ($fields as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            fail("Missing required field: {$field}", 422);
        }
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = get_pdo()->prepare('SELECT * FROM users WHERE user_id = ? AND status = "active"');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        fail('Authentication required', 401);
    }

    return $user;
}

function require_roles(array $roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        fail('You do not have permission to perform this action', 403);
    }

    return $user;
}

function public_user(array $user): array
{
    unset($user['password']);
    return $user;
}

function generate_code(string $prefix): string
{
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function find_party_for_user(int $userId, string $role): ?array
{
    if ($role === 'customer') {
        $stmt = get_pdo()->prepare('SELECT customer_id AS id, name FROM customers WHERE user_id = ?');
    } elseif ($role === 'wholesaler') {
        $stmt = get_pdo()->prepare('SELECT wholesaler_id AS id, company_name AS name FROM wholesalers WHERE user_id = ?');
    } elseif ($role === 'supplier') {
        $stmt = get_pdo()->prepare('SELECT supplier_id AS id, name FROM suppliers WHERE user_id = ?');
    } else {
        return null;
    }

    $stmt->execute([$userId]);
    $party = $stmt->fetch();

    return $party ?: null;
}

function create_notification(int $userId, string $role, string $type, string $title, string $message, ?string $link = null): void
{
    $stmt = get_pdo()->prepare('
        INSERT INTO notifications (user_id, user_role, type, title, message, link)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $role, $type, $title, $message, $link]);
}

function update_user_session(array $user): void
{
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
}

/**
 * Creates the unified pending_registration_requests table if it doesn't exist.
 * This table holds customer / wholesaler / supplier sign-up requests until
 * an admin or manager approves or rejects them.
 */
function ensure_pending_registration_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pending_registration_requests (
            request_id INT AUTO_INCREMENT PRIMARY KEY,
            request_code VARCHAR(30) NOT NULL UNIQUE,
            role VARCHAR(20) NOT NULL,
            username VARCHAR(50) NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NULL,
            first_name VARCHAR(80) NULL,
            last_name VARCHAR(80) NULL,
            company_name VARCHAR(150) NULL,
            business_type VARCHAR(50) NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            address TEXT NOT NULL,
            city VARCHAR(50) NOT NULL,
            postal_code VARCHAR(20) NOT NULL,
            extra_data TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "Pending",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL
        )
    ');
}

function has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_supplier_request_columns(PDO $pdo): void
{
    if (!has_column($pdo, 'supplier_registration_requests', 'username')) {
        $pdo->exec('ALTER TABLE supplier_registration_requests ADD username VARCHAR(50) NULL AFTER request_code');
    }

    if (!has_column($pdo, 'supplier_registration_requests', 'postal_code')) {
        $pdo->exec('ALTER TABLE supplier_registration_requests ADD postal_code VARCHAR(20) NULL AFTER city');
    }
}

function endpoint_guard(callable $callback): void
{
    try {
        $callback();
    } catch (PDOException $e) {
        fail('Database error: ' . $e->getMessage(), 500);
    } catch (Throwable $e) {
        fail('Server error: ' . $e->getMessage(), 500);
    }
}