<?php
require_once __DIR__ . '/db_connect.php';

/**
 * Everything the Account Clerk dashboard needs in one round-trip:
 *
 *  - customer_payments / wholesaler_payments: collections already recorded
 *    against real orders (api/process_payment.php writes them).
 *  - outstanding: approved orders that are not fully settled yet, which is
 *    what the clerk actually works off - previously this dashboard only ever
 *    showed hardcoded demo rows out of localStorage, so a customer's or
 *    wholesaler's real order never appeared here at all.
 *  - supplier_payments: the outgoing side, for the Supplier Payments tab.
 */
endpoint_guard(function (): void {
    require_method(['GET']);
    require_roles(['account_clerk', 'manager', 'admin']);

    $pdo = get_pdo();

    $params = [];
    $dateWhere = '';
    if (!empty($_GET['from'])) {
        $dateWhere .= ' AND DATE(p.payment_date) >= ?';
        $params[] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $dateWhere .= ' AND DATE(p.payment_date) <= ?';
        $params[] = $_GET['to'];
    }

    $paymentSql = '
        SELECT p.*,
               o.order_code,
               o.total_amount AS order_total,
               o.delivery_status,
               COALESCE(c.name, w.company_name, "Unknown") AS party_name
        FROM payments p
        LEFT JOIN orders o ON o.order_id = p.order_id
        LEFT JOIN customers c ON c.customer_id = COALESCE(p.customer_id, o.customer_id)
        LEFT JOIN wholesalers w ON w.wholesaler_id = COALESCE(p.wholesaler_id, o.wholesaler_id)
        WHERE p.payment_type = ?' . $dateWhere . '
        ORDER BY p.payment_date DESC, p.payment_id DESC
    ';

    $customerStmt = $pdo->prepare($paymentSql);
    $customerStmt->execute(array_merge(['customer'], $params));
    $customerPayments = $customerStmt->fetchAll();

    $wholesalerStmt = $pdo->prepare($paymentSql);
    $wholesalerStmt->execute(array_merge(['wholesaler'], $params));
    $wholesalerPayments = $wholesalerStmt->fetchAll();

    // Orders the manager has approved but that aren't fully collected yet.
    $outstanding = $pdo->query('
        SELECT o.order_id, o.order_code, o.order_type, o.order_date, o.delivery_date,
               o.total_amount, o.payment_status, o.payment_method, o.delivery_status,
               o.manager_approval, o.stock_clerk_approval,
               o.customer_id, o.wholesaler_id,
               COALESCE(c.name, w.company_name, "Unknown") AS party_name,
               COALESCE(paid.amount_paid, 0) AS amount_paid,
               o.total_amount - COALESCE(paid.amount_paid, 0) AS balance_due
        FROM orders o
        LEFT JOIN customers c ON c.customer_id = o.customer_id
        LEFT JOIN wholesalers w ON w.wholesaler_id = o.wholesaler_id
        LEFT JOIN (
            SELECT order_id, SUM(amount) AS amount_paid
            FROM payments WHERE status = "paid" GROUP BY order_id
        ) paid ON paid.order_id = o.order_id
        WHERE o.manager_approval = "approved"
          AND o.delivery_status <> "cancelled"
          AND o.total_amount - COALESCE(paid.amount_paid, 0) > 0.009
        ORDER BY o.order_date ASC
    ')->fetchAll();

    $supplierPayments = $pdo->query('
        SELECT sp.*, s.name AS supplier_name
        FROM supplier_payments sp
        LEFT JOIN suppliers s ON s.supplier_id = sp.supplier_id
        ORDER BY sp.created_at DESC
    ')->fetchAll();

    $totals = [
        'customer_collected'   => array_sum(array_map(static fn ($p) => $p['status'] === 'paid' ? (float)$p['amount'] : 0, $customerPayments)),
        'wholesaler_collected' => array_sum(array_map(static fn ($p) => $p['status'] === 'paid' ? (float)$p['amount'] : 0, $wholesalerPayments)),
        'outstanding'          => array_sum(array_map(static fn ($o) => (float)$o['balance_due'], $outstanding)),
        'supplier_paid'        => array_sum(array_map(static fn ($p) => $p['status'] === 'paid' ? (float)$p['amount'] : 0, $supplierPayments)),
    ];

    respond(true, 'Payments loaded', [
        'customer_payments'   => $customerPayments,
        'wholesaler_payments' => $wholesalerPayments,
        'outstanding'         => $outstanding,
        'supplier_payments'   => $supplierPayments,
        'totals'              => $totals,
    ]);
});
