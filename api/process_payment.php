<?php
require_once __DIR__ . '/db_connect.php';

/**
 * Records a payment. Customer/wholesaler collections are written against the
 * real order they settle, and the order's own payment_status is recomputed
 * from the sum of its paid rows - so "paid" on the account clerk's dashboard
 * is the same "paid" the customer and the manager see, rather than three
 * unrelated localStorage copies.
 */
endpoint_guard(function (): void {
    require_method(['POST']);
    $user = require_roles(['account_clerk', 'manager', 'admin']);
    $input = json_input();
    require_fields($input, ['amount', 'payment_method']);

    $type = $input['payment_type'] ?? 'customer';
    $amount = (float)$input['amount'];
    if ($amount <= 0) {
        fail('Payment amount must be greater than zero', 422);
    }

    $pdo = get_pdo();

    if ($type === 'supplier') {
        require_fields($input, ['supplier_id']);
        $stmt = $pdo->prepare('
            INSERT INTO supplier_payments (grn_id, purchase_order_id, supplier_id, invoice_number, amount, payment_method, reference_no, status, due_date, payment_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $input['grn_id'] ?? null,
            $input['purchase_order_id'] ?? null,
            $input['supplier_id'],
            $input['invoice_number'] ?? null,
            $amount,
            $input['payment_method'],
            $input['reference_no'] ?? null,
            $input['status'] ?? 'paid',
            $input['due_date'] ?? null,
            $input['payment_date'] ?? date('Y-m-d'),
            $input['notes'] ?? null,
            $user['user_id'],
        ]);

        respond(true, 'Payment processed', ['payment_id' => (int)$pdo->lastInsertId()], 201);
    }

    if (!in_array($type, ['customer', 'wholesaler'], true)) {
        fail('Invalid payment type', 422);
    }

    $pdo->beginTransaction();

    $order = null;
    $customerId = $input['customer_id'] ?? null;
    $wholesalerId = $input['wholesaler_id'] ?? null;

    if (!empty($input['order_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_id = ? FOR UPDATE');
        $stmt->execute([(int)$input['order_id']]);
        $order = $stmt->fetch();
        if (!$order) {
            $pdo->rollBack();
            fail('Order not found', 404);
        }
        if ($order['manager_approval'] !== 'approved') {
            $pdo->rollBack();
            fail('This order has not been approved by the manager yet', 409);
        }
        // Always take the party from the order itself, so a payment can never
        // be filed against the wrong customer.
        $customerId = $order['customer_id'];
        $wholesalerId = $order['wholesaler_id'];
        $type = $order['order_type'] === 'wholesaler' ? 'wholesaler' : 'customer';
    }

    $status = $input['status'] ?? 'paid';
    if (!in_array($status, ['pending', 'paid', 'partial', 'failed', 'refunded'], true)) {
        $pdo->rollBack();
        fail('Invalid payment status', 422);
    }

    $stmt = $pdo->prepare('
        INSERT INTO payments (order_id, customer_id, wholesaler_id, payment_type, amount, payment_method, reference_no, status, payment_date, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $order ? (int)$order['order_id'] : null,
        $customerId ?: null,
        $wholesalerId ?: null,
        $type,
        $amount,
        $input['payment_method'],
        $input['reference_no'] ?? null,
        $status,
        !empty($input['payment_date']) ? $input['payment_date'] . ' 00:00:00' : date('Y-m-d H:i:s'),
        $input['notes'] ?? null,
    ]);
    $paymentId = (int)$pdo->lastInsertId();

    $orderStatus = null;
    if ($order) {
        $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = "paid"');
        $paidStmt->execute([(int)$order['order_id']]);
        $paid = (float)$paidStmt->fetchColumn();

        // "processing" is this schema's only partial-payment state - the
        // orders.payment_status enum has no 'partial'.
        $orderStatus = $paid + 0.009 >= (float)$order['total_amount'] ? 'paid' : ($paid > 0 ? 'processing' : 'pending');

        $pdo->prepare('UPDATE orders SET payment_status = ?, payment_method = ? WHERE order_id = ?')
            ->execute([$orderStatus, $input['payment_method'], (int)$order['order_id']]);

        $buyerRole = $type === 'wholesaler' ? 'wholesaler' : 'customer';
        $buyerStmt = $buyerRole === 'customer'
            ? $pdo->prepare('SELECT user_id FROM customers WHERE customer_id = ?')
            : $pdo->prepare('SELECT user_id FROM wholesalers WHERE wholesaler_id = ?');
        $buyerStmt->execute([$buyerRole === 'customer' ? $customerId : $wholesalerId]);
        $buyerUserId = $buyerStmt->fetchColumn();

        if ($buyerUserId) {
            create_notification((int)$buyerUserId, $buyerRole, 'success', 'Payment recorded',
                'Rs. ' . number_format($amount, 2) . " received against order {$order['order_code']}.");
        }
    }

    $pdo->commit();

    respond(true, 'Payment processed', [
        'payment_id' => $paymentId,
        'order_payment_status' => $orderStatus,
    ], 201);
});
