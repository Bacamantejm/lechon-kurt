<?php
/**
 * API: User Tutorial & Onboarding State Manager
 * Handles checking and updating tutorial completion for customers and shop owners.
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

$type = strtolower(trim((string)($_REQUEST['type'] ?? 'customer')));
if (!in_array($type, ['customer', 'shop_owner'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid tutorial type specified.'
    ]);
    exit;
}

$column_name = ($type === 'shop_owner') ? 'tutorial_seen_shop' : 'tutorial_seen_customer';

// Handle GET request to check status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = mysqli_prepare($conn, "SELECT {$column_name} FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $seen);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        echo json_encode([
            'success' => true,
            'type' => $type,
            'seen' => $found ? ((int)$seen === 1) : false
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'seen' => false]);
    exit;
}

// Handle POST request to update state
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!empty($input['type'])) {
        $type = strtolower(trim((string)$input['type']));
        if (!in_array($type, ['customer', 'shop_owner'], true)) {
            $type = 'customer';
        }
        $column_name = ($type === 'shop_owner') ? 'tutorial_seen_shop' : 'tutorial_seen_customer';
    }

    $seen_val = isset($input['reset']) && $input['reset'] ? 0 : 1;

    $stmt = mysqli_prepare($conn, "UPDATE users SET {$column_name} = ? WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $seen_val, $user_id);
        $updated = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        echo json_encode([
            'success' => $updated,
            'type' => $type,
            'seen' => ($seen_val === 1),
            'message' => $updated ? 'Tutorial progress saved.' : 'Failed to update tutorial progress.'
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error updating tutorial state.'
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
