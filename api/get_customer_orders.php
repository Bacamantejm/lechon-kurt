<?php
/**
 * Chat API: Get Customer Orders
 * Returns list of customer's orders for quick reference in chat
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $customer_id = $_GET['customer_id'] ?? $_SESSION['user_id'];
    $limit = (int)($_GET['limit'] ?? 10);
    $session_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));
    $is_agent_user = in_array($session_user_type, ['admin', 'employee'], true);
    
    // Validation: customers can only see their own orders, agents can see any
    if (!$is_agent_user && $customer_id != $_SESSION['user_id']) {
        throw new Exception("Unauthorized to view other customer's orders");
    }
    
    $chatService = new ChatService($conn);
    $orders = $chatService->getCustomerOrders($customer_id, $limit);
    
    if ($orders === false) {
        throw new Exception($chatService->getLastError());
    }
    
    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
