<?php
/**
 * Chat API: Set Typing Status
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';

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
    $is_typing = (bool)($data['is_typing'] ?? true);
    
    if (!$conversation_id) {
        throw new Exception("conversation_id is required");
    }
    
    $chatService = new ChatService($conn);
    
    // Set typing status
    if (!$chatService->setTypingStatus($conversation_id, $user_id, $is_typing)) {
        throw new Exception($chatService->getLastError());
    }
    
    // Get current typing users
    $typing_users = $chatService->getTypingUsers($conversation_id, $user_id);
    
    echo json_encode([
        'success' => true,
        'is_typing' => $is_typing,
        'typing_users' => $typing_users
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
