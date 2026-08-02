<?php
session_start();
require_once 'includes/config.php';
require_once 'logistics_service.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$user_id = $_SESSION['user_id'];

if ($order_id === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID']);
    exit;
}

// Verify user owns the order
$verify_stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
$verify_stmt->bind_param("ii", $order_id, $user_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied to this order.']);
    exit;
}
$verify_stmt->close();

// Fetch tracking info
$logisticsService = new LogisticsService($conn);
$tracking_info = $logisticsService->getTrackingByOrderId($order_id);

// Check if a delivery review has already been submitted for this order
$delivery_review_exists = false;
$review_check_stmt = $conn->prepare("SELECT id FROM delivery_reviews WHERE order_id = ? LIMIT 1");
$review_check_stmt->bind_param("i", $order_id);
$review_check_stmt->execute();
if ($review_check_stmt->get_result()->num_rows > 0) {
    $delivery_review_exists = true;
}
$review_check_stmt->close();

if ($tracking_info) {
    $driver_latitude = null;
    $driver_longitude = null;
    $location_updated_at = $tracking_info['last_location_update'] ?? null;
    $location_source = 'logistics_tracking';

    if (isset($tracking_info['current_latitude']) && is_numeric($tracking_info['current_latitude'])) {
        $driver_latitude = (float)$tracking_info['current_latitude'];
    }
    if (isset($tracking_info['current_longitude']) && is_numeric($tracking_info['current_longitude'])) {
        $driver_longitude = (float)$tracking_info['current_longitude'];
    }

    // Fallback: if logistics row has no fresh coordinates, pull latest from employee geo tracker.
    if (($driver_latitude === null || $driver_longitude === null) && !empty($tracking_info['driver_id'])) {
        $geo_stmt = $conn->prepare(
            "SELECT current_latitude, current_longitude, last_update
             FROM employees_geo_tracking
             WHERE employee_id = ?
             LIMIT 1"
        );
        if ($geo_stmt) {
            $driver_id = (int)$tracking_info['driver_id'];
            $geo_stmt->bind_param("i", $driver_id);
            $geo_stmt->execute();
            $geo_row = $geo_stmt->get_result()->fetch_assoc();
            $geo_stmt->close();

            if ($geo_row) {
                if (isset($geo_row['current_latitude']) && is_numeric($geo_row['current_latitude'])) {
                    $driver_latitude = (float)$geo_row['current_latitude'];
                }
                if (isset($geo_row['current_longitude']) && is_numeric($geo_row['current_longitude'])) {
                    $driver_longitude = (float)$geo_row['current_longitude'];
                }
                if (!empty($geo_row['last_update'])) {
                    $location_updated_at = $geo_row['last_update'];
                    $location_source = 'employees_geo_tracking';
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $tracking_info['current_status'],
        'driver_name' => $tracking_info['driver_name'],
        'driver_phone' => $tracking_info['driver_phone'] ?? null,
        'driver_id' => intval($tracking_info['driver_id'] ?? 0),
        'tracking_id' => intval($tracking_info['id'] ?? 0),
        'latitude' => $driver_latitude,
        'longitude' => $driver_longitude,
        'last_location_update' => $location_updated_at,
        'location_source' => $location_source,
        'estimated_delivery' => $tracking_info['estimated_delivery'] ?? null,
        'status_timestamp' => $tracking_info['status_timestamp'] ?? null,
        'proof_path' => $tracking_info['proof_of_delivery_path'] ?? null,
        'delivery_review_exists' => $delivery_review_exists
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Tracking information not yet available for this order.']);
}

mysqli_close($conn);
?>
