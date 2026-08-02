<?php
session_start();
require_once 'includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to cancel an order.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$order_id = intval($_POST['order_id'] ?? 0);
$reason = trim($_POST['reason'] ?? 'Change of mind');
$other_reason = trim($_POST['other_reason'] ?? '');

if ($order_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

mysqli_begin_transaction($conn);

try {
    // 1. Verify order ownership and status
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Order not found or you do not have permission to cancel it.");
    }
    $order = $result->fetch_assoc();
    $stmt->close();

    // 2. Check if order is eligible for cancellation request
    if (!in_array($order['status'], ['pending', 'confirmed'])) {
        throw new Exception("This order cannot be cancelled as it is already being prepared or has been delivered.");
    }

    // 3. Insert into cancellations table
    $cancellation_status = 'Requested';
    $stmt = $conn->prepare("INSERT INTO cancellations (user_id, order_id, reason, other_reason_text, status, cancellation_date) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iisss", $user_id, $order_id, $reason, $other_reason, $cancellation_status);
    $stmt->execute();
    $cancellation_id = $stmt->insert_id;
    if ($cancellation_id === 0) {
        throw new Exception("Failed to log cancellation request.");
    }
    $stmt->close();

    // 4. Update order status
    $new_order_status = 'cancellation_requested';
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_order_status, $order_id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new Exception("Failed to update order status.");
    }
    $stmt->close();

    mysqli_commit($conn);
    
    echo json_encode(['success' => true, 'message' => 'Cancellation requested. Please wait for admin approval.']);

} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

mysqli_close($conn);
?>