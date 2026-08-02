<?php

if (!function_exists('prsTableExists')) {
    function prsTableExists(mysqli $conn, string $tableName): bool
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

if (!function_exists('prsColumnExists')) {
    function prsColumnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . '.' . $columnName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!prsTableExists($conn, $tableName)) {
            return $cache[$key] = false;
        }

        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $safeColumn = mysqli_real_escape_string($conn, $columnName);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $cache[$key] = (bool)($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('prsDefaultReceiptSettings')) {
    function prsDefaultReceiptSettings(): array
    {
        return [
            'partner_user_id' => 0,
            'store_display_name' => '',
            'branch_name' => '',
            'vat_tin' => '',
            'business_style' => '',
            'permit_no' => '',
            'ptu_no' => '',
            'accreditation_no' => '',
            'serial_no' => '',
            'footer_text' => '',
        ];
    }
}

if (!function_exists('prsEnsureReceiptSettingsSchema')) {
    function prsEnsureReceiptSettingsSchema(mysqli $conn): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }

        $createSql = "
            CREATE TABLE IF NOT EXISTS partner_receipt_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                partner_user_id INT(11) NOT NULL,
                store_display_name VARCHAR(180) DEFAULT NULL,
                branch_name VARCHAR(180) DEFAULT NULL,
                vat_tin VARCHAR(80) DEFAULT NULL,
                business_style VARCHAR(180) DEFAULT NULL,
                permit_no VARCHAR(120) DEFAULT NULL,
                ptu_no VARCHAR(120) DEFAULT NULL,
                accreditation_no VARCHAR(120) DEFAULT NULL,
                serial_no VARCHAR(120) DEFAULT NULL,
                footer_text TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_partner_user_id (partner_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!mysqli_query($conn, $createSql)) {
            return false;
        }

        $requiredColumns = [
            'store_display_name' => "ALTER TABLE partner_receipt_settings ADD COLUMN store_display_name VARCHAR(180) DEFAULT NULL AFTER partner_user_id",
            'branch_name' => "ALTER TABLE partner_receipt_settings ADD COLUMN branch_name VARCHAR(180) DEFAULT NULL AFTER store_display_name",
            'vat_tin' => "ALTER TABLE partner_receipt_settings ADD COLUMN vat_tin VARCHAR(80) DEFAULT NULL AFTER branch_name",
            'business_style' => "ALTER TABLE partner_receipt_settings ADD COLUMN business_style VARCHAR(180) DEFAULT NULL AFTER vat_tin",
            'permit_no' => "ALTER TABLE partner_receipt_settings ADD COLUMN permit_no VARCHAR(120) DEFAULT NULL AFTER business_style",
            'ptu_no' => "ALTER TABLE partner_receipt_settings ADD COLUMN ptu_no VARCHAR(120) DEFAULT NULL AFTER permit_no",
            'accreditation_no' => "ALTER TABLE partner_receipt_settings ADD COLUMN accreditation_no VARCHAR(120) DEFAULT NULL AFTER ptu_no",
            'serial_no' => "ALTER TABLE partner_receipt_settings ADD COLUMN serial_no VARCHAR(120) DEFAULT NULL AFTER accreditation_no",
            'footer_text' => "ALTER TABLE partner_receipt_settings ADD COLUMN footer_text TEXT DEFAULT NULL AFTER serial_no",
        ];

        foreach ($requiredColumns as $column => $alterSql) {
            if (!prsColumnExists($conn, 'partner_receipt_settings', $column)) {
                if (!mysqli_query($conn, $alterSql)) {
                    return false;
                }
            }
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('prsFetchReceiptSettings')) {
    function prsFetchReceiptSettings(mysqli $conn, int $partnerUserId): array
    {
        $settings = prsDefaultReceiptSettings();
        $partnerUserId = (int)$partnerUserId;
        if ($partnerUserId <= 0) {
            return $settings;
        }
        if (!prsEnsureReceiptSettingsSchema($conn)) {
            return $settings;
        }

        $stmt = mysqli_prepare($conn, "SELECT partner_user_id, store_display_name, branch_name, vat_tin, business_style, permit_no, ptu_no, accreditation_no, serial_no, footer_text FROM partner_receipt_settings WHERE partner_user_id = ? LIMIT 1");
        if (!$stmt) {
            return $settings;
        }
        mysqli_stmt_bind_param($stmt, 'i', $partnerUserId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($row) {
            foreach ($settings as $key => $defaultValue) {
                if (array_key_exists($key, $row)) {
                    $settings[$key] = trim((string)$row[$key]);
                }
            }
            $settings['partner_user_id'] = $partnerUserId;
        }

        return $settings;
    }
}

if (!function_exists('prsSaveReceiptSettings')) {
    function prsSaveReceiptSettings(mysqli $conn, int $partnerUserId, array $payload): bool
    {
        $partnerUserId = (int)$partnerUserId;
        if ($partnerUserId <= 0) {
            return false;
        }
        if (!prsEnsureReceiptSettingsSchema($conn)) {
            return false;
        }

        $defaults = prsDefaultReceiptSettings();
        $record = [];
        foreach ($defaults as $field => $defaultValue) {
            if ($field === 'partner_user_id') {
                continue;
            }
            $value = trim((string)($payload[$field] ?? ''));
            if ($field === 'footer_text') {
                $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
            }
            $record[$field] = $value;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO partner_receipt_settings (
                partner_user_id, store_display_name, branch_name, vat_tin, business_style, permit_no, ptu_no, accreditation_no, serial_no, footer_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                store_display_name = VALUES(store_display_name),
                branch_name = VALUES(branch_name),
                vat_tin = VALUES(vat_tin),
                business_style = VALUES(business_style),
                permit_no = VALUES(permit_no),
                ptu_no = VALUES(ptu_no),
                accreditation_no = VALUES(accreditation_no),
                serial_no = VALUES(serial_no),
                footer_text = VALUES(footer_text),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'isssssssss',
            $partnerUserId,
            $record['store_display_name'],
            $record['branch_name'],
            $record['vat_tin'],
            $record['business_style'],
            $record['permit_no'],
            $record['ptu_no'],
            $record['accreditation_no'],
            $record['serial_no'],
            $record['footer_text']
        );

        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}
