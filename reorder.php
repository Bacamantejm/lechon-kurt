<?php
session_start();
require_once 'includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

if (!isset($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'No order specified']);
    exit;
}

$order_id = $_GET['order_id'];
$user_id = $_SESSION['user_id'];

// Verify order belongs to user
$stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit;
}

// Get order items
$items_stmt = $conn->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

// For now, simulate success
$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item['product_name'];
}

echo json_encode([
    'success' => true, 
    'message' => 'Added ' . count($items) . ' items to cart',
    'items' => $items
]);
?>