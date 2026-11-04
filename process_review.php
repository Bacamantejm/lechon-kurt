<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/partner_dashboard_helper.php';

pdhEnsureProductReviewReplySchema($conn);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_review'])) {
    header('Location: my_orders.php');
    exit;
}

if (isset($_POST['csrf_token']) && !validateCSRFToken($_POST['csrf_token'])) {
    $_SESSION['error_msg'] = "Invalid session token. Please try again.";
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
        $review_stmt = mysqli_prepare($conn, "
            INSERT INTO product_reviews (order_id, product_id, user_id, rating, comment, image, is_approved, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), image = COALESCE(VALUES(image), product_reviews.image), is_approved = 1, created_at = NOW()
        ");
        $update_item_stmt = mysqli_prepare($conn, "UPDATE order_items SET is_reviewed = 1 WHERE id = ?");

        $p_id = 0; $r_val = 0; $c_val = ''; $img_val = null; $oi_id = 0;
        mysqli_stmt_bind_param($review_stmt, "iiiiss", $order_id, $p_id, $user_id, $r_val, $c_val, $img_val);
        mysqli_stmt_bind_param($update_item_stmt, "i", $oi_id);

        $products_to_update = [];
        $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $upload_dir = __DIR__ . '/uploads/reviews';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        foreach ($_POST['reviews'] as $index => $review_data) {
            $p_id = intval($review_data['product_id']);
            $oi_id = intval($review_data['order_item_id']);
            $r_val = intval($review_data['rating']);
            $c_val = trim($review_data['comment']);
            $img_val = null;

            // Check if photo was uploaded for this item
            if (isset($_FILES['review_images']['name'][$index]) && $_FILES['review_images']['error'][$index] === UPLOAD_ERR_OK) {
                $tmp = $_FILES['review_images']['tmp_name'][$index];
                $size = (int)$_FILES['review_images']['size'][$index];
                if ($size <= 5 * 1024 * 1024) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $tmp);
                    finfo_close($finfo);

                    if (isset($allowed_mimes[$mime])) {
                        $ext = $allowed_mimes[$mime];
                        $filename = 'rev_' . $order_id . '_' . $oi_id . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                        if (move_uploaded_file($tmp, $upload_dir . '/' . $filename)) {
                            $img_val = 'uploads/reviews/' . $filename;
                        }
                    }
                }
            }

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

            // Sync driver's rating in riders table
            $drv_stmt = mysqli_prepare($conn, "SELECT driver_id FROM logistics_tracking WHERE order_id = ? LIMIT 1");
            if ($drv_stmt) {
                mysqli_stmt_bind_param($drv_stmt, "i", $order_id);
                mysqli_stmt_execute($drv_stmt);
                $drv_res = mysqli_stmt_get_result($drv_stmt);
                if ($drv_row = mysqli_fetch_assoc($drv_res)) {
                    $d_id = (int)($drv_row['driver_id'] ?? 0);
                    if ($d_id > 0) {
                        $avg_q = mysqli_query($conn, "
                            SELECT AVG(dr.rating) AS avg_rating 
                            FROM delivery_reviews dr 
                            INNER JOIN logistics_tracking lt ON dr.order_id = lt.order_id 
                            WHERE lt.driver_id = $d_id
                        ");
                        if ($avg_q && $avg_data = mysqli_fetch_assoc($avg_q)) {
                            $new_avg = round((float)($avg_data['avg_rating'] ?? 5.0), 2);
                            mysqli_query($conn, "UPDATE riders SET rating = $new_avg WHERE id = $d_id");
                            mysqli_query($conn, "UPDATE driver_delivery_stats SET avg_rating = $new_avg WHERE driver_id = $d_id");
                        }
                    }
                }
                mysqli_stmt_close($drv_stmt);
            }
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