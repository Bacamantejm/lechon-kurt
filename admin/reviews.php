<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once '../includes/partner_dashboard_helper.php';

checkAdminAccess();
requirePermission('orders.view');
$admin_info = getAdminInfo($conn);
$csrf_token = generateCSRFToken();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

$review_reply_schema_ready = pdhEnsureProductReviewReplySchema($conn);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('orders.edit');

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header("Location: reviews.php");
        exit;
    }

    $review_id = intval($_POST['review_id'] ?? 0);
    if ($review_id <= 0) {
        $_SESSION['error'] = "Invalid review selected.";
        header("Location: reviews.php");
        exit;
    }

    $action = '';
    if (isset($_POST['approve'])) {
        $action = 'approve';
    } elseif (isset($_POST['unapprove'])) {
        $action = 'unapprove';
    } elseif (isset($_POST['delete'])) {
        $action = 'delete';
    } elseif (isset($_POST['save_reply'])) {
        $action = 'save_reply';
    } elseif (isset($_POST['clear_reply'])) {
        $action = 'clear_reply';
    }

    if ($action === '') {
        $_SESSION['error'] = "No valid action selected.";
        header("Location: reviews.php");
        exit;
    }

    if (($action === 'save_reply' || $action === 'clear_reply') && !$review_reply_schema_ready) {
        $_SESSION['error'] = "Seller reply feature is unavailable right now. Please contact support.";
        header("Location: reviews.php");
        exit;
    }

    // Load trusted product id from DB (avoid trusting hidden form fields for integrity).
    $review_lookup_query = "SELECT pr.id, pr.product_id
                            FROM product_reviews pr
                            INNER JOIN products p ON pr.product_id = p.id
                            WHERE pr.id = ?" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "") . "
                            LIMIT 1";
    $review_lookup_stmt = mysqli_prepare($conn, $review_lookup_query);
    if ($seller_scope_id !== null) {
        mysqli_stmt_bind_param($review_lookup_stmt, "ii", $review_id, $seller_scope_id);
    } else {
        mysqli_stmt_bind_param($review_lookup_stmt, "i", $review_id);
    }
    mysqli_stmt_execute($review_lookup_stmt);
    $review_lookup_result = mysqli_stmt_get_result($review_lookup_stmt);
    $review_row = mysqli_fetch_assoc($review_lookup_result);
    mysqli_stmt_close($review_lookup_stmt);

    if (!$review_row) {
        $_SESSION['error'] = "Review record no longer exists.";
        header("Location: reviews.php");
        exit;
    }

    $product_id = intval($review_row['product_id']);
    $seller_reply = trim((string)($_POST['seller_reply'] ?? ''));
    if (function_exists('mb_substr')) {
        $seller_reply = mb_substr($seller_reply, 0, 1000);
    } else {
        $seller_reply = substr($seller_reply, 0, 1000);
    }
    if ($action === 'clear_reply') {
        $seller_reply = '';
    }
    $action_success_message = "Review updated successfully.";

    mysqli_begin_transaction($conn);
    try {
        if ($action === 'approve' || $action === 'unapprove') {
            $is_approved = $action === 'approve' ? 1 : 0;
            $action_stmt = mysqli_prepare($conn, "UPDATE product_reviews SET is_approved = ? WHERE id = ?");
            mysqli_stmt_bind_param($action_stmt, "ii", $is_approved, $review_id);
            mysqli_stmt_execute($action_stmt);
            mysqli_stmt_close($action_stmt);
            $action_success_message = $action === 'approve'
                ? "Review approved successfully."
                : "Review moved back to pending.";
        } elseif ($action === 'delete') {
            $action_stmt = mysqli_prepare($conn, "DELETE FROM product_reviews WHERE id = ?");
            mysqli_stmt_bind_param($action_stmt, "i", $review_id);
            mysqli_stmt_execute($action_stmt);
            mysqli_stmt_close($action_stmt);
            $action_success_message = "Review deleted successfully.";
        } elseif ($action === 'save_reply' || $action === 'clear_reply') {
            if ($seller_reply !== '') {
                $action_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE product_reviews
                     SET seller_reply = ?, seller_reply_at = NOW(), seller_reply_by = ?
                     WHERE id = ?"
                );
                mysqli_stmt_bind_param($action_stmt, "sii", $seller_reply, $current_user_id, $review_id);
            } else {
                $action_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE product_reviews
                     SET seller_reply = NULL, seller_reply_at = NULL, seller_reply_by = NULL
                     WHERE id = ?"
                );
                mysqli_stmt_bind_param($action_stmt, "i", $review_id);
            }
            mysqli_stmt_execute($action_stmt);
            mysqli_stmt_close($action_stmt);
            $action_success_message = $seller_reply === ''
                ? "Seller reply removed."
                : "Seller reply saved successfully.";
        }

        // Recalculate product average rating using approved reviews only.
        $stats_stmt = mysqli_prepare($conn, "SELECT AVG(rating) as avg_rating, COUNT(id) as review_count FROM product_reviews WHERE product_id = ? AND is_approved = 1");
        mysqli_stmt_bind_param($stats_stmt, "i", $product_id);
        mysqli_stmt_execute($stats_stmt);
        $stats = mysqli_stmt_get_result($stats_stmt)->fetch_assoc();
        mysqli_stmt_close($stats_stmt);

        $avg_rating = isset($stats['avg_rating']) ? (float)$stats['avg_rating'] : 0.0;
        $review_count = isset($stats['review_count']) ? (int)$stats['review_count'] : 0;

        $update_stmt = mysqli_prepare($conn, "UPDATE products SET avg_rating = ?, review_count = ? WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "dii", $avg_rating, $review_count, $product_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);

        mysqli_commit($conn);
        $_SESSION['success'] = $action_success_message;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Unable to update review: " . $e->getMessage();
    }

    header("Location: reviews.php");
    exit;
}

// Fetch reviews
$reply_user_select = $review_reply_schema_ready
    ? "COALESCE(NULLIF(TRIM(reply_user.full_name), ''), 'Store') AS reply_user_name"
    : "'Store' AS reply_user_name";
$reply_user_join = $review_reply_schema_ready
    ? "LEFT JOIN users reply_user ON pr.seller_reply_by = reply_user.id"
    : '';
$reviews_query = "SELECT pr.*, p.name as product_name, u.full_name as user_name,
                         {$reply_user_select}
                  FROM product_reviews pr 
                  JOIN products p ON pr.product_id = p.id 
                  JOIN users u ON pr.user_id = u.id
                  {$reply_user_join} " .
                  ($seller_scope_id !== null ? "WHERE p.seller_id = " . (int)$seller_scope_id . " " : "") .
                  "ORDER BY pr.created_at DESC";
$reviews_result = mysqli_query($conn, $reviews_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Reviews - Admin</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
</head>
<body class="admin-polish reviews-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Product Reviews</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            <div class="admin-main">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); ?></div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); ?></div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>User</th>
                                <th>Rating</th>
                                <th>Comment</th>
                                <th>Date</th>
                                <th>Seller Reply</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($review = mysqli_fetch_assoc($reviews_result)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($review['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($review['user_name']); ?></td>
                                <td><?php $rating = max(0, min(5, (int)$review['rating'])); echo str_repeat('&#9733;', $rating) . str_repeat('&#9734;', 5 - $rating); ?></td>
                                <td><?php echo htmlspecialchars($review['comment']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($review['created_at'])); ?></td>
                                <td style="min-width:260px;">
                                    <?php
                                    $existing_reply = trim((string)($review['seller_reply'] ?? ''));
                                    $reply_author = trim((string)($review['reply_user_name'] ?? 'Store'));
                                    $reply_at = trim((string)($review['seller_reply_at'] ?? ''));
                                    ?>
                                    <?php if ($existing_reply !== ''): ?>
                                        <div class="small mb-2">
                                            <strong><?php echo htmlspecialchars($reply_author !== '' ? $reply_author : 'Store'); ?></strong>
                                            <?php if ($reply_at !== ''): ?>
                                                <span class="text-muted">- <?php echo date('M d, Y', strtotime($reply_at)); ?></span>
                                            <?php endif; ?>
                                            <div><?php echo nl2br(htmlspecialchars($existing_reply)); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <form method="POST" class="review-reply-form">
                                        <input type="hidden" name="review_id" value="<?php echo (int)$review['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <textarea name="seller_reply" class="form-control form-control-sm" rows="2" maxlength="1000" placeholder="Reply to this review"><?php echo htmlspecialchars($existing_reply); ?></textarea>
                                        <div class="d-flex gap-2 mt-2">
                                            <button type="submit" name="save_reply" class="btn btn-sm btn-primary">
                                                <?php echo $existing_reply === '' ? 'Post Reply' : 'Update Reply'; ?>
                                            </button>
                                            <?php if ($existing_reply !== ''): ?>
                                                <button type="submit" name="clear_reply" class="btn btn-sm btn-outline-secondary">Clear</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($review['is_approved']): ?>
                                        <span class="status-badge badge-success">Approved</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;" class="review-action-form">
                                        <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <?php if ($review['is_approved']): ?>
                                            <button type="submit" name="unapprove" class="btn btn-sm btn-warning">Unapprove</button>
                                        <?php else: ?>
                                            <button type="submit" name="approve" class="btn btn-sm btn-success">Approve</button>
                                        <?php endif; ?>
                                        <button type="button" name="delete" class="btn btn-sm btn-danger review-delete-btn">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.review-delete-btn').forEach(function(button) {
                button.addEventListener('click', function() {
                    const form = button.closest('.review-action-form');
                    if (!form) return;

                    const submitDelete = function() {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'delete';
                        hidden.value = '1';
                        form.appendChild(hidden);
                        form.submit();
                    };

                    if (window.swalConfirmAction) {
                        window.swalConfirmAction({
                            title: 'Delete review?',
                            text: 'This review will be permanently removed.',
                            icon: 'warning',
                            confirmButtonText: 'Yes, delete'
                        }).then(function(confirmed) {
                            if (confirmed) submitDelete();
                        });
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Delete review?',
                            text: 'This review will be permanently removed.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Yes, delete',
                            cancelButtonText: 'Cancel',
                            confirmButtonColor: '#3085d6',
                            cancelButtonColor: '#d33'
                        }).then(function(result) {
                            if (result && result.isConfirmed) submitDelete();
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>

