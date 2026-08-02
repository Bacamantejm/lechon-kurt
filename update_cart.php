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
    $subtotal = 0;
    $items = [];

    foreach ($_SESSION['cart'] as $idx => $item) {
        $item_price = (float)($item['price'] ?? 0);
        $item_qty = (int)($item['quantity'] ?? 0);
        $item_total = $item_price * $item_qty;
        $subtotal += $item_total;

        $items[] = [
            'index' => $idx,
            'product_id' => $item['product_id'] ?? '',
            'name' => $item['name'] ?? '',
            'image' => $item['image'] ?? '',
            'price' => $item_price,
            'quantity' => $item_qty,
            'size' => $item['size'] ?? 'Regular',
            'addons' => $item['addons'] ?? [],
            'item_total' => $item_total
        ];
    }

    return [
        'success' => $success,
        'message' => $message,
        'cart_count' => count($_SESSION['cart']),
        'subtotal' => $subtotal,
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
        'subtotal' => 0,
        'message' => 'Cart cleared',
        'items' => []
    ]);
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
