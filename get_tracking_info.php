<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/config.php';
require_once 'logistics_service.php';

if (ob_get_length()) {
    ob_clean();
}
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
if ($review_check_stmt) {
    $review_check_stmt->bind_param("i", $order_id);
    $review_check_stmt->execute();
    $res_rev = $review_check_stmt->get_result();
    if ($res_rev && $res_rev->num_rows > 0) {
        $delivery_review_exists = true;
    }
    $review_check_stmt->close();
}

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

    // Ensure driver_id is retrieved from orders.rider_id if logistics_tracking has not recorded it yet
    if (empty($tracking_info['driver_id'])) {
        $ord_r_chk = $conn->prepare("SELECT rider_id FROM orders WHERE id = ? LIMIT 1");
        if ($ord_r_chk) {
            $ord_r_chk->bind_param("i", $order_id);
            $ord_r_chk->execute();
            $ord_r_res = $ord_r_chk->get_result()->fetch_assoc();
            $ord_r_chk->close();
            if (!empty($ord_r_res['rider_id'])) {
                $tracking_info['driver_id'] = (int)$ord_r_res['rider_id'];
            }
        }
    }

    // Check latest coordinates from riders and employees_geo_tracking tables
    if (!empty($tracking_info['driver_id'])) {
        $driver_id = (int)$tracking_info['driver_id'];

        $rider_geo_stmt = $conn->prepare("SELECT current_latitude, current_longitude, last_location_update FROM riders WHERE id = ? OR employee_id = ? OR user_id = ? ORDER BY last_location_update DESC LIMIT 1");
        if ($rider_geo_stmt) {
            $rider_geo_stmt->bind_param("iii", $driver_id, $driver_id, $driver_id);
            $rider_geo_stmt->execute();
            $r_geo_row = $rider_geo_stmt->get_result()->fetch_assoc();
            $rider_geo_stmt->close();
            if ($r_geo_row && isset($r_geo_row['current_latitude']) && is_numeric($r_geo_row['current_latitude']) && (float)$r_geo_row['current_latitude'] != 0) {
                $r_time = !empty($r_geo_row['last_location_update']) ? strtotime($r_geo_row['last_location_update']) : 0;
                $lt_time = !empty($location_updated_at) ? strtotime($location_updated_at) : 0;
                if ($driver_latitude === null || $r_time >= $lt_time) {
                    $driver_latitude = (float)$r_geo_row['current_latitude'];
                    $driver_longitude = (float)$r_geo_row['current_longitude'];
                    $location_updated_at = $r_geo_row['last_location_update'] ?? $location_updated_at;
                    $location_source = 'riders';
                }
            }
        }

        $geo_stmt = $conn->prepare(
            "SELECT current_latitude, current_longitude, last_update
             FROM employees_geo_tracking
             WHERE employee_id = ? OR employee_id IN (SELECT employee_id FROM riders WHERE id = ? OR user_id = ?)
             ORDER BY last_update DESC LIMIT 1"
        );
        if ($geo_stmt) {
            $geo_stmt->bind_param("iii", $driver_id, $driver_id, $driver_id);
            $geo_stmt->execute();
            $geo_row = $geo_stmt->get_result()->fetch_assoc();
            $geo_stmt->close();

            if ($geo_row && isset($geo_row['current_latitude']) && is_numeric($geo_row['current_latitude']) && (float)$geo_row['current_latitude'] != 0) {
                $eg_time = !empty($geo_row['last_update']) ? strtotime($geo_row['last_update']) : 0;
                $cur_time = !empty($location_updated_at) ? strtotime($location_updated_at) : 0;
                if ($driver_latitude === null || $eg_time >= $cur_time) {
                    $driver_latitude = (float)$geo_row['current_latitude'];
                    $driver_longitude = (float)$geo_row['current_longitude'];
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
             LEFT JOIN store_locations sl ON sl.store_id = o.pickup_location
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

    $has_driver = !empty($tracking_info['driver_id']) && in_array($tracking_info['current_status'], ['assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving', 'delivered'], true);

    if (!$has_driver) {
        $driver_latitude = null;
        $driver_longitude = null;
    }

        // Fetch store and customer coordinates for real-time distance calculations
    $store_info = null;
    $sq_stmt = $conn->prepare("
        SELECT sl.store_name, sl.latitude, sl.longitude, sl.address, sl.phone
        FROM orders o 
        LEFT JOIN store_locations sl ON (sl.store_id = o.pickup_location OR sl.id = o.pickup_location)
        WHERE o.id = ? LIMIT 1
    ");
    if ($sq_stmt) {
        $sq_stmt->bind_param("i", $order_id);
        $sq_stmt->execute();
        $store_info = $sq_stmt->get_result()->fetch_assoc();
        $sq_stmt->close();
    }
    if (!$store_info || empty($store_info['latitude'])) {
        $def_s = $conn->query("SELECT store_name, latitude, longitude, address, phone FROM store_locations WHERE is_active = 1 AND latitude IS NOT NULL LIMIT 1");
        if ($def_s) {
            $store_info = $def_s->fetch_assoc();
        }
    }

    $cust_lat = null;
    $cust_lng = null;
    $cust_addr = '';
    $cq_stmt = $conn->prepare("SELECT latitude, longitude, delivery_address FROM orders WHERE id = ? LIMIT 1");
    if ($cq_stmt) {
        $cq_stmt->bind_param("i", $order_id);
        $cq_stmt->execute();
        $cq_res = $cq_stmt->get_result()->fetch_assoc();
        $cq_stmt->close();
        if ($cq_res) {
            $cust_lat = (isset($cq_res['latitude']) && is_numeric($cq_res['latitude'])) ? (float)$cq_res['latitude'] : null;
            $cust_lng = (isset($cq_res['longitude']) && is_numeric($cq_res['longitude'])) ? (float)$cq_res['longitude'] : null;
            $cust_addr = (string)($cq_res['delivery_address'] ?? '');
        }
    }

    $dist_to_store_km = null;
    $dist_to_store_formatted = null;
    $dist_to_cust_km = null;
    $dist_to_cust_formatted = null;

    if ($driver_latitude !== null && $driver_longitude !== null) {
        $earth_radius = 6371.0;

        // Calculate distance from rider to store
        if ($store_info && is_numeric($store_info['latitude']) && is_numeric($store_info['longitude'])) {
            $s_lat = (float)$store_info['latitude'];
            $s_lng = (float)$store_info['longitude'];
            $dLat = deg2rad($s_lat - $driver_latitude);
            $dLon = deg2rad($s_lng - $driver_longitude);
            $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($driver_latitude)) * cos(deg2rad($s_lat)) * sin($dLon / 2) * sin($dLon / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $s_road_km = ($earth_radius * $c) * 1.25;
            $dist_to_store_km = round($s_road_km, 2);
            $dist_to_store_formatted = ($s_road_km < 0.95) ? round($s_road_km * 1000) . ' m' : number_format($s_road_km, 1) . ' km';
        }

        // Calculate distance from rider to customer
        if ($cust_lat !== null && $cust_lng !== null) {
            $dLat = deg2rad($cust_lat - $driver_latitude);
            $dLon = deg2rad($cust_lng - $driver_longitude);
            $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($driver_latitude)) * cos(deg2rad($cust_lat)) * sin($dLon / 2) * sin($dLon / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $c_road_km = ($earth_radius * $c) * 1.25;
            $dist_to_cust_km = round($c_road_km, 2);
            $dist_to_cust_formatted = ($c_road_km < 0.95) ? round($c_road_km * 1000) . ' m' : number_format($c_road_km, 1) . ' km';
        }
    }

    $proof_path = $tracking_info['proof_of_delivery_path'] ?? null;
    if (empty($proof_path)) {
        $pod_chk = $conn->prepare("SELECT photo_path FROM proof_of_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        if ($pod_chk) {
            $pod_chk->bind_param("i", $order_id);
            $pod_chk->execute();
            $pod_res = $pod_chk->get_result();
            if ($pod_res && $pod_row = $pod_res->fetch_assoc()) {
                $proof_path = $pod_row['photo_path'];
            }
            $pod_chk->close();
        }
    }
    if (!empty($proof_path)) {
        $proof_path = 'uploads/proof_of_delivery/' . basename($proof_path);
    }

    echo json_encode([
        'success' => true,
        'is_pickup' => false,
        'status' => $has_driver ? $tracking_info['current_status'] : 'pending',
        'waiting_for_rider' => !$has_driver,
        'driver_name' => $has_driver ? ($tracking_info['driver_name'] ?: 'Assigned Rider') : null,
        'driver_phone' => $has_driver ? ($tracking_info['driver_phone'] ?? null) : null,
        'driver_vehicle' => $has_driver ? ($tracking_info['driver_vehicle'] ?? 'Motorcycle') : null,
        'driver_rating' => $has_driver ? (isset($tracking_info['driver_rating']) && is_numeric($tracking_info['driver_rating']) ? number_format((float)$tracking_info['driver_rating'], 1) : '5.0') : '5.0',
        'driver_id' => $has_driver ? intval($tracking_info['driver_id'] ?? 0) : 0,
        'tracking_id' => intval($tracking_info['id'] ?? 0),
        'latitude' => $driver_latitude,
        'longitude' => $driver_longitude,
        'last_location_update' => $location_updated_at,
        'location_source' => $location_source,
        'estimated_delivery' => $tracking_info['estimated_delivery'] ?? null,
        'status_timestamp' => $tracking_info['status_timestamp'] ?? null,
        'proof_path' => $proof_path,
        'distance_to_store_km' => $dist_to_store_km,
        'distance_to_store_formatted' => $dist_to_store_formatted,
        'distance_to_customer_km' => $dist_to_cust_km,
        'distance_to_customer_formatted' => $dist_to_cust_formatted,
        'store_latitude' => $store_info ? (float)$store_info['latitude'] : null,
        'store_longitude' => $store_info ? (float)$store_info['longitude'] : null,
        'store_name' => $store_info['store_name'] ?? 'Store',
        'customer_latitude' => $cust_lat,
        'customer_longitude' => $cust_lng,
        'customer_address' => $cust_addr,
        'delivery_review_exists' => $delivery_review_exists
    ]);
} else {
    // Check if it's a pick-up order or pending order without logistics driver
    $ord_stmt = $conn->prepare("SELECT o.status, o.delivery_option, o.pickup_location, o.delivery_date, o.delivery_time, o.estimated_delivery_time, o.created_at, sl.latitude, sl.longitude, sl.store_name, sl.address, sl.phone FROM orders o LEFT JOIN store_locations sl ON (sl.store_id = o.pickup_location OR sl.id = o.pickup_location) WHERE o.id = ? LIMIT 1");
    $ord_stmt->bind_param("i", $order_id);
    $ord_stmt->execute();
    $ord_data = $ord_stmt->get_result()->fetch_assoc();
    $ord_stmt->close();

    if ($ord_data) {
        $is_pickup = ($ord_data['delivery_option'] === 'pickup');
        $store_lat = (isset($ord_data['latitude']) && is_numeric($ord_data['latitude'])) ? (float)$ord_data['latitude'] : null;
        $store_lng = (isset($ord_data['longitude']) && is_numeric($ord_data['longitude'])) ? (float)$ord_data['longitude'] : null;

        // If store has no coords, fallback to first active store
        if ($store_lat === null || $store_lng === null) {
            $def_s = $conn->query("SELECT latitude, longitude FROM store_locations WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1");
            if ($def_s && $def_row = $def_s->fetch_assoc()) {
                $store_lat = (float)$def_row['latitude'];
                $store_lng = (float)$def_row['longitude'];
            }
        }

        echo json_encode([
            'success' => true,
            'is_pickup' => $is_pickup,
            'status' => $ord_data['status'] ?? 'pending',
            'delivery_option' => $ord_data['delivery_option'],
            'driver_name' => null,
            'driver_phone' => null,
            'driver_id' => 0,
            'tracking_id' => 0,
            'latitude' => $store_lat,
            'longitude' => $store_lng,
            'store_name' => $ord_data['store_name'] ?? 'Lechon Delights Store',
            'store_address' => $ord_data['address'] ?? 'Cavite, Philippines',
            'store_phone' => $ord_data['phone'] ?? null,
            'last_location_update' => date('Y-m-d H:i:s'),
            'location_source' => 'store_pickup',
            'estimated_delivery' => $ord_data['estimated_delivery_time'] ?? null,
            'delivery_date' => $ord_data['delivery_date'],
            'delivery_time' => $ord_data['delivery_time'],
            'status_timestamp' => $ord_data['created_at'],
            'proof_path' => null,
            'delivery_review_exists' => $delivery_review_exists
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Tracking information not yet available for this order.']);
    }
}

mysqli_close($conn);
?>
