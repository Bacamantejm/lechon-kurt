<?php
session_start();
require_once 'includes/config.php';

header('Content-Type: application/json');

if (!isset($_GET['store_id'])) {
    echo json_encode(['error' => 'No store ID provided']);
    exit;
}

$store_id = intval($_GET['store_id']);

$query = "SELECT store_name, address, phone, opening_hours FROM store_locations WHERE store_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $store_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$store = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($store) {
    echo json_encode([
        'success' => true,
        'store' => $store
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Store not found'
    ]);
}
?>