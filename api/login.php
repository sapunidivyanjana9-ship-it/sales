<?php
require_once __DIR__ . '/db_connect.php';


endpoint_guard(function (): void {
    require_method(['POST']);
    $input = json_input();
    require_fields($input, ['username', 'password']);

    $stmt = get_pdo()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([trim((string)$input['username'])]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        fail('Invalid username or inactive account', 401);
    }

    $password = (string)$input['password'];
    $stored = (string)$user['password'];
    $valid = password_verify($password, $stored) || hash_equals($stored, $password);

    if (!$valid) {
        fail('Invalid username or password', 401);
    }

    if (!password_get_info($stored)['algo']) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $update = get_pdo()->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            $update->execute([$hash, $user['user_id']]);
        } catch (Exception $e) {
            error_log('Failed to update password hash: ' . $e->getMessage());
        }
    }

    get_pdo()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?')->execute([$user['user_id']]);
    update_user_session($user);

    $redirects = [
        'admin' => '../dashboards/admin-dashboard.php',
        // pages/auth/login.html's own roleRedirects map already sends
        // 'manager' here - managerdashboard.html is the dashboard with the
        // real (now backend-wired) Purchase Order review/approve panel.
        // (manager/manager-dashboard.php used to be a separate, older PHP
        // dashboard that read a differently-shaped purchase_orders table,
        // rendered no HTML of its own, and was never kept in sync with this
        // one - it's been removed; see includes/auth.php and include.php for
        // the matching redirect-target fix.)
        'manager' => '../dashboards/managerdashboard.html',
        'stock_clerk' => '../dashboards/stock-dashboard.php',
        // account-dashboard.php is an old stub (a different auth/DB layer,
        // and no PO/GRN/supplier-payment UI at all) - accountdashboard.html
        // is the real dashboard, same as managerdashboard.html above.
        'account_clerk' => '../dashboards/accountdashboard.html',
        'customer' => '../customer/customer.html',
        'wholesaler' => '../dashboards/wholeseller.html',
        'supplier' => '../dashboards/supplierdashboard.html',
    ];

    respond(true, 'Login successful', [
        'user' => public_user($user),
        'redirect_page' => $redirects[$user['role']] ?? ($user['redirect_page'] ?: null),
    ]);
});
?>
