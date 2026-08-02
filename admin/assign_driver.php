<?php
session_start();
require_once '../includes/config.php';
require_once 'auth.php';
checkAdminAccess();
require_once '../includes/security.php';
require_once '../logistics_service.php';
require_once 'hr_module_common.php';
requirePermission('logistics.assign');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh and try again.']);
    exit;
}

$tracking_id = intval($_POST['tracking_id'] ?? 0);
$employee_id = intval($_POST['employee_id'] ?? 0);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$driver_role_sql = hrLogisticsEmployeeSqlCondition('e', 'd', $conn);

if ($tracking_id === 0 || $employee_id === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid tracking ID or employee selected.']);
    exit;
}
if ($seller_scope_id !== null && !hrEmployeeIdInScope($conn, $employee_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You can only assign drivers from your own store workforce.']);
    exit;
}

// Resolve order/delivery date from tracking record for attendance validation.
$tracking_query = mysqli_prepare(
    $conn,
    "SELECT lt.id, lt.order_id, lt.current_status, o.delivery_date
     FROM logistics_tracking lt
     INNER JOIN orders o ON o.id = lt.order_id
     WHERE lt.id = ?
     LIMIT 1"
);
if (!$tracking_query) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to validate tracking record.']);
    exit;
}
mysqli_stmt_bind_param($tracking_query, "i", $tracking_id);
mysqli_stmt_execute($tracking_query);
$tracking_result = mysqli_stmt_get_result($tracking_query);
$tracking = mysqli_fetch_assoc($tracking_result);
mysqli_stmt_close($tracking_query);

if (!$tracking) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Tracking record not found.']);
    exit;
}
if ($seller_scope_id !== null) {
    $scope_sql = "SELECT lt.id
                  FROM logistics_tracking lt
                  WHERE lt.id = ?
                    AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt.order_id') . "
                  LIMIT 1";
    $scope_stmt = mysqli_prepare($conn, $scope_sql);
    mysqli_stmt_bind_param($scope_stmt, "i", $tracking_id);
    mysqli_stmt_execute($scope_stmt);
    $scope_result = mysqli_stmt_get_result($scope_stmt);
    $is_allowed = $scope_result && mysqli_fetch_assoc($scope_result);
    mysqli_stmt_close($scope_stmt);

    if (!$is_allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only assign drivers for your own store orders.']);
        exit;
    }
}

$assignable_statuses = ['pending', 'assigned'];
if (!in_array($tracking['current_status'], $assignable_statuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Driver assignment is only allowed for pending/assigned deliveries.']);
    exit;
}

$delivery_date = date('Y-m-d');
if (!empty($tracking['delivery_date'])) {
    $candidate_date = substr((string)$tracking['delivery_date'], 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate_date)) {
        $delivery_date = $candidate_date;
    }
}

// Get employee details (active delivery employee only).
$emp_query = mysqli_prepare(
    $conn,
    "SELECT e.first_name, e.last_name, e.phone, e.vehicle_details
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE e.id = ? AND e.status = 'active' AND {$driver_role_sql}
     LIMIT 1"
);
mysqli_stmt_bind_param($emp_query, "i", $employee_id);
mysqli_stmt_execute($emp_query);
$emp_result = mysqli_stmt_get_result($emp_query);
$employee = mysqli_fetch_assoc($emp_result);
mysqli_stmt_close($emp_query);

if (!$employee) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Selected active employee was not found.']);
    exit;
}

// Attendance check: rider must have attendance on the delivery day.
$attendance_query = mysqli_prepare(
    $conn,
    "SELECT id
     FROM attendance
     WHERE employee_id = ?
       AND attendance_date = ?
       AND status IN ('present', 'late', 'half_day')
       AND (hr_status IS NULL OR hr_status <> 'rejected')
     LIMIT 1"
);
if (!$attendance_query) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to validate attendance.']);
    exit;
}
mysqli_stmt_bind_param($attendance_query, "is", $employee_id, $delivery_date);
mysqli_stmt_execute($attendance_query);
$attendance_result = mysqli_stmt_get_result($attendance_query);
$attendance_ok = $attendance_result && mysqli_fetch_assoc($attendance_result);
mysqli_stmt_close($attendance_query);

if (!$attendance_ok) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "Selected rider has no valid attendance on {$delivery_date}."
    ]);
    exit;
}

$logisticsService = new LogisticsService($conn);
$result = $logisticsService->assignDriver($tracking_id, $employee_id, $employee['first_name'] . ' ' . $employee['last_name'], $employee['phone'], $employee['vehicle_details'] ?? '');

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'driver_name' => $employee['first_name'] . ' ' . $employee['last_name']
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $result['message']
    ]);
}
exit;
