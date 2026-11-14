<?php
/**
 * supplier/api.php
 * REST / AJAX API endpoints for Supplier Dashboard operations.
 */

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/IngredientStockManager.php';

$action = trim((string)($_REQUEST['action'] ?? ''));

function jsonOutput($success, $message, $data = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

if ($action === 'get_metrics') {
    $pending = 0;
    $p_q = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM supplier_orders WHERE supplier_id = $current_supplier_id AND status = 'pending'");
    if ($p_q && ($r = mysqli_fetch_assoc($p_q))) {
        $pending = (int)$r['cnt'];
    }
    jsonOutput(true, 'Metrics fetched', ['pending_count' => $pending]);
}

if ($action === 'quick_status_update') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = trim((string)($_POST['new_status'] ?? ''));

    $valid_statuses = ['accepted', 'processing', 'packed', 'in_transit', 'completed', 'rejected'];
    if ($order_id <= 0 || !in_array($new_status, $valid_statuses)) {
        jsonOutput(false, 'Invalid order ID or target status');
    }

    // Verify ownership
    $chk = mysqli_query($conn, "SELECT id, store_id, order_code, status FROM supplier_orders WHERE id = $order_id AND supplier_id = $current_supplier_id LIMIT 1");
    $ord = $chk ? mysqli_fetch_assoc($chk) : null;
    if (!$ord) {
        jsonOutput(false, 'Order not found');
    }

    if ($new_status === 'completed') {
        // Fetch items
        $fetch_items_q = mysqli_prepare($conn, "SELECT material_id, quantity_supplied, unit FROM supplier_order_items WHERE order_id = ?");
        $items_to_restock = [];
        if ($fetch_items_q) {
            mysqli_stmt_bind_param($fetch_items_q, "i", $order_id);
            mysqli_stmt_execute($fetch_items_q);
            $fi_res = mysqli_stmt_get_result($fetch_items_q);
            while ($fi = mysqli_fetch_assoc($fi_res)) {
                $items_to_restock[] = $fi;
            }
            mysqli_stmt_close($fetch_items_q);
        }

        mysqli_query($conn, "UPDATE supplier_orders SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = $order_id");
        IngredientStockManager::restockIngredients($conn, $ord['store_id'], $items_to_restock, $ord['order_code']);

        mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                             VALUES ($order_id, '{$ord['status']}', 'completed', {$_SESSION['user_id']}, 'Delivered & restocked via quick API')");

        jsonOutput(true, 'Order completed and store stock restocked', ['order_id' => $order_id, 'new_status' => 'completed']);
    } else {
        mysqli_query($conn, "UPDATE supplier_orders SET status = '$new_status', updated_at = NOW() WHERE id = $order_id");
        mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                             VALUES ($order_id, '{$ord['status']}', '$new_status', {$_SESSION['user_id']}, 'Updated status via quick API')");

        jsonOutput(true, "Order status updated to $new_status", ['order_id' => $order_id, 'new_status' => $new_status]);
    }
}

jsonOutput(false, 'Unrecognized API action');
