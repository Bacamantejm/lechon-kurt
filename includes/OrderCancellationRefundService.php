<?php

require_once __DIR__ . '/../paymongo_integration.php';

class OrderCancellationRefundService
{
    public const CANCELLATION_REFUND_RATE = 0.50;

    private static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $safeTable = mysqli_real_escape_string($conn, $table);
        $safeCol = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
        $exists = ($res instanceof mysqli_result && mysqli_num_rows($res) > 0);
        if ($res instanceof mysqli_result) {
            mysqli_free_result($res);
        }
        return $cache[$key] = $exists;
    }

    public static function calculateFiftyPercentRefund(float $orderTotal): float
    {
        if ($orderTotal <= 0) {
            return 0.00;
        }
        return round($orderTotal * self::CANCELLATION_REFUND_RATE, 2);
    }

    /**
     * Fetch latest cancellation, refund, and payment details for an order.
     */
    public static function getOrderRefundSummary(mysqli $conn, int $orderId): array
    {
        $summary = [
            'order_total' => 0.00,
            'refund_percentage' => 50.00,
            'expected_refund_amount' => 0.00,
            'cancellation' => null,
            'refund' => null,
            'payment' => null,
            'is_completed' => false,
            'is_pending_checkout' => false,
            'paymongo_reference' => '',
            'cancellation_reason' => ''
        ];

        $ordStmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? LIMIT 1");
        if (!$ordStmt) {
            return $summary;
        }
        mysqli_stmt_bind_param($ordStmt, "i", $orderId);
        mysqli_stmt_execute($ordStmt);
        $ordRes = mysqli_stmt_get_result($ordStmt);
        $order = $ordRes ? mysqli_fetch_assoc($ordRes) : null;
        mysqli_stmt_close($ordStmt);

        if (!$order) {
            return $summary;
        }

        $total = (float)($order['total_amount'] ?? 0);
        $summary['order_total'] = $total;
        $summary['expected_refund_amount'] = self::calculateFiftyPercentRefund($total);
        $summary['cancellation_reason'] = trim((string)($order['cancellation_reason'] ?? ''));

        $cxlStmt = mysqli_prepare($conn, "SELECT * FROM cancellations WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        if ($cxlStmt) {
            mysqli_stmt_bind_param($cxlStmt, "i", $orderId);
            mysqli_stmt_execute($cxlStmt);
            $cxlRes = mysqli_stmt_get_result($cxlStmt);
            $cxl = $cxlRes ? mysqli_fetch_assoc($cxlRes) : null;
            mysqli_stmt_close($cxlStmt);

            if ($cxl) {
                $summary['cancellation'] = $cxl;
                if ($summary['cancellation_reason'] === '') {
                    $otherText = trim((string)($cxl['other_reason_text'] ?? ''));
                    $enumReason = trim((string)($cxl['reason'] ?? ''));
                    $summary['cancellation_reason'] = $otherText !== '' ? $otherText : $enumReason;
                }

                $refStmt = mysqli_prepare($conn, "SELECT * FROM refunds WHERE cancellation_id = ? ORDER BY id DESC LIMIT 1");
                if ($refStmt) {
                    $cxlId = (int)$cxl['id'];
                    mysqli_stmt_bind_param($refStmt, "i", $cxlId);
                    mysqli_stmt_execute($refStmt);
                    $refRes = mysqli_stmt_get_result($refStmt);
                    $ref = $refRes ? mysqli_fetch_assoc($refRes) : null;
                    mysqli_stmt_close($refStmt);

                    if ($ref) {
                        $summary['refund'] = $ref;
                        $refStatus = (string)($ref['refund_status'] ?? '');
                        $summary['is_completed'] = ($refStatus === 'Refund Completed');
                        $summary['is_pending_checkout'] = ($refStatus === 'Refund Approved' && strtolower((string)($ref['payout_channel'] ?? '')) === 'paymongo' && !empty($ref['payout_reference']));
                        $summary['paymongo_reference'] = trim((string)($ref['payout_reference'] ?? ''));
                    }
                }
            }
        }

        $pmtStmt = mysqli_prepare($conn, "SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        if ($pmtStmt) {
            mysqli_stmt_bind_param($pmtStmt, "i", $orderId);
            mysqli_stmt_execute($pmtStmt);
            $pmtRes = mysqli_stmt_get_result($pmtStmt);
            $summary['payment'] = $pmtRes ? mysqli_fetch_assoc($pmtRes) : null;
            mysqli_stmt_close($pmtStmt);
        }

        return $summary;
    }

    private static function ensureCancellationRecord(mysqli $conn, array $order, string $reasonText = ''): int
    {
        $orderId = (int)$order['id'];
        $userId = (int)($order['user_id'] ?? 0);

        $existingStmt = mysqli_prepare($conn, "SELECT id, status FROM cancellations WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        if ($existingStmt) {
            mysqli_stmt_bind_param($existingStmt, "i", $orderId);
            mysqli_stmt_execute($existingStmt);
            $res = mysqli_stmt_get_result($existingStmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($existingStmt);

            if ($row) {
                $cxlId = (int)$row['id'];
                if (($row['status'] ?? '') !== 'Cancelled') {
                    $updStmt = mysqli_prepare($conn, "UPDATE cancellations SET status = 'Cancelled', cancellation_date = NOW(), updated_at = NOW() WHERE id = ?");
                    if ($updStmt) {
                        mysqli_stmt_bind_param($updStmt, "i", $cxlId);
                        mysqli_stmt_execute($updStmt);
                        mysqli_stmt_close($updStmt);
                    }
                }
                return $cxlId;
            }
        }

        $reasonLower = strtolower($reasonText);
        $enumReason = 'Other';
        if (strpos($reasonLower, 'change of mind') !== false) {
            $enumReason = 'Change of mind';
        } elseif (strpos($reasonLower, 'wrong') !== false || strpos($reasonLower, 'mistake') !== false) {
            $enumReason = 'Wrong order';
        } elseif (strpos($reasonLower, 'emergency') !== false) {
            $enumReason = 'Emergency';
        }

        $otherText = trim($reasonText) !== '' ? $reasonText : 'Cancelled order - 50% PayMongo refund policy';
        $insStmt = mysqli_prepare($conn, "INSERT INTO cancellations (user_id, order_id, reason, other_reason_text, status, cancellation_date) VALUES (?, ?, ?, ?, 'Cancelled', NOW())");
        if (!$insStmt) {
            return 0;
        }
        mysqli_stmt_bind_param($insStmt, "iiss", $userId, $orderId, $enumReason, $otherText);
        mysqli_stmt_execute($insStmt);
        $cxlId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($insStmt);
        return $cxlId;
    }

    private static function upsertRefundRecord(
        mysqli $conn,
        int $cancellationId,
        float $refundAmount,
        string $status,
        ?int $processedBy,
        string $remarks,
        string $payoutChannel = 'paymongo',
        ?string $payoutReference = null,
        bool $markSentNow = false
    ): int {
        $existingId = 0;
        $chkStmt = mysqli_prepare($conn, "SELECT id FROM refunds WHERE cancellation_id = ? ORDER BY id DESC LIMIT 1");
        if ($chkStmt) {
            mysqli_stmt_bind_param($chkStmt, "i", $cancellationId);
            mysqli_stmt_execute($chkStmt);
            $chkRes = mysqli_stmt_get_result($chkStmt);
            $chkRow = $chkRes ? mysqli_fetch_assoc($chkRes) : null;
            mysqli_stmt_close($chkStmt);
            if ($chkRow) {
                $existingId = (int)$chkRow['id'];
            }
        }

        $hasRule = self::columnExists($conn, 'refunds', 'computed_rule');
        $hasPct = self::columnExists($conn, 'refunds', 'percentage');
        $hasChannel = self::columnExists($conn, 'refunds', 'payout_channel');
        $hasRef = self::columnExists($conn, 'refunds', 'payout_reference');
        $hasSentAt = self::columnExists($conn, 'refunds', 'payout_sent_at');
        $hasProcessedBy = self::columnExists($conn, 'refunds', 'processed_by');
        $hasProcessedDate = self::columnExists($conn, 'refunds', 'processed_date');
        $hasRemarks = self::columnExists($conn, 'refunds', 'remarks');

        $pct = 50.00;
        $rule = 'PARTIAL';
        $now = date('Y-m-d H:i:s');
        $remarksTrimmed = substr($remarks, 0, 500);

        if ($existingId > 0) {
            $sets = ['refund_amount = ?', 'refund_status = ?'];
            $types = 'ds';
            $params = [$refundAmount, $status];

            if ($hasRule) {
                $sets[] = 'computed_rule = ?';
                $types .= 's';
                $params[] = $rule;
            }
            if ($hasPct) {
                $sets[] = 'percentage = ?';
                $types .= 'd';
                $params[] = $pct;
            }
            if ($hasRemarks) {
                $sets[] = 'remarks = ?';
                $types .= 's';
                $params[] = $remarksTrimmed;
            }
            if ($hasChannel) {
                $sets[] = 'payout_channel = ?';
                $types .= 's';
                $params[] = $payoutChannel;
            }
            if ($hasRef && $payoutReference !== null) {
                $sets[] = 'payout_reference = ?';
                $types .= 's';
                $params[] = $payoutReference;
            }
            if ($hasProcessedBy && $processedBy !== null && $processedBy > 0) {
                $sets[] = 'processed_by = ?';
                $types .= 'i';
                $params[] = $processedBy;
            }
            if ($hasProcessedDate && ($markSentNow || $status === 'Refund Completed')) {
                $sets[] = 'processed_date = ?';
                $types .= 's';
                $params[] = $now;
            }
            if ($hasSentAt && $markSentNow) {
                $sets[] = 'payout_sent_at = ?';
                $types .= 's';
                $params[] = $now;
            }

            $types .= 'i';
            $params[] = $existingId;

            $sql = "UPDATE refunds SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            return $existingId;
        }

        $cols = ['cancellation_id', 'refund_amount', 'refund_status'];
        $placeholders = ['?', '?', '?'];
        $types = 'ids';
        $params = [$cancellationId, $refundAmount, $status];

        if ($hasRule) {
            $cols[] = 'computed_rule';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $rule;
        }
        if ($hasPct) {
            $cols[] = 'percentage';
            $placeholders[] = '?';
            $types .= 'd';
            $params[] = $pct;
        }
        if ($hasRemarks) {
            $cols[] = 'remarks';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $remarksTrimmed;
        }
        if ($hasChannel) {
            $cols[] = 'payout_channel';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $payoutChannel;
        }
        if ($hasRef && $payoutReference !== null) {
            $cols[] = 'payout_reference';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $payoutReference;
        }
        if ($hasProcessedBy && $processedBy !== null && $processedBy > 0) {
            $cols[] = 'processed_by';
            $placeholders[] = '?';
            $types .= 'i';
            $params[] = $processedBy;
        }
        if ($hasProcessedDate && ($markSentNow || $status === 'Refund Completed')) {
            $cols[] = 'processed_date';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $now;
        }
        if ($hasSentAt && $markSentNow) {
            $cols[] = 'payout_sent_at';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $now;
        }

        $sql = "INSERT INTO refunds (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $newId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        return $newId;
    }

    /**
     * Record and process a 50% PayMongo refund for a cancelled order.
     *
     * Flow:
     * 1. Computes 50% of orders.total_amount.
     * 2. If the order has a PayMongo payment_id / checkout_session_id, calls POST /v1/refunds directly.
     * 3. If direct /v1/refunds succeeds -> marks Refund Completed immediately with PayMongo refund ID (ref_...).
     * 4. If interactive ($allowCheckoutSessionFallback = true) and no capturable pay_... exists (e.g. COD or sandbox),
     *    creates a real PayMongo Checkout Session for the 50% return amount and returns checkout_url so the admin
     *    completes the disbursement via PayMongo.
     */
    public static function processFiftyPercentPayMongoRefund(
        mysqli $conn,
        int $orderId,
        ?int $adminUserId = null,
        string $reasonText = '',
        bool $allowCheckoutSessionFallback = false,
        string $preferredPayMongoMethod = 'all'
    ): array {
        $ordStmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? LIMIT 1");
        if (!$ordStmt) {
            return ['success' => false, 'message' => 'Failed to load order details.'];
        }
        mysqli_stmt_bind_param($ordStmt, "i", $orderId);
        mysqli_stmt_execute($ordStmt);
        $ordRes = mysqli_stmt_get_result($ordStmt);
        $order = $ordRes ? mysqli_fetch_assoc($ordRes) : null;
        mysqli_stmt_close($ordStmt);

        if (!$order) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        $orderTotal = (float)($order['total_amount'] ?? 0);
        $refundAmount = self::calculateFiftyPercentRefund($orderTotal);
        if ($refundAmount <= 0) {
            return ['success' => false, 'message' => 'Order total must be greater than zero to compute a 50% refund.'];
        }

        $orderNumber = (string)($order['order_number'] ?? ('#' . $orderId));
        $cancellationId = self::ensureCancellationRecord($conn, $order, $reasonText);
        if ($cancellationId <= 0) {
            return ['success' => false, 'message' => 'Failed to create cancellation record.'];
        }

        // Idempotency: prevent duplicate refunds if already completed
        $existingStmt = mysqli_prepare($conn, "SELECT id, refund_status, refund_amount, payout_reference FROM refunds WHERE cancellation_id = ? ORDER BY id DESC LIMIT 1");
        if ($existingStmt) {
            mysqli_stmt_bind_param($existingStmt, "i", $cancellationId);
            mysqli_stmt_execute($existingStmt);
            $existingRes = mysqli_stmt_get_result($existingStmt);
            $existingRef = $existingRes ? mysqli_fetch_assoc($existingRes) : null;
            mysqli_stmt_close($existingStmt);

            if ($existingRef && ($existingRef['refund_status'] ?? '') === 'Refund Completed') {
                return [
                    'success' => true,
                    'already_completed' => true,
                    'mode' => 'already_completed',
                    'refund_id' => (int)$existingRef['id'],
                    'refund_amount' => (float)$existingRef['refund_amount'],
                    'payout_reference' => (string)($existingRef['payout_reference'] ?? ''),
                    'message' => '50% PayMongo refund has already been completed for this order.'
                ];
            }
        }

        // Look up original PayMongo payment reference if present
        $paymongoTargetId = '';
        $pmtStmt = mysqli_prepare($conn, "SELECT transaction_id, checkout_session_id, payment_method, status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        if ($pmtStmt) {
            mysqli_stmt_bind_param($pmtStmt, "i", $orderId);
            mysqli_stmt_execute($pmtStmt);
            $pmtRes = mysqli_stmt_get_result($pmtStmt);
            $pmt = $pmtRes ? mysqli_fetch_assoc($pmtRes) : null;
            mysqli_stmt_close($pmtStmt);

            if ($pmt) {
                $txId = trim((string)($pmt['transaction_id'] ?? ''));
                $csId = trim((string)($pmt['checkout_session_id'] ?? ''));
                if (strpos($txId, 'pay_') === 0 || strpos($txId, 'cs_') === 0) {
                    $paymongoTargetId = $txId;
                } elseif (strpos($csId, 'cs_') === 0 || strpos($csId, 'pay_') === 0) {
                    $paymongoTargetId = $csId;
                }
            }
        }

        $paymongo = new PayMongoIntegration(
            function_exists('appConfigValue') ? appConfigValue('PAYMONGO_SECRET_KEY') : null,
            function_exists('appConfigValue') ? appConfigValue('PAYMONGO_PUBLIC_KEY') : null
        );

        $apiErrorNote = '';
        if ($paymongoTargetId !== '' && $paymongo->isConfigured()) {
            $apiNotes = "50% cancellation refund for Order {$orderNumber} (Total PHP " . number_format($orderTotal, 2) . ")";
            $pmRefund = $paymongo->createRefund($paymongoTargetId, $refundAmount, 'requested_by_customer', $apiNotes);
            if (!empty($pmRefund['success']) && !empty($pmRefund['refund_id'])) {
                $pmRefId = (string)$pmRefund['refund_id'];
                $remarks = "50% cancellation refund (PHP " . number_format($refundAmount, 2) . ") automatically returned via PayMongo Refund API. Ref: {$pmRefId}";
                $refundRowId = self::upsertRefundRecord(
                    $conn,
                    $cancellationId,
                    $refundAmount,
                    'Refund Completed',
                    $adminUserId,
                    $remarks,
                    'paymongo',
                    $pmRefId,
                    true
                );

                // Mark order payment_status cancelled if it was paid/partial
                $updOrd = mysqli_prepare($conn, "UPDATE orders SET payment_status = 'cancelled', updated_at = NOW() WHERE id = ? AND payment_status IN ('paid','partial','pending')");
                if ($updOrd) {
                    mysqli_stmt_bind_param($updOrd, "i", $orderId);
                    mysqli_stmt_execute($updOrd);
                    mysqli_stmt_close($updOrd);
                }

                self::notifyCustomerRefund($conn, $order, $refundAmount, $pmRefId, true);

                return [
                    'success' => true,
                    'mode' => 'paymongo_api',
                    'refund_id' => $refundRowId,
                    'refund_amount' => $refundAmount,
                    'payout_reference' => $pmRefId,
                    'message' => '50% refund (PHP ' . number_format($refundAmount, 2) . ') successfully returned to customer via PayMongo (Ref: ' . $pmRefId . ').'
                ];
            }
            $apiErrorNote = trim((string)($pmRefund['error'] ?? ''));
        }

        // If invoked interactively from Order Management Details modal, create a PayMongo Checkout Session
        // for the exact 50% refund return amount (covers COD orders or uncaptured test sessions).
        if ($allowCheckoutSessionFallback && $paymongo->isConfigured()) {
            $pendingRemarks = "50% cancellation refund (PHP " . number_format($refundAmount, 2) . ") initiated via PayMongo.";
            if ($apiErrorNote !== '') {
                $pendingRemarks .= " Direct refund note: {$apiErrorNote}";
            }
            $refundRowId = self::upsertRefundRecord(
                $conn,
                $cancellationId,
                $refundAmount,
                'Refund Approved',
                $adminUserId,
                $pendingRemarks,
                'paymongo',
                null,
                false
            );

            $successUrl = function_exists('appBaseUrl')
                ? appBaseUrl("admin/order_refund_paymongo_success.php?order_id={$orderId}&refund_id={$refundRowId}")
                : ("admin/order_refund_paymongo_success.php?order_id={$orderId}&refund_id={$refundRowId}");
            $cancelUrl = function_exists('appBaseUrl')
                ? appBaseUrl("admin/orders.php?refund_cancelled={$orderId}")
                : ("admin/orders.php?refund_cancelled={$orderId}");

            // Resolve customer details from orders + users table for PayMongo billing validation
            $custName = trim((string)($order['customer_name'] ?? ''));
            $custEmail = trim((string)($order['customer_email'] ?? ''));
            $custPhone = trim((string)($order['customer_phone'] ?? ''));
            $orderUserId = (int)($order['user_id'] ?? 0);
            if ($orderUserId > 0) {
                $uStmt = mysqli_prepare($conn, "SELECT full_name, email, phone FROM users WHERE id = ? LIMIT 1");
                if ($uStmt) {
                    mysqli_stmt_bind_param($uStmt, "i", $orderUserId);
                    mysqli_stmt_execute($uStmt);
                    $uRes = mysqli_stmt_get_result($uStmt);
                    $uRow = $uRes ? mysqli_fetch_assoc($uRes) : null;
                    mysqli_stmt_close($uStmt);
                    if ($uRow) {
                        if ($custName === '' && !empty($uRow['full_name'])) {
                            $custName = trim((string)$uRow['full_name']);
                        }
                        if ($custEmail === '' && !empty($uRow['email'])) {
                            $custEmail = trim((string)$uRow['email']);
                        }
                        if (($custPhone === '' || strtoupper($custPhone) === 'N/A') && !empty($uRow['phone'])) {
                            $custPhone = trim((string)$uRow['phone']);
                        }
                    }
                }
            }

            if ($custName === '') {
                $custName = 'Customer';
            }
            if (!filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
                $custEmail = 'customer' . ($orderUserId > 0 ? $orderUserId : $orderId) . '@lechondelights.com';
            }
            $cleanPhone = preg_replace('/[^0-9+]/', '', $custPhone);
            if (strlen($cleanPhone) < 7) {
                $cleanPhone = '09170000000';
            }

            $safeMethod = in_array(strtolower(trim($preferredPayMongoMethod)), ['gcash', 'paymaya', 'all'], true)
                ? strtolower(trim($preferredPayMongoMethod))
                : 'all';

            $checkoutResult = $paymongo->createCheckoutSession([
                'amount' => $refundAmount,
                'description' => "50% Order Cancellation Refund - {$orderNumber} ({$custName})",
                'order_id' => "REF-{$orderId}-{$refundRowId}",
                'customer_name' => $custName,
                'customer_email' => $custEmail,
                'customer_phone' => $cleanPhone,
                'payment_method' => $safeMethod,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl
            ]);

            if (!empty($checkoutResult['success']) && !empty($checkoutResult['checkout_url'])) {
                $sessionId = (string)($checkoutResult['session_id'] ?? '');
                self::upsertRefundRecord(
                    $conn,
                    $cancellationId,
                    $refundAmount,
                    'Refund Approved',
                    $adminUserId,
                    "50% cancellation refund checkout session created via PayMongo ({$sessionId}).",
                    'paymongo',
                    $sessionId,
                    false
                );

                return [
                    'success' => true,
                    'mode' => 'paymongo_checkout',
                    'refund_id' => $refundRowId,
                    'refund_amount' => $refundAmount,
                    'payout_reference' => $sessionId,
                    'checkout_url' => (string)$checkoutResult['checkout_url'],
                    'message' => 'Redirecting to PayMongo to complete the 50% refund (PHP ' . number_format($refundAmount, 2) . ').'
                ];
            }

            return [
                'success' => false,
                'message' => 'PayMongo could not initiate the 50% refund session: ' . (string)($checkoutResult['error'] ?? $apiErrorNote ?: 'Unknown PayMongo error')
            ];
        }

        // Non-interactive cancellation (e.g., status change or customer cancel when not directly capturable via /v1/refunds):
        // Record 50% Refund Pending via PayMongo so shop owner can complete it from Order Details.
        $pendingRemarks = "50% cancellation refund (PHP " . number_format($refundAmount, 2) . ") queued for PayMongo processing.";
        if ($apiErrorNote !== '') {
            $pendingRemarks .= " (" . $apiErrorNote . ")";
        }
        $refundRowId = self::upsertRefundRecord(
            $conn,
            $cancellationId,
            $refundAmount,
            'Refund Pending',
            $adminUserId,
            $pendingRemarks,
            'paymongo',
            null,
            false
        );

        return [
            'success' => true,
            'mode' => 'pending_manual_paymongo',
            'refund_id' => $refundRowId,
            'refund_amount' => $refundAmount,
            'payout_reference' => '',
            'message' => 'Order cancelled. 50% refund (PHP ' . number_format($refundAmount, 2) . ') queued for PayMongo disbursement in Order Details.'
        ];
    }

    /**
     * Finalize a PayMongo Checkout Session 50% refund upon return from PayMongo.
     */
    public static function completePayMongoCheckoutRefund(
        mysqli $conn,
        int $orderId,
        int $refundId,
        int $adminUserId
    ): array {
        $stmt = mysqli_prepare($conn, "SELECT r.*, c.order_id FROM refunds r INNER JOIN cancellations c ON c.id = r.cancellation_id WHERE r.id = ? AND c.order_id = ? LIMIT 1");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to load refund record.'];
        }
        mysqli_stmt_bind_param($stmt, "ii", $refundId, $orderId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $refund = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$refund) {
            return ['success' => false, 'message' => 'Refund record not found for this order.'];
        }

        $ordStmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($ordStmt, "i", $orderId);
        mysqli_stmt_execute($ordStmt);
        $ordRes = mysqli_stmt_get_result($ordStmt);
        $order = $ordRes ? mysqli_fetch_assoc($ordRes) : null;
        mysqli_stmt_close($ordStmt);

        if (!$order) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        if (($refund['refund_status'] ?? '') === 'Refund Completed') {
            return [
                'success' => true,
                'already_completed' => true,
                'refund_amount' => (float)$refund['refund_amount'],
                'payout_reference' => (string)($refund['payout_reference'] ?? ''),
                'message' => '50% PayMongo refund is already completed.'
            ];
        }

        $sessionId = trim((string)($refund['payout_reference'] ?? ''));
        $refundAmount = (float)($refund['refund_amount'] ?? self::calculateFiftyPercentRefund((float)$order['total_amount']));

        if ($sessionId !== '') {
            $paymongo = new PayMongoIntegration(
                function_exists('appConfigValue') ? appConfigValue('PAYMONGO_SECRET_KEY') : null,
                function_exists('appConfigValue') ? appConfigValue('PAYMONGO_PUBLIC_KEY') : null
            );
            $sessionCheck = $paymongo->retrieveCheckoutSession($sessionId);
            if (empty($sessionCheck['success']) || strtolower((string)($sessionCheck['status'] ?? '')) !== 'paid') {
                $fallback = $paymongo->verifyPayment($sessionId);
                if (empty($fallback['success']) || strtolower((string)($fallback['status'] ?? '')) !== 'paid') {
                    return ['success' => false, 'message' => 'PayMongo refund payment has not been confirmed yet.'];
                }
            }
        }

        $remarks = "50% cancellation refund (PHP " . number_format($refundAmount, 2) . ") completed via PayMongo. Ref: {$sessionId}";
        self::upsertRefundRecord(
            $conn,
            (int)$refund['cancellation_id'],
            $refundAmount,
            'Refund Completed',
            $adminUserId,
            $remarks,
            'paymongo',
            $sessionId !== '' ? $sessionId : ('PM-REF-' . $orderId),
            true
        );

        $updOrd = mysqli_prepare($conn, "UPDATE orders SET payment_status = 'cancelled', updated_at = NOW() WHERE id = ? AND payment_status IN ('paid','partial','pending')");
        if ($updOrd) {
            mysqli_stmt_bind_param($updOrd, "i", $orderId);
            mysqli_stmt_execute($updOrd);
            mysqli_stmt_close($updOrd);
        }

        self::notifyCustomerRefund($conn, $order, $refundAmount, $sessionId, true);

        return [
            'success' => true,
            'refund_amount' => $refundAmount,
            'payout_reference' => $sessionId,
            'message' => '50% refund (PHP ' . number_format($refundAmount, 2) . ') completed via PayMongo for Order #' . ($order['order_number'] ?? $orderId) . '.'
        ];
    }

    private static function notifyCustomerRefund(mysqli $conn, array $order, float $refundAmount, string $reference, bool $completed): void
    {
        $customerUserId = (int)($order['user_id'] ?? 0);
        if ($customerUserId <= 0 || !function_exists('createNotification')) {
            return;
        }
        $orderNumber = (string)($order['order_number'] ?? ('#' . $order['id']));
        $title = $completed ? '50% Refund Sent via PayMongo' : '50% PayMongo Refund Queued';
        $message = $completed
            ? 'Your 50% cancellation refund of PHP ' . number_format($refundAmount, 2) . ' for Order #' . $orderNumber . ' has been processed via PayMongo.' . ($reference !== '' ? ' Reference: ' . $reference : '')
            : 'A 50% cancellation refund of PHP ' . number_format($refundAmount, 2) . ' for Order #' . $orderNumber . ' has been queued via PayMongo.';
        createNotification($conn, $customerUserId, 'refund_update', $title, $message, (int)$order['id'], 'order');
    }
}
