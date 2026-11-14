<?php
/**
 * supplier/auth.php
 * Authentication and authorization guard for the Supplier Dashboard.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';

function checkSupplierAccess($conn) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];
    $user_type = strtolower((string)($_SESSION['user_type'] ?? ''));

    // Admins have universal backoffice override
    if ($user_type === 'admin') {
        // Return or create admin-scoped supplier record
        $adm_supp = mysqli_query($conn, "SELECT id, name FROM suppliers LIMIT 1");
        if ($adm_supp && ($as_row = mysqli_fetch_assoc($adm_supp))) {
            return (int)$as_row['id'];
        }
        return 1;
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

    // 2. Check if user is an employee whose position is Supplier
    $emp_stmt = mysqli_prepare($conn, "
        SELECT e.id, e.first_name, e.last_name, e.email, e.phone, e.position, jp.position_title
        FROM employees e
        LEFT JOIN job_positions jp ON e.position_id = jp.id
        WHERE e.user_id = ?
        LIMIT 1
    ");

    if ($emp_stmt) {
        mysqli_stmt_bind_param($emp_stmt, "i", $user_id);
        mysqli_stmt_execute($emp_stmt);
        $e_res = mysqli_stmt_get_result($emp_stmt);
        if ($e_row = mysqli_fetch_assoc($e_res)) {
            $pos_str = strtolower(($e_row['position_title'] ?? '') . ' ' . ($e_row['position'] ?? ''));
            if (strpos($pos_str, 'supplier') !== false) {
                mysqli_stmt_close($emp_stmt);

                // Auto-provision supplier record linked to this employee user
                $name = trim($e_row['first_name'] . ' ' . $e_row['last_name']);
                $phone = $e_row['phone'] ?? '';
                $email = $e_row['email'] ?? '';

                $ins_stmt = mysqli_prepare($conn, "INSERT INTO suppliers (user_id, name, contact_person, phone, email, is_active) VALUES (?, ?, ?, ?, ?, 1)");
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
        $u_stmt = mysqli_prepare($conn, "SELECT full_name, email, phone FROM users WHERE id = ? LIMIT 1");
        if ($u_stmt) {
            mysqli_stmt_bind_param($u_stmt, "i", $user_id);
            mysqli_stmt_execute($u_stmt);
            $u_res = mysqli_stmt_get_result($u_stmt);
            if ($u_row = mysqli_fetch_assoc($u_res)) {
                $name = $u_row['full_name'] ?: 'Supplier Partner';
                $email = $u_row['email'] ?: '';
                $phone = $u_row['phone'] ?: '';
                mysqli_stmt_close($u_stmt);

                $ins_stmt = mysqli_prepare($conn, "INSERT INTO suppliers (user_id, name, contact_person, phone, email, is_active) VALUES (?, ?, ?, ?, ?, 1)");
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
