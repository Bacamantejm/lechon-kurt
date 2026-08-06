<?php
session_start();

// Include database connection
require_once 'includes/config.php';
require_once 'includes/partner_voucher_helper.php';
require_once 'includes/partner_order_policy_helper.php';
pvEnsureVoucherSchema($conn);
popEnsurePolicySchema($conn);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['login_redirect'] = 'my_orders.php';
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

if (!function_exists('myOrdersRespond')) {
    function myOrdersRespond(bool $success, string $message, array $extra = []): void
    {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(array_merge([
                'success' => $success,
                'message' => $message
            ], $extra));
            exit;
        }
    }
}

// Get current tab (default to 'orders')
$current_tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'orders';

// Legacy server-side cancellation block removed in favor of AJAX cancel_request API

// Handle order archiving (soft delete)
if (isset($_POST['archive_order']) && isset($_POST['order_id'])) {
    $order_id = $_POST['order_id'];
    
    // Archive order (soft delete)
    $stmt = mysqli_prepare($conn, "UPDATE orders SET is_archived = 1, updated_at = NOW() WHERE id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $order_id, $user_id);
    mysqli_stmt_execute($stmt);
    
    if (mysqli_stmt_affected_rows($stmt) > 0) {
        $_SESSION['success_msg'] = "Order has been archived successfully.";
    } else {
        $_SESSION['error_msg'] = "Failed to archive order.";
    }
    mysqli_stmt_close($stmt);
    
    header('Location: my_orders.php');
    exit;
}

// Handle refund request (for delivered/completed orders)
if (isset($_POST['request_refund']) && isset($_POST['order_id'])) {
    $order_id = (int)$_POST['order_id'];
    $refund_reason = trim((string)($_POST['refund_reason'] ?? $_POST['reason'] ?? ''));
    $refund_details = trim((string)($_POST['refund_details'] ?? ''));
    if ($refund_reason === '') {
        $refund_reason = 'Other';
    }
    
    $stmt = mysqli_prepare($conn, "SELECT status, total_amount, user_id, payment_status FROM orders WHERE id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $order_id, $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($res)) {
        if (in_array(strtolower($row['status']), ['delivered', 'completed'])) {
            $existing_refund_stmt = mysqli_prepare(
                $conn,
                "SELECT r.id
                 FROM refunds r
                 INNER JOIN cancellations c ON c.id = r.cancellation_id
                 WHERE c.order_id = ?
                   AND c.user_id = ?
                   AND r.refund_status IN ('Refund Pending', 'Refund Approved', 'Refund Completed')
                 LIMIT 1"
            );
            if ($existing_refund_stmt) {
                mysqli_stmt_bind_param($existing_refund_stmt, "ii", $order_id, $user_id);
                mysqli_stmt_execute($existing_refund_stmt);
                $existing_refund_res = mysqli_stmt_get_result($existing_refund_stmt);
                if ($existing_refund_res && mysqli_fetch_assoc($existing_refund_res)) {
                    mysqli_stmt_close($existing_refund_stmt);
                    mysqli_stmt_close($stmt);
                    myOrdersRespond(false, "A refund request already exists for this order.");
                    $_SESSION['error_msg'] = "A refund request already exists for this order.";
                    header('Location: my_orders.php');
                    exit;
                }
                mysqli_stmt_close($existing_refund_stmt);
            }

            $policy = popGetOrderPolicy($conn, $order_id);
            $requires_proof = !empty($policy['require_refund_photo_for_damage']) && popRefundReasonNeedsPhoto($refund_reason);
            $evidence_path = '';

            if ($requires_proof && (empty($_FILES['refund_proof']) || (int)($_FILES['refund_proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
                mysqli_stmt_close($stmt);
                myOrdersRespond(false, "This store requires a proof photo for damaged or broken product refunds.");
                $_SESSION['error_msg'] = "This store requires a proof photo for damaged or broken product refunds.";
                header('Location: my_orders.php');
                exit;
            }

            if (!empty($_FILES['refund_proof']) && (int)($_FILES['refund_proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $evidence_path = popUploadRefundEvidence($_FILES['refund_proof'], 'order_' . $order_id);
                } catch (Exception $uploadException) {
                    mysqli_stmt_close($stmt);
                    myOrdersRespond(false, $uploadException->getMessage());
                    $_SESSION['error_msg'] = $uploadException->getMessage();
                    header('Location: my_orders.php');
                    exit;
                }
            }

            // Create cancellation record (as refund request)
            $cxl_query = "INSERT INTO cancellations (user_id, order_id, reason, other_reason_text, status) VALUES (?, ?, ?, ?, 'Requested')";
            $cxl_stmt = mysqli_prepare($conn, $cxl_query);
            mysqli_stmt_bind_param($cxl_stmt, "iiss", $user_id, $order_id, $refund_reason, $refund_details);
            mysqli_stmt_execute($cxl_stmt);
            $cancellation_id = mysqli_insert_id($conn);
            mysqli_stmt_close($cxl_stmt);
            
            // Create refund record
            $ref_query = "INSERT INTO refunds (cancellation_id, refund_amount, refund_status, refund_reason, customer_evidence_path) VALUES (?, ?, 'Refund Pending', ?, ?)";
            $ref_stmt = mysqli_prepare($conn, $ref_query);
            mysqli_stmt_bind_param($ref_stmt, "idss", $cancellation_id, $row['total_amount'], $refund_reason, $evidence_path);
            mysqli_stmt_execute($ref_stmt);
            mysqli_stmt_close($ref_stmt);
            
            // Notify admins about refund request
            $admin_ids = getAdminUserIds($conn);
            $notif_title = "Refund Request";
            $notif_message = "User #" . $user_id . " requested a refund for Order #" . $order_id . ". Reason: " . $refund_reason;
            if ($refund_details !== '') {
                $notif_message .= ". Details: " . $refund_details;
            }
            if ($evidence_path !== '') {
                $notif_message .= ". Evidence attached.";
            }
            
            foreach ($admin_ids as $admin_id) {
                createNotification($conn, $admin_id, 'refund_request', $notif_title, $notif_message, $order_id, 'order');
            }

            myOrdersRespond(true, "Refund request submitted for Order #$order_id.", [
                'requires_proof' => $requires_proof,
                'evidence_uploaded' => $evidence_path !== ''
            ]);
            $_SESSION['success_msg'] = "Refund request submitted.";
        } else {
            myOrdersRespond(false, "Order not eligible for refund.");
            $_SESSION['error_msg'] = "Order not eligible for refund.";
        }
    } else {
        myOrdersRespond(false, "Order not found.");
        $_SESSION['error_msg'] = "Order not found.";
    }
    mysqli_stmt_close($stmt);
    header('Location: my_orders.php');
    exit;
}

// Handle order cancellation
if (isset($_POST['cancel_order']) && isset($_POST['order_id'])) {
    $order_id = (int)$_POST['order_id'];
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $stmt = mysqli_prepare($conn, "SELECT status, payment_status, total_amount, COALESCE(downpayment_amount, 0) AS downpayment_amount FROM orders WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        myOrdersRespond(false, "Unable to validate your order right now.");
        $_SESSION['error_msg'] = "Unable to validate your order right now.";
        header('Location: my_orders.php');
        exit;
    }

    mysqli_stmt_bind_param($stmt, "ii", $order_id, $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        myOrdersRespond(false, "Order not found.");
        $_SESSION['error_msg'] = "Order not found.";
        header('Location: my_orders.php');
        exit;
    }

    $policy = popGetOrderPolicy($conn, $order_id);
    $downpayment_refundable = !empty($policy['downpayment_refundable']);
    $policy_check = popEvaluateOrderCancellation($policy, (string)($row['status'] ?? ''));
    if (empty($policy_check['allowed'])) {
        $policy_msg = (string)($policy_check['message'] ?? 'Cancellation is not allowed for this order.');
        myOrdersRespond(false, $policy_msg);
        $_SESSION['error_msg'] = $policy_msg;
        header('Location: my_orders.php');
        exit;
    }

    $success = false;
    $cancel_query = "UPDATE orders SET status = 'cancelled', cancellation_reason = ?, updated_at = NOW() WHERE id = ?";
    $cancel_stmt = mysqli_prepare($conn, $cancel_query);
    if ($cancel_stmt) {
        mysqli_stmt_bind_param($cancel_stmt, "si", $reason, $order_id);
        $success = mysqli_stmt_execute($cancel_stmt);
        mysqli_stmt_close($cancel_stmt);
    } else {
        // Fallback for legacy schema without cancellation_reason
        $cancel_query = "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?";
        $cancel_stmt = mysqli_prepare($conn, $cancel_query);
        if ($cancel_stmt) {
            mysqli_stmt_bind_param($cancel_stmt, "i", $order_id);
            $success = mysqli_stmt_execute($cancel_stmt);
            mysqli_stmt_close($cancel_stmt);
        }
    }

    if ($success) {
        $cancellation_id = 0;
        $cxl_query = "INSERT INTO cancellations (user_id, order_id, reason, other_reason_text, status) VALUES (?, ?, 'Other', ?, 'Cancelled')";
        $cxl_stmt = mysqli_prepare($conn, $cxl_query);
        if ($cxl_stmt) {
            mysqli_stmt_bind_param($cxl_stmt, "iis", $user_id, $order_id, $reason);
            mysqli_stmt_execute($cxl_stmt);
            $cancellation_id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($cxl_stmt);
        }

        $payment_status_normalized = strtolower((string)($row['payment_status'] ?? ''));
        $downpayment_amount = (float)($row['downpayment_amount'] ?? 0);
        $refund_amount = 0.0;
        $refund_note = '';
        if ($cancellation_id > 0 && in_array($payment_status_normalized, ['paid', 'partial', 'partially_paid'], true)) {
            $refund_amount = (float)($row['total_amount'] ?? 0);
            if (!$downpayment_refundable && $downpayment_amount > 0) {
                if (in_array($payment_status_normalized, ['partial', 'partially_paid'], true)) {
                    $refund_amount = 0.0;
                    $refund_note = 'Downpayment is non-refundable under this store\'s terms.';
                } else {
                    $refund_amount = max(0.0, $refund_amount - $downpayment_amount);
                    $refund_note = 'Refund excludes the non-refundable downpayment amount.';
                }
            }

            if ($refund_amount > 0) {
                $ref_query = "INSERT INTO refunds (cancellation_id, refund_amount, refund_status) VALUES (?, ?, 'Refund Pending')";
                $ref_stmt = mysqli_prepare($conn, $ref_query);
                if ($ref_stmt) {
                    mysqli_stmt_bind_param($ref_stmt, "id", $cancellation_id, $refund_amount);
                    mysqli_stmt_execute($ref_stmt);
                    mysqli_stmt_close($ref_stmt);
                }
            }
        }

        $admin_ids = getAdminUserIds($conn);
        $notif_title = "Order Cancelled by User";
        $notif_message = "User #" . $user_id . " cancelled Order #$order_id.";
        if ($refund_amount > 0) {
            $notif_message .= " A refund may be required.";
        } elseif ($refund_note !== '') {
            $notif_message .= " " . $refund_note;
        }
        foreach ($admin_ids as $admin_id) {
            createNotification($conn, $admin_id, 'order_cancelled', $notif_title, $notif_message, $order_id, 'order');
        }

        $success_message = "Order #$order_id has been cancelled.";
        if ($refund_note !== '') {
            $success_message .= " " . $refund_note;
        }
        myOrdersRespond(true, $success_message);
        $_SESSION['success_msg'] = $success_message;
    } else {
        myOrdersRespond(false, "Failed to cancel order.");
        $_SESSION['error_msg'] = "Failed to cancel order.";
    }
    
    header('Location: my_orders.php');
    exit;
}

// Handle re-order
if (isset($_POST['reorder']) && isset($_POST['order_id'])) {
    $order_id = $_POST['order_id'];
    
    // Initialize cart if needed
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    // Fetch order items
    $items_query = "SELECT product_id, product_name, price, quantity, size, addons FROM order_items WHERE order_id = ?";
    $stmt = mysqli_prepare($conn, $items_query);
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $count = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        // Decode addons
        $addons = [];
        if (!empty($row['addons'])) {
            $decoded = json_decode($row['addons'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $addons = $decoded;
            }
        }
        
        // Try to get image from products table
        $image = '';
        $p_stmt = mysqli_prepare($conn, "SELECT image FROM products WHERE product_id = ? OR id = ? LIMIT 1");
        $pid_str = $row['product_id'];
        $pid_int = intval($row['product_id']);
        mysqli_stmt_bind_param($p_stmt, "si", $pid_str, $pid_int);
        mysqli_stmt_execute($p_stmt);
        $p_res = mysqli_stmt_get_result($p_stmt);
        if ($p_row = mysqli_fetch_assoc($p_res)) {
            $image = $p_row['image'];
        }
        mysqli_stmt_close($p_stmt);

        $_SESSION['cart'][] = [
            'product_id' => $row['product_id'],
            'name' => $row['product_name'],
            'price' => $row['price'],
            'quantity' => $row['quantity'],
            'size' => $row['size'],
            'addons' => $addons,
            'image' => $image
        ];
        $count++;
    }
    mysqli_stmt_close($stmt);
    
    if ($count > 0) {
        $_SESSION['success_msg'] = "Items from Order #$order_id have been added to your cart.";
        header('Location: menu.php');
        exit;
    } else {
        $_SESSION['error_msg'] = "Failed to re-order items.";
    }
    
    header('Location: my_orders.php');
    exit;
}

// Handle pre-order cancellation
if (isset($_POST['cancel_preorder']) && isset($_POST['pre_order_id'])) {
    require_once 'preorder_service.php';
    $preorder_service = new PreOrderService($conn);
    $reason = $_POST['reason'] ?? 'User cancelled';
    
    $result = $preorder_service->cancelPreOrder($_POST['pre_order_id'], $reason);
    if (isset($_POST['ajax'])) {
        echo json_encode($result);
        exit;
    }
    if ($result['success']) {
        $_SESSION['success_msg'] = "Pre-order cancelled successfully.";
    } else {
        $_SESSION['error_msg'] = "Failed to cancel pre-order: " . $result['message'];
    }
    header('Location: my_orders.php?tab=preorders');
    exit;
}

$page_title = "My Orders | Lechon Delights";
include 'includes/header.php';

// Pagination setup
$orders_per_page = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $orders_per_page;

// Get total number of active orders
$count_query = "SELECT COUNT(*) as total FROM orders WHERE user_id = ? AND (is_archived IS NULL OR is_archived = 0)";
$count_stmt = mysqli_prepare($conn, $count_query);
mysqli_stmt_bind_param($count_stmt, "i", $user_id);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_orders = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_orders / $orders_per_page);
mysqli_stmt_close($count_stmt);

$orders_stats = [
    'total_orders' => (int)$total_orders,
    'active_orders' => 0,
    'fulfilled_orders' => 0,
    'lifetime_spent' => 0.00
];

if ($total_orders > 0) {
    $stats_query = "
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('pending', 'confirmed', 'preparing') THEN 1 ELSE 0 END), 0) AS active_orders,
            COALESCE(SUM(CASE WHEN status IN ('delivered', 'completed') THEN 1 ELSE 0 END), 0) AS fulfilled_orders,
            COALESCE(SUM(CASE WHEN status IN ('delivered', 'completed') THEN total_amount ELSE 0 END), 0) AS lifetime_spent
        FROM orders
        WHERE user_id = ?
          AND (is_archived IS NULL OR is_archived = 0)
    ";

    $stats_stmt = mysqli_prepare($conn, $stats_query);
    if ($stats_stmt) {
        mysqli_stmt_bind_param($stats_stmt, "i", $user_id);
        mysqli_stmt_execute($stats_stmt);
        $stats_result = mysqli_stmt_get_result($stats_stmt);
        if ($stats_row = mysqli_fetch_assoc($stats_result)) {
            $orders_stats['active_orders'] = (int)$stats_row['active_orders'];
            $orders_stats['fulfilled_orders'] = (int)$stats_row['fulfilled_orders'];
            $orders_stats['lifetime_spent'] = (float)$stats_row['lifetime_spent'];
        }
        mysqli_stmt_close($stats_stmt);
    }
}

// Check which orders have unreviewed items to determine if the "Leave a Review" button should be shown
$orders_with_unreviewed_items = []; // Initialize the variable
if ($total_orders > 0) {
    // Get all order IDs for the current user that are delivered or completed and not archived
    $eligible_order_ids_query = "SELECT id FROM orders WHERE user_id = ? AND status IN ('delivered', 'completed') AND (is_archived IS NULL OR is_archived = 0)";
    $stmt_eligible_ids = mysqli_prepare($conn, $eligible_order_ids_query);
    mysqli_stmt_bind_param($stmt_eligible_ids, "i", $user_id);
    mysqli_stmt_execute($stmt_eligible_ids);
    $eligible_order_ids_result = mysqli_stmt_get_result($stmt_eligible_ids);
    $eligible_order_ids = array_column(mysqli_fetch_all($eligible_order_ids_result, MYSQLI_ASSOC), 'id');
    mysqli_stmt_close($stmt_eligible_ids);

    if (!empty($eligible_order_ids)) {
        $placeholders = implode(',', array_fill(0, count($eligible_order_ids), '?'));
        $types = str_repeat('i', count($eligible_order_ids));

        // Find order IDs that have at least one unreviewed item
        $unreviewed_items_query = "SELECT DISTINCT order_id FROM order_items WHERE order_id IN ($placeholders) AND is_reviewed = 0";
        $stmt_unreviewed = mysqli_prepare($conn, $unreviewed_items_query);
        mysqli_stmt_bind_param($stmt_unreviewed, $types, ...$eligible_order_ids);
        mysqli_stmt_execute($stmt_unreviewed);
        $unreviewed_items_result = mysqli_stmt_get_result($stmt_unreviewed);
        $orders_with_unreviewed_items = array_column(mysqli_fetch_all($unreviewed_items_result, MYSQLI_ASSOC), 'order_id');
        mysqli_stmt_close($stmt_unreviewed);
    }
}

// Get active orders with order items
$query = "
    SELECT 
        o.id,
        o.order_number,
        o.customer_name,
        o.customer_email,
        o.customer_phone,
        o.delivery_address,
        o.delivery_date,
        o.delivery_time,
        o.payment_method,
        o.subtotal,
        o.delivery_fee,
        o.voucher_code,
        o.voucher_discount,
        o.total_amount,
        o.status,
        o.special_instructions,
        o.created_at,
        o.updated_at,
        o.delivery_option,
        o.pickup_location,
        o.delivery_location,
        o.is_archived,
        oi.product_name,
        oi.price,
        oi.quantity,
        oi.size,
        oi.addons,
        oi.total as item_total,
        p.image as product_image
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.user_id = ? AND (o.is_archived IS NULL OR o.is_archived = 0)
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "iii", $user_id, $orders_per_page, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Group orders by order_id
$orders = [];
while ($row = mysqli_fetch_assoc($result)) {
    $order_id = $row['id'];
    
    if (!isset($orders[$order_id])) {
        $orders[$order_id] = [
            'id' => $row['id'],
            'order_number' => $row['order_number'],
            'customer_name' => $row['customer_name'],
            'customer_email' => $row['customer_email'],
            'customer_phone' => $row['customer_phone'],
            'delivery_address' => $row['delivery_address'],
            'delivery_date' => $row['delivery_date'],
            'delivery_time' => $row['delivery_time'],
            'payment_method' => $row['payment_method'],
            'subtotal' => $row['subtotal'],
            'delivery_fee' => $row['delivery_fee'],
            'voucher_code' => $row['voucher_code'] ?? '',
            'voucher_discount' => $row['voucher_discount'] ?? 0,
            'total_amount' => $row['total_amount'],
            'status' => $row['status'],
            'special_instructions' => $row['special_instructions'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'delivery_option' => $row['delivery_option'],
            'pickup_location' => $row['pickup_location'],
            'delivery_location' => $row['delivery_location'],
            'is_archived' => $row['is_archived'],
            'items' => []
        ];
    }
    
    if ($row['product_name']) {
        $orders[$order_id]['items'][] = [
            'product_name' => $row['product_name'],
            'price' => $row['price'],
            'quantity' => $row['quantity'],
            'size' => $row['size'],
            'addons' => $row['addons'],
            'item_total' => $row['item_total'],
            'product_image' => $row['product_image'] ?? ''
        ];
    }
}
mysqli_stmt_close($stmt);

foreach ($orders as &$order) {
    $policy = popGetOrderPolicy($conn, (int)$order['id']);
    $policy_check = popEvaluateOrderCancellation($policy, (string)($order['status'] ?? ''));
    $order['partner_policy'] = $policy;
    $order['can_customer_cancel'] = !empty($policy_check['allowed']);
    $order['cancellation_policy_message'] = (string)($policy_check['message'] ?? '');
    $order['refund_terms'] = (string)($policy['refund_terms'] ?? '');
    $order['refund_photo_required'] = !empty($policy['require_refund_photo_for_damage']);
}
unset($order);
?>

<section class="page-header">
    <div class="container">
        <h1>My Orders</h1>
        <p>View your order history and track current orders</p>
    </div>
</section>

<section class="orders-section">
    <div class="container">
        <!-- Tab Navigation -->
        <div class="orders-tabs" style="margin-bottom: 30px;">
            <a href="my_orders.php?tab=orders" class="tab-link <?php echo $current_tab === 'orders' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-bag"></i> Regular Orders
            </a>
            <a href="my_orders.php?tab=preorders" class="tab-link <?php echo $current_tab === 'preorders' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i> Pre-Orders
            </a>
        </div>
        
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger">
                <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- REGULAR ORDERS TAB -->
        <?php if ($current_tab === 'orders'): ?>

        <!-- TOTAL ORDERS HERO STAT CARD -->
        <div class="total-orders-wrapper" style="display: flex; justify-content: center; margin-bottom: 28px;">
            <div class="total-orders-hero-card">
                <div class="total-orders-icon-wrap">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="total-orders-meta">
                    <span class="total-orders-label">Total Orders</span>
                    <strong class="total-orders-value animated-counter" data-target="<?php echo (int)($orders_stats['total_orders'] ?? 0); ?>">0</strong>
                </div>
            </div>
        </div>
        
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <h2>No Orders Yet</h2>
                <p>You haven't placed any orders yet. Start shopping now!</p>
                <a href="menu.php" class="btn-primary">
                    <i class="fas fa-utensils"></i> Browse Menu
                </a>
            </div>
        <?php else: ?>
            <div class="orders-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $status_key = strtolower(trim((string)$order['status']));
                    $progress_map = [
                        'pending' => 1,
                        'confirmed' => 2,
                        'preparing' => 3,
                        'delivered' => 4,
                        'completed' => 4,
                        'cancelled' => 0
                    ];
                    $order_progress = $progress_map[$status_key] ?? 1;
                    $delivery_label = ucfirst((string)($order['delivery_option'] ?? 'delivery'));
                    
                    $status_display_map = [
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'preparing' => 'In Preparation',
                        'assigned' => 'In - Transit',
                        'on_the_way' => 'In - Transit',
                        'arriving' => 'Arriving Soon',
                        'delivered' => 'Delivered',
                        'completed' => 'Delivered',
                        'cancelled' => 'Cancelled'
                    ];
                    $status_display_text = $status_display_map[$status_key] ?? ucfirst($order['status']);
                    ?>
                    <div class="clean-order-card" data-status="<?php echo htmlspecialchars($status_key); ?>">
                        <!-- Header Section -->
                        <div class="clean-card-header">
                            <div class="clean-header-left">
                                <span class="clean-order-badge">
                                    Order <span class="clean-order-num">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                                </span>
                                <span class="clean-order-date">
                                    Order Placed: <?php echo date('D, jS M Y', strtotime($order['created_at'])); ?>
                                </span>
                            </div>
                            <div class="clean-header-right">
                                <?php if (($order['delivery_option'] ?? 'delivery') !== 'pickup'): ?>
                                    <a href="track_order.php?order_id=<?php echo (int)$order['id']; ?>" class="clean-btn-track">
                                        <i class="fas fa-crosshairs"></i> TRACK ORDER
                                    </a>
                                <?php else: ?>
                                    <a href="track_order.php?order_id=<?php echo (int)$order['id']; ?>" class="clean-btn-track" style="background: linear-gradient(135deg, #2a211d, #7b6d64);">
                                        <i class="fas fa-store"></i> VIEW PICKUP
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Items List Section -->
                        <div class="clean-items-body">
                            <?php foreach ($order['items'] as $item): ?>
                                <?php
                                $item_img = !empty($item['product_image']) ? $item['product_image'] : 'assets/images/promo_lechon.jpg';
                                if (!file_exists($item_img) && file_exists('uploads/products/' . basename($item_img))) {
                                    $item_img = 'uploads/products/' . basename($item_img);
                                }
                                ?>
                                <div class="clean-item-row">
                                    <div class="clean-item-thumb-col">
                                        <img src="<?php echo htmlspecialchars($item_img); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" class="clean-item-img" onError="this.onerror=null;this.src='assets/images/promo_lechon.jpg';">
                                    </div>
                                    <div class="clean-item-info-col">
                                        <h4 class="clean-item-title"><?php echo htmlspecialchars($item['product_name']); ?></h4>
                                        <span class="clean-item-seller">By: Lechon Delights</span>
                                        <div class="clean-item-meta">
                                            <?php if (!empty($item['size'])): ?>Size: <strong><?php echo htmlspecialchars($item['size']); ?></strong> &bull; <?php endif; ?>
                                            Qty: <strong><?php echo (int)$item['quantity']; ?></strong>
                                            <span class="clean-item-price">&bull; ₱<?php echo number_format($item['price'], 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="clean-item-status-col">
                                        <span class="clean-col-label">Status</span>
                                        <strong class="clean-status-val status-<?php echo htmlspecialchars($status_key); ?>">
                                            <?php echo htmlspecialchars($status_display_text); ?>
                                        </strong>
                                    </div>
                                    <div class="clean-item-delivery-col">
                                        <span class="clean-col-label">Delivery Expected by:</span>
                                        <strong class="clean-delivery-val"><?php echo date('j F Y', strtotime($order['delivery_date'])); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Card Footer -->
                        <div class="clean-card-footer">
                            <div class="clean-footer-left">
                                <?php if (in_array(strtolower($order['status']), ['pending', 'confirmed', 'preparing', 'processing'], true) && !empty($order['can_customer_cancel'])): ?>
                                    <button type="button" class="clean-btn-cancel-action" onclick="cancelOrder(<?php echo $order['id']; ?>, <?php echo json_encode((string)$order['cancellation_policy_message']); ?>)">
                                        &times; CANCEL ORDER
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="clean-btn-toggle" onclick="toggleOrderDetails(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-chevron-down" id="toggleIcon_<?php echo $order['id']; ?>"></i>
                                    <span id="toggleText_<?php echo $order['id']; ?>">View Details & Actions</span>
                                </button>
                            </div>
                            <div class="clean-footer-middle">
                                <span class="clean-payment-info">
                                    Paid using <?php echo ucfirst(str_replace('_', ' ', (string)$order['payment_method'])); ?>
                                </span>
                            </div>
                            <div class="clean-footer-right">
                                <div class="clean-total-box">
                                    <span class="clean-total-label">Total:</span>
                                    <strong class="clean-total-amount">₱<?php echo number_format($order['total_amount'], 2); ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Collapsible Order Details & Actions Drawer -->
                        <div class="clean-details-drawer" id="orderDetails_<?php echo $order['id']; ?>" style="display: none; padding: 24px; background: #ffffff; border-top: 1px dashed #efddcd;">
                            <?php if ($status_key === 'cancelled'): ?>
                                <div class="order-progress cancelled">
                                    <i class="fas fa-ban"></i> This order was cancelled.
                                </div>
                            <?php else: ?>
                                <div class="order-progress">
                                    <div class="progress-step <?php echo $order_progress >= 1 ? 'done' : ''; ?>">
                                        <span class="progress-dot"></span>
                                        <span class="progress-label">Placed</span>
                                    </div>
                                    <div class="progress-step <?php echo $order_progress >= 2 ? 'done' : ''; ?>">
                                        <span class="progress-dot"></span>
                                        <span class="progress-label">Confirmed</span>
                                    </div>
                                    <div class="progress-step <?php echo $order_progress >= 3 ? 'done' : ''; ?>">
                                        <span class="progress-dot"></span>
                                        <span class="progress-label">Preparing</span>
                                    </div>
                                    <div class="progress-step <?php echo $order_progress >= 4 ? 'done current' : ''; ?>">
                                        <span class="progress-dot"></span>
                                        <span class="progress-label">Delivered</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="order-details">
                                <div class="detail-row">
                                    <div class="detail-item">
                                        <span class="detail-label">Customer:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Phone:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-item">
                                        <span class="detail-label">Delivery/Pickup Date:</span>
                                        <span class="detail-value"><?php echo date('F j, Y', strtotime($order['delivery_date'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Time:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($order['delivery_time']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-item">
                                        <span class="detail-label">Payment Method:</span>
                                        <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Address/Location:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($order['special_instructions'])): ?>
                                    <div class="special-instructions">
                                        <span class="detail-label">Special Instructions:</span>
                                        <p><?php echo htmlspecialchars($order['special_instructions']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <div class="order-policy-box">
                                    <div class="policy-row">
                                        <strong><i class="fas fa-file-contract"></i> Store Cancellation Terms:</strong>
                                        <span><?php echo htmlspecialchars((string)($order['partner_policy']['cancellation_terms'] ?? '')); ?></span>
                                    </div>
                                    <div class="policy-row">
                                        <strong><i class="fas fa-shield-alt"></i> Refund Terms:</strong>
                                        <span>
                                            <?php echo htmlspecialchars((string)($order['refund_terms'] ?? '')); ?>
                                            <?php if (!empty($order['refund_photo_required'])): ?>
                                                Photo proof is required for damaged or broken product claims.
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="order-actions" style="margin-top: 20px; display: flex; flex-wrap: wrap; gap: 10px;">
                                <?php
                                $can_leave_review = in_array($order['id'], $orders_with_unreviewed_items);
                                if (($order['status'] == 'delivered' || $order['status'] == 'completed') && $can_leave_review):
                                ?>
                                    <a href="leave_review.php?order_id=<?php echo $order['id']; ?>" class="btn-primary" style="background: #ef6b2e; border: none; color: #fff;">
                                        <i class="fas fa-star"></i> Leave a Review
                                    </a>
                                <?php elseif (($order['status'] == 'delivered' || $order['status'] == 'completed') && !$can_leave_review): ?>
                                    <button type="button" class="btn-secondary" disabled><i class="fas fa-check-circle"></i> All Items Reviewed</button>
                                <?php endif; ?>

                                <?php if (in_array(strtolower($order['status']), ['delivered', 'completed'])): ?>
                                    <button type="button" class="btn-cancel" style="border-color: #ff9800; color: #ff9800;" onclick="requestRefund(<?php echo $order['id']; ?>, <?php echo !empty($order['refund_photo_required']) ? 'true' : 'false'; ?>, <?php echo json_encode((string)$order['refund_terms']); ?>)">
                                        <i class="fas fa-undo"></i> Request Refund
                                    </button>
                                <?php endif; ?>

                                <?php if (in_array(strtolower($order['status']), ['delivered', 'completed', 'cancelled'])): ?>
                                    <form method="POST" action="my_orders.php" style="display: inline-block;">
                                        <input type="hidden" name="reorder_items" value="1">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                        <button type="submit" class="btn-reorder">
                                            <i class="fas fa-redo"></i> Re-order Items
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <a href="help_center.php?category=order&order_id=<?php echo $order['id']; ?>" class="btn-details">
                                    <i class="fas fa-life-ring"></i> Report Issue
                                </a>
                                
                                <button type="button" class="btn-archive" onclick="archiveOrder(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-archive"></i> Archive
                                </button>
                                
                                <a href="receipt.php?order_id=<?php echo $order['id']; ?>" class="btn-details" target="_blank">
                                    <i class="fas fa-receipt"></i> View Receipt
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?> <!-- End of else block for empty($orders) -->
        <?php endif; ?> <!-- End of Regular Orders Tab -->
        
        <!-- PRE-ORDERS TAB -->
        <?php endif; ?> <!-- End of else block for empty($orders) -->
        
        <?php if ($current_tab === 'preorders'): ?>
            <?php include 'preorder_tab_content.php'; ?>
        <?php endif; ?> <!-- End of Pre-Orders Tab -->
    </div>
</section>

<!-- Add SweetAlert2 CSS & JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- cancellation.js is included globally via footer -->

<style>
/* Total Orders Hero Stat Card */
.total-orders-hero-card {
    background: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 20px;
    padding: 16px 28px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    display: inline-flex;
    align-items: center;
    gap: 18px;
    min-width: 230px;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s ease, border-color 0.25s ease;
}
.total-orders-hero-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(179, 38, 30, 0.08);
    border-color: #e8d4c3;
}
.total-orders-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #fff0eb, #fff9f2);
    border: 1px solid #efddcd;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #b3261e;
    font-size: 1.25rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.08);
}
.total-orders-meta {
    display: flex;
    flex-direction: column;
}
.total-orders-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #7b6d64;
    margin-bottom: 3px;
}
.total-orders-value {
    font-size: 1.85rem;
    font-weight: 800;
    color: #171922;
    line-height: 1;
}

/* Clean Minimalist E-Commerce Order Card Styles */
.clean-order-card {
    background: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 16px;
    margin-bottom: 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    overflow: hidden;
    transition: box-shadow 0.25s ease, border-color 0.25s ease;
}
.clean-order-card:hover {
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
    border-color: #e8d4c3;
}

.clean-card-header {
    background: #ffffff;
    border-bottom: 1px solid #f3e8de;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.clean-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.clean-order-badge {
    background: #f4f5f7;
    border: 1px solid #e2e8f0;
    color: #171922;
    padding: 6px 16px;
    border-radius: 30px;
    font-size: 0.88rem;
    font-weight: 600;
}
.clean-order-num {
    color: #b3261e;
    font-weight: 800;
    font-family: monospace;
}
.clean-order-date {
    color: #7b6d64;
    font-size: 0.88rem;
    font-weight: 500;
}
.clean-btn-track {
    background: linear-gradient(135deg, #ef6b2e, #b3261e);
    color: #ffffff !important;
    border: 0;
    padding: 9px 22px;
    border-radius: 30px;
    font-weight: 700;
    font-size: 0.82rem;
    letter-spacing: 0.5px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(239, 107, 46, 0.25);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.clean-btn-track:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(239, 107, 46, 0.35);
}

.clean-items-body {
    padding: 8px 24px;
}
.clean-item-row {
    display: grid;
    grid-template-columns: 80px 1fr 150px 180px;
    gap: 20px;
    align-items: center;
    padding: 18px 0;
    border-bottom: 1px solid #f8f1eb;
}
.clean-item-row:last-child {
    border-bottom: 0;
}
.clean-item-img {
    width: 80px;
    height: 85px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #efddcd;
    background: #fffaf5;
}
.clean-item-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #171922;
    margin: 0 0 4px 0;
}
.clean-item-seller {
    font-size: 0.85rem;
    color: #7b6d64;
    display: block;
    margin-bottom: 6px;
}
.clean-item-meta {
    font-size: 0.88rem;
    color: #667085;
}
.clean-item-price {
    font-weight: 700;
    color: #171922;
}

.clean-col-label {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #7b6d64;
    display: block;
    margin-bottom: 4px;
}
.clean-status-val {
    font-size: 0.98rem;
    font-weight: 800;
}
.clean-status-val.status-pending { color: #f57c00; }
.clean-status-val.status-confirmed { color: #b3261e; }
.clean-status-val.status-preparing { color: #ef6b2e; }
.clean-status-val.status-assigned, .clean-status-val.status-on_the_way { color: #ef6b2e; }
.clean-status-val.status-delivered, .clean-status-val.status-completed { color: #2e7d32; }
.clean-status-val.status-cancelled { color: #d32f2f; }

.clean-delivery-val {
    font-size: 0.95rem;
    font-weight: 800;
    color: #171922;
}

.clean-card-footer {
    background: #faf8f5;
    border-top: 1px solid #f3e8de;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
}
.clean-footer-left {
    display: flex;
    align-items: center;
    gap: 16px;
}
.clean-btn-toggle {
    background: transparent;
    border: 0;
    color: #7b6d64;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0;
}
.clean-btn-toggle:hover {
    color: #b3261e;
}
.clean-btn-cancel-action {
    background: transparent;
    border: 0;
    color: #b3261e;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.clean-btn-cancel-action:hover {
    text-decoration: underline;
}
.clean-payment-info {
    font-size: 0.88rem;
    color: #7b6d64;
}
.clean-total-box {
    display: flex;
    align-items: baseline;
    gap: 6px;
}
.clean-total-label {
    font-size: 0.9rem;
    color: #7b6d64;
}
.clean-total-amount {
    font-size: 1.25rem;
    font-weight: 800;
    color: #171922;
}

@media (max-width: 768px) {
    .clean-item-row {
        grid-template-columns: 70px 1fr;
        gap: 14px;
    }
    .clean-item-status-col, .clean-item-delivery-col {
        grid-column: 2 / -1;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
}

/* Modern Orders Page Styles */
:root {
    --primary-color: #c62828;
    --primary-dark: #b71c1c;
    --text-dark: #2c3e50;
    --text-light: #6c757d;
    --bg-light: #f8f9fa;
    --card-shadow: 0 2px 12px rgba(0,0,0,0.04);
    --hover-shadow: 0 12px 24px rgba(0,0,0,0.1);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.orders-section {
    padding: 40px 0 80px;
    background: linear-gradient(180deg, #f9fafc 0%, #f4f6f9 100%);
    min-height: 80vh;
}

.order-policy-box {
    margin: 18px 0 8px;
    padding: 14px 16px;
    border-radius: 14px;
    border: 1px solid #fde68a;
    background: linear-gradient(135deg, #fffdf3, #fff7ed);
}

.policy-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    font-size: 13px;
    color: #7c2d12;
}

.policy-row + .policy-row {
    margin-top: 8px;
}

.policy-row strong {
    min-width: 190px;
    color: #9a3412;
}

.swal-policy-note {
    text-align: left;
    background: #fff7ed;
    color: #9a3412;
    border: 1px solid #fed7aa;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 12px;
    font-size: 13px;
    line-height: 1.45;
}

.swal-policy-note.refund {
    background: #fef3c7;
    border-color: #fcd34d;
    color: #92400e;
}

.swal-proof-hint {
    text-align: left;
    font-size: 12px;
    color: #64748b;
    margin-top: 6px;
}

.swal-proof-hint.required {
    color: #b91c1c;
    font-weight: 600;
}

.orders-overview {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}

.overview-card {
    background: #fff;
    border: 1px solid #ebedf1;
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 10px rgba(14, 22, 33, 0.04);
}

.overview-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #c62828;
    background: #ffebee;
    font-size: 1rem;
}

.overview-icon.overview-active {
    color: #ef6c00;
    background: #fff3e0;
}

.overview-icon.overview-complete {
    color: #2e7d32;
    background: #e8f5e9;
}

.overview-icon.overview-spent {
    color: #1565c0;
    background: #e3f2fd;
}

.overview-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.overview-label {
    color: #8892a0;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.45px;
    font-weight: 700;
}

.overview-value {
    color: #1f2a37;
    font-size: 1.18rem;
    font-weight: 800;
}

/* Modern Tabs */
.orders-tabs {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-bottom: 40px;
    border-bottom: none;
}

.tab-link {
    padding: 12px 30px;
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-light);
    background: white;
    border-radius: 50px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.02);
    cursor: pointer;
    text-decoration: none;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 8px;
    border: 2px solid transparent;
}

.tab-link:hover {
    transform: translateY(-2px);
    color: var(--primary-color);
    background: white;
}

.tab-link.active {
    background: var(--primary-color);
    color: white;
    box-shadow: 0 8px 15px rgba(198, 40, 40, 0.25);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 30px;
    font-size: 1.1rem;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background-color: white;
    border-radius: 20px;
    box-shadow: var(--card-shadow);
    animation: fadeIn 0.6s ease;
}

.empty-icon {
    font-size: 4rem;
    color: #e9ecef;
    margin-bottom: 20px;
}

.empty-state h2 {
    color: var(--text-dark);
    margin-bottom: 15px;
    font-size: 1.8rem;
}

.empty-state p {
    color: var(--text-light);
    margin-bottom: 30px;
    font-size: 1.1rem;
}

.empty-state .btn-primary {
    padding: 12px 35px;
    border-radius: 50px;
}

.orders-list {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

/* Order Card Design */
.order-card {
    background-color: white;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    overflow: hidden;
    transition: var(--transition);
    border: 1px solid rgba(0,0,0,0.03);
    opacity: 0;
    animation: slideUp 0.5s ease forwards;
}

.order-card[data-status="pending"] {
    border-left: 5px solid #f59e0b;
}

.order-card[data-status="confirmed"] {
    border-left: 5px solid #3b82f6;
}

.order-card[data-status="preparing"] {
    border-left: 5px solid #14b8a6;
}

.order-card[data-status="delivered"],
.order-card[data-status="completed"] {
    border-left: 5px solid #22c55e;
}

.order-card[data-status="cancelled"] {
    border-left: 5px solid #ef4444;
}

.order-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--hover-shadow);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 30px;
    background: linear-gradient(180deg, #ffffff 0%, #fff9f9 100%);
    border-bottom: 1px solid #f1f1f1;
}

.order-info h3 {
    color: var(--text-dark);
    margin-bottom: 5px;
    font-size: 1.25rem;
    font-weight: 700;
}

.order-date, .order-method {
    color: var(--text-light);
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 3px 0;
}

.order-meta-line {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 9px;
}

.meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #f4f6f9;
    border: 1px solid #e4e8ee;
    color: #475467;
    font-size: 0.78rem;
    font-weight: 700;
}

.order-status {
    text-align: right;
}

.status-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Modern Status Colors */
.status-pending { background-color: #fff8e1; color: #f57c00; }
.status-confirmed { background-color: #e3f2fd; color: #1976d2; }
.status-preparing { background-color: #e0f2f1; color: #00796b; }
.status-delivered, .status-completed { background-color: #e8f5e9; color: #2e7d32; }
.status-cancelled { background-color: #ffebee; color: #c62828; }

.order-total {
    color: var(--primary-color);
    font-size: 1.4rem;
    font-weight: 800;
    margin-top: 5px;
}

.order-progress {
    margin: 0 30px;
    padding: 14px 12px 12px;
    border-bottom: 1px solid #f0f2f5;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
}

.order-progress.cancelled {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #c62828;
    font-size: 0.9rem;
    font-weight: 700;
    background: #fff5f5;
    border-radius: 10px;
    border: 1px solid #ffd9d9;
    padding: 12px 14px;
    margin-top: 16px;
    margin-bottom: 0;
}

.progress-step {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
}

.progress-step:not(:last-child)::after {
    content: "";
    position: absolute;
    top: 5px;
    left: calc(50% + 11px);
    width: calc(100% - 20px);
    height: 2px;
    background: #dde3ea;
}

.progress-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #cbd5e1;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #e2e8f0;
    z-index: 1;
}

.progress-label {
    font-size: 0.74rem;
    color: #94a3b8;
    font-weight: 700;
    letter-spacing: 0.15px;
}

.progress-step.done .progress-dot {
    background: #c62828;
    box-shadow: 0 0 0 2px rgba(198, 40, 40, 0.18);
}

.progress-step.done .progress-label {
    color: #334155;
}

.progress-step.done:not(:last-child)::after {
    background: rgba(198, 40, 40, 0.5);
}

.progress-step.current .progress-label {
    color: #c62828;
}

.order-details {
    padding: 30px;
}

.detail-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px;
    margin-bottom: 25px;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.detail-label {
    color: #999;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    color: var(--text-dark);
    font-size: 1rem;
    font-weight: 600;
}

.special-instructions {
    margin: 20px 0 30px;
    padding: 20px;
    background-color: #fff8e1;
    border-radius: 8px;
    border-left: 4px solid #ffc107;
}

.special-instructions .detail-label {
    color: #f57c00;
}

.special-instructions p {
    color: #5d4037;
    margin: 0;
}

.order-items {
    margin-top: 20px;
    background: #fff;
    border: 1px solid #eee;
    border-radius: 8px;
    overflow: hidden;
}

.order-items h4 {
    padding: 15px 20px;
    background: #f8f9fa;
    margin: 0;
    font-size: 1rem;
    color: var(--text-dark);
    border-bottom: 1px solid #eee;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th {
    padding: 12px 20px;
    text-align: left;
    color: #888;
    font-weight: 600;
    font-size: 0.85rem;
    border-bottom: 1px solid #eee;
    background: #fff;
}

.items-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #f5f5f5;
    color: var(--text-dark);
    vertical-align: top;
}

.item-name {
    font-weight: 600;
    color: var(--text-dark);
}

.item-size, .item-addons {
    font-size: 0.8rem;
    color: var(--text-light);
    margin-top: 2px;
}

.summary-row td {
    border-bottom: none;
    padding-top: 15px;
    padding-bottom: 5px;
}

.total-row {
    background-color: white;
}

.total-row td {
    border-top: 1px solid #eee;
    border-bottom: none;
    padding-top: 20px;
    padding-bottom: 20px;
    font-size: 1.1rem;
}

.text-right {
    text-align: right;
}

.total-amount {
    color: var(--primary-color);
}

.order-actions {
    padding: 20px 30px 25px;
    background-color: white;
    border-top: 1px solid #f1f1f1;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.order-actions > * {
    min-height: 40px;
}

.btn-cancel, .btn-archive, .btn-details, .btn-reorder, .btn-track {
    padding: 10px 24px;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: var(--transition);
    border: none;
}

.btn-track {
    background-color: #17a2b8;
    color: white;
}

.btn-track:hover {
    background-color: #138496;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
}

.btn-cancel {
    background-color: white;
    border: 1px solid #ffcdd2;
    color: #c62828;
}

.btn-cancel:hover {
    background-color: #ffebee;
    transform: translateY(-2px);
}

.btn-archive {
    background-color: #f1f3f5;
    color: var(--text-light);
}

.btn-archive:hover {
    background-color: #e9ecef;
    color: var(--text-dark);
}

.btn-details {
    background-color: white;
    border: 1px solid #e0e0e0;
    color: var(--text-dark);
}

.btn-details:hover {
    border-color: var(--text-dark);
    transform: translateY(-2px);
}

.btn-reorder {
    background-color: white;
    border: 1px solid #28a745;
    color: #28a745;
}

.btn-reorder:hover {
    background-color: #e8f5e9;
    transform: translateY(-2px);
}

.order-actions .btn-primary,
.order-actions .btn-secondary {
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.order-actions .btn-secondary {
    border: 1px solid #d0d5dd;
    color: #667085;
    background: #f8f9fb;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 40px;
}

.page-link {
    width: 40px;
    height: 40px;
    display: flex;
    justify-content: center;
    align-items: center;
    background-color: white;
    border: 1px solid #e0e0e0;
    border-radius: 50%;
    color: var(--text-dark);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
}

.page-link:hover {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
    color: white;
    transform: scale(1.1);
}

.page-link.active {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
    color: white;
    box-shadow: 0 4px 10px rgba(198, 40, 40, 0.3);
}

/* Animations */
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Stagger Animation Delays */
.order-card:nth-child(1) { animation-delay: 0.1s; }
.order-card:nth-child(2) { animation-delay: 0.2s; }
.order-card:nth-child(3) { animation-delay: 0.3s; }
.order-card:nth-child(4) { animation-delay: 0.4s; }
.order-card:nth-child(5) { animation-delay: 0.5s; }

@media (max-width: 768px) {
    .orders-overview {
        grid-template-columns: 1fr 1fr;
    }

    .overview-card {
        padding: 14px;
        border-radius: 12px;
    }

    .order-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .order-status {
        text-align: left;
        width: 100%;
    }

    .order-progress {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 6px;
        margin: 0 16px;
        padding: 14px 0 10px;
    }

    .progress-step:not(:last-child)::after {
        display: none;
    }
    
    .detail-row {
        grid-template-columns: 1fr;
        gap: 16px;
        margin-bottom: 16px;
    }

    .order-details {
        padding: 20px 16px;
    }
    
    .items-table {
        display: block;
        overflow-x: auto;
    }
    
    .order-actions {
        flex-direction: column;
        padding: 16px;
    }
    
    .btn-cancel, .btn-archive, .btn-details, .btn-reorder, .btn-track, .order-actions .btn-primary, .order-actions .btn-secondary {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
function archiveOrder(orderId) {
    Swal.fire({
        title: 'Archive Order?',
        text: "This order will be moved to archives. You can view archived orders later.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, archive it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Submit the form via AJAX
            const formData = new FormData();
            formData.append('archive_order', 'true');
            formData.append('order_id', orderId);
            
            fetch('my_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                Swal.fire(
                    'Archived!',
                    'Your order has been archived.',
                    'success'
                ).then(() => {
                    // Refresh the page
                    location.reload();
                });
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire(
                    'Error!',
                    'Failed to archive order. Please try again.',
                    'error'
                );
            });
        }
    });
}

function cancelOrder(orderId, policyMessage = '') {
    Swal.fire({
        title: 'Cancel Order?',
        icon: 'warning',
        html: `
            ${policyMessage ? `<div class="swal-policy-note">${escapeHtml(policyMessage)}</div>` : ''}
            <textarea id="cancelReasonInput" class="swal2-textarea" placeholder="Reason for cancellation (optional)"></textarea>
        `,
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, cancel it!',
        focusConfirm: false,
        preConfirm: () => document.getElementById('cancelReasonInput').value.trim()
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('cancel_order', 'true');
            formData.append('order_id', orderId);
            formData.append('reason', result.value || '');
            formData.append('ajax', 'true');
            
            fetch('my_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(async (response) => {
                const raw = await response.text();
                try {
                    return JSON.parse(raw);
                } catch (parseError) {
                    throw new Error(raw && raw.trim() !== '' ? raw : 'Invalid server response.');
                }
            })
            .then(data => {
                if (data.success) {
                    Swal.fire('Cancelled!', data.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const fallbackMessage = String(error && error.message ? error.message : 'An error occurred while processing your request.');
                Swal.fire('Error!', fallbackMessage, 'error');
            });
        }
    });
}

function cancelPreOrder(preOrderId) {
    Swal.fire({
        title: 'Cancel Pre-Order?',
        text: "Are you sure you want to cancel this pre-order?",
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'Reason for cancellation (optional)',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, cancel it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('cancel_preorder', 'true');
            formData.append('pre_order_id', preOrderId);
            formData.append('reason', result.value);
            formData.append('ajax', 'true');
            
            fetch('my_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Cancelled!', data.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            });
        }
    });
}

function reorderOrder(orderId) {
    Swal.fire({
        title: 'Re-order Items?',
        text: "This will add items from this order to your current cart.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, add to cart!'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'my_orders.php';
            
            const inputReorder = document.createElement('input');
            inputReorder.type = 'hidden';
            inputReorder.name = 'reorder';
            inputReorder.value = 'true';
            form.appendChild(inputReorder);
            
            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'order_id';
            inputId.value = orderId;
            form.appendChild(inputId);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function refundReasonNeedsProof(reason) {
    return ['damaged product', 'broken product'].includes(String(reason || '').trim().toLowerCase());
}

function escapeHtml(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function requestRefund(orderId, proofRequired = false, refundTerms = '') {
    Swal.fire({
        title: 'Request Refund?',
        icon: 'question',
        html: `
            ${refundTerms ? `<div class="swal-policy-note refund">${escapeHtml(refundTerms)}</div>` : ''}
            <select id="refundReasonInput" class="swal2-select" style="display:block;width:100%;margin-top:8px;">
                <option value="">Select refund reason</option>
                <option value="Damaged Product">Damaged Product</option>
                <option value="Broken Product">Broken Product</option>
                <option value="Wrong Item">Wrong Item</option>
                <option value="Missing Item">Missing Item</option>
                <option value="Quality Issue">Quality Issue</option>
                <option value="Other">Other</option>
            </select>
            <textarea id="refundDetailsInput" class="swal2-textarea" placeholder="Tell us what happened"></textarea>
            <input id="refundProofInput" type="file" class="swal2-file" accept="image/png,image/jpeg,image/webp">
            <div id="refundProofHint" class="swal-proof-hint">Upload a clear photo of the damaged or broken item when required by the store.</div>
        `,
        showCancelButton: true,
        confirmButtonColor: '#ff9800',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Submit Request',
        focusConfirm: false,
        didOpen: () => {
            const reasonSelect = document.getElementById('refundReasonInput');
            const proofHint = document.getElementById('refundProofHint');
            const refreshProofState = () => {
                const proofNeeded = proofRequired && refundReasonNeedsProof(reasonSelect.value);
                proofHint.textContent = proofNeeded
                    ? 'This refund reason requires a proof photo before the request can be submitted.'
                    : 'You can attach a proof photo to help the store and admin review faster.';
                proofHint.classList.toggle('required', proofNeeded);
            };
            reasonSelect.addEventListener('change', refreshProofState);
            refreshProofState();
        },
        preConfirm: () => {
            const refundReason = document.getElementById('refundReasonInput').value;
            const refundDetails = document.getElementById('refundDetailsInput').value.trim();
            const proofFile = document.getElementById('refundProofInput').files[0] || null;

            if (!refundReason) {
                Swal.showValidationMessage('Please choose a refund reason.');
                return false;
            }

            if (proofRequired && refundReasonNeedsProof(refundReason) && !proofFile) {
                Swal.showValidationMessage('Please attach a proof photo for damaged or broken product refunds.');
                return false;
            }

            return {
                refundReason,
                refundDetails,
                proofFile
            };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('request_refund', 'true');
            formData.append('order_id', orderId);
            formData.append('refund_reason', result.value.refundReason);
            formData.append('refund_details', result.value.refundDetails || '');
            if (result.value.proofFile) {
                formData.append('refund_proof', result.value.proofFile);
            }
            formData.append('ajax', 'true');
            
            fetch('my_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Submitted!', data.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            });
        }
    });
}

function toggleOrderDetails(orderId) {
    const drawer = document.getElementById('orderDetails_' + orderId);
    const icon = document.getElementById('toggleIcon_' + orderId);
    const text = document.getElementById('toggleText_' + orderId);
    
    if (drawer) {
        if (drawer.style.display === 'none' || drawer.style.display === '') {
            drawer.style.display = 'block';
            if (icon) icon.className = 'fas fa-chevron-up';
            if (text) text.textContent = 'Hide Details';
        } else {
            drawer.style.display = 'none';
            if (icon) icon.className = 'fas fa-chevron-down';
            if (text) text.textContent = 'View Details & Actions';
        }
    }
}

// Handle page load notifications & counter animations
document.addEventListener('DOMContentLoaded', function() {
    // Count-up animation for Total Orders
    const counterEl = document.querySelector('.animated-counter');
    if (counterEl) {
        const target = parseInt(counterEl.getAttribute('data-target'), 10) || 0;
        const duration = 900;
        const startTime = performance.now();
        
        function updateCounter(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easeProgress = 1 - Math.pow(1 - progress, 3);
            const currentVal = Math.floor(easeProgress * target);
            counterEl.textContent = currentVal.toLocaleString();
            
            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            } else {
                counterEl.textContent = target.toLocaleString();
            }
        }
        requestAnimationFrame(updateCounter);
    }
    <?php if (isset($_SESSION['success_msg'])): ?>
        Swal.fire({
            title: 'Success!',
            text: '<?php echo addslashes($_SESSION['success_msg']); ?>',
            icon: 'success',
            confirmButtonText: 'OK'
        });
    <?php unset($_SESSION['success_msg']); endif; ?>
    
    <?php if (isset($_SESSION['error_msg'])): ?>
        Swal.fire({
            title: 'Error!',
            text: '<?php echo addslashes($_SESSION['error_msg']); ?>',
            icon: 'error',
            confirmButtonText: 'OK'
        });
    <?php unset($_SESSION['error_msg']); endif; ?>
});
</script>

<?php include 'includes/footer.php'; ?>
