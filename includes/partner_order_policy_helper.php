<?php
require_once __DIR__ . '/security.php';

if (!function_exists('popTableExists')) {
    function popTableExists(mysqli $conn, string $tableName): bool
    {
        static $cache = [];
        $tableName = trim($tableName);
        if ($tableName === '') {
            return false;
        }
        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }
        $safe = mysqli_real_escape_string($conn, $tableName);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
        return $cache[$tableName] = (bool)($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('popColumnExists')) {
    function popColumnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . '.' . $columnName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!popTableExists($conn, $tableName)) {
            return $cache[$key] = false;
        }
        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $safeColumn = mysqli_real_escape_string($conn, $columnName);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $cache[$key] = (bool)($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('popEnsurePolicySchema')) {
    function popEnsurePolicySchema(mysqli $conn): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }

        $createPolicy = "
            CREATE TABLE IF NOT EXISTS partner_order_policy_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                partner_user_id INT(11) NOT NULL,
                allow_customer_cancel_pending TINYINT(1) NOT NULL DEFAULT 1,
                allow_customer_cancel_confirmed TINYINT(1) NOT NULL DEFAULT 1,
                allow_customer_cancel_preparing TINYINT(1) NOT NULL DEFAULT 0,
                downpayment_refundable TINYINT(1) NOT NULL DEFAULT 0,
                require_refund_photo_for_damage TINYINT(1) NOT NULL DEFAULT 1,
                cancellation_terms TEXT DEFAULT NULL,
                refund_terms TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_partner_order_policy_partner (partner_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!mysqli_query($conn, $createPolicy)) {
            return false;
        }

        $policyColumns = [
            'allow_customer_cancel_pending' => "ALTER TABLE partner_order_policy_settings ADD COLUMN allow_customer_cancel_pending TINYINT(1) NOT NULL DEFAULT 1 AFTER partner_user_id",
            'allow_customer_cancel_confirmed' => "ALTER TABLE partner_order_policy_settings ADD COLUMN allow_customer_cancel_confirmed TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_customer_cancel_pending",
            'allow_customer_cancel_preparing' => "ALTER TABLE partner_order_policy_settings ADD COLUMN allow_customer_cancel_preparing TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_customer_cancel_confirmed",
            'downpayment_refundable' => "ALTER TABLE partner_order_policy_settings ADD COLUMN downpayment_refundable TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_customer_cancel_preparing",
            'require_refund_photo_for_damage' => "ALTER TABLE partner_order_policy_settings ADD COLUMN require_refund_photo_for_damage TINYINT(1) NOT NULL DEFAULT 1 AFTER downpayment_refundable",
            'cancellation_terms' => "ALTER TABLE partner_order_policy_settings ADD COLUMN cancellation_terms TEXT DEFAULT NULL AFTER require_refund_photo_for_damage",
            'refund_terms' => "ALTER TABLE partner_order_policy_settings ADD COLUMN refund_terms TEXT DEFAULT NULL AFTER cancellation_terms",
        ];
        foreach ($policyColumns as $column => $sql) {
            if (!popColumnExists($conn, 'partner_order_policy_settings', $column)) {
                if (!mysqli_query($conn, $sql)) {
                    return false;
                }
            }
        }

        if (popTableExists($conn, 'refunds')) {
            $refundColumns = [
                'refund_reason' => "ALTER TABLE refunds ADD COLUMN refund_reason VARCHAR(120) DEFAULT NULL AFTER remarks",
                'customer_evidence_path' => "ALTER TABLE refunds ADD COLUMN customer_evidence_path VARCHAR(255) DEFAULT NULL AFTER refund_reason"
            ];
            foreach ($refundColumns as $column => $sql) {
                if (!popColumnExists($conn, 'refunds', $column)) {
                    if (!mysqli_query($conn, $sql)) {
                        return false;
                    }
                }
            }
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('popDefaultPolicy')) {
    function popDefaultPolicy(): array
    {
        return [
            'partner_user_id' => 0,
            'allow_customer_cancel_pending' => 1,
            'allow_customer_cancel_confirmed' => 1,
            'allow_customer_cancel_preparing' => 0,
            'downpayment_refundable' => 0,
            'require_refund_photo_for_damage' => 1,
            'cancellation_terms' => 'Customers may only cancel while the order is still pending or confirmed. Once preparation starts, cancellation is blocked unless the store explicitly allows it.',
            'refund_terms' => 'Refund requests for damaged or broken products must include a photo. Downpayments are non-refundable by default.'
        ];
    }
}

if (!function_exists('popFetchPolicy')) {
    function popFetchPolicy(mysqli $conn, int $partnerUserId): array
    {
        $policy = popDefaultPolicy();
        $partnerUserId = (int)$partnerUserId;
        if ($partnerUserId <= 0) {
            return $policy;
        }
        if (!popEnsurePolicySchema($conn)) {
            return $policy;
        }

        $stmt = mysqli_prepare($conn, "SELECT partner_user_id, allow_customer_cancel_pending, allow_customer_cancel_confirmed, allow_customer_cancel_preparing, downpayment_refundable, require_refund_photo_for_damage, cancellation_terms, refund_terms FROM partner_order_policy_settings WHERE partner_user_id = ? LIMIT 1");
        if (!$stmt) {
            return $policy;
        }
        mysqli_stmt_bind_param($stmt, 'i', $partnerUserId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if ($row) {
            $policy['partner_user_id'] = $partnerUserId;
            foreach (['allow_customer_cancel_pending', 'allow_customer_cancel_confirmed', 'allow_customer_cancel_preparing', 'downpayment_refundable', 'require_refund_photo_for_damage'] as $flag) {
                $policy[$flag] = (int)($row[$flag] ?? $policy[$flag]);
            }
            foreach (['cancellation_terms', 'refund_terms'] as $field) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value !== '') {
                    $policy[$field] = $value;
                }
            }
        }
        return $policy;
    }
}

if (!function_exists('popSavePolicy')) {
    function popSavePolicy(mysqli $conn, int $partnerUserId, array $payload): bool
    {
        $partnerUserId = (int)$partnerUserId;
        if ($partnerUserId <= 0 || !popEnsurePolicySchema($conn)) {
            return false;
        }
        $record = [
            'allow_customer_cancel_pending' => !empty($payload['allow_customer_cancel_pending']) ? 1 : 0,
            'allow_customer_cancel_confirmed' => !empty($payload['allow_customer_cancel_confirmed']) ? 1 : 0,
            'allow_customer_cancel_preparing' => !empty($payload['allow_customer_cancel_preparing']) ? 1 : 0,
            'downpayment_refundable' => !empty($payload['downpayment_refundable']) ? 1 : 0,
            'require_refund_photo_for_damage' => !empty($payload['require_refund_photo_for_damage']) ? 1 : 0,
            'cancellation_terms' => trim((string)($payload['cancellation_terms'] ?? '')),
            'refund_terms' => trim((string)($payload['refund_terms'] ?? '')),
        ];

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO partner_order_policy_settings (partner_user_id, allow_customer_cancel_pending, allow_customer_cancel_confirmed, allow_customer_cancel_preparing, downpayment_refundable, require_refund_photo_for_damage, cancellation_terms, refund_terms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                allow_customer_cancel_pending = VALUES(allow_customer_cancel_pending),
                allow_customer_cancel_confirmed = VALUES(allow_customer_cancel_confirmed),
                allow_customer_cancel_preparing = VALUES(allow_customer_cancel_preparing),
                downpayment_refundable = VALUES(downpayment_refundable),
                require_refund_photo_for_damage = VALUES(require_refund_photo_for_damage),
                cancellation_terms = VALUES(cancellation_terms),
                refund_terms = VALUES(refund_terms),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iiiiiiss',
            $partnerUserId,
            $record['allow_customer_cancel_pending'],
            $record['allow_customer_cancel_confirmed'],
            $record['allow_customer_cancel_preparing'],
            $record['downpayment_refundable'],
            $record['require_refund_photo_for_damage'],
            $record['cancellation_terms'],
            $record['refund_terms']
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('popResolveOrderPartnerUserId')) {
    function popResolveOrderPartnerUserId(mysqli $conn, int $orderId): int
    {
        $orderId = (int)$orderId;
        if ($orderId <= 0) {
            return 0;
        }
        $query = "SELECT p.seller_id
                  FROM order_items oi
                  INNER JOIN products p
                    ON oi.product_id = p.product_id
                    OR oi.product_id = CAST(p.id AS CHAR)
                    OR CAST(oi.product_id AS UNSIGNED) = p.id
                  WHERE oi.order_id = ?
                    AND p.seller_id IS NOT NULL
                  GROUP BY p.seller_id
                  ORDER BY COUNT(*) DESC, p.seller_id ASC
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        return (int)($row['seller_id'] ?? 0);
    }
}

if (!function_exists('popResolvePreOrderPartnerUserId')) {
    function popResolvePreOrderPartnerUserId(mysqli $conn, int $preOrderId): int
    {
        $preOrderId = (int)$preOrderId;
        if ($preOrderId <= 0) {
            return 0;
        }
        $query = "SELECT p.seller_id
                  FROM pre_orders po
                  INNER JOIN products p ON p.id = po.product_id
                  WHERE po.id = ?
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'i', $preOrderId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        return (int)($row['seller_id'] ?? 0);
    }
}

if (!function_exists('popGetOrderPolicy')) {
    function popGetOrderPolicy(mysqli $conn, int $orderId): array
    {
        $partnerUserId = popResolveOrderPartnerUserId($conn, $orderId);
        return popFetchPolicy($conn, $partnerUserId);
    }
}

if (!function_exists('popGetPreOrderPolicy')) {
    function popGetPreOrderPolicy(mysqli $conn, int $preOrderId): array
    {
        $partnerUserId = popResolvePreOrderPartnerUserId($conn, $preOrderId);
        return popFetchPolicy($conn, $partnerUserId);
    }
}

if (!function_exists('popEvaluateOrderCancellation')) {
    function popEvaluateOrderCancellation(array $policy, string $status): array
    {
        $normalized = strtolower(trim($status));
        $allowed = false;
        if ($normalized === 'pending') {
            $allowed = !empty($policy['allow_customer_cancel_pending']);
        } elseif ($normalized === 'confirmed' || $normalized === 'cancellation_requested') {
            $allowed = !empty($policy['allow_customer_cancel_confirmed']);
        } elseif ($normalized === 'preparing' || $normalized === 'processing') {
            $allowed = !empty($policy['allow_customer_cancel_preparing']);
        }

        $message = $allowed
            ? 'Cancellation is allowed under this store\'s current terms.'
            : 'Cancellation is not allowed under this store\'s current terms for the current order status.';
        if (!empty($policy['cancellation_terms'])) {
            $message .= ' ' . trim((string)$policy['cancellation_terms']);
        }

        return ['allowed' => $allowed, 'message' => $message];
    }
}

if (!function_exists('popRefundReasonNeedsPhoto')) {
    function popRefundReasonNeedsPhoto(string $refundReason): bool
    {
        $normalized = strtolower(trim($refundReason));
        return in_array($normalized, ['damaged product', 'broken product'], true);
    }
}

if (!function_exists('popUploadRefundEvidence')) {
    function popUploadRefundEvidence(array $file, string $prefix = 'refund_evidence'): string
    {
        $validation = validateFileUpload($file, ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);
        if (empty($validation['valid'])) {
            throw new Exception($validation['errors'][0] ?? 'Invalid refund evidence upload.');
        }
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        $extension = $extMap[(string)($validation['mime_type'] ?? '')] ?? 'jpg';
        $uploadDir = __DIR__ . '/../uploads/refund_evidence/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('Unable to prepare refund evidence upload folder.');
        }
        $fileName = sprintf('%s_%s_%s.%s', $prefix, date('YmdHis'), bin2hex(random_bytes(5)), $extension);
        $targetPath = $uploadDir . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save refund evidence image.');
        }
        return 'uploads/refund_evidence/' . $fileName;
    }
}
