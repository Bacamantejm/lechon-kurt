<?php

if (!function_exists('pdhTableExists')) {
    function pdhTableExists(mysqli $conn, string $tableName): bool
    {
        static $cache = [];
        $tableName = trim($tableName);
        if ($tableName === '') {
            return false;
        }
        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
        return $cache[$tableName] = (bool)($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('pdhColumnExists')) {
    function pdhColumnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . '.' . $columnName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!pdhTableExists($conn, $tableName)) {
            return $cache[$key] = false;
        }

        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $safeColumn = mysqli_real_escape_string($conn, $columnName);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $cache[$key] = (bool)($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('pdhIndexExists')) {
    function pdhIndexExists(mysqli $conn, string $tableName, string $indexName): bool
    {
        static $cache = [];
        $key = $tableName . ':' . $indexName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!pdhTableExists($conn, $tableName)) {
            return $cache[$key] = false;
        }

        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $safeIndex = mysqli_real_escape_string($conn, $indexName);
        $result = mysqli_query($conn, "SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
        return $cache[$key] = (bool)($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('pdhEnsureProductReviewReplySchema')) {
    function pdhEnsureProductReviewReplySchema(mysqli $conn): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }
        if (!pdhTableExists($conn, 'product_reviews')) {
            return false;
        }

        $requiredColumns = [
            'seller_reply' => "ALTER TABLE product_reviews ADD COLUMN seller_reply TEXT DEFAULT NULL AFTER comment",
            'seller_reply_at' => "ALTER TABLE product_reviews ADD COLUMN seller_reply_at DATETIME DEFAULT NULL AFTER seller_reply",
            'seller_reply_by' => "ALTER TABLE product_reviews ADD COLUMN seller_reply_by INT(11) DEFAULT NULL AFTER seller_reply_at"
        ];

        foreach ($requiredColumns as $columnName => $alterSql) {
            if (!pdhColumnExists($conn, 'product_reviews', $columnName)) {
                if (!mysqli_query($conn, $alterSql)) {
                    return false;
                }
            }
        }

        if (!pdhIndexExists($conn, 'product_reviews', 'idx_product_reviews_reply_by')) {
            mysqli_query($conn, "ALTER TABLE product_reviews ADD INDEX idx_product_reviews_reply_by (seller_reply_by)");
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('pdhEnsureProductViewEventsSchema')) {
    function pdhEnsureProductViewEventsSchema(mysqli $conn): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }

        $createSql = "
            CREATE TABLE IF NOT EXISTS product_view_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT(11) NOT NULL,
                seller_id INT(11) DEFAULT NULL,
                viewer_user_id INT(11) DEFAULT NULL,
                session_token VARCHAR(120) NOT NULL,
                view_date DATE NOT NULL,
                first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                view_count INT(11) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_pve_product_session_day (product_id, session_token, view_date),
                KEY idx_pve_seller_date (seller_id, view_date),
                KEY idx_pve_viewer_date (viewer_user_id, view_date),
                KEY idx_pve_product_date (product_id, view_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!mysqli_query($conn, $createSql)) {
            return false;
        }

        $requiredColumns = [
            'seller_id' => "ALTER TABLE product_view_events ADD COLUMN seller_id INT(11) DEFAULT NULL AFTER product_id",
            'viewer_user_id' => "ALTER TABLE product_view_events ADD COLUMN viewer_user_id INT(11) DEFAULT NULL AFTER seller_id",
            'session_token' => "ALTER TABLE product_view_events ADD COLUMN session_token VARCHAR(120) NOT NULL DEFAULT '' AFTER viewer_user_id",
            'view_date' => "ALTER TABLE product_view_events ADD COLUMN view_date DATE NOT NULL AFTER session_token",
            'first_viewed_at' => "ALTER TABLE product_view_events ADD COLUMN first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER view_date",
            'last_viewed_at' => "ALTER TABLE product_view_events ADD COLUMN last_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER first_viewed_at",
            'view_count' => "ALTER TABLE product_view_events ADD COLUMN view_count INT(11) NOT NULL DEFAULT 1 AFTER last_viewed_at"
        ];

        foreach ($requiredColumns as $columnName => $alterSql) {
            if (!pdhColumnExists($conn, 'product_view_events', $columnName)) {
                if (!mysqli_query($conn, $alterSql)) {
                    return false;
                }
            }
        }

        $requiredIndexes = [
            'uniq_pve_product_session_day' => "ALTER TABLE product_view_events ADD UNIQUE KEY uniq_pve_product_session_day (product_id, session_token, view_date)",
            'idx_pve_seller_date' => "ALTER TABLE product_view_events ADD KEY idx_pve_seller_date (seller_id, view_date)",
            'idx_pve_viewer_date' => "ALTER TABLE product_view_events ADD KEY idx_pve_viewer_date (viewer_user_id, view_date)",
            'idx_pve_product_date' => "ALTER TABLE product_view_events ADD KEY idx_pve_product_date (product_id, view_date)"
        ];
        foreach ($requiredIndexes as $indexName => $indexSql) {
            if (!pdhIndexExists($conn, 'product_view_events', $indexName)) {
                if (!mysqli_query($conn, $indexSql)) {
                    return false;
                }
            }
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('pdhEnsurePartnerPayoutAccountsSchema')) {
    function pdhEnsurePartnerPayoutAccountsSchema(mysqli $conn): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }

        $createSql = "
            CREATE TABLE IF NOT EXISTS partner_payout_accounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                partner_user_id INT(11) NOT NULL,
                payout_method VARCHAR(40) NOT NULL DEFAULT 'bank_transfer',
                account_holder VARCHAR(180) NOT NULL,
                financial_institution VARCHAR(180) DEFAULT NULL,
                account_type VARCHAR(80) DEFAULT NULL,
                account_number VARCHAR(140) NOT NULL,
                account_number_masked VARCHAR(140) NOT NULL,
                branch_name VARCHAR(120) DEFAULT NULL,
                routing_reference VARCHAR(120) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                updated_by INT(11) DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_partner_user_id (partner_user_id),
                KEY idx_payout_method (payout_method)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!mysqli_query($conn, $createSql)) {
            return false;
        }

        $requiredColumns = [
            'payout_method' => "ALTER TABLE partner_payout_accounts ADD COLUMN payout_method VARCHAR(40) NOT NULL DEFAULT 'bank_transfer' AFTER partner_user_id",
            'account_holder' => "ALTER TABLE partner_payout_accounts ADD COLUMN account_holder VARCHAR(180) NOT NULL DEFAULT '' AFTER payout_method",
            'financial_institution' => "ALTER TABLE partner_payout_accounts ADD COLUMN financial_institution VARCHAR(180) DEFAULT NULL AFTER account_holder",
            'account_type' => "ALTER TABLE partner_payout_accounts ADD COLUMN account_type VARCHAR(80) DEFAULT NULL AFTER financial_institution",
            'account_number' => "ALTER TABLE partner_payout_accounts ADD COLUMN account_number VARCHAR(140) NOT NULL DEFAULT '' AFTER account_type",
            'account_number_masked' => "ALTER TABLE partner_payout_accounts ADD COLUMN account_number_masked VARCHAR(140) NOT NULL DEFAULT '' AFTER account_number",
            'branch_name' => "ALTER TABLE partner_payout_accounts ADD COLUMN branch_name VARCHAR(120) DEFAULT NULL AFTER account_number_masked",
            'routing_reference' => "ALTER TABLE partner_payout_accounts ADD COLUMN routing_reference VARCHAR(120) DEFAULT NULL AFTER branch_name",
            'notes' => "ALTER TABLE partner_payout_accounts ADD COLUMN notes TEXT DEFAULT NULL AFTER routing_reference",
            'is_active' => "ALTER TABLE partner_payout_accounts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes",
            'updated_by' => "ALTER TABLE partner_payout_accounts ADD COLUMN updated_by INT(11) DEFAULT NULL AFTER is_active"
        ];
        foreach ($requiredColumns as $columnName => $alterSql) {
            if (!pdhColumnExists($conn, 'partner_payout_accounts', $columnName)) {
                if (!mysqli_query($conn, $alterSql)) {
                    return false;
                }
            }
        }

        if (!pdhIndexExists($conn, 'partner_payout_accounts', 'idx_payout_method')) {
            if (!mysqli_query($conn, "ALTER TABLE partner_payout_accounts ADD KEY idx_payout_method (payout_method)")) {
                return false;
            }
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('pdhMaskPayoutAccountNumber')) {
    function pdhMaskPayoutAccountNumber(string $rawValue): string
    {
        $value = preg_replace('/\s+/', '', trim($rawValue));
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', max(0, $length - 1)) . substr($value, -1);
        }

        return str_repeat('*', $length - 4) . substr($value, -4);
    }
}

if (!function_exists('pdhDefaultPartnerPayoutAccount')) {
    function pdhDefaultPartnerPayoutAccount(): array
    {
        return [
            'partner_user_id' => 0,
            'payout_method' => 'bank_transfer',
            'account_holder' => '',
            'financial_institution' => '',
            'account_type' => '',
            'account_number' => '',
            'account_number_masked' => '',
            'branch_name' => '',
            'routing_reference' => '',
            'notes' => '',
            'is_active' => 1,
            'updated_by' => 0
        ];
    }
}

if (!function_exists('pdhFetchPartnerPayoutAccount')) {
    function pdhFetchPartnerPayoutAccount(mysqli $conn, int $partnerUserId): array
    {
        $defaults = pdhDefaultPartnerPayoutAccount();
        $partnerUserId = (int)$partnerUserId;
        if ($partnerUserId <= 0 || !pdhEnsurePartnerPayoutAccountsSchema($conn)) {
            return $defaults;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT partner_user_id, payout_method, account_holder, financial_institution, account_type, account_number, account_number_masked, branch_name, routing_reference, notes, is_active, updated_by
             FROM partner_payout_accounts
             WHERE partner_user_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return $defaults;
        }

        mysqli_stmt_bind_param($stmt, "i", $partnerUserId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$row) {
            return $defaults;
        }

        $account = $defaults;
        $account['partner_user_id'] = $partnerUserId;
        $account['payout_method'] = trim((string)($row['payout_method'] ?? 'bank_transfer')) ?: 'bank_transfer';
        $account['account_holder'] = trim((string)($row['account_holder'] ?? ''));
        $account['financial_institution'] = trim((string)($row['financial_institution'] ?? ''));
        $account['account_type'] = trim((string)($row['account_type'] ?? ''));
        $account['account_number'] = trim((string)($row['account_number'] ?? ''));
        $account['account_number_masked'] = trim((string)($row['account_number_masked'] ?? ''));
        $account['branch_name'] = trim((string)($row['branch_name'] ?? ''));
        $account['routing_reference'] = trim((string)($row['routing_reference'] ?? ''));
        $account['notes'] = trim((string)($row['notes'] ?? ''));
        $account['is_active'] = (int)($row['is_active'] ?? 1) === 1 ? 1 : 0;
        $account['updated_by'] = (int)($row['updated_by'] ?? 0);

        if ($account['account_number_masked'] === '' && $account['account_number'] !== '') {
            $account['account_number_masked'] = pdhMaskPayoutAccountNumber($account['account_number']);
        }

        return $account;
    }
}

if (!function_exists('pdhSavePartnerPayoutAccount')) {
    function pdhSavePartnerPayoutAccount(mysqli $conn, int $partnerUserId, array $payload, int $updatedBy = 0): bool
    {
        $partnerUserId = (int)$partnerUserId;
        $updatedBy = (int)$updatedBy;
        if ($partnerUserId <= 0 || !pdhEnsurePartnerPayoutAccountsSchema($conn)) {
            return false;
        }

        $allowedMethods = ['bank_transfer', 'gcash', 'paymaya', 'other'];
        $payoutMethod = strtolower(trim((string)($payload['payout_method'] ?? 'bank_transfer')));
        if (!in_array($payoutMethod, $allowedMethods, true)) {
            $payoutMethod = 'bank_transfer';
        }

        $accountHolder = trim((string)($payload['account_holder'] ?? ''));
        $financialInstitution = trim((string)($payload['financial_institution'] ?? ''));
        $accountType = trim((string)($payload['account_type'] ?? ''));
        $accountNumber = preg_replace('/\s+/', '', trim((string)($payload['account_number'] ?? '')));
        $branchName = trim((string)($payload['branch_name'] ?? ''));
        $routingReference = trim((string)($payload['routing_reference'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        $isActive = !empty($payload['is_active']) ? 1 : 0;

        if ($accountHolder === '' || $accountNumber === '') {
            return false;
        }

        $masked = pdhMaskPayoutAccountNumber($accountNumber);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO partner_payout_accounts (
                partner_user_id, payout_method, account_holder, financial_institution, account_type, account_number, account_number_masked, branch_name, routing_reference, notes, is_active, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                payout_method = VALUES(payout_method),
                account_holder = VALUES(account_holder),
                financial_institution = VALUES(financial_institution),
                account_type = VALUES(account_type),
                account_number = VALUES(account_number),
                account_number_masked = VALUES(account_number_masked),
                branch_name = VALUES(branch_name),
                routing_reference = VALUES(routing_reference),
                notes = VALUES(notes),
                is_active = VALUES(is_active),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "isssssssssii",
            $partnerUserId,
            $payoutMethod,
            $accountHolder,
            $financialInstitution,
            $accountType,
            $accountNumber,
            $masked,
            $branchName,
            $routingReference,
            $notes,
            $isActive,
            $updatedBy
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $ok;
    }
}
