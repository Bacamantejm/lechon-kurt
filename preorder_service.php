<?php
/**
 * PreOrderService Class
 * Handles all pre-order/advance reservation operations
 */
require_once __DIR__ . '/includes/partner_order_policy_helper.php';

class PreOrderService {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }

    private function getLinkedPreOrderIds($pre_order_id, $user_id = 0) {
        $pre_order_id = (int)$pre_order_id;
        if ($pre_order_id <= 0) {
            return [];
        }

        $base_query = "SELECT id, user_id, paymongo_session_id FROM pre_orders WHERE id = ? LIMIT 1";
        $base_stmt = mysqli_prepare($this->conn, $base_query);
        if (!$base_stmt) {
            return [$pre_order_id];
        }
        mysqli_stmt_bind_param($base_stmt, "i", $pre_order_id);
        mysqli_stmt_execute($base_stmt);
        $base_result = mysqli_stmt_get_result($base_stmt);
        $base_row = $base_result ? mysqli_fetch_assoc($base_result) : null;
        mysqli_stmt_close($base_stmt);

        if (!$base_row) {
            return [];
        }

        $resolved_user_id = (int)($base_row['user_id'] ?? 0);
        if ((int)$user_id > 0 && $resolved_user_id !== (int)$user_id) {
            return [];
        }

        $session_id = trim((string)($base_row['paymongo_session_id'] ?? ''));
        if ($session_id === '') {
            return [$pre_order_id];
        }

        $ids = [];
        $link_query = "SELECT id FROM pre_orders WHERE user_id = ? AND paymongo_session_id = ? ORDER BY id ASC";
        $link_stmt = mysqli_prepare($this->conn, $link_query);
        if ($link_stmt) {
            mysqli_stmt_bind_param($link_stmt, "is", $resolved_user_id, $session_id);
            mysqli_stmt_execute($link_stmt);
            $link_result = mysqli_stmt_get_result($link_stmt);
            while ($link_result && ($link_row = mysqli_fetch_assoc($link_result))) {
                $id = (int)($link_row['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            mysqli_stmt_close($link_stmt);
        }

        if (empty($ids)) {
            $ids[$pre_order_id] = $pre_order_id;
        }

        return array_values($ids);
    }
    
    /**
     * Create a new pre-order
     */
    public function createPreOrder($user_id, $product_id, $product_name, $quantity, $unit_price, 
                                   $pickup_date, $pickup_time, $pickup_location, $delivery_method, 
                                   $payment_type, $downpayment_percentage = 30, $special_instructions = '', $delivery_address = '',
                                   $latitude = 0, $longitude = 0) {
        try {
            $vat_rate = 0.12;
            $subtotal = $quantity * $unit_price;
            $vat_amount = round($subtotal * $vat_rate, 2);
            $total_price = $subtotal + $vat_amount;
            $downpayment_amount = ($payment_type === 'downpayment') ? ($total_price * $downpayment_percentage / 100) : $total_price;
            $remaining_amount = $total_price - $downpayment_amount;
            
            $insert_query = "INSERT INTO pre_orders 
                           (user_id, product_id, product_name, quantity, unit_price, total_price, 
                            reservation_date, preferred_pickup_date, preferred_pickup_time, pickup_location, 
                            delivery_address, delivery_method, payment_type, downpayment_amount, remaining_amount, 
                            special_instructions, latitude, longitude, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = mysqli_prepare($this->conn, $insert_query);
            if (!$stmt) {
                return ['success' => false, 'message' => 'Database error: ' . mysqli_error($this->conn)];
            }
            
            mysqli_stmt_bind_param($stmt, "iisiddssssssddd", $user_id, $product_id, $product_name, $quantity, 
                                  $unit_price, $total_price, $pickup_date, $pickup_time, $pickup_location, 
                                  $delivery_address, $delivery_method, $payment_type, $downpayment_amount, $remaining_amount, $special_instructions,
                                  $latitude, $longitude);
            
            if (mysqli_stmt_execute($stmt)) {
                $pre_order_id = mysqli_insert_id($this->conn);
                mysqli_stmt_close($stmt);
                
                return [
                    'success' => true, 
                    'pre_order_id' => $pre_order_id,
                    'total_price' => $total_price,
                    'downpayment_amount' => $downpayment_amount,
                    'remaining_amount' => $remaining_amount
                ];
            } else {
                mysqli_stmt_close($stmt);
                return ['success' => false, 'message' => 'Failed to create pre-order: ' . mysqli_error($this->conn)];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get pre-order by ID
     */
    public function getPreOrder($pre_order_id) {
        $query = "SELECT * FROM pre_orders WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $pre_order_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        
        return mysqli_fetch_assoc($result);
    }
    
    /**
     * Get all pre-orders for a user
     */
    public function getUserPreOrders($user_id) {
        $query = "SELECT * FROM pre_orders WHERE user_id = ? ORDER BY created_at DESC";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        
        $pre_orders = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $pre_orders[] = $row;
        }
        return $pre_orders;
    }
    
    /**
     * Update pre-order status
     */
    public function updatePreOrderStatus($pre_order_id, $new_status, $admin_notes = '') {
        $allowed_statuses = ['pending', 'confirmed', 'in_preparation', 'ready_for_pickup', 'completed', 'cancelled'];
        if (!in_array($new_status, $allowed_statuses, true)) {
            return ['success' => false, 'message' => 'Invalid pre-order status selected.'];
        }

        // First, get the pre-order details including user_id and product_name/current status
        $detail_query = "SELECT id, user_id, product_name, reservation_status, paymongo_session_id FROM pre_orders WHERE id = ?";
        $detail_stmt = mysqli_prepare($this->conn, $detail_query);
        if (!$detail_stmt) {
            return ['success' => false, 'message' => 'Database error while validating pre-order.'];
        }
        mysqli_stmt_bind_param($detail_stmt, "i", $pre_order_id);
        mysqli_stmt_execute($detail_stmt);
        $detail_result = mysqli_stmt_get_result($detail_stmt);
        $preorder = mysqli_fetch_assoc($detail_result);
        mysqli_stmt_close($detail_stmt);

        if (!$preorder) {
            return ['success' => false, 'message' => 'Pre-order record not found.'];
        }

        $current_status = (string)($preorder['reservation_status'] ?? '');
        if ($current_status === $new_status) {
            return ['success' => true, 'message' => 'No status changes were needed.'];
        }

        $allowed_transitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['in_preparation', 'cancelled'],
            'in_preparation' => ['ready_for_pickup', 'cancelled'],
            'ready_for_pickup' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => []
        ];

        $next_statuses = $allowed_transitions[$current_status] ?? [];
        if (!in_array($new_status, $next_statuses, true)) {
            return [
                'success' => false,
                'message' => 'Invalid status transition from ' . str_replace('_', ' ', $current_status) . ' to ' . str_replace('_', ' ', $new_status) . '.'
            ];
        }
        
        // Update the status
        $linked_ids = $this->getLinkedPreOrderIds($pre_order_id, (int)$preorder['user_id']);
        if (empty($linked_ids)) {
            return ['success' => false, 'message' => 'Pre-order record not found.'];
        }

        $placeholders = implode(',', array_fill(0, count($linked_ids), '?'));
        $bind_types = "ss" . str_repeat('i', count($linked_ids));
        $query = "UPDATE pre_orders
                  SET reservation_status = ?, admin_notes = ?, updated_at = NOW()
                  WHERE id IN ({$placeholders})
                    AND reservation_status = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error while updating pre-order status.'];
        }

        $bind_values = array_merge([$new_status, $admin_notes], $linked_ids, [$current_status]);
        $bind_types .= "s";
        $bind_refs = [];
        foreach ($bind_values as $k => $v) {
            $bind_refs[$k] = &$bind_values[$k];
        }
        array_unshift($bind_refs, $bind_types);
        call_user_func_array([$stmt, 'bind_param'], $bind_refs);

        if (mysqli_stmt_execute($stmt)) {
            $affected = (int)mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            // Create notification for customer if we have their info.
            if ($preorder && isset($preorder['user_id'])) {
                $this->createPreOrderNotification($preorder['user_id'], $pre_order_id, $new_status, $preorder['product_name']);
            }

            $msg = $affected > 1
                ? "Pre-order status updated across {$affected} linked item(s)."
                : 'Pre-order status updated successfully';
            return ['success' => true, 'message' => $msg];
        }

        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to update pre-order status'];
    }
    
    /**
     * Create notification for pre-order status changes
     */
    private function createPreOrderNotification($user_id, $pre_order_id, $status, $product_name) {
        $status_messages = [
            'confirmed' => 'Your pre-order for ' . htmlspecialchars($product_name) . ' has been confirmed!',
            'in_preparation' => 'Your pre-order for ' . htmlspecialchars($product_name) . ' is now being prepared.',
            'ready_for_pickup' => 'Your pre-order for ' . htmlspecialchars($product_name) . ' is ready for pickup!',
            'completed' => 'Your pre-order for ' . htmlspecialchars($product_name) . ' has been completed. Thank you!',
            'cancelled' => 'Your pre-order for ' . htmlspecialchars($product_name) . ' has been cancelled.'
        ];
        
        $status_titles = [
            'confirmed' => 'Pre-Order Confirmed',
            'in_preparation' => 'Pre-Order Being Prepared',
            'ready_for_pickup' => 'Pre-Order Ready for Pickup',
            'completed' => 'Pre-Order Completed',
            'cancelled' => 'Pre-Order Cancelled'
        ];
        
        // Include config file for createNotification function if not already loaded
        if (!function_exists('createNotification')) {
            require_once 'includes/config.php';
        }
        
        $notif_type = 'preorder_' . $status;
        $notif_title = $status_titles[$status] ?? 'Pre-Order Status Updated';
        $notif_message = $status_messages[$status] ?? 'Your pre-order status has been updated.';
        
        createNotification($this->conn, $user_id, $notif_type, $notif_title, $notif_message, $pre_order_id, 'pre_order');
    }
    
    /**
     * Process downpayment
     */
    public function processDownPayment($pre_order_id, $transaction_id, $payment_gateway = 'paymongo') {
        $pre_order = $this->getPreOrder($pre_order_id);
        
        if (!$pre_order) {
            return ['success' => false, 'message' => 'Pre-order not found'];
        }
        
        // Record payment
        $payment_query = "INSERT INTO pre_order_payments 
                         (pre_order_id, payment_type, amount, transaction_id, payment_status, payment_gateway, paid_at) 
                         VALUES (?, 'downpayment', ?, ?, 'paid', ?, NOW())";
        $stmt = mysqli_prepare($this->conn, $payment_query);
        mysqli_stmt_bind_param($stmt, "idss", $pre_order_id, $pre_order['downpayment_amount'], $transaction_id, $payment_gateway);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update pre-order downpayment status
            $update_query = "UPDATE pre_orders SET downpayment_status = 'paid', downpayment_paid_at = NOW() WHERE id = ?";
            $update_stmt = mysqli_prepare($this->conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $pre_order_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
            
            mysqli_stmt_close($stmt);
            return [
                'success' => true, 
                'message' => 'Downpayment processed successfully',
                'remaining_amount' => $pre_order['remaining_amount']
            ];
        } else {
            mysqli_stmt_close($stmt);
            return ['success' => false, 'message' => 'Failed to process payment'];
        }
    }
    
    /**
     * Process final payment
     */
    public function processFinalPayment($pre_order_id, $transaction_id, $payment_gateway = 'paymongo') {
        $pre_order = $this->getPreOrder($pre_order_id);
        
        if (!$pre_order) {
            return ['success' => false, 'message' => 'Pre-order not found'];
        }
        
        // Record payment
        $payment_query = "INSERT INTO pre_order_payments 
                         (pre_order_id, payment_type, amount, transaction_id, payment_status, payment_gateway, paid_at) 
                         VALUES (?, 'final_payment', ?, ?, 'paid', ?, NOW())";
        $stmt = mysqli_prepare($this->conn, $payment_query);
        mysqli_stmt_bind_param($stmt, "idss", $pre_order_id, $pre_order['remaining_amount'], $transaction_id, $payment_gateway);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update pre-order final payment status
            $update_query = "UPDATE pre_orders SET final_payment_status = 'paid', final_payment_paid_at = NOW() WHERE id = ?";
            $update_stmt = mysqli_prepare($this->conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $pre_order_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
            
            mysqli_stmt_close($stmt);
            return ['success' => true, 'message' => 'Final payment processed successfully'];
        } else {
            mysqli_stmt_close($stmt);
            return ['success' => false, 'message' => 'Failed to process payment'];
        }
    }
    
    /**
     * Cancel pre-order
     */
    public function cancelPreOrder($pre_order_id, $cancellation_reason = '') {
        $po = $this->getPreOrder($pre_order_id);
        if (!$po) {
            return ['success' => false, 'message' => 'Pre-order not found'];
        }
        popEnsurePolicySchema($this->conn);
        $policy = popGetPreOrderPolicy($this->conn, (int)$pre_order_id);
        $downpayment_refundable = !empty($policy['downpayment_refundable']);

        $linked_ids = $this->getLinkedPreOrderIds($pre_order_id, (int)$po['user_id']);
        if (empty($linked_ids)) {
            return ['success' => false, 'message' => 'Pre-order not found'];
        }

        $placeholders = implode(',', array_fill(0, count($linked_ids), '?'));
        $types = "s" . str_repeat('i', count($linked_ids));
        $query = "UPDATE pre_orders
                  SET reservation_status = 'cancelled',
                      cancellation_reason = ?,
                      cancelled_at = NOW(),
                      updated_at = NOW()
                  WHERE id IN ({$placeholders})
                    AND reservation_status NOT IN ('cancelled', 'completed')";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare cancellation update'];
        }

        $bind_values = array_merge([$cancellation_reason], $linked_ids);
        $bind_refs = [];
        foreach ($bind_values as $k => $v) {
            $bind_refs[$k] = &$bind_values[$k];
        }
        array_unshift($bind_refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $bind_refs);

        if (mysqli_stmt_execute($stmt)) {
            $affected = (int)mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            $refund_amount = 0.0;
            $fetch_query = "SELECT downpayment_status, final_payment_status, downpayment_amount, remaining_amount
                            FROM pre_orders
                            WHERE id IN ({$placeholders})";
            $fetch_stmt = mysqli_prepare($this->conn, $fetch_query);
            if ($fetch_stmt) {
                $fetch_values = $linked_ids;
                $fetch_refs = [];
                foreach ($fetch_values as $k => $v) {
                    $fetch_refs[$k] = &$fetch_values[$k];
                }
                array_unshift($fetch_refs, str_repeat('i', count($linked_ids)));
                call_user_func_array([$fetch_stmt, 'bind_param'], $fetch_refs);
                mysqli_stmt_execute($fetch_stmt);
                $fetch_result = mysqli_stmt_get_result($fetch_stmt);
                while ($fetch_result && ($row = mysqli_fetch_assoc($fetch_result))) {
                    if ($downpayment_refundable && strtolower((string)($row['downpayment_status'] ?? '')) === 'paid') {
                        $refund_amount += (float)($row['downpayment_amount'] ?? 0);
                    }
                    if (strtolower((string)($row['final_payment_status'] ?? '')) === 'paid') {
                        $refund_amount += (float)($row['remaining_amount'] ?? 0);
                    }
                }
                mysqli_stmt_close($fetch_stmt);
            }

            $cxl_query = "INSERT INTO cancellations (user_id, reservation_id, reason, other_reason_text, status)
                          VALUES (?, ?, 'Other', ?, 'Cancelled')";
            $cxl_stmt = mysqli_prepare($this->conn, $cxl_query);
            if ($cxl_stmt) {
                mysqli_stmt_bind_param($cxl_stmt, "iis", $po['user_id'], $pre_order_id, $cancellation_reason);
                mysqli_stmt_execute($cxl_stmt);
                $cancellation_id = mysqli_insert_id($this->conn);
                mysqli_stmt_close($cxl_stmt);

                if ($refund_amount > 0) {
                    $ref_query = "INSERT INTO refunds (cancellation_id, refund_amount, refund_status) VALUES (?, ?, 'Refund Pending')";
                    $ref_stmt = mysqli_prepare($this->conn, $ref_query);
                    if ($ref_stmt) {
                        mysqli_stmt_bind_param($ref_stmt, "id", $cancellation_id, $refund_amount);
                        mysqli_stmt_execute($ref_stmt);
                        mysqli_stmt_close($ref_stmt);
                    }
                }

                if (!function_exists('getAdminUserIds')) {
                    require_once 'includes/config.php';
                }
                $admin_ids = getAdminUserIds($this->conn);
                $notif_title = "Pre-Order Cancelled by User";
                $notif_message = "User #" . $po['user_id'] . " cancelled Pre-Order transaction #{$pre_order_id} (" . count($linked_ids) . " item(s)).";
                if ($refund_amount > 0) {
                    $notif_message .= " A refund of PHP " . number_format($refund_amount, 2) . " is pending.";
                } elseif (!$downpayment_refundable && strtolower((string)($po['payment_type'] ?? '')) === 'downpayment') {
                    $notif_message .= " Downpayment is non-refundable under the current store policy.";
                }
                foreach ($admin_ids as $admin_id) {
                    createNotification($this->conn, $admin_id, 'preorder_cancelled', $notif_title, $notif_message, $pre_order_id, 'pre_order');
                }
            }

            $message = $affected > 1
                ? "Pre-order transaction cancelled across {$affected} item(s)."
                : 'Pre-order cancelled successfully';
            if ($refund_amount <= 0 && !$downpayment_refundable && strtolower((string)($po['payment_type'] ?? '')) === 'downpayment') {
                $message .= ' Downpayment is non-refundable under this store\'s terms.';
            }
            return ['success' => true, 'message' => $message, 'refund_amount' => $refund_amount];
        }

        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to cancel pre-order'];
    }

    /**
     * Create notification record
     */
    public function createNotification($pre_order_id, $user_id, $notification_type, $title, $message) {
        $query = "INSERT INTO pre_order_notifications 
                  (pre_order_id, user_id, notification_type, title, message, created_at) 
                  VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "iisss", $pre_order_id, $user_id, $notification_type, $title, $message);
        
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        
        return $result;
    }
    
    /**
     * Mark notification as email sent
     */
    public function markEmailSent($notification_id) {
        $query = "UPDATE pre_order_notifications SET email_sent = TRUE, sent_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $notification_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    
    /**
     * Get pre-orders for admin dashboard
     */
    public function getAdminPreOrders($status = '', $limit = 50, $offset = 0) {
        $where = '';
        if (!empty($status)) {
            $where = " WHERE reservation_status = '" . mysqli_real_escape_string($this->conn, $status) . "'";
        }
        
        $query = "SELECT po.*, u.full_name, u.email, u.phone 
                  FROM pre_orders po 
                  JOIN users u ON po.user_id = u.id 
                  $where 
                  ORDER BY po.created_at DESC 
                  LIMIT $limit OFFSET $offset";
        
        $result = mysqli_query($this->conn, $query);
        
        $pre_orders = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $pre_orders[] = $row;
        }
        return $pre_orders;
    }
    
    /**
     * Record cash payment for pre-order
     */
    public function recordCashPayment($pre_order_id, $payment_type, $amount, $payment_method) {
        try {
            // Insert payment record
            $payment_query = "INSERT INTO pre_order_payments 
                            (pre_order_id, payment_type, amount, payment_method, transaction_id, 
                             payment_status, payment_gateway, paid_at, created_at) 
                            VALUES (?, ?, ?, ?, ?, 'paid', 'cash', NOW(), NOW())";
            
            $stmt = mysqli_prepare($this->conn, $payment_query);
            $transaction_id = 'CASH-' . $pre_order_id . '-' . time();
            
            mysqli_stmt_bind_param($stmt, "isdss", $pre_order_id, $payment_type, $amount, $payment_method, $transaction_id);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                
                // Update pre-order status based on payment type
                if ($payment_type === 'downpayment') {
                    $update_query = "UPDATE pre_orders SET downpayment_status = 'paid', downpayment_paid_at = NOW() WHERE id = ?";
                } else {
                    $update_query = "UPDATE pre_orders SET final_payment_status = 'paid', final_payment_paid_at = NOW(), reservation_status = 'completed' WHERE id = ?";
                }
                
                $stmt = mysqli_prepare($this->conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $pre_order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                
                return ['success' => true, 'transaction_id' => $transaction_id];
            } else {
                return ['success' => false, 'message' => 'Failed to record payment'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
?>
