<?php
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

$root_path = dirname(__DIR__, 2);
require_once $root_path . '/includes/config.php';
require_once $root_path . '/includes/partner_voucher_helper.php';
require_once $root_path . '/includes/checkout_address_helper.php';

if (PHP_SAPI !== 'cli') {
    require_once $root_path . '/admin/auth.php';
    checkAdminAccess();
    if (($_SESSION['role_name'] ?? '') !== 'super_admin') {
        requirePermission('users.edit');
    }
}

function suTableExists(mysqli $conn, string $table_name): bool {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    return $result && mysqli_num_rows($result) > 0;
}

function suColumnExists(mysqli $conn, string $table_name, string $column_name): bool {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    return $result && mysqli_num_rows($result) > 0;
}

function suIndexExists(mysqli $conn, string $table_name, string $index_name): bool {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_index = mysqli_real_escape_string($conn, $index_name);
    $result = mysqli_query($conn, "SHOW INDEX FROM `{$safe_table}` WHERE Key_name = '{$safe_index}'");
    return $result && mysqli_num_rows($result) > 0;
}

function suForeignKeyExists(mysqli $conn, string $table_name, string $constraint_name): bool {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_constraint = mysqli_real_escape_string($conn, $constraint_name);
    $sql = "SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$safe_table}'
              AND CONSTRAINT_NAME = '{$safe_constraint}'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            LIMIT 1";
    $result = mysqli_query($conn, $sql);
    return $result && mysqli_num_rows($result) > 0;
}

function suAddResult(array &$results, string $item, string $status, string $message): void {
    $results[] = ['item' => $item, 'status' => $status, 'message' => $message];
}

function suEnsureTable(mysqli $conn, string $table, string $ddl, array &$results, string $item_prefix): void {
    if (suTableExists($conn, $table)) {
        suAddResult($results, $item_prefix . '.' . $table, 'ok', 'Table already exists.');
        return;
    }
    if (!mysqli_query($conn, $ddl)) {
        throw new RuntimeException("Failed to create `{$table}`: " . mysqli_error($conn));
    }
    suAddResult($results, $item_prefix . '.' . $table, 'ok', 'Table created.');
}

function suEnsureColumn(mysqli $conn, string $table, string $column, string $ddl, array &$results, string $item_prefix): void {
    if (suColumnExists($conn, $table, $column)) {
        suAddResult($results, $item_prefix . '.' . $column, 'ok', 'Column already exists.');
        return;
    }
    if (!mysqli_query($conn, $ddl)) {
        throw new RuntimeException("Failed to add {$table}.{$column}: " . mysqli_error($conn));
    }
    suAddResult($results, $item_prefix . '.' . $column, 'ok', 'Column added.');
}

function suEnsureIndex(mysqli $conn, string $table, string $index, string $ddl, array &$results, string $item_prefix): void {
    if (suIndexExists($conn, $table, $index)) {
        suAddResult($results, $item_prefix . '.' . $index, 'ok', 'Index already exists.');
        return;
    }
    if (!mysqli_query($conn, $ddl)) {
        suAddResult($results, $item_prefix . '.' . $index, 'warning', 'Index was not added: ' . mysqli_error($conn));
        return;
    }
    suAddResult($results, $item_prefix . '.' . $index, 'ok', 'Index added.');
}

function suEnsureForeignKey(mysqli $conn, string $table, string $constraint, string $ddl, array &$results, string $item_prefix): void {
    if (suForeignKeyExists($conn, $table, $constraint)) {
        suAddResult($results, $item_prefix . '.' . $constraint, 'ok', 'Foreign key already exists.');
        return;
    }
    if (!mysqli_query($conn, $ddl)) {
        throw new RuntimeException("Failed to add foreign key `{$constraint}` on `{$table}`: " . mysqli_error($conn));
    }
    suAddResult($results, $item_prefix . '.' . $constraint, 'ok', 'Foreign key added.');
}

function suRunSchemaUpdates(mysqli $conn): array {
    $results = [];
    $has_errors = false;

    try {
        pvEnsureVoucherSchema($conn);
        foreach (['partner_vouchers', 'partner_voucher_redemptions'] as $table) {
            if (!suTableExists($conn, $table)) {
                throw new RuntimeException("Table `{$table}` is missing after voucher setup.");
            }
            suAddResult($results, 'partner_voucher_schema.' . $table, 'ok', 'Table is ready.');
        }
        foreach (['voucher_id', 'voucher_code', 'voucher_discount'] as $col) {
            if (!suColumnExists($conn, 'orders', $col)) {
                throw new RuntimeException("Column `orders.{$col}` is missing after voucher setup.");
            }
            suAddResult($results, 'partner_voucher_schema.orders.' . $col, 'ok', 'Column is ready.');
        }
    } catch (Throwable $e) {
        $has_errors = true;
        suAddResult($results, 'partner_voucher_schema', 'error', $e->getMessage());
    }

    try {
        caEnsureUserSavedAddressSchema($conn);
        if (!suTableExists($conn, 'user_saved_addresses')) {
            throw new RuntimeException('Table `user_saved_addresses` is missing after address schema setup.');
        }
        suAddResult($results, 'checkout_address_schema.user_saved_addresses', 'ok', 'Table is ready.');

        suEnsureColumn($conn, 'user_saved_addresses', 'latitude', "ALTER TABLE `user_saved_addresses` ADD COLUMN `latitude` decimal(10,7) DEFAULT NULL AFTER `address_hash`", $results, 'checkout_address_schema');
        suEnsureColumn($conn, 'user_saved_addresses', 'longitude', "ALTER TABLE `user_saved_addresses` ADD COLUMN `longitude` decimal(10,7) DEFAULT NULL AFTER `latitude`", $results, 'checkout_address_schema');
        suEnsureColumn($conn, 'user_saved_addresses', 'updated_at', "ALTER TABLE `user_saved_addresses` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()", $results, 'checkout_address_schema');

        if (suColumnExists($conn, 'user_saved_addresses', 'address_hash')) {
            $sql = "UPDATE `user_saved_addresses` SET `address_hash` = SHA1(LOWER(TRIM(COALESCE(`full_address`, '')))) WHERE `address_hash` IS NULL OR `address_hash` = ''";
            if (!mysqli_query($conn, $sql)) {
                suAddResult($results, 'checkout_address_schema.address_hash_backfill', 'warning', 'Address hash backfill failed: ' . mysqli_error($conn));
            } else {
                suAddResult($results, 'checkout_address_schema.address_hash_backfill', 'ok', 'Address hash backfill completed.');
            }
        }

        suEnsureIndex($conn, 'user_saved_addresses', 'uniq_user_hash', "ALTER TABLE `user_saved_addresses` ADD UNIQUE KEY `uniq_user_hash` (`user_id`,`address_hash`)", $results, 'checkout_address_schema');
        suEnsureIndex($conn, 'user_saved_addresses', 'idx_user_default', "ALTER TABLE `user_saved_addresses` ADD KEY `idx_user_default` (`user_id`,`is_default`)", $results, 'checkout_address_schema');
        suEnsureIndex($conn, 'user_saved_addresses', 'idx_user_updated', "ALTER TABLE `user_saved_addresses` ADD KEY `idx_user_updated` (`user_id`,`updated_at`)", $results, 'checkout_address_schema');
    } catch (Throwable $e) {
        $has_errors = true;
        suAddResult($results, 'checkout_address_schema', 'error', $e->getMessage());
    }

    try {
        if (!suTableExists($conn, 'products')) {
            throw new RuntimeException('Table `products` is missing.');
        }
        suEnsureColumn($conn, 'products', 'is_archived', "ALTER TABLE `products` ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0", $results, 'products_schema');
        suEnsureColumn($conn, 'products', 'lead_time_hours', "ALTER TABLE `products` ADD COLUMN `lead_time_hours` INT(11) NOT NULL DEFAULT 24 COMMENT 'Minimum hours notice required for pre-order'", $results, 'products_schema');
    } catch (Throwable $e) {
        $has_errors = true;
        suAddResult($results, 'products_schema', 'error', $e->getMessage());
    }

    try {
        if (!suTableExists($conn, 'users')) {
            throw new RuntimeException('Table `users` is missing.');
        }
        if (!suTableExists($conn, 'orders')) {
            throw new RuntimeException('Table `orders` is missing.');
        }

        suEnsureColumn(
            $conn,
            'users',
            'updated_at',
            "ALTER TABLE `users` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`",
            $results,
            'query_compat_schema'
        );

        suEnsureColumn(
            $conn,
            'users',
            'profile_image',
            "ALTER TABLE `users` ADD COLUMN `profile_image` VARCHAR(255) DEFAULT NULL AFTER `address`",
            $results,
            'query_compat_schema'
        );

        suEnsureColumn(
            $conn,
            'users',
            'business_logo',
            "ALTER TABLE `users` ADD COLUMN `business_logo` VARCHAR(255) DEFAULT NULL AFTER `profile_image`",
            $results,
            'query_compat_schema'
        );

        suEnsureColumn(
            $conn,
            'orders',
            'confirmed_at',
            "ALTER TABLE `orders` ADD COLUMN `confirmed_at` datetime DEFAULT NULL AFTER `status`",
            $results,
            'query_compat_schema'
        );

        suEnsureColumn(
            $conn,
            'orders',
            'estimated_delivery_time',
            "ALTER TABLE `orders` ADD COLUMN `estimated_delivery_time` datetime DEFAULT NULL AFTER `delivery_time`",
            $results,
            'query_compat_schema'
        );

        mysqli_query($conn, "SET SESSION sql_mode = ''");
        @mysqli_query($conn, "UPDATE `orders` SET `delivery_date` = CURDATE() WHERE CAST(`delivery_date` AS CHAR) LIKE '0000%' OR `delivery_date` IS NULL");
        @mysqli_query($conn, "ALTER TABLE `orders` MODIFY COLUMN `order_number` VARCHAR(64) NOT NULL");
        @mysqli_query($conn, "ALTER TABLE `order_items` MODIFY COLUMN `product_id` VARCHAR(64) NULL DEFAULT NULL");
        @mysqli_query($conn, "ALTER TABLE `order_items` MODIFY COLUMN `product_name` VARCHAR(255) NOT NULL");
        suAddResult($results, 'query_compat_schema.orders.order_number', 'ok', 'Column order_number size is ready.');
        suAddResult($results, 'query_compat_schema.order_items.product_id', 'ok', 'Column order_items size is ready.');
    } catch (Throwable $e) {
        $has_errors = true;
        suAddResult($results, 'query_compat_schema', 'error', $e->getMessage());
    }

    try {
        if (!suTableExists($conn, 'franchise_applications')) {
            throw new RuntimeException('Table `franchise_applications` is missing.');
        }

        suEnsureColumn(
            $conn,
            'franchise_applications',
            'region_name',
            "ALTER TABLE `franchise_applications` ADD COLUMN `region_name` VARCHAR(120) DEFAULT NULL AFTER `proposed_location`",
            $results,
            'franchise_location_schema'
        );
        suEnsureColumn(
            $conn,
            'franchise_applications',
            'region_code',
            "ALTER TABLE `franchise_applications` ADD COLUMN `region_code` VARCHAR(30) DEFAULT NULL AFTER `region_name`",
            $results,
            'franchise_location_schema'
        );
        suEnsureColumn(
            $conn,
            'franchise_applications',
            'province_name',
            "ALTER TABLE `franchise_applications` ADD COLUMN `province_name` VARCHAR(120) DEFAULT NULL AFTER `region_code`",
            $results,
            'franchise_location_schema'
        );
        suEnsureColumn(
            $conn,
            'franchise_applications',
            'province_code',
            "ALTER TABLE `franchise_applications` ADD COLUMN `province_code` VARCHAR(30) DEFAULT NULL AFTER `province_name`",
            $results,
            'franchise_location_schema'
        );
        suEnsureColumn(
            $conn,
            'franchise_applications',
            'city_name',
            "ALTER TABLE `franchise_applications` ADD COLUMN `city_name` VARCHAR(120) DEFAULT NULL AFTER `province_code`",
            $results,
            'franchise_location_schema'
        );
        suEnsureColumn(
            $conn,
            'franchise_applications',
            'city_code',
            "ALTER TABLE `franchise_applications` ADD COLUMN `city_code` VARCHAR(30) DEFAULT NULL AFTER `city_name`",
            $results,
            'franchise_location_schema'
        );
        suEnsureColumn(
            $conn,
            'franchise_applications',
            'barangay_name',
            "ALTER TABLE `franchise_applications` ADD COLUMN `barangay_name` VARCHAR(120) DEFAULT NULL AFTER `city_code`",
            $results,
            'franchise_location_schema'
        );
        suEnsureColumn(
            $conn,
            'franchise_applications',
            'barangay_code',
            "ALTER TABLE `franchise_applications` ADD COLUMN `barangay_code` VARCHAR(30) DEFAULT NULL AFTER `barangay_name`",
            $results,
            'franchise_location_schema'
        );
    } catch (Throwable $e) {
        $has_errors = true;
        suAddResult($results, 'franchise_location_schema', 'error', $e->getMessage());
    }

    try {
        suEnsureTable(
            $conn,
            'user_valid_id_documents',
            "CREATE TABLE IF NOT EXISTS `user_valid_id_documents` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `document_type` varchar(50) NOT NULL DEFAULT 'valid_id',
                `file_name` varchar(255) NOT NULL,
                `file_path` varchar(500) NOT NULL,
                `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_user_valid_id_documents_user` (`user_id`),
                CONSTRAINT `fk_user_valid_id_documents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            $results,
            'registration_valid_id_schema'
        );

        suEnsureColumn(
            $conn,
            'user_valid_id_documents',
            'document_type',
            "ALTER TABLE `user_valid_id_documents` ADD COLUMN `document_type` VARCHAR(50) NOT NULL DEFAULT 'valid_id' AFTER `user_id`",
            $results,
            'registration_valid_id_schema'
        );
        suEnsureColumn(
            $conn,
            'user_valid_id_documents',
            'file_name',
            "ALTER TABLE `user_valid_id_documents` ADD COLUMN `file_name` VARCHAR(255) NOT NULL AFTER `document_type`",
            $results,
            'registration_valid_id_schema'
        );
        suEnsureColumn(
            $conn,
            'user_valid_id_documents',
            'file_path',
            "ALTER TABLE `user_valid_id_documents` ADD COLUMN `file_path` VARCHAR(500) NOT NULL AFTER `file_name`",
            $results,
            'registration_valid_id_schema'
        );
        suEnsureColumn(
            $conn,
            'user_valid_id_documents',
            'uploaded_at',
            "ALTER TABLE `user_valid_id_documents` ADD COLUMN `uploaded_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() AFTER `file_path`",
            $results,
            'registration_valid_id_schema'
        );

        suEnsureIndex(
            $conn,
            'user_valid_id_documents',
            'PRIMARY',
            "ALTER TABLE `user_valid_id_documents` ADD PRIMARY KEY (`id`)",
            $results,
            'registration_valid_id_schema'
        );
        suEnsureIndex(
            $conn,
            'user_valid_id_documents',
            'idx_user_valid_id_documents_user',
            "ALTER TABLE `user_valid_id_documents` ADD KEY `idx_user_valid_id_documents_user` (`user_id`)",
            $results,
            'registration_valid_id_schema'
        );

        if (suColumnExists($conn, 'user_valid_id_documents', 'id')) {
            $id_column_result = mysqli_query($conn, "SHOW COLUMNS FROM `user_valid_id_documents` LIKE 'id'");
            $id_column = $id_column_result ? mysqli_fetch_assoc($id_column_result) : null;
            if (is_array($id_column) && stripos((string)($id_column['Extra'] ?? ''), 'auto_increment') === false) {
                if (!mysqli_query($conn, "ALTER TABLE `user_valid_id_documents` MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT")) {
                    suAddResult($results, 'registration_valid_id_schema.id_auto_increment', 'warning', 'Failed to set id as AUTO_INCREMENT: ' . mysqli_error($conn));
                } else {
                    suAddResult($results, 'registration_valid_id_schema.id_auto_increment', 'ok', 'Set id as AUTO_INCREMENT.');
                }
            } else {
                suAddResult($results, 'registration_valid_id_schema.id_auto_increment', 'ok', 'id already uses AUTO_INCREMENT.');
            }
        }

        $cleanup_orphans = mysqli_query(
            $conn,
            "DELETE uvid
             FROM `user_valid_id_documents` uvid
             LEFT JOIN `users` u ON u.id = uvid.user_id
             WHERE u.id IS NULL"
        );
        if (!$cleanup_orphans) {
            suAddResult($results, 'registration_valid_id_schema.orphan_cleanup', 'warning', 'Could not clean orphan valid ID metadata rows: ' . mysqli_error($conn));
        } else {
            suAddResult($results, 'registration_valid_id_schema.orphan_cleanup', 'ok', 'Orphan valid ID metadata rows cleaned: ' . mysqli_affected_rows($conn));
        }

        suEnsureForeignKey(
            $conn,
            'user_valid_id_documents',
            'fk_user_valid_id_documents_user',
            "ALTER TABLE `user_valid_id_documents`
             ADD CONSTRAINT `fk_user_valid_id_documents_user`
             FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
             ON DELETE CASCADE ON UPDATE CASCADE",
            $results,
            'registration_valid_id_schema'
        );
    } catch (Throwable $e) {
        $has_errors = true;
        suAddResult($results, 'registration_valid_id_schema', 'error', $e->getMessage());
    }

    try {
        if (!suTableExists($conn, 'employees')) {
            throw new RuntimeException('Table `employees` is missing.');
        }
        if (!suTableExists($conn, 'job_positions')) {
            throw new RuntimeException('Table `job_positions` is missing.');
        }

        suEnsureColumn(
            $conn,
            'employees',
            'position_id',
            "ALTER TABLE `employees` ADD COLUMN `position_id` INT(11) NULL AFTER `department_id`",
            $results,
            'hr_position_access_schema'
        );
        suEnsureIndex(
            $conn,
            'employees',
            'idx_employees_position_id',
            "ALTER TABLE `employees` ADD KEY `idx_employees_position_id` (`position_id`)",
            $results,
            'hr_position_access_schema'
        );

        $cleanup_employee_positions = mysqli_query(
            $conn,
            "UPDATE `employees` e
             LEFT JOIN `job_positions` jp ON jp.id = e.position_id
             SET e.position_id = NULL
             WHERE e.position_id IS NOT NULL
               AND jp.id IS NULL"
        );
        if (!$cleanup_employee_positions) {
            suAddResult($results, 'hr_position_access_schema.employee_orphan_cleanup', 'warning', 'Could not normalize orphan employee position_id values: ' . mysqli_error($conn));
        } else {
            suAddResult($results, 'hr_position_access_schema.employee_orphan_cleanup', 'ok', 'Normalized orphan employee position references: ' . mysqli_affected_rows($conn));
        }

        suEnsureForeignKey(
            $conn,
            'employees',
            'fk_employees_position_id',
            "ALTER TABLE `employees`
             ADD CONSTRAINT `fk_employees_position_id`
             FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`)
             ON DELETE SET NULL ON UPDATE CASCADE",
            $results,
            'hr_position_access_schema'
        );

        suEnsureTable(
            $conn,
            'hr_position_module_access',
            "CREATE TABLE IF NOT EXISTS `hr_position_module_access` (
                `position_id` INT(11) NOT NULL,
                `module_key` VARCHAR(100) NOT NULL,
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`position_id`, `module_key`),
                CONSTRAINT `fk_hr_position_module_access_position`
                    FOREIGN KEY (`position_id`) REFERENCES `job_positions`(`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            $results,
            'hr_position_access_schema'
        );

        suEnsureColumn(
            $conn,
            'hr_position_module_access',
            'is_enabled',
            "ALTER TABLE `hr_position_module_access` ADD COLUMN `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `module_key`",
            $results,
            'hr_position_access_schema'
        );
        suEnsureColumn(
            $conn,
            'hr_position_module_access',
            'created_at',
            "ALTER TABLE `hr_position_module_access` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() AFTER `is_enabled`",
            $results,
            'hr_position_access_schema'
        );
        suEnsureColumn(
            $conn,
            'hr_position_module_access',
            'updated_at',
            "ALTER TABLE `hr_position_module_access` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`",
            $results,
            'hr_position_access_schema'
        );

        suEnsureIndex(
            $conn,
            'hr_position_module_access',
            'PRIMARY',
            "ALTER TABLE `hr_position_module_access` ADD PRIMARY KEY (`position_id`, `module_key`)",
            $results,
            'hr_position_access_schema'
        );

        $cleanup_hr_access_orphans = mysqli_query(
            $conn,
            "DELETE hpma
             FROM `hr_position_module_access` hpma
             LEFT JOIN `job_positions` jp ON jp.id = hpma.position_id
             WHERE jp.id IS NULL"
        );
        if (!$cleanup_hr_access_orphans) {
            suAddResult($results, 'hr_position_access_schema.access_orphan_cleanup', 'warning', 'Could not clean orphan hr_position_module_access rows: ' . mysqli_error($conn));
        } else {
            suAddResult($results, 'hr_position_access_schema.access_orphan_cleanup', 'ok', 'Orphan hr_position_module_access rows cleaned: ' . mysqli_affected_rows($conn));
        }

        suEnsureForeignKey(
            $conn,
            'hr_position_module_access',
            'fk_hr_position_module_access_position',
            "ALTER TABLE `hr_position_module_access`
             ADD CONSTRAINT `fk_hr_position_module_access_position`
             FOREIGN KEY (`position_id`) REFERENCES `job_positions`(`id`)
             ON DELETE CASCADE ON UPDATE CASCADE",
            $results,
            'hr_position_access_schema'
        );
    } catch (Throwable $e) {
        $has_errors = true;
        suAddResult($results, 'hr_position_access_schema', 'error', $e->getMessage());
    }

    return ['results' => $results, 'has_errors' => $has_errors];
}

$is_cli = PHP_SAPI === 'cli';
$results = [];
$has_errors = false;
$ran_updates = false;

if (!$is_cli && (!isset($_SESSION['schema_updates_csrf']) || !is_string($_SESSION['schema_updates_csrf']) || $_SESSION['schema_updates_csrf'] === '')) {
    $_SESSION['schema_updates_csrf'] = bin2hex(random_bytes(16));
}

if ($is_cli) {
    $ran_updates = true;
    $run_output = suRunSchemaUpdates($conn);
    $results = $run_output['results'];
    $has_errors = (bool)$run_output['has_errors'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_updates'])) {
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals((string)$_SESSION['schema_updates_csrf'], $posted_token)) {
        $has_errors = true;
        $results[] = ['item' => 'request', 'status' => 'error', 'message' => 'Invalid CSRF token. Refresh the page and try again.'];
    } else {
        $ran_updates = true;
        $run_output = suRunSchemaUpdates($conn);
        $results = $run_output['results'];
        $has_errors = (bool)$run_output['has_errors'];
    }
}

if ($is_cli) {
    echo "Schema Update Results\n";
    echo "=====================\n";
    foreach ($results as $row) {
        $prefix = '[OK]';
        if ($row['status'] === 'warning') $prefix = '[WARN]';
        if ($row['status'] === 'error') $prefix = '[FAIL]';
        echo $prefix . ' ' . $row['item'] . ' - ' . $row['message'] . "\n";
    }
    echo $has_errors ? "Completed with errors.\n" : "Completed successfully.\n";
    exit($has_errors ? 1 : 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Schema Setup</title>
    <style>
        body { margin:0; font-family:"Segoe UI",Arial,sans-serif; background:#f6f8fb; color:#1f2937; padding:24px; }
        .wrap { max-width:980px; margin:0 auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:22px; box-shadow:0 10px 24px rgba(15,23,42,.08); }
        h1 { margin:0 0 6px; font-size:24px; }
        p { margin:0 0 14px; color:#4b5563; }
        .btn { border:1px solid #c62828; background:#c62828; color:#fff; border-radius:8px; padding:10px 14px; cursor:pointer; font-weight:700; }
        .btn:hover { background:#b71c1c; }
        .status-ok { color:#166534; font-weight:700; }
        .status-warning { color:#92400e; font-weight:700; }
        .status-error { color:#991b1b; font-weight:700; }
        table { width:100%; border-collapse:collapse; margin-top:16px; }
        th,td { border:1px solid #e5e7eb; padding:10px; text-align:left; vertical-align:top; }
        th { background:#f3f4f6; }
        .summary { margin-top:10px; padding:10px 12px; border-radius:8px; background:#eef2ff; border:1px solid #dbe4ff; }
        .summary.error { background:#fee2e2; border-color:#fecaca; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Complete Schema Setup</h1>
    <p>Centralized updater for missing schema/query compatibility across voucher, checkout, products, registration, and HR modules.</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['schema_updates_csrf'] ?? '')); ?>">
        <button type="submit" name="run_updates" class="btn">Run Schema Updates</button>
    </form>

    <?php if ($ran_updates || !empty($results)): ?>
        <div class="summary <?php echo $has_errors ? 'error' : ''; ?>"><?php echo $has_errors ? 'Schema updates completed with errors.' : 'Schema updates completed successfully.'; ?></div>
        <table>
            <thead>
                <tr><th>Item</th><th>Status</th><th>Message</th></tr>
            </thead>
            <tbody>
            <?php foreach ($results as $row): ?>
                <?php
                $status_class = 'status-ok';
                if (($row['status'] ?? '') === 'warning') $status_class = 'status-warning';
                if (($row['status'] ?? '') === 'error') $status_class = 'status-error';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($row['item'] ?? '')); ?></td>
                    <td class="<?php echo $status_class; ?>"><?php echo strtoupper(htmlspecialchars((string)($row['status'] ?? 'ok'))); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['message'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>

