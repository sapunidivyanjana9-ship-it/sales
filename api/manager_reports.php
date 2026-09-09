<?php
require_once __DIR__ . '/db_connect.php';

/**
 * Server-side aggregates behind the manager dashboard's Reports section.
 *
 * Every figure here is computed from the live tables (orders, order_items,
 * payments, products, purchase_orders, ...) for the requested date range,
 * so "update the reports" means re-running this endpoint rather than
 * re-reading whatever demo rows happened to be sitting in localStorage.
 *
 * GET ?from=YYYY-MM-DD&to=YYYY-MM-DD
 */
endpoint_guard(function (): void {
    require_method(['GET']);
    require_roles(['manager', 'admin']);

    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    foreach ([$from, $to] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            fail('Dates must be in YYYY-MM-DD format', 422);
        }
    }
    if ($from > $to) {
        fail('The "from" date must not be after the "to" date', 422);
    }

    $pdo = get_pdo();
    $range = [$from, $to];

    $q = static function (PDO $pdo, string $sql, array $params = []) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    };

    $orders = $q($pdo, '
        SELECT o.order_id, o.order_code, o.order_type, o.order_date, o.total_amount, o.subtotal,
               o.discount_amount, o.shipping_amount, o.manager_approval, o.stock_clerk_approval,
               o.delivery_status, o.payment_status, o.payment_method,
               COALESCE(c.name, w.company_name, "Unknown") AS party_name
        FROM orders o
        LEFT JOIN customers c ON c.customer_id = o.customer_id
        LEFT JOIN wholesalers w ON w.wholesaler_id = o.wholesaler_id
        WHERE DATE(o.order_date) BETWEEN ? AND ?
        ORDER BY o.order_date DESC
    ', $range);

    // Attach line items - the manager dashboard renders product names and
    // quantities straight off these, so a report without them shows
    // "No items / 0kg" for every row.
    if ($orders) {
        $ids = array_column($orders, 'order_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ({$in})");
        $itemStmt->execute($ids);

        $itemsByOrder = [];
        foreach ($itemStmt->fetchAll() as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }
        foreach ($orders as &$order) {
            $order['items'] = $itemsByOrder[$order['order_id']] ?? [];
        }
        unset($order);
    }

    $countBy = static function (array $rows, string $field, string $value): int {
        return count(array_filter($rows, static fn ($r) => $r[$field] === $value));
    };
    $sumOf = static function (array $rows, string $field): float {
        return array_sum(array_map(static fn ($r) => (float)$r[$field], $rows));
    };

    $customerOrders = array_values(array_filter($orders, static fn ($o) => $o['order_type'] === 'customer'));
    $wholesalerOrders = array_values(array_filter($orders, static fn ($o) => $o['order_type'] === 'wholesaler'));
    // Revenue is only counted once an order has actually been approved -
    // a pending or rejected order is not money the business has earned.
    $earned = array_values(array_filter($orders, static fn ($o) => $o['manager_approval'] === 'approved'));

    $summary = [
        'from' => $from,
        'to' => $to,
        'total_orders' => count($orders),
        'customer_orders' => count($customerOrders),
        'wholesaler_orders' => count($wholesalerOrders),
        'pending_approval' => $countBy($orders, 'manager_approval', 'pending'),
        'approved' => $countBy($orders, 'manager_approval', 'approved'),
        'rejected' => $countBy($orders, 'manager_approval', 'rejected'),
        'awaiting_stock_clerk' => count(array_filter($orders, static fn ($o) => $o['manager_approval'] === 'approved' && $o['stock_clerk_approval'] === 'pending')),
        'delivered' => $countBy($orders, 'delivery_status', 'delivered'),
        'in_delivery' => count(array_filter($orders, static fn ($o) => in_array($o['delivery_status'], ['processing', 'shipped'], true))),
        'cancelled' => $countBy($orders, 'delivery_status', 'cancelled'),
        'gross_order_value' => $sumOf($orders, 'total_amount'),
        'approved_order_value' => $sumOf($earned, 'total_amount'),
        'paid_orders' => $countBy($orders, 'payment_status', 'paid'),
        'unpaid_value' => $sumOf(array_values(array_filter($earned, static fn ($o) => $o['payment_status'] !== 'paid')), 'total_amount'),
    ];

    $payments = $q($pdo, '
        SELECT p.payment_id, p.payment_type, p.amount, p.payment_method, p.status, p.reference_no,
               DATE(p.payment_date) AS payment_date, o.order_code,
               COALESCE(c.name, w.company_name, "Unknown") AS party_name
        FROM payments p
        LEFT JOIN orders o ON o.order_id = p.order_id
        LEFT JOIN customers c ON c.customer_id = COALESCE(p.customer_id, o.customer_id)
        LEFT JOIN wholesalers w ON w.wholesaler_id = COALESCE(p.wholesaler_id, o.wholesaler_id)
        WHERE DATE(p.payment_date) BETWEEN ? AND ?
        ORDER BY p.payment_date DESC
    ', $range);

    $productPerformance = $q($pdo, '
        SELECT oi.product_id, oi.product_name,
               SUM(oi.quantity) AS quantity_sold,
               SUM(oi.line_total) AS revenue,
               COUNT(DISTINCT oi.order_id) AS order_count
        FROM order_items oi
        JOIN orders o ON o.order_id = oi.order_id
        WHERE DATE(o.order_date) BETWEEN ? AND ? AND o.manager_approval <> "rejected"
        GROUP BY oi.product_id, oi.product_name
        ORDER BY revenue DESC
    ', $range);

    $customerPerformance = $q($pdo, '
        SELECT c.customer_id, c.name, c.email,
               COUNT(o.order_id) AS order_count,
               COALESCE(SUM(o.total_amount), 0) AS total_value,
               SUM(o.delivery_status = "delivered") AS delivered_count
        FROM orders o
        JOIN customers c ON c.customer_id = o.customer_id
        WHERE DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY c.customer_id, c.name, c.email
        ORDER BY total_value DESC
    ', $range);

    $wholesalerPerformance = $q($pdo, '
        SELECT w.wholesaler_id, w.company_name AS name, w.email,
               COUNT(o.order_id) AS order_count,
               COALESCE(SUM(o.total_amount), 0) AS total_value,
               SUM(o.delivery_status = "delivered") AS delivered_count
        FROM orders o
        JOIN wholesalers w ON w.wholesaler_id = o.wholesaler_id
        WHERE DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY w.wholesaler_id, w.company_name, w.email
        ORDER BY total_value DESC
    ', $range);

    $inventory = $q($pdo, '
        SELECT product_id, name, category, unit, price, wholesale_price,
               current_stock, reorder_level, status
        FROM products
        ORDER BY name
    ');

    $supplierPerformance = $q($pdo, '
        SELECT s.supplier_id, s.name, s.email,
               COUNT(po.purchase_order_id) AS po_count,
               COALESCE(SUM(po.total_amount), 0) AS po_value
        FROM suppliers s
        LEFT JOIN purchase_orders po
               ON po.supplier_id = s.supplier_id
              AND DATE(po.created_at) BETWEEN ? AND ?
        GROUP BY s.supplier_id, s.name, s.email
        ORDER BY po_value DESC
    ', $range);

    $dailySales = $q($pdo, '
        SELECT DATE(o.order_date) AS day,
               COUNT(*) AS order_count,
               COALESCE(SUM(o.total_amount), 0) AS total_value
        FROM orders o
        WHERE DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY DATE(o.order_date)
        ORDER BY day
    ', $range);

    respond(true, 'Reports generated', [
        'summary' => $summary,
        'orders' => $orders,
        'payments' => $payments,
        'product_performance' => $productPerformance,
        'customer_performance' => $customerPerformance,
        'wholesaler_performance' => $wholesalerPerformance,
        'supplier_performance' => $supplierPerformance,
        'inventory' => $inventory,
        'daily_sales' => $dailySales,
        'generated_at' => date('c'),
    ]);
});
