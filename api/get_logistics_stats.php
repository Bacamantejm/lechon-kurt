<?php
/**
 * API Endpoint: Get Logistics Statistics
 */
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';

checkAdminAccess();

header('Content-Type: application/json');

try {
    // Total deliveries today
    $total_query = "SELECT COUNT(*) as count FROM logistics_tracking WHERE DATE(created_at) = CURDATE()";
    $total_result = mysqli_query($conn, $total_query);
    $total_data = mysqli_fetch_assoc($total_result);
    $total_deliveries = $total_data['count'] ?? 0;
    
    // Active deliveries
    $active_query = "SELECT COUNT(*) as count FROM logistics_tracking 
                    WHERE current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
                    AND DATE(created_at) = CURDATE()";
    $active_result = mysqli_query($conn, $active_query);
    $active_data = mysqli_fetch_assoc($active_result);
    $active_deliveries = $active_data['count'] ?? 0;
    
    // Completed deliveries
    $completed_query = "SELECT COUNT(*) as count FROM logistics_tracking 
                       WHERE current_status = 'delivered' AND DATE(created_at) = CURDATE()";
    $completed_result = mysqli_query($conn, $completed_query);
    $completed_data = mysqli_fetch_assoc($completed_result);
    $completed_deliveries = $completed_data['count'] ?? 0;
    
    // Failed deliveries
    $failed_query = "SELECT COUNT(*) as count FROM logistics_tracking 
                    WHERE current_status = 'failed' AND DATE(created_at) = CURDATE()";
    $failed_result = mysqli_query($conn, $failed_query);
    $failed_data = mysqli_fetch_assoc($failed_result);
    $failed_deliveries = $failed_data['count'] ?? 0;
    
    // Average rating
    $rating_query = "SELECT AVG(rating) as avg_rating FROM delivery_ratings WHERE DATE(created_at) = CURDATE()";
    $rating_result = mysqli_query($conn, $rating_query);
    $rating_data = mysqli_fetch_assoc($rating_result);
    $average_rating = floatval($rating_data['avg_rating'] ?? 4.5);
    
    // Success rate
    $success = $total_deliveries > 0 ? ($completed_deliveries / $total_deliveries) * 100 : 0;
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_deliveries' => $total_deliveries,
            'active_deliveries' => $active_deliveries,
            'completed_deliveries' => $completed_deliveries,
            'failed_deliveries' => $failed_deliveries,
            'average_rating' => $average_rating,
            'success_rate' => $success
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
