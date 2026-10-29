<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/security.php';
require_once '../includes/sales_receipt_helper.php';
require_once 'auth.php';

checkAdminAccess();
requireAnyPermission(['orders.view', 'orders.create']);
$csrf_token = generateCSRFToken();
$admin_info = getAdminInfo($conn);
$admin_user_id = intval($_SESSION['user_id'] ?? 0);
$receipt_owner_user_id = $admin_user_id;
$seller_scope_id = null;
if (function_exists('isApprovedFranchiseSellerAccount') && isApprovedFranchiseSellerAccount($conn, $admin_user_id)) {
    $receipt_owner_user_id = (int)getFranchiseSellerScopeOwnerId($conn, $admin_user_id);
    $seller_scope_id = $receipt_owner_user_id;
}
$receipt_business = srFetchBusinessProfile($conn, (int)$receipt_owner_user_id);
$receipt_business['address'] = trim((string)($receipt_business['address'] ?? '')) !== ''
    ? (string)$receipt_business['address']
    : 'In-store Pickup';
$can_create_orders = true;
if (function_exists('hasPermission')) {
    $can_create_orders = hasPermission($conn, $admin_user_id, 'orders.create');
}
if ((string)($_SESSION['role_name'] ?? '') === 'super_admin' || ((string)($_SESSION['user_type'] ?? '') === 'admin' && intval($_SESSION['role_id'] ?? 0) <= 0)) {
    $can_create_orders = true;
}

// Daily stats for bottom POS status bar
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');
$today_orders_count = 0;
$today_sales_total = 0.0;
$today_sql = "SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total FROM orders WHERE delivery_address = 'In-store Pickup' AND created_at >= ? AND created_at <= ?";
if ($seller_scope_id !== null) {
    $today_sql .= " AND EXISTS (SELECT 1 FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = orders.id AND p.seller_id = ?)";
    $stmt = mysqli_prepare($conn, $today_sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ssi", $today_start, $today_end, $seller_scope_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $today_orders_count = (int)$row['cnt'];
            $today_sales_total = (float)$row['total'];
        }
        mysqli_stmt_close($stmt);
    }
} else {
    $stmt = mysqli_prepare($conn, $today_sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ss", $today_start, $today_end);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $today_orders_count = (int)$row['cnt'];
            $today_sales_total = (float)$row['total'];
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-in Orders - POS Terminal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --pos-bg: #f8f9fa;
            --pos-card-bg: #ffffff;
            --pos-border: #eaecf0;
            --pos-border-focus: #d0d5dd;
            --pos-primary: #b3261e;
            --pos-primary-hover: #981b15;
            --pos-primary-light: #fff5f5;
            --pos-primary-badge: #fee4e2;
            --pos-text-main: #101828;
            --pos-text-muted: #475467;
            --pos-text-soft: #667085;
            --pos-radius-lg: 18px;
            --pos-radius-md: 14px;
            --pos-radius-sm: 10px;
            --pos-shadow: 0 1px 3px rgba(16, 24, 40, 0.04), 0 1px 2px rgba(16, 24, 40, 0.02);
            --pos-shadow-hover: 0 8px 20px rgba(16, 24, 40, 0.08);
        }

        body.dark-mode {
            --pos-bg: #121826;
            --pos-card-bg: #1e293b;
            --pos-border: #334155;
            --pos-border-focus: #475569;
            --pos-primary-light: #2d1818;
            --pos-primary-badge: #3d1c1b;
            --pos-text-main: #f8fafc;
            --pos-text-muted: #94a3b8;
            --pos-text-soft: #cbd5e1;
            --pos-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
            --pos-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.4);
            background-color: var(--pos-bg) !important;
            color: var(--pos-text-main) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--pos-bg) !important;
        }

        body.dark-mode .admin-topbar {
            background-color: var(--pos-card-bg) !important;
            border-color: var(--pos-border) !important;
            color: var(--pos-text-main) !important;
        }

        body.dark-mode .admin-topbar h1 {
            color: var(--pos-text-main) !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .kiosk-page .admin-main {
            padding: 12px 16px 20px !important;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* Fullscreen POS Layout Container */
        .pos-shell {
            display: flex;
            flex-direction: column;
            gap: 12px;
            height: calc(100vh - 120px);
            min-height: 600px;
        }

        /* Top Bar Controls */
        .pos-top-toolbar {
            background: var(--pos-card-bg);
            border: 1px solid var(--pos-border);
            border-radius: var(--pos-radius-md);
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            box-shadow: var(--pos-shadow);
        }

        .pos-meta-pills {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pos-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #f2f4f7;
            color: var(--pos-text-main);
            border: 1px solid var(--pos-border);
        }

        body.dark-mode .pos-pill {
            background: #0f172a;
        }

        .pos-pill-status {
            background: #ecfdf3;
            color: #027a48;
            border-color: #abefc6;
        }

        body.dark-mode .pos-pill-status {
            background: #064e3b;
            color: #a7f3d0;
            border-color: #059669;
        }

        .pos-search-wrap {
            position: relative;
            flex: 1;
            max-width: 380px;
            min-width: 220px;
        }

        .pos-search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--pos-text-soft);
            font-size: 0.88rem;
            pointer-events: none;
        }

        .pos-search-input {
            width: 100%;
            height: 38px;
            padding-left: 38px;
            padding-right: 14px;
            border-radius: 999px;
            border: 1px solid var(--pos-border);
            background: #f8f9fa;
            color: var(--pos-text-main);
            font-size: 0.86rem;
            font-weight: 500;
            outline: none;
            transition: all 0.2s ease;
        }

        body.dark-mode .pos-search-input {
            background: #0f172a;
        }

        .pos-search-input:focus {
            border-color: var(--pos-primary);
            background: var(--pos-card-bg);
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
        }

        /* Category Horizontal Track */
        .pos-category-track {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding: 2px 2px 8px;
            scrollbar-width: thin;
        }

        .pos-category-track::-webkit-scrollbar {
            height: 4px;
        }

        .pos-category-track::-webkit-scrollbar-thumb {
            background: #d0d5dd;
            border-radius: 4px;
        }

        .pos-category-card {
            min-width: 96px;
            height: 80px;
            background: var(--pos-card-bg);
            border: 1.5px solid var(--pos-border);
            border-radius: var(--pos-radius-md);
            padding: 8px 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
            box-shadow: var(--pos-shadow);
            flex-shrink: 0;
            text-align: center;
        }

        .pos-category-card:hover {
            transform: translateY(-2px);
            border-color: #d0d5dd;
            box-shadow: 0 4px 12px rgba(16, 24, 40, 0.06);
        }

        .pos-cat-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f2f4f7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            color: var(--pos-text-muted);
            margin-bottom: 4px;
            transition: all 0.2s ease;
        }

        body.dark-mode .pos-cat-icon {
            background: #0f172a;
        }

        .pos-cat-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--pos-text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 86px;
            line-height: 1.15;
        }

        .pos-cat-count {
            font-size: 0.68rem;
            font-weight: 500;
            color: var(--pos-text-soft);
            line-height: 1;
            margin-top: 2px;
        }

        .pos-category-card.active {
            border-color: var(--pos-primary);
            background: var(--pos-primary-light);
            box-shadow: 0 2px 8px rgba(179, 38, 30, 0.12);
        }

        .pos-category-card.active .pos-cat-icon {
            background: var(--pos-primary);
            color: #ffffff;
        }

        .pos-category-card.active .pos-cat-title {
            color: var(--pos-primary);
        }

        .pos-category-card.active .pos-cat-count {
            color: var(--pos-primary-hover);
        }

        /* Main Workspace Split Layout */
        .pos-main-grid {
            display: flex;
            gap: 16px;
            flex: 1;
            overflow: hidden;
        }

        .pos-catalog-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .pos-product-scroll {
            flex: 1;
            overflow-y: auto;
            padding-right: 6px;
            padding-bottom: 12px;
        }

        .pos-product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
            gap: 14px;
        }

        /* Product Cards (Mockup Design) */
        .pos-product-card {
            background: var(--pos-card-bg);
            border: 1px solid var(--pos-border);
            border-radius: var(--pos-radius-md);
            padding: 12px;
            display: flex;
            flex-direction: column;
            position: relative;
            cursor: pointer;
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease, border-color 0.2s ease;
            box-shadow: var(--pos-shadow);
            user-select: none;
        }

        .pos-product-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--pos-shadow-hover);
            border-color: #d0d5dd;
        }

        .pos-product-card:active {
            transform: scale(0.97);
        }

        .pos-product-img-box {
            width: 100%;
            height: 115px;
            border-radius: var(--pos-radius-sm);
            background: #f8f9fa;
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
        }

        body.dark-mode .pos-product-img-box {
            background: #0f172a;
        }

        .pos-product-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .pos-product-card:hover .pos-product-img {
            transform: scale(1.06);
        }

        .pos-stock-badge {
            position: absolute;
            top: 6px;
            left: 6px;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(4px);
            border: 1px solid #d0d5dd;
            color: #344054;
            line-height: 1.2;
            z-index: 2;
        }

        body.dark-mode .pos-stock-badge {
            background: rgba(15, 23, 42, 0.9);
            color: #f8fafc;
            border-color: #334155;
        }

        .pos-stock-badge.in-stock {
            color: #027a48;
            border-color: #abefc6;
            background: rgba(236, 253, 243, 0.95);
        }

        .pos-stock-badge.low-stock {
            color: #b54708;
            border-color: #fedf89;
            background: rgba(255, 250, 235, 0.95);
        }

        .pos-stock-badge.sold-out {
            color: #b3261e;
            border-color: #fee4e2;
            background: rgba(254, 243, 242, 0.95);
        }

        .pos-product-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--pos-text-main);
            margin-bottom: 4px;
            line-height: 1.3;
            min-height: 36px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .pos-product-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 6px;
        }

        .pos-product-price {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--pos-primary);
        }

        .pos-add-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--pos-primary);
            color: #ffffff;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            transition: all 0.18s ease;
            box-shadow: 0 2px 6px rgba(179, 38, 30, 0.25);
            cursor: pointer;
        }

        .pos-add-btn:hover {
            background: var(--pos-primary-hover);
            transform: scale(1.08);
        }

        .pos-product-card.sold-out {
            opacity: 0.58;
            cursor: not-allowed;
        }

        .pos-product-card.sold-out .pos-add-btn {
            background: #98a2b3;
            box-shadow: none;
            cursor: not-allowed;
        }

        /* Right Order Panel / Ticket */
        .pos-order-col {
            width: 360px;
            min-width: 360px;
            display: flex;
            flex-direction: column;
        }

        .pos-cart-panel {
            background: var(--pos-card-bg);
            border: 1px solid var(--pos-border);
            border-radius: var(--pos-radius-lg);
            display: flex;
            flex-direction: column;
            height: 100%;
            box-shadow: var(--pos-shadow);
            overflow: hidden;
        }

        .pos-cart-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--pos-border);
            background: var(--pos-card-bg);
        }

        .pos-cart-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .pos-order-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--pos-text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pos-order-num-badge {
            font-size: 0.76rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: #f2f4f7;
            color: var(--pos-text-muted);
            border: 1px solid var(--pos-border);
        }

        body.dark-mode .pos-order-num-badge {
            background: #0f172a;
        }

        /* Order Type & Customer Inputs */
        .pos-order-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pos-type-toggle {
            display: inline-flex;
            background: #f2f4f7;
            border-radius: 8px;
            padding: 2px;
            border: 1px solid var(--pos-border);
        }

        body.dark-mode .pos-type-toggle {
            background: #0f172a;
        }

        .pos-type-btn {
            border: none;
            background: transparent;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 6px;
            color: var(--pos-text-muted);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .pos-type-btn.active {
            background: #ffffff;
            color: var(--pos-text-main);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        body.dark-mode .pos-type-btn.active {
            background: #334155;
            color: #f8fafc;
        }

        .pos-cust-input {
            flex: 1;
            height: 32px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid var(--pos-border);
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--pos-text-main);
            background: #ffffff;
            outline: none;
        }

        body.dark-mode .pos-cust-input {
            background: #0f172a;
        }

        .pos-cust-input:focus {
            border-color: var(--pos-primary);
        }

        /* Cart Body Items */
        .pos-cart-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px 14px;
        }

        .pos-cart-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: var(--pos-radius-sm);
            background: #f8f9fa;
            border: 1px solid var(--pos-border);
            margin-bottom: 8px;
            animation: posFadeIn 0.25s ease-out;
            transition: all 0.2s ease;
        }

        body.dark-mode .pos-cart-item {
            background: #0f172a;
        }

        .pos-item-thumb {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            object-fit: cover;
            background: #ffffff;
            flex-shrink: 0;
            border: 1px solid var(--pos-border);
        }

        .pos-item-info {
            flex: 1;
            min-width: 0;
        }

        .pos-item-title {
            font-size: 0.84rem;
            font-weight: 700;
            color: var(--pos-text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
            margin-bottom: 2px;
        }

        .pos-item-unit-price {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--pos-text-soft);
        }

        /* Stepper Control */
        .pos-stepper {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            padding: 1px;
            overflow: hidden;
        }

        body.dark-mode .pos-stepper {
            background: #1e293b;
        }

        .pos-stepper-btn {
            width: 24px;
            height: 24px;
            border: none;
            background: transparent;
            color: var(--pos-text-main);
            font-size: 0.76rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s;
        }

        .pos-stepper-btn:hover {
            background: #f2f4f7;
        }

        body.dark-mode .pos-stepper-btn:hover {
            background: #334155;
        }

        .pos-stepper-val {
            width: 26px;
            border: none;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--pos-text-main);
            background: transparent;
            outline: none;
        }

        .pos-item-total {
            font-size: 0.88rem;
            font-weight: 800;
            color: var(--pos-text-main);
            min-width: 58px;
            text-align: right;
        }

        .pos-item-del-btn {
            border: none;
            background: transparent;
            color: #98a2b3;
            cursor: pointer;
            padding: 4px;
            font-size: 0.82rem;
            transition: color 0.18s ease;
        }

        .pos-item-del-btn:hover {
            color: var(--pos-primary);
        }

        /* Empty Cart State */
        .pos-cart-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            color: var(--pos-text-soft);
            padding: 24px;
        }

        .pos-cart-empty i {
            font-size: 2.8rem;
            margin-bottom: 12px;
            opacity: 0.35;
        }

        .pos-cart-empty h6 {
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--pos-text-main);
        }

        .pos-cart-empty p {
            font-size: 0.82rem;
            margin: 0;
            max-width: 200px;
        }

        /* Cart Footer & Checkout */
        .pos-cart-footer {
            padding: 14px 16px;
            border-top: 1px solid var(--pos-border);
            background: var(--pos-card-bg);
        }

        .pos-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 0.85rem;
            color: var(--pos-text-muted);
        }

        .pos-summary-row strong {
            color: var(--pos-text-main);
            font-weight: 700;
        }

        .pos-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            margin-top: 6px;
            border-top: 1px dashed var(--pos-border);
            margin-bottom: 12px;
        }

        .pos-total-label {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--pos-text-main);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .pos-total-val {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--pos-primary);
        }

        .pos-checkout-btn {
            width: 100%;
            height: 46px;
            border-radius: var(--pos-radius-sm);
            background: var(--pos-primary);
            color: #ffffff;
            font-size: 0.96rem;
            font-weight: 800;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(179, 38, 30, 0.28);
        }

        .pos-checkout-btn:hover:not(:disabled) {
            background: var(--pos-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(179, 38, 30, 0.35);
        }

        .pos-checkout-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            box-shadow: none;
        }

        /* Bottom Status & Staff Bar */
        .pos-bottom-bar {
            background: var(--pos-card-bg);
            border: 1px solid var(--pos-border);
            border-radius: var(--pos-radius-md);
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.8rem;
            box-shadow: var(--pos-shadow);
            flex-wrap: wrap;
            gap: 8px;
        }

        .pos-staff-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .pos-staff-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: var(--pos-text-muted);
        }

        .pos-staff-chip strong {
            color: var(--pos-text-main);
        }

        @keyframes posFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .pos-shell {
                height: auto;
                min-height: auto;
            }
            .pos-main-grid {
                flex-direction: column;
            }
            .pos-order-col {
                width: 100%;
                min-width: 0;
            }
            .pos-cart-panel {
                height: 480px;
            }
        }
    </style>
</head>
<body class="admin-polish kiosk-page">
    <div class="page-loader">
        <div class="spinner"></div>
    </div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Walk-in Orders (POS)</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="topbar-right">
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main" style="padding: 14px 18px 24px;">
                <div class="pos-shell">
                    
                    <!-- Top Toolbar -->
                    <div class="pos-top-toolbar">
                        <div class="pos-meta-pills">
                            <div class="pos-pill">
                                <i class="fas fa-calendar-alt text-primary"></i>
                                <span id="liveDate">Loading date...</span>
                            </div>
                            <div class="pos-pill">
                                <i class="fas fa-clock text-secondary"></i>
                                <span id="liveTime">00:00:00 AM</span>
                            </div>
                            <div class="pos-pill pos-pill-status">
                                <i class="fas fa-circle" style="font-size: 8px;"></i>
                                <span>Open Order</span>
                            </div>
                        </div>

                        <div class="pos-search-wrap">
                            <i class="fas fa-search"></i>
                            <input id="searchInput" type="text" class="pos-search-input" placeholder="Search menu item or lechon...">
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <button id="clearCartBtn" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold">
                                <i class="fas fa-trash-alt me-1"></i> Clear Order
                            </button>
                            <button id="refreshProductsBtn" class="btn btn-sm btn-outline-secondary rounded-circle" style="width: 34px; height: 34px; padding: 0;" title="Reload Catalog">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Category Horizontal Cards Track -->
                    <div id="categoryTrack" class="pos-category-track">
                        <!-- Populated dynamically with icon cards -->
                    </div>

                    <!-- Main Split Layout -->
                    <div class="pos-main-grid">
                        
                        <!-- Catalog Left Column -->
                        <div class="pos-catalog-col">
                            <div class="pos-product-scroll">
                                <div id="productGrid" class="pos-product-grid">
                                    <!-- Rendered dynamically -->
                                </div>
                            </div>
                        </div>

                        <!-- Order Ticket Right Column -->
                        <div class="pos-order-col">
                            <div class="pos-cart-panel">
                                <div class="pos-cart-header">
                                    <div class="pos-cart-title-row">
                                        <div class="pos-order-title">
                                            <i class="fas fa-receipt text-primary"></i>
                                            <span>Current Order</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="pos-order-num-badge" id="orderNumBadge">Ticket #<?= str_pad((string)($today_orders_count + 1), 3, '0', STR_PAD_LEFT) ?></span>
                                            <span id="cartCountBadge" class="badge bg-danger rounded-pill">0</span>
                                        </div>
                                    </div>
                                    <div class="pos-order-controls">
                                        <div class="pos-type-toggle">
                                            <button type="button" class="pos-type-btn active" data-type="Dine In" onclick="setOrderType('Dine In')">Dine In</button>
                                            <button type="button" class="pos-type-btn" data-type="Take Out" onclick="setOrderType('Take Out')">Take Out</button>
                                        </div>
                                        <input type="text" id="custNameInput" class="pos-cust-input" placeholder="Customer Name (optional)" value="Walk-in Customer">
                                    </div>
                                </div>

                                <div id="cartBody" class="pos-cart-body">
                                    <div class="pos-cart-empty">
                                        <i class="fas fa-shopping-basket"></i>
                                        <h6>Order is Empty</h6>
                                        <p>Tap menu items on the left to add them to this order.</p>
                                    </div>
                                </div>

                                <div class="pos-cart-footer">
                                    <div class="pos-summary-row">
                                        <span>Subtotal</span>
                                        <strong id="subtotal">&#8369;0.00</strong>
                                    </div>
                                    <div class="pos-summary-row">
                                        <span>VAT (12%)</span>
                                        <strong id="vatAmount">&#8369;0.00</strong>
                                    </div>
                                    <div class="pos-total-row">
                                        <span class="pos-total-label">Total Amount</span>
                                        <span class="pos-total-val" id="grandTotal">&#8369;0.00</span>
                                    </div>
                                    <?php if (!$can_create_orders): ?>
                                        <div class="small text-danger mb-2 text-center">View-only mode. Requires <strong>orders.create</strong> permission.</div>
                                    <?php endif; ?>
                                    <button id="checkoutBtn" class="pos-checkout-btn" disabled>
                                        <i class="fas fa-cash-register"></i>
                                        <span>Place Order</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Bottom Staff & POS Status Bar -->
                    <div class="pos-bottom-bar">
                        <div class="pos-staff-group">
                            <div class="pos-staff-chip">
                                <i class="fas fa-user-circle text-primary"></i>
                                <span>Cashier: <strong><?= htmlspecialchars($admin_info['full_name']); ?></strong></span>
                            </div>
                            <div class="pos-staff-chip">
                                <i class="fas fa-store text-secondary"></i>
                                <span>Branch: <strong><?= htmlspecialchars($receipt_business['branch_name'] ?? 'Main Store'); ?></strong></span>
                            </div>
                            <div class="pos-staff-chip">
                                <i class="fas fa-receipt text-success"></i>
                                <span>Today's Orders: <strong id="todayOrdersCountDisplay"><?= number_format($today_orders_count); ?></strong> (&#8369;<span id="todaySalesDisplay"><?= number_format($today_sales_total, 2); ?></span>)</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-sm btn-outline-secondary px-3" onclick="reprintLastReceipt()">
                                <i class="fas fa-print me-1"></i> Last Receipt
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="admin.js"></script>
    <script>
        const kioskMeta = {
            storeName: <?php echo json_encode($receipt_business['business_name'] ?? 'Lechon Delights'); ?>,
            branch: <?php echo json_encode($receipt_business['branch_name'] ?? 'Walk-in Counter'); ?>,
            address: <?php echo json_encode($receipt_business['address'] ?? 'In-store Pickup'); ?>,
            phone: <?php echo json_encode($receipt_business['phone'] ?? ''); ?>,
            taxId: <?php echo json_encode($receipt_business['tax_id'] ?? ''); ?>,
            businessStyle: <?php echo json_encode($receipt_business['business_style'] ?? ''); ?>,
            permitNo: <?php echo json_encode($receipt_business['permit_no'] ?? ''); ?>,
            ptuNo: <?php echo json_encode($receipt_business['ptu_no'] ?? ''); ?>,
            accreditationNo: <?php echo json_encode($receipt_business['accreditation_no'] ?? ''); ?>,
            serialNo: <?php echo json_encode($receipt_business['serial_no'] ?? ''); ?>,
            footerText: <?php echo json_encode($receipt_business['footer_text'] ?? ''); ?>,
            cashier: <?php echo json_encode($admin_info['full_name']); ?>
        };
        const kioskVatRate = 0.12;
        const kioskCsrfToken = <?php echo json_encode($csrf_token); ?>;
        const canCreateOrders = <?php echo $can_create_orders ? 'true' : 'false'; ?>;

        let products = [];
        let cart = {};
        let categories = [];
        let activeCategory = '';
        let currentOrderType = 'Dine In';
        let lastCompletedOrder = null;

        // Theme Toggler Logic
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const themeIcon = themeToggler.querySelector('i');

        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Live Date & Time Clock
        function updateLiveClock() {
            const now = new Date();
            const dateOpts = { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' };
            const timeOpts = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            const dateEl = document.getElementById('liveDate');
            const timeEl = document.getElementById('liveTime');
            if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', dateOpts);
            if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-US', timeOpts);
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock();

        // Number format helpers
        const peso = v => '\u20B1' + (Number(v)||0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const kioskEscapeHtml = (str) => String(str ?? '').replace(/[&<>'"]/g, (ch) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[ch]));

        // Order Type switcher
        function setOrderType(type) {
            currentOrderType = type;
            document.querySelectorAll('.pos-type-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.type === type);
            });
        }

        // Category Icon Mapper
        function getCategoryIcon(catName) {
            const c = (catName || '').toLowerCase();
            if (c.includes('whole') || c.includes('lechon') || c.includes('pork') || c.includes('meat') || c.includes('parts')) return 'fa-drumstick-bite';
            if (c.includes('belly')) return 'fa-bacon';
            if (c.includes('platter')) return 'fa-plate-wheat';
            if (c.includes('rice') || c.includes('meal')) return 'fa-bowl-rice';
            if (c.includes('side') || c.includes('dish')) return 'fa-utensils';
            if (c.includes('drink') || c.includes('beverage')) return 'fa-glass-water';
            if (c.includes('dessert') || c.includes('cake') || c.includes('sweet')) return 'fa-ice-cream';
            if (c.includes('bread') || c.includes('pastry') || c.includes('donut')) return 'fa-bread-slice';
            if (c.includes('fried') || c.includes('chicken') || c.includes('tie')) return 'fa-burger';
            return 'fa-boxes-stacked';
        }

        function computeKioskTotals(items) {
            const subtotal = items.reduce((sum, item) => sum + (Number(item.total) || 0), 0);
            const vatAmount = Math.round(subtotal * kioskVatRate * 100) / 100;
            const totalAmount = subtotal + vatAmount;
            return { subtotal, vatAmount, totalAmount };
        }

        // Receipt Printers
        function buildReceiptHtml(receiptData) {
            const totals = {
                subtotal: Number(receiptData.subtotal || 0),
                vatAmount: Number(receiptData.vat_amount || 0),
                totalAmount: Number(receiptData.total_amount || 0),
                vatableSales: Number(receiptData.vatable_sales || receiptData.subtotal || 0),
                vatExemptSales: Number(receiptData.vat_exempt_sales || 0),
                zeroRatedSales: Number(receiptData.zero_rated_sales || 0)
            };
            const itemsHtml = receiptData.items.map((it) => `
                <tr>
                    <td>${kioskEscapeHtml(it.name)}</td>
                    <td>${it.quantity}</td>
                    <td>${peso(it.price)}</td>
                    <td>${peso(it.total)}</td>
                </tr>
            `).join('');
            const footerHtml = kioskMeta.footerText
                ? kioskMeta.footerText.split(/\r?\n/).filter(Boolean).map((line) => `<div>${kioskEscapeHtml(line)}</div>`).join('')
                : '<div>Thank you for dining with us!</div><div class="muted">Please keep this invoice for your records.</div>';

            return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Invoice ${kioskEscapeHtml(receiptData.order_number)}</title>
    <style>
        body { margin: 0; padding: 16px; background: #eef2f7; color: #111827; font-family: Arial, sans-serif; }
        .screen-only { display: flex; gap: 8px; justify-content: center; margin-bottom: 14px; }
        .btn { border: 1px solid #d1d5db; background: #fff; padding: 9px 14px; border-radius: 999px; cursor: pointer; font-size: 12px; font-weight: 700; }
        .receipt-wrap { width: 360px; margin: 0 auto; background: #fff; color: #111; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16); padding: 18px 18px 22px; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.32; }
        .center { text-align: center; }
        .muted { color: #4b5563; }
        .business-name { margin: 0 0 4px; font-size: 24px; line-height: 1.1; font-weight: 700; }
        .heading { text-transform: uppercase; letter-spacing: 0.08em; font-size: 13px; margin: 6px 0 0; font-weight: 700; }
        .line { border-top: 1px dashed #6b7280; margin: 10px 0; }
        .meta-row, .amount-row { display: flex; justify-content: space-between; gap: 12px; margin: 3px 0; }
        .meta-row span:first-child, .amount-row span:first-child { min-width: 116px; }
        .section-title { text-align: center; font-size: 12px; font-weight: 700; letter-spacing: 0.12em; margin: 10px 0 6px; }
        .receipt-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .receipt-table th, .receipt-table td { padding: 3px 0; vertical-align: top; }
        .receipt-table th { border-bottom: 1px solid #111; font-weight: 700; }
        .receipt-table th:nth-child(2), .receipt-table td:nth-child(2) { width: 42px; text-align: center; }
        .receipt-table th:nth-child(3), .receipt-table th:nth-child(4), .receipt-table td:nth-child(3), .receipt-table td:nth-child(4) { width: 74px; text-align: right; }
        .receipt-table td:first-child { padding-right: 8px; }
        .grand-total { font-weight: 700; font-size: 13px; }
        .bottom-note { text-align: center; margin-top: 12px; }
        .claim { text-align: center; margin-top: 16px; font-size: 18px; font-weight: 700; letter-spacing: 0.08em; }
        @media print {
            .screen-only { display: none !important; }
            body { padding: 0; background: #fff; }
            .receipt-wrap { width: auto; box-shadow: none; padding: 0; }
            @page { size: auto; margin: 6mm; }
        }
    </style>
</head>
<body>
    <div class="screen-only">
        <button class="btn" onclick="window.print()">Print Sales Invoice</button>
        <button class="btn" onclick="window.close()">Close</button>
    </div>
    <div class="receipt-wrap">
        <div class="center">
            <div class="business-name">${kioskEscapeHtml(kioskMeta.storeName)}</div>
            ${kioskMeta.branch ? `<div class="muted">${kioskEscapeHtml(kioskMeta.branch)}</div>` : ''}
            <div class="muted">${kioskEscapeHtml(kioskMeta.address)}</div>
            ${kioskMeta.phone ? `<div class="muted">Tel: ${kioskEscapeHtml(kioskMeta.phone)}</div>` : ''}
            ${kioskMeta.taxId ? `<div class="muted">VAT Reg TIN: ${kioskEscapeHtml(kioskMeta.taxId)}</div>` : ''}
            ${kioskMeta.businessStyle ? `<div class="muted">Business Style: ${kioskEscapeHtml(kioskMeta.businessStyle)}</div>` : ''}
            ${kioskMeta.permitNo ? `<div class="muted">Permit No.: ${kioskEscapeHtml(kioskMeta.permitNo)}</div>` : ''}
            ${kioskMeta.ptuNo ? `<div class="muted">PTU No.: ${kioskEscapeHtml(kioskMeta.ptuNo)}</div>` : ''}
            ${kioskMeta.accreditationNo ? `<div class="muted">Accreditation No.: ${kioskEscapeHtml(kioskMeta.accreditationNo)}</div>` : ''}
            ${kioskMeta.serialNo ? `<div class="muted">Serial No.: ${kioskEscapeHtml(kioskMeta.serialNo)}</div>` : ''}
            <div class="heading">Sales Invoice</div>
        </div>

        <div class="line"></div>

        <div class="meta-row"><span>Invoice No.</span><span>${kioskEscapeHtml(receiptData.order_number)}</span></div>
        <div class="meta-row"><span>Type</span><span>${kioskEscapeHtml(receiptData.order_type || 'Walk-in')}</span></div>
        <div class="meta-row"><span>Date</span><span>${kioskEscapeHtml(receiptData.date_time)}</span></div>
        <div class="meta-row"><span>Cashier</span><span>${kioskEscapeHtml(receiptData.cashier)}</span></div>
        <div class="meta-row"><span>Payment</span><span>Cash</span></div>

        <div class="line"></div>

        <div class="section-title">WALK-IN COUNTER</div>

        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>${itemsHtml}</tbody>
        </table>

        <div class="line"></div>

        <div class="amount-row"><span>Subtotal</span><span>${peso(totals.subtotal)}</span></div>
        <div class="amount-row"><span>Total Amount Due</span><span class="grand-total">${peso(totals.totalAmount)}</span></div>
        <div class="amount-row"><span>Cash</span><span>${peso(receiptData.cash_received || 0)}</span></div>
        <div class="amount-row"><span>Change</span><span>${peso(receiptData.change_amount || 0)}</span></div>
        <div class="amount-row"><span>VATable Sales</span><span>${peso(totals.vatableSales)}</span></div>
        <div class="amount-row"><span>VAT-Exempt Sales</span><span>${peso(totals.vatExemptSales)}</span></div>
        <div class="amount-row"><span>Zero Rated Sales</span><span>${peso(totals.zeroRatedSales)}</span></div>
        <div class="amount-row"><span>VAT Amount (12%)</span><span>${peso(totals.vatAmount)}</span></div>

        <div class="line"></div>

        <div class="section-title">CUSTOMER</div>
        <div class="meta-row"><span>Name</span><span>${kioskEscapeHtml(receiptData.customer_name || 'Walk-in Customer')}</span></div>
        <div class="meta-row"><span>Signature</span><span>________________</span></div>

        <div class="bottom-note">
            ${footerHtml}
        </div>

        <div class="claim">
            CLAIM#<br>${kioskEscapeHtml((receiptData.claim_code || receiptData.order_number || '').replace(/[^A-Za-z0-9]/g, '').slice(-8) || 'N/A')}
        </div>
    </div>
</body>
</html>`;
        }

        function openPrintPreview(html, autoPrint = false, title = 'Print Preview') {
            const printWindow = window.open('', '_blank', 'width=900,height=850');
            if (!printWindow) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Popup Blocked',
                    text: `Unable to open ${title}. Please allow popups for printing receipts.`
                });
                return;
            }

            printWindow.document.open();
            printWindow.document.write(html);
            printWindow.document.close();
            if (autoPrint) {
                printWindow.focus();
                setTimeout(() => {
                    try { printWindow.print(); } catch (e) {}
                }, 300);
            }
        }

        function generateReceipt(receiptData, autoPrint = false) {
            openPrintPreview(buildReceiptHtml(receiptData), autoPrint, 'receipt');
        }

        function reprintLastReceipt() {
            if (!lastCompletedOrder) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Recent Receipt',
                    text: 'No completed walk-in orders in this active session yet.'
                });
                return;
            }
            generateReceipt(lastCompletedOrder, false);
        }

        // Fetch Products and Categories
        async function fetchProducts() {
            const grid = document.getElementById('productGrid');
            try {
                const res = await fetch('../get_products_for_kiosk.php', {
                    headers: { 'Accept': 'application/json' }
                });
                const text = await res.text();
                let data = {};
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Unexpected response while loading products.');
                }

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Failed to load products.');
                }

                products = Array.isArray(data.products) ? data.products : [];
                categories = Array.isArray(data.categories) ? data.categories : [];
                renderCategories();
                renderProducts();
            } catch (error) {
                console.error('Error fetching products:', error);
                if (grid) {
                    grid.innerHTML = `<div class="col-12 text-center py-5 text-danger">${kioskEscapeHtml(error.message || 'Failed to load products.')}</div>`;
                }
                Swal.fire('Error', error.message || 'Failed to load products', 'error');
            }
        }

        // Render Horizontal Category Cards
        function renderCategories() {
            const track = document.getElementById('categoryTrack');
            track.innerHTML = '';

            // Compute item counts per category
            const counts = {};
            products.forEach(p => {
                const cat = p.category || 'Other';
                counts[cat] = (counts[cat] || 0) + 1;
            });

            // 1. "All Menu" card
            const allCard = document.createElement('div');
            allCard.className = 'pos-category-card' + (!activeCategory ? ' active' : '');
            allCard.innerHTML = `
                <div class="pos-cat-icon"><i class="fas fa-boxes-stacked"></i></div>
                <div class="pos-cat-title">All Menu</div>
                <div class="pos-cat-count">${products.length} Items</div>
            `;
            allCard.onclick = () => {
                activeCategory = '';
                renderCategories();
                renderProducts();
            };
            track.appendChild(allCard);

            // 2. Individual Category Cards
            categories.forEach(cat => {
                const card = document.createElement('div');
                const isActive = (activeCategory === cat);
                card.className = 'pos-category-card' + (isActive ? ' active' : '');
                const iconClass = getCategoryIcon(cat);
                const count = counts[cat] || 0;
                card.innerHTML = `
                    <div class="pos-cat-icon"><i class="fas ${iconClass}"></i></div>
                    <div class="pos-cat-title" title="${kioskEscapeHtml(cat)}">${kioskEscapeHtml(cat)}</div>
                    <div class="pos-cat-count">${count} Items</div>
                `;
                card.onclick = () => {
                    activeCategory = (activeCategory === cat ? '' : cat);
                    renderCategories();
                    renderProducts();
                };
                track.appendChild(card);
            });
        }

        // Render Product Cards Grid
        function renderProducts() {
            const grid = document.getElementById('productGrid');
            const q = document.getElementById('searchInput').value.trim().toLowerCase();
            grid.innerHTML = '';

            const filtered = products
                .filter(p => (!activeCategory || p.category === activeCategory))
                .filter(p => !q || p.name.toLowerCase().includes(q) || (p.category && p.category.toLowerCase().includes(q)));

            if (filtered.length === 0) {
                grid.innerHTML = `
                    <div class="col-12 text-center text-muted py-5" style="grid-column: 1 / -1;">
                        <i class="fas fa-search fa-3x mb-3 text-secondary" style="opacity: 0.3;"></i>
                        <p class="fw-semibold">No menu items found</p>
                    </div>`;
                return;
            }

            filtered.forEach(p => {
                const soldOut = !p.is_active || (Number(p.stock) <= 0);
                const card = document.createElement('div');
                card.className = 'pos-product-card' + (soldOut ? ' sold-out' : '');

                let badgeClass = 'in-stock';
                let badgeText = p.stock + ' in stock';

                if (soldOut) {
                    badgeClass = 'sold-out';
                    badgeText = 'Sold Out';
                } else if (p.stock <= (p.min_stock_level || 5)) {
                    badgeClass = 'low-stock';
                    badgeText = p.stock + ' left';
                }

                card.innerHTML = `
                    <div class="pos-product-img-box">
                        <span class="pos-stock-badge ${badgeClass}">${badgeText}</span>
                        <img src="${p.image ? '../' + p.image : '../images/placeholder.png'}" 
                             onerror="this.src='../images/placeholder.png'" 
                             alt="${kioskEscapeHtml(p.name)}" 
                             class="pos-product-img">
                    </div>
                    <div class="pos-product-title" title="${kioskEscapeHtml(p.name)}">${kioskEscapeHtml(p.name)}</div>
                    <div class="pos-product-bottom">
                        <div class="pos-product-price">${peso(p.price)}</div>
                        <button class="pos-add-btn" ${soldOut ? 'disabled' : ''} title="Add to Order">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                `;

                if (!soldOut) {
                    card.addEventListener('click', () => addToCart(p, card));
                }

                const addBtn = card.querySelector('.pos-add-btn');
                if (addBtn && !soldOut) {
                    addBtn.onclick = (e) => {
                        e.stopPropagation();
                        addToCart(p, card);
                    };
                }

                grid.appendChild(card);
            });
        }

        // Add to Cart
        function addToCart(p, sourceCard = null) {
            const key = String(p.id);
            const currentQty = cart[key]?.quantity || 0;

            if (sourceCard) {
                sourceCard.style.transform = 'scale(0.96)';
                setTimeout(() => sourceCard.style.transform = '', 120);
            }

            if (currentQty + 1 > Number(p.stock)) {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 1800, timerProgressBar: true
                });
                Toast.fire({ icon: 'warning', title: 'Insufficient Stock' });
                return;
            }

            cart[key] = cart[key] || {
                id: p.id,
                product_id: p.product_id,
                name: p.name,
                price: Number(p.price),
                quantity: 0,
                image: p.image
            };
            cart[key].quantity += 1;
            renderCart();
        }

        // Adjust Quantity
        function changeQty(key, delta) {
            const item = cart[key];
            if (!item) return;
            const product = products.find(p => String(p.id) === String(key));
            const newQty = item.quantity + delta;
            if (newQty <= 0) {
                delete cart[key];
                renderCart();
                return;
            }
            if (product && newQty > Number(product.stock)) {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 1800
                });
                Toast.fire({ icon: 'warning', title: 'Max available stock reached' });
                return;
            }
            item.quantity = newQty;
            renderCart();
        }

        function removeItem(key) {
            delete cart[key];
            renderCart();
        }

        // Render Cart Panel
        function renderCart() {
            const body = document.getElementById('cartBody');
            body.innerHTML = '';
            let count = 0;
            let subtotal = 0;
            const items = Object.values(cart);

            if (items.length === 0) {
                body.innerHTML = `
                    <div class="pos-cart-empty">
                        <i class="fas fa-shopping-basket"></i>
                        <h6>Order is Empty</h6>
                        <p>Tap menu items on the left to add them to this order.</p>
                    </div>`;
                document.getElementById('cartCountBadge').textContent = '0';
                document.getElementById('subtotal').textContent = peso(0);
                document.getElementById('vatAmount').textContent = peso(0);
                document.getElementById('grandTotal').textContent = peso(0);
                document.getElementById('checkoutBtn').disabled = true;
                return;
            }

            items.forEach(it => {
                count += it.quantity;
                subtotal += it.price * it.quantity;
                const row = document.createElement('div');
                row.className = 'pos-cart-item';
                row.innerHTML = `
                    <img src="${it.image ? '../' + it.image : '../images/placeholder.png'}" 
                         onerror="this.src='../images/placeholder.png'" 
                         alt="${kioskEscapeHtml(it.name)}" 
                         class="pos-item-thumb">
                    <div class="pos-item-info">
                        <div class="pos-item-title" title="${kioskEscapeHtml(it.name)}">${kioskEscapeHtml(it.name)}</div>
                        <div class="pos-item-unit-price">${peso(it.price)}</div>
                    </div>
                    <div class="pos-stepper">
                        <button class="pos-stepper-btn" onclick="changeQty('${it.id}', -1)"><i class="fas fa-minus"></i></button>
                        <input type="text" class="pos-stepper-val" value="${it.quantity}" readonly>
                        <button class="pos-stepper-btn" onclick="changeQty('${it.id}', 1)"><i class="fas fa-plus"></i></button>
                    </div>
                    <div class="pos-item-total">${peso(it.price * it.quantity)}</div>
                    <button class="pos-item-del-btn" onclick="removeItem('${it.id}')" title="Remove">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                `;
                body.appendChild(row);
            });

            const totals = computeKioskTotals(items.map(it => ({
                total: Number(it.price) * Number(it.quantity)
            })));

            document.getElementById('cartCountBadge').textContent = count;
            document.getElementById('subtotal').textContent = peso(subtotal);
            document.getElementById('vatAmount').textContent = peso(totals.vatAmount);
            document.getElementById('grandTotal').textContent = peso(totals.totalAmount);
            document.getElementById('checkoutBtn').disabled = !canCreateOrders;
        }

        // Checkout Modal & Processing
        async function checkout() {
            if (!canCreateOrders) {
                Swal.fire({
                    icon: 'info',
                    title: 'View-Only Access',
                    text: 'Your current account does not have permission to create orders.'
                });
                return;
            }

            const items = Object.values(cart).map(it => ({
                product_id: it.product_id || it.id,
                id: it.id,
                quantity: it.quantity
            }));
            if (items.length === 0) return;

            const receiptItems = Object.values(cart).map(it => ({
                name: it.name,
                quantity: Number(it.quantity),
                price: Number(it.price),
                total: Number(it.price) * Number(it.quantity)
            }));
            const estimatedTotals = computeKioskTotals(receiptItems);
            const estimatedTotal = estimatedTotals.totalAmount;
            const currentCustomer = (document.getElementById('custNameInput')?.value || '').trim() || 'Walk-in Customer';

            const paymentPrompt = await Swal.fire({
                title: 'Process Payment',
                html: `
                    <div style="text-align:left; font-size: 0.9rem; margin-bottom: 12px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom: 4px;">
                            <span>Order Type:</span><strong>${kioskEscapeHtml(currentOrderType)}</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom: 4px;">
                            <span>Total Due:</span><strong style="color:#b3261e; font-size:1.15rem;">${peso(estimatedTotal)}</strong>
                        </div>
                    </div>
                    <div style="text-align:left; margin-bottom: 6px;">
                        <label style="font-size:0.8rem; font-weight:700; color:#344054;">Customer Name</label>
                        <input id="swalCustomerName" class="swal2-input" style="width:100%; margin:4px 0 10px;" placeholder="Customer name" value="${kioskEscapeHtml(currentCustomer)}">
                    </div>
                    <div style="text-align:left; margin-bottom: 6px;">
                        <label style="font-size:0.8rem; font-weight:700; color:#344054;">Cash Received (&#8369;)</label>
                        <input id="swalCashReceived" class="swal2-input" style="width:100%; margin:4px 0 8px;" placeholder="0.00" type="number" min="0" step="0.01" autofocus>
                    </div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px;">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setCashPrompt(${estimatedTotal})">Exact (${peso(estimatedTotal)})</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addCashPrompt(50)">+50</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addCashPrompt(100)">+100</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addCashPrompt(500)">+500</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addCashPrompt(1000)">+1000</button>
                    </div>
                    <div id="swalChangeBox" style="background:#f8f9fa; border:1px solid #eaecf0; border-radius:8px; padding:10px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:0.88rem; font-weight:600; color:#475467;">Estimated Change:</span>
                        <strong id="swalChangeVal" style="font-size:1.1rem; color:#027a48;">&#8369;0.00</strong>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check me-1"></i> Complete & Print',
                confirmButtonColor: '#b3261e',
                cancelButtonColor: '#6c757d',
                didOpen: () => {
                    const cashInput = document.getElementById('swalCashReceived');
                    const changeVal = document.getElementById('swalChangeVal');
                    if (cashInput) {
                        cashInput.addEventListener('input', () => {
                            const val = Number(cashInput.value || 0);
                            const change = Math.max(0, val - estimatedTotal);
                            if (changeVal) {
                                changeVal.textContent = peso(change);
                                changeVal.style.color = (val >= estimatedTotal) ? '#027a48' : '#b3261e';
                            }
                        });
                    }
                },
                focusConfirm: false,
                preConfirm: () => {
                    const customerNameInput = document.getElementById('swalCustomerName');
                    const cashReceivedInput = document.getElementById('swalCashReceived');
                    const customerName = (customerNameInput?.value || '').trim() || 'Walk-in Customer';
                    const cashReceived = Number(cashReceivedInput?.value || 0);

                    if (!Number.isFinite(cashReceived) || cashReceived <= 0) {
                        Swal.showValidationMessage('Please enter a valid cash received amount.');
                        return false;
                    }

                    if (cashReceived < estimatedTotal) {
                        Swal.showValidationMessage(`Cash received is less than total due (${peso(estimatedTotal)}).`);
                        return false;
                    }

                    return {
                        customer_name: customerName,
                        cash_received: cashReceived
                    };
                }
            });

            if (!paymentPrompt.isConfirmed) return;
            const customer_name = paymentPrompt.value.customer_name;
            const cash_received = Number(paymentPrompt.value.cash_received || 0);

            // Update panel customer input
            const custInput = document.getElementById('custNameInput');
            if (custInput) custInput.value = customer_name;

            Swal.fire({
                title: 'Processing Order...',
                text: 'Saving walk-in transaction',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch('../create_walkin_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': kioskCsrfToken
                    },
                    body: JSON.stringify({
                        customer_name,
                        items,
                        csrf_token: kioskCsrfToken
                    })
                });

                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    const msg = text ? text.substring(0, 100) : "Empty response from server";
                    throw new Error("Server Error: " + msg);
                }

                if (!data.success) throw new Error(data.message || 'Failed to create order');
                const finalTotal = Number(data.total_amount || estimatedTotal);
                const change_amount = Math.max(0, cash_received - finalTotal);
                const completedOrderData = {
                    order_id: Number(data.order_id || 0),
                    order_number: String(data.order_number || ''),
                    order_type: currentOrderType,
                    subtotal: Number(data.subtotal || estimatedTotals.subtotal),
                    vat_amount: Number(data.vat_amount || estimatedTotals.vatAmount),
                    vatable_sales: Number(data.vatable_sales || estimatedTotals.subtotal),
                    vat_exempt_sales: 0,
                    zero_rated_sales: 0,
                    total_amount: finalTotal,
                    customer_name: customer_name || 'Walk-in Customer',
                    cashier: kioskMeta.cashier,
                    cash_received,
                    change_amount,
                    date_time: new Date().toLocaleString('en-PH', {
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    }),
                    items: receiptItems
                };

                lastCompletedOrder = completedOrderData;

                // Open receipt popup
                generateReceipt(completedOrderData, false);

                // Reset cart
                cart = {};
                renderCart();
                fetchProducts(); // Refresh stock

                // Update bottom counter
                const todayOrdersEl = document.getElementById('todayOrdersCountDisplay');
                if (todayOrdersEl) {
                    const currentCnt = parseInt(todayOrdersEl.textContent.replace(/,/g, ''), 10) || 0;
                    todayOrdersEl.textContent = (currentCnt + 1).toLocaleString();
                }

                const actionPrompt = await Swal.fire({
                    icon: 'success',
                    title: 'Order Completed!',
                    html: `
                        <div style="font-size:0.95rem; line-height:1.5;">
                            Order <b>#${data.order_number}</b> recorded.<br>
                            Total: <b>${peso(finalTotal)}</b> | Change: <b style="color:#027a48;">${peso(change_amount)}</b>
                        </div>
                    `,
                    confirmButtonColor: '#b3261e',
                    confirmButtonText: '<i class="fas fa-print me-1"></i> Print Invoice Again',
                    showCancelButton: true,
                    cancelButtonText: 'Next Customer'
                });

                if (actionPrompt.isConfirmed) {
                    generateReceipt(completedOrderData, true);
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Checkout Failed', text: e.message });
            }
        }

        // Helper functions for cash prompt
        window.setCashPrompt = function(amount) {
            const input = document.getElementById('swalCashReceived');
            if (input) {
                input.value = amount.toFixed(2);
                input.dispatchEvent(new Event('input'));
            }
        };

        window.addCashPrompt = function(delta) {
            const input = document.getElementById('swalCashReceived');
            if (input) {
                const current = Number(input.value || 0);
                input.value = (current + delta).toFixed(2);
                input.dispatchEvent(new Event('input'));
            }
        };

        // Event Listeners
        document.getElementById('checkoutBtn').addEventListener('click', checkout);

        document.getElementById('clearCartBtn').addEventListener('click', () => {
            if (Object.keys(cart).length > 0) {
                Swal.fire({
                    title: 'Clear Current Order?',
                    text: 'Remove all selected items from this order?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#b3261e',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, clear it'
                }).then((result) => {
                    if (result.isConfirmed) {
                        cart = {};
                        renderCart();
                    }
                });
            }
        });

        document.getElementById('searchInput').addEventListener('input', renderProducts);
        document.getElementById('refreshProductsBtn').addEventListener('click', () => {
            fetchProducts();
            const Toast = Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 1500
            });
            Toast.fire({ icon: 'success', title: 'Catalog refreshed' });
        });

        // Initial Load
        fetchProducts();
    </script>
</body>
</html>
