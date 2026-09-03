<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_clerk_db_connect.php';

// Lets a manager/admin (authenticated against the main app's session)
// review, approve/reject, and create Purchase Orders in
// stock_clerk_db.purchase_orders (see pages/dashboards/stock-dashboard.php -
// the Stock Clerk's own "Create PO" writes to this same table). The manager
// dashboard's PO panel used to be a localStorage-only mock that never saw
// or persisted to these real POs.

function ensure_po_payment_status_column(PDO $pdo): void
{
    if (!stock_clerk_has_column($pdo, 'purchase_orders', 'payment_status')) {
        $pdo->exec("ALTER TABLE purchase_orders ADD payment_status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
    }
}

function generate_po_id(): string
{
    return 'PO-' . str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
}

endpoint_guard(function (): void {
    require_roles(['manager', 'admin']);

    $pdo = get_stock_clerk_pdo();
    ensure_purchase_orders_table($pdo);
    ensure_po_payment_status_column($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query('SELECT * FROM purchase_orders ORDER BY id DESC');
        respond(true, 'Purchase orders loaded', ['purchase_orders' => $stmt->fetchAll()]);
    }

    require_method(['POST']);
    $input = json_input();
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'create') {
        require_fields($input, ['supplier', 'material', 'qty', 'unit_price', 'delivery_date', 'terms']);

        $supplier = trim((string)$input['supplier']);
        $material = trim((string)$input['material']);
        $qty = (int)$input['qty'];
        $unitPrice = (float)$input['unit_price'];
        $deliveryDate = trim((string)$input['delivery_date']);
        $terms = trim((string)$input['terms']);
        $sampleId = isset($input['sample_id']) && trim((string)$input['sample_id']) !== ''
            ? trim((string)$input['sample_id'])
            : null;

        if ($qty <= 0 || $unitPrice <= 0) {
            fail('Quantity and unit price must be greater than zero', 422);
        }

        $total = $qty * $unitPrice;

        // The same short random-ID scheme stock-dashboard.php's own Create PO
        // uses; retry a few times on a collision rather than failing outright,
        // now that two different screens can both generate one.
        $insert = $pdo->prepare('
            INSERT INTO purchase_orders (id, sample_id, supplier, material, qty, unit_price, total, delivery_date, terms, status, payment_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Pending Approval", "Pending")
        ');

        $poId = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = generate_po_id();
            try {
                $insert->execute([$candidate, $sampleId, $supplier, $material, $qty, $unitPrice, $total, $deliveryDate, $terms]);
                $poId = $candidate;
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // duplicate id - try again
            }
        }

        if ($poId === null) {
            fail('Could not generate a unique PO number, please try again', 500);
        }

        if ($sampleId !== null) {
            $pdo->prepare("UPDATE sample_requests SET status = 'PO Created' WHERE id = ?")->execute([$sampleId]);
        }

        $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        respond(true, 'Purchase order created', ['purchase_order' => $stmt->fetch()], 201);
    }

    require_fields($input, ['po_id']);
    $poId = trim((string)$input['po_id']);

    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'Approved' WHERE id = ? AND status = 'Pending Approval'");
        $stmt->execute([$poId]);
        if ($stmt->rowCount() === 0) {
            fail('This purchase order was not found or has already been processed', 409);
        }
    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'Rejected' WHERE id = ? AND status = 'Pending Approval'");
        $stmt->execute([$poId]);
        if ($stmt->rowCount() === 0) {
            fail('This purchase order was not found or has already been processed', 409);
        }
    } elseif ($action === 'update_payment_status') {
        require_fields($input, ['payment_status']);
        $paymentStatus = trim((string)$input['payment_status']);
        if (!in_array($paymentStatus, ['Pending', 'Paid', 'Overdue'], true)) {
            fail('Invalid payment status', 422);
        }
        $stmt = $pdo->prepare('UPDATE purchase_orders SET payment_status = ? WHERE id = ?');
        $stmt->execute([$paymentStatus, $poId]);
        if ($stmt->rowCount() === 0) {
            fail('Purchase order not found', 404);
        }
    } else {
        fail('Unsupported action', 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $stmt->execute([$poId]);
    respond(true, 'Purchase order updated', ['purchase_order' => $stmt->fetch()]);
});
