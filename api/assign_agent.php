<?php
/**
 * Chat API: Assign Agent to Conversation
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';
require_once '../includes/security.php';

// Check authentication - only admins/agents
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));
if (!in_array($user_type, ['admin', 'employee'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. POST required.");
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conversation_id = $data['conversation_id'] ?? null;
    $agent_id = $data['agent_id'] ?? null;
    
    if (!$conversation_id || !$agent_id) {
        throw new Exception("conversation_id and agent_id are required");
    }
    
    $chatService = new ChatService($conn);
    
    if (!$chatService->assignAgent($conversation_id, $agent_id)) {
        throw new Exception($chatService->getLastError());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Agent assigned successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
