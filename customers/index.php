<?php
// customers/index.php
require_once '../includes/auth.php';
requireLogin();

$pdo = Database::getInstance()->getConnection();

// Only manager and account clerk can access customers
if (!hasRole('manager') && !hasRole('account_clerk')) {
    header('Location: ../stock-dashboard.php');
    exit();
}

$customers = $pdo->query("SELECT * FROM customers ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management - Pearl Land</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f5f5f5;
        }

        .container {
            display: flex;
        }

        .sidebar {
            width: 280px;
            background: #8B4513;
            color: white;
            padding: 20px;
            min-height: 100vh;
        }

        .sidebar h2 {
            text-align: center;
            padding: 20px 0;
            border-bottom: 2px solid #D2691E;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            color: white;
            text-decoration: none;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: #D2691E;
        }

        .main-content {
            flex: 1;
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .btn-add {
            background: #8B4513;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
        }

        table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        th {
            background: #8B4513;
            color: white;
            padding: 12px;
            text-align: left;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .actions a {
            margin-right: 10px;
            color: #8B4513;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <h2>🌶️ Pearl Land</h2>
            <div class="sidebar-menu">
                <a href="../pages/dashboards/managerdashboard.html">🏠 Dashboard</a>
                <a href="index.php" class="active">👥 Customers</a>
                <a href="../suppliers/index.php">🚚 Suppliers</a>
                <a href="../products/index.php">📦 Products</a>
                <a href="../orders/index.php">📝 Orders</a>
                <a href="../reports/index.php">📊 Reports</a>
            </div>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Customer Management</h1>
                <a href="add.php" class="btn-add">+ Add Customer</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><?php echo $customer['customer_code']; ?></td>
                        <td><?php echo $customer['name']; ?></td>
                        <td><?php echo $customer['phone']; ?></td>
                        <td><?php echo $customer['email']; ?></td>
                        <td class="actions">
                            <a href="edit.php?id=<?php echo $customer['customer_id']; ?>">Edit</a>
                            <a href="delete.php?id=<?php echo $customer['customer_id']; ?>" onclick="return confirm('Are you sure?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
