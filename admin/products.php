<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/DSSInsightsService.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

/**
 * Safe helper to keep DSS widgets non-blocking if optional tables are missing.
 */
function safeDssCall($callback, $default) {
    try {
        return $callback();
    } catch (Throwable $e) {
        return $default;
    }
}

// Ensure is_archived column exists to prevent errors
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'is_archived'");
if (mysqli_num_rows($check_column) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
}

// Ensure lead_time_hours column exists
$check_lead_time_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'lead_time_hours'");
if (mysqli_num_rows($check_lead_time_column) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN lead_time_hours INT(11) NOT NULL DEFAULT 24 COMMENT 'Minimum hours notice required for pre-order'");
}

/**
 * Generate a unique product code for the products.product_id column.
 */
function generateProductCode(mysqli $conn): string {
    do {
        $candidate = 'prod-' . bin2hex(random_bytes(3));
        $check_stmt = mysqli_prepare($conn, "SELECT 1 FROM products WHERE product_id = ? LIMIT 1");
        mysqli_stmt_bind_param($check_stmt, "s", $candidate);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        $exists = mysqli_stmt_num_rows($check_stmt) > 0;
        mysqli_stmt_close($check_stmt);
    } while ($exists);

    return $candidate;
}

// Handle product status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_product'])) {
    $product_id = intval($_POST['product_id']);
    if ($seller_scope_id !== null) {
        $toggle_query = "UPDATE products SET is_active = NOT is_active WHERE id = ? AND seller_id = ?";
        $stmt = mysqli_prepare($conn, $toggle_query);
        mysqli_stmt_bind_param($stmt, "ii", $product_id, $seller_scope_id);
    } else {
        $toggle_query = "UPDATE products SET is_active = NOT is_active WHERE id = ?";
        $stmt = mysqli_prepare($conn, $toggle_query);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
    }
    mysqli_stmt_execute($stmt);
    $updated_rows = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    $_SESSION[$updated_rows > 0 ? 'success' : 'error'] = $updated_rows > 0 ? "Product status updated successfully." : "Unauthorized product action.";
    header("Location: products.php");
    exit();
}

// Handle add new product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_product'])) {
    $name = trim($_POST['product_name']);
    $category = trim($_POST['product_category']);
    $price = floatval($_POST['product_price']);
    $description = trim($_POST['product_description']);
    $weight_info = trim($_POST['weight_info'] ?? '');
    $pax_info = trim($_POST['pax_info'] ?? '');
    $lead_time_hours = intval($_POST['lead_time_hours'] ?? 24);
    
    $errors = [];
    
    if (empty($name)) $errors[] = "Product name is required";
    if (empty($category)) $errors[] = "Category is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";
    
    // Handle image upload
    $image_path = '';
    if (isset($_FILES['product_image']) && $_FILES['product_image']['size'] > 0) {
        $file = $_FILES['product_image'];
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = basename($file['name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $errors[] = "Only JPG, JPEG, PNG, and GIF files are allowed";
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = "File size must not exceed 5MB";
        } else {
            $new_filename = time() . '_' . uniqid() . '.' . $ext;
            $upload_dir = '../uploads/products/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                $image_path = 'uploads/products/' . $new_filename;
            } else {
                $errors[] = "Failed to upload image";
            }
        }
    }
    
    if (empty($errors)) {
        $product_code = generateProductCode($conn);

        if (!empty($image_path)) {
            if ($seller_scope_id !== null) {
                $insert_query = "INSERT INTO products (seller_id, product_id, name, category, price, description, weight_info, pax_info, lead_time_hours, image, is_active, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt, "isssdsssis", $seller_scope_id, $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours, $image_path);
            } else {
                // Query with image
                $insert_query = "INSERT INTO products (product_id, name, category, price, description, weight_info, pax_info, lead_time_hours, image, is_active, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt, "sssdsssis", $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours, $image_path);
            }
        } else {
            if ($seller_scope_id !== null) {
                $insert_query = "INSERT INTO products (seller_id, product_id, name, category, price, description, weight_info, pax_info, lead_time_hours, is_active, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt, "isssdsssi", $seller_scope_id, $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours);
            } else {
                // Query without image
                $insert_query = "INSERT INTO products (product_id, name, category, price, description, weight_info, pax_info, lead_time_hours, is_active, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt, "sssdsssi", $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours);
            }
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Product '$name' added successfully! It will now appear on the menu.";
            header("Location: products.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to add product: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }
}

// Handle update product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $id = intval($_POST['product_id']);
    $name = trim($_POST['product_name']);
    $category = trim($_POST['product_category']);
    $price = floatval($_POST['product_price']);
    $description = trim($_POST['product_description']);
    $weight_info = trim($_POST['weight_info'] ?? '');
    $pax_info = trim($_POST['pax_info'] ?? '');
    $lead_time_hours = intval($_POST['lead_time_hours'] ?? 24);
    
    $errors = [];
    
    if (empty($name)) $errors[] = "Product name is required";
    if (empty($category)) $errors[] = "Category is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";
    
    // Handle image upload
    $image_path = '';
    if (isset($_FILES['product_image']) && $_FILES['product_image']['size'] > 0) {
        $file = $_FILES['product_image'];
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = basename($file['name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $errors[] = "Only JPG, JPEG, PNG, and GIF files are allowed";
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = "File size must not exceed 5MB";
        } else {
            $new_filename = time() . '_' . uniqid() . '.' . $ext;
            $upload_dir = '../uploads/products/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                $image_path = 'uploads/products/' . $new_filename;
            } else {
                $errors[] = "Failed to upload image";
            }
        }
    }
    
    if (empty($errors)) {
        if (!empty($image_path)) {
            $update_query = "UPDATE products SET name=?, category=?, price=?, description=?, weight_info=?, pax_info=?, lead_time_hours=?, image=? WHERE id=?" . ($seller_scope_id !== null ? " AND seller_id=?" : "");
            $stmt = mysqli_prepare($conn, $update_query);
            if ($seller_scope_id !== null) {
                mysqli_stmt_bind_param($stmt, "ssdsssisii", $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours, $image_path, $id, $seller_scope_id);
            } else {
                mysqli_stmt_bind_param($stmt, "ssdsssisi", $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours, $image_path, $id);
            }
        } else {
            $update_query = "UPDATE products SET name=?, category=?, price=?, description=?, weight_info=?, pax_info=?, lead_time_hours=? WHERE id=?" . ($seller_scope_id !== null ? " AND seller_id=?" : "");
            $stmt = mysqli_prepare($conn, $update_query);
            if ($seller_scope_id !== null) {
                mysqli_stmt_bind_param($stmt, "ssdsssiii", $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours, $id, $seller_scope_id);
            } else {
                mysqli_stmt_bind_param($stmt, "ssdsssii", $name, $category, $price, $description, $weight_info, $pax_info, $lead_time_hours, $id);
            }
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $affected = mysqli_stmt_affected_rows($stmt);
            if ($seller_scope_id !== null && $affected === 0) {
                $_SESSION['error'] = "No product was updated. It may not belong to your store.";
            } else {
                $_SESSION['success'] = "Product updated successfully.";
            }
            header("Location: products.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to update product: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }
}

// Handle archive product (soft delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_product'])) {
    $id = intval($_POST['product_id']);
    $archive_query = "UPDATE products SET is_archived = 1, is_active = 0 WHERE id = ?" . ($seller_scope_id !== null ? " AND seller_id = ?" : "");
    $stmt = mysqli_prepare($conn, $archive_query);
    if ($seller_scope_id !== null) {
        mysqli_stmt_bind_param($stmt, "ii", $id, $seller_scope_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $affected = mysqli_stmt_affected_rows($stmt);
        $_SESSION[$affected > 0 ? 'success' : 'error'] = $affected > 0 ? "Product moved to archives." : "Unauthorized product action.";
        header("Location: products.php");
        exit();
    } else {
        $_SESSION['error'] = "Failed to archive product: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
}

// Get products
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';

$where_clauses = [];
$params = [];
$param_types = '';

$where_clauses[] = "p.is_archived = 0";
if ($seller_scope_id !== null) {
    $where_clauses[] = "p.seller_id = ?";
    $params[] = $seller_scope_id;
    $param_types .= 'i';
}
if ($search !== '') {
    $where_clauses[] = "p.name LIKE ?";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $param_types .= 's';
}
if ($category !== '') {
    $where_clauses[] = "p.category = ?";
    $params[] = $category;
    $param_types .= 's';
}

$where_clause = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
$products_query = "SELECT p.*, COALESCE(i.current_stock, 0) as today_stock FROM products p LEFT JOIN inventory i ON p.id = i.product_id AND i.inventory_date = CURDATE() $where_clause ORDER BY p.created_at DESC";

$stmt = mysqli_prepare($conn, $products_query);
if (count($params) > 0) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
}
mysqli_stmt_execute($stmt);
$products_result = mysqli_stmt_get_result($stmt);

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM products" . ($seller_scope_id !== null ? " WHERE seller_id = ?" : "") . " ORDER BY category";
$categories_stmt = mysqli_prepare($conn, $categories_query);
if ($categories_stmt) {
    if ($seller_scope_id !== null) {
        mysqli_stmt_bind_param($categories_stmt, "i", $seller_scope_id);
    }
    mysqli_stmt_execute($categories_stmt);
    $categories_result = mysqli_stmt_get_result($categories_stmt);
} else {
    $categories_result = mysqli_query($conn, "SELECT DISTINCT category FROM products ORDER BY category");
}

// DSS-oriented product KPIs and operational guidance.
$product_kpis = [
    'active_products' => 0,
    'inactive_products' => 0,
    'archived_products' => 0,
    'out_of_stock_items' => 0,
    'low_stock_items' => 0
];
$kpi_query = "
    SELECT
        SUM(CASE WHEN p.is_archived = 0 AND p.is_active = 1 THEN 1 ELSE 0 END) AS active_products,
        SUM(CASE WHEN p.is_archived = 0 AND p.is_active = 0 THEN 1 ELSE 0 END) AS inactive_products,
        SUM(CASE WHEN p.is_archived = 1 THEN 1 ELSE 0 END) AS archived_products,
        SUM(CASE WHEN p.is_archived = 0 AND COALESCE(i.current_stock, 0) <= 0 THEN 1 ELSE 0 END) AS out_of_stock_items,
        SUM(CASE WHEN p.is_archived = 0 AND COALESCE(i.current_stock, 0) > 0 AND COALESCE(i.current_stock, 0) <= COALESCE(i.min_stock_level, 5) THEN 1 ELSE 0 END) AS low_stock_items
    FROM products p
    LEFT JOIN inventory i
        ON p.id = i.product_id
       AND i.inventory_date = CURDATE()
       AND i.is_archived = 0
    " . ($seller_scope_id !== null ? "WHERE p.seller_id = " . (int)$seller_scope_id : "") . "
";
$kpi_result = mysqli_query($conn, $kpi_query);
if ($kpi_result) {
    $kpi_row = mysqli_fetch_assoc($kpi_result);
    if ($kpi_row) {
        $product_kpis = [
            'active_products' => (int)($kpi_row['active_products'] ?? 0),
            'inactive_products' => (int)($kpi_row['inactive_products'] ?? 0),
            'archived_products' => (int)($kpi_row['archived_products'] ?? 0),
            'out_of_stock_items' => (int)($kpi_row['out_of_stock_items'] ?? 0),
            'low_stock_items' => (int)($kpi_row['low_stock_items'] ?? 0)
        ];
    }
}

$dss_forecast_summary = ['predicted_orders' => 0, 'avg_confidence' => 0];
$dss_top_products = [];
$dss_inventory_pressure = [];
if ($seller_scope_id === null) {
    $insights_service = new DSSInsightsService($conn);
    $dss_forecast_summary = safeDssCall(function () use ($insights_service) {
        return $insights_service->getForecastingSummary(7);
    }, ['predicted_orders' => 0, 'avg_confidence' => 0]);

    $dss_top_products = safeDssCall(function () use ($insights_service) {
        return $insights_service->getTopProducts(30, 5);
    }, []);

    $dss_inventory_pressure = safeDssCall(function () use ($insights_service) {
        return $insights_service->getInventoryPressure(7, 5);
    }, []);
} else {
    $top_query = "SELECT p.name AS product_name, COALESCE(SUM(oi.quantity), 0) AS quantity
                  FROM products p
                  LEFT JOIN order_items oi
                    ON (oi.product_id COLLATE utf8mb4_general_ci = p.product_id COLLATE utf8mb4_general_ci OR oi.product_id COLLATE utf8mb4_general_ci = CAST(p.id AS CHAR) COLLATE utf8mb4_general_ci)
                  LEFT JOIN orders o
                    ON oi.order_id = o.id
                   AND o.is_archived = 0
                   AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed')
                   AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  WHERE p.seller_id = ?
                    AND p.is_archived = 0
                  GROUP BY p.id, p.name
                  ORDER BY quantity DESC
                  LIMIT 5";
    $top_stmt = mysqli_prepare($conn, $top_query);
    if ($top_stmt) {
        mysqli_stmt_bind_param($top_stmt, "i", $seller_scope_id);
        mysqli_stmt_execute($top_stmt);
        $top_result = mysqli_stmt_get_result($top_stmt);
        while ($top_result && ($top_row = mysqli_fetch_assoc($top_result))) {
            $dss_top_products[] = $top_row;
        }
        mysqli_stmt_close($top_stmt);
    }

    $pressure_query = "SELECT p.name AS product_name,
                              COALESCE(i.current_stock, 0) AS stock,
                              0 AS forecast_demand,
                              CASE
                                  WHEN COALESCE(i.current_stock, 0) <= 0 THEN 'critical'
                                  WHEN COALESCE(i.current_stock, 0) <= COALESCE(i.min_stock_level, 5) THEN 'high'
                                  ELSE 'low'
                              END AS severity
                       FROM products p
                       LEFT JOIN inventory i
                         ON p.id = i.product_id
                        AND i.inventory_date = CURDATE()
                        AND i.is_archived = 0
                       WHERE p.seller_id = ?
                         AND p.is_archived = 0
                       ORDER BY
                         CASE
                             WHEN COALESCE(i.current_stock, 0) <= 0 THEN 1
                             WHEN COALESCE(i.current_stock, 0) <= COALESCE(i.min_stock_level, 5) THEN 2
                             ELSE 3
                         END,
                         p.name
                       LIMIT 5";
    $pressure_stmt = mysqli_prepare($conn, $pressure_query);
    mysqli_stmt_bind_param($pressure_stmt, "i", $seller_scope_id);
    mysqli_stmt_execute($pressure_stmt);
    $pressure_result = mysqli_stmt_get_result($pressure_stmt);
    while ($pressure_result && ($pressure_row = mysqli_fetch_assoc($pressure_result))) {
        $dss_inventory_pressure[] = $pressure_row;
    }
    mysqli_stmt_close($pressure_stmt);
}

$dss_priority_items = array_values(array_filter($dss_inventory_pressure, function ($item) {
    return in_array(strtolower($item['severity'] ?? ''), ['critical', 'high'], true);
}));

$product_decisions = [];
if ($product_kpis['out_of_stock_items'] > 0) {
    $product_decisions[] = "Prioritize replenishment for {$product_kpis['out_of_stock_items']} out-of-stock menu item(s) to avoid missed sales.";
}
if ($product_kpis['low_stock_items'] > 0) {
    $product_decisions[] = "Create early purchase requests for {$product_kpis['low_stock_items']} low-stock item(s) before the next demand spike.";
}
if (($dss_forecast_summary['predicted_orders'] ?? 0) > 0 && ($dss_forecast_summary['avg_confidence'] ?? 0) >= 70) {
    $predicted_orders = number_format((float)$dss_forecast_summary['predicted_orders'], 0);
    $product_decisions[] = "Forecast expects about {$predicted_orders} orders in 7 days. Keep high-demand products visible on the menu.";
}
if (empty($product_decisions)) {
    $product_decisions[] = "Menu inventory is stable. Maintain current product mix and review lead times weekly.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td,
        body.dark-mode .modal-content,
        body.dark-mode .modal-header,
        body.dark-mode .modal-footer,
        body.dark-mode .form-control,
        body.dark-mode .form-select,
        body.dark-mode .product-card,
        body.dark-mode .product-header,
        body.dark-mode .product-footer {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode label,
        body.dark-mode .modal-title {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small {
            color: #b0b0b0 !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .insight-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .insight-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 14px;
        }

        .insight-label {
            color: #6c757d;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .insight-value {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
        }

        .decision-panel {
            position: relative;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
            border: 1px solid #e8edf3;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 18px;
            overflow: hidden;
        }

        .decision-panel::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #2563eb, #38bdf8);
        }

        .decision-panel h5 {
            margin-bottom: 8px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .decision-panel .decision-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .decision-panel .decision-actions .btn {
            border-radius: 999px;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
        }

        .decision-panel ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
        }

        .decision-panel li {
            margin: 0;
            background: #f8fafc;
            border: 1px solid #e8edf3;
            border-radius: 10px;
            padding: 10px 12px 10px 34px;
            line-height: 1.45;
            position: relative;
        }

        .decision-panel li::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free", "Font Awesome 5 Free";
            font-weight: 900;
            position: absolute;
            left: 12px;
            top: 10px;
            color: #2563eb;
            font-size: 0.75rem;
        }

        body.dark-mode .insight-card,
        body.dark-mode .decision-panel {
            background-color: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .decision-panel {
            background: linear-gradient(180deg, #2d2d2d 0%, #252525 100%) !important;
        }

        body.dark-mode .decision-panel li {
            background: rgba(255, 255, 255, 0.03);
            border-color: #454545;
        }

        body.dark-mode .decision-panel li::before {
            color: #67e8f9;
        }

        body.dark-mode .insight-value {
            color: var(--text-color-dark) !important;
        }

        /* Products page styling enhancements */
        .products-page .insight-card {
            position: relative;
            overflow: hidden;
            border-radius: 14px;
        }

        .products-page .insight-card::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #c62828, #ef5350);
        }

        .products-page .decision-panel {
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.06);
        }

        .products-page .section-header {
            background: #ffffff;
            border: 1px solid #e8edf3;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
        }

        .products-page .section-header > div {
            margin-bottom: 12px !important;
        }

        .products-page .filter-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(170px, 220px) auto;
            gap: 10px;
            align-items: center;
        }

        .products-page .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
            gap: 16px;
        }

        .products-page .product-card {
            border: 1px solid #e8edf3;
            border-radius: 16px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
        }

        .products-page .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
        }

        .products-page .product-image {
            position: relative;
            border-radius: 0 !important;
            background: linear-gradient(160deg, #fff6f6, #ffffff) !important;
        }

        .products-page .product-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 12px 14px 8px;
            border-bottom: 1px dashed #e8edf3;
        }

        .products-page .product-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
        }

        .products-page .product-body {
            padding: 12px 14px;
            display: grid;
            gap: 6px;
        }

        .products-page .product-category {
            margin: 0;
            color: #6b7280;
            font-size: 0.84rem;
            font-weight: 600;
        }

        .products-page .product-price {
            margin: 0;
            color: #c62828;
            font-weight: 800;
            font-size: 1.08rem;
        }

        .products-page .product-info {
            margin: 0;
            color: #475569;
            font-size: 0.88rem;
        }

        .products-page .product-footer {
            margin-top: auto;
            padding: 12px 14px 14px;
            border-top: 1px solid #edf2f7;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .products-page .product-footer form {
            display: inline-flex !important;
            align-items: center;
            gap: 8px;
        }

        .products-page .status-badge {
            border-radius: 999px;
            font-size: 0.72rem;
            letter-spacing: 0.2px;
            padding: 4px 10px;
        }

        .products-page .no-results {
            padding: 40px 20px;
            border: 1px dashed #d6dde6;
            border-radius: 14px;
            color: #6b7280;
            background: #fafbfd;
            text-align: center;
        }

        @media (max-width: 992px) {
            .products-page .filter-form {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 640px) {
            .products-page .filter-form {
                grid-template-columns: 1fr;
            }
        }

        body.dark-mode.products-page .section-header,
        body.dark-mode.products-page .product-card,
        body.dark-mode.products-page .no-results {
            border-color: var(--border-color-dark) !important;
            background: var(--card-bg-dark) !important;
        }

        body.dark-mode.products-page .product-header,
        body.dark-mode.products-page .product-footer {
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode.products-page .product-price {
            color: #ff8a80 !important;
        }
    </style>
</head>
<body class="admin-polish products-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Products Management</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <div class="insight-grid">
                    <div class="insight-card">
                        <div class="insight-label">Active Menu Products</div>
                        <div class="insight-value"><?php echo number_format($product_kpis['active_products']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Hidden Products</div>
                        <div class="insight-value"><?php echo number_format($product_kpis['inactive_products']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Out of Stock Today</div>
                        <div class="insight-value"><?php echo number_format($product_kpis['out_of_stock_items']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Low Stock Today</div>
                        <div class="insight-value"><?php echo number_format($product_kpis['low_stock_items']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">7-Day Forecasted Orders</div>
                        <div class="insight-value"><?php echo number_format((float)($dss_forecast_summary['predicted_orders'] ?? 0), 0); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Forecast Confidence</div>
                        <div class="insight-value"><?php echo number_format((float)($dss_forecast_summary['avg_confidence'] ?? 0), 1); ?>%</div>
                    </div>
                </div>

                <div class="decision-panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="fas fa-brain text-primary me-1"></i> DSS Product Decisions</h5>
                        <div class="decision-actions">
                            <a href="inventory.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-boxes me-1"></i>Inventory</a>
                            <a href="mrp.php?tab=low_stock" class="btn btn-sm btn-outline-warning"><i class="fas fa-file-alt me-1"></i>Procurement</a>
                            <a href="forecasting_dashboard.php" class="btn btn-sm btn-outline-success"><i class="fas fa-chart-line me-1"></i>Forecasting</a>
                        </div>
                    </div>
                    <ul class="mt-3">
                        <?php foreach ($product_decisions as $decision): ?>
                            <li><?php echo htmlspecialchars($decision); ?></li>
                        <?php endforeach; ?>
                        <?php if (!empty($dss_priority_items)): ?>
                            <?php foreach ($dss_priority_items as $risk_item): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($risk_item['product_name']); ?></strong> risk is
                                    <strong><?php echo strtoupper(htmlspecialchars($risk_item['severity'])); ?></strong>
                                    (stock: <?php echo (int)$risk_item['stock']; ?>, 7-day demand: <?php echo number_format((float)$risk_item['forecast_demand'], 1); ?>).
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($dss_top_products)): ?>
                            <li>
                                Highest moving item this month:
                                <strong><?php echo htmlspecialchars($dss_top_products[0]['product_name']); ?></strong>
                                (<?php echo (int)$dss_top_products[0]['quantity']; ?> units sold).
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="section-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2>All Products</h2>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="fas fa-plus"></i> Add New Product
                        </button>
                    </div>
                    <form method="GET" class="filter-form">
                        <input type="text" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php
                            while ($cat = mysqli_fetch_assoc($categories_result)) {
                                $selected = $category === $cat['category'] ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($cat['category']) . "' $selected>" . htmlspecialchars($cat['category']) . "</option>";
                            }
                            ?>
                        </select>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
                
                <div class="products-grid">
                    <?php
                    if (mysqli_num_rows($products_result) > 0) {
                        while ($product = mysqli_fetch_assoc($products_result)) {
                            $status_class = $product['is_active'] ? 'badge-success' : 'badge-danger';
                            $status_text = $product['is_active'] ? 'Active' : 'Inactive';
                            
                            echo "
                            <div class='product-card'>
                                <div class='product-image' style='width: 100%; height: 200px; background-color: #f5f5f5; border-radius: 8px 8px 0 0; display: flex; align-items: center; justify-content: center; overflow: hidden;'>";
                            
                            if (!empty($product['image']) && file_exists('../' . $product['image'])) {
                                echo "<img src='../" . htmlspecialchars($product['image']) . "' alt='" . htmlspecialchars($product['name']) . "' style='width: 100%; height: 100%; object-fit: cover;'>";
                            } else {
                                echo "<div style='text-align: center; color: #999;'><i class='fas fa-image' style='font-size: 48px;'></i><p>No Image</p></div>";
                            }
                            
                            echo "</div>
                                <div class='product-header'>
                                    <h5>" . htmlspecialchars($product['name']) . "</h5>
                                    <span class='status-badge $status_class'>$status_text</span>
                                </div>
                                
                                <div class='product-body'>
                                    <p class='product-category'>" . htmlspecialchars($product['category']) . "</p>
                                    <p class='product-price'>&#8369;" . number_format($product['price'], 2) . "</p>
                                    <p class='product-info'><i class='fas fa-box'></i> Stock Today: {$product['today_stock']}</p>
                                    " . (isset($product['weight_info']) ? "<p class='product-info'><i class='fas fa-weight'></i> {$product['weight_info']}</p>" : "") . "
                                    " . (isset($product['pax_info']) ? "<p class='product-info'><i class='fas fa-users'></i> {$product['pax_info']}</p>" : "") . "
                                    " . (isset($product['lead_time_hours']) ? "<p class='product-info'><i class='fas fa-clock'></i> {$product['lead_time_hours']} hrs lead time</p>" : "") . "
                                </div>
                                
                                <div class='product-footer'>
                                    <button type='button' class='btn btn-sm btn-primary me-1' onclick='openEditModal(" . htmlspecialchars(json_encode($product), ENT_QUOTES, 'UTF-8') . ")'>
                                        <i class='fas fa-edit'></i> Edit
                                    </button>
                                    <form method='POST' id='toggleForm{$product['id']}' style='display:inline;'>
                                        <input type='hidden' name='product_id' value='{$product['id']}'>
                                        <input type='hidden' name='toggle_product' value='1'>
                                        <button type='button' onclick='confirmToggle({$product['id']}, {$product['is_active']})' class='btn btn-sm btn-" . ($product['is_active'] ? 'warning' : 'success') . " me-1'>
                                            <i class='fas fa-" . ($product['is_active'] ? 'eye-slash' : 'eye') . "'></i>
                                            " . ($product['is_active'] ? 'Hide' : 'Show') . "
                                        </button>
                                    </form>
                                    <form method='POST' id='archiveForm{$product['id']}' style='display:inline;'>
                                        <input type='hidden' name='product_id' value='{$product['id']}'>
                                        <input type='hidden' name='archive_product' value='1'>
                                        <button type='button' onclick='confirmArchive({$product['id']})' class='btn btn-sm btn-danger' title='Archive Product'>
                                            <i class='fas fa-trash'></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            ";
                        }
                    } else {
                        echo "<div class='no-results'>No products found</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProductLabel"><i class="fas fa-plus-circle"></i> Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addProductForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="add_new_product" value="1">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="product_name" class="form-label"><strong>Product Name *</strong></label>
                                <input type="text" class="form-control" id="product_name" name="product_name" placeholder="e.g., Lechon Kawali" required>
                            </div>
                            <div class="col-md-6">
                                <label for="product_category" class="form-label"><strong>Category *</strong></label>
                                <select class="form-select" id="product_category" name="product_category" required>
                                    <option value="">Select Category</option>
                                    <option value="Whole Lechon">Whole Lechon</option>
                                    <option value="Lechon Belly">Lechon Belly</option>
                                    <option value="Lechon Manok">Lechon Manok</option>
                                    <option value="Platters">Platters</option>
                                    <option value="Rice Meals">Rice Meals</option>
                                    <option value="Sides">Sides</option>
                                    <option value="Desserts">Desserts</option>
                                    <option value="Beverages">Beverages</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="product_price" class="form-label"><strong>Price (PHP) *</strong></label>
                                <input type="number" class="form-control" id="product_price" name="product_price" placeholder="0.00" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="lead_time_hours" class="form-label"><strong>Lead Time (Hours) *</strong></label>
                                <input type="number" class="form-control" id="lead_time_hours" name="lead_time_hours" placeholder="e.g., 24" value="24" min="0" required>
                                <small class="text-muted">Min. notice for pre-orders.</small>
                            </div>
                            <div class="col-md-4">
                                <label for="product_image" class="form-label"><strong>Product Image</strong></label>
                                <input type="file" class="form-control" id="product_image" name="product_image" accept="image/*">
                                <small class="text-muted">JPG, PNG, GIF (Max 5MB)</small>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="weight_info" class="form-label"><strong>Weight Info</strong></label>
                                <input type="text" class="form-control" id="weight_info" name="weight_info" placeholder="e.g., 1 kg, 500g">
                            </div>
                            <div class="col-md-6">
                                <label for="pax_info" class="form-label"><strong>Pax Info</strong></label>
                                <select class="form-select" id="pax_info" name="pax_info">
                                    <option value="">Select Pax Info</option>
                                    <option value="1 pax">1 pax</option>
                                    <option value="2-3 pax">2-3 pax</option>
                                    <option value="4-6 pax">4-6 pax</option>
                                    <option value="8-10 pax">8-10 pax</option>
                                    <option value="10-15 pax">10-15 pax</option>
                                    <option value="20-25 pax">20-25 pax</option>
                                    <option value="30-50 pax">30-50 pax</option>
                                    <option value="50+ pax">50+ pax</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="product_description" class="form-label"><strong>Description</strong></label>
                            <textarea class="form-control" id="product_description" name="product_description" rows="3" placeholder="Product details and description..."></textarea>
                        </div>
                        
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Once added, this product will be automatically visible on the customer menu page!
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addProductForm" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Product
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProductLabel"><i class="fas fa-edit"></i> Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editProductForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="update_product" value="1">
                        <input type="hidden" name="product_id" id="edit_product_id">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_product_name" class="form-label"><strong>Product Name *</strong></label>
                                <input type="text" class="form-control" id="edit_product_name" name="product_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_product_category" class="form-label"><strong>Category *</strong></label>
                                <select class="form-select" id="edit_product_category" name="product_category" required>
                                    <option value="">Select Category</option>
                                    <option value="Whole Lechon">Whole Lechon</option>
                                    <option value="Lechon Belly">Lechon Belly</option>
                                    <option value="Lechon Manok">Lechon Manok</option>
                                    <option value="Platters">Platters</option>
                                    <option value="Rice Meals">Rice Meals</option>
                                    <option value="Sides">Sides</option>
                                    <option value="Desserts">Desserts</option>
                                    <option value="Beverages">Beverages</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="edit_product_price" class="form-label"><strong>Price (PHP) *</strong></label>
                                <input type="number" class="form-control" id="edit_product_price" name="product_price" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_lead_time_hours" class="form-label"><strong>Lead Time (Hours) *</strong></label>
                                <input type="number" class="form-control" id="edit_lead_time_hours" name="lead_time_hours" required>
                                <small class="text-muted">Min. notice for pre-orders.</small>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_product_image" class="form-label"><strong>Product Image</strong></label>
                                <input type="file" class="form-control" id="edit_product_image" name="product_image" accept="image/*">
                                <small class="text-muted">Leave empty to keep current image</small>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_weight_info" class="form-label"><strong>Weight Info</strong></label>
                                <input type="text" class="form-control" id="edit_weight_info" name="weight_info">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_pax_info" class="form-label"><strong>Pax Info</strong></label>
                                <select class="form-select" id="edit_pax_info" name="pax_info">
                                    <option value="">Select Pax Info</option>
                                    <option value="1 pax">1 pax</option>
                                    <option value="2-3 pax">2-3 pax</option>
                                    <option value="4-6 pax">4-6 pax</option>
                                    <option value="8-10 pax">8-10 pax</option>
                                    <option value="10-15 pax">10-15 pax</option>
                                    <option value="20-25 pax">20-25 pax</option>
                                    <option value="30-50 pax">30-50 pax</option>
                                    <option value="50+ pax">50+ pax</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_product_description" class="form-label"><strong>Description</strong></label>
                            <textarea class="form-control" id="edit_product_description" name="product_description" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="editProductForm" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Product
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Theme Toggler
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        // Check local storage
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Session Messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?php echo $_SESSION['success']; ?>',
                timer: 2000,
                showConfirmButton: false
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?php echo $_SESSION['error']; ?>'
            });
        <?php unset($_SESSION['error']); endif; ?>

        function confirmToggle(id, isActive) {
            const action = isActive ? 'hide' : 'show';
            Swal.fire({
                title: 'Are you sure?',
                text: "Do you want to " + action + " this product on the menu?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: isActive ? '#d33' : '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, ' + action + ' it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('toggleForm' + id).submit();
                }
            })
        }

        function openEditModal(product) {
            document.getElementById('edit_product_id').value = product.id;
            document.getElementById('edit_product_name').value = product.name;
            document.getElementById('edit_product_category').value = product.category;
            document.getElementById('edit_product_price').value = product.price;
            document.getElementById('edit_weight_info').value = product.weight_info || '';
            document.getElementById('edit_pax_info').value = product.pax_info || '';
            document.getElementById('edit_lead_time_hours').value = product.lead_time_hours || '24';
            document.getElementById('edit_product_description').value = product.description || '';
            
            var myModal = new bootstrap.Modal(document.getElementById('editProductModal'));
            myModal.show();
        }

        function confirmArchive(id) {
            Swal.fire({
                title: 'Archive Product?',
                text: "This will remove the product from the menu and move it to archives.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, archive it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('archiveForm' + id).submit();
                }
            })
        }

        // Add confirmation for adding product
        document.getElementById('addProductForm').addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Add Product?',
                text: "Are you sure you want to add this product?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, add it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });

        // Add confirmation for updating product
        document.getElementById('editProductForm').addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Update Product?',
                text: "Are you sure you want to update this product?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, update it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });
    </script>
</body>
</html>
