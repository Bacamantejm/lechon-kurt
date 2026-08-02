<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function getDbConnectionForCartSnapshot() {
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

function getAvailableStockForSnapshotItem($item) {
    $conn = getDbConnectionForCartSnapshot();
    if (!$conn) {
        return null;
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

function normalizeCartSnapshot() {
    $normalized = [];
    foreach ($_SESSION['cart'] as $item) {
        $qty = (int)($item['quantity'] ?? 0);
        if ($qty < 1) {
            continue;
        }

        $available_stock = getAvailableStockForSnapshotItem($item);
        $max_allowed = 20;
        if ($available_stock !== null) {
            $max_allowed = max(0, min(20, $available_stock));
        }

        if ($max_allowed <= 0) {
            continue;
        }

        if ($qty > $max_allowed) {
            $qty = $max_allowed;
        }

        $item['quantity'] = $qty;
        $normalized[] = $item;
    }

    $_SESSION['cart'] = array_values($normalized);
}

normalizeCartSnapshot();

$subtotal = 0;
$items = [];

foreach ($_SESSION['cart'] as $index => $item) {
    $price = (float)($item['price'] ?? 0);
    $quantity = (int)($item['quantity'] ?? 0);
    $item_total = $price * $quantity;
    $subtotal += $item_total;

    $available_stock = getAvailableStockForSnapshotItem($item);

    $items[] = [
        'index' => $index,
        'product_id' => $item['product_id'] ?? '',
        'name' => $item['name'] ?? '',
        'image' => $item['image'] ?? '',
        'price' => $price,
        'quantity' => $quantity,
        'size' => $item['size'] ?? 'Regular',
        'addons' => $item['addons'] ?? [],
        'available_stock' => $available_stock,
        'item_total' => $item_total
    ];
}

$delivery_option = $_SESSION['delivery_option'] ?? 'pickup';
$delivery_location = $_SESSION['delivery_location'] ?? 'metro_manila';
$delivery_fee = 0;

if ($delivery_option === 'delivery' && isset($_SESSION['delivery_fees'][$delivery_location])) {
    $delivery_fee = (float)$_SESSION['delivery_fees'][$delivery_location]['fee'];
}

echo json_encode([
    'success' => true,
    'cart_count' => count($_SESSION['cart']),
    'subtotal' => $subtotal,
    'delivery_option' => $delivery_option,
    'delivery_location' => $delivery_location,
    'delivery_fee' => $delivery_fee,
    'items' => $items
]);
?>
