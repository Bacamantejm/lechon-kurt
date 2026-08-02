<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkEmployeeAccess() {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    // Check if user is an employee or an admin (admins can access employee areas)
    $user_type = strtolower($_SESSION['user_type'] ?? 'customer');
    if ($user_type !== 'employee' && $user_type !== 'admin') {
        header("Location: ../index.php?error=unauthorized");
        exit;
    }
}