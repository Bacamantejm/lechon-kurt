<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/preorder_schedule_helper.php';

$action = $_GET['action'] ?? '';
$seller_id = (int)($_GET['seller_id'] ?? 0);

if ($action === 'get_calendar') {
    $month = trim((string)($_GET['month'] ?? ''));
    $calendar_data = posGetCalendarAvailability($conn, $seller_id, $month);
    echo json_encode([
        'success' => true,
        'data' => $calendar_data
    ]);
    exit;
}

if ($action === 'get_slots') {
    $date = trim((string)($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format']);
        exit;
    }

    $slots = posGetTimeSlotsForDate($conn, $seller_id, $date);
    echo json_encode([
        'success' => true,
        'date' => $date,
        'slots' => $slots
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
