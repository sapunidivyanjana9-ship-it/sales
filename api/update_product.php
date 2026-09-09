<?php
require_once __DIR__ . '/db_connect.php';

/**
 * Edits a product's catalogue fields. Stock changes go through the same
 * ledger api/update_stock.php writes to, so a manual correction on the
 * manager's Products screen is still traceable in stock_movements rather
 * than silently overwriting current_stock.
 */
endpoint_guard(function (): void {
    require_method(['POST', 'PUT', 'PATCH']);
    $user = require_roles(['manager', 'stock_clerk', 'admin']);
    $input = json_input();

    $pdo = get_pdo();

    if (!empty($input['product_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
        $stmt->execute([(int)$input['product_id']]);
    } elseif (!empty($input['product_code'])) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE product_code = ?');
        $stmt->execute([$input['product_code']]);
    } else {
        fail('product_id or product_code is required', 422);
    }

    $product = $stmt->fetch();
    if (!$product) {
        fail('Product not found', 404);
    }

    $pdo->beginTransaction();

    $fields = ['name', 'category', 'sub_category', 'description', 'unit', 'price', 'wholesale_price', 'cost_price', 'reorder_level', 'status'];
    $sets = [];
    $params = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $input)) {
            $sets[] = "{$field} = ?";
            $params[] = in_array($field, ['price', 'wholesale_price', 'cost_price', 'reorder_level'], true)
                ? (float)$input[$field]
                : $input[$field];
        }
    }

    if ($sets) {
        $params[] = (int)$product['product_id'];
        $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE product_id = ?')->execute($params);
    }

    if (array_key_exists('current_stock', $input)) {
        $newStock = (float)$input['current_stock'];
        if ($newStock < 0) {
            $pdo->rollBack();
            fail('Stock cannot be negative', 422);
        }
        $previous = (float)$product['current_stock'];
        if (abs($newStock - $previous) > 0.0001) {
            $pdo->prepare('UPDATE products SET current_stock = ? WHERE product_id = ?')
                ->execute([$newStock, (int)$product['product_id']]);
            $pdo->prepare('
                INSERT INTO stock_movements (product_id, movement_type, quantity, previous_stock, new_stock, reference_type, reason, created_by)
                VALUES (?, "adjustment", ?, ?, ?, "manual", ?, ?)
            ')->execute([
                (int)$product['product_id'],
                abs($newStock - $previous),
                $previous,
                $newStock,
                $input['reason'] ?? 'Adjusted from the manager dashboard',
                $user['user_id'],
            ]);
        }
    }

    $pdo->commit();

    $stmt = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
    $stmt->execute([(int)$product['product_id']]);

    respond(true, 'Product updated', ['product' => $stmt->fetch()]);
});
