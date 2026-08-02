<?php
/**
 * Chat API: Get Refund Context
 * Returns refund details for display in chat interface
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
    $refund_id = $_GET['refund_id'] ?? null;
    $conversation_id = $_GET['conversation_id'] ?? null;
    
    if (!$refund_id && !$conversation_id) {
        throw new Exception("refund_id or conversation_id is required");
    }
    
    $chatService = new ChatService($conn);
    
    // If conversation_id provided, get refund from conversation
    if ($conversation_id && !$refund_id) {
        if (!$chatService->hasConversationAccess($conversation_id, $user_id)) {
            throw new Exception("Access denied to this conversation");
        }
        $conv_query = "SELECT refund_id FROM chat_conversations WHERE id = ?";
        $stmt = $conn->prepare($conv_query);
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc();
        
        if (!$conv || !$conv['refund_id']) {
            echo json_encode(['success' => false, 'error' => 'No refund linked']);
            exit;
        }
        $refund_id = $conv['refund_id'];
    }
    
    // Get refund context
    $refund = $chatService->getRefundContext($refund_id);
    
    if (!$refund) {
        throw new Exception("Refund not found or access denied");
    }
    
    echo json_encode([
        'success' => true,
        'refund' => $refund
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
