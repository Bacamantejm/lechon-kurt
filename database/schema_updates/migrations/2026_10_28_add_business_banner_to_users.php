<?php
/**
 * Migration Runner: Add business_banner to users table for store background / hero picture.
 */
require_once dirname(__DIR__, 3) . '/includes/config.php';

echo "Running Migration: 2026_10_28_add_business_banner_to_users...\n";

if (!isset($conn) || !$conn) {
    die("Error: No database connection available.\n");
}

if (!function_exists('userAccountControlColumnExists')) {
    function userAccountControlColumnExists($conn, string $column_name): bool {
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            $res = @mysqli_query($conn, "SHOW COLUMNS FROM `users`");
            if ($res) {
                while ($r = mysqli_fetch_assoc($res)) {
                    $cols[strtolower($r['Field'])] = true;
                }
                mysqli_free_result($res);
            }
        }
        return isset($cols[strtolower($column_name)]);
    }
}

$has_banner = userAccountControlColumnExists($conn, 'business_banner');
if (!$has_banner) {
    $res = mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `business_banner` VARCHAR(255) NULL DEFAULT NULL AFTER `business_logo`");
    echo $res ? "[OK] Added business_banner to users\n" : "[SKIP/ERR] users.business_banner: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] users.business_banner\n";
}

echo "Migration completed.\n";
