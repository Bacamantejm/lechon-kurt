<?php
/**
 * Chat API: Escalate Conversation
 * Marks a conversation as escalated and notifies admins
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. POST required.");
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conversation_id = $data['conversation_id'] ?? null;
    $reason = $data['reason'] ?? null;
    
    if (!$conversation_id) {
        throw new Exception("conversation_id is required");
    }
    
    $chatService = new ChatService($conn);
    
    // Escalate conversation
    $result = $chatService->escalateConversation(
        $conversation_id,
        $reason,
        $_SESSION['user_id']
    );
    
    if (!$result) {
        throw new Exception($chatService->getLastError());
    }
    
    // Send system message
    $message = "⚠️ Conversation escalated" . ($reason ? ": $reason" : "");
    $chatService->sendMessage($conversation_id, $_SESSION['user_id'], 'system', $message);
    
    echo json_encode([
        'success' => true,
        'message' => 'Conversation escalated and admins have been notified'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
