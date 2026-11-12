<?php
/**
 * Migration: Remove sample, dummy, and test promotional vouchers
 * Date: 2026-11-12
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

mysqli_set_charset($conn, 'utf8mb4');

echo "Starting migration: removing sample, dummy, and test promotional vouchers...\n";

$sample_codes = "'RBINMINIVOUCHER', 'LYDIA20', 'DELIGHT25', 'MAIN10', 'JUSTINE50', 'JANNA15', 'JAKOL10'";

// 1. Remove redemptions associated with sample vouchers
@mysqli_query($conn, "DELETE FROM `partner_voucher_redemptions` WHERE `voucher_code` IN ({$sample_codes}) OR `voucher_id` IN (SELECT `id` FROM `partner_vouchers` WHERE `code` IN ({$sample_codes}))");
echo "Cleaned partner_voucher_redemptions.\n";

// 2. Remove sample vouchers from partner_vouchers
@mysqli_query($conn, "DELETE FROM `partner_vouchers` WHERE `code` IN ({$sample_codes})");
echo "Cleaned partner_vouchers.\n";

echo "Migration completed successfully!\n";
