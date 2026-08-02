<?php
require_once 'session_check.php'; // Use the improved session check

// Ensure the logged-in user is an employee with a valid employee ID
if ($employee_id === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You are not a registered employee.']);
    exit;
}
require_once '../includes/config.php';
require_once '../logistics_service.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

try {
    $tracking_id = intval($_POST['tracking_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    $proof_path = null;

    if ($tracking_id === 0 || empty($status)) {
        throw new Exception("Missing required parameters.");
    }

    // Handle file upload for proof of delivery
    if ($status === 'delivered' && isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/proof_of_delivery/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_ext = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
        $safe_filename = "pod_{$tracking_id}_" . time() . "." . $file_ext;
        $target_file = $upload_dir . $safe_filename;

        if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $target_file)) {
            $proof_path = 'uploads/proof_of_delivery/' . $safe_filename;
        } else {
            throw new Exception("Failed to upload proof of delivery image.");
        }
    }

    $logisticsService = new LogisticsService($conn);
    $result = $logisticsService->updateTrackingStatus(
        $tracking_id,
        $status,
        $notes,
        $latitude,
        $longitude,
        $proof_path
    );

    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Status updated successfully.']);
    } else {
        throw new Exception($result['message'] ?? 'An unknown error occurred.');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

mysqli_close($conn);