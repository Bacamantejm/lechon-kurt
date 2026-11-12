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

if (!function_exists('populateCartStoreMetadata')) {
function populateCartStoreMetadata(&$cart) {
    if (!is_array($cart) || empty($cart)) {
        return;
    }
    $conn = getDbConnectionForCartSnapshot();
    $missing_seller_product_ids = [];
    $needed_seller_ids = [];

    foreach ($cart as &$item) {
        if (!isset($item['selected'])) {
            $item['selected'] = 1;
        }
        $seller_id = (int)($item['seller_id'] ?? 0);
        if ($seller_id <= 0) {
            $pid = (int)($item['id'] ?? 0);
            if ($pid > 0) {
                $missing_seller_product_ids[] = $pid;
            }
        } else {
            $needed_seller_ids[] = $seller_id;
        }
    }
    unset($item);

    if ($conn && !empty($missing_seller_product_ids)) {
        $id_list = implode(',', array_map('intval', array_unique($missing_seller_product_ids)));
        $res = mysqli_query($conn, "SELECT id, seller_id FROM products WHERE id IN ({$id_list})");
        if ($res) {
            $p_map = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $p_map[(int)$row['id']] = (int)($row['seller_id'] ?? 1);
            }
            mysqli_free_result($res);
            foreach ($cart as &$item) {
                $pid = (int)($item['id'] ?? 0);
                if ((int)($item['seller_id'] ?? 0) <= 0 && isset($p_map[$pid])) {
                    $item['seller_id'] = $p_map[$pid] > 0 ? $p_map[$pid] : 1;
                    $needed_seller_ids[] = $item['seller_id'];
                }
            }
            unset($item);
        }
    }

    $store_name_map = [];
    if ($conn && !empty($needed_seller_ids)) {
        $s_list = implode(',', array_map('intval', array_unique($needed_seller_ids)));
        $s_res = mysqli_query($conn, "SELECT id, COALESCE(NULLIF(TRIM(business_name), ''), full_name, 'Partner Store') AS store_name FROM users WHERE id IN ({$s_list})");
        if ($s_res) {
            while ($s_row = mysqli_fetch_assoc($s_res)) {
                $store_name_map[(int)$s_row['id']] = (string)$s_row['store_name'];
            }
            mysqli_free_result($s_res);
        }
    }

    foreach ($cart as &$item) {
        if ((int)($item['seller_id'] ?? 0) <= 0) {
            $item['seller_id'] = 1;
        }
        $sid = (int)$item['seller_id'];
        if (empty($item['store_name'])) {
            $item['store_name'] = $store_name_map[$sid] ?? ($sid === 1 ? 'Lechon Delights' : 'Store #' . $sid);
        }
    }
    unset($item);
}
}

normalizeCartSnapshot();
populateCartStoreMetadata($_SESSION['cart']);

$selected_subtotal = 0;
$total_subtotal = 0;
$items = [];
$stores_map = [];
$selected_sellers = [];

foreach ($_SESSION['cart'] as $index => $item) {
    $price = (float)($item['price'] ?? 0);
    $quantity = (int)($item['quantity'] ?? 0);
    $item_total = $price * $quantity;
    $is_selected = !isset($item['selected']) || !empty($item['selected']);
    $seller_id = (int)($item['seller_id'] ?? 1);
    $store_name = (string)($item['store_name'] ?? 'Lechon Delights');

    $total_subtotal += $item_total;
    if ($is_selected) {
        $selected_subtotal += $item_total;
        $selected_sellers[$seller_id] = true;
    }

    $available_stock = getAvailableStockForSnapshotItem($item);

    $item_data = [
        'index' => $index,
        'id' => (int)($item['id'] ?? 0),
        'product_id' => $item['product_id'] ?? '',
        'name' => $item['name'] ?? '',
        'image' => $item['image'] ?? '',
        'price' => $price,
        'quantity' => $quantity,
        'size' => $item['size'] ?? 'Regular',
        'addons' => $item['addons'] ?? [],
        'seller_id' => $seller_id,
        'store_name' => $store_name,
        'selected' => $is_selected ? 1 : 0,
        'available_stock' => $available_stock,
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

$delivery_option = $_SESSION['delivery_option'] ?? 'pickup';
$delivery_location = $_SESSION['delivery_location'] ?? 'metro_manila';
$delivery_fee = 0;

if ($delivery_option === 'delivery' && isset($_SESSION['delivery_fees'][$delivery_location])) {
    $delivery_fee = (float)$_SESSION['delivery_fees'][$delivery_location]['fee'];
}

echo json_encode([
    'success' => true,
    'cart_count' => count($_SESSION['cart']),
    'selected_count' => $selected_count,
    'subtotal' => $selected_subtotal,
    'total_subtotal' => $total_subtotal,
    'delivery_option' => $delivery_option,
    'delivery_location' => $delivery_location,
    'delivery_fee' => $delivery_fee,
    'is_single_store_selected' => $is_single_store_selected,
    'selected_seller_id' => $selected_seller_id,
    'stores' => $stores,
    'items' => $items
]);
?>
