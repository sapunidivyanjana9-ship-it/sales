<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_clerk_db_connect.php';

// Lets a manager/admin (main app session) see the real sample requests and
// QC results a Stock Clerk has logged in stock_clerk_db (see
// pages/dashboards/stock-dashboard.php), so "Create PO from Approved Sample"
// on the manager dashboard offers actual QC-passed samples instead of a
// localStorage mock the manager's own browser made up.
endpoint_guard(function (): void {
    require_method(['GET']);
    require_roles(['manager', 'admin']);

    $pdo = get_stock_clerk_pdo();
    ensure_sample_tables($pdo);

    $stmt = $pdo->query('SELECT * FROM sample_requests ORDER BY request_date DESC, id DESC');
    $requests = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT * FROM qc_reports ORDER BY test_date DESC, id DESC');
    $reports = $stmt->fetchAll();

    respond(true, 'Sample requests loaded', [
        'requests' => $requests,
        'qc_reports' => $reports,
    ]);
});
