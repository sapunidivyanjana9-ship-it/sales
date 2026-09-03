<?php
// database-setup.php
// Script to automatically create the MySQL database and tables for Pearl Land Commodities (PELCOMO)
// Uses the Database class for connection management.

require_once __DIR__ . '/../classes/Database.php';

// DB_HOST / DB_USER / DB_PASS / DB_NAME come from config/env.php (already
// pulled in by classes/Database.php above) - env vars / .env / XAMPP-style
// defaults, so this bootstrap script targets the same DB as the app itself.

try {
    // 1. Connect to MySQL Server (without database) to create the DB first
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8 COLLATE utf8_general_ci;");
    echo "<h3>✅ Database `" . DB_NAME . "` created or already exists.</h3>";
    
    // 3. Now connect via the Database singleton class
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // 4. Create Tables
    
    // Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");
    echo "📊 Table `users` created.<br>";

    // Customers Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        customer_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        phone VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");
    echo "📊 Table `customers` created.<br>";

    // Suppliers Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        supplier_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        contact VARCHAR(100),
        email VARCHAR(100),
        phone VARCHAR(20),
        materials VARCHAR(255),
        orders_cost DECIMAL(10,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");
    echo "📊 Table `suppliers` created.<br>";

    // Products Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        product_id INT AUTO_INCREMENT PRIMARY KEY,
        product_code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        category VARCHAR(50),
        price DECIMAL(10,2) NOT NULL,
        current_stock DECIMAL(10,2) DEFAULT 0.00,
        reorder_level DECIMAL(10,2) DEFAULT 10.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");
    echo "📊 Table `products` created.<br>";

    // Orders Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        order_id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(20) NOT NULL UNIQUE,
        customer_id INT,
        order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        total_amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL
    ) ENGINE=InnoDB;");
    echo "📊 Table `orders` created.<br>";

    // Payments Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50),
        status VARCHAR(50) DEFAULT 'pending',
        payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL
    ) ENGINE=InnoDB;");
    echo "📊 Table `payments` created.<br>";

    // 5. Insert Sample Data if empty
    
    // Insert Users
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)");
        $stmt->execute(['manager', 'manager123', 'manager', 'Admin Manager']);
        $stmt->execute(['clerk', 'clerk123', 'stock_clerk', 'Stock Clerk']);
        $stmt->execute(['account', 'account123', 'account_clerk', 'Account Clerk']);
        echo "🌱 Default Users inserted (manager, clerk, account).<br>";
    }

    // Insert Customers
    $custCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    if ($custCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO customers (customer_code, name, email, phone) VALUES (?, ?, ?, ?)");
        $stmt->execute(['C001', 'Saman Perera', 'saman@gmail.com', '071-1234567']);
        $stmt->execute(['C002', 'City Market', 'city@market.com', '077-4567890']);
        echo "🌱 Sample Customers inserted.<br>";
    }

    // Insert Suppliers
    $supCount = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
    if ($supCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact, email, phone, materials, orders_cost) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(['Lanka Spices', 'Kamal Perera', 'lanka@spices.com', '071-1234567', 'Turmeric, Chili', 500000.00]);
        $stmt->execute(['Kalutara Farmers', 'Nimal Silva', 'kalutara@farmers.com', '072-7654321', 'Coriander, Pepper', 320000.00]);
        echo "🌱 Sample Suppliers inserted.<br>";
    }

    // Insert Products
    $prodCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ($prodCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO products (product_code, name, category, price, current_stock, reorder_level) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(['P001', 'Turmeric Powder', 'Spice', 500.00, 450.00, 100.00]);
        $stmt->execute(['P002', 'Chili Powder', 'Spice', 400.00, 15.00, 50.00]);
        $stmt->execute(['P003', 'Black Pepper', 'Spice', 780.00, 8.00, 40.00]);
        $stmt->execute(['P004', 'Cinnamon Sticks', 'Spice', 950.00, 320.00, 100.00]);
        $stmt->execute(['P005', 'Cardamom', 'Whole Spice', 2800.00, 25.00, 30.00]);
        echo "🌱 Sample Products inserted.<br>";
    }

    // Insert Orders & Payments
    $orderCount = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    if ($orderCount == 0) {
        // Find customer IDs
        $customers_list = $pdo->query("SELECT customer_id, name FROM customers")->fetchAll(PDO::FETCH_KEY_PAIR);
        $saman_id = array_search('Saman Perera', $customers_list);
        $city_id = array_search('City Market', $customers_list);
        
        if ($saman_id && $city_id) {
            // ORD-001
            $stmtOrder = $pdo->prepare("INSERT INTO orders (order_code, customer_id, total_amount, status, order_date) VALUES (?, ?, ?, ?, ?)");
            $stmtOrder->execute(['ORD-001', $saman_id, 190000.00, 'delivered', '2026-05-15 10:00:00']);
            $order1_id = $pdo->lastInsertId();
            
            // ORD-002
            $stmtOrder->execute(['ORD-002', $city_id, 117000.00, 'processing', '2026-06-01 11:30:00']);
            $order2_id = $pdo->lastInsertId();
            
            // ORD-003
            $stmtOrder->execute(['ORD-003', $city_id, 54000.00, 'pending', '2026-06-05 14:15:00']);
            $order3_id = $pdo->lastInsertId();
            
            echo "🌱 Sample Orders inserted.<br>";
            
            // Insert Payments
            $stmtPay = $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, status, payment_date) VALUES (?, ?, ?, ?, ?)");
            $stmtPay->execute([$order1_id, 190000.00, 'cash', 'paid', '2026-05-15 10:15:00']);
            $stmtPay->execute([$order2_id, 58000.00, 'card', 'pending', '2026-06-01 11:45:00']);
            $stmtPay->execute([$order3_id, 54000.00, 'cash', 'paid', '2026-06-05 14:30:00']);
            echo "🌱 Sample Payments inserted.<br>";
        }
    }
    
    echo "<h2>🎉 Database setup completed successfully!</h2>";
    echo "<p>You can now use the credentials: <strong>manager / manager123</strong> to login.</p>";
    
} catch (PDOException $e) {
    echo "<h3>❌ Error: " . $e->getMessage() . "</h3>";
}
?>
