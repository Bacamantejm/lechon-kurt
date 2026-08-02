<?php
/**
 * Chat API: Get Conversations
 */

header('Content-Type: application/json');
ini_set('display_errors', '0');
session_start();
ob_start();

$chat_api_response_sent = false;

if (!function_exists('chatApiEncodeJson')) {
    function chatApiEncodeJson(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($json === false) {
            return '{"success":false,"error":"Unable to encode JSON response."}';
        }
        return $json;
    }
}

if (!function_exists('chatApiRespondJson')) {
    function chatApiRespondJson(int $statusCode, array $payload): void
    {
        global $chat_api_response_sent;
        $chat_api_response_sent = true;

        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo chatApiEncodeJson($payload);
        exit;
    }
}

register_shutdown_function(function () {
    global $chat_api_response_sent;
    if ($chat_api_response_sent) {
        return;
    }

    $last_error = error_get_last();
    if (!$last_error || !is_array($last_error)) {
        return;
    }

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)($last_error['type'] ?? 0), $fatal_types, true)) {
        return;
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    $message = trim((string)($last_error['message'] ?? 'Internal server error.'));
    if ($message === '') {
        $message = 'Internal server error.';
    } else {
        $message = preg_replace('/\s+/', ' ', $message);
    }

    echo chatApiEncodeJson([
        'success' => false,
        'error' => 'Fatal error: ' . $message
    ]);
});

require_once '../includes/config.php';
require_once '../includes/ChatService.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    chatApiRespondJson(401, ['success' => false, 'error' => 'Unauthorized']);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_type_session = $_SESSION['user_type'] ?? 'customer';
$user_type = strtolower(trim((string)$user_type_session));
if ($user_id <= 0) {
    chatApiRespondJson(401, ['success' => false, 'error' => 'Unauthorized']);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method. GET required.");
    }
    
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $chatService = new ChatService($conn);
    $partner_owner_id = $chatService->getApprovedPartnerScopeOwnerId($user_id);
    $is_platform_user = in_array($user_type, ['admin', 'employee'], true) && $partner_owner_id <= 0;
    
    if ($partner_owner_id > 0) {
        $conversations = $chatService->getPartnerConversations($partner_owner_id, $status, $limit, $offset, $search);
    } elseif ($is_platform_user) {
        // Get platform conversations
        $conversations = $chatService->getAgentConversations($user_id, $status, $limit, $offset, $search);
    } else {
        // Get customer's conversations
        $conversations = $chatService->getCustomerConversations($user_id, $limit, $offset);
    }
    
    if ($conversations === false) {
        throw new Exception($chatService->getLastError());
    }
    
    // Determine support online status
    $support_status = 'offline';
    $hour = (int)date('G');
    
    // Online between 8 AM and 8 PM
    if ($hour >= 8 && $hour < 20) {
        $support_status = 'online';
    } else {
        // Or if agents have been active recently (last 30 mins)
        $check_query = "SELECT 1 FROM chat_messages WHERE sender_type != 'customer' AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1";
        $check_res = $conn->query($check_query);
        if ($check_res && $check_res->num_rows > 0) {
            $support_status = 'online';
        }
    }
    
    chatApiRespondJson(200, [
        'success' => true,
        'conversations' => $conversations,
        'count' => count($conversations),
        'support_status' => $support_status,
        'viewer_role' => $partner_owner_id > 0 ? 'store' : ($is_platform_user ? 'platform' : 'customer')
    ]);
    
} catch (Throwable $e) {
    chatApiRespondJson(500, [
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
