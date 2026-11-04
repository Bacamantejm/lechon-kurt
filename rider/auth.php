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

    // Auto-resolve hiring store and enforce strict shop_rider tenant isolation
    $hiring_store_id = (int)($rider['store_id'] ?? 0);
    $hiring_owner_id = (int)($rider['store_owner_id'] ?? 0);
    $store_name = $rider['store_name'] ?? '';

    if ($hiring_store_id <= 0) {
        if (!empty($rider['resolved_store_id']) && (int)$rider['resolved_store_id'] > 0) {
            $hiring_store_id = (int)$rider['resolved_store_id'];
        } elseif (!empty($rider['emp_store_id']) && (int)$rider['emp_store_id'] > 0) {
            $hiring_store_id = (int)$rider['emp_store_id'];
        } elseif (!empty($rider['partner_owner_id']) && (int)$rider['partner_owner_id'] > 0) {
            $sl_q = mysqli_query($conn, "SELECT store_id, store_name, owner_user_id, address, city FROM store_locations WHERE owner_user_id = " . (int)$rider['partner_owner_id'] . " LIMIT 1");
            if ($sl_q && ($sl_row = mysqli_fetch_assoc($sl_q))) {
                $hiring_store_id = (int)$sl_row['store_id'];
                $hiring_owner_id = (int)$sl_row['owner_user_id'];
                $store_name = $sl_row['store_name'];
                $rider['store_address'] = $sl_row['address'];
                $rider['store_city'] = $sl_row['city'];
            }
        }

        // If still unassigned, check order history
        if ($hiring_store_id <= 0 && !empty($rider['id'])) {
            $past_q = mysqli_query($conn, "
                SELECT COALESCE(o.pickup_location, o.store_id) AS s_id
                FROM logistics_tracking lt
                JOIN orders o ON lt.order_id = o.id
                WHERE lt.driver_id = " . (int)$rider['id'] . " AND (o.pickup_location > 0 OR o.store_id > 0)
                ORDER BY lt.id DESC LIMIT 1
            ");
            if ($past_q && ($past_row = mysqli_fetch_assoc($past_q)) && !empty($past_row['s_id'])) {
                $hiring_store_id = (int)$past_row['s_id'];
            }
        }

        // Fallback default: Store 1 (Dasmariñas Branch)
        if ($hiring_store_id <= 0) {
            $hiring_store_id = 1;
        }

        // Persist resolved store to riders table
        mysqli_query($conn, "UPDATE riders SET store_id = $hiring_store_id, rider_type = 'shop_rider' WHERE id = " . (int)$rider['id'] . " LIMIT 1");
        $rider['store_id'] = $hiring_store_id;
        $rider['rider_type'] = 'shop_rider';

        // Re-fetch store details if not yet loaded
        if (empty($store_name)) {
            $sl_q2 = mysqli_query($conn, "SELECT store_name, owner_user_id, address, city FROM store_locations WHERE store_id = $hiring_store_id LIMIT 1");
            if ($sl_q2 && ($sl2 = mysqli_fetch_assoc($sl_q2))) {
                $store_name = $sl2['store_name'];
                $hiring_owner_id = (int)$sl2['owner_user_id'];
                $rider['store_address'] = $sl2['address'];
                $rider['store_city'] = $sl2['city'];
            }
        }
    }

    if ($hiring_owner_id <= 0 && $hiring_store_id > 0) {
        $owner_q = mysqli_query($conn, "SELECT owner_user_id, store_name FROM store_locations WHERE store_id = $hiring_store_id LIMIT 1");
        if ($owner_q && ($ow = mysqli_fetch_assoc($owner_q))) {
            $hiring_owner_id = (int)$ow['owner_user_id'];
            if (empty($store_name)) {
                $store_name = $ow['store_name'];
            }
        }
    }

    $rider['store_id'] = $hiring_store_id;
    $rider['store_name'] = $store_name ?: 'Dasmariñas Branch';
    $rider['store_owner_id'] = $hiring_owner_id;
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

function formatRiderPeso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}
