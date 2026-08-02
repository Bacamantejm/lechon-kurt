<?php
/**
 * Chat API: Create Refund Request
 * Submits a refund request through chat conversation
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';
require_once '../includes/security.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. POST required.");
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conversation_id = $data['conversation_id'] ?? null;
    $order_id = $data['order_id'] ?? null;
    $reason = $data['reason'] ?? null;
    $screenshots = $data['screenshots'] ?? null;
    
    if (!$conversation_id || !$order_id || !$reason) {
        throw new Exception("conversation_id, order_id, and reason are required");
    }
    
    $user_id = $_SESSION['user_id'];
    
    $chatService = new ChatService($conn);
    
    // Create refund request
    $result = $chatService->createRefundRequestFromChat(
        $conversation_id,
        $order_id,
        $user_id,
        $reason,
        $screenshots
    );
    
    if (!$result) {
        throw new Exception($chatService->getLastError());
    }
    
    echo json_encode([
        'success' => true,
        'refund_request' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
