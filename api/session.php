<?php
require_once __DIR__ . '/db_connect.php';

/**
 * Who is logged in, according to the PHP session - the same check every
 * other api/*.php endpoint makes. Dashboards use this instead of trusting
 * the localStorage copy of the last login, which survives logout and goes
 * stale as soon as the profile changes.
 */
endpoint_guard(function (): void {
    require_method(['GET']);

    $user = current_user();
    if (!$user) {
        respond(false, 'Not authenticated', ['authenticated' => false], 401);
    }

    $profile = find_party_for_user((int)$user['user_id'], $user['role']);

    respond(true, 'Session active', [
        'authenticated' => true,
        'user' => public_user($user),
        'profile' => $profile,
    ]);
});
