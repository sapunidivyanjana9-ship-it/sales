<?php
require_once __DIR__ . '/db_connect.php';

/**
 * Single entry point for every stage transition an order goes through after
 * it is placed:
 *
 *   placed -> manager_approval -> stock_clerk_approval -> delivery_status
 *          -> payment_status
 *
 * Each stage is gated on the previous one so the dashboards can't push an
 * order out of order (a stock clerk approving something the manager hasn't
 * approved, a delivery being marked shipped before the stock clerk released
 * it, ...), and each transition notifies the parties who need to act next.
 */

const ORDER_STAGE_ROLES = [
    'manager_approval'     => ['manager', 'admin'],
    'stock_clerk_approval' => ['stock_clerk', 'manager', 'admin'],
    'delivery_status'      => ['stock_clerk', 'manager', 'admin'],
    'payment_status'       => ['account_clerk', 'manager', 'admin'],
    'payment_method'       => ['account_clerk', 'manager', 'admin'],
    'tracking_number'      => ['stock_clerk', 'manager', 'admin'],
    'rejection_reason'     => ['manager', 'stock_clerk', 'admin'],
];

const ORDER_STAGE_VALUES = [
    'manager_approval'     => ['pending', 'approved', 'rejected'],
    'stock_clerk_approval' => ['pending', 'approved', 'rejected'],
    'delivery_status'      => ['pending', 'processing', 'shipped', 'delivered', 'cancelled'],
    'payment_status'       => ['pending', 'processing', 'paid', 'failed'],
];

/**
 * The user_id of whoever placed the order, so status changes can be pushed
 * back to the customer's / wholesaler's own dashboard as a notification.
 */
function order_buyer(PDO $pdo, array $order): ?array
{
    if (!empty($order['customer_id'])) {
        $stmt = $pdo->prepare('SELECT u.user_id, u.role, c.name FROM customers c JOIN users u ON u.user_id = c.user_id WHERE c.customer_id = ?');
        $stmt->execute([(int)$order['customer_id']]);
    } elseif (!empty($order['wholesaler_id'])) {
        $stmt = $pdo->prepare('SELECT u.user_id, u.role, w.company_name AS name FROM wholesalers w JOIN users u ON u.user_id = w.user_id WHERE w.wholesaler_id = ?');
        $stmt->execute([(int)$order['wholesaler_id']]);
    } else {
        return null;
    }

    return $stmt->fetch() ?: null;
}

/** Notifies every active user holding one of the given roles. */
function notify_roles(PDO $pdo, array $roles, string $type, string $title, string $message, ?string $link = null): void
{
    $in = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT user_id, role FROM users WHERE role IN ({$in}) AND status = 'active'");
    $stmt->execute($roles);
    foreach ($stmt->fetchAll() as $row) {
        create_notification((int)$row['user_id'], $row['role'], $type, $title, $message, $link);
    }
}

/**
 * Puts the stock a rejected/cancelled order was holding back on the shelf.
 * create_order.php decrements current_stock when the order is placed, so an
 * order that never ships has to give it back or the stock silently leaks.
 */
function restock_order(PDO $pdo, int $orderId, int $userId, string $reason): void
{
    $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ? AND product_id IS NOT NULL');
    $items->execute([$orderId]);

    $read = $pdo->prepare('SELECT current_stock FROM products WHERE product_id = ? FOR UPDATE');
    $write = $pdo->prepare('UPDATE products SET current_stock = current_stock + ? WHERE product_id = ?');
    $movement = $pdo->prepare('
        INSERT INTO stock_movements (product_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, reason, created_by)
        VALUES (?, "in", ?, ?, ?, "order", ?, ?, ?)
    ');

    foreach ($items->fetchAll() as $item) {
        $read->execute([(int)$item['product_id']]);
        $previous = (float)$read->fetchColumn();
        $qty = (float)$item['quantity'];
        $write->execute([$qty, (int)$item['product_id']]);
        $movement->execute([(int)$item['product_id'], $qty, $previous, $previous + $qty, $orderId, $reason, $userId]);
    }
}

endpoint_guard(function (): void {
    require_method(['POST', 'PUT', 'PATCH']);
    $user = require_roles(['manager', 'stock_clerk', 'account_clerk', 'admin']);
    $input = json_input();
    require_fields($input, ['order_id']);

    $pdo = get_pdo();
    $orderId = (int)$input['order_id'];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        $pdo->rollBack();
        fail('Order not found', 404);
    }

    $sets = [];
    $params = [];
    $changed = [];

    foreach (ORDER_STAGE_ROLES as $field => $roles) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        if (!in_array($user['role'], $roles, true)) {
            $pdo->rollBack();
            fail("Your role cannot update {$field}", 403);
        }

        $value = $input[$field];
        if (isset(ORDER_STAGE_VALUES[$field])) {
            $value = strtolower(trim((string)$value));
            if (!in_array($value, ORDER_STAGE_VALUES[$field], true)) {
                $pdo->rollBack();
                fail("Invalid value for {$field}: {$input[$field]}", 422);
            }
        }

        $sets[] = "{$field} = ?";
        $params[] = $value;
        $changed[$field] = $value;
    }

    if (!$sets) {
        $pdo->rollBack();
        fail('No status fields supplied', 422);
    }

    // ---- Workflow gating: a stage can only move once the one before it has ----
    $managerApproval = $changed['manager_approval'] ?? $order['manager_approval'];
    $stockApproval = $changed['stock_clerk_approval'] ?? $order['stock_clerk_approval'];

    if (($changed['stock_clerk_approval'] ?? 'pending') !== 'pending' && $managerApproval !== 'approved') {
        $pdo->rollBack();
        fail('The manager must approve this order before the stock clerk can release it', 409);
    }

    $deliveryTarget = $changed['delivery_status'] ?? null;
    if ($deliveryTarget !== null && !in_array($deliveryTarget, ['pending', 'cancelled'], true) && $stockApproval !== 'approved') {
        $pdo->rollBack();
        fail('The stock clerk must approve this order before its delivery can progress', 409);
    }

    if (($changed['payment_status'] ?? null) === 'paid' && $managerApproval !== 'approved') {
        $pdo->rollBack();
        fail('Payment cannot be settled against an order the manager has not approved', 409);
    }

    // ---- Automatic follow-on transitions ----
    // Stock clerk approval is what actually puts an order into fulfilment,
    // which is what makes it show up in the Delivery tab. Rejection anywhere
    // cancels the delivery and returns the reserved stock.
    if (($changed['stock_clerk_approval'] ?? null) === 'approved'
        && !array_key_exists('delivery_status', $changed)
        && $order['delivery_status'] === 'pending') {
        $sets[] = 'delivery_status = ?';
        $params[] = 'processing';
        $changed['delivery_status'] = 'processing';
    }

    $rejected = ($changed['manager_approval'] ?? null) === 'rejected'
        || ($changed['stock_clerk_approval'] ?? null) === 'rejected';

    if ($rejected && !array_key_exists('delivery_status', $changed)) {
        $sets[] = 'delivery_status = ?';
        $params[] = 'cancelled';
        $changed['delivery_status'] = 'cancelled';
    }

    if (($changed['manager_approval'] ?? null) === 'approved') {
        $sets[] = 'approved_by = ?';
        $params[] = (int)$user['user_id'];
        $sets[] = 'approved_at = NOW()';
    }

    $params[] = $orderId;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE order_id = ?')->execute($params);

    // Give the stock back exactly once - only on the transition into a
    // rejected state, never on a repeat call that re-sets the same value.
    if ($rejected
        && $order['manager_approval'] !== 'rejected'
        && $order['stock_clerk_approval'] !== 'rejected') {
        restock_order($pdo, $orderId, (int)$user['user_id'], 'Order rejected');
    }

    // ---- Notifications ----
    $buyer = order_buyer($pdo, $order);
    $code = $order['order_code'];

    if (isset($changed['manager_approval'])) {
        if ($changed['manager_approval'] === 'approved') {
            if ($buyer) {
                create_notification((int)$buyer['user_id'], $buyer['role'], 'success', 'Order approved',
                    "Order {$code} was approved by the manager and is now with the stock clerk.");
            }
            notify_roles($pdo, ['stock_clerk'], 'info', 'Order awaiting stock approval',
                "Order {$code} was approved by the manager and needs stock clerk release.");
        } elseif ($changed['manager_approval'] === 'rejected') {
            if ($buyer) {
                create_notification((int)$buyer['user_id'], $buyer['role'], 'error', 'Order rejected',
                    "Order {$code} was rejected by the manager." . (!empty($changed['rejection_reason']) ? ' Reason: ' . $changed['rejection_reason'] : ''));
            }
        }
    }

    if (isset($changed['stock_clerk_approval'])) {
        if ($changed['stock_clerk_approval'] === 'approved') {
            if ($buyer) {
                create_notification((int)$buyer['user_id'], $buyer['role'], 'success', 'Order in processing',
                    "Order {$code} was released by the stock clerk and is being prepared for delivery.");
            }
            notify_roles($pdo, ['manager', 'account_clerk'], 'info', 'Order released for delivery',
                "Order {$code} was released by the stock clerk and is now in delivery.");
        } elseif ($changed['stock_clerk_approval'] === 'rejected' && $buyer) {
            create_notification((int)$buyer['user_id'], $buyer['role'], 'error', 'Order cancelled',
                "Order {$code} could not be fulfilled by the stock clerk." . (!empty($changed['rejection_reason']) ? ' Reason: ' . $changed['rejection_reason'] : ''));
        }
    }

    if (($changed['delivery_status'] ?? null) === 'delivered') {
        if ($buyer) {
            create_notification((int)$buyer['user_id'], $buyer['role'], 'success', 'Order delivered',
                "Order {$code} has been delivered.");
        }
        notify_roles($pdo, ['manager', 'account_clerk'], 'success', 'Order delivered',
            "Order {$code} has been delivered and is ready for payment settlement.");
    } elseif (($changed['delivery_status'] ?? null) === 'shipped' && $buyer) {
        create_notification((int)$buyer['user_id'], $buyer['role'], 'info', 'Order shipped',
            "Order {$code} has been shipped.");
    }

    if (($changed['payment_status'] ?? null) === 'paid' && $buyer) {
        create_notification((int)$buyer['user_id'], $buyer['role'], 'success', 'Payment received',
            "Payment for order {$code} has been recorded.");
    }

    $pdo->commit();

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_id = ?');
    $stmt->execute([$orderId]);

    respond(true, 'Order status updated', ['order' => $stmt->fetch()]);
});
