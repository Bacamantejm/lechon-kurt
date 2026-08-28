@extends('layouts.app')

@section('content')
<?php
global $conn;
if (!isset($conn) || !($conn instanceof \mysqli)) {
    require_once base_path('includes/config.php');
    if (!isset($conn) || !($conn instanceof \mysqli)) {
        $conn = @mysqli_connect(
            defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '127.0.0.1'),
            defined('DB_USER') ? DB_USER : (getenv('DB_USERNAME') ?: 'root'),
            defined('DB_PASS') ? DB_PASS : (getenv('DB_PASSWORD') ?: ''),
            defined('DB_NAME') ? DB_NAME : (getenv('DB_DATABASE') ?: 'lechon_db')
        );
    }
}
$user_id = auth()->id() ?? (int)($_SESSION['user_id'] ?? 1);
if (empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $user_id;
}
$flash_message = session('success', '');
$form_error = session('error', '');
require_once base_path('includes/config.php');
require_once base_path('includes/security.php');
require_once base_path('includes/ChatService.php');

function helpCenterTableExists(mysqli $conn, string $table_name): bool {
    $table_name = trim($table_name);
    if ($table_name === '') {
        return false;
    }

    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    return $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
}

function helpCenterColumnExists(mysqli $conn, string $table_name, string $column_name): bool {
    $table_name = trim($table_name);
    $column_name = trim($column_name);
    if ($table_name === '' || $column_name === '') {
        return false;
    }

    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    return $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
}

function helpCenterIndexExists(mysqli $conn, string $table_name, string $index_name): bool {
    $table_name = trim($table_name);
    $index_name = trim($index_name);
    if ($table_name === '' || $index_name === '') {
        return false;
    }

    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_index = mysqli_real_escape_string($conn, $index_name);
    $result = mysqli_query($conn, "SHOW INDEX FROM `{$safe_table}` WHERE Key_name = '{$safe_index}'");
    return $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
}

function helpCenterEnsureComplaintShopColumn(mysqli $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (!helpCenterTableExists($conn, 'chat_conversations')) {
        return $ready = false;
    }

    if (!helpCenterColumnExists($conn, 'chat_conversations', 'shop_user_id')) {
        @mysqli_query($conn, "ALTER TABLE chat_conversations ADD COLUMN shop_user_id INT NULL AFTER order_id");
    }
    if (!helpCenterColumnExists($conn, 'chat_conversations', 'shop_user_id')) {
        return $ready = false;
    }

    if (!helpCenterIndexExists($conn, 'chat_conversations', 'idx_chat_conversations_shop_user_id')) {
        @mysqli_query($conn, "ALTER TABLE chat_conversations ADD INDEX idx_chat_conversations_shop_user_id (shop_user_id)");
    }
    return $ready = true;
}

function helpCenterNormalizeCategory($value): string {
    $value = strtolower(trim((string)$value));
    $allowed = ['order', 'shop', 'system', 'general'];
    return in_array($value, $allowed, true) ? $value : 'general';
}

function helpCenterNormalizePriority($value): string {
    $value = strtolower(trim((string)$value));
    $allowed = ['low', 'medium', 'high', 'urgent'];
    return in_array($value, $allowed, true) ? $value : 'medium';
}

function helpCenterCategoryLabel(string $category): string {
    $labels = [
        'order' => 'Order Problem',
        'shop' => 'Shop Concern',
        'system' => 'System Error',
        'general' => 'General Help'
    ];
    return $labels[$category] ?? 'General Help';
}

function helpCenterCategoryPrefix(string $category): string {
    if ($category === 'system') {
        return '[TECHNICAL]';
    }
    if (in_array($category, ['order', 'shop'], true)) {
        return '[BUSINESS]';
    }
    return '[GENERAL]';
}

function helpCenterDefaultSubject(string $category, ?array $order_row = null, ?array $shop_row = null): string {
    $label = helpCenterCategoryLabel($category);
    if ($order_row && !empty($order_row['order_number'])) {
        return $label . ' for Order #' . $order_row['order_number'];
    }
    if ($shop_row && !empty($shop_row['shop_name'])) {
        return $label . ' for ' . (string)$shop_row['shop_name'];
    }
    return $label . ' Request';
}

function helpCenterBuildInitialMessage(string $category, string $priority, ?array $order_row, ?array $shop_row, string $details): string {
    $lines = [
        'Help Center ticket submitted.',
        'Issue type: ' . helpCenterCategoryLabel($category),
        'Priority: ' . ucfirst($priority)
    ];

    if ($shop_row && !empty($shop_row['shop_name'])) {
        $lines[] = 'Business partner shop: ' . (string)$shop_row['shop_name'];
    }

    if ($order_row) {
        $lines[] = 'Order number: ' . (string)($order_row['order_number'] ?? '');
        $lines[] = 'Order status: ' . ucfirst((string)($order_row['status'] ?? 'pending'));
    }

    $lines[] = '';
    $lines[] = trim($details);

    return trim(implode("\n", $lines));
}

function helpCenterFetchOrder(mysqli $conn, int $user_id, int $order_id): ?array {
    if ($order_id <= 0) {
        return null;
    }

    $shop_user_expr = 'NULL';
    $join_sql = '';

    if (
        helpCenterTableExists($conn, 'partner_voucher_redemptions')
        && helpCenterColumnExists($conn, 'partner_voucher_redemptions', 'order_id')
        && helpCenterColumnExists($conn, 'partner_voucher_redemptions', 'seller_id')
    ) {
        $join_sql .= "
            LEFT JOIN (
                SELECT order_id, MAX(seller_id) AS seller_id
                FROM partner_voucher_redemptions
                GROUP BY order_id
            ) pvr ON pvr.order_id = o.id";
        $shop_user_expr = 'pvr.seller_id';
    }

    if (helpCenterTableExists($conn, 'order_items') && helpCenterTableExists($conn, 'products')) {
        $join_sql .= "
            LEFT JOIN (
                SELECT oi.order_id, MAX(p.seller_id) AS seller_id
                FROM order_items oi
                LEFT JOIN products p
                  ON p.product_id = oi.product_id
                  OR CAST(p.id AS CHAR) = oi.product_id
                GROUP BY oi.order_id
            ) oi_shop ON oi_shop.order_id = o.id";
        $shop_user_expr = $shop_user_expr === 'NULL'
            ? 'oi_shop.seller_id'
            : "COALESCE({$shop_user_expr}, oi_shop.seller_id)";
    }

    $query = "SELECT
                o.id,
                o.order_number,
                o.status,
                o.total_amount,
                o.created_at,
                {$shop_user_expr} AS shop_user_id,
                COALESCE(
                    NULLIF(TRIM(shop.business_name), ''),
                    NULLIF(TRIM(shop.full_name), ''),
                    CASE WHEN {$shop_user_expr} IS NOT NULL THEN CONCAT('Shop #', {$shop_user_expr}) ELSE '' END
                ) AS shop_name
              FROM orders o
              {$join_sql}
              LEFT JOIN users shop ON shop.id = {$shop_user_expr}
              WHERE o.id = ? AND o.user_id = ?
              LIMIT 1";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "ii", $order_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function helpCenterFetchPartnerShops(mysqli $conn, int $limit = 60): array {
    $shops = [];
    if (!helpCenterTableExists($conn, 'users')) {
        return $shops;
    }

    $query = "SELECT u.id,
                     COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(fa.business_name), ''), NULLIF(TRIM(u.full_name), ''), CONCAT('Shop #', u.id)) AS shop_name,
                     u.email,
                     u.business_type
              FROM users u
              LEFT JOIN franchise_applications fa
                ON fa.user_id = u.id
               AND fa.status = 'approved'
              WHERE u.is_active = 1
                AND u.account_type = 'organization'
                AND (fa.id IS NOT NULL OR u.user_type = 'admin')
              GROUP BY u.id, shop_name, u.email, u.business_type
              ORDER BY shop_name ASC
              LIMIT ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return $shops;
    }

    mysqli_stmt_bind_param($stmt, "i", $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $shops[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $shops;
}

function helpCenterFetchPartnerShopById(mysqli $conn, int $shop_user_id): ?array {
    if ($shop_user_id <= 0) {
        return null;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT u.id,
                COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(fa.business_name), ''), NULLIF(TRIM(u.full_name), ''), CONCAT('Shop #', u.id)) AS shop_name,
                u.email,
                u.business_type
         FROM users u
         LEFT JOIN franchise_applications fa
           ON fa.user_id = u.id
          AND fa.status = 'approved'
         WHERE u.id = ?
           AND u.is_active = 1
           AND u.account_type = 'organization'
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $shop_user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function helpCenterFetchRecentOrders(mysqli $conn, int $user_id, int $limit = 8): array {
    $orders = [];
    if (!helpCenterTableExists($conn, 'orders')) {
        return $orders;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, order_number, status, total_amount, created_at
         FROM orders
         WHERE user_id = ? AND (is_archived IS NULL OR is_archived = 0)
         ORDER BY created_at DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return $orders;
    }

    mysqli_stmt_bind_param($stmt, "ii", $user_id, $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $orders[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $orders;
}

function helpCenterFetchRecentCases(mysqli $conn, int $user_id, int $limit = 8): array {
    $cases = [];
    if (!helpCenterTableExists($conn, 'chat_conversations') || !helpCenterTableExists($conn, 'chat_messages')) {
        return $cases;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT cc.id,
                cc.subject,
                cc.status,
                cc.priority,
                cc.order_id,
                cc.created_at,
                cc.last_message_time,
                cc.conversation_type,
                o.order_number,
                (
                    SELECT SUBSTRING(m.message_text, 1, 140)
                    FROM chat_messages m
                    WHERE m.conversation_id = cc.id
                    ORDER BY m.id DESC
                    LIMIT 1
                ) AS latest_message
         FROM chat_conversations cc
         LEFT JOIN orders o ON o.id = cc.order_id
         WHERE cc.customer_id = ?
         ORDER BY COALESCE(cc.last_message_time, cc.created_at) DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return $cases;
    }

    mysqli_stmt_bind_param($stmt, "ii", $user_id, $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $cases[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $cases;
}

function helpCenterResolveOwnerId(mysqli $conn): int {
    $chat_service = new ChatService($conn);
    $owner_ids = $chat_service->getPlatformOwnerIds();
    if (!empty($owner_ids)) {
        return (int)$owner_ids[0];
    }

    $result = mysqli_query(
        $conn,
        "SELECT id
         FROM users
         WHERE user_type = 'admin' AND is_active = 1
         ORDER BY id ASC
         LIMIT 1"
    );
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return (int)$row['id'];
    }

    return 0;
}

function helpCenterPrepareAttachment(?array $file): array {
    if (!$file || !isset($file['error'])) {
        return ['success' => true, 'selected' => false];
    }

    $upload_error = (int)$file['error'];
    if ($upload_error === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'selected' => false];
    }

    if ($upload_error !== UPLOAD_ERR_OK) {
        $message = 'Unable to process the uploaded file.';
        switch ($upload_error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $message = 'Attachment is too large. Maximum file size is 10MB.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $message = 'Attachment upload was interrupted. Please try again.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
                $message = 'Server cannot accept attachments right now. Please try again later.';
                break;
        }
        return ['success' => false, 'error' => $message];
    }

    $tmp_name = (string)($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        return ['success' => false, 'error' => 'Invalid attachment upload source.'];
    }

    $file_size = (int)($file['size'] ?? 0);
    if ($file_size <= 0) {
        return ['success' => false, 'error' => 'The uploaded attachment is empty.'];
    }
    if ($file_size > (10 * 1024 * 1024)) {
        return ['success' => false, 'error' => 'Attachment is too large. Maximum file size is 10MB.'];
    }

    $allowed_mime_types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return ['success' => false, 'error' => 'Unable to inspect the uploaded attachment.'];
    }

    $mime_type = (string)finfo_file($finfo, $tmp_name);
    finfo_close($finfo);

    if (!isset($allowed_mime_types[$mime_type])) {
        return ['success' => false, 'error' => 'Unsupported file type. Please upload PNG, JPG, GIF, WEBP, PDF, DOC, or DOCX.'];
    }

    return [
        'success' => true,
        'selected' => true,
        'tmp_name' => $tmp_name,
        'size' => $file_size,
        'mime_type' => $mime_type,
        'extension' => $allowed_mime_types[$mime_type],
        'original_name' => trim((string)($file['name'] ?? 'attachment'))
    ];
}

function helpCenterStorePreparedAttachment(ChatService $chat_service, int $conversation_id, int $message_id, int $user_id, array $prepared_attachment): array {
    if (empty($prepared_attachment['selected'])) {
        return ['success' => true, 'stored' => false];
    }

    $upload_dir = __DIR__ . '/uploads/chat_attachments';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        return ['success' => false, 'error' => 'Unable to prepare the attachment upload folder.'];
    }

    try {
        $token = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $token = str_replace('.', '', uniqid((string)$conversation_id, true));
    }

    $safe_file_name = 'chat_' . $conversation_id . '_' . $user_id . '_' . date('YmdHis') . '_' . $token . '.' . $prepared_attachment['extension'];
    $absolute_path = $upload_dir . '/' . $safe_file_name;
    $relative_path = 'uploads/chat_attachments/' . $safe_file_name;

    if (!move_uploaded_file($prepared_attachment['tmp_name'], $absolute_path)) {
        return ['success' => false, 'error' => 'Failed to save the uploaded attachment.'];
    }

    $attachment_id = $chat_service->storeAttachment(
        $message_id,
        $prepared_attachment['original_name'],
        $relative_path,
        $prepared_attachment['extension'],
        (int)$prepared_attachment['size'],
        $prepared_attachment['mime_type'],
        $user_id
    );

    if (!$attachment_id) {
        @unlink($absolute_path);
        return ['success' => false, 'error' => $chat_service->getLastError()];
    }

    return ['success' => true, 'stored' => true];
}

$user_id = (int)$_SESSION['user_id'];
$current_page = 'help_center';
$page_title = 'Help Center | Lechon Delights';

$has_chat_tables = helpCenterTableExists($conn, 'chat_conversations') && helpCenterTableExists($conn, 'chat_messages');
$has_shop_column = helpCenterEnsureComplaintShopColumn($conn);
$partner_shops = helpCenterFetchPartnerShops($conn, 80);
$recent_orders = helpCenterFetchRecentOrders($conn, $user_id, 8);
$recent_cases = helpCenterFetchRecentCases($conn, $user_id, 8);

$issue_type = helpCenterNormalizeCategory($_GET['category'] ?? 'order');
$priority = helpCenterNormalizePriority($_GET['priority'] ?? 'medium');
$selected_order_id = max(0, (int)($_GET['order_id'] ?? 0));
$selected_shop_id = max(0, (int)($_GET['shop_user_id'] ?? 0));
$subject_input = trim((string)($_GET['subject'] ?? ''));
$details_input = '';
$form_error = '';
$flash_message = (string)($_SESSION['help_center_flash'] ?? '');
unset($_SESSION['help_center_flash']);

$selected_order = helpCenterFetchOrder($conn, $user_id, $selected_order_id);
if (!$selected_order) {
    $selected_order_id = 0;
}
$selected_shop = helpCenterFetchPartnerShopById($conn, $selected_shop_id);
if (!$selected_shop) {
    $selected_shop_id = 0;
}
if ($selected_order && !$selected_shop && !empty($selected_order['shop_user_id'])) {
    $selected_shop_id = (int)$selected_order['shop_user_id'];
    $selected_shop = helpCenterFetchPartnerShopById($conn, $selected_shop_id);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $issue_type = helpCenterNormalizeCategory($_POST['issue_type'] ?? 'general');
    $priority = helpCenterNormalizePriority($_POST['priority'] ?? 'medium');
    $selected_order_id = max(0, (int)($_POST['order_id'] ?? 0));
    $selected_shop_id = max(0, (int)($_POST['shop_user_id'] ?? 0));
    $subject_input = trim((string)($_POST['subject'] ?? ''));
    $details_input = trim((string)($_POST['details'] ?? ''));
    $prepared_attachment = helpCenterPrepareAttachment($_FILES['attachment'] ?? null);

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $form_error = 'Your session expired. Please refresh the page and try again.';
    } elseif (!$has_chat_tables) {
        $form_error = 'Help Center is not ready because the support tables are unavailable.';
    } elseif (!$prepared_attachment['success']) {
        $form_error = (string)($prepared_attachment['error'] ?? 'Unable to validate the selected attachment.');
    } elseif ($details_input === '') {
        $form_error = 'Please describe the issue so the team can help you properly.';
    } else {
        $selected_order = helpCenterFetchOrder($conn, $user_id, $selected_order_id);
        $selected_shop = helpCenterFetchPartnerShopById($conn, $selected_shop_id);
        if ($selected_order_id > 0 && !$selected_order) {
            $form_error = 'The selected order could not be linked to your account.';
        } elseif ($selected_shop_id > 0 && !$selected_shop) {
            $form_error = 'The selected shop could not be found.';
        } elseif ($selected_order && !$selected_shop && !empty($selected_order['shop_user_id'])) {
            $selected_shop_id = (int)$selected_order['shop_user_id'];
            $selected_shop = helpCenterFetchPartnerShopById($conn, $selected_shop_id);
        } elseif ($issue_type === 'shop' && !$selected_shop) {
            $form_error = 'Please select the business partner shop related to this complaint.';
        } else {
            $resolved_subject = $subject_input !== ''
                ? $subject_input
                : helpCenterDefaultSubject($issue_type, $selected_order, $selected_shop);
            $resolved_subject = substr(helpCenterCategoryPrefix($issue_type) . ' ' . $resolved_subject, 0, 255);

            $message_text = helpCenterBuildInitialMessage($issue_type, $priority, $selected_order, $selected_shop, $details_input);
            $safe_subject = mysqli_real_escape_string($conn, $resolved_subject);
            $safe_priority = mysqli_real_escape_string($conn, $priority);
            $safe_entity_type = $issue_type === 'shop'
                ? 'shop'
                : ($selected_order ? 'order' : ($issue_type === 'system' ? 'system' : 'general'));
            $safe_order_id = $selected_order ? (int)$selected_order['id'] : 'NULL';
            $safe_shop_user_id = $selected_shop ? (int)$selected_shop['id'] : 'NULL';
            $assigned_agent_id = helpCenterResolveOwnerId($conn);
            $safe_agent_id = $assigned_agent_id > 0 ? $assigned_agent_id : 'NULL';

            if ($has_shop_column) {
                $insert_conversation_sql = "INSERT INTO chat_conversations
                    (customer_id, assigned_agent_id, order_id, shop_user_id, entity_type, conversation_type, subject, status, priority, first_message_time, last_message_time, created_at, updated_at)
                    VALUES
                    ({$user_id}, {$safe_agent_id}, {$safe_order_id}, {$safe_shop_user_id}, '{$safe_entity_type}', 'complaint', '{$safe_subject}', 'open', '{$safe_priority}', NOW(), NOW(), NOW(), NOW())";
            } else {
                $insert_conversation_sql = "INSERT INTO chat_conversations
                    (customer_id, assigned_agent_id, order_id, entity_type, conversation_type, subject, status, priority, first_message_time, last_message_time, created_at, updated_at)
                    VALUES
                    ({$user_id}, {$safe_agent_id}, {$safe_order_id}, '{$safe_entity_type}', 'complaint', '{$safe_subject}', 'open', '{$safe_priority}', NOW(), NOW(), NOW(), NOW())";
            }

            if (mysqli_query($conn, $insert_conversation_sql)) {
                $conversation_id = (int)mysqli_insert_id($conn);
                $message_stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO chat_messages
                     (conversation_id, sender_id, sender_type, message_text, message_type, created_at, updated_at)
                     VALUES (?, ?, 'customer', ?, 'text', NOW(), NOW())"
                );

                if ($message_stmt) {
                    mysqli_stmt_bind_param($message_stmt, "iis", $conversation_id, $user_id, $message_text);
                    $message_ok = mysqli_stmt_execute($message_stmt);
                    $message_id = $message_ok ? (int)mysqli_insert_id($conn) : 0;
                    mysqli_stmt_close($message_stmt);

                    if ($message_ok) {
                        $chat_service = new ChatService($conn);
                        $attachment_result = helpCenterStorePreparedAttachment(
                            $chat_service,
                            $conversation_id,
                            $message_id,
                            $user_id,
                            $prepared_attachment
                        );

                        if (!$attachment_result['success']) {
                            mysqli_query($conn, "DELETE FROM chat_messages WHERE id = {$message_id} LIMIT 1");
                            mysqli_query($conn, "DELETE FROM chat_conversations WHERE id = {$conversation_id} LIMIT 1");
                            $form_error = (string)($attachment_result['error'] ?? 'Unable to save the attachment.');
                        } else {
                            if (in_array($issue_type, ['shop', 'system'], true) || $priority === 'urgent') {
                                $chat_service->escalateConversation($conversation_id, 'Help Center ticket marked for priority review.', $user_id);
                            }

                            $_SESSION['help_center_flash'] = 'Your help request has been submitted. You can continue the conversation below.';
                            header('Location: customer_chat.php?conversation_id=' . $conversation_id);
                            exit;
                        }
                    }
                }

                if ($form_error === '') {
                    mysqli_query($conn, "DELETE FROM chat_conversations WHERE id = {$conversation_id} LIMIT 1");
                    $form_error = 'The ticket was created but the message could not be saved. Please try again.';
                }
            } else {
                $form_error = 'Unable to create your help request right now. Please try again in a moment.';
            }
        }
    }
}

?>
?>
<section class="help-center-section">
    <div class="container">
        <?php if ($flash_message !== ''): ?>
            <div class="help-alert success"><?php echo htmlspecialchars($flash_message); ?></div>
        <?php endif; ?>

        <?php if ($form_error !== ''): ?>
            <div class="help-alert error"><?php echo htmlspecialchars($form_error); ?></div>
        <?php endif; ?>

        <?php if (!$has_chat_tables): ?>
            <div class="help-alert error">Support tables are not available yet, so the Help Center cannot submit tickets from this page.</div>
        <?php endif; ?>

        <div class="help-layout">
            <div class="help-main">
                <div class="help-card">
                    <div class="help-card-head">
                        <div>
                            <h2>Report an issue</h2>
                            <p>Pick the closest category so we can route your case to the right team faster.</p>
                        </div>
                        <a class="help-secondary-link" href="customer_chat.php">Open existing support chat</a>
                    </div>

                    <div class="issue-cards" id="issueCards">
                        <?php foreach (['order', 'shop', 'system', 'general'] as $category_key): ?>
                            <button
                                type="button"
                                class="issue-card<?php echo $issue_type === $category_key ? ' active' : ''; ?>"
                                data-category="<?php echo htmlspecialchars($category_key); ?>"
                                data-default-subject="<?php echo htmlspecialchars(helpCenterDefaultSubject($category_key, $selected_order)); ?>"
                            >
                                <span class="issue-card-icon">
                                    <?php if ($category_key === 'order'): ?><i class="fas fa-box"></i><?php endif; ?>
                                    <?php if ($category_key === 'shop'): ?><i class="fas fa-store"></i><?php endif; ?>
                                    <?php if ($category_key === 'system'): ?><i class="fas fa-triangle-exclamation"></i><?php endif; ?>
                                    <?php if ($category_key === 'general'): ?><i class="fas fa-life-ring"></i><?php endif; ?>
                                </span>
                                <strong><?php echo htmlspecialchars(helpCenterCategoryLabel($category_key)); ?></strong>
                                <span>
                                    <?php if ($category_key === 'order'): ?>Delivery, refund, missing item, or wrong order<?php endif; ?>
                                    <?php if ($category_key === 'shop'): ?>Store behavior, fulfillment, or service quality<?php endif; ?>
                                    <?php if ($category_key === 'system'): ?>Checkout bugs, errors, broken pages, or login issues<?php endif; ?>
                                    <?php if ($category_key === 'general'): ?>Questions, account help, or anything else<?php endif; ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <form method="post" class="help-form" enctype="multipart/form-data" novalidate>
                        <?php echo getCSRFTokenField(); ?>
                        <input type="hidden" name="issue_type" id="issueTypeInput" value="<?php echo htmlspecialchars($issue_type); ?>">

                        <div class="help-form-grid">
                            <div class="form-field">
                                <label for="priority">Priority</label>
                                <select name="priority" id="priority">
                                    <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority_option): ?>
                                        <option value="<?php echo $priority_option; ?>" <?php echo $priority === $priority_option ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($priority_option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-field" id="orderFieldWrap">
                                <label for="order_id">Related order</label>
                                <select name="order_id" id="order_id">
                                    <option value="0">No order to attach</option>
                                    <?php foreach ($recent_orders as $order): ?>
                                        <option
                                            value="<?php echo (int)$order['id']; ?>"
                                            data-shop-id="<?php echo (int)($order['shop_user_id'] ?? 0); ?>"
                                            data-shop-name="<?php echo htmlspecialchars((string)($order['shop_name'] ?? ''), ENT_QUOTES); ?>"
                                            <?php echo $selected_order_id === (int)$order['id'] ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($order['order_number']); ?> Â· <?php echo htmlspecialchars(ucfirst((string)$order['status'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small id="orderFieldHint">Attach an order if this issue is tied to a specific purchase.</small>
                            </div>

                            <div class="form-field" id="shopFieldWrap">
                                <label for="shop_user_id">Business partner shop</label>
                                <select name="shop_user_id" id="shop_user_id">
                                    <option value="0">Select shop</option>
                                    <?php foreach ($partner_shops as $shop): ?>
                                        <option value="<?php echo (int)$shop['id']; ?>" <?php echo $selected_shop_id === (int)$shop['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$shop['shop_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small id="shopFieldHint">Choose the business partner shop this complaint is about.</small>
                            </div>
                        </div>

                        <?php if ($selected_order): ?>
                            <div class="selected-order-banner" id="selectedOrderBanner">
                                <div>
                                    <strong>Linked order:</strong> #<?php echo htmlspecialchars((string)$selected_order['order_number']); ?>
                                    <span class="order-chip"><?php echo htmlspecialchars(ucfirst((string)$selected_order['status'])); ?></span>
                                </div>
                                <span>
                                    <?php if (!empty($selected_order['shop_name'])): ?>Shop: <?php echo htmlspecialchars((string)$selected_order['shop_name']); ?> Â· <?php endif; ?>
                                    Placed <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$selected_order['created_at']))); ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <div class="selected-order-banner hidden" id="selectedOrderBanner"></div>
                        <?php endif; ?>

                        <div class="form-field">
                            <label for="subject">Subject</label>
                            <input
                                type="text"
                                name="subject"
                                id="subject"
                                maxlength="180"
                                placeholder="Summarize the problem"
                                value="<?php echo htmlspecialchars($subject_input); ?>"
                            >
                        </div>

                        <div class="form-field">
                            <label for="details">What happened?</label>
                            <textarea
                                name="details"
                                id="details"
                                rows="8"
                                placeholder="Share the issue, what you expected, what happened instead, and any timing/details that can help the team investigate."
                                required
                            ><?php echo htmlspecialchars($details_input); ?></textarea>
                        </div>

                        <div class="form-field">
                            <label for="attachment">Screenshot or file</label>
                            <label class="help-upload-box" for="attachment">
                                <input
                                    type="file"
                                    name="attachment"
                                    id="attachment"
                                    accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                >
                                <span class="help-upload-icon"><i class="fas fa-paperclip"></i></span>
                                <span class="help-upload-copy">
                                    <strong>Attach screenshot or document</strong>
                                    <span id="attachmentName">PNG, JPG, GIF, WEBP, PDF, DOC, or DOCX up to 10MB</span>
                                </span>
                            </label>
                            <small>Useful for broken page screenshots, payment proof, missing item photos, or order issue evidence.</small>
                        </div>

                        <div class="help-form-actions">
                            <button type="submit" class="btn-help-primary" <?php echo !$has_chat_tables ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane"></i> Submit And Open Support Chat
                            </button>
                            <a href="my_orders.php" class="btn-help-secondary">
                                <i class="fas fa-bag-shopping"></i> View My Orders
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <aside class="help-sidebar">
                <div class="help-card compact">
                    <h3>How this helps</h3>
                    <ul class="help-checklist">
                        <li><i class="fas fa-check-circle"></i> <span>Routes order and shop issues into your support conversation faster.</span></li>
                        <li><i class="fas fa-check-circle"></i> <span>Lets you attach the right order so staff can investigate with context.</span></li>
                        <li><i class="fas fa-check-circle"></i> <span>Flags urgent or system issues for priority review.</span></li>
                    </ul>
                </div>

                <div class="help-card compact">
                    <div class="help-card-head side">
                        <div>
                            <h3>Recent support cases</h3>
                            <p>Your latest conversations and complaint threads.</p>
                        </div>
                    </div>

                    <?php if (empty($recent_cases)): ?>
                        <div class="help-empty">
                            <i class="fas fa-comments"></i>
                            <p>No support cases yet. Your first request will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="case-list">
                            <?php foreach ($recent_cases as $case): ?>
                                <?php
                                $case_status = strtolower(trim((string)($case['status'] ?? 'open')));
                                $case_priority = strtolower(trim((string)($case['priority'] ?? 'medium')));
                                ?>
                                <a class="case-item" href="customer_chat.php?conversation_id=<?php echo (int)$case['id']; ?>">
                                    <div class="case-item-top">
                                        <strong>#<?php echo (int)$case['id']; ?></strong>
                                        <span class="case-status <?php echo htmlspecialchars($case_status); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $case_status))); ?>
                                        </span>
                                    </div>
                                    <div class="case-subject"><?php echo htmlspecialchars((string)$case['subject']); ?></div>
                                    <?php if (!empty($case['order_number'])): ?>
                                        <div class="case-meta">Order: <?php echo htmlspecialchars((string)$case['order_number']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($case['latest_message'])): ?>
                                        <div class="case-preview"><?php echo htmlspecialchars((string)$case['latest_message']); ?></div>
                                    <?php endif; ?>
                                    <div class="case-meta-row">
                                        <span class="case-priority"><?php echo htmlspecialchars(ucfirst($case_priority)); ?> priority</span>
                                        <span><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string)($case['last_message_time'] ?: $case['created_at'])))); ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="help-card compact">
                    <h3>Recent orders</h3>
                    <?php if (empty($recent_orders)): ?>
                        <div class="help-empty simple">
                            <p>No recent orders yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="quick-order-list">
                            <?php foreach ($recent_orders as $order): ?>
                                <a class="quick-order-item" href="help_center.php?category=order&order_id=<?php echo (int)$order['id']; ?>">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string)$order['order_number']); ?></strong>
                                        <span><?php echo htmlspecialchars(ucfirst((string)$order['status'])); ?></span>
                                    </div>
                                    <span>Report issue</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</section>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.help-center-section {
    padding: 32px 0 140px;
    background: #f8f9fa;
    min-height: 85vh;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

.help-center-section .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.help-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(290px, 0.95fr);
    gap: 24px;
    align-items: start;
}

.help-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    padding: 28px;
}

.help-card.compact {
    padding: 20px;
}

.help-card + .help-card {
    margin-top: 16px;
}

.help-card-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-bottom: 20px;
    border-bottom: 1px solid #f2f4f7;
    padding-bottom: 16px;
}

.help-card-head h2 {
    margin: 0 0 4px;
    color: #101828;
    font-family: 'Outfit', sans-serif;
    font-size: 1.35rem;
    font-weight: 700;
}

.help-card-head p {
    margin: 0;
    color: #667085;
    font-size: 0.88rem;
}

.help-card-head h3,
.help-card.compact h3 {
    margin: 0 0 4px;
    color: #101828;
    font-family: 'Outfit', sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
}

.help-secondary-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    background: #ffffff;
    border: 1px solid #d0d5dd;
    color: #344054;
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s ease;
    white-space: nowrap;
}

.help-secondary-link:hover {
    background: #f8f9fa;
    color: #101828;
}

.issue-cards {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}

.issue-card {
    width: 100%;
    border: 1px solid #eaecf0;
    background: #ffffff;
    border-radius: 12px;
    padding: 16px;
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.issue-card:hover {
    border-color: #d0d5dd;
    background: #f8f9fa;
}

.issue-card.active {
    border-color: #b3261e;
    background: #fff1f0;
}

.issue-card-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #f8f9fa;
    color: #667085;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    transition: all 0.15s ease;
}

.issue-card.active .issue-card-icon {
    background: #fee4e2;
    color: #b3261e;
}

.issue-card strong {
    color: #101828;
    font-size: 0.95rem;
    font-weight: 700;
}

.issue-card span:last-child {
    color: #475467;
    font-size: 0.82rem;
    line-height: 1.45;
}

.help-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.help-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-field label {
    font-weight: 600;
    color: #344054;
    font-size: 0.88rem;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    padding: 10px 14px;
    background: #ffffff;
    color: #101828;
    font-size: 0.9rem;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    border-color: #b3261e;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}

.form-field small {
    color: #667085;
    font-size: 0.78rem;
}

.help-upload-box {
    border: 1px dashed #d0d5dd;
    border-radius: 12px;
    padding: 14px 16px;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.help-upload-box:hover {
    border-color: #b3261e;
    background: #fff1f0;
}

.help-upload-box input[type="file"] {
    display: none;
}

.help-upload-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid #eaecf0;
    color: #667085;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.95rem;
}

.help-upload-box:hover .help-upload-icon {
    color: #b3261e;
    border-color: #ffccc7;
}

.help-upload-copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.help-upload-copy strong {
    color: #101828;
    font-size: 0.88rem;
}

.help-upload-copy span {
    color: #667085;
    font-size: 0.8rem;
}

.selected-order-banner {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    border: 1px solid #eaecf0;
    background: #f8f9fa;
    border-radius: 10px;
    padding: 12px 14px;
    color: #344054;
    font-size: 0.86rem;
}

.selected-order-banner.hidden {
    display: none;
}

.order-chip {
    display: inline-flex;
    align-items: center;
    margin-left: 8px;
    padding: 3px 8px;
    border-radius: 6px;
    background: #eff8ff;
    color: #175cd3;
    font-size: 0.75rem;
    font-weight: 600;
}

.help-form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.btn-help-primary {
    background: #b3261e;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    padding: 10px 20px;
    font-weight: 600;
    font-size: 0.88rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: background-color 0.15s ease;
}

.btn-help-primary:hover:not(:disabled) {
    background: #981b15;
}

.btn-help-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-help-secondary {
    background: #ffffff;
    color: #344054;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    padding: 10px 18px;
    font-weight: 600;
    font-size: 0.88rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.15s ease;
}

.btn-help-secondary:hover {
    background: #f8f9fa;
    color: #101828;
}

.help-alert {
    border-radius: 10px;
    padding: 12px 16px;
    font-weight: 600;
    font-size: 0.88rem;
    margin-bottom: 18px;
}

.help-alert.success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.help-alert.error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.help-checklist {
    list-style: none;
    padding: 0;
    margin: 12px 0 0;
    display: grid;
    gap: 10px;
}

.help-checklist li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: #475467;
    font-size: 0.85rem;
    line-height: 1.45;
}

.help-checklist li i {
    color: #12b76a;
    font-size: 0.95rem;
    margin-top: 2px;
    flex-shrink: 0;
}

.help-empty {
    border: 1px dashed #eaecf0;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    color: #667085;
    background: #f8f9fa;
    font-size: 0.86rem;
}

.help-empty i {
    font-size: 1.4rem;
    color: #98a2b3;
    margin-bottom: 8px;
}

.case-list,
.quick-order-list {
    display: grid;
    gap: 10px;
    margin-top: 12px;
}

.case-item,
.quick-order-item {
    display: block;
    text-decoration: none;
    border: 1px solid #eaecf0;
    border-radius: 12px;
    padding: 12px 14px;
    background: #ffffff;
    color: inherit;
    transition: all 0.15s ease;
}

.case-item:hover,
.quick-order-item:hover {
    border-color: #b3261e;
    background: #f8f9fa;
}

.case-item-top,
.case-meta-row,
.quick-order-item {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
}

.case-subject {
    color: #101828;
    font-weight: 600;
    font-size: 0.88rem;
    margin: 6px 0 4px;
    line-height: 1.4;
}

.case-preview,
.case-meta,
.case-meta-row,
.quick-order-item span {
    color: #667085;
    font-size: 0.8rem;
}

.case-status {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.74rem;
    font-weight: 600;
}

.case-status.open {
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
}

.case-status.in_progress {
    background: #fffaeb;
    color: #b54708;
    border: 1px solid #fedf89;
}

.case-status.resolved,
.case-status.closed {
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
}

.case-priority {
    font-weight: 600;
    color: #344054;
}

.quick-order-item strong {
    display: block;
    color: #101828;
    font-size: 0.86rem;
    margin-bottom: 2px;
}

.quick-order-item span:last-child {
    font-weight: 600;
    color: #b3261e;
    font-size: 0.8rem;
}

@media (max-width: 1024px) {
    .help-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .issue-cards,
    .help-form-grid {
        grid-template-columns: 1fr;
    }

    .help-card {
        padding: 18px;
        border-radius: 14px;
    }

    .help-card-head {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .btn-help-primary,
    .btn-help-secondary {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const issueCards = document.querySelectorAll('.issue-card');
    const issueTypeInput = document.getElementById('issueTypeInput');
    const subjectInput = document.getElementById('subject');
    const orderSelect = document.getElementById('order_id');
    const orderFieldHint = document.getElementById('orderFieldHint');
    const shopSelect = document.getElementById('shop_user_id');
    const shopFieldWrap = document.getElementById('shopFieldWrap');
    const shopFieldHint = document.getElementById('shopFieldHint');
    const banner = document.getElementById('selectedOrderBanner');
    const attachmentInput = document.getElementById('attachment');
    const attachmentName = document.getElementById('attachmentName');

    function updateCategoryUI(category, options = {}) {
        issueCards.forEach((card) => {
            card.classList.toggle('active', card.dataset.category === category);
        });

        issueTypeInput.value = category;

        if (!options.keepSubject && subjectInput && subjectInput.value.trim() === '') {
            const activeCard = document.querySelector(`.issue-card[data-category="${category}"]`);
            if (activeCard && activeCard.dataset.defaultSubject) {
                subjectInput.value = activeCard.dataset.defaultSubject;
            }
        }

        if (!orderFieldHint) {
            return;
        }

        if (category === 'system') {
            orderFieldHint.textContent = 'Attach an order only if the system issue happened during a specific purchase.';
        } else if (category === 'general') {
            orderFieldHint.textContent = 'Order linking is optional for general help requests.';
        } else {
            orderFieldHint.textContent = 'Attach an order if this issue is tied to a specific purchase.';
        }

        if (shopFieldWrap && shopSelect) {
            const showShopField = category === 'shop';
            shopFieldWrap.style.display = showShopField ? '' : 'none';
            shopSelect.required = showShopField;
            if (shopFieldHint) {
                shopFieldHint.textContent = showShopField
                    ? 'Choose the business partner shop this complaint is about.'
                    : 'Shop is auto-detected from the selected order when possible.';
            }
        }
    }

    function updateBanner() {
        if (!banner || !orderSelect) {
            return;
        }
        const selectedOption = orderSelect.options[orderSelect.selectedIndex];
        if (!selectedOption || selectedOption.value === '0') {
            banner.classList.add('hidden');
            banner.innerHTML = '';
            return;
        }

        banner.classList.remove('hidden');
        const shopName = selectedOption.dataset.shopName ? ` Â· Shop: ${selectedOption.dataset.shopName}` : '';
        banner.innerHTML = `<div><strong>Linked order:</strong> ${selectedOption.text}</div><span>Reported from Help Center${shopName}</span>`;
    }

    function syncShopFromOrder() {
        if (!orderSelect || !shopSelect) {
            return;
        }
        const selectedOption = orderSelect.options[orderSelect.selectedIndex];
        if (!selectedOption || !selectedOption.dataset.shopId || selectedOption.value === '0') {
            return;
        }
        const matchingOption = Array.from(shopSelect.options).find((option) => option.value === selectedOption.dataset.shopId);
        if (matchingOption) {
            shopSelect.value = matchingOption.value;
        }
    }

    issueCards.forEach((card) => {
        card.addEventListener('click', () => updateCategoryUI(card.dataset.category));
    });

    if (orderSelect) {
        orderSelect.addEventListener('change', () => {
            syncShopFromOrder();
            updateBanner();
        });
    }

    if (attachmentInput && attachmentName) {
        attachmentInput.addEventListener('change', () => {
            if (attachmentInput.files && attachmentInput.files.length > 0) {
                attachmentName.textContent = attachmentInput.files[0].name;
            } else {
                attachmentName.textContent = 'PNG, JPG, GIF, WEBP, PDF, DOC, or DOCX up to 10MB';
            }
        });
    }

    updateCategoryUI(issueTypeInput.value || 'general', { keepSubject: subjectInput.value.trim() !== '' });
    syncShopFromOrder();
    updateBanner();
});
</script>
@endsection