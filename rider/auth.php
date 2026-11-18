<?php
/**
 * Rider Portal Authentication Guard & Helpers
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

function checkRiderAccess(): array {
    global $conn;

    if (!isset($_SESSION['user_id'])) {
        $curr_url = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header("Location: login.php?redirect=" . urlencode($curr_url));
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];

    // Query rider profile joined with user, employee, and hiring store details.
    // Partner links are optional on older installations.
    $has_partner_user_links = function_exists('appTableExists')
        && appTableExists($conn, 'partner_user_links');
    $partner_owner_select = $has_partner_user_links
        ? 'pul.owner_user_id AS partner_owner_id'
        : 'NULL AS partner_owner_id';
    $partner_link_join = $has_partner_user_links
        ? 'LEFT JOIN partner_user_links pul ON pul.managed_user_id = u.id'
        : '';
    $partner_store_join = $has_partner_user_links
        ? ' OR (r.store_id IS NULL AND sl.owner_user_id = pul.owner_user_id)'
        : '';

    $sql = "
        SELECT r.*, e.id AS matched_emp_id, e.store_id AS emp_store_id,
               u.full_name, u.email, u.phone AS user_phone, u.profile_image, u.user_type,
               e.first_name, e.last_name, e.phone AS emp_phone, e.vehicle_details AS emp_vehicle,
               sl.store_id AS resolved_store_id, sl.store_name, sl.address AS store_address, sl.city AS store_city, sl.owner_user_id AS store_owner_id,
               {$partner_owner_select}
        FROM riders r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN employees e ON (r.employee_id = e.id OR e.user_id = u.id OR (u.email IS NOT NULL AND u.email != '' AND e.email = u.email))
        {$partner_link_join}
        LEFT JOIN store_locations sl ON (r.store_id = sl.store_id{$partner_store_join})
        WHERE r.user_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("System error: Unable to verify rider access.");
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rider = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    require_once __DIR__ . '/../includes/rider_helper.php';

    if (!$rider || $rider['verification_status'] !== 'verified') {
        if (function_exists('isDeliveryDriverUser') && isDeliveryDriverUser($conn, $user_id)) {
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $rider = mysqli_fetch_assoc($res);
                mysqli_stmt_close($stmt);
            }
        }
    }

    if (!$rider) {
        // Not a registered rider
        session_destroy();
        header("Location: login.php?error=" . urlencode("Access denied. No active delivery rider profile found for this account."));
        exit;
    }

    // Auto-link rider to employee record if unlinked but matched
    if (empty($rider['employee_id']) && !empty($rider['matched_emp_id'])) {
        $matched_id = (int)$rider['matched_emp_id'];
        mysqli_query($conn, "UPDATE riders SET employee_id = $matched_id WHERE id = " . (int)$rider['id'] . " LIMIT 1");
        $rider['employee_id'] = $matched_id;
    }

    // Check verification status
    if ($rider['verification_status'] === 'pending') {
        header("Location: login.php?msg=" . urlencode("Your rider account is pending verification by the platform administrator."));
        exit;
    } elseif ($rider['verification_status'] === 'rejected') {
        header("Location: login.php?error=" . urlencode("Your rider application has been rejected or suspended. Please contact support."));
        exit;
    }

    // Auto-resolve hiring store strictly from tenant hiring records (NEVER fall back to Store 1 or another shop)
    $hiring_store_id = 0;
    $hiring_owner_id = 0;
    $store_name = '';

    $partner_owner_id = (int)($rider['partner_owner_id'] ?? 0);
    $emp_store_id = (int)($rider['emp_store_id'] ?? 0);
    $rider_store_id = (int)($rider['store_id'] ?? 0);

    // 1. If partner_user_links defines the hiring owner, that owner is authoritative
    if ($partner_owner_id > 0) {
        $hiring_owner_id = $partner_owner_id;
        // Prefer employee's store_id if it belongs to this owner
        if ($emp_store_id > 0) {
            $sl_chk = @mysqli_query($conn, "SELECT store_id, store_name, owner_user_id, address, city FROM store_locations WHERE store_id = {$emp_store_id} AND owner_user_id = {$partner_owner_id} LIMIT 1");
            if ($sl_chk && ($sl_row = mysqli_fetch_assoc($sl_chk))) {
                $hiring_store_id = (int)$sl_row['store_id'];
                $store_name = (string)$sl_row['store_name'];
                $rider['store_address'] = $sl_row['address'];
                $rider['store_city'] = $sl_row['city'];
            }
        }
        // Otherwise pick the hiring owner's active store branch
        if ($hiring_store_id <= 0) {
            $sl_q = @mysqli_query($conn, "SELECT store_id, store_name, owner_user_id, address, city FROM store_locations WHERE owner_user_id = {$partner_owner_id} ORDER BY is_active DESC, store_id ASC LIMIT 1");
            if ($sl_q && ($sl_row = mysqli_fetch_assoc($sl_q))) {
                $hiring_store_id = (int)$sl_row['store_id'];
                $store_name = (string)$sl_row['store_name'];
                $rider['store_address'] = $sl_row['address'];
                $rider['store_city'] = $sl_row['city'];
            }
        }
    }

    // 2. Otherwise use employee's assigned store_id (authoritative HR assignment)
    if ($hiring_store_id <= 0 && $emp_store_id > 0) {
        $sl_q = @mysqli_query($conn, "SELECT store_id, store_name, owner_user_id, address, city FROM store_locations WHERE store_id = {$emp_store_id} LIMIT 1");
        if ($sl_q && ($sl_row = mysqli_fetch_assoc($sl_q))) {
            $hiring_store_id = (int)$sl_row['store_id'];
            $hiring_owner_id = (int)($sl_row['owner_user_id'] ?? 0);
            $store_name = (string)$sl_row['store_name'];
            $rider['store_address'] = $sl_row['address'];
            $rider['store_city'] = $sl_row['city'];
        }
    }

    // 3. Otherwise use existing riders.store_id / riders.store_owner_id
    if ($hiring_store_id <= 0 && $rider_store_id > 0) {
        $sl_q = @mysqli_query($conn, "SELECT store_id, store_name, owner_user_id, address, city FROM store_locations WHERE store_id = {$rider_store_id} LIMIT 1");
        if ($sl_q && ($sl_row = mysqli_fetch_assoc($sl_q))) {
            $hiring_store_id = (int)$sl_row['store_id'];
            $hiring_owner_id = (int)($sl_row['owner_user_id'] ?? ($rider['store_owner_id'] ?? 0));
            $store_name = (string)$sl_row['store_name'];
            $rider['store_address'] = $sl_row['address'];
            $rider['store_city'] = $sl_row['city'];
        }
    }

    // 4. Fallback: infer from role ownership (roles.owner_user_id)
    if ($hiring_store_id <= 0) {
        $role_sl_q = @mysqli_query($conn, "
            SELECT sl.store_id, sl.store_name, sl.owner_user_id, sl.address, sl.city
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            INNER JOIN store_locations sl ON sl.owner_user_id = r.owner_user_id
            WHERE u.id = {$user_id} AND r.owner_user_id IS NOT NULL AND r.owner_user_id > 0
            ORDER BY sl.is_active DESC, sl.store_id ASC
            LIMIT 1
        ");
        if ($role_sl_q && ($sl_row = mysqli_fetch_assoc($role_sl_q))) {
            $hiring_store_id = (int)$sl_row['store_id'];
            $hiring_owner_id = (int)($sl_row['owner_user_id'] ?? 0);
            $store_name = (string)$sl_row['store_name'];
            $rider['store_address'] = $sl_row['address'];
            $rider['store_city'] = $sl_row['city'];
        }
    }

    // Auto-heal mismatched riders.store_id / riders.store_owner_id / employees.store_id
    if ($hiring_store_id > 0) {
        $has_r_owner_col = false;
        $col_r_chk = @mysqli_query($conn, "SHOW COLUMNS FROM `riders` LIKE 'store_owner_id'");
        if ($col_r_chk && mysqli_num_rows($col_r_chk) > 0) {
            $has_r_owner_col = true;
        }
        $needs_rider_sync = ($rider_store_id !== $hiring_store_id)
            || ($has_r_owner_col && $hiring_owner_id > 0 && (int)($rider['store_owner_id'] ?? 0) !== $hiring_owner_id);
        if ($needs_rider_sync) {
            $owner_sql = ($has_r_owner_col && $hiring_owner_id > 0) ? ", store_owner_id = {$hiring_owner_id}" : "";
            @mysqli_query($conn, "UPDATE riders SET store_id = {$hiring_store_id}, rider_type = 'shop_rider'{$owner_sql} WHERE id = " . (int)$rider['id'] . " LIMIT 1");
        }
        if (!empty($rider['employee_id']) && $emp_store_id !== $hiring_store_id && $partner_owner_id > 0) {
            @mysqli_query($conn, "UPDATE employees SET store_id = {$hiring_store_id} WHERE id = " . (int)$rider['employee_id'] . " LIMIT 1");
        }
    }

    // Collect all store_ids belonging to this hiring shop owner (for multi-branch owners)
    $hiring_owner_store_ids = [];
    if ($hiring_store_id > 0) {
        $hiring_owner_store_ids[$hiring_store_id] = true;
    }
    if ($hiring_owner_id > 0) {
        $all_st_q = @mysqli_query($conn, "SELECT store_id FROM store_locations WHERE owner_user_id = {$hiring_owner_id}");
        while ($all_st_q && ($as_row = mysqli_fetch_assoc($all_st_q))) {
            $sid = (int)($as_row['store_id'] ?? 0);
            if ($sid > 0) {
                $hiring_owner_store_ids[$sid] = true;
            }
        }
    }

    $rider['store_id'] = $hiring_store_id;
    $rider['store_name'] = $store_name ?: ($hiring_store_id > 0 ? ('Store #' . $hiring_store_id) : 'Unassigned Store');
    $rider['store_owner_id'] = $hiring_owner_id;
    $rider['owner_store_ids'] = array_map('intval', array_keys($hiring_owner_store_ids));
    $rider['rider_type'] = 'shop_rider';

    $_SESSION['store_id'] = $hiring_store_id;
    $_SESSION['store_name'] = $rider['store_name'];
    $_SESSION['store_owner_id'] = $hiring_owner_id;

    // Compute display name from employee or user records
    $first_last = trim(($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? ''));
    $full_name = trim((string)($rider['full_name'] ?? ''));
    $session_name = trim((string)($_SESSION['full_name'] ?? ''));

    if ($first_last !== '') {
        $display_name = ucwords(strtolower($first_last));
    } elseif ($full_name !== '') {
        $display_name = ucwords(strtolower($full_name));
    } elseif ($session_name !== '') {
        $display_name = ucwords(strtolower($session_name));
    } else {
        $display_name = 'Delivery Rider';
    }

    // Set convenience session indicators & populate rider_name in rider array
    $rider['rider_name'] = $display_name;
    $_SESSION['is_driver'] = true;
    $_SESSION['rider_id'] = (int)$rider['id'];
    $_SESSION['rider_code'] = $rider['rider_code'];
    $_SESSION['rider_name'] = $display_name;

    return $rider;
}

function getRiderActiveDelivery(int $rider_id, ?int $employee_id = null): ?array {
    global $conn;

    $driver_clause = "lt.driver_id = ?";
    $params = [$rider_id];
    $types = "i";

    if ($employee_id && $employee_id > 0) {
        $driver_clause = "(lt.driver_id = ? OR lt.driver_id = ?)";
        $params = [$rider_id, $employee_id];
        $types = "ii";
    }

    $sql = "
        SELECT lt.*,
               o.order_number, o.customer_name, o.customer_phone, o.customer_email,
               o.delivery_address, o.latitude AS customer_latitude, o.longitude AS customer_longitude,
               o.delivery_instructions, o.special_instructions, o.total_amount, o.delivery_fee,
               o.payment_method, o.payment_status, o.delivery_pin, o.pickup_location,
               sl.store_name, sl.address AS store_address, sl.city AS store_city,
               sl.latitude AS store_latitude, sl.longitude AS store_longitude
        FROM logistics_tracking lt
        JOIN orders o ON lt.order_id = o.id
        LEFT JOIN store_locations sl ON o.pickup_location = sl.store_id
        WHERE $driver_clause
          AND lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving')
        ORDER BY lt.updated_at DESC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $active = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    return $active ?: null;
}

function riderBuildShopOrderFilterSql(mysqli $conn, array $rider, string $orderAlias = 'o'): string {
    $hiring_store_id = (int)($rider['store_id'] ?? 0);
    $hiring_owner_id = (int)($rider['store_owner_id'] ?? 0);
    $owner_store_ids = array_values(array_unique(array_filter(array_map('intval', $rider['owner_store_ids'] ?? ($hiring_store_id > 0 ? [$hiring_store_id] : [])))));

    if ($hiring_store_id <= 0 && $hiring_owner_id <= 0) {
        return "AND 1 = 0";
    }

    $store_csv = !empty($owner_store_ids) ? implode(',', $owner_store_ids) : '';

    $match_clauses = [];
    if ($store_csv !== '') {
        $match_clauses[] = "{$orderAlias}.store_id IN ({$store_csv})";
        $match_clauses[] = "({$orderAlias}.pickup_location REGEXP '^[0-9]+$' AND CAST({$orderAlias}.pickup_location AS UNSIGNED) IN ({$store_csv}))";
    }
    if ($hiring_owner_id > 0) {
        $match_clauses[] = "({$orderAlias}.seller_id IS NOT NULL AND {$orderAlias}.seller_id = {$hiring_owner_id})";
        $match_clauses[] = "(
            ({$orderAlias}.seller_id IS NULL OR {$orderAlias}.seller_id = 0 OR {$orderAlias}.seller_id = {$hiring_owner_id})
            AND EXISTS (
                SELECT 1
                FROM order_items oi_rider_scope
                INNER JOIN products p_rider_scope
                    ON (
                        oi_rider_scope.product_id COLLATE utf8mb4_general_ci = p_rider_scope.product_id COLLATE utf8mb4_general_ci
                        OR oi_rider_scope.product_id COLLATE utf8mb4_general_ci = CAST(p_rider_scope.id AS CHAR) COLLATE utf8mb4_general_ci
                        OR CAST(oi_rider_scope.product_id AS UNSIGNED) = p_rider_scope.id
                    )
                WHERE oi_rider_scope.order_id = {$orderAlias}.id
                  AND p_rider_scope.seller_id = {$hiring_owner_id}
            )
        )";
    }

    if (empty($match_clauses)) {
        return "AND 1 = 0";
    }

    // Explicit cross-tenant guards: NEVER allow orders belonging to another shop owner or store
    $guards = [];
    if ($hiring_owner_id > 0) {
        $guards[] = "({$orderAlias}.seller_id IS NULL OR {$orderAlias}.seller_id = 0 OR {$orderAlias}.seller_id = {$hiring_owner_id})";
    }
    if ($store_csv !== '') {
        $guards[] = "({$orderAlias}.store_id IS NULL OR {$orderAlias}.store_id = 0 OR {$orderAlias}.store_id IN ({$store_csv}))";
    }

    $guard_sql = !empty($guards) ? (' AND ' . implode(' AND ', $guards)) : '';
    return "AND (" . implode(' OR ', $match_clauses) . ")" . $guard_sql;
}

function riderIsOrderFromHiringShop(mysqli $conn, array $rider, int $orderId): bool {
    if ($orderId <= 0) {
        return false;
    }
    $filter_sql = riderBuildShopOrderFilterSql($conn, $rider, 'o');
    if ($filter_sql === 'AND 1 = 0') {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT o.id FROM orders o WHERE o.id = ? {$filter_sql} LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "i", $orderId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = (bool)($res && mysqli_fetch_assoc($res));
    mysqli_stmt_close($stmt);
    return $ok;
}

function formatRiderPeso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}
