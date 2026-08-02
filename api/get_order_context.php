<?php
/**
 * Chat API: Get Order Context
 * Returns order details for display in chat interface
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

$user_id = (int)$_SESSION['user_id'];

try {
    $order_id = $_GET['order_id'] ?? null;
    $conversation_id = $_GET['conversation_id'] ?? null;
    
    if (!$order_id && !$conversation_id) {
        throw new Exception("order_id or conversation_id is required");
    }
    
    $chatService = new ChatService($conn);
    
    // If conversation_id provided, get order from conversation
    if ($conversation_id && !$order_id) {
        if (!$chatService->hasConversationAccess($conversation_id, $user_id)) {
            throw new Exception("Access denied to this conversation");
        }
        $conv_query = "SELECT order_id FROM chat_conversations WHERE id = ?";
        $stmt = $conn->prepare($conv_query);
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc();
        
        if (!$conv || !$conv['order_id']) {
            echo json_encode(['success' => false, 'error' => 'No order linked']);
            exit;
        }
        $order_id = $conv['order_id'];
    }
    
    // Get order context
    $order = $chatService->getOrderContext($order_id);
    
    if (!$order) {
        throw new Exception("Order not found or access denied");
    }
    
    echo json_encode([
        'success' => true,
        'order' => $order
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
