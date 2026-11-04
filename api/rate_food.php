<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/partner_dashboard_helper.php';

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a review.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
$comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : '';

if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a rating between 1 and 5 stars.']);
    exit;
}

// Ensure product_reviews schema has the image column
pdhEnsureProductReviewReplySchema($conn);

// Verify order ownership and delivery status
$ord_stmt = $conn->prepare("
    SELECT o.id, o.status, o.user_id, o.seller_id, o.store_id, lt.current_status as tracking_status
    FROM orders o
    LEFT JOIN logistics_tracking lt ON o.id = lt.order_id
    WHERE o.id = ? AND o.user_id = ?
    LIMIT 1
");

if (!$ord_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

$ord_stmt->bind_param("ii", $order_id, $user_id);
$ord_stmt->execute();
$ord_res = $ord_stmt->get_result();
$order_data = $ord_res ? $ord_res->fetch_assoc() : null;
$ord_stmt->close();

if (!$order_data) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Order not found or permission denied.']);
    exit;
}

$is_delivered = in_array(strtolower((string)$order_data['status']), ['delivered', 'completed'], true) 
             || in_array(strtolower((string)($order_data['tracking_status'] ?? '')), ['delivered', 'completed'], true);

if (!$is_delivered) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Food reviews can only be submitted once the food has been successfully delivered.']);
    exit;
}

// Handle optional review image upload
$uploaded_image_rel = null;
if (isset($_FILES['food_image']) && $_FILES['food_image']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['food_image']['tmp_name'];
    $file_size = (int)$_FILES['food_image']['size'];
    $file_orig_name = $_FILES['food_image']['name'];

    // Max 5MB
    if ($file_size > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Image file is too large. Maximum size is 5MB.']);
        exit;
    }

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_tmp);
    finfo_close($finfo);

    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];

    if (!isset($allowed_mimes[$mime_type])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid image format. Please upload JPG, PNG, or WEBP photos.']);
        exit;
    }

    $ext = $allowed_mimes[$mime_type];
    $target_dir = __DIR__ . '/../uploads/reviews';
    if (!is_dir($target_dir)) {
        @mkdir($target_dir, 0755, true);
    }

    $unique_name = 'rev_' . $order_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target_file = $target_dir . '/' . $unique_name;

    if (move_uploaded_file($file_tmp, $target_file)) {
        $uploaded_image_rel = 'uploads/reviews/' . $unique_name;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded image. Please try again.']);
        exit;
    }
}

// Fetch order items to link review to products
$items_stmt = $conn->prepare("
    SELECT oi.id as order_item_id, oi.product_id, oi.product_name, p.id as product_db_id
    FROM order_items oi
    LEFT JOIN products p ON (oi.product_id = p.product_id COLLATE utf8mb4_general_ci OR oi.product_id = CAST(p.id AS CHAR) COLLATE utf8mb4_general_ci)
    WHERE oi.order_id = ?
");

$items = [];
if ($items_stmt) {
    $items_stmt->bind_param("i", $order_id);
    $items_stmt->execute();
    $items_res = $items_stmt->get_result();
    while ($row = $items_res->fetch_assoc()) {
        $items[] = $row;
    }
    $items_stmt->close();
}

mysqli_begin_transaction($conn);

try {
    $products_to_update = [];
    $inserted_any = false;

    if (!empty($items)) {
        $review_stmt = $conn->prepare("
            INSERT INTO product_reviews (order_id, product_id, user_id, rating, comment, image, is_approved, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $update_item_stmt = $conn->prepare("UPDATE order_items SET is_reviewed = 1 WHERE id = ?");

        foreach ($items as $item) {
            $product_db_id = (int)($item['product_db_id'] ?? 0);
            $order_item_id = (int)$item['order_item_id'];

            if ($product_db_id > 0) {
                // Check if this item is already reviewed for this order
                $chk_rev = $conn->prepare("SELECT id FROM product_reviews WHERE order_id = ? AND product_id = ? AND user_id = ? LIMIT 1");
                $chk_rev->bind_param("iii", $order_id, $product_db_id, $user_id);
                $chk_rev->execute();
                $already_reviewed = $chk_rev->get_result()->fetch_assoc();
                $chk_rev->close();

                if ($already_reviewed) {
                    $rev_id = (int)$already_reviewed['id'];
                    if ($uploaded_image_rel !== null) {
                        $upd_rev = $conn->prepare("UPDATE product_reviews SET rating = ?, comment = ?, image = ?, created_at = NOW() WHERE id = ?");
                        $upd_rev->bind_param("issi", $rating, $comment, $uploaded_image_rel, $rev_id);
                    } else {
                        $upd_rev = $conn->prepare("UPDATE product_reviews SET rating = ?, comment = ?, created_at = NOW() WHERE id = ?");
                        $upd_rev->bind_param("isi", $rating, $comment, $rev_id);
                    }
                    $upd_rev->execute();
                    $upd_rev->close();
                } else {
                    $review_stmt->bind_param("iiiiss", $order_id, $product_db_id, $user_id, $rating, $comment, $uploaded_image_rel);
                    $review_stmt->execute();
                }

                $update_item_stmt->bind_param("i", $order_item_id);
                $update_item_stmt->execute();

                $products_to_update[] = $product_db_id;
                $inserted_any = true;
            }
        }

        if ($review_stmt) $review_stmt->close();
        if ($update_item_stmt) $update_item_stmt->close();
    }

    // Fallback if no specific products matched order items: attach to seller's primary product or generic
    if (!$inserted_any) {
        $seller_id = (int)($order_data['seller_id'] ?? 0);
        $fallback_pid = 0;
        if ($seller_id > 0) {
            $fb_res = mysqli_query($conn, "SELECT id FROM products WHERE seller_id = $seller_id AND is_archived = 0 LIMIT 1");
            if ($fb_res && $fb_row = mysqli_fetch_assoc($fb_res)) {
                $fallback_pid = (int)$fb_row['id'];
            }
        }

        $ins_fb = $conn->prepare("
            INSERT INTO product_reviews (order_id, product_id, user_id, rating, comment, image, is_approved, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $ins_fb->bind_param("iiiiss", $order_id, $fallback_pid, $user_id, $rating, $comment, $uploaded_image_rel);
        $ins_fb->execute();
        $ins_fb->close();

        if ($fallback_pid > 0) {
            $products_to_update[] = $fallback_pid;
        }
    }

    mysqli_commit($conn);

    // Update product ratings in background
    if (!function_exists('syncProductRatingStats')) {
        function syncProductRatingStats(mysqli $conn, int $pid): void {
            if ($pid <= 0) return;
            $sql = "SELECT AVG(rating) as avg_rating, COUNT(id) as review_count FROM product_reviews WHERE product_id = ? AND is_approved = 1";
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $pid);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $stats = $res ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($stmt);

                $avg = round((float)($stats['avg_rating'] ?? 0), 2);
                $cnt = (int)($stats['review_count'] ?? 0);

                $upd = mysqli_prepare($conn, "UPDATE products SET avg_rating = ?, review_count = ? WHERE id = ?");
                if ($upd) {
                    mysqli_stmt_bind_param($upd, "dii", $avg, $cnt, $pid);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                }
            }
        }
    }

    foreach (array_unique($products_to_update) as $pid) {
        syncProductRatingStats($conn, (int)$pid);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your food review and photo have been posted.',
        'data' => [
            'rating' => $rating,
            'comment' => $comment,
            'image' => $uploaded_image_rel,
            'created_at' => date('M j, Y')
        ]
    ]);
    exit;

} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save review: ' . $e->getMessage()]);
    exit;
}
