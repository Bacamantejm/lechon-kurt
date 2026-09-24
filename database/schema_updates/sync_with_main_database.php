<?php
/**
 * Safe Database Sync with Main Branch
 * 
 * Synchronizes schema and missing reference data from main branch lechon_db_latest.sql
 * without overwriting, truncating, or dropping existing local orders, users, or custom data.
 */

$is_cli = PHP_SAPI === 'cli';
require_once __DIR__ . '/../../includes/config.php';

echo "========================================================\n";
echo "SAFE DATABASE SYNC: lechon_db <= main branch schema/data\n";
echo "========================================================\n\n";

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

$db_name = 'lechon_db';
$source_db = 'lechon_db_main_source';

// Step 1: Ensure partner_payout_accounts table exists
echo "[Step 1] Ensuring `partner_payout_accounts` table exists...\n";
$create_payout_tbl = "
CREATE TABLE IF NOT EXISTS `partner_payout_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `partner_user_id` int NOT NULL,
  `payout_method` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank_transfer',
  `account_holder` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `financial_institution` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(140) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number_masked` varchar(140) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `routing_reference` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_user_id` (`partner_user_id`),
  KEY `idx_payout_method` (`payout_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
if (mysqli_query($conn, $create_payout_tbl)) {
    echo " -> OK: `partner_payout_accounts` is ready.\n";
} else {
    echo " -> FAILED to create `partner_payout_accounts`: " . mysqli_error($conn) . "\n";
}

// Step 2: Ensure owner_user_id column & scoped indexes exist on all 9 operational tables
echo "\n[Step 2] Updating operational tables with `owner_user_id` scope...\n";
$ops_tables = [
    'operational_alerts' => [
        'col' => "ALTER TABLE `operational_alerts` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0 AFTER `id`",
        'idx' => "ALTER TABLE `operational_alerts` ADD UNIQUE KEY `uniq_operational_alert_scope` (`alert_key`, `owner_user_id`)"
    ],
    'operational_announcements' => [
        'col' => "ALTER TABLE `operational_announcements` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0",
        'idx' => "ALTER TABLE `operational_announcements` ADD KEY `idx_ops_announcements_owner` (`owner_user_id`)"
    ],
    'operational_backup_log' => [
        'col' => "ALTER TABLE `operational_backup_log` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0",
        'idx' => "ALTER TABLE `operational_backup_log` ADD KEY `idx_ops_backup_owner` (`owner_user_id`)"
    ],
    'operational_content_queue' => [
        'col' => "ALTER TABLE `operational_content_queue` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0",
        'idx' => "ALTER TABLE `operational_content_queue` ADD KEY `idx_ops_content_owner` (`owner_user_id`)"
    ],
    'operational_incidents' => [
        'col' => "ALTER TABLE `operational_incidents` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0",
        'idx' => "ALTER TABLE `operational_incidents` ADD KEY `idx_ops_incidents_owner` (`owner_user_id`)"
    ],
    'operational_jobs' => [
        'col' => "ALTER TABLE `operational_jobs` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0",
        'idx' => "ALTER TABLE `operational_jobs` ADD KEY `idx_ops_jobs_owner` (`owner_user_id`)"
    ],
    'operational_metric_snapshots' => [
        'col' => "ALTER TABLE `operational_metric_snapshots` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0",
        'idx' => "ALTER TABLE `operational_metric_snapshots` ADD UNIQUE KEY `uniq_operational_snapshot_scope` (`snapshot_date`, `snapshot_hour`, `owner_user_id`)"
    ],
    'operational_rules' => [
        'col' => "ALTER TABLE `operational_rules` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0 AFTER `created_by`",
        'idx' => "ALTER TABLE `operational_rules` ADD UNIQUE KEY `uniq_operational_rule_scope` (`rule_name`, `owner_user_id`)"
    ],
    'operational_watchlist' => [
        'col' => "ALTER TABLE `operational_watchlist` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0",
        'idx' => "ALTER TABLE `operational_watchlist` ADD KEY `idx_ops_watchlist_owner` (`owner_user_id`)"
    ],
];

foreach ($ops_tables as $tbl => $def) {
    // Check if column exists
    $chk_col = mysqli_query($conn, "SHOW COLUMNS FROM `$tbl` LIKE 'owner_user_id'");
    if (mysqli_num_rows($chk_col) === 0) {
        if (mysqli_query($conn, $def['col'])) {
            echo " -> Added `owner_user_id` to `$tbl`.\n";
        } else {
            echo " -> FAILED to add `owner_user_id` to `$tbl`: " . mysqli_error($conn) . "\n";
        }
    } else {
        echo " -> Column `owner_user_id` already exists in `$tbl`.\n";
    }

    // Check if index exists
    if (!empty($def['idx'])) {
        preg_match('/(?:KEY|UNIQUE KEY)\s+`([^`]+)`/', $def['idx'], $idx_match);
        $idx_name = $idx_match[1] ?? '';
        $chk_idx = mysqli_query($conn, "SHOW INDEX FROM `$tbl` WHERE Key_name = '$idx_name'");
        if ($chk_idx && mysqli_num_rows($chk_idx) === 0) {
            if (mysqli_query($conn, $def['idx'])) {
                echo " -> Added index `$idx_name` to `$tbl`.\n";
            } else {
                echo " -> Warning: index `$idx_name` on `$tbl`: " . mysqli_error($conn) . "\n";
            }
        }
    }
}

// Step 3: Safely synchronize missing records from main branch staging database
echo "\n[Step 3] Merging reference/seed data from main branch...\n";

// Disable foreign key checks temporarily for clean order-independent insert of missing seeds
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0;");

$sync_tables = [
    'users' => 'id',
    'store_locations' => 'store_id',
    'shop_preorder_schedules' => 'id',
    'food_delivery_integrations' => 'id',
    'partner_plan_subscriptions' => 'id',
    'partner_user_links' => 'id',
    'partner_subscription_requests' => 'id',
    'partner_billing_invoices' => 'id',
    'partner_invoice_payment_sessions' => 'id',
    'expenses' => 'id',
    'user_saved_addresses' => 'id'
];

foreach ($sync_tables as $t => $pk) {
    // Check if source table exists
    $chk = mysqli_query($conn, "SHOW TABLES FROM `$source_db` LIKE '$t'");
    if (!$chk || mysqli_num_rows($chk) == 0) continue;

    // Get live PKs
    $live_pks = [];
    $res = mysqli_query($conn, "SELECT `$pk` FROM `$db_name`.`$t`");
    if ($res) {
        while ($r = mysqli_fetch_row($res)) {
            $live_pks[] = $r[0];
        }
    }

    // Find missing in source
    $missing_query = "SELECT * FROM `$source_db`.`$t`";
    $source_rows = mysqli_query($conn, $missing_query);
    $inserted_count = 0;

    if ($source_rows) {
        while ($row = mysqli_fetch_assoc($source_rows)) {
            $row_pk = $row[$pk];
            if (!in_array($row_pk, $live_pks)) {
                // Construct safe insert
                $cols = array_map(function($c) { return "`$c`"; }, array_keys($row));
                $vals = array_map(function($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    return "'" . mysqli_real_escape_string($conn, $v) . "'";
                }, array_values($row));

                $ins_sql = "INSERT INTO `$db_name`.`$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                if (mysqli_query($conn, $ins_sql)) {
                    $inserted_count++;
                } else {
                    echo "   ! Insert failed in `$t` for PK $row_pk: " . mysqli_error($conn) . "\n";
                }
            }
        }
    }

    echo " -> `$t`: merged $inserted_count missing records from main.\n";
}

mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1;");

echo "\n========================================================\n";
echo "DATABASE SYNC COMPLETED SUCCESSFULLY!\n";
echo "========================================================\n";
