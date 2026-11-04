<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';

$query = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
$limit = max(1, min($limit, 30));

if ($query === '') {
    echo json_encode([
        'success' => true,
        'query' => '',
        'count' => 0,
        'foods' => []
    ]);
    exit;
}

$searchParam = '%' . $query . '%';

$sql = "SELECT p.id, p.product_id, p.seller_id, p.name, p.description, p.category, p.price, p.image, p.avg_rating, p.review_count,
               COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(sl.store_name), ''), NULLIF(TRIM(u.full_name), ''), 'Lechon Delights Main Kitchen') AS store_name,
               COALESCE(sl.store_id, 0) AS branch_id,
               COALESCE(sl.city, '') AS store_city,
               COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END), 0) AS total_sold
        FROM products p
        LEFT JOIN users u ON u.id = p.seller_id
        LEFT JOIN store_locations sl ON (sl.owner_user_id = p.seller_id AND sl.is_active = 1)
        LEFT JOIN order_items oi ON (oi.product_id = p.product_id OR oi.product_id = CONVERT(p.id, CHAR) COLLATE utf8mb4_general_ci)
        LEFT JOIN orders o ON oi.order_id = o.id AND o.is_archived = 0 AND o.status <> 'cancelled'
        WHERE p.is_archived = 0 AND p.is_active = 1
          AND (
              p.name LIKE ?
              OR p.description LIKE ?
              OR p.category LIKE ?
              OR u.business_name LIKE ?
              OR sl.store_name LIKE ?
          )
        GROUP BY p.id, p.product_id, p.seller_id, p.name, p.description, p.category, p.price, p.image, p.avg_rating, p.review_count, store_name, branch_id, store_city
        ORDER BY total_sold DESC, p.avg_rating DESC, p.id DESC
        LIMIT ?";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Query preparation failed: ' . mysqli_error($conn)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'sssssi', $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $limit);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$foods = [];
while ($row = mysqli_fetch_assoc($res)) {
    $seller_id = (int)($row['seller_id'] ?? 0);
    $branch_id = (int)($row['branch_id'] ?? 0);
    $prod_id = (int)$row['id'];
    
    // Resolve store name
    $raw_store = trim((string)$row['store_name']);
    if ($raw_store === '' || strtolower($raw_store) === 'admin user') {
        $raw_store = 'Dasmariñas Main Branch';
    }

    // Resolve shop menu link with selected product
    $menu_params = [];
    if ($seller_id > 0) {
        $menu_params['seller_id'] = $seller_id;
    } elseif ($branch_id > 0) {
        $menu_params['branch_id'] = $branch_id;
    }
    $menu_params['product_id'] = $prod_id;
    $menu_link = 'menu.php?' . http_build_query($menu_params);

    // Resolve image
    $img = trim((string)($row['image'] ?? ''));
    if ($img === '' || !file_exists(__DIR__ . '/../' . ltrim($img, '/'))) {
        $img = 'images/menu/whole-lechon.jpg';
    }

    $foods[] = [
        'id' => $prod_id,
        'product_id' => (string)($row['product_id'] ?? $prod_id),
        'name' => trim((string)$row['name']),
        'category' => trim((string)$row['category']),
        'price' => (float)$row['price'],
        'price_formatted' => '₱' . number_format((float)$row['price'], 2),
        'image' => $img,
        'seller_id' => $seller_id,
        'branch_id' => $branch_id,
        'store_name' => $raw_store,
        'store_city' => trim((string)$row['store_city']),
        'menu_link' => $menu_link,
        'total_sold' => (int)$row['total_sold'],
        'rating' => (float)$row['avg_rating'],
        'reviews' => (int)$row['review_count']
    ];
}

mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'query' => $query,
    'count' => count($foods),
    'foods' => $foods
]);
