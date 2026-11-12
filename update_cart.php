<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function getDbConnectionForCart() {
    static $db_conn = null;
    static $initialized = false;

    if (!$initialized) {
        $initialized = true;
        if (file_exists(__DIR__ . '/includes/config.php')) {
            require_once __DIR__ . '/includes/config.php';
            if (isset($conn) && $conn instanceof mysqli) {
                $db_conn = $conn;
            }
        }
    }

    return $db_conn;
}

function getAvailableStockForCartItem($item) {
    $conn = getDbConnectionForCart();
    if (!$conn) {
        return null; // Fallback to legacy limit if DB is unavailable
    }

    $numeric_id = (int)($item['id'] ?? 0);
    $string_product_id = trim((string)($item['product_id'] ?? ''));
    $available_stock = null;

    if ($numeric_id > 0) {
        $query = "SELECT COALESCE(i.current_stock, p.stock, 0) AS available_stock
                  FROM products p
                  LEFT JOIN inventory i
                    ON p.id = i.product_id
                    AND i.inventory_date = CURDATE()
                    AND i.is_archived = 0
                  WHERE p.id = ?
                    AND p.is_archived = 0
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $numeric_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($result)) {
                $available_stock = (int)$row['available_stock'];
            }
            mysqli_stmt_close($stmt);
        }
    } elseif ($string_product_id !== '') {
        $query = "SELECT COALESCE(i.current_stock, p.stock, 0) AS available_stock
                  FROM products p
                  LEFT JOIN inventory i
                    ON p.id = i.product_id
                    AND i.inventory_date = CURDATE()
                    AND i.is_archived = 0
                  WHERE p.product_id = ?
                    AND p.is_archived = 0
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $string_product_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($result)) {
                $available_stock = (int)$row['available_stock'];
            }
            mysqli_stmt_close($stmt);
        }
    }

    if ($available_stock === null) {
        return null;
    }

    return max(0, $available_stock);
}

function getMaxAllowedQuantity($item) {
    $legacy_cap = 20;
    $available_stock = getAvailableStockForCartItem($item);
    if ($available_stock === null) {
        return $legacy_cap;
    }
    return max(0, min($legacy_cap, $available_stock));
}

function normalizeCartByStock() {
    $normalized = [];
    foreach ($_SESSION['cart'] as $item) {
        $qty = (int)($item['quantity'] ?? 0);
        if ($qty < 1) {
            continue;
        }

        $max_allowed = getMaxAllowedQuantity($item);
        if ($max_allowed <= 0) {
            continue; // Out of stock, remove from cart
        }

        if ($qty > $max_allowed) {
            $qty = $max_allowed;
        }

        $item['quantity'] = $qty;
        $normalized[] = $item;
    }
    $_SESSION['cart'] = array_values($normalized);
}

function buildCartPayload($success, $message) {
    $selected_subtotal = 0;
    $total_subtotal = 0;
    $items = [];
    $stores_map = [];
    $selected_sellers = [];

    foreach ($_SESSION['cart'] as $idx => $item) {
        $item_price = (float)($item['price'] ?? 0);
        $item_qty = (int)($item['quantity'] ?? 0);
        $item_total = $item_price * $item_qty;
        $is_selected = !isset($item['selected']) || !empty($item['selected']);
        $seller_id = (int)($item['seller_id'] ?? 1);
        $store_name = (string)($item['store_name'] ?? 'Lechon Delights');

        $total_subtotal += $item_total;
        if ($is_selected) {
            $selected_subtotal += $item_total;
            $selected_sellers[$seller_id] = true;
        }

        $item_data = [
            'index' => $idx,
            'id' => (int)($item['id'] ?? 0),
            'product_id' => $item['product_id'] ?? '',
            'name' => $item['name'] ?? '',
            'image' => $item['image'] ?? '',
            'price' => $item_price,
            'quantity' => $item_qty,
            'size' => $item['size'] ?? 'Regular',
            'addons' => $item['addons'] ?? [],
            'seller_id' => $seller_id,
            'store_name' => $store_name,
            'selected' => $is_selected ? 1 : 0,
            'item_total' => $item_total
        ];
        $items[] = $item_data;

        if (!isset($stores_map[$seller_id])) {
            $stores_map[$seller_id] = [
                'seller_id' => $seller_id,
                'store_name' => $store_name,
                'item_count' => 0,
                'selected_count' => 0,
                'items' => [],
                'subtotal' => 0
            ];
        }
        $stores_map[$seller_id]['item_count']++;
        if ($is_selected) {
            $stores_map[$seller_id]['selected_count']++;
            $stores_map[$seller_id]['subtotal'] += $item_total;
        }
        $stores_map[$seller_id]['items'][] = $item_data;
    }

    $stores = [];
    foreach ($stores_map as $sid => $sdata) {
        $sdata['all_selected'] = ($sdata['selected_count'] > 0 && $sdata['selected_count'] === $sdata['item_count']);
        $stores[] = $sdata;
    }

    $distinct_selected_sellers = array_keys($selected_sellers);
    $is_single_store_selected = (count($distinct_selected_sellers) === 1);
    $selected_seller_id = $is_single_store_selected ? (int)$distinct_selected_sellers[0] : 0;
    $selected_count = 0;
    foreach ($items as $it) {
        if ($it['selected'] === 1) {
            $selected_count++;
        }
    }

    return [
        'success' => $success,
        'message' => $message,
        'cart_count' => count($_SESSION['cart']),
        'selected_count' => $selected_count,
        'subtotal' => $selected_subtotal,
        'total_subtotal' => $total_subtotal,
        'is_single_store_selected' => $is_single_store_selected,
        'selected_seller_id' => $selected_seller_id,
        'stores' => $stores,
        'items' => $items
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'clear') {
    $_SESSION['cart'] = [];
    echo json_encode([
        'success' => true,
        'cart_count' => 0,
        'selected_count' => 0,
        'subtotal' => 0,
        'total_subtotal' => 0,
        'is_single_store_selected' => true,
        'selected_seller_id' => 0,
        'message' => 'Cart cleared',
        'stores' => [],
        'items' => []
    ]);
    exit;
}

// Bulk store selection: selecting a store checks all items in that store (and can exclusively select that store)
if ($action === 'select_store') {
    $target_seller_id = (int)($_POST['seller_id'] ?? 0);
    $selected_state = isset($_POST['selected']) ? (int)$_POST['selected'] : 1;
    $exclusive = isset($_POST['exclusive']) ? (!empty($_POST['exclusive'])) : true;

    foreach ($_SESSION['cart'] as $k => &$c_item) {
        $c_seller = (int)($c_item['seller_id'] ?? 1);
        if ($c_seller === $target_seller_id) {
            $c_item['selected'] = $selected_state ? 1 : 0;
        } elseif ($exclusive && $selected_state) {
            // Deselect other stores so customer is immediately prepared to checkout this store
            $c_item['selected'] = 0;
        }
    }
    unset($c_item);

    echo json_encode(buildCartPayload(true, 'Store items updated'));
    exit;
}

// Select/Deselect all items
if ($action === 'select_all') {
    $selected_state = isset($_POST['selected']) ? (int)$_POST['selected'] : 1;
    foreach ($_SESSION['cart'] as &$c_item) {
        $c_item['selected'] = $selected_state ? 1 : 0;
    }
    unset($c_item);

    echo json_encode(buildCartPayload(true, 'Selection updated'));
    exit;
}

normalizeCartByStock();

if (!isset($_POST['index'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Item index is required'
    ]);
    exit;
}

$index = (int)$_POST['index'];
if (!isset($_SESSION['cart'][$index])) {
    echo json_encode([
        'success' => false,
        'message' => 'Item not found'
    ]);
    exit;
}

// Toggle individual item selection
if ($action === 'toggle_select') {
    if (isset($_POST['selected'])) {
        $_SESSION['cart'][$index]['selected'] = (int)$_POST['selected'] ? 1 : 0;
    } else {
        $cur = !isset($_SESSION['cart'][$index]['selected']) || !empty($_SESSION['cart'][$index]['selected']);
        $_SESSION['cart'][$index]['selected'] = $cur ? 0 : 1;
    }
    echo json_encode(buildCartPayload(true, 'Item selection updated'));
    exit;
}

if ($action === 'remove') {
    array_splice($_SESSION['cart'], $index, 1);
    normalizeCartByStock();
    echo json_encode(buildCartPayload(true, 'Item removed'));
    exit;
}

if ($action === 'decrease') {
    if ((int)$_SESSION['cart'][$index]['quantity'] > 1) {
        $_SESSION['cart'][$index]['quantity']--;
    } else {
        array_splice($_SESSION['cart'], $index, 1);
    }
    normalizeCartByStock();
    echo json_encode(buildCartPayload(true, 'Quantity decreased'));
    exit;
}

if ($action === 'increase') {
    $current_item = $_SESSION['cart'][$index];
    $current_qty = (int)($current_item['quantity'] ?? 0);
    $max_allowed = getMaxAllowedQuantity($current_item);

    if ($max_allowed <= 0) {
        array_splice($_SESSION['cart'], $index, 1);
        normalizeCartByStock();
        echo json_encode(buildCartPayload(true, 'This item is currently out of stock and was removed from your cart.'));
        exit;
    }

    if ($current_qty >= $max_allowed) {
        normalizeCartByStock();
        echo json_encode(buildCartPayload(false, 'Only ' . $max_allowed . ' unit(s) available for this item.'));
        exit;
    }

    $_SESSION['cart'][$index]['quantity'] = $current_qty + 1;
    normalizeCartByStock();
    echo json_encode(buildCartPayload(true, 'Quantity increased'));
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Invalid action'
]);
?>
