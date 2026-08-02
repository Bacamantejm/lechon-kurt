<?php
/**
 * Chat API: Get or Create Conversation
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
$user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $chatService = new ChatService($conn);
        $partner_owner_id = $chatService->getApprovedPartnerScopeOwnerId($user_id);
        $is_platform_user = in_array($user_type, ['admin', 'employee'], true) && $partner_owner_id <= 0;
        $is_customer_user = !$is_platform_user && $partner_owner_id <= 0;
        $default_channel = $is_customer_user ? 'customer_platform' : ($partner_owner_id > 0 ? 'store_platform' : 'customer_platform');
        $channel = trim((string)($data['channel'] ?? $default_channel));
        
        // For customers: get their own conversation
        // Platform staff may create conversations for a selected customer.
        if ($is_customer_user) {
            $customer_id = $user_id;
        } elseif ($partner_owner_id > 0) {
            $customer_id = (int)($data['customer_id'] ?? 0);
        } else {
            $customer_id = $data['customer_id'] ?? null;
            if (!$customer_id && $channel !== 'store_platform') {
                throw new Exception("customer_id is required");
            }
        }
        
        $subject = $data['subject'] ?? null;
        $options = [
            'conversation_channel' => $channel,
            'seller_id' => (int)($data['seller_id'] ?? 0),
            'platform_owner_id' => (int)($data['platform_owner_id'] ?? 0),
            'rider_user_id' => (int)($data['rider_user_id'] ?? 0),
            'order_id' => (int)($data['order_id'] ?? 0),
            'refund_id' => (int)($data['refund_id'] ?? 0),
            'entity_type' => trim((string)($data['entity_type'] ?? 'general')),
            'conversation_type' => trim((string)($data['conversation_type'] ?? 'support')),
        ];

        if ($channel === 'store_platform' && $partner_owner_id > 0) {
            $conversation = $chatService->getOrCreateStorePlatformConversation($partner_owner_id, $subject);
        } elseif ($channel === 'store_platform' && (int)$options['seller_id'] > 0) {
            $conversation = $chatService->getOrCreateStorePlatformConversation((int)$options['seller_id'], $subject);
        } elseif ($channel === 'delivery' && $customer_id > 0 && (int)$options['order_id'] > 0) {
            $conversation = $chatService->getOrCreateDeliveryConversation((int)$options['order_id'], (int)$customer_id);
        } else {
            $conversation = $chatService->getOrCreateConversation($customer_id, $subject, $options);
        }
        
        if (!$conversation) {
            throw new Exception($chatService->getLastError());
        }
        
        echo json_encode([
            'success' => true,
            'conversation' => $conversation
        ]);
        
    } else {
        throw new Exception("Invalid request method");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
