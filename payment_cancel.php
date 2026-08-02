<?php
session_start();
require_once 'includes/config.php';

$order_id = $_GET['order_id'] ?? '';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!empty($order_id)) {
    try {
        $order_id = (int)$order_id;
        $order_lookup = mysqli_prepare($conn, "SELECT id, order_number, status FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($order_lookup, "ii", $order_id, $user_id);
        mysqli_stmt_execute($order_lookup);
        $order_result = mysqli_stmt_get_result($order_lookup);
        $order = $order_result ? mysqli_fetch_assoc($order_result) : null;
        mysqli_stmt_close($order_lookup);

        if ($order) {
            // Update payment status to cancelled
            $update_query = "UPDATE payments SET status = 'cancelled' WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "i", $order_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Update order status
            $update_order = "UPDATE orders SET status = 'cancelled', payment_status = 'cancelled' WHERE id = ? AND user_id = ?";
            $order_stmt = mysqli_prepare($conn, $update_order);
            mysqli_stmt_bind_param($order_stmt, "ii", $order_id, $user_id);
            mysqli_stmt_execute($order_stmt);
            mysqli_stmt_close($order_stmt);

            if (function_exists('createNotification')) {
                createNotification($conn, $user_id, 'order_payment_cancelled', 'Payment Cancelled', 'Payment was cancelled for order #' . ($order['order_number'] ?? $order_id) . '.', $order_id, 'order');
                if (function_exists('getAdminUserIds')) {
                    $admin_ids = getAdminUserIds($conn);
                    foreach ($admin_ids as $admin_id) {
                        createNotification($conn, (int)$admin_id, 'order_payment_cancelled', 'Customer Payment Cancelled', 'Customer cancelled payment for order #' . ($order['order_number'] ?? $order_id) . '.', $order_id, 'order');
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Payment cancel error: " . $e->getMessage());
    }
}

// Show cancellation message
echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
echo '<script>
    Swal.fire({
        title: "Payment Cancelled",
        text: "Payment was cancelled. You can try again or use a different payment method.",
        icon: "warning",
        confirmButtonText: "Return to Checkout"
    }).then(() => {
        window.location.href = "checkout.php";
    });
</script>';
exit();
?>
