<?php
require_once __DIR__ . '/db_connect.php';

/**
 * The single source of truth for orders on every dashboard.
 *
 * Customers and wholesalers see only their own orders; manager, stock clerk,
 * account clerk and admin see all of them (that is what makes a customer's
 * order show up on the manager's dashboard at all - previously each
 * dashboard kept its own private copy in localStorage and nothing crossed
 * between roles).
 *
 * Optional filters: status (delivery), manager_approval, stock_clerk_approval,
 * payment_status, order_type.
 */
endpoint_guard(function (): void {
    require_method(['GET']);
    $user = require_auth();

    $params = [];
    $where = ['1=1'];

    if (in_array($user['role'], ['customer', 'wholesaler'], true)) {
        $party = find_party_for_user((int)$user['user_id'], $user['role']);
        if (!$party) {
            respond(true, 'No orders found', ['orders' => []]);
        }
        $where[] = $user['role'] === 'customer' ? 'o.customer_id = ?' : 'o.wholesaler_id = ?';
        $params[] = $party['id'];
    }

    $filters = [
        'status'               => 'o.delivery_status',
        'delivery_status'      => 'o.delivery_status',
        'manager_approval'     => 'o.manager_approval',
        'stock_clerk_approval' => 'o.stock_clerk_approval',
        'payment_status'       => 'o.payment_status',
        'order_type'           => 'o.order_type',
    ];
    foreach ($filters as $param => $column) {
        if (!empty($_GET[$param])) {
            $where[] = "{$column} = ?";
            $params[] = $_GET[$param];
        }
    }

    if (!empty($_GET['from'])) {
        $where[] = 'DATE(o.order_date) >= ?';
        $params[] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $where[] = 'DATE(o.order_date) <= ?';
        $params[] = $_GET['to'];
    }

    $pdo = get_pdo();
    $stmt = $pdo->prepare('
        SELECT o.*,
               c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
               w.company_name AS wholesaler_name, w.email AS wholesaler_email, w.phone AS wholesaler_phone,
               COALESCE(c.name, w.company_name, "Unknown") AS party_name,
               (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.order_id = o.order_id AND p.status = "paid") AS amount_paid
        FROM orders o
        LEFT JOIN customers c ON c.customer_id = o.customer_id
        LEFT JOIN wholesalers w ON w.wholesaler_id = o.wholesaler_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY o.order_date DESC, o.order_id DESC
    ');
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    if ($orders) {
        // One query for every line item instead of one per order.
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

    respond(true, 'Orders loaded', ['orders' => $orders]);
});
