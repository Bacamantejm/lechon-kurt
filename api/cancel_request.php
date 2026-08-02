<?php
// Endpoint: POST /api/cancel_request.php
// Accepts: { type: 'order'|'reservation'|'service', id: numeric, reason: enum, other_reason_text?: string, csrf_token }
// Returns JSON
require_once __DIR__ . '/../includes/cancellation_refund_helpers.php';
require_once __DIR__ . '/../email_service.php';
require_once __DIR__ . '/../includes/partner_order_policy_helper.php';

header('Content-Type: application/json');
ensure_csrf();
$user_id = require_login_json();

$type = $_POST['type'] ?? '';
$entity_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$reason = $_POST['reason'] ?? '';
$other_text = trim($_POST['other_reason_text'] ?? '');
popEnsurePolicySchema($conn);

if (!in_array($type, ['order','reservation','service'], true)) json_out(['success' => false, 'error' => 'Invalid type']);
$valid_reasons = ['Change of mind','Wrong order','Emergency','Other'];
if (!in_array($reason, $valid_reasons, true)) json_out(['success' => false, 'error' => 'Invalid reason']);
if ($reason === 'Other' && $other_text === '') json_out(['success' => false, 'error' => 'Other reason text required']);
if ($entity_id <= 0) json_out(['success' => false, 'error' => 'Invalid id']);

// Determine table and ownership/status fields
$table = '';$status_field='status';$created_field='created_at';$amount_field='total_amount';$payment_status_field='payment_status';
switch ($type) {
    case 'order':
        $table = 'orders';
        break;
    case 'reservation':
        $table = 'reservations';
        break;
    case 'service':
        $table = 'service_requests';
        break;
}

// Fetch entity with ownership and status
$row = db_fetch_one("SELECT id, user_id, $status_field AS status, $created_field AS created_at, $amount_field AS total_amount, $payment_status_field AS payment_status FROM $table WHERE id = ?", [$entity_id]);
if (!$row) json_out(['success' => false, 'error' => ucfirst($type) . ' not found']);
if ((int)$row['user_id'] !== $user_id) json_out(['success' => false, 'error' => 'You do not own this ' . $type]);

$status = (string)($row['status'] ?? '');
$status_normalized = strtolower(trim($status));
$policy = [];
$downpayment_refundable = true;
$order_downpayment_amount = 0.0;
if ($type === 'order') {
    $policy = popGetOrderPolicy($conn, $entity_id);
    $policy_check = popEvaluateOrderCancellation($policy, $status);
    if (empty($policy_check['allowed'])) {
        json_out(['success' => false, 'error' => $policy_check['message']]);
    }
    $downpayment_refundable = !empty($policy['downpayment_refundable']);
    $order_downpayment_amount = (float)($row['downpayment_amount'] ?? 0);
    if ($order_downpayment_amount <= 0) {
        $order_payment_meta = db_fetch_one("SELECT COALESCE(downpayment_amount, 0) AS downpayment_amount FROM orders WHERE id = ? LIMIT 1", [$entity_id]);
        $order_downpayment_amount = (float)($order_payment_meta['downpayment_amount'] ?? 0);
    }
} else {
    if (!in_array($status_normalized, ['pending', 'confirmed'], true)) {
        json_out(['success' => false, 'error' => 'Cancellation not allowed in current status']);
    }
}

// Disallow if completed/shipped/used (extra safety)
if (in_array($status_normalized, ['completed', 'shipped', 'used', 'cancelled'], true)) {
    json_out(['success' => false, 'error' => 'Already completed/used']);
}

// Begin cancellation: insert into cancellations, set entity status Cancelled
try {
    $conn->begin_transaction();

    // Insert cancellation log
    $sqlC = "INSERT INTO cancellations (user_id, order_id, reservation_id, service_request_id, reason, other_reason_text, status) VALUES (?,?,?,?,?,?,?)";
    $ids = [null, null, null];
    $order_id = $reservation_id = $service_id = null;
    if ($type === 'order') $order_id = $entity_id;
    if ($type === 'reservation') $reservation_id = $entity_id;
    if ($type === 'service') $service_id = $entity_id;

    list($cxl_id,) = db_execute($sqlC, [
        $user_id,
        $order_id,
        $reservation_id,
        $service_id,
        $reason,
        $other_text ?: null,
        'Cancelled'
    ]);

    // Update entity status -> Cancelled
    db_execute("UPDATE $table SET $status_field = 'Cancelled' WHERE id = ?", [$entity_id]);

    // Compute refund eligibility
    $total = (float)($row['total_amount'] ?? 0);
    $rule = compute_refund_rule($row['created_at'], $total);
    $refund_amount = 0.0;
    $refund_id = 0;
    $refund_note = '';
    $payment_status_normalized = strtolower(trim((string)($row['payment_status'] ?? '')));
    $eligible_for_refund = $total > 0 && in_array($payment_status_normalized, ['paid', 'partial', 'partially_paid'], true);
    $effective_rule = [
        'rule' => (string)($rule['rule'] ?? 'NONE'),
        'percentage' => (float)($rule['percentage'] ?? 0.0),
        'amount' => (float)($rule['amount'] ?? 0.0)
    ];
    if (!$eligible_for_refund) {
        $effective_rule = ['rule' => 'NONE', 'percentage' => 0.0, 'amount' => 0.0];
    }

    if ($type === 'order' && $eligible_for_refund && !$downpayment_refundable && $order_downpayment_amount > 0) {
        if (in_array($payment_status_normalized, ['partial', 'partially_paid'], true)) {
            $effective_rule = ['rule' => 'NONE', 'percentage' => 0.0, 'amount' => 0.0];
            $refund_note = 'Downpayment is non-refundable when a customer cancels this order.';
        } else {
            $adjusted_amount = max(0, (float)$effective_rule['amount'] - $order_downpayment_amount);
            if ($adjusted_amount <= 0) {
                $effective_rule = ['rule' => 'NONE', 'percentage' => 0.0, 'amount' => 0.0];
            } else {
                $effective_rule['amount'] = $adjusted_amount;
            }
            $refund_note = 'Refund excludes the non-refundable downpayment amount.';
        }
    }

    if ($eligible_for_refund && (float)$effective_rule['amount'] > 0) {
        $refund_amount = (float)$effective_rule['amount'];
        list($refund_id,) = db_execute(
            "INSERT INTO refunds (cancellation_id, refund_amount, currency, refund_status, computed_rule, percentage) VALUES (?,?,?,?,?,?)",
            [$cxl_id, $refund_amount, 'PHP', 'Refund Pending', $effective_rule['rule'], $effective_rule['percentage']]
        );

        // Update payment status to Refund Pending
        db_execute("UPDATE $table SET $payment_status_field = 'Refund Pending' WHERE id = ?", [$entity_id]);
    } elseif ($eligible_for_refund) {
        db_execute("UPDATE $table SET $payment_status_field = 'cancelled' WHERE id = ?", [$entity_id]);
    }

    $conn->commit();

    // Log + notify
    log_activity($user_id, 'cancel_request', strtoupper($type), $entity_id, [
        'cancellation_id' => $cxl_id,
        'reason' => $reason,
        'other' => $other_text,
        'refund_id' => $refund_id,
        'refund_amount' => $refund_amount,
        'rule' => $effective_rule,
        'downpayment_refundable' => $downpayment_refundable ? 1 : 0,
        'refund_note' => $refund_note
    ]);

    // Optional: send email notification (adjust email_service.php API accordingly)
    if (function_exists('send_email_notification')) {
        $email_message = "Your $type #$entity_id has been cancelled.";
        if ($refund_id > 0) {
            $email_message .= " Refund status: Refund Pending.";
        } elseif ($refund_note !== '') {
            $email_message .= " " . $refund_note;
        }
        @send_email_notification($user_id, 'Cancellation Submitted', $email_message);
    }

    json_out([
        'success' => true,
        'message' => $refund_id > 0
            ? 'Cancellation submitted. Refund review is now pending.'
            : ($refund_note !== '' ? 'Cancellation submitted. ' . $refund_note : 'Cancellation submitted.'),
        'cancellation_id' => $cxl_id,
        'refund_id' => $refund_id,
        'refund_amount' => $refund_amount,
        'rule' => $effective_rule,
        'downpayment_refundable' => $downpayment_refundable ? 1 : 0,
        'refund_note' => $refund_note
    ]);
} catch (Exception $e) {
    if ($conn && $conn->errno === 0) { $conn->rollback(); }
    http_response_code(500);
    json_out(['success' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}
