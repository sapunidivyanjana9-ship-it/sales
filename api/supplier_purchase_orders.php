<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_clerk_db_connect.php';

// Lets a supplier (authenticated against the main app's session) see the
// Purchase Orders a manager has approved for them (stock_clerk_db.purchase_orders
// - see api/manager_purchase_orders.php / pages/dashboards/stock-dashboard.php),
// accept one, and mark it delivered. pages/dashboards/supplierdashboard.html's
// "Purchase Orders" tab used to be permanently-static demo data with no real
// backend and no accept/deliver actions at all.
//
// Acceptance/delivery are tracked in their own columns rather than by
// overloading `status`: the Stock Clerk's own dashboard treats
// status = 'Approved' as "ready to receive" for its GRN flow regardless of
// what the supplier has done, so that column must stay untouched here.

function ensure_supplier_po_columns(PDO $pdo): void
{
    if (!stock_clerk_has_column($pdo, 'purchase_orders', 'supplier_accepted')) {
        $pdo->exec('ALTER TABLE purchase_orders ADD supplier_accepted TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!stock_clerk_has_column($pdo, 'purchase_orders', 'supplier_accepted_at')) {
        $pdo->exec('ALTER TABLE purchase_orders ADD supplier_accepted_at DATETIME NULL');
    }
    if (!stock_clerk_has_column($pdo, 'purchase_orders', 'supplier_delivered')) {
        $pdo->exec('ALTER TABLE purchase_orders ADD supplier_delivered TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!stock_clerk_has_column($pdo, 'purchase_orders', 'supplier_delivered_at')) {
        $pdo->exec('ALTER TABLE purchase_orders ADD supplier_delivered_at DATETIME NULL');
    }
}

endpoint_guard(function (): void {
    $user = require_roles(['supplier']);

    $party = find_party_for_user((int)$user['user_id'], 'supplier');
    if (!$party || trim((string)$party['name']) === '') {
        fail('Supplier profile not found for this account', 404);
    }
    $supplierName = (string)$party['name'];

    $pdo = get_stock_clerk_pdo();
    ensure_purchase_orders_table($pdo);
    ensure_supplier_po_columns($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Only POs that have actually been approved (and are still that way)
        // are "sent to the supplier" - a still-pending or rejected PO was
        // never sent.
        $stmt = $pdo->prepare("
            SELECT * FROM purchase_orders
            WHERE supplier = ? AND status NOT IN ('Pending Approval', 'Rejected')
            ORDER BY id DESC
        ");
        $stmt->execute([$supplierName]);
        respond(true, 'Purchase orders loaded', ['purchase_orders' => $stmt->fetchAll()]);
    }

    require_method(['POST']);
    $input = json_input();
    $action = trim((string)($input['action'] ?? ''));
    require_fields($input, ['po_id']);
    $poId = trim((string)$input['po_id']);

    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? AND supplier = ?');
    $stmt->execute([$poId, $supplierName]);
    $po = $stmt->fetch();
    if (!$po) {
        fail('Purchase order not found', 404);
    }

    if ($action === 'accept') {
        if ($po['status'] !== 'Approved') {
            fail('Only an approved purchase order can be accepted', 409);
        }
        if ((int)$po['supplier_accepted'] === 1) {
            fail('This purchase order has already been accepted', 409);
        }
        $pdo->prepare('UPDATE purchase_orders SET supplier_accepted = 1, supplier_accepted_at = NOW() WHERE id = ?')
            ->execute([$poId]);
    } elseif ($action === 'deliver') {
        if ((int)$po['supplier_accepted'] !== 1) {
            fail('Accept this purchase order before marking it delivered', 409);
        }
        if ((int)$po['supplier_delivered'] === 1) {
            fail('This purchase order has already been marked delivered', 409);
        }
        $pdo->prepare('UPDATE purchase_orders SET supplier_delivered = 1, supplier_delivered_at = NOW() WHERE id = ?')
            ->execute([$poId]);
    } else {
        fail('Unsupported action', 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $stmt->execute([$poId]);
    respond(true, 'Purchase order updated', ['purchase_order' => $stmt->fetch()]);
});
