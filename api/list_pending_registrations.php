<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

/**
 * Returns the unified pending registration requests (customer + wholesaler)
 * together with the legacy supplier_registration_requests, normalized into
 * one shape so the admin dashboard can render them in a single table.
 */
endpoint_guard(function (): void {
    require_method(['GET']);

    $pdo = get_pdo();
    ensure_pending_registration_table($pdo);
    ensure_supplier_request_columns($pdo);

    $results = [];

    // --- Customer / Wholesaler requests ---
    $stmt = $pdo->query('
        SELECT request_id, request_code, role, username, full_name, first_name, last_name,
               company_name, business_type, email, phone, address, city, postal_code,
               extra_data, status, created_at
        FROM pending_registration_requests
        ORDER BY created_at DESC
    ');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $extra = $row['extra_data'] ? json_decode((string)$row['extra_data'], true) : [];
        $results[] = [
            'request_id'   => (int)$row['request_id'],
            'request_code' => $row['request_code'],
            'role'         => $row['role'],
            'username'     => $row['username'],
            'full_name'    => $row['full_name'],
            'company_name' => $row['company_name'],
            'business_type'=> $row['business_type'],
            'email'        => $row['email'],
            'phone'        => $row['phone'],
            'address'      => $row['address'],
            'city'         => $row['city'],
            'postal_code'  => $row['postal_code'],
            'extra'        => $extra,
            'status'       => $row['status'],
            'created_at'   => $row['created_at'],
            'source'       => 'pending_registration_requests',
        ];
    }

    // --- Supplier requests (legacy table) ---
    $stmt = $pdo->query('
        SELECT request_id, request_code, username, company_name, contact_person, business_type,
               email, phone, address, city, postal_code, materials, status,
               submitted_at AS created_at
        FROM supplier_registration_requests
        ORDER BY submitted_at DESC
    ');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'request_id'   => (int)$row['request_id'],
            'request_code' => $row['request_code'],
            'role'         => 'supplier',
            'username'     => $row['username'],
            'full_name'    => $row['contact_person'],
            'company_name' => $row['company_name'],
            'business_type'=> $row['business_type'],
            'email'        => $row['email'],
            'phone'        => $row['phone'],
            'address'      => $row['address'],
            'city'         => $row['city'],
            'postal_code'  => $row['postal_code'],
            'extra'        => ['materials' => $row['materials']],
            'status'       => $row['status'],
            'created_at'   => $row['created_at'],
            'source'       => 'supplier_registration_requests',
        ];
    }

    respond(true, 'Pending registration requests fetched.', ['requests' => $results]);
});