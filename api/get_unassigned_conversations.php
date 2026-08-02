<?php
/**
 * Chat API: Get Unassigned Conversations (Admin)
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';

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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method. GET required.");
    }
    
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    $search = $_GET['search'] ?? null;
    
    $chatService = new ChatService($conn);
    
    $conversations = $chatService->getUnassignedConversations($limit, $offset, $search);
    
    if ($conversations === false) {
        throw new Exception($chatService->getLastError());
    }
    
    echo json_encode([
        'success' => true,
        'conversations' => $conversations,
        'count' => count($conversations)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
