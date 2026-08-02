<?php
/**
 * Chat API: Close Conversation
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';
require_once '../includes/security.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. POST required.");
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conversation_id = $data['conversation_id'] ?? null;
    $rating = $data['rating'] ?? null;
    $feedback = $data['feedback'] ?? null;
    
    if (!$conversation_id) {
        throw new Exception("conversation_id is required");
    }
    
    $chatService = new ChatService($conn);
    
    if (!$chatService->closeConversation($conversation_id, $rating, $feedback)) {
        throw new Exception($chatService->getLastError());
    }
    
    // Send system message
    $message_text = "Conversation closed";
    if ($rating) {
        $message_text .= " - Rating: $rating/5";
    }
    if ($feedback) {
        $message_text .= " - Feedback: $feedback";
    }
    
    $session_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));
    $user_type = in_array($session_user_type, ['admin', 'employee'], true) ? 'agent' : 'customer';
    $chatService->sendMessage($conversation_id, $user_id, $user_type, $message_text, 'system');
    
    echo json_encode([
        'success' => true,
        'message' => 'Conversation closed successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
