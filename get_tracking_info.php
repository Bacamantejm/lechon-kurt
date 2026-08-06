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

    // Fallback 1: if logistics row has no fresh coordinates, pull latest from employee geo tracker.
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

    // Fallback 2: if rider has no coordinates yet, use the order's branch/store location as pickup origin
    if ($driver_latitude === null || $driver_longitude === null) {
        $store_stmt = $conn->prepare(
            "SELECT sl.latitude, sl.longitude 
             FROM orders o 
             LEFT JOIN store_locations sl ON (sl.store_id = o.pickup_location OR sl.id = o.pickup_location)
             WHERE o.id = ? AND sl.latitude IS NOT NULL AND sl.longitude IS NOT NULL 
             LIMIT 1"
        );
        if ($store_stmt) {
            $store_stmt->bind_param("i", $order_id);
            $store_stmt->execute();
            $store_row = $store_stmt->get_result()->fetch_assoc();
            $store_stmt->close();

            if ($store_row && is_numeric($store_row['latitude']) && is_numeric($store_row['longitude'])) {
                $driver_latitude = (float)$store_row['latitude'];
                $driver_longitude = (float)$store_row['longitude'];
                if (!$location_updated_at) {
                    $location_updated_at = date('Y-m-d H:i:s');
                }
                $location_source = 'store_origin';
            }
        }

        if (($driver_latitude === null || $driver_longitude === null) && !empty($tracking_info['special_instructions'])) {
            if (preg_match('/fulfillment store:\s*([^|]+)/i', $tracking_info['special_instructions'], $matches)) {
                $store_name_query = trim($matches[1]);
                $inst_store_stmt = $conn->prepare("SELECT latitude, longitude FROM store_locations WHERE store_name LIKE ? AND latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1");
                if ($inst_store_stmt) {
                    $like_name = '%' . $store_name_query . '%';
                    $inst_store_stmt->bind_param("s", $like_name);
                    $inst_store_stmt->execute();
                    $inst_store_row = $inst_store_stmt->get_result()->fetch_assoc();
                    $inst_store_stmt->close();
                    if ($inst_store_row && is_numeric($inst_store_row['latitude']) && is_numeric($inst_store_row['longitude'])) {
                        $driver_latitude = (float)$inst_store_row['latitude'];
                        $driver_longitude = (float)$inst_store_row['longitude'];
                        if (!$location_updated_at) {
                            $location_updated_at = date('Y-m-d H:i:s');
                        }
                        $location_source = 'store_origin';
                    }
                }
            }
        }
    }

    // Fallback 3: if rider and order pickup location have no coordinates, use default active store location as origin
    if ($driver_latitude === null || $driver_longitude === null) {
        $default_store_res = $conn->query(
            "SELECT latitude, longitude 
             FROM store_locations 
             WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 
             ORDER BY store_id ASC 
             LIMIT 1"
        );
        if ($default_store_res && $default_store_row = $default_store_res->fetch_assoc()) {
            if (is_numeric($default_store_row['latitude']) && is_numeric($default_store_row['longitude'])) {
                $driver_latitude = (float)$default_store_row['latitude'];
                $driver_longitude = (float)$default_store_row['longitude'];
                if (!$location_updated_at) {
                    $location_updated_at = date('Y-m-d H:i:s');
                }
                $location_source = 'store_origin';
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
