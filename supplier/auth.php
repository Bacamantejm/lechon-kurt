<?php
/**
 * supplier/auth.php
 * Authentication and authorization guard for the Supplier Dashboard.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/supplier_helper.php';

function checkSupplierAccess($conn) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];
    $user_type = strtolower((string)($_SESSION['user_type'] ?? ''));

    ensureSupplierSchema($conn);

    // Admins have universal backoffice override and can switch preview
    if ($user_type === 'admin') {
        if (isset($_GET['supplier_id']) && (int)$_GET['supplier_id'] > 0) {
            $req_s = (int)$_GET['supplier_id'];
            $chk = mysqli_query($conn, "SELECT id FROM suppliers WHERE id = $req_s LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $_SESSION['admin_viewing_supplier_id'] = $req_s;
                return $req_s;
            }
        }
        if (isset($_SESSION['admin_viewing_supplier_id']) && (int)$_SESSION['admin_viewing_supplier_id'] > 0) {
            $sess_s = (int)$_SESSION['admin_viewing_supplier_id'];
            $chk = mysqli_query($conn, "SELECT id FROM suppliers WHERE id = $sess_s LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                return $sess_s;
            }
        }
        // Fallback: supplier with the latest order, or first active supplier
        $lat_ord = mysqli_query($conn, "SELECT supplier_id FROM supplier_orders ORDER BY id DESC LIMIT 1");
        if ($lat_ord && ($lo_r = mysqli_fetch_assoc($lat_ord)) && (int)$lo_r['supplier_id'] > 0) {
            return (int)$lo_r['supplier_id'];
        }
        $adm_supp = mysqli_query($conn, "SELECT id, name FROM suppliers WHERE (is_active = 1 OR is_active IS NULL) ORDER BY id ASC LIMIT 1");
        if ($adm_supp && ($as_row = mysqli_fetch_assoc($adm_supp))) {
            return (int)$as_row['id'];
        }
        return 1;
    }

    // Resolve specific supplier ID for this logged-in user
    if (function_exists('getSupplierIdForUser')) {
        $supp_id = getSupplierIdForUser($conn, $user_id);
        if ($supp_id > 0) {
            return $supp_id;
        }
    }

    // 1. Check if user is linked directly in suppliers table
    $supp_stmt = mysqli_prepare($conn, "SELECT id, name, is_active FROM suppliers WHERE user_id = ? LIMIT 1");
    if ($supp_stmt) {
        mysqli_stmt_bind_param($supp_stmt, "i", $user_id);
        mysqli_stmt_execute($supp_stmt);
        $res = mysqli_stmt_get_result($supp_stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            mysqli_stmt_close($supp_stmt);
            return (int)$row['id'];
        }
        mysqli_stmt_close($supp_stmt);
    }

    // 2. Check if user is an employee whose position or department is Supplier
    $emp_stmt = mysqli_prepare($conn, "
        SELECT e.id, e.first_name, e.last_name, e.email, e.phone, e.position, jp.position_title, d.name AS dept_name
        FROM employees e
        LEFT JOIN job_positions jp ON e.position_id = jp.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE e.user_id = ?
        LIMIT 1
    ");

    if ($emp_stmt) {
        mysqli_stmt_bind_param($emp_stmt, "i", $user_id);
        mysqli_stmt_execute($emp_stmt);
        $e_res = mysqli_stmt_get_result($emp_stmt);
        if ($e_row = mysqli_fetch_assoc($e_res)) {
            $pos_str = strtolower(($e_row['position_title'] ?? '') . ' ' . ($e_row['position'] ?? '') . ' ' . ($e_row['dept_name'] ?? ''));
            if (strpos($pos_str, 'supplier') !== false) {
                mysqli_stmt_close($emp_stmt);

                // Auto-provision supplier record linked to this employee user
                $name = trim($e_row['first_name'] . ' ' . $e_row['last_name']);
                $phone = $e_row['phone'] ?? '';
                $email = $e_row['email'] ?? '';

                $ins_stmt = mysqli_prepare($conn, "INSERT INTO suppliers (user_id, name, contact_person, phone, email, is_active, status) VALUES (?, ?, ?, ?, ?, 1, 'active')");
                if ($ins_stmt) {
                    mysqli_stmt_bind_param($ins_stmt, "issss", $user_id, $name, $name, $phone, $email);
                    mysqli_stmt_execute($ins_stmt);
                    $new_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($ins_stmt);
                    return $new_id;
                }
            }
        }
        mysqli_stmt_close($emp_stmt);
    }

    // 3. Check if user has user_type === 'supplier'
    if ($user_type === 'supplier') {
        $u_stmt = mysqli_prepare($conn, "SELECT full_name, business_name, email, phone FROM users WHERE id = ? LIMIT 1");
        if ($u_stmt) {
            mysqli_stmt_bind_param($u_stmt, "i", $user_id);
            mysqli_stmt_execute($u_stmt);
            $u_res = mysqli_stmt_get_result($u_stmt);
            if ($u_row = mysqli_fetch_assoc($u_res)) {
                $name = trim((string)($u_row['business_name'] ?: $u_row['full_name'])) ?: 'Supplier Partner';
                $email = $u_row['email'] ?: '';
                $phone = $u_row['phone'] ?: '';
                mysqli_stmt_close($u_stmt);

                $ins_stmt = mysqli_prepare($conn, "INSERT INTO suppliers (user_id, name, contact_person, phone, email, is_active, status) VALUES (?, ?, ?, ?, ?, 1, 'active')");
                if ($ins_stmt) {
                    mysqli_stmt_bind_param($ins_stmt, "issss", $user_id, $name, $name, $phone, $email);
                    mysqli_stmt_execute($ins_stmt);
                    $new_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($ins_stmt);
                    return $new_id;
                }
            } else {
                mysqli_stmt_close($u_stmt);
            }
        }
    }

    // Unauthorized
    header("Location: ../index.php?error=unauthorized_supplier");
    exit;
}

$current_supplier_id = checkSupplierAccess($conn);
$_SESSION['supplier_id'] = $current_supplier_id;

// Fetch supplier profile
$supplier_profile = null;
$sp_stmt = mysqli_prepare($conn, "SELECT * FROM suppliers WHERE id = ? LIMIT 1");
if ($sp_stmt) {
    mysqli_stmt_bind_param($sp_stmt, "i", $current_supplier_id);
    mysqli_stmt_execute($sp_stmt);
    $sp_res = mysqli_stmt_get_result($sp_stmt);
    $supplier_profile = mysqli_fetch_assoc($sp_res);
    mysqli_stmt_close($sp_stmt);
}

// Collect all supplier IDs linked to this user, business name, phone or email for complete identity unification
$all_my_supplier_ids = [(int)$current_supplier_id];
$logged_user_id = (int)($_SESSION['user_id'] ?? 0);
if ($logged_user_id > 0) {
    $alt_res = @mysqli_query($conn, "SELECT id FROM suppliers WHERE user_id = $logged_user_id");
    if ($alt_res) {
        while ($ar = mysqli_fetch_assoc($alt_res)) {
            $all_my_supplier_ids[] = (int)$ar['id'];
        }
    }
}
if (!empty($supplier_profile)) {
    $sp_name = trim((string)($supplier_profile['name'] ?? ''));
    $sp_email = trim((string)($supplier_profile['email'] ?? ''));
    $sp_phone = trim((string)($supplier_profile['phone'] ?? ''));
    if ($sp_name !== '') {
        $nr = @mysqli_query($conn, "SELECT id FROM suppliers WHERE LOWER(TRIM(name)) = LOWER('" . mysqli_real_escape_string($conn, $sp_name) . "')");
        if ($nr) {
            while ($ar = mysqli_fetch_assoc($nr)) {
                $all_my_supplier_ids[] = (int)$ar['id'];
            }
        }
    }
    if ($sp_email !== '') {
        $er = @mysqli_query($conn, "SELECT id FROM suppliers WHERE LOWER(TRIM(email)) = LOWER('" . mysqli_real_escape_string($conn, $sp_email) . "')");
        if ($er) {
            while ($ar = mysqli_fetch_assoc($er)) {
                $all_my_supplier_ids[] = (int)$ar['id'];
            }
        }
    }
    if ($sp_phone !== '') {
        $clean_p = preg_replace('/[^0-9]/', '', $sp_phone);
        if ($clean_p !== '') {
            $pr = @mysqli_query($conn, "SELECT id FROM suppliers WHERE REPLACE(REPLACE(phone, ' ', ''), '-', '') LIKE '%$clean_p%'");
            if ($pr) {
                while ($ar = mysqli_fetch_assoc($pr)) {
                    $all_my_supplier_ids[] = (int)$ar['id'];
                }
            }
        }
    }
}
$all_my_supplier_ids = array_values(array_unique(array_filter($all_my_supplier_ids)));
if (empty($all_my_supplier_ids)) {
    $all_my_supplier_ids = [(int)$current_supplier_id];
}
$supp_id_in_str = implode(',', $all_my_supplier_ids);
$_SESSION['all_my_supplier_ids'] = $all_my_supplier_ids;
