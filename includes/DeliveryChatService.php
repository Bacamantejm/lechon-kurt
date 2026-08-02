<?php
/**
 * Delivery Chat Service
 * Handles customer-driver chat per order.
 */
class DeliveryChatService {
    private $conn;
    private static $schemaInitialized = false;

    public function __construct($connection) {
        $this->conn = $connection;
        $this->ensureSchema();
    }

    private function ensureSchema() {
        if (self::$schemaInitialized) {
            return;
        }

        $query = "CREATE TABLE IF NOT EXISTS delivery_chat_messages (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT NOT NULL,
                    tracking_id INT NULL,
                    sender_user_id INT NOT NULL,
                    sender_role ENUM('customer','driver') NOT NULL,
                    message_text TEXT NOT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_delivery_chat_order_id (order_id),
                    INDEX idx_delivery_chat_order_message (order_id, id),
                    INDEX idx_delivery_chat_sender (sender_user_id)
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        mysqli_query($this->conn, $query);
        self::$schemaInitialized = true;
    }

    public function getOrderChatContext($order_id) {
        $query = "SELECT
                    o.id AS order_id,
                    o.user_id AS customer_user_id,
                    cu.full_name AS customer_name,
                    o.order_number,
                    lt.id AS tracking_id,
                    lt.current_status,
                    lt.driver_id,
                    lt.driver_name,
                    lt.driver_phone,
                    e.user_id AS driver_user_id,
                    du.full_name AS driver_user_name
                  FROM orders o
                  LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
                  LEFT JOIN users cu ON cu.id = o.user_id
                  LEFT JOIN employees e ON e.id = lt.driver_id
                  LEFT JOIN users du ON du.id = e.user_id
                  WHERE o.id = ?
                  ORDER BY lt.updated_at DESC, lt.id DESC
                  LIMIT 1";

        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, "i", $order_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $context = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($stmt);

        return $context;
    }

    public function canCustomerAccess($order_id, $customer_user_id) {
        $query = "SELECT id FROM orders WHERE id = ? AND user_id = ? LIMIT 1";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "ii", $order_id, $customer_user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $allowed = mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);

        return $allowed;
    }

    public function canDriverAccess($order_id, $driver_user_id) {
        $query = "SELECT lt.id
                  FROM logistics_tracking lt
                  INNER JOIN employees e ON e.id = lt.driver_id
                  WHERE lt.order_id = ?
                    AND e.user_id = ?
                    AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered')
                  LIMIT 1";

        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "ii", $order_id, $driver_user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $allowed = mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);

        return $allowed;
    }

    public function sendMessage($order_id, $tracking_id, $sender_user_id, $sender_role, $message_text) {
        $message_text = trim($message_text);
        if ($message_text === '') {
            return false;
        }

        $query = "INSERT INTO delivery_chat_messages
                  (order_id, tracking_id, sender_user_id, sender_role, message_text, created_at)
                  VALUES (?, NULLIF(?, 0), ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return false;
        }

        $tracking_id = intval($tracking_id);
        mysqli_stmt_bind_param($stmt, "iiiss", $order_id, $tracking_id, $sender_user_id, $sender_role, $message_text);
        $ok = mysqli_stmt_execute($stmt);
        $message_id = $ok ? mysqli_insert_id($this->conn) : 0;
        mysqli_stmt_close($stmt);

        if (!$ok) {
            return false;
        }

        return [
            'id' => $message_id,
            'order_id' => $order_id,
            'tracking_id' => $tracking_id > 0 ? $tracking_id : null,
            'sender_user_id' => $sender_user_id,
            'sender_role' => $sender_role,
            'message_text' => $message_text,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    public function getMessages($order_id, $after_id = 0, $limit = 100) {
        $query = "SELECT
                    m.id,
                    m.order_id,
                    m.sender_user_id,
                    m.sender_role,
                    m.message_text,
                    m.is_read,
                    m.created_at,
                    u.full_name AS sender_name
                  FROM delivery_chat_messages m
                  LEFT JOIN users u ON u.id = m.sender_user_id
                  WHERE m.order_id = ?
                    AND m.id > ?
                  ORDER BY m.id ASC
                  LIMIT ?";

        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, "iii", $order_id, $after_id, $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $messages = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $messages[] = $row;
        }
        mysqli_stmt_close($stmt);

        return $messages;
    }

    public function markIncomingAsRead($order_id, $viewer_user_id) {
        $query = "UPDATE delivery_chat_messages
                  SET is_read = 1
                  WHERE order_id = ?
                    AND sender_user_id != ?
                    AND is_read = 0";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "ii", $order_id, $viewer_user_id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}
?>
