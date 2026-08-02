<?php
/**
 * Chat API: Send Message
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';
require_once '../includes/ChatAutomationService.php';
require_once '../includes/security.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. POST required.");
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $conversation_id = $data['conversation_id'] ?? null;
    $message_text = $data['message'] ?? null;
    
    if (!$conversation_id || !$message_text) {
        throw new Exception("conversation_id and message are required");
    }
    
    $chatService = new ChatService($conn);
    $sender_type = $chatService->resolveSenderTypeForConversation($conversation_id, $user_id, $user_type);
    
    // Send message
    $message = $chatService->sendMessage($conversation_id, $user_id, $sender_type, $message_text, 'text');
    
    if (!$message) {
        throw new Exception($chatService->getLastError());
    }
    
    // Mark as read for sender
    $chatService->markMessagesAsRead($conversation_id, $user_id);
    
    // Clear typing indicator
    $chatService->setTypingStatus($conversation_id, $user_id, false);

    // AI-style automation for customer messages: auto-route, auto-status, auto-escalation.
    $automation_result = null;
    if ($sender_type === 'customer') {
        $automationService = new ChatAutomationService($conn, $chatService);
        $automation_result = $automationService->handleIncomingCustomerMessage($conversation_id, $user_id, $message_text);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'automation' => $automation_result
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
