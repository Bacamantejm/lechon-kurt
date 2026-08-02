<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_super_admin = false;
if (function_exists('isSuperAdmin') && $current_user_id > 0) {
    $is_super_admin = isSuperAdmin($conn, $current_user_id);
}
if (!$is_super_admin && (string)($_SESSION['role_name'] ?? '') === 'super_admin') {
    $is_super_admin = true;
}
if (!$is_super_admin) {
    $_SESSION['error'] = 'Tenant migration is available to Super Administrator only.';
    header('Location: index.php');
    exit;
}

$csrf_token = generateCSRFToken();
$results = [];
$has_run = false;

function migrationTableExists($conn, $table_name) {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    return $res && mysqli_num_rows($res) > 0;
}

function migrationColumnExists($conn, $table_name, $column_name) {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_col = mysqli_real_escape_string($conn, $column_name);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_col}'");
    return $res && mysqli_num_rows($res) > 0;
}

function migrationIndexExists($conn, $table_name, $index_name) {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_index = mysqli_real_escape_string($conn, $index_name);
    $res = mysqli_query($conn, "SHOW INDEX FROM `{$safe_table}` WHERE Key_name = '{$safe_index}'");
    return $res && mysqli_num_rows($res) > 0;
}

function migrationForeignKeyExists($conn, $table_name, $constraint_name) {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_constraint = mysqli_real_escape_string($conn, $constraint_name);
    $sql = "SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$safe_table}'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = '{$safe_constraint}'
            LIMIT 1";
    $res = mysqli_query($conn, $sql);
    return $res && mysqli_num_rows($res) > 0;
}

function migrationQueryScalar($conn, $query, $default = 0) {
    $res = mysqli_query($conn, $query);
    if (!$res) {
        return $default;
    }
    $row = mysqli_fetch_row($res);
    return $row[0] ?? $default;
}

function migrationAddResult(&$results, $item, $status, $message) {
    $results[] = [
        'item' => $item,
        'status' => $status,
        'message' => $message
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $has_run = true;

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        migrationAddResult($results, 'request', 'error', 'Invalid CSRF token. Refresh the page and try again.');
    } else {
        $table_steps = [
            [
                'item' => 'partner_user_links table',
                'table' => 'partner_user_links',
                'sql' => "CREATE TABLE `partner_user_links` (
                    `owner_user_id` INT(11) NOT NULL,
                    `managed_user_id` INT(11) NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`owner_user_id`, `managed_user_id`),
                    KEY `idx_partner_user_links_managed_user_id` (`managed_user_id`),
                    CONSTRAINT `fk_partner_user_links_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk_partner_user_links_managed` FOREIGN KEY (`managed_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            ],
            [
                'item' => 'commission_rules table',
                'table' => 'commission_rules',
                'sql' => "CREATE TABLE `commission_rules` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `rule_code` VARCHAR(100) NOT NULL,
                    `partner_user_id` INT(11) NULL,
                    `rule_name` VARCHAR(150) NOT NULL,
                    `scope_type` ENUM('global','partner') NOT NULL DEFAULT 'global',
                    `commission_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
                    `effective_from` DATE NOT NULL,
                    `effective_to` DATE NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `notes` TEXT NULL,
                    `created_by` INT(11) NULL,
                    `updated_by` INT(11) NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_commission_rule_code` (`rule_code`),
                    KEY `idx_commission_rules_partner_user_id` (`partner_user_id`),
                    KEY `idx_commission_rules_active_dates` (`is_active`, `effective_from`, `effective_to`),
                    CONSTRAINT `fk_commission_rules_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk_commission_rules_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                    CONSTRAINT `fk_commission_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            ],
            [
                'item' => 'partner_settlements table',
                'table' => 'partner_settlements',
                'sql' => "CREATE TABLE `partner_settlements` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `partner_user_id` INT(11) NOT NULL,
                    `commission_rule_id` INT(11) NULL,
                    `period_start` DATE NOT NULL,
                    `period_end` DATE NOT NULL,
                    `order_count` INT(11) NOT NULL DEFAULT 0,
                    `gross_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `refund_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `net_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `commission_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
                    `commission_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `partner_payout_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `settlement_status` ENUM('draft','generated','approved','paid','cancelled') NOT NULL DEFAULT 'generated',
                    `generated_at` DATETIME NULL,
                    `approved_at` DATETIME NULL,
                    `paid_at` DATETIME NULL,
                    `notes` TEXT NULL,
                    `created_by` INT(11) NULL,
                    `updated_by` INT(11) NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_partner_settlement_period` (`partner_user_id`, `period_start`, `period_end`),
                    KEY `idx_partner_settlements_rule_id` (`commission_rule_id`),
                    KEY `idx_partner_settlements_status` (`settlement_status`),
                    KEY `idx_partner_settlements_paid_at` (`paid_at`),
                    CONSTRAINT `fk_partner_settlements_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk_partner_settlements_rule` FOREIGN KEY (`commission_rule_id`) REFERENCES `commission_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                    CONSTRAINT `fk_partner_settlements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                    CONSTRAINT `fk_partner_settlements_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            ]
        ];

        foreach ($table_steps as $step) {
            $table_name = $step['table'];
            if (migrationTableExists($conn, $table_name)) {
                migrationAddResult($results, $step['item'], 'ok', 'Table already exists.');
                continue;
            }
            $ok = mysqli_query($conn, $step['sql']);
            if ($ok) {
                migrationAddResult($results, $step['item'], 'ok', 'Table created successfully.');
            } else {
                migrationAddResult($results, $step['item'], 'error', mysqli_error($conn));
            }
        }

        $schema_steps = [
            [
                'item' => 'expenses.recorded_by',
                'table' => 'expenses',
                'column' => 'recorded_by',
                'sql' => "ALTER TABLE `expenses` ADD COLUMN `recorded_by` INT(11) NULL AFTER `expense_date`"
            ],
            [
                'item' => 'products.seller_id',
                'table' => 'products',
                'column' => 'seller_id',
                'sql' => "ALTER TABLE `products` ADD COLUMN `seller_id` INT(11) NULL"
            ],
            [
                'item' => 'decisions_recommendations.created_by',
                'table' => 'decisions_recommendations',
                'column' => 'created_by',
                'sql' => "ALTER TABLE `decisions_recommendations` ADD COLUMN `created_by` INT(11) NULL"
            ],
            [
                'item' => 'orders.is_archived',
                'table' => 'orders',
                'column' => 'is_archived',
                'sql' => "ALTER TABLE `orders` ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0"
            ],
            [
                'item' => 'roles.owner_user_id',
                'table' => 'roles',
                'column' => 'owner_user_id',
                'sql' => "ALTER TABLE `roles` ADD COLUMN `owner_user_id` INT(11) NULL AFTER `department_id`"
            ],
            [
                'item' => 'users.account_control_status',
                'table' => 'users',
                'column' => 'account_control_status',
                'sql' => "ALTER TABLE `users` ADD COLUMN `account_control_status` ENUM('active','restricted','suspended','banned') NOT NULL DEFAULT 'active' AFTER `is_active`"
            ],
            [
                'item' => 'users.access_restriction_notes',
                'table' => 'users',
                'column' => 'access_restriction_notes',
                'sql' => "ALTER TABLE `users` ADD COLUMN `access_restriction_notes` TEXT NULL AFTER `account_control_status`"
            ],
            [
                'item' => 'users.access_restricted_at',
                'table' => 'users',
                'column' => 'access_restricted_at',
                'sql' => "ALTER TABLE `users` ADD COLUMN `access_restricted_at` DATETIME NULL AFTER `access_restriction_notes`"
            ],
            [
                'item' => 'users.access_restricted_by',
                'table' => 'users',
                'column' => 'access_restricted_by',
                'sql' => "ALTER TABLE `users` ADD COLUMN `access_restricted_by` INT(11) NULL AFTER `access_restricted_at`"
            ]
        ];

        foreach ($schema_steps as $step) {
            $table_name = $step['table'];
            $column_name = $step['column'];
            if (!migrationTableExists($conn, $table_name)) {
                migrationAddResult($results, $step['item'], 'skipped', "Table `{$table_name}` not found.");
                continue;
            }
            if (migrationColumnExists($conn, $table_name, $column_name)) {
                migrationAddResult($results, $step['item'], 'ok', 'Column already exists.');
                continue;
            }
            $ok = mysqli_query($conn, $step['sql']);
            if ($ok) {
                migrationAddResult($results, $step['item'], 'ok', 'Column added successfully.');
            } else {
                migrationAddResult($results, $step['item'], 'error', mysqli_error($conn));
            }
        }

        $index_steps = [
            [
                'item' => 'idx_expenses_recorded_by',
                'table' => 'expenses',
                'column' => 'recorded_by',
                'index' => 'idx_expenses_recorded_by',
                'sql' => "ALTER TABLE `expenses` ADD INDEX `idx_expenses_recorded_by` (`recorded_by`)"
            ],
            [
                'item' => 'idx_products_seller_id',
                'table' => 'products',
                'column' => 'seller_id',
                'index' => 'idx_products_seller_id',
                'sql' => "ALTER TABLE `products` ADD INDEX `idx_products_seller_id` (`seller_id`)"
            ],
            [
                'item' => 'idx_decisions_created_by',
                'table' => 'decisions_recommendations',
                'column' => 'created_by',
                'index' => 'idx_decisions_created_by',
                'sql' => "ALTER TABLE `decisions_recommendations` ADD INDEX `idx_decisions_created_by` (`created_by`)"
            ],
            [
                'item' => 'idx_orders_is_archived',
                'table' => 'orders',
                'column' => 'is_archived',
                'index' => 'idx_orders_is_archived',
                'sql' => "ALTER TABLE `orders` ADD INDEX `idx_orders_is_archived` (`is_archived`)"
            ],
            [
                'item' => 'idx_roles_owner_user_id',
                'table' => 'roles',
                'column' => 'owner_user_id',
                'index' => 'idx_roles_owner_user_id',
                'sql' => "ALTER TABLE `roles` ADD INDEX `idx_roles_owner_user_id` (`owner_user_id`)"
            ],
            [
                'item' => 'idx_users_account_control_status',
                'table' => 'users',
                'column' => 'account_control_status',
                'index' => 'idx_users_account_control_status',
                'sql' => "ALTER TABLE `users` ADD INDEX `idx_users_account_control_status` (`account_control_status`)"
            ],
            [
                'item' => 'idx_users_access_restricted_by',
                'table' => 'users',
                'column' => 'access_restricted_by',
                'index' => 'idx_users_access_restricted_by',
                'sql' => "ALTER TABLE `users` ADD INDEX `idx_users_access_restricted_by` (`access_restricted_by`)"
            ],
            [
                'item' => 'idx_partner_user_links_managed_user_id',
                'table' => 'partner_user_links',
                'column' => 'managed_user_id',
                'index' => 'idx_partner_user_links_managed_user_id',
                'sql' => "ALTER TABLE `partner_user_links` ADD INDEX `idx_partner_user_links_managed_user_id` (`managed_user_id`)"
            ],
            [
                'item' => 'pk_partner_owner_managed',
                'table' => 'partner_user_links',
                'column' => 'owner_user_id',
                'index' => 'PRIMARY',
                'sql' => "ALTER TABLE `partner_user_links` ADD PRIMARY KEY (`owner_user_id`, `managed_user_id`)"
            ]
        ];

        foreach ($index_steps as $step) {
            if (!migrationTableExists($conn, $step['table'])) {
                migrationAddResult($results, $step['item'], 'skipped', "Table `{$step['table']}` not found.");
                continue;
            }
            if (!migrationColumnExists($conn, $step['table'], $step['column'])) {
                migrationAddResult($results, $step['item'], 'skipped', "Column `{$step['column']}` does not exist yet.");
                continue;
            }
            if (migrationIndexExists($conn, $step['table'], $step['index'])) {
                migrationAddResult($results, $step['item'], 'ok', 'Index already exists.');
                continue;
            }
            $ok = mysqli_query($conn, $step['sql']);
            if ($ok) {
                migrationAddResult($results, $step['item'], 'ok', 'Index added successfully.');
            } else {
                migrationAddResult($results, $step['item'], 'error', mysqli_error($conn));
            }
        }

        $fk_steps = [
            [
                'item' => 'fk_roles_owner_user_id',
                'table' => 'roles',
                'column' => 'owner_user_id',
                'constraint' => 'fk_roles_owner_user_id',
                'sql' => "ALTER TABLE `roles` ADD CONSTRAINT `fk_roles_owner_user_id` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_users_access_restricted_by',
                'table' => 'users',
                'column' => 'access_restricted_by',
                'constraint' => 'fk_users_access_restricted_by',
                'sql' => "ALTER TABLE `users` ADD CONSTRAINT `fk_users_access_restricted_by` FOREIGN KEY (`access_restricted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_partner_user_links_owner',
                'table' => 'partner_user_links',
                'column' => 'owner_user_id',
                'constraint' => 'fk_partner_user_links_owner',
                'sql' => "ALTER TABLE `partner_user_links` ADD CONSTRAINT `fk_partner_user_links_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_partner_user_links_managed',
                'table' => 'partner_user_links',
                'column' => 'managed_user_id',
                'constraint' => 'fk_partner_user_links_managed',
                'sql' => "ALTER TABLE `partner_user_links` ADD CONSTRAINT `fk_partner_user_links_managed` FOREIGN KEY (`managed_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_commission_rules_partner_user',
                'table' => 'commission_rules',
                'column' => 'partner_user_id',
                'constraint' => 'fk_commission_rules_partner_user',
                'sql' => "ALTER TABLE `commission_rules` ADD CONSTRAINT `fk_commission_rules_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_commission_rules_created_by',
                'table' => 'commission_rules',
                'column' => 'created_by',
                'constraint' => 'fk_commission_rules_created_by',
                'sql' => "ALTER TABLE `commission_rules` ADD CONSTRAINT `fk_commission_rules_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_commission_rules_updated_by',
                'table' => 'commission_rules',
                'column' => 'updated_by',
                'constraint' => 'fk_commission_rules_updated_by',
                'sql' => "ALTER TABLE `commission_rules` ADD CONSTRAINT `fk_commission_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_partner_settlements_partner_user',
                'table' => 'partner_settlements',
                'column' => 'partner_user_id',
                'constraint' => 'fk_partner_settlements_partner_user',
                'sql' => "ALTER TABLE `partner_settlements` ADD CONSTRAINT `fk_partner_settlements_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_partner_settlements_rule',
                'table' => 'partner_settlements',
                'column' => 'commission_rule_id',
                'constraint' => 'fk_partner_settlements_rule',
                'sql' => "ALTER TABLE `partner_settlements` ADD CONSTRAINT `fk_partner_settlements_rule` FOREIGN KEY (`commission_rule_id`) REFERENCES `commission_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_partner_settlements_created_by',
                'table' => 'partner_settlements',
                'column' => 'created_by',
                'constraint' => 'fk_partner_settlements_created_by',
                'sql' => "ALTER TABLE `partner_settlements` ADD CONSTRAINT `fk_partner_settlements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"
            ],
            [
                'item' => 'fk_partner_settlements_updated_by',
                'table' => 'partner_settlements',
                'column' => 'updated_by',
                'constraint' => 'fk_partner_settlements_updated_by',
                'sql' => "ALTER TABLE `partner_settlements` ADD CONSTRAINT `fk_partner_settlements_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"
            ]
        ];

        foreach ($fk_steps as $step) {
            if (!migrationTableExists($conn, $step['table'])) {
                migrationAddResult($results, $step['item'], 'skipped', "Table `{$step['table']}` not found.");
                continue;
            }
            if (!migrationColumnExists($conn, $step['table'], $step['column'])) {
                migrationAddResult($results, $step['item'], 'skipped', "Column `{$step['column']}` does not exist yet.");
                continue;
            }
            if (migrationForeignKeyExists($conn, $step['table'], $step['constraint'])) {
                migrationAddResult($results, $step['item'], 'ok', 'Foreign key already exists.');
                continue;
            }
            $ok = mysqli_query($conn, $step['sql']);
            if ($ok) {
                migrationAddResult($results, $step['item'], 'ok', 'Foreign key added successfully.');
            } else {
                migrationAddResult($results, $step['item'], 'error', mysqli_error($conn));
            }
        }

        if (migrationTableExists($conn, 'commission_rules')) {
            $default_rule_exists = (int)migrationQueryScalar(
                $conn,
                "SELECT COUNT(*) FROM `commission_rules` WHERE `rule_code` = 'default_global_rate'",
                0
            );
            if ($default_rule_exists > 0) {
                migrationAddResult($results, 'seed_default_global_commission_rule', 'ok', 'Default global commission rule already exists.');
            } else {
                $seed_rule_sql = "INSERT INTO `commission_rules`
                    (`rule_code`, `partner_user_id`, `rule_name`, `scope_type`, `commission_percent`, `effective_from`, `effective_to`, `is_active`, `notes`, `created_by`, `updated_by`)
                    VALUES
                    ('default_global_rate', NULL, 'Default Platform Commission', 'global', 10.00, '2024-01-01', NULL, 1, 'Default global commission rule created by tenant scope migration.', {$current_user_id}, {$current_user_id})";
                $seed_ok = mysqli_query($conn, $seed_rule_sql);
                if ($seed_ok) {
                    migrationAddResult($results, 'seed_default_global_commission_rule', 'ok', 'Default global commission rule inserted.');
                } else {
                    migrationAddResult($results, 'seed_default_global_commission_rule', 'error', mysqli_error($conn));
                }
            }
        } else {
            migrationAddResult($results, 'seed_default_global_commission_rule', 'skipped', 'Table `commission_rules` not found.');
        }

        $can_backfill_settlements =
            migrationTableExists($conn, 'partner_settlements') &&
            migrationTableExists($conn, 'commission_rules') &&
            migrationTableExists($conn, 'franchise_applications') &&
            migrationTableExists($conn, 'orders') &&
            migrationTableExists($conn, 'order_items') &&
            migrationTableExists($conn, 'products') &&
            migrationTableExists($conn, 'cancellations') &&
            migrationTableExists($conn, 'refunds') &&
            migrationTableExists($conn, 'users');

        if ($can_backfill_settlements) {
            $backfill_sql = "INSERT INTO `partner_settlements`
                (`partner_user_id`, `commission_rule_id`, `period_start`, `period_end`, `order_count`, `gross_sales`, `refund_deductions`, `net_sales`, `commission_percent`, `commission_amount`, `partner_payout_amount`, `settlement_status`, `generated_at`, `approved_at`, `notes`, `created_by`, `updated_by`)
                SELECT
                    monthly_sales.partner_user_id,
                    COALESCE(partner_rule.id, default_rule.id) AS commission_rule_id,
                    monthly_sales.period_start,
                    monthly_sales.period_end,
                    monthly_sales.order_count,
                    monthly_sales.gross_sales,
                    monthly_sales.refund_deductions,
                    GREATEST(monthly_sales.gross_sales - monthly_sales.refund_deductions, 0) AS net_sales,
                    COALESCE(partner_rule.commission_percent, default_rule.commission_percent, 10.00) AS commission_percent,
                    ROUND(
                        GREATEST(monthly_sales.gross_sales - monthly_sales.refund_deductions, 0) *
                        COALESCE(partner_rule.commission_percent, default_rule.commission_percent, 10.00) / 100,
                        2
                    ) AS commission_amount,
                    ROUND(
                        GREATEST(monthly_sales.gross_sales - monthly_sales.refund_deductions, 0) -
                        ROUND(
                            GREATEST(monthly_sales.gross_sales - monthly_sales.refund_deductions, 0) *
                            COALESCE(partner_rule.commission_percent, default_rule.commission_percent, 10.00) / 100,
                            2
                        ),
                        2
                    ) AS partner_payout_amount,
                    'approved' AS settlement_status,
                    NOW() AS generated_at,
                    NOW() AS approved_at,
                    'Backfilled from historical approved partner sales by tenant scope migration.' AS notes,
                    {$current_user_id} AS created_by,
                    {$current_user_id} AS updated_by
                FROM (
                    SELECT
                        base.partner_user_id,
                        DATE_FORMAT(base.created_at, '%Y-%m-01') AS period_start,
                        LAST_DAY(base.created_at) AS period_end,
                        COUNT(DISTINCT base.order_id) AS order_count,
                        ROUND(SUM(base.seller_order_total), 2) AS gross_sales,
                        ROUND(SUM(base.allocated_refund_amount), 2) AS refund_deductions
                    FROM (
                        SELECT
                            seller_orders.partner_user_id,
                            o.id AS order_id,
                            o.created_at,
                            seller_orders.seller_order_total,
                            CASE
                                WHEN order_totals.order_total > 0
                                    THEN COALESCE(refund_alloc.refund_amount, 0) * (seller_orders.seller_order_total / order_totals.order_total)
                                ELSE 0
                            END AS allocated_refund_amount
                        FROM (
                            SELECT
                                p.seller_id AS partner_user_id,
                                oi.order_id,
                                SUM(oi.total) AS seller_order_total
                            FROM `order_items` oi
                            INNER JOIN `products` p
                                ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                            INNER JOIN `franchise_applications` fa
                                ON fa.user_id = p.seller_id
                               AND fa.status = 'approved'
                            WHERE p.seller_id IS NOT NULL
                              AND p.is_archived = 0
                            GROUP BY p.seller_id, oi.order_id
                        ) seller_orders
                        INNER JOIN `orders` o
                            ON o.id = seller_orders.order_id
                        INNER JOIN (
                            SELECT oi.order_id, SUM(oi.total) AS order_total
                            FROM `order_items` oi
                            GROUP BY oi.order_id
                        ) order_totals
                            ON order_totals.order_id = seller_orders.order_id
                        LEFT JOIN (
                            SELECT
                                c.order_id,
                                SUM(
                                    CASE
                                        WHEN r.refund_status IN ('Refund Approved', 'Refund Completed')
                                            THEN r.refund_amount
                                        ELSE 0
                                    END
                                ) AS refund_amount
                            FROM `cancellations` c
                            LEFT JOIN `refunds` r
                                ON r.cancellation_id = c.id
                            GROUP BY c.order_id
                        ) refund_alloc
                            ON refund_alloc.order_id = seller_orders.order_id
                        WHERE o.is_archived = 0
                          AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed')
                    ) base
                    GROUP BY base.partner_user_id, DATE_FORMAT(base.created_at, '%Y-%m-01'), LAST_DAY(base.created_at)
                ) monthly_sales
                LEFT JOIN `commission_rules` partner_rule
                    ON partner_rule.partner_user_id = monthly_sales.partner_user_id
                   AND partner_rule.scope_type = 'partner'
                   AND partner_rule.is_active = 1
                   AND partner_rule.effective_from <= monthly_sales.period_start
                   AND (partner_rule.effective_to IS NULL OR partner_rule.effective_to >= monthly_sales.period_end)
                LEFT JOIN `commission_rules` default_rule
                    ON default_rule.rule_code = 'default_global_rate'
                LEFT JOIN `partner_settlements` existing
                    ON existing.partner_user_id = monthly_sales.partner_user_id
                   AND existing.period_start = monthly_sales.period_start
                   AND existing.period_end = monthly_sales.period_end
                WHERE existing.id IS NULL";

            $backfill_ok = mysqli_query($conn, $backfill_sql);
            if ($backfill_ok) {
                $affected_rows = mysqli_affected_rows($conn);
                migrationAddResult($results, 'backfill_partner_settlements', 'ok', $affected_rows . ' settlement row(s) generated or already up to date.');
            } else {
                migrationAddResult($results, 'backfill_partner_settlements', 'error', mysqli_error($conn));
            }
        } else {
            migrationAddResult($results, 'backfill_partner_settlements', 'skipped', 'Required source tables for settlement backfill are not all available yet.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Scope Migration</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Tenant Scope Migration</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Admin')); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>

            <div class="admin-main p-4">
                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <strong><i class="fas fa-database"></i> Scope-Critical Schema Setup</strong>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">This tool ensures required tenant-scoping columns, partner ownership tables, commission settlement schema, and supporting indexes exist.</p>
                        <p class="mb-3"><strong>Target:</strong> <code>expenses.recorded_by</code>, <code>products.seller_id</code>, <code>decisions_recommendations.created_by</code>, <code>orders.is_archived</code>, <code>roles.owner_user_id</code>, <code>partner_user_links</code>, <code>users.account_control_status</code>, <code>users.access_restricted_by</code>, <code>commission_rules</code>, <code>partner_settlements</code>.</p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <button type="submit" name="run_migration" class="btn btn-primary">
                                <i class="fas fa-play"></i> Run Migration
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary ms-2">Back to Dashboard</a>
                        </form>
                    </div>
                </div>

                <?php if ($has_run): ?>
                    <div class="card">
                        <div class="card-header">
                            <strong><i class="fas fa-list-check"></i> Migration Results</strong>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Status</th>
                                            <th>Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($results)): ?>
                                            <tr>
                                                <td colspan="3" class="text-muted">No migration output available.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($results as $row): ?>
                                                <?php
                                                $status = (string)($row['status'] ?? 'skipped');
                                                $badge = $status === 'ok' ? 'success' : ($status === 'error' ? 'danger' : 'secondary');
                                                ?>
                                                <tr>
                                                    <td><code><?php echo htmlspecialchars((string)$row['item']); ?></code></td>
                                                    <td><span class="badge bg-<?php echo $badge; ?>"><?php echo strtoupper(htmlspecialchars($status)); ?></span></td>
                                                    <td><?php echo htmlspecialchars((string)$row['message']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
