<?php
/**
 * Chat API: Link Conversation to Order
 * Associates a conversation with an order for context tracking
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. POST required.");
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conversation_id = $data['conversation_id'] ?? null;
    $order_id = $data['order_id'] ?? null;
    
    if (!$conversation_id || !$order_id) {
        throw new Exception("conversation_id and order_id are required");
    }
    
    $chatService = new ChatService($conn);

    if (!$chatService->hasConversationAccess($conversation_id, $user_id)) {
        throw new Exception("Access denied to this conversation");
    }
    
    // Link conversation to order
    $result = $chatService->linkConversationToOrder($conversation_id, $order_id);
    
    if (!$result) {
        throw new Exception($chatService->getLastError());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Conversation linked to order'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
