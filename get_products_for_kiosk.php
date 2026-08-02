<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/admin/auth.php';
header('Content-Type: application/json');

checkAdminAccess();
requireAnyPermission(['orders.view', 'orders.create']);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

$today = date('Y-m-d');

// Query active products with today's inventory if available; fall back to products.stock
$sql = "SELECT 
            p.id,
            p.product_id,
            p.name,
            p.price,
            p.image,
            p.category,
            p.is_active,
            COALESCE(i.current_stock, p.stock) AS stock,
            COALESCE(i.min_stock_level, 5) AS min_stock_level
        FROM products p
        LEFT JOIN inventory i 
            ON p.id = i.product_id 
           AND i.inventory_date = ?
           AND (i.is_archived IS NULL OR i.is_archived = 0)
        WHERE (p.is_archived IS NULL OR p.is_archived = 0)" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "");

$stmt = mysqli_prepare($conn, $sql);
if ($seller_scope_id !== null) {
    mysqli_stmt_bind_param($stmt, 'si', $today, $seller_scope_id);
} else {
    mysqli_stmt_bind_param($stmt, 's', $today);
}
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$products = [];
$cats = [];
while ($row = mysqli_fetch_assoc($res)) {
    $row['price'] = (float)$row['price'];
    $row['stock'] = (int)$row['stock'];
    $row['is_active'] = (int)$row['is_active'];
    $products[] = $row;
    if (!empty($row['category'])) $cats[$row['category']] = true;
}
mysqli_stmt_close($stmt);

$categories = array_keys($cats);
sort($categories);

echo json_encode([
    'success' => true,
    'products' => $products,
    'categories' => $categories
]);
