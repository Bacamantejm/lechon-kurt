<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/favorites_helper.php';

if (!favoritesIsCustomerUserSession()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Please log in as a customer to manage favorites.'
    ]);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0 || !favoritesEnsureTable($conn)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Favorites service is unavailable right now.'
    ]);
    exit;
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'count')));

if ($action === 'count') {
    echo json_encode([
        'success' => true,
        'count' => favoritesGetTotalCount($conn, $user_id)
    ]);
    exit;
}

if ($action === 'in_stock_reminders') {
    $items = favoritesFetchUserInStockFavorites($conn, $user_id);
    echo json_encode([
        'success' => true,
        'count' => count($items),
        'items' => $items
    ]);
    exit;
}

if ($action !== 'toggle') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported favorites action.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$favorite_type = strtolower(trim((string)($_POST['favorite_type'] ?? '')));
$toggle_result = ['success' => false, 'is_favorite' => false];

if ($favorite_type === 'store') {
    $store_key = favoritesNormalizeStoreKey($_POST['store_key'] ?? '');
    if ($store_key === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid store key.'
        ]);
        exit;
    }

    $toggle_result = favoritesToggleStore($conn, $user_id, $store_key);
} elseif ($favorite_type === 'product') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid product id.'
        ]);
        exit;
    }

    $product_stmt = mysqli_prepare($conn, "SELECT id FROM products WHERE id = ? AND is_archived = 0 LIMIT 1");
    if ($product_stmt) {
        mysqli_stmt_bind_param($product_stmt, "i", $product_id);
        mysqli_stmt_execute($product_stmt);
        $product_result = mysqli_stmt_get_result($product_stmt);
        $product_exists = $product_result && mysqli_num_rows($product_result) > 0;
        if ($product_result) {
            mysqli_free_result($product_result);
        }
        mysqli_stmt_close($product_stmt);
        if (!$product_exists) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Product not found.'
            ]);
            exit;
        }
    }

    $toggle_result = favoritesToggleProduct($conn, $user_id, $product_id);
} else {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid favorite type.'
    ]);
    exit;
}

if (empty($toggle_result['success'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not update favorites. Please try again.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'favorite_type' => $favorite_type,
    'is_favorite' => !empty($toggle_result['is_favorite']),
    'total_count' => favoritesGetTotalCount($conn, $user_id)
]);

