<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_clerk_db_connect.php';

// The Stock Clerk's sample/QC workflow lives in a separate database
// (stock_clerk_db - see pages/dashboards/stock-dashboard.php) on the same
// MySQL server as the main app (pearl_land_db). This endpoint lets an
// authenticated supplier (authenticated against the main app's session and
// `users`/`suppliers` tables) read and act on their own rows over there, so
// "supplier sends the sample" actually reaches the same table the Stock
// Clerk's QC screen reads from - instead of a localStorage-only mock.

function ensure_sample_sent_date_column(PDO $pdo): void
{
    if (!stock_clerk_has_column($pdo, 'sample_requests', 'sample_sent_date')) {
        $pdo->exec('ALTER TABLE sample_requests ADD sample_sent_date DATE NULL');
    }
}

endpoint_guard(function (): void {
    $user = require_roles(['supplier']);

    // Match by the supplier's real company name (suppliers.name) - the same
    // value stock-dashboard.php stores in sample_requests.supplier when the
    // Stock Clerk creates a request. Resolved server-side from the
    // authenticated session so a supplier can never see or act on another
    // supplier's rows by passing a different name.
    $party = find_party_for_user((int)$user['user_id'], 'supplier');
    if (!$party || trim((string)$party['name']) === '') {
        fail('Supplier profile not found for this account', 404);
    }
    $supplierName = (string)$party['name'];

    $pdo = get_stock_clerk_pdo();
    ensure_sample_tables($pdo);
    ensure_sample_sent_date_column($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM sample_requests WHERE supplier = ? ORDER BY request_date DESC, id DESC');
        $stmt->execute([$supplierName]);
        $requests = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM qc_reports WHERE supplier = ? ORDER BY test_date DESC, id DESC');
        $stmt->execute([$supplierName]);
        $reports = $stmt->fetchAll();

        respond(true, 'Sample requests loaded', [
            'supplier_name' => $supplierName,
            'requests' => $requests,
            'qc_reports' => $reports,
        ]);
    }

    require_method(['POST']);
    $input = json_input();
    $action = trim((string)($input['action'] ?? ''));

    if ($action !== 'send') {
        fail('Unsupported action', 422);
    }

    require_fields($input, ['request_id']);
    $requestId = trim((string)$input['request_id']);

    $stmt = $pdo->prepare('SELECT * FROM sample_requests WHERE id = ? AND supplier = ?');
    $stmt->execute([$requestId, $supplierName]);
    $row = $stmt->fetch();

    if (!$row) {
        fail('Sample request not found', 404);
    }

    if ((int)$row['sample_sent'] === 1 || $row['status'] !== 'Pending') {
        fail('This sample has already been sent or processed', 409);
    }

    $update = $pdo->prepare('
        UPDATE sample_requests
        SET status = "Sample Sent", sample_sent = 1, sample_sent_date = CURDATE()
        WHERE id = ? AND supplier = ? AND status = "Pending" AND sample_sent = 0
    ');
    $update->execute([$requestId, $supplierName]);

    if ($update->rowCount() === 0) {
        fail('This sample has already been sent or processed', 409);
    }

    $stmt = $pdo->prepare('SELECT * FROM sample_requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $updated = $stmt->fetch();

    respond(true, 'Sample marked as sent', ['request' => $updated]);
});
