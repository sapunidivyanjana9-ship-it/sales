<?php
require_once __DIR__ . '/db_connect.php';

endpoint_guard(function (): void {
    require_method(['POST']);
    $user = require_roles(['customer', 'wholesaler', 'admin', 'manager']);
    $input = json_input();
    if (empty($input['items']) || !is_array($input['items'])) {
        fail('Order items are required', 422);
    }

    $orderType = $input['order_type'] ?? ($user['role'] === 'wholesaler' ? 'wholesaler' : 'customer');
    if (!in_array($orderType, ['customer', 'wholesaler'], true)) {
        fail('Invalid order type', 422);
    }

    $pdo = get_pdo();
    $pdo->beginTransaction();

    $customerId = $input['customer_id'] ?? null;
    $wholesalerId = $input['wholesaler_id'] ?? null;
    if (in_array($user['role'], ['customer', 'wholesaler'], true)) {
        $party = find_party_for_user((int)$user['user_id'], $user['role']);
        if (!$party) {
            $pdo->rollBack();
            fail('Your profile record was not found', 404);
        }
        $customerId = $user['role'] === 'customer' ? $party['id'] : null;
        $wholesalerId = $user['role'] === 'wholesaler' ? $party['id'] : null;
        $orderType = $user['role'];
    }

    $subtotal = 0.0;
    $items = [];
    $productStmt = $pdo->prepare('SELECT * FROM products WHERE product_id = ? AND status = "active" FOR UPDATE');
    foreach ($input['items'] as $item) {
        $productStmt->execute([(int)($item['product_id'] ?? 0)]);
        $product = $productStmt->fetch();
        if (!$product) {
            $pdo->rollBack();
            fail('Product not found', 404);
        }

        $qty = (float)($item['quantity'] ?? 0);
        if ($qty <= 0) {
            $pdo->rollBack();
            fail('Quantity must be greater than zero', 422);
        }
        if ((float)$product['current_stock'] < $qty) {
            $pdo->rollBack();
            fail($product['name'] . ' does not have enough stock', 409);
        }

        $unitPrice = $orderType === 'wholesaler' && $product['wholesale_price'] !== null
            ? (float)$product['wholesale_price']
            : (float)$product['price'];
        $lineTotal = $qty * $unitPrice;
        $subtotal += $lineTotal;
        $items[] = [$product, $qty, $unitPrice, $lineTotal];
    }

    $shipping = (float)($input['shipping_amount'] ?? 0);
    $discount = (float)($input['discount_amount'] ?? 0);
    $tax = (float)($input['tax_amount'] ?? 0);
    $total = max(0, $subtotal + $shipping + $tax - $discount);
    $orderCode = generate_code('ORD');

    // Cash on delivery is only ever settled by the account clerk once the
    // order is actually delivered, so it starts 'pending' either way - the
    // method is recorded so every dashboard shows how it will be paid.
    $paymentMethod = trim((string)($input['payment_method'] ?? '')) ?: null;

    $stmt = $pdo->prepare('
        INSERT INTO orders
            (order_code, customer_id, wholesaler_id, order_type, delivery_date, delivery_region, shipping_amount, subtotal, discount_amount, tax_amount, total_amount, payment_method, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $orderCode,
        $customerId,
        $wholesalerId,
        $orderType,
        $input['delivery_date'] ?? null,
        $input['delivery_region'] ?? null,
        $shipping,
        $subtotal,
        $discount,
        $tax,
        $total,
        $paymentMethod,
        $input['notes'] ?? null,
        $user['user_id'],
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?)');
    $updateStock = $pdo->prepare('UPDATE products SET current_stock = current_stock - ? WHERE product_id = ?');
    $movement = $pdo->prepare('
        INSERT INTO stock_movements (product_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, reason, created_by)
        VALUES (?, "out", ?, ?, ?, "order", ?, "Order created", ?)
    ');

    foreach ($items as [$product, $qty, $unitPrice, $lineTotal]) {
        $newStock = (float)$product['current_stock'] - $qty;
        $insertItem->execute([$orderId, $product['product_id'], $product['name'], $qty, $unitPrice, $lineTotal]);
        $updateStock->execute([$qty, $product['product_id']]);
        $movement->execute([$product['product_id'], $qty, $product['current_stock'], $newStock, $orderId, $user['user_id']]);
    }

    // Tell the manager there is something to approve. Without this the order
    // only existed in the buyer's own dashboard and nobody upstream ever
    // learned about it.
    $placedBy = $user['role'] === 'wholesaler' ? 'Wholesaler' : 'Customer';
    $managers = $pdo->query('SELECT user_id, role FROM users WHERE role IN ("manager", "admin") AND status = "active"')->fetchAll();
    foreach ($managers as $manager) {
        create_notification(
            (int)$manager['user_id'],
            $manager['role'],
            'info',
            'New order awaiting approval',
            "{$placedBy} order {$orderCode} (Rs. " . number_format($total, 2) . ') is waiting for your approval.'
        );
    }

    $pdo->commit();
    respond(true, 'Order created successfully', ['order_id' => $orderId, 'order_code' => $orderCode], 201);
});
?>
