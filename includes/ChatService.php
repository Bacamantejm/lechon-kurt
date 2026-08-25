<?php
/**
 * Chat Support System Service
 * Handles all chat operations including conversations, messages, and attachments
 */

class ChatService {
    private $conn;
    private $errors = [];
    private static $schemaInitialized = false;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->ensureSchema();
    }

    private function tableExists(string $table_name): bool {
        $safe_table = $this->conn->real_escape_string(trim($table_name));
        if ($safe_table === '') {
            return false;
        }
        $result = $this->conn->query("SHOW TABLES LIKE '{$safe_table}'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        return $exists;
    }

    private function columnExists(string $table_name, string $column_name): bool {
        if (!$this->tableExists($table_name)) {
            return false;
        }
        $safe_table = $this->conn->real_escape_string(trim($table_name));
        $safe_column = $this->conn->real_escape_string(trim($column_name));
        $result = $this->conn->query("SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        return $exists;
    }

    private function ensureSchema(): void {
        if (self::$schemaInitialized) {
            return;
        }

        $this->conn->query(
            "CREATE TABLE IF NOT EXISTS chat_conversation_members (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                user_id INT NOT NULL,
                participant_role ENUM('customer','store','platform','rider','agent') NOT NULL DEFAULT 'customer',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_read_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uniq_conversation_member (conversation_id, user_id),
                KEY idx_member_user (user_id),
                KEY idx_member_role (participant_role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $alter_columns = [
            'conversation_channel' => "ALTER TABLE chat_conversations ADD COLUMN conversation_channel ENUM('customer_platform','customer_store','store_platform','delivery','group') NOT NULL DEFAULT 'customer_platform' AFTER conversation_type",
            'seller_id' => "ALTER TABLE chat_conversations ADD COLUMN seller_id INT NULL AFTER customer_id",
            'platform_owner_id' => "ALTER TABLE chat_conversations ADD COLUMN platform_owner_id INT NULL AFTER seller_id",
            'rider_user_id' => "ALTER TABLE chat_conversations ADD COLUMN rider_user_id INT NULL AFTER platform_owner_id",
        ];

        foreach ($alter_columns as $column_name => $sql) {
            if (!$this->columnExists('chat_conversations', $column_name)) {
                $this->conn->query($sql);
            }
        }

        if ($this->columnExists('chat_messages', 'sender_type')) {
            $this->conn->query("ALTER TABLE chat_messages MODIFY COLUMN sender_type ENUM('customer','agent','system','store','platform','rider') DEFAULT 'customer'");
        }

        self::$schemaInitialized = true;
    }

    private function userColumnExists(string $column_name): bool {
        static $column_cache = [];
        $column_name = trim($column_name);
        if ($column_name === '') {
            return false;
        }
        if (array_key_exists($column_name, $column_cache)) {
            return (bool)$column_cache[$column_name];
        }

        $safe_column = $this->conn->real_escape_string($column_name);
        $query = "SHOW COLUMNS FROM `users` LIKE '{$safe_column}'";
        $result = $this->conn->query($query);
        $exists = $result && $result->num_rows > 0;
        $column_cache[$column_name] = $exists;

        if ($result instanceof mysqli_result) {
            $result->free();
        }
        return $exists;
    }

    private function normalizeAvatarPath($value): string {
        $path = str_replace('\\', '/', trim((string)$value));
        if ($path === '') {
            return '';
        }
        if (!preg_match('#^uploads/(profile_pictures|business_logos)/[A-Za-z0-9._-]+$#', $path)) {
            return '';
        }
        return $path;
    }

    private function resolveMessageSenderAvatar(array $row): string {
        if (strtolower(trim((string)($row['sender_type'] ?? ''))) === 'system') {
            return '';
        }

        $account_type = strtolower(trim((string)($row['sender_account_type'] ?? 'individual')));
        $profile_image = $this->normalizeAvatarPath($row['sender_profile_image'] ?? '');
        $business_logo = $this->normalizeAvatarPath($row['sender_business_logo'] ?? '');

        if ($account_type === 'organization' && $business_logo !== '') {
            return $business_logo;
        }
        if ($profile_image !== '') {
            return $profile_image;
        }
        if ($business_logo !== '') {
            return $business_logo;
        }
        return '';
    }

    private function getApprovedPartnerOwnerId(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }

        $query = "SELECT fa.user_id
                  FROM franchise_applications fa
                  WHERE fa.user_id = ?
                    AND fa.status = 'approved'
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!empty($row['user_id'])) {
                return (int)$row['user_id'];
            }
        }

        $query = "SELECT fa.user_id
                  FROM users u
                  INNER JOIN franchise_applications fa ON fa.user_id = u.id AND fa.status = 'approved'
                  WHERE u.id = ?
                    AND u.account_type = 'organization'
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!empty($row['user_id'])) {
                return (int)$row['user_id'];
            }
        }

        if (
            $this->tableExists('partner_user_links')
            && $this->columnExists('partner_user_links', 'owner_user_id')
            && $this->columnExists('partner_user_links', 'managed_user_id')
        ) {
            $query = "SELECT fa.user_id
                      FROM partner_user_links pul
                      INNER JOIN franchise_applications fa ON fa.user_id = pul.owner_user_id AND fa.status = 'approved'
                      WHERE pul.managed_user_id = ?
                      LIMIT 1";
            $stmt = $this->conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if (!empty($row['user_id'])) {
                    return (int)$row['user_id'];
                }
            }
        }

        if (
            $this->tableExists('users')
            && $this->tableExists('roles')
            && $this->columnExists('users', 'role_id')
            && $this->columnExists('roles', 'owner_user_id')
        ) {
            $query = "SELECT fa.user_id
                      FROM users u
                      INNER JOIN roles r ON r.id = u.role_id
                      INNER JOIN franchise_applications fa ON fa.user_id = r.owner_user_id AND fa.status = 'approved'
                      WHERE u.id = ?
                        AND r.owner_user_id IS NOT NULL
                      LIMIT 1";
            $stmt = $this->conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if (!empty($row['user_id'])) {
                    return (int)$row['user_id'];
                }
            }
        }

        if (
            $this->tableExists('employees')
            && $this->columnExists('employees', 'owner_user_id')
            && $this->columnExists('employees', 'user_id')
        ) {
            $query = "SELECT fa.user_id
                      FROM employees e
                      INNER JOIN franchise_applications fa ON fa.user_id = e.owner_user_id AND fa.status = 'approved'
                      WHERE e.user_id = ?
                      LIMIT 1";
            $stmt = $this->conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if (!empty($row['user_id'])) {
                    return (int)$row['user_id'];
                }
            }
        }

        return 0;
    }

    public function getApprovedPartnerScopeOwnerId(int $user_id): int {
        return $this->getApprovedPartnerOwnerId($user_id);
    }

    private function getOrderPartyData(int $order_id): array {
        if ($order_id <= 0) {
            return ['seller_id' => 0, 'rider_user_id' => 0];
        }

        $seller_id = 0;
        $rider_user_id = 0;

        if ($this->columnExists('orders', 'seller_id')) {
            $stmt = $this->conn->prepare("SELECT seller_id FROM orders WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                $seller_id = (int)($row['seller_id'] ?? 0);
            }
        }

        if ($this->tableExists('logistics_tracking') && $this->tableExists('employees')) {
            $stmt = $this->conn->prepare(
                "SELECT e.user_id
                 FROM logistics_tracking lt
                 INNER JOIN employees e ON e.id = lt.driver_id
                 WHERE lt.order_id = ?
                 ORDER BY lt.updated_at DESC, lt.id DESC
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                $rider_user_id = (int)($row['user_id'] ?? 0);
            }
        }

        return [
            'seller_id' => $seller_id,
            'rider_user_id' => $rider_user_id,
        ];
    }

    private function ensureConversationMember(int $conversation_id, int $user_id, string $participant_role): void {
        if ($conversation_id <= 0 || $user_id <= 0 || !$this->tableExists('chat_conversation_members')) {
            return;
        }

        $participant_role = in_array($participant_role, ['customer', 'store', 'platform', 'rider', 'agent'], true)
            ? $participant_role
            : 'customer';

        $stmt = $this->conn->prepare(
            "INSERT INTO chat_conversation_members (conversation_id, user_id, participant_role, is_active, joined_at)
             VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE participant_role = VALUES(participant_role), is_active = 1"
        );
        if ($stmt) {
            $stmt->bind_param("iis", $conversation_id, $user_id, $participant_role);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function syncConversationMembers(int $conversation_id): void {
        if ($conversation_id <= 0 || !$this->tableExists('chat_conversation_members')) {
            return;
        }

        $stmt = $this->conn->prepare(
            "SELECT customer_id, seller_id, platform_owner_id, rider_user_id, assigned_agent_id
             FROM chat_conversations
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $conversation = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$conversation) {
            return;
        }

        $conversation = $this->ensureConversationPlatformOwner($conversation_id, $conversation);

        $customer_id = (int)($conversation['customer_id'] ?? 0);
        $seller_id = (int)($conversation['seller_id'] ?? 0);
        $platform_owner_id = (int)($conversation['platform_owner_id'] ?? 0);
        $rider_user_id = (int)($conversation['rider_user_id'] ?? 0);
        $assigned_agent_id = (int)($conversation['assigned_agent_id'] ?? 0);

        if ($customer_id > 0) {
            $this->ensureConversationMember($conversation_id, $customer_id, 'customer');
        }
        if ($seller_id > 0) {
            $this->ensureConversationMember($conversation_id, $seller_id, 'store');
        }
        if ($platform_owner_id > 0) {
            $this->ensureConversationMember($conversation_id, $platform_owner_id, 'platform');
        }
        if ($rider_user_id > 0) {
            $this->ensureConversationMember($conversation_id, $rider_user_id, 'rider');
        }
        if ($assigned_agent_id > 0) {
            $this->ensureConversationMember($conversation_id, $assigned_agent_id, 'agent');
        }
    }

    private function shouldAutoAttachPlatformOwner(array $conversation): bool {
        $channel = trim((string)($conversation['conversation_channel'] ?? 'customer_platform'));
        if (!in_array($channel, ['customer_platform', 'customer_store', 'delivery'], true)) {
            return false;
        }

        $customer_id = (int)($conversation['customer_id'] ?? 0);
        $seller_id = (int)($conversation['seller_id'] ?? 0);
        $rider_user_id = (int)($conversation['rider_user_id'] ?? 0);

        return $customer_id > 0 || $seller_id > 0 || $rider_user_id > 0;
    }

    private function getDefaultPlatformOwnerId(): int {
        $platform_owner_ids = $this->getPlatformOwnerIds();
        return (int)($platform_owner_ids[0] ?? 0);
    }

    private function ensureConversationPlatformOwner(int $conversation_id, array $conversation): array {
        $platform_owner_id = (int)($conversation['platform_owner_id'] ?? 0);
        if ($platform_owner_id > 0 || !$this->shouldAutoAttachPlatformOwner($conversation)) {
            return $conversation;
        }

        $default_platform_owner_id = $this->getDefaultPlatformOwnerId();
        if ($default_platform_owner_id <= 0) {
            return $conversation;
        }

        $stmt = $this->conn->prepare(
            "UPDATE chat_conversations
             SET platform_owner_id = ?
             WHERE id = ?
               AND COALESCE(platform_owner_id, 0) = 0"
        );
        if ($stmt) {
            $stmt->bind_param("ii", $default_platform_owner_id, $conversation_id);
            $stmt->execute();
            $stmt->close();
        }

        $conversation['platform_owner_id'] = $default_platform_owner_id;
        return $conversation;
    }

    public function hasConversationAccess($conversation_id, $user_id): bool {
        $conversation_id = (int)$conversation_id;
        $user_id = (int)$user_id;
        if ($conversation_id <= 0 || $user_id <= 0) {
            return false;
        }

        $this->syncConversationMembers($conversation_id);

        if ($this->tableExists('chat_conversation_members')) {
            $stmt = $this->conn->prepare(
                "SELECT 1
                 FROM chat_conversation_members
                 WHERE conversation_id = ?
                   AND user_id = ?
                   AND is_active = 1
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param("ii", $conversation_id, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $allowed = $result && $result->num_rows > 0;
                $stmt->close();
                if ($allowed) {
                    return true;
                }
            }
        }

        $stmt = $this->conn->prepare(
            "SELECT customer_id, seller_id, platform_owner_id, rider_user_id, assigned_agent_id
             FROM chat_conversations
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $conversation = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$conversation) {
            return false;
        }

        if ((int)($conversation['customer_id'] ?? 0) === $user_id) {
            return true;
        }
        if ((int)($conversation['platform_owner_id'] ?? 0) === $user_id || (int)($conversation['assigned_agent_id'] ?? 0) === $user_id) {
            return true;
        }
        if ((int)($conversation['seller_id'] ?? 0) > 0 && $this->getApprovedPartnerOwnerId($user_id) === (int)$conversation['seller_id']) {
            return true;
        }
        if ((int)($conversation['rider_user_id'] ?? 0) === $user_id) {
            return true;
        }

        return false;
    }

    private function getConversationActorLabel(array $conversation, int $viewer_user_id): string {
        $channel = trim((string)($conversation['conversation_channel'] ?? 'customer_platform'));
        $customer_name = trim((string)($conversation['customer_name'] ?? 'Customer'));
        $seller_name = trim((string)($conversation['seller_name'] ?? 'Store'));
        $platform_name = trim((string)($conversation['platform_name'] ?? 'Platform'));
        $rider_name = trim((string)($conversation['rider_name'] ?? 'Rider'));

        if ((int)($conversation['customer_id'] ?? 0) === $viewer_user_id) {
            if ($channel === 'customer_store') return $seller_name;
            if ($channel === 'delivery') return $rider_name;
            return $platform_name;
        }

        if ((int)($conversation['seller_id'] ?? 0) === $this->getApprovedPartnerOwnerId($viewer_user_id)) {
            if ($channel === 'store_platform') return $platform_name;
            return $customer_name;
        }

        if ((int)($conversation['rider_user_id'] ?? 0) === $viewer_user_id) {
            return $customer_name;
        }

        if ($channel === 'store_platform') {
            return $seller_name;
        }
        if ($channel === 'customer_store' || $channel === 'delivery') {
            return $customer_name . ' - ' . $seller_name;
        }

        return $customer_name;
    }

    private function getConversationCounterpartUserId(array $conversation, int $viewer_user_id): int {
        $channel = trim((string)($conversation['conversation_channel'] ?? 'customer_platform'));
        $customer_id = (int)($conversation['customer_id'] ?? 0);
        $seller_id = (int)($conversation['seller_id'] ?? 0);
        $platform_owner_id = (int)($conversation['platform_owner_id'] ?? 0);
        $rider_user_id = (int)($conversation['rider_user_id'] ?? 0);
        $assigned_agent_id = (int)($conversation['assigned_agent_id'] ?? 0);
        $platform_actor_id = $platform_owner_id > 0 ? $platform_owner_id : $assigned_agent_id;

        if ($customer_id > 0 && $customer_id === $viewer_user_id) {
            if ($channel === 'customer_store') return $seller_id;
            if ($channel === 'delivery') return $rider_user_id;
            return $platform_actor_id;
        }

        $partner_owner_id = $this->getApprovedPartnerOwnerId($viewer_user_id);
        if ($seller_id > 0 && $partner_owner_id > 0 && $seller_id === $partner_owner_id) {
            if ($channel === 'store_platform') return $platform_actor_id;
            return $customer_id;
        }

        if ($rider_user_id > 0 && $rider_user_id === $viewer_user_id) {
            return $customer_id;
        }

        if ($platform_owner_id > 0 && $platform_owner_id === $viewer_user_id) {
            if ($channel === 'store_platform') return $seller_id;
            return $customer_id;
        }
        if ($assigned_agent_id > 0 && $assigned_agent_id === $viewer_user_id) {
            if ($channel === 'store_platform') return $seller_id;
            return $customer_id;
        }

        if ($channel === 'store_platform') {
            return $seller_id > 0 ? $seller_id : $platform_actor_id;
        }
        if ($channel === 'delivery') {
            return $rider_user_id > 0 ? $rider_user_id : $customer_id;
        }
        if ($channel === 'customer_store') {
            return $seller_id > 0 ? $seller_id : $customer_id;
        }

        return $customer_id > 0 ? $customer_id : $platform_actor_id;
    }

    private function getConversationParticipantPresence(int $conversation_id, int $participant_user_id): array {
        $fallback = [
            'status' => 'offline',
            'is_typing' => 0,
            'last_active_at' => null,
        ];

        if ($conversation_id <= 0 || $participant_user_id <= 0) {
            return $fallback;
        }

        $query = "SELECT
                    (SELECT 1
                     FROM chat_typing_indicators cti
                     WHERE cti.conversation_id = ?
                       AND cti.user_id = ?
                       AND cti.created_at > DATE_SUB(NOW(), INTERVAL 45 SECOND)
                     LIMIT 1) AS is_typing_now,
                    (SELECT MAX(cm.created_at)
                     FROM chat_messages cm
                     WHERE cm.conversation_id = ?
                       AND cm.sender_id = ?) AS last_message_at";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return $fallback;
        }

        $stmt->bind_param("iiii", $conversation_id, $participant_user_id, $conversation_id, $participant_user_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return $fallback;
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            return $fallback;
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        $is_typing = (int)($row['is_typing_now'] ?? 0) === 1 ? 1 : 0;
        $last_active_at = isset($row['last_message_at']) ? (string)$row['last_message_at'] : null;
        $status = 'offline';

        if ($is_typing === 1) {
            $status = 'online';
        } elseif ($last_active_at) {
            $last_active_ts = strtotime($last_active_at);
            if ($last_active_ts !== false) {
                $delta = time() - $last_active_ts;
                if ($delta <= 7 * 60) {
                    $status = 'online';
                } elseif ($delta <= 60 * 60) {
                    $status = 'away';
                }
            }
        }

        return [
            'status' => $status,
            'is_typing' => $is_typing,
            'last_active_at' => $last_active_at,
        ];
    }

    private function hydrateConversationRow(array $row, int $viewer_user_id): array {
        $channel = trim((string)($row['conversation_channel'] ?? 'customer_platform'));
        $channel_labels = [
            'customer_platform' => 'Customer / Platform',
            'customer_store' => 'Customer / Store',
            'store_platform' => 'Store / Platform',
            'delivery' => 'Customer / Rider',
            'group' => 'Group Thread',
        ];
        $row['counterpart_name'] = $this->getConversationActorLabel($row, $viewer_user_id);
        $row['channel_label'] = $channel_labels[$channel] ?? 'Chat';
        $counterpart_user_id = $this->getConversationCounterpartUserId($row, $viewer_user_id);
        $presence = $this->getConversationParticipantPresence((int)($row['id'] ?? 0), $counterpart_user_id);
        $row['counterpart_user_id'] = $counterpart_user_id;
        $row['counterpart_status'] = (string)($presence['status'] ?? 'offline');
        $row['counterpart_is_typing'] = (int)($presence['is_typing'] ?? 0);
        $row['counterpart_last_active_at'] = $presence['last_active_at'] ?? null;
        return $row;
    }

    public function resolveSenderTypeForConversation($conversation_id, $user_id, $session_user_type = null): string {
        $conversation_id = (int)$conversation_id;
        $user_id = (int)$user_id;
        $session_user_type = strtolower(trim((string)$session_user_type));

        if ($conversation_id <= 0 || $user_id <= 0) {
            return 'customer';
        }

        $conversation = $this->getConversationById($conversation_id);
        if (!$conversation) {
            return in_array($session_user_type, ['admin', 'employee'], true) ? 'platform' : 'customer';
        }

        if ((int)($conversation['rider_user_id'] ?? 0) === $user_id) {
            return 'rider';
        }
        if ((int)($conversation['customer_id'] ?? 0) === $user_id) {
            return 'customer';
        }
        if ((int)($conversation['platform_owner_id'] ?? 0) === $user_id || (int)($conversation['assigned_agent_id'] ?? 0) === $user_id) {
            return 'platform';
        }

        $partner_owner_id = $this->getApprovedPartnerOwnerId($user_id);
        if ($partner_owner_id > 0 && (int)($conversation['seller_id'] ?? 0) === $partner_owner_id) {
            return 'store';
        }

        return in_array($session_user_type, ['admin', 'employee'], true) ? 'platform' : 'customer';
    }
    
    /**
     * Get or Create Conversation
     * Returns existing conversation or creates new one
     */
    public function getOrCreateConversation($customer_id, $subject = null, array $options = []) {
        try {
            $customer_id = (int)$customer_id;
            if ($customer_id <= 0) {
                throw new Exception("Customer account is required.");
            }

            $channel = trim((string)($options['conversation_channel'] ?? 'customer_platform'));
            if (!in_array($channel, ['customer_platform', 'customer_store', 'store_platform', 'delivery', 'group'], true)) {
                $channel = 'customer_platform';
            }

            $order_id = (int)($options['order_id'] ?? 0);
            $refund_id = (int)($options['refund_id'] ?? 0);
            $seller_id = (int)($options['seller_id'] ?? 0);
            $platform_owner_id = (int)($options['platform_owner_id'] ?? 0);
            $rider_user_id = (int)($options['rider_user_id'] ?? 0);
            $entity_type = trim((string)($options['entity_type'] ?? 'general'));
            $conversation_type = trim((string)($options['conversation_type'] ?? 'support'));

            if ($order_id > 0) {
                $party_data = $this->getOrderPartyData($order_id);
                if ($seller_id <= 0) {
                    $seller_id = (int)($party_data['seller_id'] ?? 0);
                }
                if ($rider_user_id <= 0) {
                    $rider_user_id = (int)($party_data['rider_user_id'] ?? 0);
                }
            }

            if ($channel === 'customer_store' && $seller_id <= 0 && $order_id > 0) {
                $party_data = $this->getOrderPartyData($order_id);
                $seller_id = (int)($party_data['seller_id'] ?? 0);
            }

            if (in_array($channel, ['customer_platform', 'customer_store', 'delivery'], true) && $platform_owner_id <= 0) {
                $platform_owner_id = $this->getDefaultPlatformOwnerId();
            }

            if ($channel === 'store_platform' && $platform_owner_id <= 0) {
                $platform_owner_id = $this->getDefaultPlatformOwnerId();
            }

            if ($channel === 'delivery' && $rider_user_id <= 0 && $order_id > 0) {
                $party_data = $this->getOrderPartyData($order_id);
                $rider_user_id = (int)($party_data['rider_user_id'] ?? 0);
            }

            $query = "SELECT id, status
                     FROM chat_conversations
                     WHERE customer_id = ?
                       AND COALESCE(seller_id, 0) = ?
                       AND COALESCE(platform_owner_id, 0) = ?
                       AND COALESCE(rider_user_id, 0) = ?
                       AND COALESCE(order_id, 0) = ?
                       AND COALESCE(refund_id, 0) = ?
                       AND conversation_channel = ?
                       AND status IN ('open', 'in_progress')
                     ORDER BY last_message_time DESC, id DESC
                     LIMIT 1";

            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iiiiiis", $customer_id, $seller_id, $platform_owner_id, $rider_user_id, $order_id, $refund_id, $channel);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $this->syncConversationMembers((int)$row['id']);
                return $row;
            }
            
            $subject = $subject ?? "Support Request - " . date('Y-m-d H:i:s');
            $query = "INSERT INTO chat_conversations
                        (customer_id, seller_id, platform_owner_id, rider_user_id, order_id, refund_id, entity_type, conversation_type, conversation_channel, subject, status, created_at)
                     VALUES (?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, 'open', NOW())";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("iiiiiissss", $customer_id, $seller_id, $platform_owner_id, $rider_user_id, $order_id, $refund_id, $entity_type, $conversation_type, $channel, $subject);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $conversation_id = (int)$this->conn->insert_id;
            $this->syncConversationMembers($conversation_id);
            return [
                'id' => $conversation_id,
                'status' => 'open'
            ];
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Conversation Details
     */
    public function getConversation($conversation_id, $user_id) {
        try {
            $query = "SELECT cc.*, 
                     cu.full_name as customer_name, cu.email as customer_email,
                     au.full_name as agent_name, au.email as agent_email,
                     COUNT(cm.id) as message_count,
                     SUM(CASE WHEN cm.is_read = 0 THEN 1 ELSE 0 END) as unread_count
                     FROM chat_conversations cc
                     LEFT JOIN users cu ON cc.customer_id = cu.id
                     LEFT JOIN users au ON cc.assigned_agent_id = au.id
                     LEFT JOIN chat_messages cm ON cc.id = cm.conversation_id
                     WHERE cc.id = ? AND (cc.customer_id = ? OR cc.assigned_agent_id = ?)
                     GROUP BY cc.id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $conversation_id, $user_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Customer's Conversations
     */
    public function getCustomerConversations($customer_id, $limit = 50, $offset = 0) {
        try {
            $query = "SELECT cc.*,
                     cu.full_name as customer_name,
                     COALESCE(NULLIF(TRIM(su.business_name), ''), su.full_name, 'Store') as seller_name,
                     pu.full_name as platform_name,
                     ru.full_name as rider_name,
                     (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id) as message_count,
                     (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id AND is_read = 0 AND sender_id != ?) as unread_count,
                     cc.last_message_time,
                     (SELECT SUBSTRING(message_text, 1, 100) FROM chat_messages WHERE conversation_id = cc.id ORDER BY id DESC LIMIT 1) as last_message_preview
                     FROM chat_conversations cc
                     LEFT JOIN users cu ON cu.id = cc.customer_id
                     LEFT JOIN users su ON su.id = cc.seller_id
                     LEFT JOIN users pu ON pu.id = cc.platform_owner_id
                     LEFT JOIN users ru ON ru.id = cc.rider_user_id
                     WHERE cc.customer_id = ?
                     ORDER BY cc.last_message_time DESC, cc.id DESC
                     LIMIT ? OFFSET ?";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Failed to prepare customer conversations query: " . $this->conn->error);
            }
            $stmt->bind_param("iiii", $customer_id, $customer_id, $limit, $offset);
            if (!$stmt->execute()) {
                throw new Exception("Failed to load customer conversations: " . $stmt->error);
            }
            $result = $stmt->get_result();
            if (!$result) {
                throw new Exception("Failed to fetch customer conversations.");
            }
            
            $conversations = [];
            while ($row = $result->fetch_assoc()) {
                $conversations[] = $this->hydrateConversationRow($row, (int)$customer_id);
            }
            $stmt->close();
            
            return $conversations;
            
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Agent's Assigned Conversations
     */
    public function getAgentConversations($agent_id, $status = null, $limit = 50, $offset = 0, $search = null) {
        try {
            $query = "SELECT cc.*, 
                     cu.full_name as customer_name, cu.email as customer_email,
                     COALESCE(NULLIF(TRIM(su.business_name), ''), su.full_name, 'Store') as seller_name,
                     pu.full_name as platform_name,
                     ru.full_name as rider_name,
                     (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id) as message_count,
                     (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id AND is_read = 0 AND sender_id != ?) as unread_count,
                     cc.last_message_time,
                     (SELECT SUBSTRING(message_text, 1, 100) FROM chat_messages WHERE conversation_id = cc.id ORDER BY id DESC LIMIT 1) as last_message_preview,
                     (SELECT 1 FROM chat_typing_indicators cti WHERE cti.conversation_id = cc.id AND cti.user_id = cc.customer_id AND cti.created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND) LIMIT 1) as is_typing
                     FROM chat_conversations cc
                     LEFT JOIN users cu ON cc.customer_id = cu.id
                     LEFT JOIN users su ON su.id = cc.seller_id
                     LEFT JOIN users pu ON pu.id = cc.platform_owner_id
                     LEFT JOIN users ru ON ru.id = cc.rider_user_id
                     WHERE (cc.assigned_agent_id = ? OR cc.platform_owner_id = ?)";
            
            if ($status) {
                $query .= " AND cc.status = '" . $this->conn->real_escape_string($status) . "'";
            }
            
            if ($search) {
                $search = $this->conn->real_escape_string($search);
                $query .= " AND (cu.full_name LIKE '%$search%' OR cu.email LIKE '%$search%' OR cc.subject LIKE '%$search%')";
            }
            
            $query .= " ORDER BY cc.last_message_time DESC
                       LIMIT ? OFFSET ?";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Failed to prepare agent conversations query: " . $this->conn->error);
            }
            $stmt->bind_param("iiiii", $agent_id, $agent_id, $agent_id, $limit, $offset);
            if (!$stmt->execute()) {
                throw new Exception("Failed to load agent conversations: " . $stmt->error);
            }
            $result = $stmt->get_result();
            if (!$result) {
                throw new Exception("Failed to fetch agent conversations.");
            }
            
            $conversations = [];
            while ($row = $result->fetch_assoc()) {
                $conversations[] = $this->hydrateConversationRow($row, (int)$agent_id);
            }
            $stmt->close();
            
            return $conversations;
            
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Send Message
     */
    public function sendMessage($conversation_id, $sender_id, $sender_type, $message_text, $message_type = 'text') {
        try {
            // Validate inputs
            $message_text = trim((string)$message_text);
            if ($message_text === '') {
                throw new Exception("Message text cannot be empty");
            }
            if (strlen($message_text) > 5000) {
                throw new Exception("Message is too long");
            }
            
            // Check conversation exists
            $check_query = "SELECT id, customer_id, seller_id, platform_owner_id, rider_user_id, assigned_agent_id, is_escalated FROM chat_conversations WHERE id = ?";
            $stmt = $this->conn->prepare($check_query);
            $stmt->bind_param("i", $conversation_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Conversation not found");
            }
            
            $conv_data = $result->fetch_assoc();

            if (!$this->hasConversationAccess($conversation_id, $sender_id)) {
                throw new Exception("Access denied");
            }

            if (in_array($sender_type, ['agent', 'platform'], true) && is_null($conv_data['assigned_agent_id'])) {
                // Auto-assign agent if they reply to unassigned chat
                $this->assignAgent($conversation_id, $sender_id);
            }
            
            // Insert message
            $query = "INSERT INTO chat_messages 
                     (conversation_id, sender_id, sender_type, message_text, message_type, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("iisss", $conversation_id, $sender_id, $sender_type, $message_text, $message_type);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $message_id = $this->conn->insert_id;
            
            // Update conversation's last message time and set status
            $update_query = "UPDATE chat_conversations 
                            SET last_message_time = NOW(), 
                                status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END,
                                first_message_time = COALESCE(first_message_time, NOW())
                            WHERE id = ?";
            
            $stmt = $this->conn->prepare($update_query);
            $stmt->bind_param("i", $conversation_id);
            $stmt->execute();

            $this->syncConversationMembers($conversation_id);

            // Trigger in-app notifications for recipients.
            $this->notifyMessageRecipients($conversation_id, $conv_data, $sender_id, $sender_type);
            
            return [
                'id' => $message_id,
                'conversation_id' => $conversation_id,
                'sender_id' => $sender_id,
                'sender_type' => $sender_type,
                'message_text' => $message_text,
                'message_type' => $message_type,
                'is_read' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Messages (for polling)
     */
    public function getMessages($conversation_id, $limit = 50, $offset = 0, $since_timestamp = null, $after_id = null) {
        try {
            $limit = max(1, min(200, (int)$limit));
            $offset = max(0, (int)$offset);
            $after_id = ($after_id !== null) ? (int)$after_id : null;
            $sender_select = "u.full_name as sender_name, u.email as sender_email";
            $sender_select .= $this->userColumnExists('account_type')
                ? ", u.account_type as sender_account_type"
                : ", 'individual' as sender_account_type";
            $sender_select .= $this->userColumnExists('profile_image')
                ? ", u.profile_image as sender_profile_image"
                : ", NULL as sender_profile_image";
            $sender_select .= $this->userColumnExists('business_logo')
                ? ", u.business_logo as sender_business_logo"
                : ", NULL as sender_business_logo";

            // Incremental fetch path for real-time polling.
            if ($after_id !== null && $after_id >= 0) {
                $query = "SELECT cm.*, 
                         {$sender_select},
                         GROUP_CONCAT(
                             JSON_OBJECT('id', ca.id, 'file_name', ca.file_name, 
                                        'file_type', ca.file_type, 'file_size', ca.file_size,
                                        'file_path', ca.file_path, 'mime_type', ca.mime_type)
                         ) as attachments
                         FROM chat_messages cm
                         LEFT JOIN users u ON cm.sender_id = u.id
                         LEFT JOIN chat_attachments ca ON cm.id = ca.message_id
                         WHERE cm.conversation_id = ?
                         AND cm.id > ?
                         GROUP BY cm.id
                         ORDER BY cm.id ASC
                         LIMIT ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("iii", $conversation_id, $after_id, $limit);
            } elseif ($offset === 0 && empty($since_timestamp)) {
                // Initial load path: fetch latest N messages, then sort oldest->newest for rendering.
                $query = "SELECT cm.*, 
                         {$sender_select},
                         GROUP_CONCAT(
                             JSON_OBJECT('id', ca.id, 'file_name', ca.file_name, 
                                        'file_type', ca.file_type, 'file_size', ca.file_size,
                                        'file_path', ca.file_path, 'mime_type', ca.mime_type)
                         ) as attachments
                         FROM chat_messages cm
                         LEFT JOIN users u ON cm.sender_id = u.id
                         LEFT JOIN chat_attachments ca ON cm.id = ca.message_id
                         WHERE cm.id IN (
                             SELECT msg_id FROM (
                                 SELECT id AS msg_id
                                 FROM chat_messages
                                 WHERE conversation_id = ?
                                 ORDER BY id DESC
                                 LIMIT ?
                             ) latest_ids
                         )
                         GROUP BY cm.id
                         ORDER BY cm.id ASC";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("ii", $conversation_id, $limit);
            } else {
                // Legacy path with offset/since support.
                $query = "SELECT cm.*, 
                         {$sender_select},
                         GROUP_CONCAT(
                             JSON_OBJECT('id', ca.id, 'file_name', ca.file_name, 
                                        'file_type', ca.file_type, 'file_size', ca.file_size,
                                        'file_path', ca.file_path, 'mime_type', ca.mime_type)
                         ) as attachments
                         FROM chat_messages cm
                         LEFT JOIN users u ON cm.sender_id = u.id
                         LEFT JOIN chat_attachments ca ON cm.id = ca.message_id
                         WHERE cm.conversation_id = ?";
                
                if ($since_timestamp) {
                    $query .= " AND cm.created_at > '" . $this->conn->real_escape_string($since_timestamp) . "'";
                }
                
                $query .= " GROUP BY cm.id
                           ORDER BY cm.created_at ASC
                           LIMIT ? OFFSET ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("iii", $conversation_id, $limit, $offset);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            
            $messages = [];
            while ($row = $result->fetch_assoc()) {
                if ($row['attachments']) {
                    $row['attachments'] = json_decode('[' . $row['attachments'] . ']', true);
                } else {
                    $row['attachments'] = [];
                }
                $row['sender_avatar'] = $this->resolveMessageSenderAvatar($row);
                $messages[] = $row;
            }
            
            return $messages;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }

    public function getPartnerConversations($partner_owner_id, $status = null, $limit = 50, $offset = 0, $search = null) {
        try {
            $query = "SELECT cc.*,
                     cu.full_name as customer_name, cu.email as customer_email,
                     COALESCE(NULLIF(TRIM(su.business_name), ''), su.full_name, 'Store') as seller_name,
                     pu.full_name as platform_name,
                     ru.full_name as rider_name,
                     (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id) as message_count,
                     (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id AND is_read = 0 AND sender_id != ?) as unread_count,
                     cc.last_message_time,
                     (SELECT SUBSTRING(message_text, 1, 100) FROM chat_messages WHERE conversation_id = cc.id ORDER BY id DESC LIMIT 1) as last_message_preview,
                     (SELECT 1 FROM chat_typing_indicators cti WHERE cti.conversation_id = cc.id AND cti.user_id = cc.customer_id AND cti.created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND) LIMIT 1) as is_typing
                     FROM chat_conversations cc
                     LEFT JOIN users cu ON cc.customer_id = cu.id
                     LEFT JOIN users su ON su.id = cc.seller_id
                     LEFT JOIN users pu ON pu.id = cc.platform_owner_id
                     LEFT JOIN users ru ON ru.id = cc.rider_user_id
                     WHERE cc.seller_id = ?";

            if ($status) {
                $query .= " AND cc.status = '" . $this->conn->real_escape_string($status) . "'";
            }
            if ($search) {
                $search = $this->conn->real_escape_string($search);
                $query .= " AND (cu.full_name LIKE '%$search%' OR su.business_name LIKE '%$search%' OR cc.subject LIKE '%$search%' OR cc.conversation_channel LIKE '%$search%')";
            }

            $query .= " ORDER BY cc.last_message_time DESC, cc.id DESC LIMIT ? OFFSET ?";

            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Failed to prepare partner conversations query: " . $this->conn->error);
            }
            $stmt->bind_param("iiii", $partner_owner_id, $partner_owner_id, $limit, $offset);
            if (!$stmt->execute()) {
                throw new Exception("Failed to load partner conversations: " . $stmt->error);
            }
            $result = $stmt->get_result();
            if (!$result) {
                throw new Exception("Failed to fetch partner conversations.");
            }

            $conversations = [];
            while ($row = $result->fetch_assoc()) {
                $conversations[] = $this->hydrateConversationRow($row, (int)$partner_owner_id);
            }
            $stmt->close();

            return $conversations;
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }

    public function getOrCreateStorePlatformConversation($store_owner_id, $subject = null) {
        try {
            $store_owner_id = (int)$store_owner_id;
            if ($store_owner_id <= 0) {
                throw new Exception("Store owner is required.");
            }

            $platform_owner_id = $this->getDefaultPlatformOwnerId();
            if ($platform_owner_id <= 0) {
                throw new Exception("No platform owner account is available for chat routing.");
            }

            $query = "SELECT id, status
                     FROM chat_conversations
                     WHERE seller_id = ?
                       AND platform_owner_id = ?
                       AND conversation_channel = 'store_platform'
                       AND status IN ('open', 'in_progress')
                     ORDER BY last_message_time DESC, id DESC
                     LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $store_owner_id, $platform_owner_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                $this->syncConversationMembers((int)$row['id']);
                return $row;
            }
            $stmt->close();

            $subject = $subject ?: 'Store Coordination Channel';
            $stmt = $this->conn->prepare(
                "INSERT INTO chat_conversations
                    (customer_id, seller_id, platform_owner_id, conversation_type, conversation_channel, subject, status, created_at)
                 VALUES (NULL, ?, ?, 'support', 'store_platform', ?, 'open', NOW())"
            );
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            $stmt->bind_param("iis", $store_owner_id, $platform_owner_id, $subject);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $conversation_id = (int)$this->conn->insert_id;
            $stmt->close();
            $this->syncConversationMembers($conversation_id);

            return ['id' => $conversation_id, 'status' => 'open'];
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }

    public function getOrCreateDeliveryConversation($order_id, $customer_id) {
        $party_data = $this->getOrderPartyData((int)$order_id);
        return $this->getOrCreateConversation((int)$customer_id, 'Delivery Chat', [
            'conversation_channel' => 'delivery',
            'order_id' => (int)$order_id,
            'seller_id' => (int)($party_data['seller_id'] ?? 0),
            'rider_user_id' => (int)($party_data['rider_user_id'] ?? 0),
            'entity_type' => 'order',
            'conversation_type' => 'order_tracking',
        ]);
    }

    /**
     * Returns the latest message ID for a conversation.
     */
    public function getLatestMessageId($conversation_id) {
        try {
            $query = "SELECT COALESCE(MAX(id), 0) AS latest_id
                     FROM chat_messages
                     WHERE conversation_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $conversation_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return (int)($row['latest_id'] ?? 0);
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return 0;
        }
    }
    
    /**
     * Mark Messages as Read
     */
    public function markMessagesAsRead($conversation_id, $user_id) {
        try {
            $query = "UPDATE chat_messages 
                     SET is_read = 1, read_at = NOW()
                     WHERE conversation_id = ? AND sender_id != ? AND is_read = 0";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $conversation_id, $user_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to mark messages as read: " . $stmt->error);
            }
            
            return $stmt->affected_rows;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Assign Agent to Conversation
     */
    public function assignAgent($conversation_id, $agent_id) {
        try {
            $query = "UPDATE chat_conversations 
                     SET assigned_agent_id = ?, status = 'in_progress'
                     WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $agent_id, $conversation_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to assign agent: " . $stmt->error);
            }
            
            // Create system message
            $this->sendMessage($conversation_id, $agent_id, 'system', 
                              "Agent assigned to conversation");
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Close Conversation
     */
    public function closeConversation($conversation_id, $rating = null, $feedback = null) {
        try {
            $query = "UPDATE chat_conversations 
                     SET status = 'closed', resolved_at = NOW()";
            
            if ($rating !== null) {
                $query .= ", satisfaction_rating = " . intval($rating);
            }
            
            if ($feedback) {
                $query .= ", satisfaction_feedback = '" . $this->conn->real_escape_string($feedback) . "'";
            }
            
            $query .= " WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $conversation_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to close conversation: " . $stmt->error);
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Unread Message Count
     */
    public function getUnreadCount($user_id, $is_agent = false) {
        try {
            if ($is_agent) {
                // For agents: unread messages from customers
                $query = "SELECT COUNT(cm.id) as unread_count
                         FROM chat_messages cm
                         JOIN chat_conversations cc ON cm.conversation_id = cc.id
                         WHERE cc.assigned_agent_id = ? AND cm.is_read = 0 AND cm.sender_type = 'customer'";
            } else {
                // For customers: unread messages from agents
                $query = "SELECT COUNT(cm.id) as unread_count
                         FROM chat_messages cm
                         JOIN chat_conversations cc ON cm.conversation_id = cc.id
                         WHERE cc.customer_id = ? AND cm.is_read = 0 AND cm.sender_type = 'agent'";
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            return $result['unread_count'] ?? 0;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return 0;
        }
    }
    
    /**
     * Set Typing Indicator
     */
    public function setTypingStatus($conversation_id, $user_id, $is_typing = true) {
        try {
            if ($is_typing) {
                $query = "INSERT INTO chat_typing_indicators (conversation_id, user_id, is_typing, created_at)
                         VALUES (?, ?, 1, NOW())
                         ON DUPLICATE KEY UPDATE created_at = NOW()";
            } else {
                $query = "DELETE FROM chat_typing_indicators 
                         WHERE conversation_id = ? AND user_id = ?";
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $conversation_id, $user_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update typing status: " . $stmt->error);
            }
            
            // Cleanup old typing indicators (older than 30 seconds)
            $cleanup_query = "DELETE FROM chat_typing_indicators 
                            WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 SECOND)";
            $this->conn->query($cleanup_query);
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Typing Users
     */
    public function getTypingUsers($conversation_id, $exclude_user_id = null) {
        try {
            $query = "SELECT cti.user_id, u.full_name 
                     FROM chat_typing_indicators cti
                     JOIN users u ON cti.user_id = u.id
                     WHERE cti.conversation_id = ? 
                     AND cti.created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)";
            
            if ($exclude_user_id) {
                $query .= " AND cti.user_id != " . intval($exclude_user_id);
            }
            
            $result = $this->conn->query($query);
            $typing_users = [];
            
            while ($row = $result->fetch_assoc()) {
                $typing_users[] = $row;
            }
            
            return $typing_users;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return [];
        }
    }
    
    /**
     * Store Attachment
     */
    public function storeAttachment($message_id, $file_name, $file_path, $file_type, $file_size, $mime_type, $uploaded_by) {
        try {
            $query = "INSERT INTO chat_attachments 
                     (message_id, file_name, file_path, file_type, file_size, mime_type, uploaded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("isssisi", $message_id, $file_name, $file_path, $file_type, $file_size, $mime_type, $uploaded_by);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            return $this->conn->insert_id;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get All Unassigned Conversations
     */
    public function getUnassignedConversations($limit = 50, $offset = 0, $search = null) {
        try {
            $query = "SELECT cc.*, 
                     cu.full_name as customer_name, cu.email as customer_email,
                     COALESCE(NULLIF(TRIM(su.business_name), ''), su.full_name, 'Store') as seller_name,
                     pu.full_name as platform_name,
                     ru.full_name as rider_name,
                     (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id) as message_count,
                     cc.last_message_time,
                     (SELECT SUBSTRING(message_text, 1, 100) FROM chat_messages WHERE conversation_id = cc.id ORDER BY id DESC LIMIT 1) as last_message_preview,
                     (SELECT 1 FROM chat_typing_indicators cti WHERE cti.conversation_id = cc.id AND cti.user_id = cc.customer_id AND cti.created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND) LIMIT 1) as is_typing
                     FROM chat_conversations cc
                     LEFT JOIN users cu ON cc.customer_id = cu.id
                     LEFT JOIN users su ON su.id = cc.seller_id
                     LEFT JOIN users pu ON pu.id = cc.platform_owner_id
                     LEFT JOIN users ru ON ru.id = cc.rider_user_id
                     WHERE cc.assigned_agent_id IS NULL AND cc.status != 'closed'
                     ORDER BY cc.created_at ASC";
            
            if ($search) {
                $search = $this->conn->real_escape_string($search);
                $query = str_replace("WHERE", "WHERE (cu.full_name LIKE '%$search%' OR cu.email LIKE '%$search%' OR cc.subject LIKE '%$search%') AND", $query);
            }
            
            $query .= " LIMIT ? OFFSET ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $conversations = [];
            while ($row = $result->fetch_assoc()) {
                $conversations[] = $this->hydrateConversationRow($row, 0);
            }
            
            return $conversations;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Link Conversation to Order
     */
    public function linkConversationToOrder($conversation_id, $order_id) {
        try {
            $party_data = $this->getOrderPartyData((int)$order_id);
            $seller_id = (int)($party_data['seller_id'] ?? 0);
            $rider_user_id = (int)($party_data['rider_user_id'] ?? 0);
            $platform_owner_id = $this->getDefaultPlatformOwnerId();

            $query = "UPDATE chat_conversations 
                     SET order_id = ?,
                         seller_id = NULLIF(?, 0),
                         platform_owner_id = COALESCE(NULLIF(platform_owner_id, 0), NULLIF(?, 0)),
                         rider_user_id = NULLIF(?, 0),
                         entity_type = 'order',
                         conversation_type = 'order_tracking',
                         conversation_channel = CASE
                             WHEN ? > 0 THEN 'customer_store'
                             ELSE conversation_channel
                         END
                     WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iiiiii", $order_id, $seller_id, $platform_owner_id, $rider_user_id, $seller_id, $conversation_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to link conversation to order: " . $stmt->error);
            }

            $this->syncConversationMembers((int)$conversation_id);
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Link Conversation to Refund
     */
    public function linkConversationToRefund($conversation_id, $refund_id, $order_id) {
        try {
            $platform_owner_id = $this->getDefaultPlatformOwnerId();
            $query = "UPDATE chat_conversations 
                     SET refund_id = ?,
                         order_id = ?,
                         platform_owner_id = COALESCE(NULLIF(platform_owner_id, 0), NULLIF(?, 0)),
                         entity_type = 'refund',
                         conversation_type = 'refund_inquiry'
                     WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iiii", $refund_id, $order_id, $platform_owner_id, $conversation_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to link conversation to refund: " . $stmt->error);
            }

            $this->syncConversationMembers((int)$conversation_id);
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Order Details for Chat Context
     */
    public function getOrderContext($order_id) {
        try {
            $query = "SELECT 
                     o.id, o.order_number, o.status as order_status, 
                     o.customer_name, o.customer_email, o.customer_phone,
                     o.delivery_address, o.delivery_date, o.delivery_time,
                     o.total_amount, o.subtotal, o.delivery_fee,
                     o.payment_status, o.delivery_option,
                     o.created_at as order_created_at,
                     o.actual_delivery_time,
                     lt.current_latitude, lt.current_longitude,
                     lt.driver_name, lt.driver_phone, lt.current_status as delivery_status,
                     lt.proof_of_delivery_path,
                     GROUP_CONCAT(CONCAT(oi.product_name, ' (Qty: ', oi.quantity, ')') SEPARATOR ', ') as items
                     FROM orders o
                     LEFT JOIN logistics_tracking lt ON o.id = lt.order_id
                     LEFT JOIN order_items oi ON o.id = oi.order_id
                     WHERE o.id = ?
                     GROUP BY o.id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Refund Details for Chat Context
     */
    public function getRefundContext($refund_id) {
        try {
            $query = "SELECT 
                     r.id, r.refund_amount, r.refund_status,
                     r.computed_rule, r.percentage,
                     r.remarks, r.refund_reason, r.customer_evidence_path,
                     r.processed_by, r.processed_date,
                     r.created_at as refund_created_at,
                     c.id as cancellation_id, c.reason as cancellation_reason,
                     c.status as cancellation_status,
                     c.rejection_reason,
                     o.id as order_id, o.order_number, o.status as order_status,
                     o.total_amount, o.customer_name, o.customer_email
                     FROM refunds r
                     LEFT JOIN cancellations c ON r.cancellation_id = c.id
                     LEFT JOIN orders o ON c.order_id = o.id
                     WHERE r.id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $refund_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Customer's Orders for Chat Reference
     */
    public function getCustomerOrders($customer_id, $limit = 10) {
        try {
            $query = "SELECT 
                     id, order_number, status, total_amount,
                     delivery_date, created_at,
                     (SELECT SUBSTRING(GROUP_CONCAT(product_name SEPARATOR ', '), 1, 50) 
                      FROM order_items WHERE order_id = orders.id) as items
                     FROM orders
                     WHERE user_id = ?
                     ORDER BY created_at DESC
                     LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $customer_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $orders = [];
            while ($row = $result->fetch_assoc()) {
                $orders[] = $row;
            }
            
            return $orders;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Create Refund Request from Chat
     */
    public function createRefundRequestFromChat($conversation_id, $order_id, $requested_by, $reason, $screenshots = null) {
        try {
            // Check if order exists and belongs to customer
            $order_query = "SELECT id, user_id, total_amount, status FROM orders WHERE id = ?";
            $stmt = $this->conn->prepare($order_query);
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            
            if (!$order) {
                throw new Exception("Order not found");
            }
            
            if ($order['user_id'] != $requested_by && $_SESSION['user_type'] !== 'admin') {
                throw new Exception("Unauthorized to request refund for this order");
            }
            
            // Check if cancellation exists
            $cancellation_query = "SELECT id FROM cancellations WHERE order_id = ? AND status IN ('Requested', 'Cancelled')";
            $stmt = $this->conn->prepare($cancellation_query);
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $existing_cancellation = $stmt->get_result()->fetch_assoc();
            
            if (!$existing_cancellation) {
                throw new Exception("Cancellation request must be made first");
            }
            
            // Insert into chat_refund_requests
            $insert_query = "INSERT INTO chat_refund_requests 
                           (conversation_id, order_id, requested_by, reason, screenshot_paths, status, created_at)
                           VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
            
            $stmt = $this->conn->prepare($insert_query);
            $screenshot_json = $screenshots ? json_encode($screenshots) : null;
            $stmt->bind_param("iisss", $conversation_id, $order_id, $requested_by, $reason, $screenshot_json);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create refund request: " . $stmt->error);
            }
            
            $refund_request_id = $this->conn->insert_id;
            
            // Send system message to conversation
            $system_message = "Refund request submitted for order #{$order['id']}. Amount: PHP " . number_format($order['total_amount'], 2) . ". Status: Pending Review";
            $this->sendMessage($conversation_id, $requested_by, 'system', $system_message);
            
            return [
                'id' => $refund_request_id,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Quick Response Templates
     */
    public function getQuickResponses($category = null, $agent_id = null) {
        try {
            $query = "SELECT * FROM chat_quick_responses 
                     WHERE is_active = 1";
            
            if ($category) {
                $query .= " AND category = '" . $this->conn->real_escape_string($category) . "'";
            }
            
            if ($agent_id) {
                $query .= " AND (is_global = 1 OR agent_id = " . intval($agent_id) . ")";
            } else {
                $query .= " AND is_global = 1";
            }
            
            $query .= " ORDER BY category, title";
            
            $result = $this->conn->query($query);
            $responses = [];
            
            while ($row = $result->fetch_assoc()) {
                $responses[] = $row;
            }
            
            return $responses;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return [];
        }
    }
    
    /**
     * Escalate Conversation
     */
    public function escalateConversation($conversation_id, $reason = null, $escalated_by = null) {
        try {
            $query = "UPDATE chat_conversations 
                     SET is_escalated = 1, escalated_at = NOW()";
            
            if ($reason) {
                $reason_escaped = $this->conn->real_escape_string($reason);
                $query .= ", escalated_reason = '$reason_escaped'";
            }
            
            $query .= " WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $conversation_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to escalate conversation: " . $stmt->error);
            }
            
            // Add activity log
            $this->logActivity($conversation_id, 'escalated', $escalated_by, 'Conversation escalated', 'false', 'true');
            
            // Send notification to admins
            $this->notifyAdminsEscalation($conversation_id, $reason);
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Log Activity
     */
    public function logActivity($conversation_id, $activity_type, $user_id, $description, $old_value = null, $new_value = null) {
        try {
            $query = "INSERT INTO chat_activity_log 
                     (conversation_id, activity_type, user_id, action_description, old_value, new_value, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("isssss", $conversation_id, $activity_type, $user_id, $description, $old_value, $new_value);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to log activity: " . $stmt->error);
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Notify Admins of Escalation
     */
    private function notifyAdminsEscalation($conversation_id, $reason = null) {
        try {
            // Get all admin users
            $admin_query = "SELECT id FROM users WHERE user_type = 'admin'";
            $result = $this->conn->query($admin_query);
            
            while ($admin = $result->fetch_assoc()) {
                $notif_query = "INSERT INTO chat_notifications 
                              (conversation_id, user_id, notification_type, is_read, created_at)
                              VALUES (?, ?, 'conversation_update', 0, NOW())";
                
                $stmt = $this->conn->prepare($notif_query);
                $stmt->bind_param("ii", $conversation_id, $admin['id']);
                $stmt->execute();
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }

    /**
     * Get raw conversation row.
     */
    public function getConversationById($conversation_id) {
        try {
            $query = "SELECT *
                     FROM chat_conversations
                     WHERE id = ?
                     LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $conversation_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $conversation = $result->fetch_assoc() ?: null;
            if (!$conversation) {
                return null;
            }
            return $this->ensureConversationPlatformOwner((int)$conversation_id, $conversation);
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return null;
        }
    }

    /**
     * Return platform owner/system owner user IDs.
     */
    public function getPlatformOwnerIds() {
        try {
            $query = "SELECT DISTINCT u.id
                     FROM users u
                     LEFT JOIN roles r ON u.role_id = r.id
                     WHERE u.user_type = 'admin'
                     AND u.is_active = 1
                     AND (
                         r.name = 'super_admin'
                         OR r.name LIKE '%owner%'
                     )";
            $result = $this->conn->query($query);
            $ids = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $ids[] = (int)$row['id'];
                }
            }

            if (empty($ids)) {
                // Fallback: at least notify all admins.
                $fallback = $this->conn->query("SELECT id FROM users WHERE user_type = 'admin' AND is_active = 1");
                if ($fallback) {
                    while ($row = $fallback->fetch_assoc()) {
                        $ids[] = (int)$row['id'];
                    }
                }
            }

            return array_values(array_unique(array_filter($ids)));
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return [];
        }
    }

    /**
     * Get all active support users (shop admins/employees + platform admins).
     */
    private function getSupportUserIds() {
        $ids = [];
        $query = "SELECT id
                 FROM users
                 WHERE user_type IN ('admin', 'employee')
                 AND is_active = 1";
        $result = $this->conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int)$row['id'];
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Create per-user chat notifications for message fanout.
     */
    private function notifyMessageRecipients($conversation_id, array $conversation, $sender_id, $sender_type) {
        try {
            $recipients = [];
            $notification_type_map = [
                'customer' => 'customer_message',
                'store' => 'store_message',
                'platform' => 'platform_message',
                'rider' => 'delivery_message',
                'agent' => 'agent_message',
            ];
            $notification_type = $notification_type_map[$sender_type] ?? 'new_message';
            $is_escalated = (int)($conversation['is_escalated'] ?? 0) === 1;

            $this->syncConversationMembers((int)$conversation_id);
            if ($this->tableExists('chat_conversation_members')) {
                $result = $this->conn->query(
                    "SELECT user_id
                     FROM chat_conversation_members
                     WHERE conversation_id = " . (int)$conversation_id . "
                       AND is_active = 1"
                );
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $recipients[] = (int)($row['user_id'] ?? 0);
                    }
                }
            }

            if (empty($recipients)) {
                $customer_id = (int)($conversation['customer_id'] ?? 0);
                $assigned_agent_id = isset($conversation['assigned_agent_id']) ? (int)$conversation['assigned_agent_id'] : 0;
                $seller_id = (int)($conversation['seller_id'] ?? 0);
                $platform_owner_id = (int)($conversation['platform_owner_id'] ?? 0);
                $rider_user_id = (int)($conversation['rider_user_id'] ?? 0);
                $recipients = array_merge($recipients, [$customer_id, $assigned_agent_id, $seller_id, $platform_owner_id, $rider_user_id]);
            }

            if ($sender_type === 'customer' && empty($conversation['assigned_agent_id'])) {
                $recipients = array_merge($recipients, $this->getSupportUserIds());
            }
            if ($is_escalated) {
                $recipients = array_merge($recipients, $this->getPlatformOwnerIds());
            }

            $recipients = array_values(array_unique(array_filter(array_map('intval', $recipients))));
            if (empty($recipients)) {
                return true;
            }

            $query = "INSERT INTO chat_notifications
                     (conversation_id, user_id, notification_type, is_read, created_at)
                     VALUES (?, ?, ?, 0, NOW())";
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                return false;
            }

            foreach ($recipients as $recipient_id) {
                if ($recipient_id <= 0 || $recipient_id === (int)$sender_id) {
                    continue;
                }
                $stmt->bind_param("iis", $conversation_id, $recipient_id, $notification_type);
                $stmt->execute();
            }

            return true;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Get Errors
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Get Last Error
     */
    public function getLastError() {
        return !empty($this->errors) ? end($this->errors) : null;
    }
}
?>

