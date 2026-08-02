<?php
/**
 * Chat API: Get Quick Responses
 * Returns quick response templates for agents
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
    // Only agents/admins can use quick responses
    if ($_SESSION['user_type'] !== 'admin' && $_SESSION['user_type'] !== 'employee') {
        http_response_code(403);
        throw new Exception("Only support staff can access quick responses");
    }
    
    $category = $_GET['category'] ?? null;
    
    $chatService = new ChatService($conn);
    $responses = $chatService->getQuickResponses($category, $_SESSION['user_id']);
    
    echo json_encode([
        'success' => true,
        'responses' => $responses
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
