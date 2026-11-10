<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/SubscriptionAccessService.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

function getLatestApprovedFranchise($conn, $user_id) {
    $query = "SELECT business_name, business_type
              FROM franchise_applications
              WHERE user_id = ? AND status = 'approved'
              ORDER BY reviewed_at DESC, created_at DESC
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = $result ? mysqli_fetch_assoc($result) : null;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
    return $data ?: null;
}

// Check if user can manage seller products
$user_id = (int)$_SESSION['user_id'];
$user_query = "SELECT * FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_result = mysqli_stmt_get_result($stmt);
$user_info = mysqli_fetch_assoc($user_result);
mysqli_stmt_close($stmt);

$has_seller_access = $user_info && (($user_info['account_type'] ?? '') === 'organization');
if (!$has_seller_access) {
    $approved_franchise = getLatestApprovedFranchise($conn, $user_id);
    if ($approved_franchise) {
        $business_name = trim((string)($approved_franchise['business_name'] ?? ''));
        $business_type = trim((string)($approved_franchise['business_type'] ?? ''));

        $promote_query = "UPDATE users
                          SET account_type = 'organization',
                              business_name = COALESCE(NULLIF(business_name, ''), ?),
                              business_type = COALESCE(NULLIF(business_type, ''), ?)
                          WHERE id = ?";
        $promote_stmt = mysqli_prepare($conn, $promote_query);
        if ($promote_stmt) {
            mysqli_stmt_bind_param($promote_stmt, "ssi", $business_name, $business_type, $user_id);
            mysqli_stmt_execute($promote_stmt);
            mysqli_stmt_close($promote_stmt);
        }

        $_SESSION['account_type'] = 'organization';
        $has_seller_access = true;
    }
}

if (!$has_seller_access) {
    header('Location: index.php?error=not_approved');
    exit;
}

$seller_sub_details = SubscriptionAccessService::getShopSubscriptionDetails($conn, $user_id);
$can_upload_products = !empty($seller_sub_details['can_upload_products']);

// Ensure sizes column exists for weight-based options
$check_sizes_col = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'sizes'");
if ($check_sizes_col && mysqli_num_rows($check_sizes_col) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN sizes TEXT NULL DEFAULT NULL COMMENT 'JSON array of weight and size options' AFTER image");
}
if ($check_sizes_col) mysqli_free_result($check_sizes_col);

if (!function_exists('processProductWeightTiers')) {
    function processProductWeightTiers(array &$uploaded_images): ?string {
        $sizes_array = [];
        if (!empty($_POST['weight_tiers']) && is_array($_POST['weight_tiers'])) {
            foreach ($_POST['weight_tiers'] as $idx => $tier) {
                $tier_name = trim((string)($tier['name'] ?? ''));
                $tier_price = floatval($tier['price'] ?? 0);
                $tier_good_for = trim((string)($tier['good_for'] ?? ''));
                $tier_img = trim((string)($tier['existing_image'] ?? ''));

                if (!empty($_FILES['weight_tier_images']['name'][$idx])) {
                    $f_name = $_FILES['weight_tier_images']['name'][$idx];
                    $f_tmp = $_FILES['weight_tier_images']['tmp_name'][$idx];
                    $f_size = (int)($_FILES['weight_tier_images']['size'][$idx] ?? 0);
                    $f_err = $_FILES['weight_tier_images']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;

                    if ($f_err === UPLOAD_ERR_OK && $f_size > 0 && is_uploaded_file($f_tmp)) {
                        $ext = strtolower(pathinfo((string)$f_name, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                            $target_dir = __DIR__ . '/uploads/products/';
                            if (!is_dir($target_dir)) {
                                @mkdir($target_dir, 0755, true);
                            }
                            $new_fn = time() . '_' . uniqid('wt_') . '.' . $ext;
                            if (move_uploaded_file($f_tmp, $target_dir . $new_fn)) {
                                $tier_img = 'uploads/products/' . $new_fn;
                                if (!in_array($tier_img, $uploaded_images, true)) {
                                    $uploaded_images[] = $tier_img;
                                }
                            }
                        }
                    }
                }

                if ($tier_name !== '' && $tier_price > 0) {
                    $sizes_array[] = [
                        'name' => $tier_name,
                        'weight' => $tier_name,
                        'price' => $tier_price,
                        'good_for' => $tier_good_for,
                        'image' => $tier_img
                    ];
                }
            }
        }
        return !empty($sizes_array) ? json_encode(array_values($sizes_array)) : null;
    }
}

if (function_exists('ensureProductMultipleImagesSchema')) {
    ensureProductMultipleImagesSchema($conn);
}

// Handle product status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_product'])) {
    $product_id = intval($_POST['product_id']);
    
    // Verify product belongs to this seller
    $verify_query = "SELECT p.id FROM products p WHERE p.id = ? AND p.seller_id = ?";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $product_id, $user_id);
    mysqli_stmt_execute($verify_stmt);
    mysqli_stmt_store_result($verify_stmt);
    
    if (mysqli_stmt_num_rows($verify_stmt) > 0) {
        mysqli_stmt_close($verify_stmt);
        $toggle_query = "UPDATE products SET is_active = NOT is_active WHERE id = ?";
        $toggle_stmt = mysqli_prepare($conn, $toggle_query);
        mysqli_stmt_bind_param($toggle_stmt, "i", $product_id);
        mysqli_stmt_execute($toggle_stmt);
        mysqli_stmt_close($toggle_stmt);
        $_SESSION['success'] = "Product status updated successfully.";
    } else {
        mysqli_stmt_close($verify_stmt);
        $_SESSION['error'] = "Unauthorized action.";
    }
}

// Handle add new product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_product'])) {
    if (!$can_upload_products) {
        $_SESSION['error'] = "Uploading products is restricted. Please upgrade to an active Starter, Growth, or Pro Subscription Plan.";
        header("Location: seller_products.php");
        exit;
    }

    $name = trim($_POST['product_name']);
    $category = trim($_POST['product_category']);
    $price = floatval($_POST['product_price']);
    $description = trim($_POST['product_description']);
    $weight_info = trim($_POST['weight_info'] ?? '');
    $pax_info = trim($_POST['pax_info'] ?? '');
    $stock = intval($_POST['stock'] ?? 0);
    
    $errors = [];
    
    if (empty($name)) $errors[] = "Product name is required";
    if (empty($category)) $errors[] = "Category is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";
    
    // Handle image upload (supports multiple images and legacy single input)
    $uploaded_images = [];
    if (!empty($_FILES['product_images']['name'])) {
        if (function_exists('handleProductMultiImageUpload')) {
            $uploaded_images = handleProductMultiImageUpload($_FILES['product_images'], 'uploads/products/');
        }
    } elseif (!empty($_FILES['product_image']['name']) && (int)($_FILES['product_image']['size'] ?? 0) > 0) {
        if (function_exists('handleProductMultiImageUpload')) {
            $uploaded_images = handleProductMultiImageUpload($_FILES['product_image'], 'uploads/products/');
        }
    }

    // Process weight-based pricing tiers
    $sizes_json = processProductWeightTiers($uploaded_images);
    if ($sizes_json !== null) {
        $tmp_sizes = json_decode($sizes_json, true);
        if (!empty($tmp_sizes)) {
            if ($price <= 0) {
                $price = min(array_column($tmp_sizes, 'price'));
            }
            if ($weight_info === '' && !empty($tmp_sizes[0]['weight'])) {
                $weight_info = $tmp_sizes[0]['weight'];
            }
            if ($pax_info === '' && !empty($tmp_sizes[0]['good_for'])) {
                $pax_info = $tmp_sizes[0]['good_for'];
            }
        }
    }

    $image_path = $uploaded_images[0] ?? '';
    $additional_images = array_slice($uploaded_images, 1);
    $additional_images_json = !empty($additional_images) ? json_encode(array_values($additional_images)) : null;

    if (empty($errors)) {
        // Generate unique product code
        do {
            $product_code = 'prod-' . bin2hex(random_bytes(3));
            $check_stmt = mysqli_prepare($conn, "SELECT 1 FROM products WHERE product_id = ? LIMIT 1");
            mysqli_stmt_bind_param($check_stmt, "s", $product_code);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            $exists = mysqli_stmt_num_rows($check_stmt) > 0;
            mysqli_stmt_close($check_stmt);
        } while ($exists);

        $insert_query = "INSERT INTO products (seller_id, product_id, name, category, price, description, weight_info, pax_info, image, additional_images, sizes, stock, is_active, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
        $stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($stmt, "isssdssssssi", $user_id, $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $image_path, $additional_images_json, $sizes_json, $stock);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Menu item '$name' added successfully! It is now live on the menu.";
            header("Location: seller_products.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to add product: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }
}

// Handle update product & re-upload images
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $product_id = intval($_POST['product_id']);
    
    // Verify product belongs to this seller
    $verify_query = "SELECT id, image, additional_images FROM products WHERE id = ? AND seller_id = ?";
    $v_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($v_stmt, "ii", $product_id, $user_id);
    mysqli_stmt_execute($v_stmt);
    $v_res = mysqli_stmt_get_result($v_stmt);
    $existing_product = $v_res ? mysqli_fetch_assoc($v_res) : null;
    mysqli_stmt_close($v_stmt);

    if (!$existing_product) {
        $_SESSION['error'] = "Unauthorized action. Product not found or does not belong to your shop.";
        header("Location: seller_products.php");
        exit;
    }

    $name = trim($_POST['product_name']);
    $category = trim($_POST['product_category']);
    $price = floatval($_POST['product_price']);
    $description = trim($_POST['product_description'] ?? '');
    $weight_info = trim($_POST['weight_info'] ?? '');
    $pax_info = trim($_POST['pax_info'] ?? '');
    $stock = intval($_POST['stock'] ?? 0);

    $errors = [];
    if (empty($name)) $errors[] = "Product name is required";
    if (empty($category)) $errors[] = "Category is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";

    if (empty($errors)) {
        // Collect kept existing images from checkboxes
        $kept_images = isset($_POST['kept_images']) && is_array($_POST['kept_images']) ? $_POST['kept_images'] : [];
        $valid_kept = [];
        $all_curr = function_exists('getProductAllImages') ? getProductAllImages($existing_product) : array_filter([$existing_product['image'] ?? '']);
        foreach ($kept_images as $kimg) {
            $kimg_clean = trim((string)$kimg);
            if (in_array($kimg_clean, $all_curr, true)) {
                $valid_kept[] = $kimg_clean;
            }
        }

        // Upload any newly selected images (shop owners can upload multiple images anytime)
        $newly_uploaded = [];
        if (!empty($_FILES['product_images']['name'])) {
            if (function_exists('handleProductMultiImageUpload')) {
                $newly_uploaded = handleProductMultiImageUpload($_FILES['product_images'], 'uploads/products/');
            }
        }

        // Process weight-based pricing tiers
        $sizes_json = processProductWeightTiers($newly_uploaded);
        if ($sizes_json !== null) {
            $tmp_sizes = json_decode($sizes_json, true);
            if (!empty($tmp_sizes)) {
                if ($price <= 0) {
                    $price = min(array_column($tmp_sizes, 'price'));
                }
                if ($weight_info === '' && !empty($tmp_sizes[0]['weight'])) {
                    $weight_info = $tmp_sizes[0]['weight'];
                }
                if ($pax_info === '' && !empty($tmp_sizes[0]['good_for'])) {
                    $pax_info = $tmp_sizes[0]['good_for'];
                }
            }
        }
        if ($price <= 0) $errors[] = "Price must be greater than 0";

        // Combine kept images + newly uploaded images
        $final_images = array_merge($valid_kept, $newly_uploaded);
        $final_primary = $final_images[0] ?? '';
        $final_additional = array_slice($final_images, 1);
        $final_additional_json = !empty($final_additional) ? json_encode(array_values($final_additional)) : null;

        $update_query = "UPDATE products SET name = ?, category = ?, price = ?, description = ?, weight_info = ?, pax_info = ?, stock = ?, image = ?, additional_images = ?, sizes = ?, updated_at = NOW() WHERE id = ? AND seller_id = ?";
        $u_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($u_stmt, "ssdsssisssii", $name, $category, $price, $description, $weight_info, $pax_info, $stock, $final_primary, $final_additional_json, $sizes_json, $product_id, $user_id);

        if (mysqli_stmt_execute($u_stmt)) {
            $_SESSION['success'] = "Menu item '$name' updated successfully! All photo and menu changes are now live.";
        } else {
            $_SESSION['error'] = "Failed to update product: " . mysqli_error($conn);
        }
        mysqli_stmt_close($u_stmt);
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }

    header("Location: seller_products.php");
    exit;
}

// Get seller's products
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';

$where_clauses = ["seller_id = ?"];
if ($search) {
    $where_clauses[] = "name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'";
}
if ($category) {
    $where_clauses[] = "category = '" . mysqli_real_escape_string($conn, $category) . "'";
}

$where_clause = "WHERE " . implode(" AND ", $where_clauses);
$products_query = "SELECT * FROM products $where_clause ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, "SELECT * FROM products WHERE seller_id = ? " . 
    ($search ? "AND name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'" : "") .
    ($category ? " AND category = '" . mysqli_real_escape_string($conn, $category) . "'" : "") .
    " ORDER BY created_at DESC");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$products_result = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

// Get categories
$categories_query = "SELECT DISTINCT category FROM products WHERE seller_id = ? ORDER BY category";
$cat_stmt = mysqli_prepare($conn, $categories_query);
mysqli_stmt_bind_param($cat_stmt, "i", $user_id);
mysqli_stmt_execute($cat_stmt);
$categories_result = mysqli_stmt_get_result($cat_stmt);
mysqli_stmt_close($cat_stmt);

$page_title = "My Products | Lechon Delights";
include 'includes/header.php';
?>

<style>
    .seller-products-page {
        min-height: calc(100vh - 200px);
        background: linear-gradient(135deg, #f5f5f5 0%, #fafafa 100%);
        padding: 50px 0;
    }

    .seller-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 40px;
        padding-bottom: 30px;
        border-bottom: 2px solid rgba(198, 40, 40, 0.1);
    }

    .seller-header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .header-content h1 {
        margin: 0;
        font-size: 32px;
        color: #333;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .header-content h1 i {
        color: #c62828;
    }

    .header-content p {
        margin: 8px 0 0 0;
        color: #666;
        font-size: 16px;
    }

    .btn {
        padding: 12px 28px;
        border-radius: 50px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-success {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }

    .btn-primary {
        background: #007bff;
        color: white;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }

    .btn-dark {
        background: #212529;
        color: #fff;
        box-shadow: 0 4px 15px rgba(33, 37, 41, 0.28);
        text-decoration: none;
    }

    .btn-dark:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(33, 37, 41, 0.35);
    }

    .btn-primary:hover {
        background: #0056b3;
        transform: translateY(-2px);
    }

    .btn-danger {
        background: #dc3545;
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
    }

    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        border-left: 4px solid;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .alert-success {
        background-color: #d4edda;
        border-color: #28a745;
        color: #155724;
    }

    .alert-danger {
        background-color: #f8d7da;
        border-color: #dc3545;
        color: #721c24;
    }

    .btn-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        opacity: 0.7;
        margin-left: auto;
    }

    .btn-close:hover {
        opacity: 1;
    }

    .section-header {
        margin-bottom: 30px;
    }

    .section-info {
        background: linear-gradient(135deg, #e7f3ff, #f0f8ff);
        border-left: 4px solid #2196F3;
        padding: 16px 20px;
        margin-bottom: 20px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #1565c0;
        font-weight: 500;
    }

    .section-info i {
        font-size: 18px;
        color: #2196F3;
    }

    .filter-form {
        display: grid;
        grid-template-columns: 1fr 200px auto;
        gap: 12px;
        align-items: end;
    }

    .form-control,
    .form-select {
        padding: 12px 16px;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .form-control:focus,
    .form-select:focus {
        outline: none;
        border-color: #c62828;
        box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.1);
    }

    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 25px;
        margin-top: 30px;
    }

    .product-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
    }

    .product-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .product-image {
        width: 100%;
        height: 220px;
        background: linear-gradient(135deg, #f5f5f5, #eeeeee);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
    }

    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .product-card:hover .product-image img {
        transform: scale(1.05);
    }

    .product-image i {
        font-size: 48px;
        color: #ccc;
    }

    .product-header {
        padding: 16px 20px 12px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex: 1;
    }

    .product-header h5 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: #333;
        flex: 1;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .badge-success {
        background: #d4edda;
        color: #155724;
    }

    .badge-danger {
        background: #f8d7da;
        color: #721c24;
    }

    .product-body {
        padding: 0 20px 16px;
        flex: 1;
        border-bottom: 1px solid #f0f0f0;
    }

    .product-category {
        margin: 0 0 8px 0;
        color: #666;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .product-price {
        margin: 0 0 12px 0;
        font-size: 20px;
        font-weight: 800;
        color: #c62828;
    }

    .product-info {
        margin: 6px 0;
        font-size: 13px;
        color: #666;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .product-info i {
        color: #999;
        width: 14px;
    }

    .product-footer {
        padding: 16px 20px;
        display: flex;
        gap: 10px;
    }

    .btn-sm {
        padding: 10px 16px;
        font-size: 13px;
        border-radius: 6px;
    }

    .no-results {
        grid-column: 1 / -1;
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    }

    .no-results i {
        font-size: 48px;
        color: #ddd;
        margin-bottom: 20px;
        display: block;
    }

    .no-results p {
        color: #999;
        font-size: 16px;
        margin: 0;
    }

    /* Modal Styles */
    .modal-content {
        border: none;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    }

    .modal-header {
        background: linear-gradient(135deg, #c62828, #b71c1c);
        border-bottom: none;
        padding: 25px;
        border-radius: 12px 12px 0 0;
    }

    .modal-header .modal-title {
        color: white;
        font-weight: 800;
        font-size: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    .modal-body {
        padding: 30px;
    }

    .form-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
        display: block;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-control {
        border: 1px solid #e0e0e0;
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 14px;
    }

    .form-control:focus {
        border-color: #c62828;
        box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.1);
        outline: none;
    }

    textarea.form-control {
        resize: vertical;
        min-height: 100px;
    }

    .small {
        font-size: 12px;
        color: #999;
        display: block;
        margin-top: 6px;
    }

    .alert-info {
        background: linear-gradient(135deg, #e7f3ff, #f0f8ff);
        border-left: 4px solid #2196F3;
        color: #1565c0;
        padding: 16px 20px;
        border-radius: 8px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
    }

    .alert-info i {
        color: #2196F3;
        margin-top: 2px;
    }

    .modal-footer {
        padding: 20px 30px;
        border-top: 1px solid #f0f0f0;
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
        padding: 12px 28px;
        border-radius: 50px;
        border: none;
        cursor: pointer;
        font-weight: 600;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    @media (max-width: 768px) {
        .seller-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 20px;
        }

        .seller-header-actions {
            width: 100%;
        }

        .header-content h1 {
            font-size: 24px;
        }

        .filter-form {
            grid-template-columns: 1fr;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
    }

    @media (max-width: 576px) {
        .seller-products-page {
            padding-bottom: 140px !important;
        }

        .seller-header-actions {
            flex-direction: column;
            width: 100%;
        }

        .seller-header-actions > * {
            width: 100% !important;
            text-align: center;
            justify-content: center;
        }

        .products-grid {
            grid-template-columns: 1fr !important;
        }

        .product-card .card-actions {
            flex-direction: column;
            gap: 6px;
        }

        .product-card .card-actions .btn {
            width: 100%;
        }
    }
</style>

<div class="seller-products-page">
    <div class="container">
        <div class="seller-header">
            <div class="header-content">
                <h1><i class="fas fa-box"></i> My Products</h1>
                <p>Manage and showcase your delicious offerings</p>
            </div>
            <div class="seller-header-actions">
                <a href="seller_vouchers.php" class="btn btn-dark">
                    <i class="fas fa-tags"></i> Manage Vouchers
                </a>
                <?php if ($can_upload_products): ?>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="fas fa-plus-circle"></i> Add New Product
                    </button>
                <?php else: ?>
                    <a href="subscription_plans.php?locked_feature=upload_products&required_tier=starter" class="btn btn-outline-danger" style="border-radius:8px; font-weight:600;">
                        <i class="fas fa-lock"></i> Add New Product (Plan Required)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$can_upload_products): ?>
            <div style="background:#fff8ef; border:1px solid #fedf89; border-radius:12px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div style="color:#b54708; font-size:0.92rem; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-lock" style="font-size:1.2rem; color:#b54708;"></i>
                    <span><strong>Limited Access (Non-Subscribed Account):</strong> Product uploading is locked. Subscribe to the Starter Plan or higher to upload and sell products.</span>
                </div>
                <a href="subscription_plans.php?locked_feature=upload_products&required_tier=starter" class="btn btn-sm" style="background:#b3261e; color:#ffffff; border-radius:999px; font-weight:700; padding:8px 20px; text-decoration:none;">
                    <i class="fas fa-arrow-up"></i> Upgrade to Starter
                </a>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                <button type="button" class="btn-close" onclick="this.parentElement.style.display='none';"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                <button type="button" class="btn-close" onclick="this.parentElement.style.display='none';"></button>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <div class="section-info">
                <i class="fas fa-lightbulb"></i> 
                <strong>Tip:</strong> Active products automatically appear on our menu page for customers to order!
            </div>
            <form method="GET" class="filter-form">
                <input type="text" name="search" placeholder="Search your products..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
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
                    $all_product_images = function_exists('getProductAllImages') ? getProductAllImages($product) : array_filter([$product['image'] ?? '']);
                    $photo_count = count($all_product_images);

                    $product_payload = [
                        'id' => (int)$product['id'],
                        'product_id' => $product['product_id'],
                        'name' => $product['name'],
                        'category' => $product['category'],
                        'price' => (float)$product['price'],
                        'stock' => (int)$product['stock'],
                        'weight_info' => (string)($product['weight_info'] ?? ''),
                        'pax_info' => (string)($product['pax_info'] ?? ''),
                        'description' => (string)($product['description'] ?? ''),
                        'images' => $all_product_images,
                        'sizes' => $product['sizes'] ?? null
                    ];
                    $payload_json = htmlspecialchars(json_encode($product_payload), ENT_QUOTES, 'UTF-8');
                    
                    echo "
                    <div class='product-card'>
                        <div class='product-image' style='position:relative;'>";
                    
                    if (!empty($product['image']) && file_exists($product['image'])) {
                        echo "<img src='" . htmlspecialchars($product['image']) . "' alt='" . htmlspecialchars($product['name']) . "'>";
                    } else {
                        echo "<i class='fas fa-image'></i>";
                    }

                    if ($photo_count > 1) {
                        echo "<span class='photo-count-badge' style='position:absolute; top:10px; right:10px; background:rgba(16,24,40,0.85); color:#ffffff; font-size:11px; font-weight:700; padding:4px 8px; border-radius:20px; backdrop-filter:blur(4px); display:flex; align-items:center; gap:5px; z-index:2;'><i class='fas fa-images'></i> " . $photo_count . " photos</span>";
                    }
                    
                    echo "</div>
                        <div class='product-header'>
                            <h5>" . htmlspecialchars($product['name']) . "</h5>
                            <span class='status-badge $status_class'>$status_text</span>
                        </div>
                        
                        <div class='product-body'>
                            <p class='product-category'>" . htmlspecialchars($product['category']) . "</p>
                            <p class='product-price'>₱" . number_format($product['price'], 2) . "</p>";
                    
                    echo "<p class='product-info'><i class='fas fa-cubes'></i> Stock: " . intval($product['stock']) . "</p>";
                    
                    if (!empty($product['weight_info'])) {
                        echo "<p class='product-info'><i class='fas fa-weight'></i> " . htmlspecialchars($product['weight_info']) . "</p>";
                    }
                    if (!empty($product['pax_info'])) {
                        echo "<p class='product-info'><i class='fas fa-users'></i> " . htmlspecialchars($product['pax_info']) . "</p>";
                    }
                    
                    echo "</div>
                        
                        <div class='product-footer' style='display:flex; gap:8px; width:100%; flex-wrap:wrap;'>
                            <button type='button' class='btn btn-sm btn-outline-primary js-edit-product-btn' data-product='" . $payload_json . "' style='flex:1; justify-content:center; border-radius:8px; font-weight:700; min-width:120px;'>
                                <i class='fas fa-edit'></i> Edit &amp; Photos
                            </button>
                            <form method='POST' style='flex:1; margin:0; min-width:100px;'>
                                <input type='hidden' name='product_id' value='{$product['id']}'>
                                <button type='submit' name='toggle_product' class='btn btn-sm btn-" . ($product['is_active'] ? 'danger' : 'success') . "' style='width:100%; justify-content:center; border-radius:8px; font-weight:700;'>
                                    <i class='fas fa-" . ($product['is_active'] ? 'eye-slash' : 'eye') . "'></i>
                                    " . ($product['is_active'] ? 'Hide' : 'Show') . "
                                </button>
                            </form>
                        </div>
                    </div>
                    ";
                }
            } else {
                echo "<div class='no-results'>
                        <i class='fas fa-inbox'></i>
                        <p>No products yet</p>
                        <p style='font-size: 14px; margin-top: 10px; color: #bbb;'>Click \"Add New Product\" to get started!</p>
                    </div>";
            }
            ?>
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
                            <input type="text" class="form-control" id="product_category" name="product_category" placeholder="e.g., Grilled Meats" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="product_price" class="form-label"><strong>Price (₱) *</strong></label>
                            <input type="number" class="form-control" id="product_price" name="product_price" placeholder="0.00" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label for="stock" class="form-label"><strong>Stock Quantity</strong></label>
                            <input type="number" class="form-control" id="stock" name="stock" placeholder="0" min="0" value="0">
                        </div>
                        <div class="col-md-6">
                            <label for="product_images" class="form-label"><strong><i class="fas fa-images text-danger"></i> Product Photos</strong></label>
                            <input type="file" class="form-control" id="product_images" name="product_images[]" multiple accept="image/*">
                            <small class="text-muted">Upload 1 or multiple photos (JPG, PNG, GIF, WEBP up to 8MB). You can add or change photos anytime.</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="weight_info" class="form-label"><strong>Weight Info</strong></label>
                            <input type="text" class="form-control" id="weight_info" name="weight_info" placeholder="e.g., 1 kg, 500g">
                        </div>
                        <div class="col-md-6">
                            <label for="pax_info" class="form-label"><strong>Pax Info</strong></label>
                            <input type="text" class="form-control" id="pax_info" name="pax_info" placeholder="e.g., Serves 4-6 pax">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="product_description" class="form-label"><strong>Description</strong></label>
                        <textarea class="form-control" id="product_description" name="product_description" rows="3" placeholder="Product details and description..."></textarea>
                    </div>

                    <!-- Weight & Size Pricing Options (Optional) -->
                    <div class="card mb-3 p-3 border rounded-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong style="color: #101828;"><i class="fas fa-weight-hanging text-danger"></i> Weight & Size Pricing Options</strong>
                                <span class="badge bg-secondary ms-2" style="font-size: 11px;">Optional</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="addSellerWeightTierRow('add')">
                                <i class="fas fa-plus"></i> Add Weight Tier
                            </button>
                        </div>
                        <p class="text-muted small mb-2" style="font-size: 12px;">
                            Optional: Add multiple sizes/weights with custom prices and photos (e.g. 500g, 1 kg). If not filled, customers will order at the base price above.
                        </p>
                        <div id="seller_add_weight_tiers_container"></div>
                    </div>
                    
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Once added, this product will be automatically visible on our menu page!
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addProductForm" class="btn btn-success" style="background:#b3261e; border-color:#b3261e;">
                    <i class="fas fa-save"></i> Add Product
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Product Modal (Unlimited Menu Photo Updates) -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #b3261e, #981b15);">
                <h5 class="modal-title" id="editProductLabel" style="color:#ffffff; font-weight:800; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-edit"></i> Edit Menu Item &amp; Photos
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
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
                            <input type="text" class="form-control" id="edit_product_category" name="product_category" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_product_price" class="form-label"><strong>Price (₱) *</strong></label>
                            <input type="number" class="form-control" id="edit_product_price" name="product_price" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_stock" class="form-label"><strong>Stock Quantity</strong></label>
                            <input type="number" class="form-control" id="edit_stock" name="stock" min="0">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_weight_info" class="form-label"><strong>Weight / Portion Info</strong></label>
                            <input type="text" class="form-control" id="edit_weight_info" name="weight_info" placeholder="e.g. 1 kg, 500g">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_pax_info" class="form-label"><strong>Pax Capacity</strong></label>
                            <input type="text" class="form-control" id="edit_pax_info" name="pax_info" placeholder="e.g. 4-6 pax">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_product_description" class="form-label"><strong>Description</strong></label>
                        <textarea class="form-control" id="edit_product_description" name="product_description" rows="3"></textarea>
                    </div>

                    <!-- Current Photos Management -->
                    <div class="mb-4" style="background:#f8f9fa; border:1px solid #eaecf0; border-radius:12px; padding:16px;">
                        <label class="form-label mb-2" style="font-weight:700; color:#101828;">
                            <i class="fas fa-images text-danger"></i> Current Menu Photos
                        </label>
                        <p class="small text-muted mb-2">Uncheck any photo you wish to remove. The first kept photo is used as the primary menu thumbnail.</p>
                        <div id="editCurrentImagesContainer" style="display:flex; gap:12px; flex-wrap:wrap;"></div>
                    </div>

                    <!-- Upload New Photos -->
                    <div class="mb-3">
                        <label for="edit_product_images" class="form-label"><strong><i class="fas fa-cloud-arrow-up text-primary"></i> Upload More / New Photos</strong></label>
                        <input type="file" class="form-control" id="edit_product_images" name="product_images[]" multiple accept="image/*">
                        <small class="text-muted">You can upload multiple new photos at once (JPG, PNG, GIF, WEBP up to 8MB each). You can upload and update your menu photos as many times as you want!</small>
                    </div>

                    <!-- Weight & Size Pricing Options (Optional) -->
                    <div class="card mb-3 p-3 border rounded-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong style="color: #101828;"><i class="fas fa-weight-hanging text-danger"></i> Weight & Size Pricing Options</strong>
                                <span class="badge bg-secondary ms-2" style="font-size: 11px;">Optional</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="addSellerWeightTierRow('edit')">
                                <i class="fas fa-plus"></i> Add Weight Tier
                            </button>
                        </div>
                        <p class="text-muted small mb-2" style="font-size: 12px;">
                            Optional: Add multiple sizes/weights with custom prices and photos (e.g. 500g, 1 kg). If not filled, customers will order at the base price above.
                        </p>
                        <div id="seller_edit_weight_tiers_container"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="editProductForm" class="btn btn-success" style="background:#b3261e; border-color:#b3261e;">
                    <i class="fas fa-save"></i> Save Changes &amp; Photos
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.seller-products-page {
    min-height: 100vh;
    background: #f5f5f5;
    padding: 40px 0;
}

.seller-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #ddd;
}

.header-content h1 {
    margin: 0;
    font-size: 28px;
    color: #333;
}

.header-content p {
    margin: 5px 0 0 0;
    color: #666;
    font-size: 14px;
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.product-card {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.product-header {
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 10px;
}

.product-header h5 {
    margin: 0;
    font-size: 16px;
    flex: 1;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.badge-danger {
    background: #f8d7da;
    color: #721c24;
}

.product-body {
    padding: 0 15px 15px 15px;
    border-bottom: 1px solid #eee;
}

.product-category {
    margin: 0 0 8px 0;
    color: #666;
    font-size: 13px;
}

.product-price {
    margin: 0 0 10px 0;
    font-size: 18px;
    font-weight: 600;
    color: #e74c3c;
}

.product-info {
    margin: 5px 0;
    font-size: 13px;
    color: #666;
}

.product-info i {
    margin-right: 5px;
    color: #999;
}

.product-footer {
    padding: 15px;
    display: flex;
    gap: 10px;
}

.btn {
    padding: 8px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.no-results {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
}

.filter-form {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.filter-form input,
.filter-form select {
    flex: 1;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.filter-form button {
    padding: 10px 20px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
}
</style>

<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
let sellerTierGlobalIndex = 200;
function escapeSellerHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function addSellerWeightTierRow(prefix, data = null) {
    const container = document.getElementById('seller_' + prefix + '_weight_tiers_container');
    if (!container) return;

    const idx = sellerTierGlobalIndex++;
    const nameVal = data && (data.name || data.weight) ? escapeSellerHtml(data.name || data.weight) : '';
    const priceVal = data && data.price !== undefined ? parseFloat(data.price).toFixed(2) : '';
    const goodForVal = data && (data.good_for || data.pax) ? escapeSellerHtml(data.good_for || data.pax) : '';
    const imgVal = data && data.image ? data.image : '';

    const row = document.createElement('div');
    row.className = 'weight-tier-row mb-2 p-2 border rounded-3 bg-white position-relative';
    row.style.border = '1px solid #eaecf0';

    let imgPreviewHtml = '';
    if (imgVal) {
        const fullImgSrc = imgVal.startsWith('http') || imgVal.startsWith('/') ? imgVal : imgVal;
        imgPreviewHtml = `
            <div class="d-flex align-items-center gap-2 mt-1">
                <img src="${fullImgSrc}" alt="Tier Photo" style="width: 32px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid #d0d5dd;">
                <span class="text-muted" style="font-size: 11px;">Current Photo</span>
                <input type="hidden" name="weight_tiers[${idx}][existing_image]" value="${escapeSellerHtml(imgVal)}">
            </div>
        `;
    }

    row.innerHTML = `
        <div class="row g-2 align-items-center">
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Weight / Option <span class="text-danger">*</span></label>
                <input type="text" name="weight_tiers[${idx}][name]" class="form-control form-control-sm" placeholder="e.g. 500g, 1 kg, 8-11 kg" value="${nameVal}" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Price (₱) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" name="weight_tiers[${idx}][price]" class="form-control form-control-sm" placeholder="0.00" value="${priceVal}" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Pax / Serves</label>
                <input type="text" name="weight_tiers[${idx}][good_for]" class="form-control form-control-sm" placeholder="e.g. 4-6 pax" value="${goodForVal}">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Photo for this Weight</label>
                <input type="file" name="weight_tier_images[${idx}]" class="form-control form-control-sm" accept="image/*">
                ${imgPreviewHtml}
            </div>
            <div class="col-md-1 text-end">
                <label class="form-label small d-block mb-1">&nbsp;</label>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.weight-tier-row').remove();" title="Remove Option">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>
    `;

    container.appendChild(row);
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-edit-product-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var raw = this.getAttribute('data-product');
            if (!raw) return;
            try {
                var prod = JSON.parse(raw);
                document.getElementById('edit_product_id').value = prod.id;
                document.getElementById('edit_product_name').value = prod.name;
                document.getElementById('edit_product_category').value = prod.category;
                document.getElementById('edit_product_price').value = prod.price;
                document.getElementById('edit_stock').value = prod.stock;
                document.getElementById('edit_weight_info').value = prod.weight_info || '';
                document.getElementById('edit_pax_info').value = prod.pax_info || '';
                document.getElementById('edit_product_description').value = prod.description || '';

                // Populate weight tiers in Edit Modal
                const editTiersContainer = document.getElementById('seller_edit_weight_tiers_container');
                if (editTiersContainer) {
                    editTiersContainer.innerHTML = '';
                    let sizesData = [];
                    if (prod.sizes) {
                        try {
                            sizesData = typeof prod.sizes === 'string' ? JSON.parse(prod.sizes) : prod.sizes;
                        } catch(e) {
                            sizesData = [];
                        }
                    }
                    if (Array.isArray(sizesData) && sizesData.length > 0) {
                        sizesData.forEach(tier => {
                            addSellerWeightTierRow('edit', tier);
                        });
                    }
                }

                var container = document.getElementById('editCurrentImagesContainer');
                container.innerHTML = '';
                var images = prod.images || [];
                if (images.length === 0) {
                    container.innerHTML = '<span class="text-muted" style="font-size:0.85rem;">No images currently uploaded for this product.</span>';
                } else {
                    images.forEach(function(imgSrc, index) {
                        var card = document.createElement('div');
                        card.style.cssText = 'position:relative; width:100px; height:100px; border-radius:10px; overflow:hidden; border:1px solid #eaecf0; background:#f8fafc;';
                        
                        var img = document.createElement('img');
                        img.src = imgSrc;
                        img.style.cssText = 'width:100%; height:100%; object-fit:cover;';
                        card.appendChild(img);

                        var badge = document.createElement('span');
                        badge.style.cssText = 'position:absolute; top:4px; left:4px; font-size:0.65rem; background:rgba(0,0,0,0.7); color:#fff; padding:2px 6px; border-radius:4px; font-weight:700;';
                        badge.textContent = index === 0 ? 'Primary' : '#' + (index + 1);
                        card.appendChild(badge);

                        var label = document.createElement('label');
                        label.style.cssText = 'position:absolute; bottom:0; left:0; right:0; background:rgba(255,255,255,0.94); padding:3px 4px; font-size:0.72rem; font-weight:700; color:#101828; display:flex; align-items:center; justify-content:center; gap:4px; margin:0; cursor:pointer;';
                        
                        var chk = document.createElement('input');
                        chk.type = 'checkbox';
                        chk.name = 'kept_images[]';
                        chk.value = imgSrc;
                        chk.checked = true;

                        label.appendChild(chk);
                        label.appendChild(document.createTextNode(' Keep'));
                        card.appendChild(label);

                        container.appendChild(card);
                    });
                }

                var modalEl = document.getElementById('editProductModal');
                var editModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                editModal.show();
            } catch (e) {
                console.error(e);
            }
        });
    });
});
</script>
<?php include 'includes/footer.php'; ?>
