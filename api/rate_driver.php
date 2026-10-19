<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a rating.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Read input (support both application/json and form-urlencoded)
$input_data = [];
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($content_type, 'application/json') !== false) {
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true) ?: [];
} else {
    $input_data = $_POST;
}

$order_id = isset($input_data['order_id']) ? (int)$input_data['order_id'] : 0;
$rating = isset($input_data['rating']) ? (int)$input_data['rating'] : 0;
$comment = isset($input_data['comment']) ? trim((string)$input_data['comment']) : '';

if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a star rating between 1 and 5.']);
    exit;
}

// Verify order ownership and status
$ord_stmt = $conn->prepare("
    SELECT o.id, o.status, o.delivery_option, lt.driver_id, lt.driver_name, lt.current_status as tracking_status
    FROM orders o
    LEFT JOIN logistics_tracking lt ON o.id = lt.order_id
    WHERE o.id = ? AND o.user_id = ?
    LIMIT 1
");

if (!$ord_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

$ord_stmt->bind_param("ii", $order_id, $user_id);
$ord_stmt->execute();
$ord_res = $ord_stmt->get_result();
$order_data = $ord_res ? $ord_res->fetch_assoc() : null;
$ord_stmt->close();

if (!$order_data) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Order not found or permission denied.']);
    exit;
}

$is_delivered = in_array(strtolower((string)$order_data['status']), ['delivered', 'completed'], true) 
             || in_array(strtolower((string)($order_data['tracking_status'] ?? '')), ['delivered', 'completed'], true);

if (!$is_delivered) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ratings can only be submitted once the order has been delivered.']);
    exit;
}

$driver_id = (int)($order_data['driver_id'] ?? 0);

// Ensure delivery_reviews table exists
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `delivery_reviews` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `rating` TINYINT(1) NOT NULL,
        `comment` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `user_order` (`user_id`, `order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Check if review already exists
$existing_review = null;
$chk_stmt = $conn->prepare("SELECT id FROM delivery_reviews WHERE order_id = ? AND user_id = ? LIMIT 1");
if ($chk_stmt) {
    $chk_stmt->bind_param("ii", $order_id, $user_id);
    $chk_stmt->execute();
    $existing_review = $chk_stmt->get_result()->fetch_assoc();
    $chk_stmt->close();
}

if ($existing_review) {
    $upd_stmt = $conn->prepare("UPDATE delivery_reviews SET rating = ?, comment = ?, created_at = NOW() WHERE id = ?");
    if ($upd_stmt) {
        $rev_id = (int)$existing_review['id'];
        $upd_stmt->bind_param("isi", $rating, $comment, $rev_id);
        $upd_stmt->execute();
        $upd_stmt->close();
    }
} else {
    $ins_stmt = $conn->prepare("INSERT INTO delivery_reviews (order_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
    if ($ins_stmt) {
        $ins_stmt->bind_param("iiis", $order_id, $user_id, $rating, $comment);
        $ins_stmt->execute();
        $ins_stmt->close();
    }
}

$new_avg_rating = 5.0;
$total_ratings_count = 1;

// Recalculate rider average rating if a driver was assigned
if ($driver_id > 0) {
    $avg_stmt = $conn->prepare("
        SELECT AVG(dr.rating) AS avg_rating, COUNT(dr.id) AS total_count
        FROM delivery_reviews dr
        INNER JOIN logistics_tracking lt ON dr.order_id = lt.order_id
        WHERE lt.driver_id = ?
    ");
    if ($avg_stmt) {
        $avg_stmt->bind_param("i", $driver_id);
        $avg_stmt->execute();
        $avg_res = $avg_stmt->get_result();
        if ($avg_row = $avg_res->fetch_assoc()) {
            if (!empty($avg_row['avg_rating']) && is_numeric($avg_row['avg_rating'])) {
                $new_avg_rating = round((float)$avg_row['avg_rating'], 2);
                $total_ratings_count = (int)$avg_row['total_count'];
            }
        }
        $avg_stmt->close();
    }

    // Update riders table
    $upd_rider = $conn->prepare("UPDATE riders SET rating = ? WHERE id = ?");
    if ($upd_rider) {
        $upd_rider->bind_param("di", $new_avg_rating, $driver_id);
        $upd_rider->execute();
        $upd_rider->close();
    }

    // Also update driver_delivery_stats if present
    $upd_stats = $conn->prepare("UPDATE driver_delivery_stats SET avg_rating = ? WHERE driver_id = ?");
    if ($upd_stats) {
        $upd_stats->bind_param("di", $new_avg_rating, $driver_id);
        $upd_stats->execute();
        $upd_stats->close();
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Thank you! Your rider rating has been recorded successfully.',
    'rating' => $rating,
    'comment' => $comment,
    'driver_id' => $driver_id,
    'new_driver_rating' => number_format((float)$new_avg_rating, 1),
    'total_ratings_count' => $total_ratings_count
]);
exit;
