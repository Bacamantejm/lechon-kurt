<?php
session_start();
require_once 'includes/config.php';
require_once 'paymongo_integration.php';

function createNotificationOnce($conn, $user_id, $type, $title, $message, $related_id = null, $related_type = null) {
    $check_sql = "SELECT id FROM notifications WHERE user_id = ? AND type = ? AND related_id <=> ? AND related_type <=> ? LIMIT 1";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    if (!$check_stmt) {
        return false;
    }

    mysqli_stmt_bind_param($check_stmt, "isss", $user_id, $type, $related_id, $related_type);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $exists = $check_result && mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($check_stmt);

    if ($exists) {
        return true;
    }

    return createNotification($conn, $user_id, $type, $title, $message, $related_id, $related_type);
}

$preorder_id = isset($_GET['preorder_id']) ? intval($_GET['preorder_id']) : 0;

if (!$preorder_id) {
    header("Location: index.php?error=invalid_order");
    exit;
}

// Get primary preorder details
$query = "SELECT po.*, u.email
          FROM pre_orders po
          JOIN users u ON po.user_id = u.id
          WHERE po.id = ? AND po.user_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $preorder_id, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$primary_preorder = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$primary_preorder) {
    header("Location: index.php?error=order_not_found");
    exit;
}

$session_id = trim((string)($primary_preorder['paymongo_session_id'] ?? ''));

// Load all linked pre-orders (same user + same PayMongo session if present)
$linked_preorders = [];
if ($session_id !== '') {
    $linked_query = "SELECT po.*, u.email
                     FROM pre_orders po
                     JOIN users u ON po.user_id = u.id
                     WHERE po.user_id = ?
                       AND po.paymongo_session_id = ?
                     ORDER BY po.id ASC";
    $linked_stmt = mysqli_prepare($conn, $linked_query);
    mysqli_stmt_bind_param($linked_stmt, "is", $_SESSION['user_id'], $session_id);
    mysqli_stmt_execute($linked_stmt);
    $linked_result = mysqli_stmt_get_result($linked_stmt);
    while ($linked_result && ($linked_row = mysqli_fetch_assoc($linked_result))) {
        $linked_preorders[] = $linked_row;
    }
    mysqli_stmt_close($linked_stmt);
}
if (empty($linked_preorders)) {
    $linked_preorders[] = $primary_preorder;
}

$linked_ids = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $linked_preorders);
$linked_ids = array_values(array_filter($linked_ids));

// Verify payment state
if ($session_id !== '') {
    $paymongo = new PayMongoIntegration(
        'sk_test_YOUR_PAYMONGO_SECRET_KEY_HERE',
        'pk_test_YOUR_PAYMONGO_PUBLIC_KEY_HERE'
    );
    $verification = $paymongo->verifyPayment($session_id);
    if (!($verification['success'] ?? false) || strtolower((string)($verification['status'] ?? '')) !== 'paid') {
        header("Location: my_orders.php?tab=preorders&error=payment_not_verified");
        exit;
    }
}

$payment_type = (string)($primary_preorder['payment_type'] ?? 'full_payment');
$was_downpayment_paid = false;
$was_final_paid = false;
foreach ($linked_preorders as $linked_po) {
    if (strtolower((string)($linked_po['downpayment_status'] ?? '')) === 'paid') {
        $was_downpayment_paid = true;
    }
    if (strtolower((string)($linked_po['final_payment_status'] ?? '')) === 'paid') {
        $was_final_paid = true;
    }
}

// Record payment ledger entries for linked rows (idempotent)
foreach ($linked_preorders as $linked_po) {
    $linked_id = (int)($linked_po['id'] ?? 0);
    if ($linked_id <= 0) {
        continue;
    }

    $ledger_payment_type = ($payment_type === 'downpayment') ? 'downpayment' : 'final_payment';
    $ledger_amount = ($payment_type === 'downpayment')
        ? (float)($linked_po['downpayment_amount'] ?? 0)
        : (float)($linked_po['total_price'] ?? 0);
    if ($ledger_amount <= 0) {
        continue;
    }

    $check_payment_sql = "SELECT id
                          FROM pre_order_payments
                          WHERE pre_order_id = ?
                            AND payment_gateway = 'paymongo'
                            AND transaction_id = ?
                          LIMIT 1";
    $check_payment_stmt = mysqli_prepare($conn, $check_payment_sql);
    if (!$check_payment_stmt) {
        continue;
    }
    mysqli_stmt_bind_param($check_payment_stmt, "is", $linked_id, $session_id);
    mysqli_stmt_execute($check_payment_stmt);
    $check_payment_result = mysqli_stmt_get_result($check_payment_stmt);
    $existing_payment = $check_payment_result ? mysqli_fetch_assoc($check_payment_result) : null;
    mysqli_stmt_close($check_payment_stmt);

    if ($existing_payment) {
        continue;
    }

    $insert_payment_sql = "INSERT INTO pre_order_payments
                           (pre_order_id, payment_type, amount, payment_method, transaction_id, payment_status, payment_gateway, paid_at, created_at)
                           VALUES (?, ?, ?, 'online', ?, 'paid', 'paymongo', NOW(), NOW())";
    $insert_payment_stmt = mysqli_prepare($conn, $insert_payment_sql);
    if ($insert_payment_stmt) {
        mysqli_stmt_bind_param($insert_payment_stmt, "isds", $linked_id, $ledger_payment_type, $ledger_amount, $session_id);
        mysqli_stmt_execute($insert_payment_stmt);
        mysqli_stmt_close($insert_payment_stmt);
    }
}

// Update linked preorder rows to paid state
if (!empty($linked_ids)) {
    $placeholders = implode(',', array_fill(0, count($linked_ids), '?'));
    $types = str_repeat('i', count($linked_ids));
    if ($payment_type === 'downpayment') {
        $update_query = "UPDATE pre_orders
                         SET downpayment_status = 'paid',
                             downpayment_paid_at = NOW(),
                             reservation_status = CASE
                                 WHEN reservation_status = 'pending' THEN 'confirmed'
                                 ELSE reservation_status
                             END
                         WHERE id IN ({$placeholders})";
    } else {
        $update_query = "UPDATE pre_orders
                         SET final_payment_status = 'paid',
                             final_payment_paid_at = NOW(),
                             reservation_status = 'confirmed'
                         WHERE id IN ({$placeholders})";
    }
    $update_stmt = mysqli_prepare($conn, $update_query);
    if ($update_stmt) {
        $bind_values = $linked_ids;
        $bind_refs = [];
        foreach ($bind_values as $k => $v) {
            $bind_refs[$k] = &$bind_values[$k];
        }
        array_unshift($bind_refs, $types);
        call_user_func_array([$update_stmt, 'bind_param'], $bind_refs);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
    }
}

// Deduct inventory if applicable
$should_deduct = false;
if ($payment_type === 'downpayment') {
    // Deduct once when downpayment transitions to paid.
    $should_deduct = !$was_downpayment_paid;
} else {
    // Deduct once when final/full payment transitions to paid.
    $should_deduct = !$was_final_paid;
}

if ($should_deduct) {
    // Deduct inventory from the preferred pickup date
    mysqli_begin_transaction($conn);
    try {
        foreach ($linked_preorders as $linked_po) {
            $int_product_id = (int)($linked_po['product_id'] ?? 0);
            $quantity = (int)($linked_po['quantity'] ?? 0);
            $inventory_date = (string)($linked_po['preferred_pickup_date'] ?? '');
            if ($int_product_id <= 0 || $quantity <= 0 || $inventory_date === '') {
                continue;
            }

            // Atomically create a daily inventory record if it doesn't exist for the preferred date
            $init_daily_inv_sql = "
                INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated)
                SELECT ?, ?, p.stock, 5, NOW()
                FROM products p
                WHERE p.id = ?
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";
            $init_stmt = mysqli_prepare($conn, $init_daily_inv_sql);
            mysqli_stmt_bind_param($init_stmt, "isi", $int_product_id, $inventory_date, $int_product_id);
            mysqli_stmt_execute($init_stmt);
            mysqli_stmt_close($init_stmt);

            // Decrement daily inventory for the preferred date
            $update_daily_sql = "UPDATE inventory SET current_stock = current_stock - ?, last_updated = NOW() WHERE product_id = ? AND inventory_date = ?";
            $daily_stmt = mysqli_prepare($conn, $update_daily_sql);
            mysqli_stmt_bind_param($daily_stmt, "iis", $quantity, $int_product_id, $inventory_date);
            mysqli_stmt_execute($daily_stmt);
            mysqli_stmt_close($daily_stmt);
        }

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Inventory deduction failed for preorder #{$preorder_id}: " . $e->getMessage());
        // Don't stop the user, just log the error
    }
}

// Create notifications once (prevents duplicates on page refresh).
$payment_label = ($payment_type === 'downpayment') ? 'Downpayment' : 'Full payment';
$customer_title = 'Pre-Order Payment Received';
$customer_message = $payment_label . " received for Pre-Order transaction #" . (int)$preorder_id . " (" . count($linked_preorders) . " item(s)). We will keep you updated on the status.";
createNotificationOnce(
    $conn,
    (int)$primary_preorder['user_id'],
    'preorder_payment_received',
    $customer_title,
    $customer_message,
    (string)$preorder_id,
    'pre_order'
);

$admin_ids = function_exists('getAdminUserIds') ? getAdminUserIds($conn) : [];
$admin_title = 'New Paid Pre-Order';
$admin_message = "Pre-Order transaction #{$preorder_id} is now paid ({$payment_label}) with " . count($linked_preorders) . " item(s).";
foreach ($admin_ids as $admin_id) {
    createNotificationOnce(
        $conn,
        (int)$admin_id,
        'preorder_paid',
        $admin_title,
        $admin_message,
        (string)$preorder_id,
        'pre_order'
    );
}

$preorder_subtotal = 0.0;
$preorder_total = 0.0;
foreach ($linked_preorders as $linked_po) {
    $preorder_subtotal += floatval($linked_po['unit_price']) * intval($linked_po['quantity']);
    $preorder_total += floatval($linked_po['total_price']);
}
if ($preorder_total + 0.01 < $preorder_subtotal) {
    $preorder_subtotal = $preorder_total;
}
$preorder_vat = round(max(0, $preorder_total - $preorder_subtotal), 2);
$primary_preorder = $linked_preorders[0];
$linked_product_labels = array_map(static function ($row) {
    return (string)($row['product_name'] ?? '');
}, $linked_preorders);
$linked_product_labels = array_values(array_filter($linked_product_labels, static function ($label) {
    return trim($label) !== '';
}));
$linked_product_summary = !empty($linked_product_labels)
    ? implode(', ', array_slice($linked_product_labels, 0, 3)) . (count($linked_product_labels) > 3 ? ' +' . (count($linked_product_labels) - 3) . ' more' : '')
    : 'Multiple items';

$current_page = 'preorder-success';
$page_title = "Pre-Order Successful | Lechon Delights";
include 'includes/header.php';
?>

<div style="max-width: 900px; margin: 60px auto; padding: 20px;">
    <div style="background: white; border-radius: 15px; padding: 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.1); text-align: center;">
        <div style="font-size: 4rem; color: #4caf50; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i>
        </div>
        
        <h1 style="color: #333; margin-bottom: 10px;">Pre-Order Successful!</h1>
        <p style="color: #666; font-size: 1.1rem; margin-bottom: 30px;">Thank you for your pre-order. Your payment has been received.</p>
        
        <div style="background: #f9f9f9; padding: 30px; border-radius: 12px; margin-bottom: 30px; text-align: left;">
            <h3 style="color: #333; margin-bottom: 20px;">Order Details</h3>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 2px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 600;">Order Number:</td>
                    <td style="padding: 12px; text-align: right; color: #c62828;">#<?php echo str_pad((string)$preorder_id, 6, '0', STR_PAD_LEFT); ?></td>
                </tr>
                <tr style="border-bottom: 2px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 600;">Product:</td>
                    <td style="padding: 12px; text-align: right;"><?php echo htmlspecialchars($linked_product_summary); ?></td>
                </tr>
                <tr style="border-bottom: 2px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 600;">Line Items:</td>
                    <td style="padding: 12px; text-align: right;"><?php echo (int)count($linked_preorders); ?></td>
                </tr>
                <tr style="border-bottom: 2px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 600;">Subtotal:</td>
                    <td style="padding: 12px; text-align: right;">₱<?php echo number_format($preorder_subtotal, 2); ?></td>
                </tr>
                <tr style="border-bottom: 2px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 600;">VAT (12%):</td>
                    <td style="padding: 12px; text-align: right;">₱<?php echo number_format($preorder_vat, 2); ?></td>
                </tr>
                <tr style="border-bottom: 2px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 600;">Total Amount:</td>
                    <td style="padding: 12px; text-align: right; font-size: 1.2rem; color: #c62828; font-weight: bold;">₱<?php echo number_format($preorder_total, 2); ?></td>
                </tr>
                <tr style="border-bottom: 2px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 600;">Payment Type:</td>
                    <td style="padding: 12px; text-align: right;">
                        <?php echo ($primary_preorder['payment_type'] === 'downpayment' || (float)$primary_preorder['downpayment_amount'] > 0) ? '30% Downpayment' : 'Full Payment'; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px; font-weight: 600;">Delivery Address:</td>
                    <td style="padding: 12px; text-align: right;">
                        <?php echo htmlspecialchars((string)$primary_preorder['delivery_address']); ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <p style="color: #666; margin-bottom: 20px;">
            A confirmation email has been sent to <strong><?php echo htmlspecialchars((string)$primary_preorder['email']); ?></strong><br>
            Our team will contact you shortly to confirm the delivery date.
        </p>
        
        <div style="display: flex; gap: 15px; justify-content: center;">
            <a href="my_orders.php" class="btn" style="display: inline-block; padding: 12px 30px; background: #c62828; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.3s ease;">
                View My Orders
            </a>
            <a href="index.php" class="btn" style="display: inline-block; padding: 12px 30px; background: #f5f5f5; color: #333; text-decoration: none; border-radius: 8px; font-weight: 600; border: 2px solid #e0e0e0; transition: all 0.3s ease;">
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
