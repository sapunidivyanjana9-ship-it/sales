<?php
// ============================================================
// PEARL LAND COMMODITIES - ACCOUNT CLERK DASHBOARD
// Complete Frontend + Backend
// ============================================================

// Database Configuration (centralized - see config/env.php)
require_once __DIR__ . '/config/db_bootstrap.php';

// Session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// DATABASE CONNECTION
// ============================================================

function getDBConnection() {
    try {
        pelcomo_ensure_schema();
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function checkAuth() {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'account_clerk') {
        sendResponse(['error' => 'Unauthorized', 'logged_in' => false], 401);
    }
    return $_SESSION;
}

// ============================================================
// CHECK IF API REQUEST
// ============================================================

$is_api = isset($_GET['api']) || isset($_POST['api']);

if ($is_api) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $pdo = getDBConnection();
    
    // ============================================================
    // AUTH API
    // ============================================================
    
    if ($action === 'check_auth') {
        if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'account_clerk') {
            echo json_encode([
                'logged_in' => true,
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'username' => $_SESSION['username'],
                    'full_name' => $_SESSION['full_name']
                ]
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        exit;
    }
    
    if ($action === 'login') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'account_clerk'");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            unset($user['password']);
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['error' => 'Invalid credentials'], 401);
        }
        exit;
    }
    
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ============================================================
    // CHECK AUTH FOR ALL OTHER API CALLS
    // ============================================================
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'account_clerk') {
        echo json_encode(['error' => 'Unauthorized', 'logged_in' => false]);
        exit;
    }
    
    // ============================================================
    // DASHBOARD STATS API
    // ============================================================
    
    if ($action === 'dashboard_stats') {
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_type = 'customer' AND status = 'paid'");
        $custTotal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_type = 'wholesaler' AND status = 'paid'");
        $wholeTotal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM supplier_payments WHERE status = 'paid'");
        $supTotal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT COUNT(*) as pending FROM grns WHERE status = 'Manager Approved'");
        $pendingGRNs = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM supplier_invoices");
        $invTotal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => [
            'customer_income' => $custTotal['total'] ?? 0,
            'wholesaler_income' => $wholeTotal['total'] ?? 0,
            'supplier_expense' => $supTotal['total'] ?? 0,
            'pending_grns' => $pendingGRNs['pending'] ?? 0,
            'total_invoices' => $invTotal['total'] ?? 0
        ]]);
        exit;
    }
    
    // ============================================================
    // CUSTOMER PAYMENTS API
    // ============================================================
    
    if ($action === 'customer_payments') {
        $stmt = $pdo->query("SELECT * FROM payments WHERE payment_type = 'customer' ORDER BY payment_id DESC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($action === 'add_customer_payment') {
        $data = json_decode(file_get_contents('php://input'), true);
        $payment_number = 'CPAY-' . date('Ymd') . '-' . rand(100, 999);
        
        $stmt = $pdo->prepare("INSERT INTO payments 
                               (payment_number, customer_name, payment_type, amount, payment_method, reference_no, payment_date, status) 
                               VALUES (?, ?, 'customer', ?, ?, ?, ?, 'paid')");
        $stmt->execute([
            $payment_number,
            $data['customer_name'],
            $data['amount'],
            $data['payment_method'],
            $data['reference_no'] ?? '',
            $data['payment_date'] ?? date('Y-m-d')
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Customer payment recorded', 'payment_number' => $payment_number]);
        exit;
    }
    
    // ============================================================
    // WHOLESALER PAYMENTS API
    // ============================================================
    
    if ($action === 'wholesaler_payments') {
        $stmt = $pdo->query("SELECT * FROM payments WHERE payment_type = 'wholesaler' ORDER BY payment_id DESC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($action === 'add_wholesaler_payment') {
        $data = json_decode(file_get_contents('php://input'), true);
        $payment_number = 'WPAY-' . date('Ymd') . '-' . rand(100, 999);
        
        $stmt = $pdo->prepare("INSERT INTO payments 
                               (payment_number, wholesaler_name, payment_type, amount, payment_method, reference_no, payment_date, status) 
                               VALUES (?, ?, 'wholesaler', ?, ?, ?, ?, 'paid')");
        $stmt->execute([
            $payment_number,
            $data['wholesaler_name'],
            $data['amount'],
            $data['payment_method'],
            $data['reference_no'] ?? '',
            $data['payment_date'] ?? date('Y-m-d')
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Wholesaler payment recorded', 'payment_number' => $payment_number]);
        exit;
    }
    
    // ============================================================
    // SUPPLIER PAYMENTS API
    // ============================================================
    
    if ($action === 'supplier_payments') {
        $stmt = $pdo->query("SELECT sp.*, g.grn_number, g.po_number, g.supplier_name, g.material
                             FROM supplier_payments sp 
                             LEFT JOIN grns g ON sp.grn_id = g.grn_id 
                             ORDER BY sp.supplier_payment_id DESC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($action === 'pending_grns') {
        $stmt = $pdo->query("SELECT * FROM grns WHERE status = 'Manager Approved' ORDER BY grn_id DESC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($action === 'process_supplier_payment') {
        $data = json_decode(file_get_contents('php://input'), true);
        $payment_number = 'SPAY-' . date('Ymd') . '-' . rand(100, 999);
        
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare("INSERT INTO supplier_payments 
                                   (payment_number, grn_id, supplier_id, amount, payment_method, reference_no, payment_date, status) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'paid')");
            $stmt->execute([
                $payment_number,
                $data['grn_id'],
                $data['supplier_id'],
                $data['amount'],
                $data['payment_method'],
                $data['reference_no'] ?? '',
                $data['payment_date'] ?? date('Y-m-d')
            ]);
            
            $stmt = $pdo->prepare("UPDATE grns SET status = 'Paid' WHERE grn_id = ?");
            $stmt->execute([$data['grn_id']]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Supplier payment processed', 'payment_number' => $payment_number]);
        } catch(PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Failed to process payment: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // ============================================================
    // SUPPLIER INVOICES API
    // ============================================================
    
    if ($action === 'supplier_invoices') {
        $stmt = $pdo->query("SELECT * FROM supplier_invoices ORDER BY invoice_id DESC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($action === 'generate_supplier_invoice') {
        $data = json_decode(file_get_contents('php://input'), true);
        $invoice_number = 'SINV-' . date('Ymd') . '-' . rand(100, 999);
        
        $stmt = $pdo->prepare("SELECT * FROM grns WHERE grn_id = ?");
        $stmt->execute([$data['grn_id']]);
        $grn = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("INSERT INTO supplier_invoices 
                               (invoice_number, grn_id, supplier_id, supplier_name, material, amount, invoice_date, due_date, payment_terms, status) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $invoice_number,
            $data['grn_id'],
            $grn['supplier_id'],
            $grn['supplier_name'],
            $grn['material'],
            $grn['amount'],
            $data['invoice_date'],
            $data['due_date'],
            $data['payment_terms'] ?? '30 Days Credit',
            $data['status'] ?? 'Pending'
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Supplier invoice generated', 'invoice_number' => $invoice_number]);
        exit;
    }
    
    // ============================================================
    // PAYMENT HISTORY API
    // ============================================================
    
    if ($action === 'payment_history') {
        $type = $_GET['type'] ?? 'all';
        $allPayments = [];
        
        $sql = "SELECT * FROM payments WHERE status = 'paid'";
        if ($type !== 'all' && $type !== 'Supplier') {
            $sql .= " AND payment_type = '" . $type . "'";
        }
        $stmt = $pdo->query($sql);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($payments as $p) {
            $allPayments[] = [
                'id' => $p['payment_number'],
                'type' => ucfirst($p['payment_type']),
                'party' => $p['customer_name'] ?? $p['wholesaler_name'] ?? 'N/A',
                'refId' => $p['order_id'] ?? 'N/A',
                'amount' => $p['amount'],
                'method' => $p['payment_method'],
                'date' => $p['payment_date'],
                'reference' => $p['reference_no'] ?? '-'
            ];
        }
        
        if ($type === 'all' || $type === 'Supplier') {
            $stmt = $pdo->query("SELECT * FROM supplier_payments WHERE status = 'paid'");
            $supplierPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($supplierPayments as $p) {
                $allPayments[] = [
                    'id' => $p['payment_number'],
                    'type' => 'Supplier',
                    'party' => $p['supplier_name'] ?? 'N/A',
                    'refId' => $p['grn_id'] ?? 'N/A',
                    'amount' => $p['amount'],
                    'method' => $p['payment_method'],
                    'date' => $p['payment_date'],
                    'reference' => $p['reference_no'] ?? '-'
                ];
            }
        }
        
        usort($allPayments, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        echo json_encode(['success' => true, 'data' => $allPayments]);
        exit;
    }
    
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ============================================================
// ============================================================
// FRONTEND HTML
// ============================================================
// ============================================================

$is_logged_in = isset($_SESSION['user_id']) && $_SESSION['role'] === 'account_clerk';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💰 Account Clerk Dashboard - Pearl Land Commodities</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="wrap">

        <!-- SIDEBAR -->
        <div class="side">
            <h2>🌶️ Pearl Land<br><small style="font-size:12px;font-weight:400">Finance Dept.</small></h2>
            <ul>
                <li><a onclick="show('dash')" class="act" id="nav-dash"><i class="ti ti-home"></i> Dashboard</a></li>
                <li><a onclick="show('cust-pay')" id="nav-cust-pay"><i class="ti ti-users"></i> Customer Payments</a></li>
                <li><a onclick="show('whole-pay')" id="nav-whole-pay"><i class="ti ti-building-store"></i> Wholesaler Payments</a></li>
                <li><a onclick="show('sup-pay')" id="nav-sup-pay"><i class="ti ti-truck"></i> Supplier Payments</a></li>
                <li><a onclick="show('sup-invoices')" id="nav-sup-invoices"><i class="ti ti-file-invoice"></i> Supplier Invoices</a></li>
                <li><a onclick="show('outstanding')" id="nav-outstanding"><i class="ti ti-file-invoice"></i> Outstanding</a></li>
                <li><a onclick="show('history')" id="nav-history"><i class="ti ti-history"></i> Payment History</a></li>
                <li><a onclick="show('reports')" id="nav-reports"><i class="ti ti-chart-bar"></i> Reports</a></li>
            </ul>
            <div class="sfoot">
                <div>💰 Account Clerk</div>
                <div style="font-size:12px;" id="userNameDisplay"><?php echo $_SESSION['full_name'] ?? 'Account Clerk'; ?></div>
                <button class="logout-btn" onclick="handleLogout()">🚪 Logout</button>
            </div>
        </div>

        <!-- MAIN -->
        <div class="main">
            <div class="hdr">
                <h1><i class="ti ti-dashboard"></i> Account Clerk Dashboard</h1>
                <div class="badge-role"><i class="ti ti-lock"></i> Finance Access</div>
            </div>

            <!-- STATS -->
            <div class="stats" id="stats"></div>

            <!-- DASHBOARD -->
            <div id="sec-dash" class="sec act">
                <div class="card">
                    <h2><i class="ti ti-chart-line"></i> Revenue Overview</h2>
                    <div class="period-btns">
                        <button class="pbtn act" id="pb-daily" onclick="setPeriod('daily')">Daily (7 days)</button>
                        <button class="pbtn" id="pb-weekly" onclick="setPeriod('weekly')">Weekly</button>
                        <button class="pbtn" id="pb-monthly" onclick="setPeriod('monthly')">Monthly</button>
                    </div>
                    <div class="legend">
                        <span><span class="dot" style="background:#3498db"></span>Customer Income</span>
                        <span><span class="dot" style="background:#9b59b6"></span>Wholesaler Income</span>
                        <span><span class="dot" style="background:#e74c3c"></span>Supplier Expense</span>
                        <span><span class="dot" style="background:#2ecc71"></span>Net Revenue (line)</span>
                    </div>
                    <div class="chart-wrap"><canvas id="revChart"></canvas></div>
                </div>

                <div class="card">
                    <h2><i class="ti ti-chart-donut"></i> Cash Flow Breakdown</h2>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:center">
                        <div>
                            <div class="legend">
                                <span><span class="dot" style="background:#3498db"></span>Customer</span>
                                <span><span class="dot" style="background:#9b59b6"></span>Wholesaler</span>
                                <span><span class="dot" style="background:#e74c3c"></span>Supplier</span>
                            </div>
                            <div class="chart-wrap" style="height:200px"><canvas id="pieChart"></canvas></div>
                        </div>
                        <div>
                            <p style="font-size:13px;color:#666;margin-bottom:10px">How revenue changes:</p>
                            <div style="background:#e8f8ef;border-radius:8px;padding:10px;margin-bottom:8px;font-size:13px">
                                <strong style="color:#155724">➕ Income:</strong> Customer & Wholesaler payments add to revenue
                            </div>
                            <div style="background:#fdecea;border-radius:8px;padding:10px;margin-bottom:8px;font-size:13px">
                                <strong style="color:#721c24">➖ Expense:</strong> Supplier payments reduce revenue
                            </div>
                            <div style="background:#fff3cd;border-radius:8px;padding:10px;font-size:13px">
                                <strong style="color:#856404">= Net Revenue:</strong> Income minus Supplier expenses
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2><i class="ti ti-bolt"></i> Quick Actions</h2>
                    <div class="quick">
                        <div class="qcard">
                            <h3>👤 Customer Payments</h3>
                            <p>Record payments received from customers</p>
                            <button class="btn-sm" onclick="show('cust-pay')">Open →</button>
                        </div>
                        <div class="qcard">
                            <h3>🏪 Wholesaler Payments</h3>
                            <p>Record payments received from wholesalers</p>
                            <button class="btn-sm" onclick="show('whole-pay')">Open →</button>
                        </div>
                        <div class="qcard">
                            <h3>🚛 Supplier Payments</h3>
                            <p>Process approved GRN supplier payments</p>
                            <button class="btn-sm" onclick="show('sup-pay')">Open →</button>
                        </div>
                        <div class="qcard">
                            <h3>📄 Supplier Invoices</h3>
                            <p>Generate invoices for approved GRNs</p>
                            <button class="btn-sm" onclick="show('sup-invoices')">Open →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CUSTOMER PAYMENTS -->
            <div id="sec-cust-pay" class="sec">
                <div class="card">
                    <h2><i class="ti ti-plus"></i> Record Customer Payment</h2>
                    <div class="form-row">
                        <div class="fg"><label>Customer Name</label><input type="text" id="cp-name" placeholder="e.g. Saman Perera"></div>
                        <div class="fg"><label>Invoice #</label><input type="text" id="cp-inv" placeholder="INV-2026-0001"></div>
                        <div class="fg"><label>Amount (LKR)</label><input type="number" id="cp-amount" placeholder="0.00" min="0"></div>
                    </div>
                    <div class="form-row">
                        <div class="fg"><label>Payment Date</label><input type="date" id="cp-date"></div>
                        <div class="fg"><label>Payment Method</label>
                            <select id="cp-method">
                                <option>Cash</option>
                                <option>Cheque</option>
                                <option>Bank Transfer</option>
                                <option>Online</option>
                            </select>
                        </div>
                        <div class="fg"><label>Reference No</label><input type="text" id="cp-ref" placeholder="Cheque / Transaction ID"></div>
                    </div>
                    <button class="btn btn-s" onclick="addCustomerPayment()"><i class="ti ti-check"></i> Record Payment</button>
                </div>
                <div class="card">
                    <h2><i class="ti ti-list"></i> Customer Payment Records</h2>
                    <table class="tbl">
                        <thead><tr><th>ID</th><th>Customer</th><th>Invoice #</th><th>Amount</th><th>Method</th><th>Date</th><th>Reference</th></tr></thead>
                        <tbody id="cust-pay-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- WHOLESALER PAYMENTS -->
            <div id="sec-whole-pay" class="sec">
                <div class="card">
                    <h2><i class="ti ti-plus"></i> Record Wholesaler Payment</h2>
                    <div class="form-row">
                        <div class="fg"><label>Wholesaler Name</label><input type="text" id="wp-name" placeholder="e.g. Colombo Wholesale Co."></div>
                        <div class="fg"><label>Invoice #</label><input type="text" id="wp-inv" placeholder="WINV-2026-0001"></div>
                        <div class="fg"><label>Amount (LKR)</label><input type="number" id="wp-amount" placeholder="0.00" min="0"></div>
                    </div>
                    <div class="form-row">
                        <div class="fg"><label>Payment Date</label><input type="date" id="wp-date"></div>
                        <div class="fg"><label>Payment Method</label>
                            <select id="wp-method">
                                <option>Cash</option>
                                <option>Cheque</option>
                                <option>Bank Transfer</option>
                                <option>Online</option>
                            </select>
                        </div>
                        <div class="fg"><label>Reference No</label><input type="text" id="wp-ref" placeholder="Cheque / Transaction ID"></div>
                    </div>
                    <button class="btn btn-s" onclick="addWholesalerPayment()"><i class="ti ti-check"></i> Record Payment</button>
                </div>
                <div class="card">
                    <h2><i class="ti ti-list"></i> Wholesaler Payment Records</h2>
                    <table class="tbl">
                        <thead><tr><th>ID</th><th>Wholesaler</th><th>Invoice #</th><th>Amount</th><th>Method</th><th>Date</th><th>Reference</th></tr></thead>
                        <tbody id="whole-pay-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- SUPPLIER PAYMENTS -->
            <div id="sec-sup-pay" class="sec">
                <div class="card">
                    <h2><i class="ti ti-truck"></i> Supplier Payments (Based on Approved GRNs)</h2>
                    <div class="info-note">📌 When GRN is approved by Manager, you can process payment here.</div>
                    <table class="tbl">
                        <thead><tr><th>GRN #</th><th>PO #</th><th>Supplier</th><th>Material</th><th>Qty</th><th>Unit Price</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="sup-pay-body"></tbody>
                    </table>
                </div>
                <div class="card sup-form-card" id="sup-form" style="display:none">
                    <h2><i class="ti ti-coin"></i> Process Supplier Payment</h2>
                    <div class="form-row">
                        <div class="fg"><label>GRN #</label><input type="text" id="sf-grn" readonly></div>
                        <div class="fg"><label>Supplier</label><input type="text" id="sf-sup" readonly></div>
                        <div class="fg"><label>Amount (LKR)</label><input type="text" id="sf-amt" readonly></div>
                    </div>
                    <div class="form-row">
                        <div class="fg"><label>Payment Date</label><input type="date" id="sf-date"></div>
                        <div class="fg"><label>Payment Method</label>
                            <select id="sf-method">
                                <option>Cash</option>
                                <option>Cheque</option>
                                <option>Bank Transfer</option>
                            </select>
                        </div>
                        <div class="fg"><label>Reference No</label><input type="text" id="sf-ref" placeholder="Cheque / Transaction ID"></div>
                    </div>
                    <button class="btn btn-s" onclick="processSupPay()"><i class="ti ti-check"></i> Process Payment</button>
                    <button class="btn btn-o" style="margin-left:8px" onclick="document.getElementById('sup-form').style.display='none'">Cancel</button>
                </div>
            </div>

            <!-- SUPPLIER INVOICES -->
            <div id="sec-sup-invoices" class="sec">
                <div class="card">
                    <h2><i class="ti ti-file-invoice"></i> Supplier Invoice Management</h2>
                    <div class="info-note">📌 Generate supplier invoice from approved GRN. Supplier will receive this invoice.</div>
                </div>
                <div class="card">
                    <h2><i class="ti ti-list"></i> Approved GRNs Ready for Invoice</h2>
                    <table class="tbl">
                        <thead><tr><th>GRN #</th><th>PO #</th><th>Supplier</th><th>Material</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="sup-invoice-body"></tbody>
                    </table>
                </div>
                <div class="card" id="sup-invoice-form" style="display:none">
                    <h2><i class="ti ti-file"></i> Generate Supplier Invoice</h2>
                    <div class="form-row">
                        <div class="fg"><label>GRN #</label><input type="text" id="si-grn" readonly></div>
                        <div class="fg"><label>Supplier</label><input type="text" id="si-sup" readonly></div>
                        <div class="fg"><label>Amount (LKR)</label><input type="text" id="si-amt" readonly></div>
                    </div>
                    <div class="form-row">
                        <div class="fg"><label>Invoice Date</label><input type="date" id="si-date"></div>
                        <div class="fg"><label>Due Date</label><input type="date" id="si-due"></div>
                        <div class="fg"><label>Payment Terms</label>
                            <select id="si-terms">
                                <option>30 Days Credit</option>
                                <option>Cash on Delivery</option>
                                <option>Net 45 Days</option>
                                <option>Advance 50%</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg"><label>Invoice Number</label><input type="text" id="si-number" readonly></div>
                        <div class="fg"><label>Status</label>
                            <select id="si-status">
                                <option>Pending</option>
                                <option>Sent</option>
                                <option>Paid</option>
                            </select>
                        </div>
                    </div>
                    <div class="invoice-preview" id="si-preview"></div>
                    <button class="btn btn-s" onclick="generateSupplierInvoice()"><i class="ti ti-check"></i> Generate & Send Invoice</button>
                    <button class="btn btn-o" style="margin-left:8px" onclick="document.getElementById('sup-invoice-form').style.display='none'">Cancel</button>
                </div>
            </div>

            <!-- OUTSTANDING -->
            <div id="sec-outstanding" class="sec">
                <div class="card">
                    <h2><i class="ti ti-file-invoice"></i> Customer Outstanding Payments</h2>
                    <table class="tbl">
                        <thead><tr><th>Customer</th><th>Invoice #</th><th>Date</th><th>Due Date</th><th>Amount</th><th>Days Overdue</th><th>Status</th></tr></thead>
                        <tbody id="out-body"></tbody>
                    </table>
                </div>
                <div class="card">
                    <h2><i class="ti ti-file-invoice"></i> Supplier Outstanding Payments</h2>
                    <table class="tbl">
                        <thead><tr><th>Supplier</th><th>Invoice #</th><th>Date</th><th>Due Date</th><th>Amount</th><th>Days Overdue</th><th>Status</th></tr></thead>
                        <tbody id="sup-out-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- PAYMENT HISTORY -->
            <div id="sec-history" class="sec">
                <div class="card">
                    <h2><i class="ti ti-history"></i> All Payment History</h2>
                    <div class="tabs">
                        <button class="tab act" onclick="filterHistory('all',this)">All</button>
                        <button class="tab" onclick="filterHistory('Customer',this)">Customer</button>
                        <button class="tab" onclick="filterHistory('Wholesaler',this)">Wholesaler</button>
                        <button class="tab" onclick="filterHistory('Supplier',this)">Supplier</button>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>ID</th><th>Type</th><th>Party</th><th>Invoice/GRN</th><th>Amount</th><th>Method</th><th>Date</th><th>Reference</th></tr></thead>
                        <tbody id="hist-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- REPORTS -->
            <div id="sec-reports" class="sec">
                <div class="card">
                    <h2><i class="ti ti-report"></i> Generate Financial Report</h2>
                    <div class="form-row" style="background:var(--cream);padding:16px;border-radius:10px">
                        <div class="fg"><label>Report Type</label>
                            <select id="rpt-type">
                                <option value="supplier">Supplier Payment Report</option>
                                <option value="customer">Customer Collection Report</option>
                                <option value="wholesaler">Wholesaler Collection Report</option>
                                <option value="cashflow">Cash Flow Statement</option>
                            </select>
                        </div>
                        <div class="fg"><label>From Date</label><input type="date" id="rpt-from"></div>
                        <div class="fg"><label>To Date</label><input type="date" id="rpt-to"></div>
                        <div class="fg" style="display:flex;align-items:flex-end">
                            <button class="btn btn-s" onclick="genReport()"><i class="ti ti-file-analytics"></i> Generate Report</button>
                        </div>
                    </div>
                </div>
                <div id="rpt-out"></div>
            </div>

        </div><!-- /main -->
    </div><!-- /wrap -->

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
    <script src="js/script.js"></script>
</body>
</html>