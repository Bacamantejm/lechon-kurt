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

    // Load trusted product id from DB
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
$reviews_query = "SELECT pr.*, p.name as product_name, p.image as product_image, u.full_name as user_name,
                         {$reply_user_select}
                  FROM product_reviews pr 
                  JOIN products p ON pr.product_id = p.id 
                  JOIN users u ON pr.user_id = u.id
                  {$reply_user_join} " .
                  ($seller_scope_id !== null ? "WHERE p.seller_id = " . (int)$seller_scope_id . " " : "") .
                  "ORDER BY pr.created_at DESC";
$reviews_result = mysqli_query($conn, $reviews_query);

// Pre-process reviews and compute summary statistics
$reviews_list = [];
$total_reviews = 0;
$total_rating_sum = 0;
$pending_count = 0;
$approved_count = 0;
$needs_reply_count = 0;
$rating_counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

if ($reviews_result) {
    while ($row = mysqli_fetch_assoc($reviews_result)) {
        $reviews_list[] = $row;
        $total_reviews++;
        $r = max(1, min(5, (int)$row['rating']));
        $total_rating_sum += $r;
        $rating_counts[$r]++;
        if ((int)$row['is_approved'] === 1) {
            $approved_count++;
        } else {
            $pending_count++;
        }
        $existing_reply = trim((string)($row['seller_reply'] ?? ''));
        if ($existing_reply === '') {
            $needs_reply_count++;
        }
    }
}
$avg_rating = $total_reviews > 0 ? round($total_rating_sum / $total_reviews, 1) : 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Reviews - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Modern Product Reviews UI Styles */
        .reviews-page-wrap {
            padding-bottom: 140px;
        }

        /* Metric Cards */
        .reviews-metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .review-stat-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .review-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 24, 40, 0.08);
        }

        .review-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .review-stat-icon.gold {
            background: #fef7ec;
            color: #d97706;
        }

        .review-stat-icon.blue {
            background: #eff8ff;
            color: #175cd3;
        }

        .review-stat-icon.amber {
            background: #fffaeb;
            color: #b54708;
        }

        .review-stat-icon.red {
            background: #fff1f0;
            color: #b3261e;
        }

        .review-stat-info .stat-val {
            font-size: 20px;
            font-weight: 700;
            color: #101828;
            line-height: 1.2;
        }

        .review-stat-info .stat-sub {
            font-size: 13px;
            color: #667085;
            font-weight: 500;
        }

        .review-stat-info .stat-lbl {
            font-size: 12px;
            color: #667085;
            font-weight: 500;
        }

        /* Filter Toolbar */
        .reviews-filter-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .reviews-status-tabs {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .tab-btn {
            background: #f2f4f7;
            border: 1px solid transparent;
            color: #475467;
            font-size: 12.5px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .tab-btn:hover {
            background: #e4e7ec;
            color: #101828;
        }

        .tab-btn.active {
            background: #b3261e;
            color: #ffffff;
            border-color: #b3261e;
        }

        .tab-count {
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 999px;
        }

        .tab-btn.active .tab-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .reviews-tools-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rating-select-filter {
            height: 36px;
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            font-size: 12.5px;
            color: #344054;
            padding: 4px 10px;
            min-width: 140px;
        }

        .reviews-search-wrap {
            position: relative;
            min-width: 240px;
        }

        .reviews-search-wrap .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #98a2b3;
            font-size: 12px;
            pointer-events: none;
        }

        .reviews-search-input {
            height: 36px;
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            padding-left: 32px;
            font-size: 12.5px;
        }

        .reviews-search-input:focus,
        .rating-select-filter:focus {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
            outline: none;
        }

        .reviews-view-toggle {
            display: inline-flex;
            background: #f2f4f7;
            padding: 3px;
            border-radius: 8px;
            border: 1px solid #eaecf0;
            gap: 2px;
        }

        .btn-toggle-view {
            background: none;
            border: none;
            padding: 5px 10px;
            border-radius: 6px;
            color: #667085;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-toggle-view.active {
            background: #ffffff;
            color: #b3261e;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08);
        }

        /* Review Cards Grid */
        .reviews-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .review-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 18px 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .review-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 24, 40, 0.06);
            border-color: #d0d5dd;
        }

        /* Card Top: Product + Status */
        .review-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f2f4f7;
            gap: 8px;
        }

        .review-product-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .review-product-thumb {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #eaecf0;
            flex-shrink: 0;
        }

        .review-product-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #f2f4f7;
            color: #667085;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .review-product-meta {
            min-width: 0;
        }

        .product-label {
            display: block;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #98a2b3;
            font-weight: 700;
        }

        .product-name {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #101828;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-badge-approved {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-badge-pending {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fffaeb;
            color: #b54708;
            border: 1px solid #fedf89;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Customer & Rating Row */
        .review-customer-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .review-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #eff8ff;
            color: #175cd3;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid #b2ddff;
        }

        .review-user-details {
            min-width: 0;
            flex-grow: 1;
        }

        .review-user-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #101828;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .verified-buyer-pill {
            font-size: 10.5px;
            font-weight: 600;
            color: #027a48;
            background: #ecfdf3;
            border: 1px solid #abefc6;
            padding: 1px 7px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .review-stars-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 3px;
            flex-wrap: wrap;
        }

        .star-rating-display {
            font-size: 12px;
            color: #f59e0b;
        }

        .rating-number-score {
            font-size: 12px;
            font-weight: 700;
            color: #101828;
        }

        .review-date-bullet {
            color: #d0d5dd;
            font-size: 11px;
        }

        .review-date-text {
            font-size: 11.5px;
            color: #667085;
        }

        /* Review Comment Body */
        .review-comment-body {
            margin-bottom: 14px;
        }

        .review-comment-text {
            font-size: 13px;
            color: #344054;
            line-height: 1.5;
            margin: 0 0 10px 0;
            word-break: break-word;
        }

        .review-comment-empty {
            font-size: 12.5px;
            color: #98a2b3;
            font-style: italic;
            margin: 0 0 10px 0;
        }

        .review-photo-wrapper {
            margin-top: 8px;
        }

        .review-photo-link {
            position: relative;
            display: inline-block;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #d0d5dd;
            max-width: 140px;
        }

        .review-attached-img {
            max-height: 85px;
            max-width: 140px;
            object-fit: cover;
            display: block;
            transition: transform 0.2s ease;
        }

        .review-photo-link:hover .review-attached-img {
            transform: scale(1.05);
        }

        .photo-expand-badge {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(16, 24, 40, 0.7);
            color: #ffffff;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 4px;
            text-align: center;
        }

        /* Seller Response Bubble */
        .seller-reply-bubble {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-size: 12.5px;
        }

        .reply-bubble-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .reply-author-info {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #101828;
            font-weight: 700;
        }

        .reply-author-info i {
            color: #b3261e;
        }

        .reply-timestamp {
            font-size: 11px;
            color: #667085;
            font-weight: 400;
        }

        .reply-bubble-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-reply-action {
            background: none;
            border: none;
            padding: 0;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.15s ease;
        }

        .btn-reply-action:hover {
            opacity: 0.8;
            text-decoration: underline;
        }

        .reply-bubble-content {
            color: #344054;
            line-height: 1.45;
            word-break: break-word;
        }

        /* Inline Reply Form */
        .reply-form-container {
            margin-bottom: 14px;
        }

        .compact-reply-input {
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font-size: 12.5px;
            padding: 8px 12px;
            color: #101828;
            background: #ffffff;
            transition: all 0.15s ease;
            resize: vertical;
        }

        .compact-reply-input:focus {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
            outline: none;
        }

        .reply-form-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 6px;
            gap: 8px;
            flex-wrap: wrap;
        }

        .reply-hint-text {
            font-size: 11px;
            color: #667085;
        }

        /* Card Footer Actions */
        .review-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid #f2f4f7;
            gap: 8px;
            margin-top: auto;
        }

        .btn-quick-reply {
            background: #eff8ff;
            color: #175cd3;
            border: 1px solid #b2ddff;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-quick-reply:hover {
            background: #175cd3;
            color: #ffffff;
        }

        .footer-actions-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-action-approve {
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-action-approve:hover {
            background: #027a48;
            color: #ffffff;
        }

        .btn-action-unapprove {
            background: #fffaeb;
            color: #b54708;
            border: 1px solid #fedf89;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-action-unapprove:hover {
            background: #b54708;
            color: #ffffff;
        }

        .btn-action-delete {
            background: #ffffff;
            color: #b3261e;
            border: 1px solid #fee4e2;
            border-radius: 6px;
            font-size: 12px;
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-action-delete:hover {
            background: #fff1f0;
            border-color: #fecdca;
        }

        .btn-builder-primary {
            background: #b3261e;
            color: #ffffff;
            border: 1px solid #b3261e;
            border-radius: 6px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-builder-primary:hover {
            background: #981b15;
            color: #ffffff;
        }

        /* Modern Table Card */
        .reviews-table-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .empty-reviews-state {
            background: #ffffff;
            border: 1px dashed #d0d5dd;
            border-radius: 14px;
            padding: 48px 24px;
            text-align: center;
            color: #667085;
            margin-bottom: 24px;
        }

        .empty-reviews-state i {
            font-size: 40px;
            color: #d0d5dd;
            margin-bottom: 12px;
        }

        /* Dark Mode Overrides */
        body.dark-mode .review-stat-card,
        body.dark-mode .reviews-filter-card,
        body.dark-mode .review-card,
        body.dark-mode .reviews-table-card,
        body.dark-mode .empty-reviews-state {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .review-stat-info .stat-val,
        body.dark-mode .product-name,
        body.dark-mode .review-user-name,
        body.dark-mode .rating-number-score,
        body.dark-mode .reply-author-info {
            color: #f8fafc !important;
        }

        body.dark-mode .review-comment-text,
        body.dark-mode .reply-bubble-content {
            color: #cbd5e1 !important;
        }

        body.dark-mode .seller-reply-bubble {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        body.dark-mode .compact-reply-input,
        body.dark-mode .reviews-search-input,
        body.dark-mode .rating-select-filter {
            background: #1e293b !important;
            border-color: #475569 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .btn-toggle-view.active {
            background: #1e293b !important;
            color: #f87171 !important;
        }

        body.dark-mode .reviews-view-toggle,
        body.dark-mode .tab-btn {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        body.dark-mode .tab-btn.active {
            background: #b3261e !important;
            color: #ffffff !important;
        }

        body.dark-mode .table thead th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-color: #334155 !important;
        }

        body.dark-mode .table tbody tr {
            border-color: #334155 !important;
        }
    </style>
</head>
<body class="admin-polish reviews-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Product Reviews</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Admin')); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>

            <div class="admin-main reviews-page-wrap">
                <!-- Flash Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <!-- 1. Top Metrics Strip -->
                <div class="reviews-metric-grid">
                    <div class="review-stat-card">
                        <div class="review-stat-icon gold"><i class="fas fa-star"></i></div>
                        <div class="review-stat-info">
                            <div class="stat-val d-flex align-items-baseline gap-1">
                                <span><?php echo number_format($avg_rating, 1); ?></span>
                                <span class="stat-sub">/ 5.0</span>
                            </div>
                            <div class="stat-lbl">Average Rating</div>
                        </div>
                    </div>
                    <div class="review-stat-card">
                        <div class="review-stat-icon blue"><i class="fas fa-comments"></i></div>
                        <div class="review-stat-info">
                            <div class="stat-val"><?php echo number_format($total_reviews); ?></div>
                            <div class="stat-lbl">Customer Reviews</div>
                        </div>
                    </div>
                    <div class="review-stat-card">
                        <div class="review-stat-icon amber"><i class="fas fa-clock"></i></div>
                        <div class="review-stat-info">
                            <div class="stat-val"><?php echo number_format($pending_count); ?></div>
                            <div class="stat-lbl">Pending Approval</div>
                        </div>
                    </div>
                    <div class="review-stat-card">
                        <div class="review-stat-icon red"><i class="fas fa-reply"></i></div>
                        <div class="review-stat-info">
                            <div class="stat-val"><?php echo number_format($needs_reply_count); ?></div>
                            <div class="stat-lbl">Awaiting Response</div>
                        </div>
                    </div>
                </div>

                <!-- 2. Modern Filter Toolbar -->
                <div class="reviews-filter-card">
                    <div class="reviews-status-tabs">
                        <button type="button" class="tab-btn active" data-status-filter="all" onclick="setStatusFilter('all')">
                            All Reviews <span class="tab-count"><?php echo $total_reviews; ?></span>
                        </button>
                        <button type="button" class="tab-btn" data-status-filter="pending" onclick="setStatusFilter('pending')">
                            Pending <span class="tab-count"><?php echo $pending_count; ?></span>
                        </button>
                        <button type="button" class="tab-btn" data-status-filter="approved" onclick="setStatusFilter('approved')">
                            Approved <span class="tab-count"><?php echo $approved_count; ?></span>
                        </button>
                        <button type="button" class="tab-btn" data-status-filter="needs_reply" onclick="setStatusFilter('needs_reply')">
                            Needs Reply <span class="tab-count"><?php echo $needs_reply_count; ?></span>
                        </button>
                    </div>

                    <div class="reviews-tools-right">
                        <!-- Star Filter -->
                        <select class="form-select rating-select-filter" id="starFilterSelect" onchange="setStarFilter(this.value)">
                            <option value="all">All Stars (★)</option>
                            <option value="5">5 Stars (<?php echo $rating_counts[5]; ?>)</option>
                            <option value="4">4 Stars (<?php echo $rating_counts[4]; ?>)</option>
                            <option value="3">3 Stars (<?php echo $rating_counts[3]; ?>)</option>
                            <option value="2">2 Stars (<?php echo $rating_counts[2]; ?>)</option>
                            <option value="1">1 Star (<?php echo $rating_counts[1]; ?>)</option>
                        </select>

                        <!-- Search Box -->
                        <div class="reviews-search-wrap">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" class="form-control reviews-search-input" id="reviewSearchInput" placeholder="Search product, customer, or comment..." oninput="handleSearch(this.value)">
                        </div>

                        <!-- View Switcher -->
                        <div class="reviews-view-toggle">
                            <button type="button" class="btn-toggle-view active" id="btnCardsView" onclick="switchReviewsView('cards')" title="Cards View">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button type="button" class="btn-toggle-view" id="btnTableView" onclick="switchReviewsView('table')" title="Table View">
                                <i class="fas fa-table-list"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 3. Cards View Container (Default) -->
                <div id="reviewsCardViewContainer" class="reviews-card-grid">
                    <?php if (!empty($reviews_list)): foreach ($reviews_list as $review): ?>
                        <?php
                            $is_approved = (int)$review['is_approved'] === 1;
                            $existing_reply = trim((string)($review['seller_reply'] ?? ''));
                            $reply_author = trim((string)($review['reply_user_name'] ?? 'Store'));
                            $reply_at = trim((string)($review['seller_reply_at'] ?? ''));
                            $rating_val = max(1, min(5, (int)$review['rating']));
                            $status_key = $is_approved ? 'approved' : 'pending';
                            $reply_key = $existing_reply === '' ? 'needs_reply' : 'replied';
                            
                            $user_initials = strtoupper(substr(trim($review['user_name']), 0, 2));
                            if ($user_initials === '') $user_initials = 'CU';
                            
                            $search_haystack = strtolower($review['product_name'] . ' ' . $review['user_name'] . ' ' . $review['comment'] . ' ' . $existing_reply);
                        ?>
                        <div class="review-card" 
                             data-status="<?php echo $status_key; ?>" 
                             data-reply="<?php echo $reply_key; ?>" 
                             data-rating="<?php echo $rating_val; ?>" 
                             data-search="<?php echo htmlspecialchars($search_haystack); ?>"
                             id="reviewCard<?php echo (int)$review['id']; ?>">
                            
                            <!-- Card Top -->
                            <div class="review-card-top">
                                <div class="review-product-badge">
                                    <?php if (!empty($review['product_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($review['product_image']); ?>" class="review-product-thumb" alt="Product">
                                    <?php else: ?>
                                        <div class="review-product-icon"><i class="fas fa-utensils"></i></div>
                                    <?php endif; ?>
                                    <div class="review-product-meta">
                                        <span class="product-label">Product</span>
                                        <span class="product-name" title="<?php echo htmlspecialchars($review['product_name']); ?>">
                                            <?php echo htmlspecialchars($review['product_name']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="review-status-wrapper">
                                    <?php if ($is_approved): ?>
                                        <span class="status-badge-approved"><i class="fas fa-circle-check"></i> Approved</span>
                                    <?php else: ?>
                                        <span class="status-badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Customer & Rating -->
                            <div class="review-customer-row">
                                <div class="review-user-avatar"><?php echo htmlspecialchars($user_initials); ?></div>
                                <div class="review-user-details">
                                    <div class="review-user-name">
                                        <span><?php echo htmlspecialchars($review['user_name']); ?></span>
                                        <span class="verified-buyer-pill"><i class="fas fa-shield-check"></i> Buyer</span>
                                    </div>
                                    <div class="review-stars-row">
                                        <span class="star-rating-display">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="<?php echo $i <= $rating_val ? 'fas fa-star' : 'far fa-star text-muted opacity-50'; ?>"></i>
                                            <?php endfor; ?>
                                        </span>
                                        <span class="rating-number-score"><?php echo number_format($rating_val, 1); ?></span>
                                        <span class="review-date-bullet">•</span>
                                        <span class="review-date-text">
                                            <i class="far fa-calendar-alt me-1"></i><?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Review Comment -->
                            <div class="review-comment-body">
                                <?php if (!empty(trim((string)$review['comment']))): ?>
                                    <p class="review-comment-text">"<?php echo nl2br(htmlspecialchars($review['comment'])); ?>"</p>
                                <?php else: ?>
                                    <p class="review-comment-empty">No written comment provided.</p>
                                <?php endif; ?>

                                <?php if (!empty($review['image'])): ?>
                                    <div class="review-photo-wrapper">
                                        <a href="../<?php echo htmlspecialchars($review['image']); ?>" target="_blank" class="review-photo-link" title="Click to view full photo">
                                            <img src="../<?php echo htmlspecialchars($review['image']); ?>" alt="Customer Photo" class="review-attached-img">
                                            <span class="photo-expand-badge"><i class="fas fa-expand"></i> View Photo</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Existing Seller Reply Box -->
                            <?php if ($existing_reply !== ''): ?>
                                <div class="seller-reply-bubble" id="existingReplyBox<?php echo (int)$review['id']; ?>">
                                    <div class="reply-bubble-head">
                                        <div class="reply-author-info">
                                            <i class="fas fa-reply"></i>
                                            <span><?php echo htmlspecialchars($reply_author); ?></span>
                                            <?php if ($reply_at !== ''): ?>
                                                <span class="reply-timestamp">• <?php echo date('M d, Y', strtotime($reply_at)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="reply-bubble-actions">
                                            <button type="button" class="btn-reply-action text-primary" onclick="toggleEditReply(<?php echo (int)$review['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="btn-reply-action text-danger" onclick="confirmDeleteReply(<?php echo (int)$review['id']; ?>)">
                                                <i class="fas fa-trash-alt"></i> Clear
                                            </button>
                                        </div>
                                    </div>
                                    <div class="reply-bubble-content">
                                        <?php echo nl2br(htmlspecialchars($existing_reply)); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Inline Reply Form (Collapsible) -->
                            <div class="reply-form-container" id="replyFormBox<?php echo (int)$review['id']; ?>" style="<?php echo $existing_reply !== '' ? 'display: none;' : ''; ?>">
                                <form method="POST" class="review-reply-form" id="replyForm<?php echo (int)$review['id']; ?>">
                                    <input type="hidden" name="review_id" value="<?php echo (int)$review['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <textarea name="seller_reply" class="form-control compact-reply-input" rows="2" maxlength="1000" placeholder="Write a response to <?php echo htmlspecialchars($review['user_name']); ?>..."><?php echo htmlspecialchars($existing_reply); ?></textarea>
                                    <div class="reply-form-footer">
                                        <span class="reply-hint-text"><i class="fas fa-eye me-1"></i> Public response</span>
                                        <div class="d-flex gap-2">
                                            <?php if ($existing_reply !== ''): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size: 11.5px;" onclick="cancelEditReply(<?php echo (int)$review['id']; ?>)">Cancel</button>
                                            <?php endif; ?>
                                            <button type="submit" name="save_reply" class="btn btn-builder-primary py-1 px-2">
                                                <i class="fas fa-paper-plane me-1"></i> <?php echo $existing_reply === '' ? 'Post Reply' : 'Update Reply'; ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Clear Reply Form (Hidden) -->
                            <form method="POST" id="clearReplyForm<?php echo (int)$review['id']; ?>" style="display: none;">
                                <input type="hidden" name="review_id" value="<?php echo (int)$review['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="clear_reply" value="1">
                            </form>

                            <!-- Card Footer -->
                            <div class="review-card-footer">
                                <div class="footer-left">
                                    <?php if ($existing_reply === ''): ?>
                                        <span class="badge text-bg-light border" style="font-size: 11px;">
                                            <i class="fas fa-clock text-warning me-1"></i> Awaiting Reply
                                        </span>
                                    <?php else: ?>
                                        <span class="badge text-bg-light border text-success" style="font-size: 11px;">
                                            <i class="fas fa-check-double me-1"></i> Responded
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="footer-actions-group">
                                    <form method="POST" class="d-inline" id="statusForm<?php echo (int)$review['id']; ?>">
                                        <input type="hidden" name="review_id" value="<?php echo (int)$review['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <?php if ($is_approved): ?>
                                            <button type="button" class="btn-action-unapprove" onclick="confirmStatusToggle(<?php echo (int)$review['id']; ?>, 'unapprove')">
                                                <i class="fas fa-pause-circle"></i> Unapprove
                                            </button>
                                            <input type="hidden" name="unapprove" value="1" disabled id="unapproveInput<?php echo (int)$review['id']; ?>">
                                        <?php else: ?>
                                            <button type="button" class="btn-action-approve" onclick="confirmStatusToggle(<?php echo (int)$review['id']; ?>, 'approve')">
                                                <i class="fas fa-check-circle"></i> Approve
                                            </button>
                                            <input type="hidden" name="approve" value="1" disabled id="approveInput<?php echo (int)$review['id']; ?>">
                                        <?php endif; ?>
                                    </form>
                                    <form method="POST" class="d-inline" id="deleteForm<?php echo (int)$review['id']; ?>">
                                        <input type="hidden" name="review_id" value="<?php echo (int)$review['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="delete" value="1" disabled id="deleteInput<?php echo (int)$review['id']; ?>">
                                        <button type="button" class="btn-action-delete" onclick="confirmDelete(<?php echo (int)$review['id']; ?>)" title="Delete review">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; else: ?>
                        <div class="col-12">
                            <div class="empty-reviews-state">
                                <i class="fas fa-comments"></i>
                                <h5>No customer reviews yet</h5>
                                <p class="mb-0">When customers review your menu items, their feedback and ratings will appear here.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 4. Table View Container (Alternative Toggle) -->
                <div id="reviewsTableViewContainer" class="reviews-table-card" style="display: none;">
                    <div class="table-responsive p-0">
                        <table class="table table-hover align-middle mb-0" id="reviewsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Product</th>
                                    <th>Customer</th>
                                    <th>Rating</th>
                                    <th>Review / Comment</th>
                                    <th>Date</th>
                                    <th>Store Reply</th>
                                    <th>Status</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($reviews_list)): foreach ($reviews_list as $review): ?>
                                <?php
                                    $is_approved = (int)$review['is_approved'] === 1;
                                    $existing_reply = trim((string)($review['seller_reply'] ?? ''));
                                    $rating_val = max(1, min(5, (int)$review['rating']));
                                    $status_key = $is_approved ? 'approved' : 'pending';
                                    $reply_key = $existing_reply === '' ? 'needs_reply' : 'replied';
                                    $search_haystack = strtolower($review['product_name'] . ' ' . $review['user_name'] . ' ' . $review['comment'] . ' ' . $existing_reply);
                                ?>
                                <tr data-status="<?php echo $status_key; ?>" 
                                    data-reply="<?php echo $reply_key; ?>" 
                                    data-rating="<?php echo $rating_val; ?>" 
                                    data-search="<?php echo htmlspecialchars($search_haystack); ?>">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($review['product_image'])): ?>
                                                <img src="../<?php echo htmlspecialchars($review['product_image']); ?>" class="review-product-thumb" style="width:30px; height:30px;" alt="">
                                            <?php else: ?>
                                                <div class="review-product-icon" style="width:30px; height:30px; font-size:12px;"><i class="fas fa-utensils"></i></div>
                                            <?php endif; ?>
                                            <strong style="font-size: 13px;"><?php echo htmlspecialchars($review['product_name']); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark" style="font-size: 13px;"><?php echo htmlspecialchars($review['user_name']); ?></div>
                                        <span class="verified-buyer-pill" style="font-size: 10px;">Verified</span>
                                    </td>
                                    <td>
                                        <span class="star-rating-display" style="font-size: 11px;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="<?php echo $i <= $rating_val ? 'fas fa-star' : 'far fa-star text-muted opacity-50'; ?>"></i>
                                            <?php endfor; ?>
                                        </span>
                                        <span class="fw-bold ms-1" style="font-size: 12px;"><?php echo number_format($rating_val, 1); ?></span>
                                    </td>
                                    <td style="max-width: 240px;">
                                        <?php if (!empty(trim((string)$review['comment']))): ?>
                                            <div style="font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($review['comment']); ?>">
                                                "<?php echo htmlspecialchars($review['comment']); ?>"
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic" style="font-size: 12px;">No comment</span>
                                        <?php endif; ?>
                                        <?php if (!empty($review['image'])): ?>
                                            <a href="../<?php echo htmlspecialchars($review['image']); ?>" target="_blank" class="badge text-bg-light border text-decoration-none mt-1" style="font-size: 10px;">
                                                <i class="fas fa-image me-1"></i> Attachment
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 12px; white-space: nowrap;"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></td>
                                    <td style="max-width: 200px;">
                                        <?php if ($existing_reply !== ''): ?>
                                            <span class="badge text-bg-light border text-success" style="font-size: 11px;" title="<?php echo htmlspecialchars($existing_reply); ?>">
                                                <i class="fas fa-check me-1"></i> Replied
                                            </span>
                                        <?php else: ?>
                                            <span class="badge text-bg-light border text-muted" style="font-size: 11px;">
                                                <i class="fas fa-clock text-warning me-1"></i> Awaiting
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_approved): ?>
                                            <span class="status-badge-approved" style="font-size: 11px;"><i class="fas fa-circle-check"></i> Approved</span>
                                        <?php else: ?>
                                            <span class="status-badge-pending" style="font-size: 11px;"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-1">
                                            <?php if ($is_approved): ?>
                                                <button type="button" class="btn-action-unapprove" onclick="confirmStatusToggle(<?php echo (int)$review['id']; ?>, 'unapprove')" title="Unapprove">
                                                    <i class="fas fa-pause-circle"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-action-approve" onclick="confirmStatusToggle(<?php echo (int)$review['id']; ?>, 'approve')" title="Approve">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn-action-delete" onclick="confirmDelete(<?php echo (int)$review['id']; ?>)" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">No reviews available.</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Empty Filter State (Hidden by default, shown by JS when filters yield 0 results) -->
                <div class="empty-reviews-state" id="noFilterResults" style="display: none;">
                    <i class="fas fa-filter-circle-xmark"></i>
                    <h5>No matching reviews found</h5>
                    <p class="mb-3">Try adjusting your status tab, star rating filter, or search keywords.</p>
                    <button type="button" class="btn btn-sm btn-builder-primary" onclick="resetAllFilters()">
                        <i class="fas fa-undo me-1"></i> Reset Filters
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    let currentStatusFilter = 'all';
    let currentStarFilter = 'all';
    let currentSearchQuery = '';

    function setStatusFilter(status) {
        currentStatusFilter = status;
        document.querySelectorAll('.tab-btn[data-status-filter]').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-status-filter') === status);
        });
        applyAllFilters();
    }

    function setStarFilter(stars) {
        currentStarFilter = stars;
        applyAllFilters();
    }

    function handleSearch(query) {
        currentSearchQuery = query.toLowerCase().trim();
        applyAllFilters();
    }

    function applyAllFilters() {
        const cardItems = document.querySelectorAll('#reviewsCardViewContainer .review-card');
        const tableRows = document.querySelectorAll('#reviewsTableViewContainer tbody tr[data-status]');
        let visibleCount = 0;

        const checkMatch = (item) => {
            const status = item.getAttribute('data-status') || '';
            const reply = item.getAttribute('data-reply') || '';
            const rating = item.getAttribute('data-rating') || '';
            const search = item.getAttribute('data-search') || '';

            // Status check
            let statusMatch = false;
            if (currentStatusFilter === 'all') {
                statusMatch = true;
            } else if (currentStatusFilter === 'pending' && status === 'pending') {
                statusMatch = true;
            } else if (currentStatusFilter === 'approved' && status === 'approved') {
                statusMatch = true;
            } else if (currentStatusFilter === 'needs_reply' && reply === 'needs_reply') {
                statusMatch = true;
            }

            // Star check
            let starMatch = (currentStarFilter === 'all') || (rating === currentStarFilter);

            // Search check
            let searchMatch = !currentSearchQuery || search.includes(currentSearchQuery);

            return statusMatch && starMatch && searchMatch;
        };

        cardItems.forEach(card => {
            const match = checkMatch(card);
            card.style.display = match ? 'flex' : 'none';
            if (match) visibleCount++;
        });

        tableRows.forEach(row => {
            const match = checkMatch(row);
            row.style.display = match ? '' : 'none';
        });

        const noResults = document.getElementById('noFilterResults');
        if (noResults) {
            noResults.style.display = (visibleCount === 0 && cardItems.length > 0) ? 'block' : 'none';
        }
    }

    function resetAllFilters() {
        currentStatusFilter = 'all';
        currentStarFilter = 'all';
        currentSearchQuery = '';
        document.getElementById('starFilterSelect').value = 'all';
        document.getElementById('reviewSearchInput').value = '';
        setStatusFilter('all');
    }

    function switchReviewsView(view) {
        const cardsContainer = document.getElementById('reviewsCardViewContainer');
        const tableContainer = document.getElementById('reviewsTableViewContainer');
        const btnCards = document.getElementById('btnCardsView');
        const btnTable = document.getElementById('btnTableView');

        if (view === 'table') {
            cardsContainer.style.display = 'none';
            tableContainer.style.display = 'block';
            btnCards.classList.remove('active');
            btnTable.classList.add('active');
            localStorage.setItem('admin_reviews_view', 'table');
        } else {
            cardsContainer.style.display = 'grid';
            tableContainer.style.display = 'none';
            btnCards.classList.add('active');
            btnTable.classList.remove('active');
            localStorage.setItem('admin_reviews_view', 'cards');
        }
    }

    function toggleEditReply(reviewId) {
        const formBox = document.getElementById('replyFormBox' + reviewId);
        const existingBox = document.getElementById('existingReplyBox' + reviewId);
        if (formBox) {
            formBox.style.display = (formBox.style.display === 'none') ? 'block' : 'none';
        }
        if (existingBox) {
            existingBox.style.display = (formBox.style.display === 'block') ? 'none' : 'block';
        }
    }

    function cancelEditReply(reviewId) {
        const formBox = document.getElementById('replyFormBox' + reviewId);
        const existingBox = document.getElementById('existingReplyBox' + reviewId);
        if (formBox) formBox.style.display = 'none';
        if (existingBox) existingBox.style.display = 'block';
    }

    function confirmStatusToggle(reviewId, action) {
        const isApprove = action === 'approve';
        Swal.fire({
            title: isApprove ? 'Approve Review?' : 'Unapprove Review?',
            text: isApprove 
                ? 'This review will be published and visible on the customer store.'
                : 'This review will be hidden and marked pending approval.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: isApprove ? 'Yes, approve it' : 'Yes, unapprove it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: isApprove ? '#027a48' : '#b54708',
            cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.getElementById('statusForm' + reviewId);
                const input = document.getElementById((isApprove ? 'approveInput' : 'unapproveInput') + reviewId);
                if (form && input) {
                    input.removeAttribute('disabled');
                    form.submit();
                }
            }
        });
    }

    function confirmDelete(reviewId) {
        Swal.fire({
            title: 'Delete Review?',
            text: 'This customer review will be permanently deleted and product ratings will be recalculated.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete permanently',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#b3261e',
            cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.getElementById('deleteForm' + reviewId);
                const input = document.getElementById('deleteInput' + reviewId);
                if (form && input) {
                    input.removeAttribute('disabled');
                    form.submit();
                }
            }
        });
    }

    function confirmDeleteReply(reviewId) {
        Swal.fire({
            title: 'Remove Response?',
            text: 'Are you sure you want to delete your response to this customer?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove response',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#b3261e',
            cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.getElementById('clearReplyForm' + reviewId);
                if (form) form.submit();
            }
        });
    }

    // Restore view preference
    document.addEventListener('DOMContentLoaded', () => {
        const savedView = localStorage.getItem('admin_reviews_view');
        if (savedView === 'table') {
            switchReviewsView('table');
        } else {
            switchReviewsView('cards');
        }
    });
    </script>
</body>
</html>