<?php
// manager/manager-dashboard.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug-friendly session validation
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    error_log("Session Validation Error: user_id or role missing in session.");
} else {
    error_log("Session Validation Success: Logged in as user_id " . $_SESSION['user_id'] . " with role " . $_SESSION['role']);
}

require_once __DIR__ . '/../includes/auth.php';
requireRole('manager');

// Rest of the legacy dashboard logic...
?>
