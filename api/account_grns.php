<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_clerk_db_connect.php';

// Lets an account clerk (authenticated against the main app's session) see
// the GRNs the Stock Clerk has actually recorded (stock_clerk_db.grns - see
// pages/dashboards/stock-dashboard.php's own createGRN()/receiveMaterial())
// and process a supplier payment against one.
// pages/dashboards/accountdashboard.html's "Supplier Payments" tab used to
// be permanently-static demo GRNs/payments with no real backend, so a GRN
// the Stock Clerk actually created there could never be seen or paid here.

function ensure_grn_payment_columns(PDO $pdo): void
{
    if (!stock_clerk_has_column($pdo, 'grns', 'payment_status')) {
        $pdo->exec("ALTER TABLE grns ADD payment_status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
    }
    if (!stock_clerk_has_column($pdo, 'grns', 'payment_method')) {
        $pdo->exec('ALTER TABLE grns ADD payment_method VARCHAR(50) NULL');
    }
    if (!stock_clerk_has_column($pdo, 'grns', 'payment_reference')) {
        $pdo->exec('ALTER TABLE grns ADD payment_reference VARCHAR(100) NULL');
    }
    if (!stock_clerk_has_column($pdo, 'grns', 'payment_date')) {
        $pdo->exec('ALTER TABLE grns ADD payment_date DATE NULL');
    }
    if (!stock_clerk_has_column($pdo, 'grns', 'paid_by')) {
        $pdo->exec('ALTER TABLE grns ADD paid_by VARCHAR(120) NULL');
    }
}

endpoint_guard(function (): void {
    $user = require_roles(['account_clerk', 'manager', 'admin']);

    $pdo = get_stock_clerk_pdo();
    ensure_grns_table($pdo);
    ensure_grn_payment_columns($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query('SELECT * FROM grns ORDER BY id DESC');
        respond(true, 'GRNs loaded', ['grns' => $stmt->fetchAll()]);
    }

    require_method(['POST']);
    $input = json_input();
    $action = trim((string)($input['action'] ?? ''));

    if ($action !== 'process_payment') {
        fail('Unsupported action', 422);
    }

    require_fields($input, ['grn_id', 'payment_method']);
    $grnId = trim((string)$input['grn_id']);

    $stmt = $pdo->prepare('SELECT * FROM grns WHERE id = ?');
    $stmt->execute([$grnId]);
    $grn = $stmt->fetch();
    if (!$grn) {
        fail('GRN not found', 404);
    }
    if (!in_array($grn['status'], ['Approved', 'Manager Approved'], true)) {
        fail('Only an approved GRN can be paid', 409);
    }
    if ($grn['payment_status'] === 'Paid') {
        fail('This GRN has already been paid', 409);
    }

    $pdo->prepare('
        UPDATE grns
        SET payment_status = "Paid", payment_method = ?, payment_reference = ?, payment_date = ?, paid_by = ?
        WHERE id = ?
    ')->execute([
        trim((string)$input['payment_method']),
        isset($input['reference_no']) ? trim((string)$input['reference_no']) : null,
        !empty($input['payment_date']) ? trim((string)$input['payment_date']) : date('Y-m-d'),
        $user['full_name'] ?? $user['username'],
        $grnId,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM grns WHERE id = ?');
    $stmt->execute([$grnId]);
    respond(true, 'Payment processed', ['grn' => $stmt->fetch()]);
});
