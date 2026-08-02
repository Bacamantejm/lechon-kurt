<?php
/**
 * Lightweight AI-style chat automation for support workflows.
 *
 * This service provides deterministic automation that is safe to run
 * without external model dependencies:
 * - Detect order numbers in customer messages.
 * - Link conversations to orders and auto-route to the shop owner.
 * - Send instant status/context replies.
 * - Auto-escalate urgent concerns to platform owners.
 */
class ChatAutomationService {
    private $conn;
    private $chatService;
    private $errors = [];

    public function __construct($conn, $chatService) {
        $this->conn = $conn;
        $this->chatService = $chatService;
    }

    /**
     * Main entry point for customer-message automation.
     */
    public function handleIncomingCustomerMessage($conversation_id, $customer_id, $message_text) {
        $result = [
            'handled' => false,
            'actions' => [],
            'linked_order_id' => null,
            'assigned_agent_id' => null,
            'escalated' => false
        ];

        try {
            $conversation_id = (int)$conversation_id;
            $customer_id = (int)$customer_id;
            $message_text = trim((string)$message_text);

            if ($conversation_id <= 0 || $customer_id <= 0 || $message_text === '') {
                return $result;
            }

            $conversation = $this->chatService->getConversationById($conversation_id);
            if (!$conversation || (int)$conversation['customer_id'] !== $customer_id) {
                return $result;
            }

            $automation_sender_id = $this->resolveAutomationSenderId($conversation);
            $customer_profile = $this->getCustomerProfile($customer_id);

            // 1) Order-aware routing/status automation.
            $order_numbers = $this->extractOrderNumbers($message_text);
            if (!empty($order_numbers)) {
                $order = $this->getOrderByNumber($order_numbers[0]);
                if ($order && $this->canCustomerAccessOrder($order, $customer_profile, $conversation)) {
                    if ((int)($conversation['order_id'] ?? 0) !== (int)$order['id']) {
                        if ($this->chatService->linkConversationToOrder($conversation_id, (int)$order['id'])) {
                            $result['actions'][] = 'linked_to_order';
                            $result['linked_order_id'] = (int)$order['id'];
                            $conversation['order_id'] = (int)$order['id'];
                        }
                    }

                    $shop_user_id = (int)($order['user_id'] ?? 0);
                    if ((int)($conversation['assigned_agent_id'] ?? 0) === 0 && $shop_user_id > 0 && $this->isSupportUser($shop_user_id)) {
                        if ($this->chatService->assignAgent($conversation_id, $shop_user_id)) {
                            $result['actions'][] = 'assigned_to_shop';
                            $result['assigned_agent_id'] = $shop_user_id;
                            $conversation['assigned_agent_id'] = $shop_user_id;
                            $automation_sender_id = $shop_user_id;
                        }
                    }

                    $status_message = $this->buildOrderStatusMessage($order);
                    if ($this->canSendAutomationMessage($conversation_id, 20)) {
                        $this->chatService->sendMessage($conversation_id, $automation_sender_id, 'system', $status_message, 'system');
                        $result['actions'][] = 'order_status_sent';
                    }
                    $result['handled'] = true;
                }
            }

            // 2) General auto-ack for unassigned conversations to reduce perceived wait.
            if ((int)($conversation['assigned_agent_id'] ?? 0) === 0 && $this->canSendAutomationMessage($conversation_id, 45)) {
                $ack_message = "Thanks for messaging support. We are routing your chat to the right shop now. "
                    . "For faster help, include your order number (example: ORD-20260317-69B95DB).";
                $this->chatService->sendMessage($conversation_id, $automation_sender_id, 'system', $ack_message, 'system');
                $result['actions'][] = 'auto_ack_sent';
                $result['handled'] = true;
            }

            // 3) Urgency/complaint escalation to platform owner.
            if (!(int)($conversation['is_escalated'] ?? 0) && $this->isUrgentConcern($message_text)) {
                $reason = 'Auto-escalated by AI assistant due to urgent complaint keywords.';
                if ($this->chatService->escalateConversation($conversation_id, $reason, $automation_sender_id)) {
                    $notice = "Your concern has been escalated to the platform owner for priority review. "
                        . "A senior support member will assist shortly.";
                    $this->chatService->sendMessage($conversation_id, $automation_sender_id, 'system', $notice, 'system');
                    $result['actions'][] = 'escalated_to_platform_owner';
                    $result['escalated'] = true;
                    $result['handled'] = true;
                }
            }

            return $result;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return $result;
        }
    }

    private function getCustomerProfile($customer_id) {
        $query = "SELECT id, email, phone FROM users WHERE id = ? LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    private function extractOrderNumbers($message_text) {
        $matches = [];
        preg_match_all('/\b(?:ORD|WALK)-\d{8}-[A-Z0-9]{4,}\b/i', (string)$message_text, $matches);
        $numbers = $matches[0] ?? [];
        $numbers = array_map('strtoupper', $numbers);
        return array_values(array_unique($numbers));
    }

    private function getOrderByNumber($order_number) {
        $query = "SELECT id, order_number, user_id, status, payment_status, delivery_option,
                         customer_email, customer_phone, delivery_date, delivery_time
                  FROM orders
                  WHERE order_number = ?
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $order_number);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    private function canCustomerAccessOrder(array $order, $customer_profile, array $conversation) {
        if ((int)($conversation['order_id'] ?? 0) === (int)$order['id']) {
            return true;
        }

        if (!$customer_profile) {
            return false;
        }

        $customer_email = strtolower(trim((string)($customer_profile['email'] ?? '')));
        $order_email = strtolower(trim((string)($order['customer_email'] ?? '')));
        if ($customer_email !== '' && $order_email !== '' && $customer_email === $order_email) {
            return true;
        }

        $customer_phone = preg_replace('/\D+/', '', (string)($customer_profile['phone'] ?? ''));
        $order_phone = preg_replace('/\D+/', '', (string)($order['customer_phone'] ?? ''));
        if ($customer_phone !== '' && $order_phone !== '' && substr($customer_phone, -7) === substr($order_phone, -7)) {
            return true;
        }

        return false;
    }

    private function buildOrderStatusMessage(array $order) {
        $status_map = [
            'pending' => 'pending confirmation',
            'confirmed' => 'confirmed',
            'preparing' => 'being prepared',
            'delivered' => 'already delivered',
            'cancelled' => 'cancelled'
        ];
        $raw_status = strtolower((string)($order['status'] ?? 'pending'));
        $friendly_status = $status_map[$raw_status] ?? $raw_status;

        $payment_status = strtolower((string)($order['payment_status'] ?? 'pending'));
        $delivery_label = strtolower((string)($order['delivery_option'] ?? 'delivery')) === 'pickup'
            ? 'pickup'
            : 'delivery';

        $delivery_parts = [];
        if (!empty($order['delivery_date']) && $order['delivery_date'] !== '0000-00-00') {
            $delivery_parts[] = $order['delivery_date'];
        }
        if (!empty($order['delivery_time'])) {
            $delivery_parts[] = $order['delivery_time'];
        }
        $eta_text = !empty($delivery_parts) ? ('Schedule: ' . implode(' ', $delivery_parts) . '.') : '';

        return "Order {$order['order_number']} is currently {$friendly_status}. "
            . "Mode: {$delivery_label}. Payment: {$payment_status}. "
            . $eta_text
            . " A shop representative has been notified.";
    }

    private function canSendAutomationMessage($conversation_id, $cooldown_seconds) {
        $cooldown_seconds = max(5, (int)$cooldown_seconds);
        $query = "SELECT id
                  FROM chat_messages
                  WHERE conversation_id = ?
                  AND sender_type = 'system'
                  AND message_text NOT LIKE 'Agent assigned to conversation%'
                  AND created_at > DATE_SUB(NOW(), INTERVAL {$cooldown_seconds} SECOND)
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return !$row;
    }

    private function isUrgentConcern($message_text) {
        $normalized = strtolower((string)$message_text);
        return (bool)preg_match(
            '/\b(refund|chargeback|fraud|scam|complaint|dti|lawsuit|legal|supervisor|manager|owner|angry|urgent|asap|escalate)\b/',
            $normalized
        );
    }

    private function resolveAutomationSenderId(array $conversation) {
        $assigned_agent_id = (int)($conversation['assigned_agent_id'] ?? 0);
        if ($assigned_agent_id > 0) {
            return $assigned_agent_id;
        }

        $platform_owner_ids = $this->chatService->getPlatformOwnerIds();
        if (!empty($platform_owner_ids)) {
            return (int)$platform_owner_ids[0];
        }

        $fallback_query = "SELECT id FROM users WHERE user_type = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1";
        $fallback_res = $this->conn->query($fallback_query);
        if ($fallback_res && $row = $fallback_res->fetch_assoc()) {
            return (int)$row['id'];
        }

        return (int)($conversation['customer_id'] ?? 0);
    }

    private function isSupportUser($user_id) {
        $query = "SELECT u.id
                  FROM users u
                  LEFT JOIN franchise_applications fa ON fa.user_id = u.id AND fa.status = 'approved'
                  WHERE u.id = ?
                    AND u.is_active = 1
                    AND (
                        u.user_type IN ('admin', 'employee')
                        OR fa.user_id IS NOT NULL
                    )
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (bool)$row;
    }

    public function getLastError() {
        return !empty($this->errors) ? end($this->errors) : null;
    }
}
?>
