<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function customer_spices(): array
{
    // Keep in sync with the same allow-list in api/register.php.
    return [
        'Turmeric', 'Chili', 'Cinnamon', 'Black Pepper', 'Cardamom',
        'Cloves', 'Coriander', 'Curry Powder', 'Fenugreek'
    ];
}

function short_code(string $prefix): string
{
    return $prefix . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Sends an "your account has been approved" email to the applicant.
 * Uses PHP's built-in mail() function. Configure your server's
 * sendmail / SMTP settings (php.ini) for this to actually deliver.
 */
function send_approval_email(string $email, string $fullName, string $username, string $loginUrl): void
{
    $subject = 'Pearl Land Commodities - Your account has been approved!';

    $message  = "Hi " . ($fullName !== '' ? $fullName : $username) . ",\n\n";
    $message .= "Good news! Your registration request has been reviewed and APPROVED.\n\n";
    $message .= "You can now log in using the username and password you created during registration:\n";
    $message .= "Username: $username\n\n";
    $message .= "Login here: $loginUrl\n\n";
    $message .= "Thank you for joining Pearl Land Commodities!\n";

    $headers = "From: Pearl Land Commodities <noreply@pearlland.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($email, $subject, $message, $headers);
}

/**
 * Sends a "your application was rejected" email to the applicant.
 */
function send_rejection_email(string $email, string $fullName, string $username, string $reason): void
{
    $subject = 'Pearl Land Commodities - Registration Update';

    $message  = "Hi " . ($fullName !== '' ? $fullName : $username) . ",\n\n";
    $message .= "We're sorry to let you know that your registration request was not approved.\n";
    if ($reason !== '') {
        $message .= "Reason: $reason\n";
    }
    $message .= "\nIf you believe this is a mistake, please contact our support team or try registering again with correct details.\n\n";
    $message .= "Thank you,\nPearl Land Commodities";

    $headers = "From: Pearl Land Commodities <noreply@pearlland.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($email, $subject, $message, $headers);
}

endpoint_guard(function (): void {
    require_method(['POST']);

    $input = json_input();
    require_fields($input, ['request_id', 'role', 'action']);

    $requestId = (int)$input['request_id'];
    $role = strtolower(trim((string)$input['role']));
    $action = strtolower(trim((string)$input['action'])); // "approve" or "reject"
    $reason = trim((string)($input['reason'] ?? ''));

    if (!in_array($action, ['approve', 'reject'], true)) {
        fail('Invalid action. Must be "approve" or "reject".', 422);
    }

    $allowedRoles = ['customer', 'wholesaler', 'supplier'];
    if (!in_array($role, $allowedRoles, true)) {
        fail('Invalid role.', 422);
    }

    $pdo = get_pdo();
    $loginUrl = 'https://yourdomain.com/index.html';

    // ===========================================================
    // SUPPLIER (legacy table)
    // ===========================================================
    if ($role === 'supplier') {
        $stmt = $pdo->prepare('SELECT * FROM supplier_registration_requests WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            fail('Registration request not found.', 404);
        }

        if ($req['status'] !== 'Pending') {
            fail('This request has already been processed.', 409);
        }

        if ($action === 'reject') {
            $pdo->prepare('UPDATE supplier_registration_requests SET status = "Rejected" WHERE request_id = ?')
                ->execute([$requestId]);

            send_rejection_email($req['email'], (string)$req['contact_person'], (string)$req['username'], $reason);

            respond(true, 'Supplier registration rejected.', []);
        }

        // approve
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO users (username, password, role, full_name, email, phone, address, city, status, redirect_page)
            VALUES (?, ?, "supplier", ?, ?, ?, ?, ?, "active", "supplierdashboard.html")
        ');
        $stmt->execute([
            $req['username'],
            $req['password'],
            $req['contact_person'],
            $req['email'],
            $req['phone'],
            $req['address'],
            $req['city'],
        ]);
        $userId = (int)$pdo->lastInsertId();

        // Register the approved supplier in the suppliers directory so the stock
        // and manager dashboards can see them.
        $stmt = $pdo->prepare('
            INSERT INTO suppliers
                (user_id, supplier_code, name, contact, email, phone, address, city,
                 postal_code, materials, business_type, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")
        ');
        $stmt->execute([
            $userId,
            short_code('SUP'),
            $req['company_name'],
            $req['contact_person'],
            $req['email'],
            $req['phone'],
            $req['address'],
            $req['city'],
            $req['postal_code'] ?? null,
            $req['materials'],
            $req['business_type'],
        ]);

        $pdo->prepare('UPDATE supplier_registration_requests SET status = "Approved" WHERE request_id = ?')
            ->execute([$requestId]);

        $pdo->commit();

        send_approval_email($req['email'], (string)$req['contact_person'], (string)$req['username'], $loginUrl);

        respond(true, 'Supplier registration approved.', ['user_id' => $userId]);
    }

    // ===========================================================
    // CUSTOMER / WHOLESALER (unified pending_registration_requests)
    // ===========================================================
    $stmt = $pdo->prepare('SELECT * FROM pending_registration_requests WHERE request_id = ? AND role = ?');
    $stmt->execute([$requestId, $role]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        fail('Registration request not found.', 404);
    }

    if ($req['status'] !== 'Pending') {
        fail('This request has already been processed.', 409);
    }

    if ($action === 'reject') {
        $pdo->prepare('UPDATE pending_registration_requests SET status = "Rejected", reviewed_at = NOW() WHERE request_id = ?')
            ->execute([$requestId]);

        send_rejection_email($req['email'], (string)$req['full_name'], (string)$req['username'], $reason);

        respond(true, ucfirst($role) . ' registration rejected.', []);
    }

    // approve
    $extra = $req['extra_data'] ? json_decode((string)$req['extra_data'], true) : [];
    $pdo->beginTransaction();

    if ($role === 'customer') {
        $stmt = $pdo->prepare('
            INSERT INTO users (username, password, role, full_name, email, phone, address, city, status, redirect_page)
            VALUES (?, ?, "customer", ?, ?, ?, ?, ?, "active", "customer.html")
        ');
        $stmt->execute([
            $req['username'],
            $req['password'],
            $req['full_name'],
            $req['email'],
            $req['phone'],
            $req['address'],
            $req['city'],
        ]);
        $userId = (int)$pdo->lastInsertId();

        $spicePreferences = $extra['spice_preferences'] ?? [];
        $spicePreferences = array_values(array_intersect((array)$spicePreferences, customer_spices()));

        $stmt = $pdo->prepare('
            INSERT INTO customers
                (user_id, customer_code, first_name, last_name, name, email, phone, address, city, postal_code, district, spice_preferences, account_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")
        ');
        $stmt->execute([
            $userId,
            short_code('CUST'),
            $req['first_name'],
            $req['last_name'],
            $req['full_name'],
            $req['email'],
            $req['phone'],
            $req['address'],
            $req['city'],
            $req['postal_code'],
            $req['city'],
            implode(', ', $spicePreferences),
        ]);
    } else { // wholesaler
        $stmt = $pdo->prepare('
            INSERT INTO users (username, password, role, full_name, email, phone, address, city, status, redirect_page)
            VALUES (?, ?, "wholesaler", ?, ?, ?, ?, ?, "active", "wholeseller.html")
        ');
        $stmt->execute([
            $req['username'],
            $req['password'],
            $req['full_name'],
            $req['email'],
            $req['phone'],
            $req['address'],
            $req['city'],
        ]);
        $userId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('
            INSERT INTO wholesalers
                (user_id, wholesaler_code, first_name, last_name, company_name, email, phone, address, city, postal_code, district, business_type, account_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")
        ');
        $stmt->execute([
            $userId,
            short_code('WHL'),
            $req['first_name'],
            $req['last_name'],
            $req['company_name'],
            $req['email'],
            $req['phone'],
            $req['address'],
            $req['city'],
            $req['postal_code'],
            $req['city'],
            $req['business_type'],
        ]);
    }

    $pdo->prepare('UPDATE pending_registration_requests SET status = "Approved", reviewed_at = NOW() WHERE request_id = ?')
        ->execute([$requestId]);

    $pdo->commit();

    send_approval_email($req['email'], (string)$req['full_name'], (string)$req['username'], $loginUrl);

    respond(true, ucfirst($role) . ' registration approved.', ['user_id' => $userId]);
});