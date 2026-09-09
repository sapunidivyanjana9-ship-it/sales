<?php
require_once __DIR__ . '/db_connect.php';

/**
 * Looks a username up across the registration-request tables so a sign-up
 * that hasn't been approved yet gets a truthful answer ("awaiting approval"
 * / "rejected: <reason>") instead of a bare "invalid username".
 *
 * Returns the first match as ['status' => ..., 'rejection_reason' => ...],
 * or null if the username was never submitted.
 */
function find_registration_request(PDO $pdo, string $username): ?array
{
    $tables = [
        'pending_registration_requests'   => false,  // has no rejection_reason column
        'supplier_registration_requests'  => true,
        'customer_registration_requests'  => true,
        'wholesaler_registration_requests' => true,
    ];

    foreach ($tables as $table => $hasReason) {
        try {
            $columns = $hasReason ? 'status, rejection_reason' : 'status, NULL AS rejection_reason';
            $stmt = $pdo->prepare("SELECT {$columns} FROM {$table} WHERE username = ? ORDER BY request_id DESC LIMIT 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        } catch (PDOException $e) {
            // Table absent on this install - keep checking the others.
            continue;
        }
    }

    return null;
}


endpoint_guard(function (): void {
    require_method(['POST']);
    $input = json_input();
    require_fields($input, ['username', 'password']);

    $username = trim((string)$input['username']);

    $stmt = get_pdo()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // No account yet - but the person may have registered and be waiting on
    // (or have been refused) approval. The login page used to work this out
    // from a localStorage copy of the registration; it has to come from the
    // server, since only the server knows the real decision.
    if (!$user) {
        $pending = find_registration_request(get_pdo(), $username);
        if ($pending) {
            $status = strtolower((string)($pending['status'] ?? 'pending'));
            if ($status === 'rejected') {
                fail('Your registration was rejected. Reason: '
                    . ($pending['rejection_reason'] ?: 'not specified'), 403);
            }
            fail('Your registration is still awaiting approval. You will be able to log in once it is approved.', 403);
        }

        fail('Invalid username or password', 401);
    }

    if ($user['status'] !== 'active') {
        fail('This account is not active. Please contact the manager.', 403);
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
