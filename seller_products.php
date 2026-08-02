<?php
session_start();
require_once 'includes/config.php';

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
            $upload_dir = 'uploads/products/';
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

        if (!empty($image_path)) {
            $insert_query = "INSERT INTO products (seller_id, product_id, name, category, price, description, weight_info, pax_info, image, stock, is_active, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, "isssdssssi", $user_id, $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $image_path, $stock);
        } else {
            $insert_query = "INSERT INTO products (seller_id, product_id, name, category, price, description, weight_info, pax_info, stock, is_active, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, "isssdsssi", $user_id, $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $stock);
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Product '$name' added successfully! It will now appear on the menu.";
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
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus-circle"></i> Add New Product
                </button>
            </div>
        </div>

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
                    
                    echo "
                    <div class='product-card'>
                        <div class='product-image'>";
                    
                    if (!empty($product['image']) && file_exists($product['image'])) {
                        echo "<img src='" . htmlspecialchars($product['image']) . "' alt='" . htmlspecialchars($product['name']) . "'>";
                    } else {
                        echo "<i class='fas fa-image'></i>";
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
                        
                        <div class='product-footer'>
                            <form method='POST' style='display: flex; gap: 10px; width: 100%;'>
                                <input type='hidden' name='product_id' value='{$product['id']}'>
                                <button type='submit' name='toggle_product' class='btn btn-sm btn-" . ($product['is_active'] ? 'danger' : 'success') . "' style='flex: 1; justify-content: center;'>
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
                            <input type="text" class="form-control" id="pax_info" name="pax_info" placeholder="e.g., Serves 4-6 pax">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="product_description" class="form-label"><strong>Description</strong></label>
                        <textarea class="form-control" id="product_description" name="product_description" rows="3" placeholder="Product details and description..."></textarea>
                    </div>
                    
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Once added, this product will be automatically visible on our menu page!
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
