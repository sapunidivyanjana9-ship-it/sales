<?php
// ============================================
// PEARLAND COMMODITIES - STOCK CLERK DASHBOARD
// Complete Single File Solution
// ============================================

// ==================== CONFIGURATION ====================
// DB_HOST / DB_USER / DB_PASS come from the centralized config (env vars /
// .env / XAMPP-style defaults) - see config/env.php. This dashboard's own
// database is a second, separate one (stock_clerk_db), so it gets its own
// constant rather than reusing the shared DB_NAME.
require_once __DIR__ . '/../../config/env.php';
define('STOCK_CLERK_DB_NAME', 'stock_clerk_db');

// Main application database (pearl_land_db, from config/env.php's DB_NAME)
// - this is where real supplier accounts live (created via registration +
// admin/manager approval, with login credentials in its `users` table).
// Suppliers are read from here so that only suppliers who can actually log
// in ever show up in this dashboard's Supplier Sample Flow.

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-app-password');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==================== DATABASE FUNCTIONS ====================
function getDB() {
    // Tracks whether this request has already checked/installed the schema,
    // so a fresh clone with no stock_clerk_db yet doesn't need anyone to
    // visit "?action=install" by hand first - opening the dashboard (or
    // logging into it) just creates it, same as pearl_land_db's bootstrap
    // (see config/db_bootstrap.php). Table creation itself is idempotent
    // (CREATE TABLE IF NOT EXISTS), so this check is only an optimization,
    // not a correctness requirement.
    static $verified = false;

    try {
        // Connect to the server first, without selecting a database - the
        // previous version connected straight to STOCK_CLERK_DB_NAME, which
        // fatally errored ("Unknown database") on any server that doesn't
        // already have it, with no way to recover.
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        $conn->query('CREATE DATABASE IF NOT EXISTS `' . STOCK_CLERK_DB_NAME . '`');
        $conn->select_db(STOCK_CLERK_DB_NAME);

        if (!$verified) {
            $verified = true;
            $result = $conn->query("SHOW TABLES LIKE 'users'");
            if ($result && $result->num_rows === 0) {
                installDatabase($conn);
            }
        }

        return $conn;
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
    }
}

// Connection to the main app database (pearl_land_db), used only to look up
// real, login-capable suppliers. Returns null instead of dying if it can't
// connect, so this dashboard's other features keep working even if the two
// databases are ever separated onto different servers.
function getMainDB() {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        return null;
    }
    return $conn;
}

// Real, active suppliers (approved and with a working login account) from
// the main app database, shaped to match the fields this dashboard expects.
function getActiveSuppliers() {
    $main = getMainDB();
    if (!$main) {
        return [];
    }

    $suppliers = [];
    $result = $main->query("
        SELECT s.supplier_code AS id, s.name AS company, s.contact AS contact,
               s.email AS email, s.phone AS phone, s.materials AS materials,
               s.orders_cost AS orders_cost, s.status AS status
        FROM suppliers s
        INNER JOIN users u ON u.user_id = s.user_id
        WHERE s.status = 'active' AND u.status = 'active'
        ORDER BY s.name
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $suppliers[] = $row;
        }
    }
    $main->close();

    return $suppliers;
}

function isAuthenticated() {
    // A session set by either login path (this page's own ?action=login
    // against stock_clerk_db.users, or the main app's api/login.php against
    // pearl_land_db.users) carries a role string - without this check, any
    // logged-in account of any role (customer, supplier, account_clerk...)
    // could fully use this dashboard, including creating GRNs and approving
    // POs.
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
        && in_array($_SESSION['role'] ?? '', ['stock_clerk', 'manager', 'admin'], true);
}

function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: ?action=login');
        exit;
    }
}

function sanitize($data) {
    $conn = getDB();
    return $conn->real_escape_string($data);
}

function sendEmail($to, $subject, $message) {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_USER . "\r\n";
    return mail($to, $subject, $message, $headers);
}

// ==================== INSTALLATION / DATABASE SETUP ====================
function installDatabase($conn = null) {
    // Accepts an existing connection so getDB() can call this directly once
    // it's already connected (avoids getDB() -> installDatabase() -> getDB()
    // recursion); ?action=install still works standalone with no argument.
    $conn = $conn ?: getDB();
    
    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            role VARCHAR(20) DEFAULT 'stock_clerk',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS products (
            id INT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(20) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            category VARCHAR(50),
            unit VARCHAR(10),
            price DECIMAL(10,2),
            stock INT DEFAULT 0,
            reorder_level INT DEFAULT 10,
            supplier VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS raw_materials (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            supplier VARCHAR(100),
            batch VARCHAR(50),
            quantity INT,
            received_date DATE,
            status VARCHAR(20)
        )",
        
        "CREATE TABLE IF NOT EXISTS deliveries (
            id VARCHAR(20) PRIMARY KEY,
            product VARCHAR(100),
            quantity INT,
            order_date DATE,
            expected_date DATE,
            status VARCHAR(20)
        )",
        
        "CREATE TABLE IF NOT EXISTS suppliers (
            id VARCHAR(20) PRIMARY KEY,
            company VARCHAR(100),
            contact VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            materials TEXT,
            orders_cost DECIMAL(10,2),
            status VARCHAR(20),
            approved_date DATE
        )",
        
        "CREATE TABLE IF NOT EXISTS sample_requests (
            id VARCHAR(20) PRIMARY KEY,
            supplier VARCHAR(100),
            material VARCHAR(100),
            qty INT,
            request_date DATE,
            status VARCHAR(50),
            sample_sent BOOLEAN DEFAULT FALSE
        )",
        
        "CREATE TABLE IF NOT EXISTS qc_reports (
            id INT PRIMARY KEY AUTO_INCREMENT,
            sample_id VARCHAR(20),
            supplier VARCHAR(100),
            material VARCHAR(100),
            test_date DATE,
            result VARCHAR(20),
            remarks TEXT,
            price DECIMAL(10,2),
            quality_score DECIMAL(3,1)
        )",
        
        "CREATE TABLE IF NOT EXISTS purchase_orders (
            id VARCHAR(20) PRIMARY KEY,
            sample_id VARCHAR(20),
            supplier VARCHAR(100),
            material VARCHAR(100),
            qty INT,
            unit_price DECIMAL(10,2),
            total DECIMAL(10,2),
            delivery_date DATE,
            terms VARCHAR(50),
            status VARCHAR(50)
        )",
        
        "CREATE TABLE IF NOT EXISTS grns (
            id VARCHAR(20) PRIMARY KEY,
            po_id VARCHAR(20),
            supplier VARCHAR(100),
            material VARCHAR(100),
            ordered_qty INT,
            received_qty INT,
            unit_price DECIMAL(10,2),
            total_amount DECIMAL(10,2),
            received_date DATE,
            inspector VARCHAR(100),
            remarks TEXT,
            status VARCHAR(20)
        )"
    ];
    
    foreach ($queries as $query) {
        $conn->query($query);
    }
    
    // Insert default user if not exists
    $check = $conn->query("SELECT * FROM users WHERE username = 'admin'");
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO users (username, password, email, role) 
                      VALUES ('admin', MD5('password123'), 'admin@pearlland.com', 'stock_clerk')");
    }
    
    // Insert sample data
    $checkProducts = $conn->query("SELECT * FROM products LIMIT 1");
    if ($checkProducts->num_rows == 0) {
        $conn->query("INSERT INTO products (code, name, category, unit, price, stock, reorder_level, supplier) VALUES
            ('P001', 'Turmeric Powder', 'Spice', 'kg', 500, 450, 100, 'Lanka Spices'),
            ('P002', 'Chili Powder', 'Spice', 'kg', 400, 15, 25, 'Lanka Spices'),
            ('P003', 'Black Pepper', 'Spice', 'kg', 780, 8, 20, 'Jaffna Exports'),
            ('P004', 'Cinnamon Sticks', 'Spice', 'kg', 950, 320, 50, 'Matale Growers'),
            ('P005', 'Cardamom', 'Whole Spice', 'kg', 2800, 25, 15, 'Jaffna Exports')");
    }
    
    $checkSuppliers = $conn->query("SELECT * FROM suppliers LIMIT 1");
    if ($checkSuppliers->num_rows == 0) {
        $conn->query("INSERT INTO suppliers (id, company, contact, email, phone, materials, orders_cost, status, approved_date) VALUES
            ('S001', 'Lanka Spices', 'Kamal Perera', 'lanka@spices.com', '071-1234567', 'Turmeric, Chili Powder', 500000, 'Active', '2025-01-16'),
            ('S002', 'Jaffna Exports', 'Priya Kumari', 'jaffna@exports.lk', '077-2345678', 'Black Pepper, Cardamom', 250000, 'Active', '2025-02-10'),
            ('S003', 'Matale Growers', 'Mr. Silva', 'matale@growers.lk', '076-3456789', 'Cinnamon, Cloves', 180000, 'Active', '2025-03-01')");
    }
    
    return true;
}

// ==================== API HANDLING ====================
function handleAPI() {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $conn = getDB();
    
    // Auth check for all API calls except login and logout
    if ($action !== 'login' && $action !== 'logout' && $action !== 'check' && !isAuthenticated()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }
    
    switch ($action) {
        case 'login':
            $data = json_decode(file_get_contents('php://input'), true);
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            
            $stmt = $conn->prepare("SELECT id, username, email, role FROM users WHERE username = ? AND password = MD5(?)");
            $stmt->bind_param("ss", $username, $password);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
            }
            $stmt->close();
            break;
            
        case 'logout':
            // ===== IMPROVED LOGOUT =====
            // Clear all session variables
            $_SESSION = array();
            
            // Destroy the session cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            
            // Destroy the session
            session_destroy();
            
            echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        case 'check':
            if (isAuthenticated()) {
                echo json_encode(['success' => true, 'user' => $_SESSION['username']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            }
            break;
            
        case 'get_products':
            $result = $conn->query("SELECT * FROM products ORDER BY id");
            $products = [];
            while ($row = $result->fetch_assoc()) {
                $products[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $products]);
            break;
            
        case 'add_product':
            $data = json_decode(file_get_contents('php://input'), true);
            $code = 'P' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            $stmt = $conn->prepare("INSERT INTO products (code, name, category, unit, price, stock, reorder_level, supplier) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssdiss", $code, $data['name'], $data['category'], $data['unit'], 
                             $data['price'], $data['stock'], $data['reorder'], $data['supplier']);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'id' => $conn->insert_id, 'code' => $code]);
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
            }
            $stmt->close();
            break;
            
        case 'update_product':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $conn->prepare("UPDATE products SET name=?, category=?, unit=?, price=?, stock=?, reorder_level=?, supplier=? WHERE id=?");
            $stmt->bind_param("sssdissi", $data['name'], $data['category'], $data['unit'], 
                             $data['price'], $data['stock'], $data['reorder'], $data['supplier'], $data['id']);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
            }
            $stmt->close();
            break;
            
        case 'delete_product':
            $id = $_GET['id'] ?? 0;
            $conn->query("DELETE FROM products WHERE id = $id");
            echo json_encode(['success' => true]);
            break;
            
        case 'request_sample':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = 'SAMP-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            $stmt = $conn->prepare("INSERT INTO sample_requests (id, supplier, material, qty, request_date, status) 
                                    VALUES (?, ?, ?, ?, CURDATE(), 'Pending')");
            $stmt->bind_param("sssi", $id, $data['supplier'], $data['material'], $data['qty']);
            
            if ($stmt->execute()) {
                // Send email notification
                sendEmail('manager@pearlland.com', 
                         "New Sample Request - $id",
                         "<h2>Sample Request Created</h2>
                          <p><strong>Supplier:</strong> {$data['supplier']}</p>
                          <p><strong>Material:</strong> {$data['material']}</p>
                          <p><strong>Quantity:</strong> {$data['qty']} kg</p>
                          <p><strong>Request ID:</strong> $id</p>
                          <p><strong>Requested by:</strong> {$_SESSION['username']}</p>");
                
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
            }
            $stmt->close();
            break;
            
        case 'qc_pass':
            $data = json_decode(file_get_contents('php://input'), true);
            
            $conn->query("UPDATE sample_requests SET status = 'QC Completed', sample_sent = TRUE WHERE id = '{$data['sample_id']}'");
            
            $stmt = $conn->prepare("INSERT INTO qc_reports (sample_id, supplier, material, test_date, result, remarks, price, quality_score) 
                                    VALUES (?, ?, ?, CURDATE(), 'Pass', ?, ?, ?)");
            $stmt->bind_param("ssssdd", $data['sample_id'], $data['supplier'], $data['material'],
                             $data['remarks'], $data['price'], $data['quality_score']);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
            }
            $stmt->close();
            break;
            
        case 'qc_reject':
            $data = json_decode(file_get_contents('php://input'), true);
            $conn->query("UPDATE sample_requests SET status = 'Rejected' WHERE id = '{$data['sample_id']}'");
            
            $stmt = $conn->prepare("INSERT INTO qc_reports (sample_id, supplier, material, test_date, result, remarks) 
                                    VALUES (?, ?, ?, CURDATE(), 'Reject', ?)");
            $stmt->bind_param("ssss", $data['sample_id'], $data['supplier'], $data['material'], $data['remarks']);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
            }
            $stmt->close();
            break;
            
        case 'create_po':
            $data = json_decode(file_get_contents('php://input'), true);
            $poId = 'PO-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $total = $data['qty'] * $data['unit_price'];
            
            $stmt = $conn->prepare("INSERT INTO purchase_orders (id, sample_id, supplier, material, qty, unit_price, total, delivery_date, terms, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Approval')");
            $stmt->bind_param("ssssiddss", $poId, $data['sample_id'], $data['supplier'], $data['material'], 
                             $data['qty'], $data['unit_price'], $total, $data['delivery_date'], $data['terms']);
            
            if ($stmt->execute()) {
                // Update sample status
                $conn->query("UPDATE sample_requests SET status = 'PO Created' WHERE id = '{$data['sample_id']}'");
                
                // Send email notification
                sendEmail('manager@pearlland.com', 
                         "New Purchase Order - $poId",
                         "<h2>Purchase Order Created</h2>
                          <p><strong>PO #:</strong> $poId</p>
                          <p><strong>Supplier:</strong> {$data['supplier']}</p>
                          <p><strong>Material:</strong> {$data['material']}</p>
                          <p><strong>Quantity:</strong> {$data['qty']} kg</p>
                          <p><strong>Unit Price:</strong> Rs. {$data['unit_price']}</p>
                          <p><strong>Total:</strong> Rs. " . number_format($total, 2) . "</p>
                          <p><strong>Delivery Date:</strong> {$data['delivery_date']}</p>
                          <p><strong>Terms:</strong> {$data['terms']}</p>
                          <p><strong>Created by:</strong> {$_SESSION['username']}</p>");
                
                echo json_encode(['success' => true, 'id' => $poId]);
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
            }
            $stmt->close();
            break;
            
        case 'approve_po':
            $data = json_decode(file_get_contents('php://input'), true);
            $poId = $data['po_id'];
            $conn->query("UPDATE purchase_orders SET status = 'Approved' WHERE id = '$poId'");
            echo json_encode(['success' => true]);
            break;
            
        case 'create_grn':
            $data = json_decode(file_get_contents('php://input'), true);
            $grnId = 'GRN-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $totalAmount = $data['received_qty'] * $data['unit_price'];
            
            $stmt = $conn->prepare("INSERT INTO grns (id, po_id, supplier, material, ordered_qty, received_qty, unit_price, total_amount, received_date, inspector, remarks, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved')");
            $stmt->bind_param("ssssidddsss", $grnId, $data['po_id'], $data['supplier'], $data['material'], 
                             $data['ordered_qty'], $data['received_qty'], $data['unit_price'], $totalAmount, 
                             $data['received_date'], $data['inspector'], $data['remarks']);
            
            if ($stmt->execute()) {
                // Update product stock
                $conn->query("UPDATE products SET stock = stock + {$data['received_qty']} 
                              WHERE name LIKE '%{$data['material']}%'");
                
                // Send email notification
                sendEmail('accounts@pearlland.com', 
                         "New GRN Created - $grnId",
                         "<h2>Goods Received Note</h2>
                          <p><strong>GRN #:</strong> $grnId</p>
                          <p><strong>PO #:</strong> {$data['po_id']}</p>
                          <p><strong>Supplier:</strong> {$data['supplier']}</p>
                          <p><strong>Material:</strong> {$data['material']}</p>
                          <p><strong>Received Quantity:</strong> {$data['received_qty']} kg</p>
                          <p><strong>Total Amount:</strong> Rs. " . number_format($totalAmount, 2) . "</p>
                          <p><strong>Inspected by:</strong> {$data['inspector']}</p>
                          <p><strong>Status:</strong> Approved</p>
                          <p><strong>Created by:</strong> {$_SESSION['username']}</p>");
                
                echo json_encode(['success' => true, 'id' => $grnId]);
            } else {
                echo json_encode(['success' => false, 'message' => $conn->error]);
            }
            $stmt->close();
            break;
            
        case 'update_delivery':
            $data = json_decode(file_get_contents('php://input'), true);
            $conn->query("UPDATE deliveries SET status = '{$data['status']}' WHERE id = '{$data['id']}'");
            echo json_encode(['success' => true]);
            break;
            
        case 'get_data':
            $type = $_GET['type'] ?? '';
            $data = [];
            
            if ($type === 'suppliers') {
                $data = getActiveSuppliers();
            } elseif ($type === 'samples') {
                $result = $conn->query("SELECT * FROM sample_requests");
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            } elseif ($type === 'qc') {
                $result = $conn->query("SELECT * FROM qc_reports");
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            } elseif ($type === 'pos') {
                $result = $conn->query("SELECT * FROM purchase_orders");
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            } elseif ($type === 'grns') {
                $result = $conn->query("SELECT * FROM grns");
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            } elseif ($type === 'deliveries') {
                $result = $conn->query("SELECT * FROM deliveries");
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            } elseif ($type === 'raw_materials') {
                $result = $conn->query("SELECT * FROM raw_materials");
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'export_csv':
            $type = $_GET['type'] ?? 'products';
            $table = $type === 'products' ? 'products' : 'deliveries';
            
            $result = $conn->query("SELECT * FROM $table");
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
            }
            fclose($output);
            exit;
            break;
            
        case 'export_pdf':
            // For PDF, we'll generate HTML and use browser print
            $type = $_GET['type'] ?? 'products';
            $result = $conn->query("SELECT * FROM $type");
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            
            ?>
            <html>
            <head><title>Export PDF</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background: #8B4513; color: white; }
                h1 { color: #8B4513; }
            </style>
            </head>
            <body>
                <h1>Pearl Land - <?php echo ucfirst($type); ?> Report</h1>
                <p>Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
                <table>
                    <thead>
                        <tr>
                            <?php if (!empty($data)): ?>
                                <?php foreach (array_keys($data[0]) as $key): ?>
                                    <th><?php echo ucfirst(str_replace('_', ' ', $key)); ?></th>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): ?>
                            <tr>
                                <?php foreach ($row as $value): ?>
                                    <td><?php echo htmlspecialchars($value); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <script>window.print();</script>
            </body>
            </html>
            <?php
            exit;
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
    $conn->close();
}

// ==================== HANDLE API REQUESTS ====================
// The login form itself submits via fetch('?action=login', {method:'POST', ...})
// and expects the JSON handled by case 'login' inside handleAPI(). Excluding
// 'login' outright meant that POST could never reach it - it fell through to
// requireAuth() below, which redirected back to '?action=login', which hit
// this same exclusion again: an infinite redirect loop that always looked
// like a network error to the login form, so no one could ever log in.
// A GET to '?action=login' (no submission yet) still needs to fall through
// so the login page HTML renders below.
if (isset($_GET['action']) && $_GET['action'] !== 'install'
    && ($_GET['action'] !== 'login' || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    handleAPI();
    exit;
}

// ==================== INSTALL DATABASE ====================
if (isset($_GET['action']) && $_GET['action'] === 'install') {
    installDatabase();
    echo "✅ Database installed successfully!";
    echo "<br>Default login: admin / password123";
    echo "<br><a href='?'>Go to Dashboard</a>";
    exit;
}

// ==================== LOGIN PAGE ====================
// By this point action is neither 'install' nor a POST 'login' submission
// (both handled and exited above), so a plain unauthenticated GET - with no
// action, or a stray GET to '?action=login' - should just show the form.
if (!isAuthenticated()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Pearl Land Stock Clerk</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
            body { background: linear-gradient(135deg, #8B4513 0%, #D2691E 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
            .login-container { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 400px; }
            .login-header { text-align: center; margin-bottom: 30px; }
            .login-header h1 { color: #8B4513; font-size: 28px; }
            .login-header p { color: #666; font-size: 14px; }
            .form-group { margin-bottom: 20px; }
            .form-group label { display: block; font-weight: 600; color: #333; margin-bottom: 5px; }
            .form-group input { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 14px; transition: 0.3s; }
            .form-group input:focus { border-color: #D2691E; outline: none; }
            .login-btn { width: 100%; padding: 12px; background: #8B4513; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.3s; }
            .login-btn:hover { background: #A0522D; transform: scale(1.02); }
            .error-msg { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 8px; margin-bottom: 15px; display: none; }
            .success-msg { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 15px; display: none; }
            .install-link { text-align: center; margin-top: 15px; }
            .install-link a { color: #8B4513; text-decoration: none; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-header">
                <h1>🌶️ Pearl Land</h1>
                <p>Stock Clerk Dashboard Login</p>
            </div>
            <div id="error-msg" class="error-msg"></div>
            <div id="success-msg" class="success-msg"></div>
            <form id="login-form">
                <div class="form-group">
                    <label>👤 Username</label>
                    <input type="text" id="username" required placeholder="Enter username">
                </div>
                <div class="form-group">
                    <label>🔒 Password</label>
                    <input type="password" id="password" required placeholder="Enter password">
                </div>
                <button type="submit" class="login-btn">🚪 Login to Dashboard</button>
            </form>
            <div class="install-link">
                <a href="?action=install">🔧 Install Database (First Time Setup)</a>
            </div>
            <p style="text-align:center;margin-top:20px;color:#666;font-size:12px;">
                Default: admin / password123
            </p>
        </div>
        <script>
            document.getElementById('login-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;
                const errorDiv = document.getElementById('error-msg');
                const successDiv = document.getElementById('success-msg');
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                try {
                    const response = await fetch('?action=login', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ username, password })
                    });
                    const data = await response.json();
                    if (data.success) {
                        successDiv.textContent = '✅ Login successful! Redirecting...';
                        successDiv.style.display = 'block';
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        errorDiv.textContent = '❌ ' + data.message;
                        errorDiv.style.display = 'block';
                    }
                } catch (error) {
                    errorDiv.textContent = '❌ Network error. Please try again.';
                    errorDiv.style.display = 'block';
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==================== MAIN DASHBOARD ====================
requireAuth();

// Get data from database
$conn = getDB();

// Fetch products
$products = [];
$result = $conn->query("SELECT * FROM products ORDER BY id");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// Fetch suppliers (from the main app database - only real, login-capable
// suppliers appear here; see getActiveSuppliers())
$suppliers = getActiveSuppliers();

// Fetch sample requests
$samples = [];
$result = $conn->query("SELECT * FROM sample_requests");
while ($row = $result->fetch_assoc()) {
    $samples[] = $row;
}

// Fetch QC reports
$qcReports = [];
$result = $conn->query("SELECT * FROM qc_reports");
while ($row = $result->fetch_assoc()) {
    $qcReports[] = $row;
}

// Fetch POs
$pos = [];
$result = $conn->query("SELECT * FROM purchase_orders");
while ($row = $result->fetch_assoc()) {
    $pos[] = $row;
}

// Fetch GRNs
$grns = [];
$result = $conn->query("SELECT * FROM grns");
while ($row = $result->fetch_assoc()) {
    $grns[] = $row;
}

// Fetch deliveries
$deliveries = [];
$result = $conn->query("SELECT * FROM deliveries");
while ($row = $result->fetch_assoc()) {
    $deliveries[] = $row;
}

// Fetch raw materials
$rawMaterials = [];
$result = $conn->query("SELECT * FROM raw_materials");
while ($row = $result->fetch_assoc()) {
    $rawMaterials[] = $row;
}

$conn->close();

// Convert PHP data to JSON for JavaScript
$productsJSON = json_encode($products);
$suppliersJSON = json_encode($suppliers);
$samplesJSON = json_encode($samples);
$qcReportsJSON = json_encode($qcReports);
$posJSON = json_encode($pos);
$grnsJSON = json_encode($grns);
$deliveriesJSON = json_encode($deliveries);
$rawMaterialsJSON = json_encode($rawMaterials);

// ==================== HTML DASHBOARD ====================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📦 Stock Clerk Dashboard - Pearl Land Commodities</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        :root { --primary-brown: #8B4513; --secondary-brown: #A0522D; --primary-orange: #D2691E; --secondary-orange: #FF8C00; --cream: #FFF5E6; --white: #ffffff; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --info: #3498db; }
        body { background: linear-gradient(135deg, var(--cream) 0%, #fff 100%); min-height: 100vh; }
        .dashboard-container { display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { width: 280px; background: var(--primary-brown); color: var(--white); padding: 20px; position: fixed; height: 100vh; overflow-y: auto; box-shadow: 2px 0 10px rgba(0,0,0,0.2); z-index: 100; }
        .sidebar-header { text-align: center; padding: 20px 0; border-bottom: 2px solid var(--secondary-orange); margin-bottom: 20px; }
        .sidebar-header h2 { font-size: 24px; margin-bottom: 5px; }
        .sidebar-header p { font-size: 12px; opacity: 0.8; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu li { margin-bottom: 5px; }
        .sidebar-menu a { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: var(--white); text-decoration: none; border-radius: 8px; transition: all 0.3s; cursor: pointer; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background: var(--secondary-brown); transform: translateX(5px); }
        .sidebar-menu .icon { font-size: 20px; width: 30px; }
        .sidebar-footer { position: absolute; bottom: 20px; left: 20px; right: 20px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 8px; text-align: center; }
        .sidebar-footer .user-info { font-size: 12px; opacity: 0.9; margin-bottom: 10px; }
        .logout-btn { background: var(--danger); color: white; border: none; padding: 8px 20px; border-radius: 20px; cursor: pointer; margin-top: 10px; width: 100%; font-weight: 600; transition: all 0.3s; }
        .logout-btn:hover { background: #c0392b; transform: scale(1.02); }
        .logout-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        
        /* Toast Notification */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #333;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            transform: translateX(120%);
            transition: transform 0.3s ease;
            min-width: 250px;
        }
        .toast.show {
            transform: translateX(0);
        }
        .toast.success {
            background: #27ae60;
        }
        .toast.error {
            background: #e74c3c;
        }
        .toast.info {
            background: #3498db;
        }
        
        /* Main Content */
        .main-content { flex: 1; margin-left: 280px; padding: 30px; }
        .content-header { background: white; padding: 20px 30px; border-radius: 12px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 6px solid var(--primary-orange); flex-wrap: wrap; gap: 15px; }
        .content-header h1 { color: var(--primary-brown); font-size: 24px; }
        .role-badge { background: var(--primary-brown); color: white; padding: 8px 20px; border-radius: 30px; font-weight: 600; }
        .export-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .export-btn { background: var(--info); color: white; padding: 6px 15px; border: none; border-radius: 20px; cursor: pointer; font-size: 12px; transition: 0.3s; }
        .export-btn:hover { transform: scale(1.05); }
        .export-btn.pdf { background: #e74c3c; }
        .export-btn.csv { background: #2ecc71; }
        
        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 18px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 12px; transition: transform 0.3s; border-bottom: 4px solid var(--primary-orange); }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { font-size: 28px; width: 50px; height: 50px; background: var(--cream); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .stat-details h3 { color: #666; font-size: 12px; margin-bottom: 4px; }
        .stat-number { color: var(--primary-brown); font-size: 22px; font-weight: 700; }
        
        /* Tabs */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn { padding: 10px 22px; background: white; border: 2px solid var(--primary-orange); border-radius: 30px; color: var(--primary-brown); font-weight: 600; cursor: pointer; transition: all 0.3s; font-size: 13px; }
        .tab-btn.active { background: var(--primary-brown); border-color: var(--primary-brown); color: white; }
        .tab-btn:hover { background: var(--secondary-brown); border-color: var(--secondary-brown); color: white; }
        .tab-content { display: none; animation: fadeIn 0.5s; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Tables */
        .data-table { width: 100%; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow-x: auto; }
        .data-table thead { background: var(--primary-brown); color: white; }
        .data-table th { padding: 10px 12px; text-align: left; font-weight: 600; font-size: 12px; }
        .data-table td { padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 12px; }
        .data-table tbody tr:hover { background: var(--cream); }
        
        /* Badges */
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-success { background: var(--success); color: white; }
        .badge-warning { background: var(--warning); color: white; }
        .badge-danger { background: var(--danger); color: white; }
        .badge-info { background: var(--info); color: white; }
        
        /* Forms */
        .product-form { background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 6px solid var(--success); }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .form-group { margin-bottom: 10px; }
        .form-group label { display: block; margin-bottom: 4px; color: var(--primary-brown); font-weight: 600; font-size: 12px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 6px 10px; border: 2px solid #e0d5cc; border-radius: 8px; font-size: 12px; transition: all 0.3s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary-orange); }
        .btn-success-custom { background: var(--success); color: white; padding: 6px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s; font-size: 12px; }
        .btn-success-custom:hover { background: #27ae60; transform: scale(1.05); }
        .btn-secondary { background: var(--primary-orange); color: white; padding: 5px 12px; border: none; border-radius: 20px; cursor: pointer; font-size: 11px; display: inline-block; transition: all 0.3s; margin: 2px; }
        .btn-secondary:hover { background: var(--secondary-orange); transform: scale(1.05); }
        .btn-sm { padding: 3px 8px; font-size: 10px; }
        .btn-approve { background: var(--success); color: white; border: none; padding: 4px 10px; border-radius: 5px; cursor: pointer; margin: 2px; font-size: 11px; }
        .btn-reject { background: var(--danger); color: white; border: none; padding: 4px 10px; border-radius: 5px; cursor: pointer; margin: 2px; font-size: 11px; }
        
        /* Alerts */
        .alerts-section { background: white; padding: 18px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .alerts-section h2 { color: var(--primary-brown); margin-bottom: 12px; border-bottom: 2px dashed var(--primary-orange); padding-bottom: 8px; font-size: 16px; }
        .info-note { background: #e8f5e9; padding: 8px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 12px; color: #2e7d32; border-left: 3px solid var(--success); }
        .alert-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-bottom: 1px solid #eee; flex-wrap: wrap; gap: 8px; }
        .stock-critical { color: var(--danger); animation: blink 1s infinite; }
        @keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        .footer { text-align: center; padding: 15px; color: #6c757d; font-size: 0.8em; }
        
        /* Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; max-width: 600px; width: 90%; border-radius: 16px; padding: 20px; animation: fadeInUp 0.3s ease; max-height: 90vh; overflow-y: auto; }
        .modal-box h3 { color: var(--primary-brown); margin-bottom: 12px; border-bottom: 2px solid var(--secondary-orange); padding-bottom: 8px; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .modal-buttons { display: flex; gap: 10px; margin-top: 12px; }
        
        .delivery-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin: 15px 0 20px; }
        .delivery-status-card { background: white; border: 1px solid rgba(0,0,0,0.08); border-radius: 12px; padding: 14px 16px; text-align: center; }
        .delivery-status-card h3 { margin: 0; font-size: 11px; color: #555; text-transform: uppercase; }
        .delivery-status-card p { margin: 6px 0 0; font-size: 24px; font-weight: 700; color: var(--primary-brown); }
        .status-select { padding: 4px 8px; border-radius: 6px; border: 1px solid #ddd; cursor: pointer; font-size: 11px; background: white; }
        .status-select.pending { background: #fff3cd; }
        .status-select.shipped { background: #cce5ff; }
        .status-select.delivered { background: #d4edda; }
        
        .velocity-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .velocity-card { background: #f8f9fa; padding: 12px; border-radius: 8px; text-align: center; }
        .velocity-card .v-value { font-size: 22px; font-weight: 700; color: var(--primary-brown); }
        .velocity-card .v-label { font-size: 11px; color: #666; }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .content-header { flex-direction: column; text-align: center; }
        }
        @media (max-width: 480px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🌶️ Pearl Land</h2>
                <p>Stock Management</p>
            </div>
            <ul class="sidebar-menu">
                <li><a onclick="showTab('overview')" class="active"><span class="icon">🏠</span> Dashboard</a></li>
                <li><a onclick="showTab('products')"><span class="icon">📦</span> Products</a></li>
                <li><a onclick="showTab('rawmaterial')"><span class="icon">🌾</span> Raw Material</a></li>
                <li><a onclick="showTab('stockreport')"><span class="icon">📈</span> Stock Report</a></li>
                <li><a onclick="showTab('delivery')"><span class="icon">🚚</span> Delivery</a></li>
                <li><a onclick="showTab('returns')"><span class="icon">🔄</span> Return Report</a></li>
                <li><a onclick="showTab('suppliers')"><span class="icon">🏭</span> Active Suppliers</a></li>
                <li><a onclick="showTab('supplier-flow')"><span class="icon">📦</span> Supplier Sample Flow</a></li>
            </ul>
            <div class="sidebar-footer">
                <div class="user-info">👤 <?php echo htmlspecialchars($_SESSION['username'] ?? 'Stock Clerk'); ?></div>
                <div style="font-size:12px;"><?php echo htmlspecialchars($_SESSION['email'] ?? 'stock@pearlland.com'); ?></div>
                <button class="logout-btn" id="logoutBtn" onclick="logoutUser()">🚪 Logout</button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="content-header">
                <h1>📦 Stock Clerk Dashboard</h1>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div class="role-badge">📦 Stock Management</div>
                    <div class="export-buttons">
                        <button class="export-btn pdf" onclick="exportPDF('products')">📄 PDF</button>
                        <button class="export-btn csv" onclick="exportCSV('products')">📊 CSV</button>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid" id="stats-summary"></div>

            <!-- ===== DASHBOARD - OVERVIEW ===== -->
            <div id="tab-overview" class="tab-content active">
                <div class="alerts-section">
                    <h2>⚠️ Critical Stock Alerts</h2>
                    <div id="critical-alerts-list"></div>
                </div>
                <div class="alerts-section">
                    <h2>📋 Low Stock Items</h2>
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Category</th><th>Current Stock</th><th>Reorder Level</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="low-stock-body"></tbody>
                    </table>
                </div>
                <div class="alerts-section">
                    <h2>🔄 Reorder Alerts</h2>
                    <div class="info-note">💡 Items that need to be reordered. Suggested order quantity is calculated based on current stock.</div>
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Current Stock</th><th>Reorder Level</th><th>Suggested Order</th><th>Supplier</th><th>Action</th></tr></thead>
                        <tbody id="reorder-list-body"></tbody>
                    </table>
                </div>
                <div class="alerts-section">
                    <h2>⚡ Fast & Slow Moving Items</h2>
                    <div class="info-note">💡 Fast moving items sell quickly, slow moving items need promotion or price adjustment.</div>
                    <div class="velocity-summary" id="velocity-summary"></div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <div>
                            <h4 style="color:var(--primary-brown); margin-bottom:8px;">⚡ Fast Moving Items</h4>
                            <table class="data-table"><thead><tr><th>Rank</th><th>Product</th><th>Sold</th><th>Sales Value</th></tr></thead><tbody id="fast-moving-body"></tbody></table>
                        </div>
                        <div>
                            <h4 style="color:var(--primary-brown); margin-bottom:8px;">🐌 Slow Moving Items</h4>
                            <table class="data-table"><thead><tr><th>Product</th><th>Stock</th><th>Sold</th><th>Days</th></tr></thead><tbody id="slow-moving-body"></tbody></table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== PRODUCTS TAB ===== -->
            <div id="tab-products" class="tab-content">
                <div class="product-form">
                    <h2 style="color: var(--primary-brown); margin-bottom: 15px;">➕ Add New Product</h2>
                    <div class="form-row">
                        <div class="form-group"><label>Product Name</label><input type="text" id="product-name" placeholder="e.g., Turmeric Powder"></div>
                        <div class="form-group"><label>Category</label><select id="product-category"><option value="Spice">Spice</option><option value="Whole Spice">Whole Spice</option><option value="Raw Material">Raw Material</option></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Unit</label><select id="product-unit"><option value="kg">Kilogram (kg)</option><option value="g">Gram (g)</option></select></div>
                        <div class="form-group"><label>Price (LKR)</label><input type="number" id="product-price" placeholder="500.00"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Initial Stock</label><input type="number" id="product-stock" placeholder="100"></div>
                        <div class="form-group"><label>Reorder Level</label><input type="number" id="product-reorder" placeholder="20"></div>
                    </div>
                    <button class="btn-success-custom" onclick="addProduct()">➕ Add Product</button>
                </div>
                <div class="alerts-section">
                    <h2>📋 Product List</h2>
                    <table class="data-table">
                        <thead><tr><th>Code</th><th>Product Name</th><th>Category</th><th>Price</th><th>Current Stock</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody id="product-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- ===== RAW MATERIAL TAB ===== -->
            <div id="tab-rawmaterial" class="tab-content">
                <div class="product-form">
                    <h2 style="color: var(--primary-brown); margin-bottom: 15px;">🌾 Raw Material Management</h2>
                    <div class="form-row">
                        <div class="form-group"><label>Raw Material Name</label><input type="text" id="raw-name" placeholder="e.g., Turmeric Roots"></div>
                        <div class="form-group"><label>Supplier</label><input type="text" id="raw-supplier" placeholder="Supplier name"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Quantity (kg)</label><input type="number" id="raw-quantity" placeholder="500"></div>
                        <div class="form-group"><label>Unit Price</label><input type="number" id="raw-price" placeholder="200.00"></div>
                    </div>
                    <button class="btn-success-custom" onclick="addRawMaterial()">➕ Add Raw Material</button>
                </div>
                <div class="alerts-section">
                    <h2>📋 Raw Materials Inventory</h2>
                    <table class="data-table">
                        <thead><tr><th>Material</th><th>Supplier</th><th>Batch</th><th>Quantity</th><th>Received Date</th><th>Status</th></tr></thead>
                        <tbody id="raw-material-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- ===== STOCK REPORT TAB ===== -->
            <div id="tab-stockreport" class="tab-content">
                <div class="product-form">
                    <h2 style="color: var(--primary-brown); margin-bottom: 15px;">📊 Stock Report</h2>
                    <div class="form-row">
                        <div class="form-group"><label>Report Type</label><select id="stock-report-type"><option value="inventory">Inventory Summary</option><option value="category">Category-wise Stock</option><option value="valuation">Stock Valuation</option></select></div>
                        <div class="form-group"><label>Category</label><select id="report-category"><option value="all">All Categories</option><option value="Spice">Spice</option><option value="Whole Spice">Whole Spice</option><option value="Raw Material">Raw Material</option></select></div>
                    </div>
                    <button class="btn-success-custom" onclick="generateStockReport()">📊 Generate Report</button>
                </div>
                <div id="stock-report-results" class="alerts-section"></div>
            </div>

            <!-- ===== DELIVERY TAB ===== -->
            <div id="tab-delivery" class="tab-content">
                <div class="alerts-section">
                    <h2>🚚 Delivery Management</h2>
                    <div class="delivery-status-grid" id="delivery-status-summary"></div>
                    <table class="data-table">
                        <thead><tr><th>Order ID</th><th>Product</th><th>Quantity</th><th>Order Date</th><th>Expected Date</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="delivery-status-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- ===== RETURNS TAB ===== -->
            <div id="tab-returns" class="tab-content">
                <div class="product-form">
                    <h2 style="color: var(--primary-brown); margin-bottom: 15px;">🔄 Return Product Report</h2>
                    <div class="form-row">
                        <div class="form-group"><label>From Date</label><input type="date" id="return-from"></div>
                        <div class="form-group"><label>To Date</label><input type="date" id="return-to"></div>
                    </div>
                    <button class="btn-success-custom" onclick="generateReturnReport()">📊 Generate Return Report</button>
                </div>
                <div class="alerts-section">
                    <table class="data-table">
                        <thead><tr><th>Return ID</th><th>Product</th><th>Party</th><th>Date</th><th>Quantity</th><th>Reason</th><th>Status</th></tr></thead>
                        <tbody id="return-report-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- ===== ACTIVE SUPPLIERS TAB ===== -->
            <div id="tab-suppliers" class="tab-content">
                <div class="alerts-section">
                    <h2 style="color: var(--primary-brown); margin-bottom: 15px;">🏭 Approved Active Suppliers</h2>
                    <div class="info-note">✅ These suppliers are approved by Manager. You can request samples from them.</div>
                    <table class="data-table">
                        <thead><tr><th>Supplier ID</th><th>Company Name</th><th>Contact Person</th><th>Email</th><th>Phone</th><th>Materials Supplied</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="active-suppliers-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- ===== SUPPLIER SAMPLE FLOW TAB ===== -->
            <div id="tab-supplier-flow" class="tab-content">
                <div class="alerts-section">
                    <h2 style="color: var(--primary-brown); margin-bottom: 15px;">📦 Supplier Sample Flow</h2>
                    <div class="info-note">📌 Manage supplier sample requests, QC testing, and PO creation.</div>
                </div>

                <div class="product-form">
                    <h2 style="color: var(--primary-brown); margin-bottom: 15px;">1️⃣ Request Sample from Supplier</h2>
                    <div class="form-row">
                        <div class="form-group"><label>Supplier</label><select id="sample-supplier-select"><option value="">Select Supplier</option></select></div>
                        <div class="form-group"><label>Raw Material</label><input type="text" id="sample-material" placeholder="e.g., Turmeric Roots"></div>
                        <div class="form-group"><label>Quantity (kg)</label><input type="number" id="sample-qty" placeholder="2" value="2"></div>
                    </div>
                    <button class="btn-success-custom" onclick="requestSample()">📦 Request Sample</button>
                </div>

                <div class="alerts-section">
                    <h2>🔬 Quality Control Testing</h2>
                    <table class="data-table">
                        <thead><tr><th>Sample ID</th><th>Supplier</th><th>Material</th><th>Qty</th><th>Request Date</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="qc-test-body"></tbody>
                    </table>
                </div>

                <div class="alerts-section">
                    <h2>✅ Select Best Sample & Create PO</h2>
                    <table class="data-table">
                        <thead><tr><th>Sample ID</th><th>Supplier</th><th>Material</th><th>QC Result</th><th>Price</th><th>Quality Score</th><th>Action</th></tr></thead>
                        <tbody id="best-sample-body"></tbody>
                    </table>
                    <div id="po-creation-area" style="display:none; margin-top:15px; padding:15px; background:#f5f5f5; border-radius:8px;">
                        <h3 style="color:var(--primary-brown);">📄 Create Purchase Order</h3>
                        <div class="form-row">
                            <div class="form-group"><label>Supplier</label><input type="text" id="po-supplier" readonly></div>
                            <div class="form-group"><label>Material</label><input type="text" id="po-material" readonly></div>
                            <div class="form-group"><label>Unit Price</label><input type="number" id="po-price"></div>
                            <div class="form-group"><label>Quantity</label><input type="number" id="po-qty"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Expected Delivery</label><input type="date" id="po-delivery-date"></div>
                            <div class="form-group"><label>Payment Terms</label><select id="po-terms"><option>30 Days Credit</option><option>Cash on Delivery</option><option>Advance 50%</option></select></div>
                        </div>
                        <button class="btn-success-custom" onclick="createPurchaseOrder()">📄 Create PO</button>
                        <button class="btn-secondary" onclick="document.getElementById('po-creation-area').style.display='none'">Cancel</button>
                    </div>
                </div>

                <div class="alerts-section">
                    <h2>📄 Purchase Orders</h2>
                    <table class="data-table">
                        <thead><tr><th>PO #</th><th>Supplier</th><th>Material</th><th>Qty</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="pending-po-body"></tbody>
                    </table>
                </div>

                <div class="alerts-section">
                    <h2>📦 Goods Received - Create GRN</h2>
                    <table class="data-table">
                        <thead><tr><th>PO #</th><th>Supplier</th><th>Material</th><th>Ordered Qty</th><th>Received Qty</th><th>Action</th></tr></thead>
                        <tbody id="received-goods-body"></tbody>
                    </table>
                    <div id="grn-creation-area" style="display:none; margin-top:15px; padding:15px; background:#f5f5f5; border-radius:8px;">
                        <h3 style="color:var(--primary-brown);">📦 Create GRN</h3>
                        <div class="form-row">
                            <div class="form-group"><label>PO Number</label><input type="text" id="grn-po" readonly></div>
                            <div class="form-group"><label>Received Date</label><input type="date" id="grn-date"></div>
                            <div class="form-group"><label>Received Quantity</label><input type="number" id="grn-qty"></div>
                        </div>
                        <button class="btn-success-custom" onclick="createGRN()">✅ Create GRN</button>
                        <button class="btn-secondary" onclick="document.getElementById('grn-creation-area').style.display='none'">Cancel</button>
                    </div>
                </div>

                <div class="alerts-section">
                    <h2>💰 Approved GRNs</h2>
                    <table class="data-table">
                        <thead><tr><th>GRN #</th><th>PO #</th><th>Supplier</th><th>Received Qty</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody id="approved-grn-body"></tbody>
                    </table>
                </div>
            </div>

            <div class="footer">
                <p>✅ Stock Clerk can update Delivery Status | Click dropdown to change Pending → Shipped → Delivered</p>
                <p>🏭 Supplier Sample Flow: Request → QC → Select Best → PO → Receive → GRN</p>
            </div>
        </div>
    </div>

    <!-- ==================== JAVASCRIPT ==================== -->
    <script>
        // ==================== DATA FROM PHP ====================
        const products = <?php echo $productsJSON; ?>;
        const suppliers = <?php echo $suppliersJSON; ?>;
        const samples = <?php echo $samplesJSON; ?>;
        const qcReports = <?php echo $qcReportsJSON; ?>;
        const pos = <?php echo $posJSON; ?>;
        const grns = <?php echo $grnsJSON; ?>;
        const deliveries = <?php echo $deliveriesJSON; ?>;
        const rawMaterials = <?php echo $rawMaterialsJSON; ?>;
        let returnRecords = [
            {id: 'RET-001', product: 'Turmeric Powder', party: 'Saman Perera', date: '2026-03-15', quantity: '5 kg', reason: 'Damaged', status: 'Approved', type: 'customer'},
            {id: 'RET-002', product: 'Chili Powder', party: 'Nimali Stores', date: '2026-03-16', quantity: '3 kg', reason: 'Expired', status: 'Pending', type: 'supplier'}
        ];
        let sales = [
            {productId: 1, quantity: 120, date: '2026-03-01'}, {productId: 1, quantity: 85, date: '2026-03-05'},
            {productId: 2, quantity: 60, date: '2026-03-02'}, {productId: 2, quantity: 45, date: '2026-03-07'},
            {productId: 3, quantity: 5, date: '2026-03-04'}, {productId: 4, quantity: 8, date: '2026-03-06'},
            {productId: 5, quantity: 2, date: '2026-03-01'}, {productId: 5, quantity: 3, date: '2026-03-10'}
        ];

        // ==================== TOAST NOTIFICATION ====================
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type;
            void toast.offsetWidth;
            toast.classList.add('show');
            setTimeout(() => { toast.classList.remove('show'); }, 3000);
        }

        // ==================== API FUNCTIONS ====================
        async function apiCall(action, data = null) {
            const options = {
                method: data ? 'POST' : 'GET',
                headers: data ? { 'Content-Type': 'application/json' } : {}
            };
            if (data) options.body = JSON.stringify(data);
            
            const response = await fetch('?action=' + action, options);
            return await response.json();
        }

        // ==================== LOGOUT (IMPROVED) ====================
        async function logoutUser() {
            if (confirm('Are you sure you want to logout?')) {
                const btn = document.getElementById('logoutBtn');
                const originalText = btn.innerHTML;
                btn.innerHTML = '⏳ Logging out...';
                btn.disabled = true;
                
                try {
                    const result = await apiCall('logout');
                    
                    if (result.success) {
                        showToast('✅ Logged out successfully!', 'success');
                        
                        // Clear local storage
                        try {
                            // Only clear session keys - localStorage.clear() would
                            // also destroy the shared registration registries.
                            localStorage.removeItem('user_session');
                            localStorage.removeItem('supplier_session');
                            sessionStorage.clear();
                        } catch(e) { /* Ignore */ }
                        
                        setTimeout(() => {
                            window.location.href = '?action=login';
                        }, 500);
                    } else {
                        showToast('❌ Logout failed: ' + result.message, 'error');
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                } catch (error) {
                    showToast('❌ Network error. Please try again.', 'error');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }
        }

        // ==================== EXPORT FUNCTIONS ====================
        function exportCSV(type) {
            window.open('?action=export_csv&type=' + type);
        }

        function exportPDF(type) {
            window.open('?action=export_pdf&type=' + type);
        }

        // ==================== STATS ====================
        function updateStats() {
            const totalProducts = products.length;
            const lowStock = products.filter(p => p.stock <= p.reorder_level && p.stock > 0).length;
            const outOfStock = products.filter(p => p.stock === 0).length;
            const totalValue = products.reduce((sum, p) => sum + (p.stock * p.price), 0);
            const pendingSamples = samples.filter(s => s.status === 'Pending').length;

            document.getElementById('stats-summary').innerHTML = `
                <div class="stat-card"><div class="stat-icon">📦</div><div class="stat-details"><h3>Total Products</h3><div class="stat-number">${totalProducts}</div></div></div>
                <div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-details"><h3>Low Stock</h3><div class="stat-number">${lowStock}</div></div></div>
                <div class="stat-card"><div class="stat-icon">⛔</div><div class="stat-details"><h3>Out of Stock</h3><div class="stat-number">${outOfStock}</div></div></div>
                <div class="stat-card"><div class="stat-icon">💰</div><div class="stat-details"><h3>Stock Value</h3><div class="stat-number">Rs. ${totalValue.toLocaleString()}</div></div></div>
                <div class="stat-card"><div class="stat-icon">🔬</div><div class="stat-details"><h3>Sample Requests</h3><div class="stat-number">${pendingSamples}</div></div></div>
                <div class="stat-card"><div class="stat-icon">📄</div><div class="stat-details"><h3>Total POs</h3><div class="stat-number">${pos.length}</div></div></div>
            `;
        }

        // ==================== PRODUCTS ====================
        function loadProducts() {
            let html = '';
            products.forEach(p => {
                const statusClass = p.stock === 0 ? 'badge-danger' : (p.stock <= p.reorder_level ? 'badge-warning' : 'badge-success');
                const statusText = p.stock === 0 ? 'Out of Stock' : (p.stock <= p.reorder_level ? 'Low Stock' : 'Good');
                html += `<tr><td>${p.code}</td><td>${p.name}</td><td>${p.category}</td><td>Rs. ${p.price}</td><td>${p.stock} ${p.unit}</td><td><span class="badge ${statusClass}">${statusText}</span></td><td><button class="btn-secondary btn-sm" onclick="reorderProduct(${p.id})">🔄 Reorder</button></td></tr>`;
            });
            document.getElementById('product-body').innerHTML = html;
        }

        async function addProduct() {
            const name = document.getElementById('product-name').value;
            const category = document.getElementById('product-category').value;
            const unit = document.getElementById('product-unit').value;
            const price = parseFloat(document.getElementById('product-price').value);
            const stock = parseFloat(document.getElementById('product-stock').value) || 0;
            const reorder = parseFloat(document.getElementById('product-reorder').value) || 10;

            if (!name || !price) { 
                showToast('❌ Please fill required fields', 'error');
                return; 
            }

            const result = await apiCall('add_product', {
                name, category, unit, price, stock, reorder, supplier: 'Lanka Spices'
            });

            if (result.success) {
                showToast('✅ Product added! Code: ' + result.code, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('❌ Error: ' + result.message, 'error');
            }
        }

        async function reorderProduct(productId) {
            const product = products.find(p => p.id === productId);
            if (!product) return;
            const suggested = product.reorder_level * 2 - product.stock;
            if (confirm(`Place reorder for ${product.name}? Suggested: ${suggested} ${product.unit}`)) {
                showToast('✅ Reorder placed for ' + product.name, 'success');
            }
        }

        // ==================== LOW STOCK ====================
        function loadLowStockTable() {
            const lowStock = products.filter(p => p.stock <= p.reorder_level && p.stock > 0);
            document.getElementById('low-stock-body').innerHTML = lowStock.map(p =>
                `<tr><td>${p.name}</td><td>${p.category}</td><td>${p.stock} ${p.unit}</td><td>${p.reorder_level} ${p.unit}</td><td><span class="badge badge-warning">Low Stock</span></td><td><button class="btn-secondary btn-sm" onclick="reorderProduct(${p.id})">Reorder</button></td></tr>`
            ).join('') || '<tr><td colspan="6" style="text-align:center">No low stock items</td></tr>';
        }

        function loadCriticalAlerts() {
            const critical = products.filter(p => p.stock === 0);
            document.getElementById('critical-alerts-list').innerHTML = critical.map(p =>
                `<div class="alert-item"><span><strong>${p.name}</strong> - Out of stock!</span><span class="stock-critical">CRITICAL</span><button class="btn-secondary btn-sm" onclick="reorderProduct(${p.id})">Reorder Now</button></div>`
            ).join('') || '<p style="padding:15px">✅ No critical stock issues</p>';
        }

        function loadReorderList() {
            const needReorder = products.filter(p => p.stock <= p.reorder_level && p.stock > 0);
            let html = '';
            needReorder.forEach(p => {
                const suggested = p.reorder_level * 2 - p.stock;
                html += `<tr><td>${p.name}</td><td>${p.stock} ${p.unit}</td><td>${p.reorder_level} ${p.unit}</td><td>${suggested} ${p.unit}</td><td>${p.supplier}</td><td><button class="btn-secondary btn-sm" onclick="reorderProduct(${p.id})">Place Order</button></td></tr>`;
            });
            document.getElementById('reorder-list-body').innerHTML = html || '<tr><td colspan="6">No items need reorder</td></tr>';
        }

        // ==================== RAW MATERIALS ====================
        function loadRawMaterials() {
            let html = '';
            rawMaterials.forEach(r => {
                html += `<tr><td>${r.name}</td><td>${r.supplier}</td><td>${r.batch}</td><td>${r.quantity} kg</td><td>${r.received_date}</td><td><span class="badge badge-success">${r.status}</span></td></tr>`;
            });
            document.getElementById('raw-material-body').innerHTML = html || '<tr><td colspan="6">No raw materials</td></tr>';
        }

        async function addRawMaterial() {
            const name = document.getElementById('raw-name').value;
            const supplier = document.getElementById('raw-supplier').value;
            const quantity = parseInt(document.getElementById('raw-quantity').value);
            if (!name || !supplier) { 
                showToast('❌ Please fill required fields', 'error');
                return; 
            }
            showToast('✅ Raw Material added!', 'success');
            location.reload();
        }

        // ==================== DELIVERY ====================
        function loadDeliveryStatus() {
            const pending = deliveries.filter(d => d.status === 'Pending').length;
            const shipped = deliveries.filter(d => d.status === 'Shipped').length;
            const delivered = deliveries.filter(d => d.status === 'Delivered').length;

            document.getElementById('delivery-status-summary').innerHTML = `
                <div class="delivery-status-card"><h3>PENDING</h3><p>${pending}</p></div>
                <div class="delivery-status-card"><h3>SHIPPED</h3><p>${shipped}</p></div>
                <div class="delivery-status-card"><h3>DELIVERED</h3><p>${delivered}</p></div>
            `;

            let html = '';
            deliveries.forEach((d, idx) => {
                const statusClass = d.status === 'Pending' ? 'pending' : (d.status === 'Shipped' ? 'shipped' : 'delivered');
                html += `<tr>
                    <td>${d.id}</td><td>${d.product}</td><td>${d.quantity} kg</td>
                    <td>${d.order_date}</td><td>${d.expected_date}</td>
                    <td><select class="status-select ${statusClass}" onchange="updateDeliveryStatus('${d.id}', this.value)">
                        <option value="Pending" ${d.status === 'Pending' ? 'selected' : ''}>Pending</option>
                        <option value="Shipped" ${d.status === 'Shipped' ? 'selected' : ''}>Shipped</option>
                        <option value="Delivered" ${d.status === 'Delivered' ? 'selected' : ''}>Delivered</option>
                    </select></td>
                    <td><button class="btn-secondary btn-sm" onclick="updateDeliveryStatus('${d.id}', document.querySelector('#tab-delivery .status-select').value)">Update</button></td>
                </tr>`;
            });
            document.getElementById('delivery-status-body').innerHTML = html || '<tr><td colspan="7">No delivery data</td></tr>';
        }

        async function updateDeliveryStatus(id, status) {
            const result = await apiCall('update_delivery', { id, status });
            if (result.success) {
                showToast('✅ Delivery status updated!', 'success');
                location.reload();
            }
        }

        // ==================== FAST/SLOW ANALYSIS ====================
        function analyzeVelocity() {
            const period = 30;
            const cutoffDate = new Date();
            cutoffDate.setDate(cutoffDate.getDate() - period);
            const filteredSales = sales.filter(s => new Date(s.date) >= cutoffDate);
            const salesByProduct = {};
            filteredSales.forEach(s => { salesByProduct[s.productId] = (salesByProduct[s.productId] || 0) + s.quantity; });

            const productStats = products.map(p => {
                const sold = salesByProduct[p.id] || 0;
                const turnover = p.stock > 0 ? (sold / p.stock) : 0;
                return { ...p, sold, turnover };
            });

            const fastMoving = [...productStats].filter(p => p.sold > 0).sort((a, b) => b.sold - a.sold).slice(0, 5);
            const slowMoving = productStats.filter(p => p.turnover < 0.5 && p.stock > 5);
            const totalSold = productStats.reduce((sum, p) => sum + p.sold, 0);
            const totalValue = productStats.reduce((sum, p) => sum + (p.sold * p.price), 0);

            document.getElementById('velocity-summary').innerHTML = `
                <div class="velocity-card"><div class="v-value">${totalSold}</div><div class="v-label">Total Sold (kg)</div></div>
                <div class="velocity-card"><div class="v-value">Rs. ${totalValue.toLocaleString()}</div><div class="v-label">Total Sales Value</div></div>
                <div class="velocity-card"><div class="v-value">${fastMoving.length}</div><div class="v-label">Fast Moving</div></div>
                <div class="velocity-card"><div class="v-value">${slowMoving.length}</div><div class="v-label">Slow Moving</div></div>
            `;

            document.getElementById('fast-moving-body').innerHTML = fastMoving.map((p, i) =>
                `<tr><td>#${i+1}</td><td>${p.name}</td><td>${p.sold} kg</td><td>Rs. ${(p.sold * p.price).toLocaleString()}</td></tr>`
            ).join('') || '<tr><td colspan="4">No data</td></tr>';

            document.getElementById('slow-moving-body').innerHTML = slowMoving.map(p =>
                `<tr><td>${p.name}</td><td>${p.stock} kg</td><td>${p.sold} kg</td><td>${p.stock > 0 ? (period / (p.sold || 1)).toFixed(0) : 0} days</td></tr>`
            ).join('') || '<tr><td colspan="4">No slow moving items</td></tr>';
        }

        // ==================== RETURNS ====================
        function loadReturnReport() {
            document.getElementById('return-report-body').innerHTML = returnRecords.map(r => {
                const statusClass = r.status === 'Approved' ? 'badge-success' : 'badge-warning';
                return `<tr><td>${r.id}</td><td>${r.product}</td><td>${r.party}</td><td>${r.date}</td><td>${r.quantity}</td><td>${r.reason}</td><td><span class="badge ${statusClass}">${r.status}</span></td></tr>`;
            }).join('') || '<tr><td colspan="7">No returns</td></tr>';
        }

        function generateReturnReport() {
            const from = document.getElementById('return-from').value;
            const to = document.getElementById('return-to').value;
            let filtered = returnRecords;
            if (from) filtered = filtered.filter(r => r.date >= from);
            if (to) filtered = filtered.filter(r => r.date <= to);
            document.getElementById('return-report-body').innerHTML = filtered.map(r => {
                const statusClass = r.status === 'Approved' ? 'badge-success' : 'badge-warning';
                return `<tr><td>${r.id}</td><td>${r.product}</td><td>${r.party}</td><td>${r.date}</td><td>${r.quantity}</td><td>${r.reason}</td><td><span class="badge ${statusClass}">${r.status}</span></td></tr>`;
            }).join('') || '<tr><td colspan="7">No matching records</td></tr>';
        }

        function generateStockReport() {
            const category = document.getElementById('report-category').value;
            const filtered = category === 'all' ? products : products.filter(p => p.category === category);
            let html = '<h3>Stock Report</h3><table class="data-table"><thead><tr><th>Code</th><th>Product</th><th>Stock</th><th>Status</th><th>Value</th></tr></thead><tbody>';
            filtered.forEach(p => {
                const statusClass = p.stock === 0 ? 'badge-danger' : (p.stock <= p.reorder_level ? 'badge-warning' : 'badge-success');
                const statusText = p.stock === 0 ? 'Out of Stock' : (p.stock <= p.reorder_level ? 'Low Stock' : 'Good');
                html += `<tr><td>${p.code}</td><td>${p.name}</td><td>${p.stock} ${p.unit}</td><td><span class="badge ${statusClass}">${statusText}</span></td><td>Rs. ${(p.stock * p.price).toLocaleString()}</td></tr>`;
            });
            const total = filtered.reduce((s, p) => s + (p.stock * p.price), 0);
            html += `<tr style="background:#fff3cd"><td colspan="4"><strong>TOTAL</strong></td><td><strong>Rs. ${total.toLocaleString()}</strong></td></tr></tbody></table>`;
            document.getElementById('stock-report-results').innerHTML = html;
        }

        // ==================== ACTIVE SUPPLIERS ====================
        function loadActiveSuppliers() {
            const tbody = document.getElementById('active-suppliers-body');
            if (!suppliers || suppliers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center">No active suppliers available</td></tr>';
                return;
            }
            tbody.innerHTML = suppliers.map(s => `
                <tr>
                    <td>${s.id}</td>
                    <td>${s.company}</td>
                    <td>${s.contact || 'N/A'}</td>
                    <td>${s.email}</td>
                    <td>${s.phone}</td>
                    <td>${s.materials}</td>
                    <td><span class="badge badge-success">✅ Active</span></td>
                    <td><button class="btn-secondary btn-sm" onclick="contactSupplier('${s.email}', '${s.company}')">📧 Contact</button></td>
                </tr>
            `).join('');

            const select = document.getElementById('sample-supplier-select');
            select.innerHTML = '<option value="">Select Supplier</option>';
            suppliers.forEach(s => {
                const option = document.createElement('option');
                option.value = s.company;
                option.textContent = s.company + ' (' + s.materials + ')';
                select.appendChild(option);
            });
        }

        function contactSupplier(email, name) {
            if (confirm(`Send email to ${name} (${email})?`)) {
                window.open(`mailto:${email}?subject=Pearl Land - Sample Request&body=Dear ${name},\n\nWe would like to request a sample of your products.\n\nPlease send us a sample for quality testing.\n\nBest regards,\nPearl Land Stock Department`);
            }
        }

        // ==================== SAMPLE FLOW ====================
        function loadSampleFlowData() {
            // QC Testing
            const pendingSamples = samples.filter(s => s.status === 'Pending' || s.status === 'Sample Sent');
            let qcHtml = '';
            pendingSamples.forEach(s => {
                const isSent = s.sample_sent || false;
                qcHtml += `<tr>
                    <td>${s.id}</td>
                    <td>${s.supplier}</td>
                    <td>${s.material}</td>
                    <td>${s.qty} kg</td>
                    <td>${s.request_date}</td>
                    <td><span class="badge ${isSent ? 'badge-info' : 'badge-warning'}">${isSent ? 'Sample Sent' : 'Pending'}</span></td>
                    <td>${isSent ? `<button class="btn-approve" onclick="qcPass('${s.id}')">✅ Pass</button> <button class="btn-reject" onclick="qcReject('${s.id}')">❌ Reject</button>` : '⏳ Waiting...'}</td>
                </tr>`;
            });
            document.getElementById('qc-test-body').innerHTML = qcHtml || '<tr><td colspan="7">No pending samples</td></tr>';

            // Best Samples (Passed QC)
            const passedSamples = qcReports.filter(q => q.result === 'Pass');
            let bestHtml = '';
            passedSamples.forEach(q => {
                const sample = samples.find(s => s.id === q.sample_id);
                if (sample) {
                    bestHtml += `<tr>
                        <td>${q.sample_id}</td>
                        <td>${q.supplier}</td>
                        <td>${q.material}</td>
                        <td><span class="badge badge-success">✅ Pass</span></td>
                        <td>${q.price ? 'Rs. ' + q.price : 'N/A'}</td>
                        <td>${q.quality_score || 'N/A'}</td>
                        <td><button class="btn-secondary btn-sm" onclick="selectForPO('${q.sample_id}')">📄 Create PO</button></td>
                    </tr>`;
                }
            });
            document.getElementById('best-sample-body').innerHTML = bestHtml || '<tr><td colspan="7">No samples passed QC</td></tr>';

            // Purchase Orders
            let poHtml = '';
            pos.forEach(po => {
                poHtml += `<tr>
                    <td>${po.id}</td>
                    <td>${po.supplier}</td>
                    <td>${po.material}</td>
                    <td>${po.qty} kg</td>
                    <td>Rs. ${(po.total || po.qty * po.unit_price).toLocaleString()}</td>
                    <td><span class="badge ${po.status === 'Approved' ? 'badge-success' : 'badge-warning'}">${po.status}</span></td>
                    <td>${po.status === 'Pending Approval' ? '<span style="color:#888;">⏳ Waiting</span>' : '<button class="btn-secondary btn-sm" onclick="approvePO(\''+po.id+'\')">✅ Approve</button>'}</td>
                </tr>`;
            });
            document.getElementById('pending-po-body').innerHTML = poHtml || '<tr><td colspan="7">No POs created</td></tr>';

            // Received Goods
            const approvedPOs = pos.filter(po => po.status === 'Approved');
            let goodsHtml = '';
            approvedPOs.forEach(po => {
                const alreadyGRN = grns.some(g => g.po_id === po.id);
                if (!alreadyGRN) {
                    goodsHtml += `<tr>
                        <td>${po.id}</td>
                        <td>${po.supplier}</td>
                        <td>${po.material}</td>
                        <td>${po.qty} kg</td>
                        <td><input type="number" id="rec-qty-${po.id}" placeholder="Enter qty" style="width:80px"></td>
                        <td><button class="btn-secondary btn-sm" onclick="receiveMaterial('${po.id}')">📦 Create GRN</button></td>
                    </tr>`;
                }
            });
            document.getElementById('received-goods-body').innerHTML = goodsHtml || '<tr><td colspan="6">No goods to receive</td></tr>';

            // Approved GRNs
            const approvedGrns = grns.filter(g => g.status === 'Approved');
            let grnHtml = '';
            approvedGrns.forEach(g => {
                grnHtml += `<tr>
                    <td>${g.id}</td>
                    <td>${g.po_id}</td>
                    <td>${g.supplier}</td>
                    <td>${g.received_qty} kg</td>
                    <td>Rs. ${(g.total_amount || g.received_qty * g.unit_price).toLocaleString()}</td>
                    <td><span class="badge badge-success">✅ Approved</span></td>
                </tr>`;
            });
            document.getElementById('approved-grn-body').innerHTML = grnHtml || '<tr><td colspan="6">No approved GRNs</td></tr>';
        }

        // ==================== SAMPLE FUNCTIONS ====================
        async function requestSample() {
            const supplier = document.getElementById('sample-supplier-select').value;
            const material = document.getElementById('sample-material').value;
            const qty = parseInt(document.getElementById('sample-qty').value) || 2;

            if (!supplier || !material) {
                showToast('❌ Please select supplier and enter material', 'error');
                return;
            }

            const result = await apiCall('request_sample', { supplier, material, qty });
            if (result.success) {
                showToast('✅ Sample requested! ID: ' + result.id, 'success');
                location.reload();
            } else {
                showToast('❌ Error: ' + result.message, 'error');
            }
        }

        async function qcPass(sampleId) {
            const sample = samples.find(s => s.id === sampleId);
            if (!sample) return;
            
            const price = prompt('Enter price per kg (LKR):', '800');
            if (!price) return;
            
            const quality = prompt('Enter quality score (1-10):', '8');
            if (!quality) return;

            const result = await apiCall('qc_pass', {
                sample_id: sampleId,
                supplier: sample.supplier,
                material: sample.material,
                price: parseFloat(price),
                quality_score: parseFloat(quality),
                remarks: 'Good quality'
            });

            if (result.success) {
                showToast('✅ Sample passed QC!', 'success');
                location.reload();
            } else {
                showToast('❌ Error: ' + result.message, 'error');
            }
        }

        async function qcReject(sampleId) {
            const sample = samples.find(s => s.id === sampleId);
            if (!sample) return;
            
            const remarks = prompt('Reason for rejection:', 'Poor quality');
            if (!remarks) return;

            const result = await apiCall('qc_reject', {
                sample_id: sampleId,
                supplier: sample.supplier,
                material: sample.material,
                remarks: remarks
            });

            if (result.success) {
                showToast('❌ Sample rejected', 'error');
                location.reload();
            } else {
                showToast('❌ Error: ' + result.message, 'error');
            }
        }

        function selectForPO(sampleId) {
            const qc = qcReports.find(q => q.sample_id === sampleId);
            if (!qc) return;

            document.getElementById('po-supplier').value = qc.supplier;
            document.getElementById('po-material').value = qc.material;
            document.getElementById('po-price').value = qc.price || '';
            document.getElementById('po-creation-area').dataset.sampleId = sampleId;
            document.getElementById('po-creation-area').style.display = 'block';
            document.getElementById('po-creation-area').scrollIntoView({ behavior: 'smooth' });
        }

        async function createPurchaseOrder() {
            const sampleId = document.getElementById('po-creation-area').dataset.sampleId;
            const supplier = document.getElementById('po-supplier').value;
            const material = document.getElementById('po-material').value;
            const unitPrice = parseFloat(document.getElementById('po-price').value);
            const qty = parseFloat(document.getElementById('po-qty').value);
            const deliveryDate = document.getElementById('po-delivery-date').value || new Date(Date.now() + 14*86400000).toISOString().split('T')[0];
            const terms = document.getElementById('po-terms').value;

            if (!unitPrice || !qty || unitPrice <= 0 || qty <= 0) {
                showToast('❌ Please enter valid price and quantity', 'error');
                return;
            }

            const result = await apiCall('create_po', {
                sample_id: sampleId,
                supplier, material, qty, unit_price: unitPrice,
                delivery_date: deliveryDate, terms
            });

            if (result.success) {
                showToast('✅ PO created! ID: ' + result.id, 'success');
                location.reload();
            } else {
                showToast('❌ Error: ' + result.message, 'error');
            }
        }

        async function approvePO(poId) {
            if (!confirm('Approve this PO?')) return;
            const result = await apiCall('approve_po', { po_id: poId });
            if (result.success) {
                showToast('✅ PO approved!', 'success');
                location.reload();
            }
        }

        function receiveMaterial(poId) {
            const po = pos.find(p => p.id === poId);
            if (!po) return;

            document.getElementById('grn-po').value = poId;
            document.getElementById('grn-qty').value = '';
            document.getElementById('grn-date').value = new Date().toISOString().split('T')[0];
            document.getElementById('grn-creation-area').dataset.poId = poId;
            document.getElementById('grn-creation-area').style.display = 'block';
            document.getElementById('grn-creation-area').scrollIntoView({ behavior: 'smooth' });
        }

        async function createGRN() {
            const poId = document.getElementById('grn-creation-area').dataset.poId;
            const po = pos.find(p => p.id === poId);
            if (!po) return;

            const receivedQty = parseFloat(document.getElementById('grn-qty').value);
            const receivedDate = document.getElementById('grn-date').value || new Date().toISOString().split('T')[0];

            if (!receivedQty || receivedQty <= 0) {
                showToast('❌ Please enter valid quantity', 'error');
                return;
            }

            const result = await apiCall('create_grn', {
                po_id: poId,
                supplier: po.supplier,
                material: po.material,
                ordered_qty: po.qty,
                received_qty: receivedQty,
                unit_price: po.unit_price,
                received_date: receivedDate,
                inspector: 'Stock Clerk',
                remarks: 'Good condition'
            });

            if (result.success) {
                showToast('✅ GRN created! ID: ' + result.id, 'success');
                location.reload();
            } else {
                showToast('❌ Error: ' + result.message, 'error');
            }
        }

        // ==================== TAB NAVIGATION ====================
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.sidebar-menu a').forEach(a => a.classList.remove('active'));

            const targetTab = document.getElementById(`tab-${tabName}`);
            if (targetTab) targetTab.classList.add('active');

            const menuMap = { 'overview': 0, 'products': 1, 'rawmaterial': 2, 'stockreport': 3, 'delivery': 4, 'returns': 5, 'suppliers': 6, 'supplier-flow': 7 };
            const menuItems = document.querySelectorAll('.sidebar-menu a');
            if (menuItems[menuMap[tabName]]) menuItems[menuMap[tabName]].classList.add('active');

            if (tabName === 'delivery') loadDeliveryStatus();
            if (tabName === 'supplier-flow') loadSampleFlowData();
            if (tabName === 'suppliers') loadActiveSuppliers();
            if (tabName === 'overview') {
                updateStats();
                loadCriticalAlerts();
                loadLowStockTable();
                loadReorderList();
                analyzeVelocity();
            }
        }

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            updateStats();
            loadProducts();
            loadLowStockTable();
            loadCriticalAlerts();
            loadReorderList();
            loadDeliveryStatus();
            loadReturnReport();
            loadRawMaterials();
            loadActiveSuppliers();
            loadSampleFlowData();
            analyzeVelocity();
            showTab('overview');
            showToast('👋 Welcome to Stock Dashboard!', 'info');
        });

        // Make functions globally accessible
        window.showTab = showTab;
        window.updateDeliveryStatus = updateDeliveryStatus;
        window.addProduct = addProduct;
        window.addRawMaterial = addRawMaterial;
        window.reorderProduct = reorderProduct;
        window.generateStockReport = generateStockReport;
        window.generateReturnReport = generateReturnReport;
        window.requestSample = requestSample;
        window.qcPass = qcPass;
        window.qcReject = qcReject;
        window.selectForPO = selectForPO;
        window.createPurchaseOrder = createPurchaseOrder;
        window.approvePO = approvePO;
        window.receiveMaterial = receiveMaterial;
        window.createGRN = createGRN;
        window.contactSupplier = contactSupplier;
        window.logoutUser = logoutUser;
        window.exportCSV = exportCSV;
        window.exportPDF = exportPDF;
        window.showToast = showToast;

        console.log('📦 Stock Clerk Dashboard loaded successfully!');
    </script>
</body>
</html>
<?php
// $conn is already closed above (right after the data fetch this page
// renders from) - closing it again here threw "mysqli object is already
// closed" on every single page load (PHP 8.1+ mysqli reports that as an
// Error, not a warning), appending a fatal-error dump after the rendered
// page's closing </html> tag.
?>
