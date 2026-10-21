<?php
/**
 * API: Dismiss Mobile Get Started Splash Screen
 * Marks mobile_splash_seen = 1 for the authenticated customer.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'User is not authenticated.'
    ]);
    exit;
}

// Clear session flag immediately
$_SESSION['show_mobile_get_started'] = false;

// Ensure mobile_splash_seen column exists
if (function_exists('ensureUserMobileSplashSchema')) {
    ensureUserMobileSplashSchema($conn);
}

$stmt = mysqli_prepare($conn, "UPDATE users SET mobile_splash_seen = 1 WHERE id = ?");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode([
        'success' => $ok,
        'seen' => true,
        'message' => 'Mobile splash recorded as seen.'
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Failed to prepare update query.'
]);
