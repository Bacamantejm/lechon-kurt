<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$rec_id = $_POST['recommendation_id'] ?? 0;
$status = $_POST['status'] ?? '';

if (empty($rec_id) || !in_array($status, ['implemented', 'rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

$stmt = $conn->prepare("UPDATE decisions_recommendations SET status = ? WHERE recommendation_id = ?");
$stmt->bind_param("si", $status, $rec_id);

if ($stmt->execute()) {
    // Optionally, log this action in an audit trail using SecurityService
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status.']);
}

$stmt->close();
$conn->close();
?>