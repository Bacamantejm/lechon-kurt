<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_review'])) {
    header('Location: my_orders.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$order_id = intval($_POST['order_id']);

// Verify order ownership
$order_check_stmt = mysqli_prepare($conn, "SELECT id FROM orders WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($order_check_stmt, "ii", $order_id, $user_id);
mysqli_stmt_execute($order_check_stmt);
if (mysqli_stmt_get_result($order_check_stmt)->num_rows === 0) {
    $_SESSION['error_msg'] = "Unauthorized action.";
    header('Location: my_orders.php');
    exit;
}
mysqli_stmt_close($order_check_stmt);

mysqli_begin_transaction($conn);

try {
    // Process product reviews
    if (isset($_POST['reviews']) && is_array($_POST['reviews'])) {
        $review_stmt = mysqli_prepare($conn, "INSERT INTO product_reviews (order_id, product_id, user_id, rating, comment, is_approved, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
        $update_item_stmt = mysqli_prepare($conn, "UPDATE order_items SET is_reviewed = 1 WHERE id = ?");

        // Bind parameters once (by reference)
        $p_id = 0; $r_val = 0; $c_val = ''; $oi_id = 0;
        mysqli_stmt_bind_param($review_stmt, "iiiis", $order_id, $p_id, $user_id, $r_val, $c_val);
        mysqli_stmt_bind_param($update_item_stmt, "i", $oi_id);

        $products_to_update = [];

        foreach ($_POST['reviews'] as $review_data) {
            $p_id = intval($review_data['product_id']);
            $oi_id = intval($review_data['order_item_id']);
            $r_val = intval($review_data['rating']);
            $c_val = trim($review_data['comment']);

            if ($r_val >= 1 && $r_val <= 5) {
                mysqli_stmt_execute($review_stmt);
                mysqli_stmt_execute($update_item_stmt);
                $products_to_update[] = $p_id;
            }
        }
        mysqli_stmt_close($review_stmt);
        mysqli_stmt_close($update_item_stmt);

        // Update product ratings after loop
        foreach (array_unique($products_to_update) as $pid) {
            updateProductRating($conn, $pid);
        }
    }

    // Process delivery review
    if (isset($_POST['delivery_rating'])) {
        $delivery_rating = intval($_POST['delivery_rating']);
        $delivery_comment = trim($_POST['delivery_comment']);

        if ($delivery_rating >= 1 && $delivery_rating <= 5) {
            $delivery_stmt = mysqli_prepare($conn, "INSERT INTO delivery_reviews (order_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
            mysqli_stmt_bind_param($delivery_stmt, "iiis", $order_id, $user_id, $delivery_rating, $delivery_comment);
            mysqli_stmt_execute($delivery_stmt);
            mysqli_stmt_close($delivery_stmt);
        }
    }

    mysqli_commit($conn);
    $_SESSION['success_msg'] = "Thank you for your review!";

} catch (Exception $e) {
    mysqli_rollback($conn);
    $_SESSION['error_msg'] = "An error occurred: " . $e->getMessage();
}

header('Location: my_orders.php');
exit;


function updateProductRating($conn, $product_id) {
    $sql = "SELECT AVG(rating) as avg_rating, COUNT(id) as review_count FROM product_reviews WHERE product_id = ? AND is_approved = 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $product_id);
    mysqli_stmt_execute($stmt);
    $stats = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    $avg_rating = $stats['avg_rating'] ?? 0;
    $review_count = $stats['review_count'] ?? 0;

    $update_sql = "UPDATE products SET avg_rating = ?, review_count = ? WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "dii", $avg_rating, $review_count, $product_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
}
?>