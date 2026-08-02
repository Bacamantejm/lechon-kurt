<?php
ob_start(); // Buffer output to prevent whitespace/warnings before JSON
session_start();
// Prevent PHP warnings/notices from breaking JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/admin/auth.php';
header('Content-Type: application/json');

checkAdminAccess();
requirePermission('orders.create');
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

// Handle fatal errors gracefully
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        http_response_code(500);
        header('Content-Type: application/json');
        ob_clean(); // Clean buffer to ensure valid JSON
        echo json_encode(['success' => false, 'message' => 'Server Fatal Error: ' . $error['message']]);
        exit;
    }
});

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (!validateCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh and try again.']);
    exit;
}

$customer_name = trim($input['customer_name'] ?? 'Walk-in Customer');
$items = $input['items'] ?? [];

if (empty($items) || !is_array($items)) {
    echo json_encode(['success' => false, 'message' => 'No items in order']);
    exit;
}

try {
    // Normalize item IDs and aggregate quantities by product id (numeric DB id)
    $byId = [];
    foreach ($items as $it) {
        $id = isset($it['id']) ? (int)$it['id'] : 0; // numeric products.id preferred from kiosk
        $pid = $id > 0 ? $id : 0;
        $qty = max(1, (int)($it['quantity'] ?? 0));
        if ($pid === 0) {
            // Fallback: try lookup using string product_id
            $pid_str = $conn->real_escape_string($it['product_id'] ?? '');
            if ($pid_str !== '') {
                $lookup_sql = "SELECT id FROM products WHERE (id = '$pid_str' OR product_id = '$pid_str')";
                if ($seller_scope_id !== null) {
                    $lookup_sql .= " AND seller_id = " . (int)$seller_scope_id;
                }
                $lookup_sql .= " LIMIT 1";
                $lookup = mysqli_query($conn, $lookup_sql);
                if ($lookup && $row = mysqli_fetch_assoc($lookup)) $pid = (int)$row['id'];
            }
        }
        if ($pid <= 0) continue;
        $byId[$pid] = ($byId[$pid] ?? 0) + $qty;
    }

    if (empty($byId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid items']);
        exit;
    }

    $today = date('Y-m-d');

    mysqli_begin_transaction($conn);

    // Fetch product info and current sellable stock for all ids
    $ids = array_keys($byId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $sql = "SELECT p.id, p.product_id, p.name, p.price, COALESCE(i.current_stock, p.stock) as stock
            FROM products p
            LEFT JOIN inventory i ON p.id = i.product_id AND i.inventory_date = ? AND (i.is_archived IS NULL OR i.is_archived = 0)
            WHERE p.id IN ($placeholders)" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "") . " FOR UPDATE";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . mysqli_error($conn));
    }
    $bind_params = [$today];
    foreach ($ids as $i) $bind_params[] = $i;
    if ($seller_scope_id !== null) {
        $bind_params[] = $seller_scope_id;
    }

    // bind dynamically
    $bind_types = 's' . $types . ($seller_scope_id !== null ? 'i' : '');
    $stmt_params = [];
    $stmt_params[] = & $bind_types;
    for ($k=0; $k<count($bind_params); $k++) { $stmt_params[] = & $bind_params[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $stmt_params);

    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $products = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['price'] = (float)$row['price'];
        $row['stock'] = (int)$row['stock'];
        $products[(int)$row['id']] = $row;
    }
    mysqli_stmt_close($stmt);

    // Validate stock availability
    foreach ($byId as $pid => $qty) {
        if (!isset($products[$pid])) {
            throw new Exception("Product $pid not found");
        }
        if ($qty > $products[$pid]['stock']) {
            throw new Exception($products[$pid]['name'] . " is short on stock. Available: " . $products[$pid]['stock']);
        }
    }

    // Create order header
    $user_id = $_SESSION['user_id'];
    $order_number = 'WALK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    $subtotal = 0.0;
    foreach ($byId as $pid => $qty) { $subtotal += $products[$pid]['price'] * $qty; }
    $delivery_fee = 0.0; // kiosk = walk-in/pickup
    $vat_rate = 0.12;
    $vat_amount = round($subtotal * $vat_rate, 2);
    $total_amount = $subtotal + $delivery_fee + $vat_amount;
    $status = 'confirmed';

    // Check orders table structure for optional columns
    $columns = [];
    $colres = mysqli_query($conn, "SHOW COLUMNS FROM orders");
    while ($c = mysqli_fetch_assoc($colres)) $columns[] = $c['Field'];

    if (in_array('delivery_option', $columns) && in_array('pickup_location', $columns) && in_array('delivery_location', $columns)) {
        $sql = "INSERT INTO orders (
                    order_number, user_id, customer_name, customer_email, customer_phone,
                    delivery_address, delivery_date, delivery_time, payment_method,
                    delivery_option, pickup_location, delivery_location,
                    subtotal, delivery_fee, total_amount, special_instructions,
                    status, payment_status
                ) VALUES (?, ?, ?, '', '', ?, CURDATE(), DATE_FORMAT(NOW(), '%H:%i:%s'), 'Cash', 'pickup', 1, NULL, ?, ?, ?, ?, ?, 'paid')";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            throw new Exception("Order prepare failed: " . mysqli_error($conn));
        }
        $delivery_address = 'In-store Pickup';
        $order_notes = 'Walk-in order (kiosk)';
        mysqli_stmt_bind_param($stmt, 'sissdddss',
            $order_number,
            $user_id,
            $customer_name,
            $delivery_address,
            $subtotal,
            $delivery_fee,
            $total_amount,
            $order_notes,
            $status
        );
    } else {
        $sql = "INSERT INTO orders (
                    order_number, user_id, customer_name, customer_email, customer_phone,
                    delivery_address, delivery_date, delivery_time, payment_method,
                    subtotal, delivery_fee, total_amount, special_instructions,
                    status, payment_status
                ) VALUES (?, ?, ?, '', '', ?, CURDATE(), DATE_FORMAT(NOW(), '%H:%i:%s'), 'Cash', ?, ?, ?, ?, ?, 'paid')";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            throw new Exception("Order prepare fallback failed: " . mysqli_error($conn));
        }
        $delivery_address = 'In-store Pickup';
        $order_notes = 'Walk-in order (kiosk)';
        mysqli_stmt_bind_param($stmt, 'sissdddss',
            $order_number,
            $user_id,
            $customer_name,
            $delivery_address,
            $subtotal,
            $delivery_fee,
            $total_amount,
            $order_notes,
            $status
        );
    }

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to create order: ' . mysqli_stmt_error($stmt));
    }
    $order_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Insert order items and deduct inventory
    $inventory_date = $today;
    foreach ($byId as $pid => $qty) {
        $p = $products[$pid];
        $item_total = $p['price'] * $qty;
        $addons_json = '[]';
        $size = 'Regular';
        $prod_str_id = $p['product_id'];

        $iq = mysqli_prepare($conn, "INSERT INTO order_items (order_id, product_id, product_name, price, quantity, size, addons, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($iq, 'issdissd', $order_id, $prod_str_id, $p['name'], $p['price'], $qty, $size, $addons_json, $item_total);
        if (!mysqli_stmt_execute($iq)) {
            throw new Exception('Failed to add line item: ' . mysqli_stmt_error($iq));
        }
        mysqli_stmt_close($iq);

        // Ensure inventory row exists for today
        $check = mysqli_prepare($conn, "SELECT id, current_stock FROM inventory WHERE product_id = ? AND inventory_date = ? FOR UPDATE");
        mysqli_stmt_bind_param($check, 'is', $pid, $inventory_date);
        mysqli_stmt_execute($check);
        $cres = mysqli_stmt_get_result($check);
        $inv = mysqli_fetch_assoc($cres);
        mysqli_stmt_close($check);

        if (!$inv) {
            $init = mysqli_prepare($conn, "INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated)
                                          SELECT id, ?, stock, 5, NOW() FROM products WHERE id = ?");
            mysqli_stmt_bind_param($init, 'si', $inventory_date, $pid);
            if (!mysqli_stmt_execute($init)) {
                throw new Exception('Failed to initialize inventory: ' . mysqli_stmt_error($init));
            }
            mysqli_stmt_close($init);
            // Re-select for update
            $check = mysqli_prepare($conn, "SELECT id, current_stock FROM inventory WHERE product_id = ? AND inventory_date = ? FOR UPDATE");
            mysqli_stmt_bind_param($check, 'is', $pid, $inventory_date);
            mysqli_stmt_execute($check);
            $cres = mysqli_stmt_get_result($check);
            $inv = mysqli_fetch_assoc($cres);
            mysqli_stmt_close($check);
        }

        $new_stock = (int)$inv['current_stock'] - $qty;
        if ($new_stock < 0) { throw new Exception($p['name'] . ' oversold during checkout.'); }

        $upd = mysqli_prepare($conn, "UPDATE inventory SET current_stock = ?, last_updated = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($upd, 'ii', $new_stock, $inv['id']);
        if (!mysqli_stmt_execute($upd)) { throw new Exception('Failed to update inventory: ' . mysqli_stmt_error($upd)); }
        mysqli_stmt_close($upd);

        // Log history
        $notes = 'Walk-in Order #' . $order_number;
        $hist = mysqli_prepare($conn, "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, created_at) VALUES (?, 'reduce', ?, ?, ?, ?, NOW())");
        mysqli_stmt_bind_param($hist, 'iiiss', $pid, $qty, $inv['current_stock'], $new_stock, $notes);
        mysqli_stmt_execute($hist);
        mysqli_stmt_close($hist);
    }

    mysqli_commit($conn);

    ob_end_clean(); // Clean any previous output
    echo json_encode([
        'success' => true,
        'order_id' => $order_id,
        'order_number' => $order_number,
        'subtotal' => round($subtotal, 2),
        'vat_amount' => $vat_amount,
        'vatable_sales' => round($subtotal, 2),
        'total_amount' => round($total_amount, 2)
    ]);
} catch (Exception $e) {
    if (isset($conn) && mysqli_ping($conn)) { mysqli_rollback($conn); }
    ob_end_clean(); // Clean any previous output
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
