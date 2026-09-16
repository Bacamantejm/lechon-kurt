<?php
/**
 * Transaction Fee & Subscription Monetization Helper
 *
 * Calculates per-transaction platform fees based on the shop owner's
 * active subscription plan (Starter: 7.5% + ₱5, Growth: 6.0% + ₱3, Pro: 4.5% + ₱2)
 * or configured platform fee rules.
 */

if (!function_exists('tfTableExists')) {
    function tfTableExists(mysqli $conn, string $tableName): bool
    {
        static $cache = [];
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }
        $escaped = mysqli_real_escape_string($conn, $tableName);
        $res = @mysqli_query($conn, "SHOW TABLES LIKE '{$escaped}'");
        $exists = (bool)($res && mysqli_num_rows($res) > 0);
        $cache[$tableName] = $exists;
        return $exists;
    }
}

if (!function_exists('tfColumnExists')) {
    function tfColumnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . '.' . $columnName;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (!tfTableExists($conn, $tableName)) {
            $cache[$key] = false;
            return false;
        }
        $escapedTable = mysqli_real_escape_string($conn, $tableName);
        $escapedColumn = mysqli_real_escape_string($conn, $columnName);
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
        $exists = (bool)($res && mysqli_num_rows($res) > 0);
        $cache[$key] = $exists;
        return $exists;
    }
}

if (!function_exists('tfEnsureOrderTransactionFeeSchema')) {
    function tfEnsureOrderTransactionFeeSchema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        if (!tfTableExists($conn, 'orders')) {
            return;
        }

        $columnsToAdd = [
            'seller_id' => "ALTER TABLE `orders` ADD COLUMN `seller_id` INT NULL DEFAULT NULL AFTER `user_id`",
            'platform_fee_rate_percent' => "ALTER TABLE `orders` ADD COLUMN `platform_fee_rate_percent` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`",
            'platform_fee_flat' => "ALTER TABLE `orders` ADD COLUMN `platform_fee_flat` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `platform_fee_rate_percent`",
            'platform_fee_amount' => "ALTER TABLE `orders` ADD COLUMN `platform_fee_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `platform_fee_flat`",
            'platform_fee_plan_name' => "ALTER TABLE `orders` ADD COLUMN `platform_fee_plan_name` VARCHAR(80) DEFAULT NULL AFTER `platform_fee_amount`",
            'net_seller_payout' => "ALTER TABLE `orders` ADD COLUMN `net_seller_payout` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `platform_fee_plan_name`",
        ];

        foreach ($columnsToAdd as $col => $sql) {
            if (!tfColumnExists($conn, 'orders', $col)) {
                @mysqli_query($conn, $sql);
            }
        }
    }
}

if (!function_exists('tfGetShopOwnerSubscriptionFeeRate')) {
    /**
     * Look up the fee rate for a shop owner based on their active subscription plan.
     *
     * @param mysqli $conn
     * @param int $shopOwnerId User ID of the franchise shop owner
     * @return array Fee configuration: fee_percent, fee_flat, plan_name, source
     */
    function tfGetShopOwnerSubscriptionFeeRate(mysqli $conn, int $shopOwnerId): array
    {
        $defaultResult = [
            'has_subscription' => false,
            'plan_id' => null,
            'plan_code' => 'standard',
            'plan_name' => 'Standard Rate',
            'fee_percent' => 6.00,
            'fee_flat' => 2.00,
            'source' => 'default_rate',
        ];

        if ($shopOwnerId <= 0) {
            return $defaultResult;
        }

        // 1. Check active subscription plan in partner_plan_subscriptions
        if (tfTableExists($conn, 'partner_plan_subscriptions') && tfTableExists($conn, 'platform_subscription_plans')) {
            $subQuery = "SELECT s.id AS subscription_id, s.subscription_status,
                                p.id AS plan_id, p.plan_code, p.plan_name,
                                p.included_order_fee_percent, p.included_order_fee_flat
                         FROM partner_plan_subscriptions s
                         INNER JOIN platform_subscription_plans p ON s.plan_id = p.id
                         WHERE s.partner_user_id = ?
                           AND s.subscription_status IN ('active', 'trial')
                         ORDER BY s.id DESC
                         LIMIT 1";
            $stmt = @mysqli_prepare($conn, $subQuery);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $shopOwnerId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $row = $res ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($stmt);

                if ($row) {
                    return [
                        'has_subscription' => true,
                        'plan_id' => (int)$row['plan_id'],
                        'plan_code' => (string)$row['plan_code'],
                        'plan_name' => (string)$row['plan_name'],
                        'fee_percent' => (float)$row['included_order_fee_percent'],
                        'fee_flat' => (float)$row['included_order_fee_flat'],
                        'source' => 'subscription_plan',
                        'subscription_status' => (string)$row['subscription_status'],
                    ];
                }
            }
        }

        // 2. Check partner-specific rule in platform_fee_rules
        if (tfTableExists($conn, 'platform_fee_rules')) {
            $ruleQuery = "SELECT id, rule_name, fee_percent, fee_flat_per_order
                          FROM platform_fee_rules
                          WHERE is_active = 1
                            AND partner_user_id = ?
                            AND effective_from <= CURDATE()
                            AND (effective_to IS NULL OR effective_to = '0000-00-00' OR effective_to >= CURDATE())
                          ORDER BY id DESC LIMIT 1";
            $stmt = @mysqli_prepare($conn, $ruleQuery);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $shopOwnerId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $row = $res ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($stmt);

                if ($row) {
                    return [
                        'has_subscription' => false,
                        'plan_id' => null,
                        'plan_code' => 'custom_rule',
                        'plan_name' => (string)($row['rule_name'] ?? 'Partner Custom Rate'),
                        'fee_percent' => (float)$row['fee_percent'],
                        'fee_flat' => (float)$row['fee_flat_per_order'],
                        'source' => 'custom_rule',
                    ];
                }
            }

            // 3. Check global rule in platform_fee_rules
            $globalQuery = "SELECT id, rule_name, fee_percent, fee_flat_per_order
                            FROM platform_fee_rules
                            WHERE is_active = 1
                              AND rule_scope = 'global'
                              AND effective_from <= CURDATE()
                              AND (effective_to IS NULL OR effective_to = '0000-00-00' OR effective_to >= CURDATE())
                            ORDER BY id DESC LIMIT 1";
            $res = @mysqli_query($conn, $globalQuery);
            if ($res && $row = mysqli_fetch_assoc($res)) {
                return [
                    'has_subscription' => false,
                    'plan_id' => null,
                    'plan_code' => 'global_rule',
                    'plan_name' => (string)($row['rule_name'] ?? 'Global Rate'),
                    'fee_percent' => (float)$row['fee_percent'],
                    'fee_flat' => (float)$row['fee_flat_per_order'],
                    'source' => 'global_rule',
                ];
            }
        }

        return $defaultResult;
    }
}

if (!function_exists('tfCalculateTransactionFee')) {
    /**
     * Calculate transaction fee and net seller payout for an order.
     *
     * @param mysqli $conn
     * @param int $shopOwnerId User ID of shop owner (0 if central store)
     * @param float $subtotal Order subtotal
     * @return array Calculation breakdown
     */
    function tfCalculateTransactionFee(mysqli $conn, int $shopOwnerId, float $subtotal): array
    {
        $rateConfig = tfGetShopOwnerSubscriptionFeeRate($conn, $shopOwnerId);
        $subtotal = round(max(0.0, $subtotal), 2);

        $feePercent = (float)($rateConfig['fee_percent'] ?? 0.0);
        $feeFlat = (float)($rateConfig['fee_flat'] ?? 0.0);

        // Calculate: (subtotal * percent / 100) + flat
        $percentAmount = round($subtotal * ($feePercent / 100.0), 2);
        $feeAmount = round($percentAmount + $feeFlat, 2);

        // Platform fee cannot exceed subtotal
        if ($feeAmount > $subtotal && $subtotal > 0) {
            $feeAmount = $subtotal;
        }

        $netPayout = round(max(0.0, $subtotal - $feeAmount), 2);

        return [
            'shop_owner_id' => $shopOwnerId,
            'has_subscription' => (bool)$rateConfig['has_subscription'],
            'plan_code' => (string)$rateConfig['plan_code'],
            'plan_name' => (string)$rateConfig['plan_name'],
            'fee_percent' => $feePercent,
            'fee_flat' => $feeFlat,
            'subtotal' => $subtotal,
            'percent_amount' => $percentAmount,
            'fee_amount' => $feeAmount,
            'net_payout' => $netPayout,
            'rate_label' => number_format($feePercent, 2) . '% + PHP ' . number_format($feeFlat, 2),
            'source' => (string)$rateConfig['source'],
        ];
    }
}
