<?php
/**
 * Delivery Chat API
 * Centralized customer-rider chat endpoint backed by the main chat service.
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';

function jsonErrorResponse($code, $message) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

function getDeliveryChatContext(mysqli $conn, int $order_id): ?array {
    $query = "SELECT o.id AS order_id,
                     o.order_number,
                     o.user_id AS customer_user_id,
                     lt.id AS tracking_id,
                     lt.current_status,
                     lt.driver_phone,
                     e.user_id AS driver_user_id,
                     cu.full_name AS customer_name,
                     du.full_name AS driver_name
              FROM orders o
              LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
              LEFT JOIN employees e ON e.id = lt.driver_id
              LEFT JOIN users cu ON cu.id = o.user_id
              LEFT JOIN users du ON du.id = e.user_id
              WHERE o.id = ?
              ORDER BY lt.updated_at DESC, lt.id DESC
              LIMIT 1";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

if (!isset($_SESSION['user_id'])) {
    jsonErrorResponse(401, 'Unauthorized');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$payload = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$order_id = intval(
    $payload['order_id']
    ?? $_POST['order_id']
    ?? $_GET['order_id']
    ?? 0
);
if ($order_id <= 0) {
    jsonErrorResponse(400, 'Invalid order_id');
}

$user_id = intval($_SESSION['user_id']);
$context = getDeliveryChatContext($conn, $order_id);
if (!$context) {
    jsonErrorResponse(404, 'Order not found.');
}

$customer_user_id = intval($context['customer_user_id'] ?? 0);
$driver_user_id = intval($context['driver_user_id'] ?? 0);
$chat_available = $customer_user_id > 0 && $driver_user_id > 0;

$can_customer_access = $customer_user_id > 0 && $customer_user_id === $user_id;
$can_driver_access = $driver_user_id > 0 && $driver_user_id === $user_id;
if (!$can_customer_access && !$can_driver_access) {
    jsonErrorResponse(403, 'You do not have access to this delivery chat.');
}

$sender_role = $can_driver_access ? 'driver' : 'customer';
$chatService = new ChatService($conn);
$conversation = null;
$conversation_id = 0;

if ($chat_available) {
    $conversation = $chatService->getOrCreateDeliveryConversation($order_id, $customer_user_id);
    if (!$conversation || empty($conversation['id'])) {
        jsonErrorResponse(500, $chatService->getLastError() ?: 'Unable to initialize delivery chat.');
    }
    $conversation_id = (int)$conversation['id'];
}

if ($method === 'GET') {
    $after_id = max(0, intval($_GET['after_id'] ?? 0));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 50)));

    $messages = [];
    if ($chat_available && $conversation_id > 0) {
        $messages = $chatService->getMessages($conversation_id, $limit, 0, null, $after_id > 0 ? $after_id : null);
        if ($messages === false) {
            jsonErrorResponse(500, $chatService->getLastError() ?: 'Unable to load messages.');
        }
        $chatService->markMessagesAsRead($conversation_id, $user_id);
        foreach ($messages as &$message) {
            $message['sender_role'] = (($message['sender_type'] ?? '') === 'rider') ? 'driver' : 'customer';
        }
        unset($message);
    }

    $last_message_id = $after_id;
    if (!empty($messages)) {
        $last = end($messages);
        $last_message_id = intval($last['id'] ?? $after_id);
    }

    echo json_encode([
        'success' => true,
        'role' => $sender_role,
        'chat_available' => $chat_available,
        'context' => [
            'order_id' => intval($context['order_id'] ?? 0),
            'order_number' => $context['order_number'] ?? '',
            'tracking_id' => isset($context['tracking_id']) ? intval($context['tracking_id']) : null,
            'delivery_status' => $context['current_status'] ?? null,
            'customer_name' => $context['customer_name'] ?? null,
            'driver_name' => $context['driver_name'] ?? null,
            'driver_phone' => $context['driver_phone'] ?? null
        ],
        'messages' => $messages,
        'last_message_id' => $last_message_id
    ]);
    exit;
}

if ($method === 'POST') {
    $message_text = trim(strval($payload['message'] ?? $_POST['message'] ?? ''));
    if ($message_text === '') {
        jsonErrorResponse(400, 'Message cannot be empty.');
    }
    $text_length = function_exists('mb_strlen') ? mb_strlen($message_text, 'UTF-8') : strlen($message_text);
    if ($text_length > 2000) {
        jsonErrorResponse(400, 'Message is too long. Maximum is 2000 characters.');
    }
    if (!$chat_available || $conversation_id <= 0) {
        jsonErrorResponse(409, 'Chat is not available yet. A driver may not be assigned.');
    }

    $new_message = $chatService->sendMessage(
        $conversation_id,
        $user_id,
        $can_driver_access ? 'rider' : 'customer',
        $message_text,
        'text'
    );
    if (!$new_message) {
        jsonErrorResponse(500, $chatService->getLastError() ?: 'Failed to send message.');
    }

    $chatService->markMessagesAsRead($conversation_id, $user_id);
    $new_message['sender_role'] = $can_driver_access ? 'driver' : 'customer';

    echo json_encode([
        'success' => true,
        'message' => $new_message
    ]);
    exit;
}

jsonErrorResponse(405, 'Method not allowed.');
?>
