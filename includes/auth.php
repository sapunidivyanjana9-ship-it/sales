<?php
// includes/auth.php
// Authentication and authorization helper functions.
// Uses the Database singleton class for all DB operations.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../classes/Database.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /index.php');
        exit();
    }
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        // Redirect to appropriate dashboard based on role
        if (hasRole('manager')) {
            header('Location: /pages/dashboards/managerdashboard.html');
        } elseif (hasRole('stock_clerk')) {
            header('Location: /pages/dashboards/stock-dashboard.php');
        } elseif (hasRole('account_clerk')) {
            header('Location: /pages/dashboards/account-dashboard.php');
        } else {
            header('Location: /index.php');
        }
        exit();
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function getRoleDisplayName($role) {
    $roles = [
        'manager' => '👑 Manager',
        'stock_clerk' => '📦 Stock Clerk',
        'account_clerk' => '💰 Account Clerk'
    ];
    return $roles[$role] ?? $role;
}
?>
