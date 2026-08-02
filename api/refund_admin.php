<?php
// Endpoint: /api/refund_admin.php
// GET: list/filter
// POST action=approve|reject|complete with refund_id and optional remarks
require_once __DIR__ . '/../includes/cancellation_refund_helpers.php';
require_once __DIR__ . '/../includes/partner_order_policy_helper.php';
require_once __DIR__ . '/../admin/auth.php';

header('Content-Type: application/json');
popEnsurePolicySchema($conn);
$admin_id = require_admin_json();
$is_partner_scoped_admin = function_exists('isApprovedFranchiseSellerAccount')
    && function_exists('getFranchiseSellerScopeOwnerId')
    && isApprovedFranchiseSellerAccount($conn, $admin_id);
$seller_scope_owner_id = $is_partner_scoped_admin
    ? (int)(getFranchiseSellerScopeOwnerId($conn, $admin_id) ?? 0)
    : 0;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    require_refund_permission_json($admin_id, false);

    // Filters: date_from, date_to, status, user_id
    $status = $_GET['status'] ?? null;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;

    $allowed_statuses = ['Refund Pending', 'Refund Approved', 'Refund Rejected', 'Refund Completed'];
    if ($status !== null && $status !== '' && !in_array($status, $allowed_statuses, true)) {
        json_out(['success' => false, 'error' => 'Invalid status filter']);
    }

    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        json_out(['success' => false, 'error' => 'Invalid date_from']);
    }
    if ($date_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        json_out(['success' => false, 'error' => 'Invalid date_to']);
    }

    $where = [];$params = [];
    if ($is_partner_scoped_admin && $seller_scope_owner_id > 0) {
        $tenant_scope_checks = [];
        if (function_exists('getFranchiseScopedOrderExistsSql')) {
            $order_scope_sql = getFranchiseScopedOrderExistsSql($conn, $seller_scope_owner_id, 'c.order_id');
            if ($order_scope_sql !== '1=0') {
                $tenant_scope_checks[] = "(c.order_id IS NOT NULL AND {$order_scope_sql})";
            }
        }
        if (function_exists('getFranchiseScopedPreOrderExistsSql')) {
            $pre_order_scope_sql = getFranchiseScopedPreOrderExistsSql($conn, $seller_scope_owner_id, 'c.reservation_id');
            if ($pre_order_scope_sql !== '1=0') {
                $tenant_scope_checks[] = "(c.reservation_id IS NOT NULL AND {$pre_order_scope_sql})";
            }
        }
        $where[] = !empty($tenant_scope_checks)
            ? '(' . implode(' OR ', $tenant_scope_checks) . ')'
            : '1=0';
    }
    if ($status) { $where[] = 'r.refund_status = ?'; $params[] = $status; }
    if ($user_id) { $where[] = 'c.user_id = ?'; $params[] = $user_id; }
    if ($date_from) { $where[] = 'c.cancellation_date >= ?'; $params[] = $date_from . ' 00:00:00'; }
    if ($date_to) { $where[] = 'c.cancellation_date <= ?'; $params[] = $date_to . ' 23:59:59'; }
    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT r.id as refund_id, r.cancellation_id, r.refund_amount, r.currency, r.refund_status, r.computed_rule, r.percentage, r.processed_by, r.processed_date, r.remarks, r.refund_reason, r.customer_evidence_path,
                   c.id as cancellation_id, c.user_id, c.order_id, c.reservation_id, c.service_request_id, c.reason, c.other_reason_text, c.cancellation_date, c.status as cancellation_status,
                   u.full_name as customer_name, o.order_number
            FROM refunds r
            JOIN cancellations c ON c.id = r.cancellation_id
            LEFT JOIN users u ON u.id = c.user_id
            LEFT JOIN orders o ON o.id = c.order_id
            $wsql
            ORDER BY c.cancellation_date DESC
            LIMIT 500";

    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        json_out(['success' => false, 'error' => 'Failed to prepare refunds query']);
    }
    crh_bind_params($stmt, $params);
    $stmt->execute(); $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
    json_out(['success' => true, 'data' => $rows]);
}

if ($method === 'POST') {
    ensure_csrf();
    require_refund_permission_json($admin_id, true);

    $action = $_POST['action'] ?? '';
    $refund_id = isset($_POST['refund_id']) ? (int)$_POST['refund_id'] : 0;
    $remarks = trim($_POST['remarks'] ?? '');
    $remarks = function_exists('mb_substr') ? mb_substr($remarks, 0, 500) : substr($remarks, 0, 500);
    if (!$refund_id) json_out(['success' => false, 'error' => 'Missing refund_id']);
    if (!in_array($action, ['approve', 'reject', 'complete'], true)) {
        json_out(['success' => false, 'error' => 'Invalid action']);
    }
    if ($action === 'reject' && $remarks === '') {
        json_out(['success' => false, 'error' => 'Remarks are required when rejecting a refund']);
    }

    $refund = db_fetch_one("SELECT r.*, c.user_id, c.order_id, c.reservation_id, c.service_request_id
                            FROM refunds r
                            JOIN cancellations c ON c.id = r.cancellation_id
                            WHERE r.id = ?
                            FOR UPDATE", [$refund_id]);
    if (!$refund) json_out(['success' => false, 'error' => 'Refund not found']);
    if (
        $is_partner_scoped_admin
        && $seller_scope_owner_id > 0
        && function_exists('adminPartnerScopeEntityExists')
        && !adminPartnerScopeEntityExists($conn, $seller_scope_owner_id, 'refund', $refund_id)
    ) {
        http_response_code(403);
        json_out(['success' => false, 'error' => 'Access denied: refund record is outside your tenant scope.']);
    }

    $entity_type = $refund['order_id'] ? 'order' : ($refund['reservation_id'] ? 'pre_order' : 'service_request');
    $entity_id = $refund['order_id'] ?: ($refund['reservation_id'] ?: $refund['service_request_id']);

    try {
        global $conn; $conn->begin_transaction();
        $now = (new DateTime('now'))->format('Y-m-d H:i:s');
        $new_status = '';

        if ($action === 'approve') {
            if ($refund['refund_status'] !== 'Refund Pending') throw new Exception('Only pending refunds can be approved');
            $new_status = 'Refund Approved';
            db_execute("UPDATE refunds SET refund_status = ?, processed_by = ?, processed_date = ?, remarks = ? WHERE id = ?", [$new_status, $admin_id, $now, $remarks, $refund_id]);
            if (!empty($refund['order_id'])) {
                db_execute("UPDATE orders SET payment_status = 'cancelled' WHERE id = ? AND payment_status IN ('paid','partial','pending')", [$refund['order_id']]);
            }
            log_activity($admin_id, 'refund_approve', 'REFUND', $refund_id, ['entity' => [$entity_type, $entity_id], 'remarks' => $remarks]);
        } elseif ($action === 'reject') {
            if (!in_array($refund['refund_status'], ['Refund Pending','Refund Approved'], true)) throw new Exception('Cannot reject in current status');
            $new_status = 'Refund Rejected';
            db_execute("UPDATE refunds SET refund_status = ?, processed_by = ?, processed_date = ?, remarks = ? WHERE id = ?", [$new_status, $admin_id, $now, $remarks, $refund_id]);
            log_activity($admin_id, 'refund_reject', 'REFUND', $refund_id, ['entity' => [$entity_type, $entity_id], 'remarks' => $remarks]);
        } elseif ($action === 'complete') {
            if ($refund['refund_status'] !== 'Refund Approved') throw new Exception('Only approved refunds can be completed');
            $new_status = 'Refund Completed';
            db_execute("UPDATE refunds SET refund_status = ?, processed_by = ?, processed_date = ?, remarks = ? WHERE id = ?", [$new_status, $admin_id, $now, $remarks, $refund_id]);
            if (!empty($refund['order_id'])) {
                db_execute("UPDATE orders SET payment_status = 'cancelled' WHERE id = ? AND payment_status IN ('paid','partial','pending')", [$refund['order_id']]);
            }
            log_activity($admin_id, 'refund_complete', 'REFUND', $refund_id, ['entity' => [$entity_type, $entity_id], 'remarks' => $remarks]);
        }

        if (!empty($refund['user_id']) && function_exists('createNotification')) {
            $title = 'Refund Status Updated';
            $message = 'Your refund request #' . $refund_id . ' has been ' . strtoupper(str_replace('Refund ', '', $new_status)) . '.';
            if ($remarks !== '') {
                $message .= ' Remarks: ' . $remarks;
            }
            createNotification($conn, (int)$refund['user_id'], 'refund_update', $title, $message, $entity_id, $entity_type);
        }

        $conn->commit();
        json_out(['success' => true, 'message' => 'Updated', 'status' => $new_status]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        json_out(['success' => false, 'error' => $e->getMessage()]);
    }
}

http_response_code(405);
json_out(['success' => false, 'error' => 'Method not allowed']);
