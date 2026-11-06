<?php
/**
 * AJAX Controller: Employee & Rider Time Clock (Time In / Time Out)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/attendance_service.php';

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to your account.']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$lat = (isset($_POST['latitude']) && is_numeric($_POST['latitude'])) ? (float)$_POST['latitude'] : null;
$lon = (isset($_POST['longitude']) && is_numeric($_POST['longitude'])) ? (float)$_POST['longitude'] : null;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    if ($action === 'get_status') {
        $status_data = AttendanceService::getTodayAttendance($conn, $user_id);
        echo json_encode(array_merge(['success' => true], $status_data));
        exit();
    }

    if ($action === 'clock_in') {
        $res = AttendanceService::clockIn($conn, $user_id, $lat, $lon, $ip_address);
        echo json_encode($res);
        exit();
    }

    if ($action === 'clock_out') {
        $res = AttendanceService::clockOut($conn, $user_id, $lat, $lon, $ip_address);
        echo json_encode($res);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action requested.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
