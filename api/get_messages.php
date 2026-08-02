<?php
/**
 * Chat API: Get Messages (Polling Endpoint)
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method. GET required.");
    }
    
    $conversation_id = $_GET['conversation_id'] ?? null;
    $limit = (int)($_GET['limit'] ?? 50);
    $limit = max(1, min(200, $limit));
    $offset = (int)($_GET['offset'] ?? 0);
    $offset = max(0, $offset);
    $since = $_GET['since'] ?? null; // ISO 8601 timestamp
    $after_id = isset($_GET['after_id']) ? (int)$_GET['after_id'] : null;
    $after_id = ($after_id !== null) ? max(0, $after_id) : null;
    $wait_for_new = isset($_GET['wait']) ? ((int)$_GET['wait'] === 1) : false;
    $timeout_seconds = isset($_GET['timeout']) ? (int)$_GET['timeout'] : 12;
    $timeout_seconds = max(3, min(25, $timeout_seconds));
    
    if (!$conversation_id) {
        throw new Exception("conversation_id is required");
    }
    
    $chatService = new ChatService($conn);

    if (!$chatService->hasConversationAccess($conversation_id, $user_id)) {
        throw new Exception("Access denied to this conversation");
    }

    // Optional long-poll wait: hold request briefly until new message arrives.
    if ($wait_for_new && $after_id !== null && $after_id >= 0) {
        $deadline = microtime(true) + $timeout_seconds;
        while (microtime(true) < $deadline) {
            $latest_id = $chatService->getLatestMessageId($conversation_id);
            if ($latest_id > $after_id) {
                break;
            }
            usleep(300000); // 300ms
        }
    }
    
    // Get messages
    $messages = $chatService->getMessages($conversation_id, $limit, $offset, $since, $after_id);
    
    if ($messages === false) {
        throw new Exception($chatService->getLastError());
    }
    
    // Get typing users in this conversation
    $typing_users = $chatService->getTypingUsers($conversation_id, $user_id);
    
    // Mark messages as read
    $chatService->markMessagesAsRead($conversation_id, $user_id);

    $latest_message_id = $chatService->getLatestMessageId($conversation_id);
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'typing_users' => $typing_users,
        'count' => count($messages),
        'latest_message_id' => $latest_message_id,
        'has_new' => !empty($messages),
        'server_time' => date('Y-m-d H:i:s'),
        'mode' => ($after_id !== null) ? 'incremental' : 'snapshot'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
