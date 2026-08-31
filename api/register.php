<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

function districts(): array
{
    return [
        'Colombo', 'Gampaha', 'Kalutara', 'Kandy', 'Matale', 'Nuwara Eliya',
        'Galle', 'Matara', 'Hambantota', 'Jaffna', 'Kilinochchi', 'Mannar',
        'Vavuniya', 'Mullaitivu', 'Batticaloa', 'Ampara', 'Trincomalee',
        'Kurunegala', 'Puttalam', 'Anuradhapura', 'Polonnaruwa', 'Badulla',
        'Moneragala', 'Ratnapura', 'Kegalle'
    ];
}

function customer_spices(): array
{
    return [
        'Turmeric', 'Chili Powder', 'Black Pepper', 'Cinnamon', 'Cardamom',
        'Coriander', 'Curry Powder', 'Cloves', 'Nutmeg', 'Vanilla'
    ];
}

function short_code(string $prefix): string
{
    return $prefix . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function password_error(string $password): string
{
    if (strlen($password) < 6 || !preg_match('/[0-9]/', $password) || !preg_match('/[A-Z]/', $password)) {
        return 'Password must be at least 6 characters and include at least 1 number and 1 uppercase letter';
    }

    return '';
}

endpoint_guard(function (): void {
    require_method(['POST']);

    $input = json_input();
    $role = strtolower(trim((string)($input['role'] ?? '')));
    $allowedRoles = ['customer', 'supplier', 'wholesaler'];

    if (!in_array($role, $allowedRoles, true)) {
        fail('Invalid registration type', 422);
    }

    // confirm_password field can be provided as `confirm_password` from UI;
    // allow fallback to `confirm` / `confirmPassword` if UI doesn't send the exact key.
    if (!isset($input['confirm_password'])) {
        foreach (['confirm', 'confirmPassword', 'password_confirmation', 'confirmPass', 'cpassword'] as $alt) {
            if (isset($input[$alt])) {
                $input['confirm_password'] = $input[$alt];
                break;
            }
        }
    }

    // support UIs that send `confirm_password` or just `confirm_password`-equivalent key.
    // (we only require it if it exists after fallback logic)
    require_fields($input, ['email', 'phone', 'address', 'city', 'postal_code', 'username', 'password', 'confirm_password']);

    $email = trim((string)$input['email']);

    $phone = trim((string)$input['phone']);
    $address = trim((string)$input['address']);
    $district = trim((string)$input['city']);
    $postalCode = trim((string)$input['postal_code']);
    $username = trim((string)$input['username']);
    $password = (string)$input['password'];
    $confirmPassword = (string)$input['confirm_password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid email address', 422);
    }

    if (!preg_match('/^07[0-9]{8}$/', $phone)) {
        fail('Phone number must be exactly 10 digits', 422);
    }

    if (!preg_match('/^[0-9]{5}$/', $postalCode)) {
        fail('Postal code must be exactly 5 digits', 422);
    }

    if (!in_array($district, districts(), true)) {
        fail('Please select a valid Sri Lankan district', 422);
    }

    if (strlen($username) < 4) {
        fail('Username must be at least 4 characters', 422);
    }

    $passwordError = password_error($password);
    if ($passwordError !== '') {
        fail($passwordError, 422);
    }

    if ($password !== $confirmPassword) {
        fail('Password and confirm password do not match', 422);
    }

    $pdo = get_pdo();
    ensure_supplier_request_columns($pdo);
    ensure_pending_registration_table($pdo);

    // Check username/email is not already used by an active user
    // or already pending in the unified requests table
    // or already pending in the legacy supplier requests table.
    $exists = $pdo->prepare('
        SELECT user_id FROM users WHERE username = ? OR email = ?
        UNION
        SELECT request_id AS user_id FROM pending_registration_requests WHERE (username = ? OR email = ?) AND status = "Pending"
        UNION
        SELECT request_id AS user_id FROM supplier_registration_requests WHERE (username = ? OR email = ?) AND status = "Pending"
        LIMIT 1
    ');
    $exists->execute([$username, $email, $username, $email, $username, $email]);
    if ($exists->fetch()) {
        fail('Username or email is already registered or pending approval', 409);
    }

    $pdo->beginTransaction();

    if ($role === 'customer') {
        require_fields($input, ['first_name', 'last_name']);

        $firstName = trim((string)$input['first_name']);
        $lastName = trim((string)$input['last_name']);
        $fullName = trim($firstName . ' ' . $lastName);
        $spicePreferences = $input['spice_preferences'] ?? [];
        $spicePreferences = is_array($spicePreferences)
            ? $spicePreferences
            : array_filter(array_map('trim', explode(',', (string)$spicePreferences)));
        $spicePreferences = array_values(array_intersect($spicePreferences, customer_spices()));

        if (count($spicePreferences) === 0) {
            $pdo->rollBack();
            fail('Please select at least one spice preference', 422);
        }

        $requestCode = short_code('CUSTREQ');
        $extraData = json_encode([
            'spice_preferences' => $spicePreferences,
        ]);

        $stmt = $pdo->prepare('
            INSERT INTO pending_registration_requests
                (request_code, role, username, password, full_name, first_name, last_name,
                 email, phone, address, city, postal_code, extra_data, status)
            VALUES (?, "customer", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Pending")
        ');
        $stmt->execute([
            $requestCode,
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $fullName,
            $firstName,
            $lastName,
            $email,
            $phone,
            $address,
            $district,
            $postalCode,
            $extraData,
        ]);

        $pdo->commit();
        respond(true, 'Registration submitted. Pending approval by admin.', [
            'request_code' => $requestCode,
            'redirect' => 'index.html',
        ], 201);
    }

    if ($role === 'wholesaler') {
        require_fields($input, ['first_name', 'last_name', 'company_name', 'business_type']);

        $businessType = trim((string)$input['business_type']);
        $allowedBusinessTypes = ['Retailer', 'Wholesaler', 'Distributor', 'Exporter'];
        if (!in_array($businessType, $allowedBusinessTypes, true)) {
            $pdo->rollBack();
            fail('Please select a valid business type', 422);
        }

        $firstName = trim((string)$input['first_name']);
        $lastName = trim((string)$input['last_name']);
        $fullName = trim($firstName . ' ' . $lastName);
        $companyName = trim((string)$input['company_name']);

        $spicePreferences = $input['spice_preferences'] ?? [];
        $spicePreferences = is_array($spicePreferences)
            ? $spicePreferences
            : array_filter(array_map('trim', explode(',', (string)$spicePreferences)));

        $requestCode = short_code('WHLREQ');
        $extraData = json_encode([
            'spice_preferences' => array_values($spicePreferences),
            'wants_offers' => !empty($input['wants_offers']) ? 1 : 0,
        ]);

        $stmt = $pdo->prepare('
            INSERT INTO pending_registration_requests
                (request_code, role, username, password, full_name, first_name, last_name,
                 company_name, business_type, email, phone, address, city, postal_code,
                 extra_data, status)
            VALUES (?, "wholesaler", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Pending")
        ');
        $stmt->execute([
            $requestCode,
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $fullName,
            $firstName,
            $lastName,
            $companyName,
            $businessType,
            $email,
            $phone,
            $address,
            $district,
            $postalCode,
            $extraData,
        ]);

        $pdo->commit();
        respond(true, 'Registration submitted. Pending approval by admin.', [
            'request_code' => $requestCode,
            'redirect' => 'index.html',
        ], 201);
    }

    if ($role === 'supplier') {
        // The supplier form labels this field "Contact Person" but posts it as
        // `full_name`; accept either so the UI and API agree.
        if (!isset($input['contact_person']) && isset($input['full_name'])) {
            $input['contact_person'] = $input['full_name'];
        }

        require_fields($input, ['company_name', 'contact_person', 'business_type', 'materials']);

        $businessType = trim((string)$input['business_type']);
        $allowedSupplierTypes = ['Spice Supplier', 'Raw Material Supplier', 'Both', 'Packaging Supplier', 'Transport Service'];
        if (!in_array($businessType, $allowedSupplierTypes, true)) {
            $pdo->rollBack();
            fail('Please select a valid supplier business type', 422);
        }

        $requestCode = generate_code('SUPREQ');
        $stmt = $pdo->prepare('
            INSERT INTO supplier_registration_requests
                (request_code, username, company_name, contact_person, email, phone, address, city, postal_code, materials, business_type, password, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Pending")
        ');
        $stmt->execute([
            $requestCode,
            $username,
            trim((string)$input['company_name']),
            trim((string)$input['contact_person']),
            $email,
            $phone,
            $address,
            $district,
            $postalCode,
            trim((string)$input['materials']),
            $businessType,
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        $pdo->commit();
        respond(true, 'Supplier registration submitted. Pending approval by admin or manager.', [
            'request_code' => $requestCode,
            'redirect' => 'index.html',
        ], 201);
    }

    $pdo->rollBack();
    fail('Unsupported registration type', 422);
});
