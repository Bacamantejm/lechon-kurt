<?php
/**
 * API Endpoint: Get Active Deliveries
 */
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';

checkAdminAccess();

header('Content-Type: application/json');

try {
    $query = "SELECT 
        lt.id,
        lt.order_id,
        o.order_number,
        o.customer_name,
        o.delivery_address,
        o.total_amount,
        lt.driver_id,
        lt.driver_name,
        lt.current_status,
        lt.created_at
    FROM logistics_tracking lt
    JOIN orders o ON lt.order_id = o.id
    WHERE lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
    OR (lt.current_status = 'pending' AND TIMEDIFF(NOW(), lt.created_at) < '01:00:00')
    ORDER BY lt.created_at DESC
    LIMIT 50";
    
    $result = mysqli_query($conn, $query);
    if (!$result) {
        throw new Exception(mysqli_error($conn));
    }
    
    $deliveries = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $deliveries[] = $row;
    }
    
    echo json_encode($deliveries);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
