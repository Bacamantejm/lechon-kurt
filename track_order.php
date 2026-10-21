<?php
session_start();
require_once 'includes/config.php';
require_once 'logistics_service.php';

$google_maps_api_key = function_exists('getGoogleMapsApiKey')
    ? getGoogleMapsApiKey()
    : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));
$google_geocoding_enabled = function_exists('shouldUseGoogleGeocoding') ? shouldUseGoogleGeocoding() : true;

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: register.php?mode=login#login&redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$user_id = $_SESSION['user_id'];

if ($order_id === 0) {
    die("Invalid Order ID.");
}

// Verify order ownership and get details
$order_query = "SELECT * FROM orders WHERE id = ? AND user_id = ?";
$order_stmt = $conn->prepare($order_query);
$order_stmt->bind_param("ii", $order_id, $user_id);
$order_stmt->execute();
$order_result = $order_stmt->get_result();
$order = $order_result->fetch_assoc();
$order_stmt->close();

if (!$order) {
    die("Order not found or you do not have permission to view it.");
}

$is_pickup = (strtolower(trim((string)($order['delivery_option'] ?? 'delivery'))) === 'pickup');

// Fetch order items with product images
$order_items = [];
$items_stmt = $conn->prepare("SELECT oi.*, p.image as product_image FROM order_items oi LEFT JOIN products p ON (p.product_id = oi.product_id OR p.id = oi.product_id) WHERE oi.order_id = ?");
if ($items_stmt) {
    $items_stmt->bind_param("i", $order_id);
    $items_stmt->execute();
    $items_res = $items_stmt->get_result();
    while ($row = $items_res->fetch_assoc()) {
        $order_items[] = $row;
    }
    $items_stmt->close();
}

// Fetch store / fulfillment branch details
$store_details = null;
if (!empty($order['pickup_location'])) {
    $store_stmt = $conn->prepare("SELECT * FROM store_locations WHERE store_id = ? OR id = ? LIMIT 1");
    if ($store_stmt) {
        $store_stmt->bind_param("ii", $order['pickup_location'], $order['pickup_location']);
        $store_stmt->execute();
        $store_details = $store_stmt->get_result()->fetch_assoc();
        $store_stmt->close();
    }
}
if (!$store_details) {
    $def_store_res = $conn->query("SELECT * FROM store_locations WHERE is_active = 1 LIMIT 1");
    if ($def_store_res) {
        $store_details = $def_store_res->fetch_assoc();
    }
}

// Get logistics tracking info (for delivery orders)
$logisticsService = new LogisticsService($conn);
$tracking_info = $logisticsService->getTrackingByOrderId($order_id);

$initial_proof_path = $tracking_info['proof_of_delivery_path'] ?? null;
if (empty($initial_proof_path)) {
    $pod_chk = $conn->prepare("SELECT photo_path FROM proof_of_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    if ($pod_chk) {
        $pod_chk->bind_param("i", $order_id);
        $pod_chk->execute();
        $pod_res = $pod_chk->get_result();
        if ($pod_res && $pod_row = $pod_res->fetch_assoc()) {
            $initial_proof_path = $pod_row['photo_path'];
        }
        $pod_chk->close();
    }
}
if (!empty($initial_proof_path)) {
    $initial_proof_path = 'uploads/proof_of_delivery/' . basename($initial_proof_path);
}

// Check if user has already reviewed the driver/delivery
$existing_delivery_review = null;
$rev_chk = $conn->prepare("SELECT rating, comment, created_at FROM delivery_reviews WHERE order_id = ? AND user_id = ? LIMIT 1");
if ($rev_chk) {
    $rev_chk->bind_param("ii", $order_id, $user_id);
    $rev_chk->execute();
    $rev_res = $rev_chk->get_result();
    $existing_delivery_review = $rev_res ? $rev_res->fetch_assoc() : null;
    $rev_chk->close();
}

$page_title = ($is_pickup ? "Pick-up Order #" : "Track Order #") . $order['order_number'];
include 'includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
    --fp-primary: #b3261e;
    --fp-primary-hover: #981b15;
    --fp-bg: #f8f9fa;
    --fp-card: #ffffff;
    --fp-ink: #101828;
    --fp-muted: #475467;
    --fp-border: #eaecf0;
    --fp-success: #027a48;
    --fp-success-bg: #ecfdf3;
    --fp-success-border: #abefc6;
    --fp-warning: #b54708;
    --fp-warning-bg: #fffaeb;
    --fp-warning-border: #fedf89;
}

html.dark-mode,
html[data-theme="dark"],
body.dark-mode {
    --fp-bg: #0f172a;
    --fp-card: #1e293b;
    --fp-ink: #f8fafc;
    --fp-muted: #94a3b8;
    --fp-border: #334155;
    --fp-success: #34d399;
    --fp-success-bg: rgba(6, 78, 59, 0.4);
    --fp-success-border: #065f46;
    --fp-warning: #fbbf24;
    --fp-warning-bg: rgba(120, 53, 15, 0.4);
    --fp-warning-border: #92400e;
}

.fp-tracking-page {
    background: var(--fp-bg);
    min-height: 90vh;
    padding: 24px 0 140px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

.fp-tracking-container {
    max-width: 1240px;
    margin: 0 auto;
    padding: 0 16px;
}

/* ==========================================================================
   Foodpanda Style Hero Order Status Card
   ========================================================================== */
.fp-status-hero-card {
    background: var(--fp-card);
    border: 1px solid var(--fp-border);
    border-radius: 18px;
    padding: 24px 28px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.fp-hero-header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.fp-hero-left {
    flex: 1;
    min-width: 280px;
}

.fp-live-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
    padding: 4px 12px;
    border-radius: 9999px;
    font-size: 0.76rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 10px;
}

.fp-live-badge.pickup-mode {
    background: #fffaeb;
    color: #b54708;
    border-color: #fedf89;
}

.fp-live-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #12b76a;
    box-shadow: 0 0 0 0 rgba(18, 183, 106, 0.7);
    animation: fpPulse 1.8s infinite;
}

.fp-live-badge.pickup-mode .fp-live-dot {
    background: #f79009;
    box-shadow: 0 0 0 0 rgba(247, 144, 9, 0.7);
}

@keyframes fpPulse {
    0% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(18, 183, 106, 0.7);
    }
    70% {
        transform: scale(1);
        box-shadow: 0 0 0 8px rgba(18, 183, 106, 0);
    }
    100% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(18, 183, 106, 0);
    }
}

.fp-hero-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--fp-ink);
    letter-spacing: -0.5px;
    margin: 0 0 6px;
    line-height: 1.25;
}

.fp-hero-subtitle {
    font-size: 0.95rem;
    color: var(--fp-muted);
    margin: 0;
    line-height: 1.45;
}

.fp-hero-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
}

.fp-eta-pill {
    background: #fff1f0;
    color: var(--fp-primary);
    border: 1px solid #fee4e2;
    padding: 10px 18px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    text-align: right;
}

.fp-eta-pill.pickup {
    background: #eff8ff;
    color: #175cd3;
    border-color: #b2ddff;
}

.fp-eta-pill i {
    font-size: 1.2rem;
}

.fp-eta-label {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: #981b15;
}

.fp-eta-pill.pickup .fp-eta-label {
    color: #175cd3;
}

.fp-eta-value {
    display: block;
    font-family: 'Outfit', sans-serif;
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--fp-primary);
    line-height: 1.1;
}

.fp-eta-pill.pickup .fp-eta-value {
    color: #175cd3;
}

.fp-order-number-pill {
    font-size: 0.8rem;
    font-weight: 700;
    color: #475467;
    background: #f8f9fa;
    border: 1px solid var(--fp-border);
    padding: 4px 10px;
    border-radius: 8px;
    font-family: monospace;
}

/* ==========================================================================
   Foodpanda Style 4-Step Animated Progress Stepper
   ========================================================================== */
.fp-stepper-wrapper {
    position: relative;
    padding: 10px 10px 0;
}

.fp-stepper-track-bg {
    position: absolute;
    top: 32px;
    left: 45px;
    right: 45px;
    height: 6px;
    background: #eaecf0;
    border-radius: 4px;
    z-index: 1;
}

.fp-stepper-track-fill {
    position: absolute;
    top: 32px;
    left: 45px;
    height: 6px;
    background: var(--fp-primary);
    border-radius: 4px;
    z-index: 2;
    width: 25%;
    transition: width 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.fp-stepper-steps {
    position: relative;
    z-index: 3;
    display: flex;
    justify-content: space-between;
}

.fp-step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    width: 90px;
}

.fp-step-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #ffffff;
    border: 2px solid #d0d5dd;
    color: #98a2b3;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    box-shadow: 0 2px 6px rgba(16, 24, 40, 0.06);
    transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    margin-bottom: 8px;
}

.fp-step-label {
    font-size: 0.82rem;
    font-weight: 700;
    color: #667085;
    transition: color 0.3s ease;
}

.fp-step-sub {
    font-size: 0.72rem;
    color: #98a2b3;
    margin-top: 2px;
}

/* Stepper Active & Completed States */
.fp-step-item.is-completed .fp-step-icon-wrap {
    background: var(--fp-primary);
    border-color: var(--fp-primary);
    color: #ffffff;
}

.fp-step-item.is-completed .fp-step-label {
    color: var(--fp-ink);
}

.fp-step-item.is-active .fp-step-icon-wrap {
    background: #ffffff;
    border-color: var(--fp-primary);
    color: var(--fp-primary);
    box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.16);
    transform: scale(1.1);
}

.fp-step-item.is-active .fp-step-label {
    color: var(--fp-primary);
    font-weight: 800;
}

/* ==========================================================================
   Foodpanda Main Tracking Grid (Map & Details Sheet)
   ========================================================================== */
.fp-tracking-grid {
    display: grid;
    grid-template-columns: 1.65fr 1fr;
    gap: 20px;
    align-items: start;
}

/* Map Column & Overlay */
.fp-map-card {
    background: var(--fp-card);
    border: 1px solid var(--fp-border);
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    position: relative;
}

#map {
    height: 640px;
    width: 100%;
    background: #eef2f6;
}

/* Floating Map Overlay Controls */
.fp-map-floating-bar {
    position: absolute;
    top: 16px;
    left: 16px;
    right: 16px;
    z-index: 1000;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    pointer-events: none;
}

.fp-map-pill-stat {
    pointer-events: auto;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(234, 236, 240, 0.9);
    border-radius: 12px;
    padding: 8px 14px;
    box-shadow: 0 4px 14px rgba(16, 24, 40, 0.08);
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.84rem;
}

.fp-map-pill-stat .stat-item {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--fp-muted);
}

.fp-map-pill-stat .stat-item strong {
    color: var(--fp-ink);
}

.fp-map-actions {
    pointer-events: auto;
    display: flex;
    gap: 6px;
}

.fp-map-btn {
    background: #ffffff;
    border: 1px solid var(--fp-border);
    border-radius: 10px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #344054;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(16, 24, 40, 0.08);
    transition: all 0.2s ease;
}

.fp-map-btn:hover {
    background: #f8f9fa;
    color: var(--fp-primary);
    border-color: #d0d5dd;
}

.fp-map-btn.is-active {
    background: #b3261e;
    color: #ffffff;
    border-color: #b3261e;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.2);
}

.fp-map-btn.is-active:hover {
    background: #981b15;
    color: #ffffff;
    border-color: #981b15;
}

/* Custom Foodpanda Map Marker Styles */
.fp-marker-rider {
    position: relative;
}

.fp-marker-rider-inner {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #b3261e 0%, #981b15 100%);
    border: 3px solid #ffffff;
    box-shadow: 0 4px 14px rgba(179, 38, 30, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.15rem;
    position: relative;
    z-index: 2;
}

.fp-marker-pulse-ring {
    position: absolute;
    top: -6px;
    left: -6px;
    right: -6px;
    bottom: -6px;
    border-radius: 50%;
    border: 2px solid var(--fp-primary);
    animation: fpMarkerPulse 2s infinite ease-out;
    z-index: 1;
}

@keyframes fpMarkerPulse {
    0% { transform: scale(1); opacity: 0.8; }
    100% { transform: scale(1.8); opacity: 0; }
}

.fp-marker-store-inner {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #101828;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fca5a5;
    font-size: 1.15rem;
}

.fp-marker-home-inner {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #027a48;
    border: 3px solid #ffffff;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1rem;
}

/* ==========================================================================
   Right Column (Foodpanda Style Details Cards)
   ========================================================================== */
.fp-side-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* Pick-up Claim Pass Card */
.fp-claim-pass-card {
    background: #ffffff;
    border: 2px dashed #b3261e;
    border-radius: 18px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(179, 38, 30, 0.06);
}

.fp-claim-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #b3261e;
    margin-bottom: 8px;
}

.fp-claim-code-box {
    background: #fff1f0;
    border: 1px solid #fee4e2;
    border-radius: 12px;
    padding: 12px;
    margin: 8px 0 12px;
}

.fp-claim-code-text {
    font-family: monospace;
    font-size: 1.6rem;
    font-weight: 900;
    letter-spacing: 2px;
    color: #b3261e;
    display: block;
}

.fp-claim-hint {
    font-size: 0.82rem;
    color: #667085;
    margin: 0;
}

/* Store Pickup Location Card */
.fp-store-card {
    background: var(--fp-card);
    border: 1px solid var(--fp-border);
    border-radius: 18px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.fp-store-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}

.fp-store-header h4 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    color: var(--fp-ink);
    display: flex;
    align-items: center;
    gap: 8px;
}

.fp-store-address-box {
    background: #f8f9fa;
    border: 1px solid var(--fp-border);
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 14px;
}

.fp-store-address-box p {
    margin: 0 0 6px;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--fp-ink);
    line-height: 1.4;
}

.fp-store-hours {
    font-size: 0.78rem;
    color: #667085;
    display: flex;
    align-items: center;
    gap: 6px;
}

.fp-store-actions {
    display: flex;
    gap: 8px;
}

.fp-btn-directions {
    flex: 1;
    background: var(--fp-primary);
    color: #ffffff !important;
    border: 0;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 0.85rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background 0.15s ease;
}

.fp-btn-directions:hover {
    background: var(--fp-primary-hover);
}

.fp-btn-call {
    background: #ecfdf3;
    color: #027a48 !important;
    border: 1px solid #abefc6;
    border-radius: 10px;
    padding: 10px 16px;
    font-size: 0.85rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.fp-btn-call:hover {
    background: #027a48;
    color: #ffffff !important;
}

/* Rider Card (for Delivery Orders) */
.fp-rider-card {
    background: var(--fp-card);
    border: 1px solid var(--fp-border);
    border-radius: 18px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.fp-rider-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.fp-rider-meta {
    display: flex;
    align-items: center;
    gap: 14px;
}

.fp-rider-avatar-wrap {
    position: relative;
    width: 52px;
    height: 52px;
}

.fp-rider-avatar {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: #fff1f0;
    color: var(--fp-primary);
    border: 2px solid #fee4e2;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}

.fp-rider-online-dot {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #12b76a;
    border: 2px solid #ffffff;
}

.fp-rider-info h4 {
    margin: 0 0 4px;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--fp-ink);
    font-family: 'Outfit', sans-serif;
}

.fp-rider-tags {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.fp-tag-verified {
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.fp-tag-vehicle {
    background: #f8f9fa;
    color: #475467;
    border: 1px solid var(--fp-border);
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Route Timeline Card */
.fp-timeline-card {
    background: var(--fp-card);
    border: 1px solid var(--fp-border);
    border-radius: 18px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.fp-timeline-title {
    font-size: 0.95rem;
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    color: var(--fp-ink);
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.fp-timeline-list {
    position: relative;
    padding-left: 28px;
}

.fp-timeline-list::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 14px;
    bottom: 22px;
    width: 2px;
    background: #d0d5dd;
    border-style: dashed;
}

.fp-timeline-node {
    position: relative;
    margin-bottom: 16px;
}

.fp-timeline-node:last-child {
    margin-bottom: 0;
}

.fp-timeline-dot {
    position: absolute;
    left: -28px;
    top: 2px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #ffffff;
    border: 2px solid #98a2b3;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    color: #475467;
}

.fp-timeline-node.origin .fp-timeline-dot {
    border-color: #101828;
    color: #101828;
}

.fp-timeline-node.destination .fp-timeline-dot {
    border-color: var(--fp-primary);
    color: var(--fp-primary);
    background: #fff1f0;
}

.fp-timeline-label {
    display: block;
    font-size: 0.74rem;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: #667085;
}

.fp-timeline-name {
    display: block;
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--fp-ink);
    margin-top: 1px;
}

.fp-timeline-desc {
    display: block;
    font-size: 0.82rem;
    color: var(--fp-muted);
    line-height: 1.4;
    margin-top: 2px;
}

/* Rider In-App Chat */
.fp-chat-card {
    background: var(--fp-card);
    border: 1px solid var(--fp-border);
    border-radius: 18px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.fp-chat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.fp-chat-header h4 {
    font-size: 0.95rem;
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    color: var(--fp-ink);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.fp-chat-messages {
    max-height: 200px;
    overflow-y: auto;
    background: #f8f9fa;
    border: 1px solid var(--fp-border);
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.fp-chat-msg {
    display: flex;
    flex-direction: column;
    max-width: 80%;
}

.fp-chat-msg.customer {
    align-self: flex-end;
    align-items: flex-end;
}

.fp-chat-msg.driver {
    align-self: flex-start;
    align-items: flex-start;
}

.fp-chat-bubble {
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 0.86rem;
    line-height: 1.4;
    word-break: break-word;
}

.fp-chat-msg.customer .fp-chat-bubble {
    background: var(--fp-primary);
    color: #ffffff;
    border-bottom-right-radius: 2px;
}

.fp-chat-msg.driver .fp-chat-bubble {
    background: #ffffff;
    color: var(--fp-ink);
    border: 1px solid var(--fp-border);
    border-bottom-left-radius: 2px;
}

.fp-chat-time {
    font-size: 0.68rem;
    color: #98a2b3;
    margin-top: 2px;
}

.fp-chat-chips {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 8px;
}

.fp-chip {
    white-space: nowrap;
    background: #ffffff;
    border: 1px solid var(--fp-border);
    border-radius: 9999px;
    padding: 4px 10px;
    font-size: 0.76rem;
    font-weight: 600;
    color: #344054;
    cursor: pointer;
    transition: all 0.15s ease;
}

.fp-chip:hover {
    background: #f8f9fa;
    border-color: #d0d5dd;
    color: var(--fp-primary);
}

.fp-chat-form {
    display: flex;
    gap: 8px;
}

.fp-chat-input {
    flex: 1;
    height: 40px;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    padding: 0 12px;
    font-size: 0.86rem;
    background: #ffffff;
    outline: none;
    transition: border-color 0.15s ease;
}

.fp-chat-input:focus {
    border-color: var(--fp-primary);
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}

.fp-chat-send-btn {
    background: var(--fp-primary);
    color: #ffffff;
    border: 0;
    border-radius: 10px;
    padding: 0 16px;
    font-weight: 700;
    font-size: 0.86rem;
    cursor: pointer;
    transition: background 0.15s ease;
}

.fp-chat-send-btn:hover:not(:disabled) {
    background: var(--fp-primary-hover);
}

/* Order Summary Details */
.fp-order-summary-card {
    background: var(--fp-card);
    border: 1px solid var(--fp-border);
    border-radius: 18px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.fp-summary-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
}

.fp-summary-header h4 {
    font-size: 0.95rem;
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    color: var(--fp-ink);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.fp-order-items-list {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #f2f4f7;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.fp-order-item-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.fp-item-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.fp-item-thumb {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    object-fit: cover;
    background: #f8f9fa;
    border: 1px solid var(--fp-border);
}

.fp-item-qty {
    font-size: 0.85rem;
    font-weight: 800;
    color: var(--fp-primary);
    width: 20px;
}

.fp-item-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--fp-ink);
    line-height: 1.3;
}

.fp-item-sub {
    font-size: 0.76rem;
    color: var(--fp-muted);
}

.fp-item-price {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--fp-ink);
    text-align: right;
}

.fp-cost-breakdown {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px dashed var(--fp-border);
}

.fp-cost-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.84rem;
    color: var(--fp-muted);
    margin-bottom: 6px;
}

.fp-cost-row.total {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--fp-ink);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #eaecf0;
}

.fp-action-links {
    margin-top: 16px;
    display: flex;
    gap: 10px;
}

.fp-btn-link-sec {
    flex: 1;
    background: #ffffff;
    border: 1px solid var(--fp-border);
    border-radius: 10px;
    padding: 10px;
    text-align: center;
    font-size: 0.84rem;
    font-weight: 700;
    color: #344054;
    text-decoration: none;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.fp-btn-link-sec:hover {
    background: #f8f9fa;
    border-color: #d0d5dd;
    color: var(--fp-ink);
}

/* ==========================================================================
   TRACK ORDER DARK THEME ENGINE (Universal Desktop & Mobile)
   ========================================================================== */
body.dark-mode .fp-tracking-page {
    background: #0f172a !important;
}

/* Universal Dark Card Containers */
body.dark-mode .fp-status-hero-card,
body.dark-mode .fp-map-card,
body.dark-mode .fp-rider-card,
body.dark-mode .fp-store-card,
body.dark-mode .fp-timeline-card,
body.dark-mode .fp-chat-card,
body.dark-mode .fp-order-summary-card,
body.dark-mode .fp-claim-pass-card,
body.dark-mode .fp-waiting-rider-card,
body.dark-mode #fpProofCard {
    background: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35) !important;
    color: #f8fafc !important;
}

/* Hero Order Status Card */
body.dark-mode .fp-hero-title {
    color: #f8fafc !important;
}

body.dark-mode .fp-hero-subtitle {
    color: #94a3b8 !important;
}

body.dark-mode .fp-live-badge {
    background: rgba(6, 78, 59, 0.35) !important;
    color: #34d399 !important;
    border-color: rgba(16, 185, 129, 0.4) !important;
}

body.dark-mode .fp-live-badge.pickup-mode {
    background: rgba(120, 53, 15, 0.35) !important;
    color: #fbbf24 !important;
    border-color: rgba(245, 158, 11, 0.4) !important;
}

body.dark-mode .fp-eta-pill {
    background: rgba(179, 38, 30, 0.18) !important;
    border-color: rgba(179, 38, 30, 0.45) !important;
    color: #fca5a5 !important;
}

body.dark-mode .fp-eta-label {
    color: #f87171 !important;
}

body.dark-mode .fp-eta-value {
    color: #fca5a5 !important;
}

body.dark-mode .fp-eta-pill.pickup {
    background: rgba(30, 58, 138, 0.3) !important;
    border-color: rgba(59, 130, 246, 0.4) !important;
    color: #93c5fd !important;
}

body.dark-mode .fp-eta-pill.pickup .fp-eta-label {
    color: #60a5fa !important;
}

body.dark-mode .fp-eta-pill.pickup .fp-eta-value {
    color: #93c5fd !important;
}

body.dark-mode .fp-order-number-pill {
    background: #0f172a !important;
    color: #94a3b8 !important;
    border-color: #334155 !important;
}

body.dark-mode .fp-delivery-pin-badge,
body.dark-mode div[title*="PIN"] {
    background: rgba(179, 38, 30, 0.18) !important;
    border-color: rgba(179, 38, 30, 0.45) !important;
    color: #fca5a5 !important;
}

/* 4-Step Animated Progress Stepper */
body.dark-mode .fp-stepper-track-bg {
    background: #334155 !important;
}

body.dark-mode .fp-stepper-track-fill {
    background: #b3261e !important;
}

body.dark-mode .fp-step-icon-wrap {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #64748b !important;
}

body.dark-mode .fp-step-label {
    color: #94a3b8 !important;
}

body.dark-mode .fp-step-sub {
    color: #64748b !important;
}

body.dark-mode .fp-step-item.is-completed .fp-step-icon-wrap {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .fp-step-item.is-completed .fp-step-label {
    color: #f8fafc !important;
}

body.dark-mode .fp-step-item.is-completed .fp-step-sub {
    color: #94a3b8 !important;
}

body.dark-mode .fp-step-item.is-active .fp-step-icon-wrap {
    background: #1e293b !important;
    border-color: #ef4444 !important;
    color: #ef4444 !important;
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.25) !important;
}

body.dark-mode .fp-step-item.is-active .fp-step-label {
    color: #ef4444 !important;
}

body.dark-mode .fp-step-item.is-active .fp-step-sub {
    color: #fca5a5 !important;
}

/* Interactive Map, Dark Tiles & Overlays */
body.dark-mode #map {
    background: #0b1120 !important;
}

body.dark-mode .leaflet-tile {
    filter: brightness(0.65) invert(1) contrast(3) hue-rotate(200deg) saturate(0.3) brightness(0.7) !important;
}

body.dark-mode .leaflet-container {
    background: #0b1120 !important;
}

body.dark-mode .leaflet-popup-content-wrapper,
body.dark-mode .leaflet-popup-tip {
    background: #1e293b !important;
    color: #f8fafc !important;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5) !important;
}

body.dark-mode .leaflet-popup-content {
    color: #f8fafc !important;
}

body.dark-mode .leaflet-popup-content strong {
    color: #ffffff !important;
}

body.dark-mode .leaflet-control-zoom a {
    background-color: #1e293b !important;
    color: #f8fafc !important;
    border-color: #334155 !important;
}

body.dark-mode .leaflet-control-zoom a:hover {
    background-color: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .leaflet-control-attribution {
    background: rgba(15, 23, 42, 0.8) !important;
    color: #94a3b8 !important;
}

body.dark-mode .leaflet-control-attribution a {
    color: #cbd5e1 !important;
}

body.dark-mode .fp-map-pill-stat {
    background: rgba(15, 23, 42, 0.94) !important;
    border-color: rgba(51, 65, 85, 0.85) !important;
    color: #f8fafc !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .fp-map-pill-stat .stat-item {
    color: #94a3b8 !important;
}

body.dark-mode .fp-map-pill-stat .stat-item strong {
    color: #f8fafc !important;
}

body.dark-mode .fp-map-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3) !important;
}

body.dark-mode .fp-map-btn:hover {
    background: #334155 !important;
    color: #ffffff !important;
    border-color: #475569 !important;
}

body.dark-mode .fp-map-btn.is-active {
    background: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
}

/* Waiting for Rider Card */
body.dark-mode .fp-waiting-rider-card strong {
    color: #f8fafc !important;
}

body.dark-mode .fp-waiting-rider-card p {
    color: #94a3b8 !important;
}

body.dark-mode .fp-waiting-rider-card .badge {
    background: rgba(120, 53, 15, 0.35) !important;
    color: #fbbf24 !important;
    border-color: rgba(245, 158, 11, 0.4) !important;
}

body.dark-mode .fp-waiting-icon,
body.dark-mode .fp-waiting-rider-card div[style*="background:#fff1f0"] {
    background: rgba(179, 38, 30, 0.2) !important;
    color: #fca5a5 !important;
}

/* Rider Profile Card */
body.dark-mode .fp-rider-avatar {
    background: rgba(179, 38, 30, 0.2) !important;
    border-color: rgba(179, 38, 30, 0.4) !important;
    color: #fca5a5 !important;
}

body.dark-mode .fp-rider-online-dot {
    border-color: #1e293b !important;
}

body.dark-mode .fp-rider-info h4 {
    color: #f8fafc !important;
}

body.dark-mode .fp-tag-verified {
    background: rgba(6, 78, 59, 0.35) !important;
    color: #34d399 !important;
    border-color: rgba(16, 185, 129, 0.4) !important;
}

body.dark-mode .fp-tag-vehicle {
    background: #0f172a !important;
    color: #94a3b8 !important;
    border-color: #334155 !important;
}

body.dark-mode .fp-btn-call {
    background: rgba(6, 78, 59, 0.35) !important;
    color: #34d399 !important;
    border-color: rgba(16, 185, 129, 0.4) !important;
}

body.dark-mode .fp-btn-call:hover {
    background: #059669 !important;
    color: #ffffff !important;
}

/* Proof of Delivery Card */
body.dark-mode #fpProofCard h4 {
    color: #f8fafc !important;
}

body.dark-mode #fpProofCard p {
    color: #94a3b8 !important;
}

body.dark-mode #fpProofImg {
    border-color: #334155 !important;
}

body.dark-mode #fpProofCard .badge {
    background: rgba(6, 78, 59, 0.35) !important;
    color: #34d399 !important;
    border-color: rgba(16, 185, 129, 0.4) !important;
}

/* Delivery Route Timeline Card */
body.dark-mode .fp-timeline-title {
    color: #f8fafc !important;
}

body.dark-mode .fp-timeline-list::before {
    background: #334155 !important;
}

body.dark-mode .fp-timeline-dot {
    background: #0f172a !important;
    border-color: #475569 !important;
    color: #94a3b8 !important;
}

body.dark-mode .fp-timeline-node.origin .fp-timeline-dot {
    border-color: #94a3b8 !important;
    color: #f8fafc !important;
}

body.dark-mode .fp-timeline-node.destination .fp-timeline-dot {
    background: rgba(179, 38, 30, 0.2) !important;
    border-color: #ef4444 !important;
    color: #fca5a5 !important;
}

body.dark-mode .fp-timeline-label {
    color: #94a3b8 !important;
}

body.dark-mode .fp-timeline-name {
    color: #f8fafc !important;
}

body.dark-mode .fp-timeline-desc {
    color: #cbd5e1 !important;
}

/* In-App Live Rider Chat Card */
body.dark-mode .fp-chat-header h4 {
    color: #f8fafc !important;
}

body.dark-mode #fpChatStatus {
    color: #34d399 !important;
}

body.dark-mode .fp-chat-messages {
    background: #0f172a !important;
    border-color: #334155 !important;
}

body.dark-mode .fp-chat-msg.driver .fp-chat-bubble {
    background: #1e293b !important;
    color: #f8fafc !important;
    border-color: #334155 !important;
}

body.dark-mode .fp-chat-time {
    color: #64748b !important;
}

body.dark-mode .fp-chip {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .fp-chip:hover {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}

body.dark-mode .fp-chat-input {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .fp-chat-input:focus {
    border-color: #b3261e !important;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.25) !important;
}

/* Pick-up Claim Pass & Store Location Cards */
body.dark-mode .fp-claim-header {
    color: #fca5a5 !important;
}

body.dark-mode .fp-claim-code-box {
    background: rgba(179, 38, 30, 0.18) !important;
    border-color: rgba(179, 38, 30, 0.35) !important;
}

body.dark-mode .fp-claim-code-text {
    color: #fca5a5 !important;
}

body.dark-mode .fp-claim-hint {
    color: #94a3b8 !important;
}

body.dark-mode .fp-store-header h4 {
    color: #f8fafc !important;
}

body.dark-mode .fp-store-address-box {
    background: #0f172a !important;
    border-color: #334155 !important;
}

body.dark-mode .fp-store-address-box p {
    color: #f8fafc !important;
}

body.dark-mode .fp-store-address-box span,
body.dark-mode .fp-store-address-text {
    color: #cbd5e1 !important;
}

body.dark-mode .fp-store-hours {
    color: #94a3b8 !important;
}

/* Order Summary Details */
body.dark-mode .fp-summary-header h4 {
    color: #f8fafc !important;
}

body.dark-mode #summaryChevron {
    color: #94a3b8 !important;
}

body.dark-mode .fp-order-items-list {
    border-top-color: #334155 !important;
}

body.dark-mode .fp-item-thumb {
    background: #0f172a !important;
    border-color: #334155 !important;
}

body.dark-mode .fp-item-name {
    color: #f8fafc !important;
}

body.dark-mode .fp-item-sub {
    color: #94a3b8 !important;
}

body.dark-mode .fp-item-price {
    color: #f8fafc !important;
}

body.dark-mode .fp-cost-breakdown {
    border-top-color: #334155 !important;
}

body.dark-mode .fp-cost-row {
    color: #94a3b8 !important;
}

body.dark-mode .fp-cost-row strong {
    color: #f8fafc !important;
}

body.dark-mode .fp-cost-row.total {
    color: #f8fafc !important;
    border-top-color: #334155 !important;
}

body.dark-mode .fp-cost-row.total strong {
    color: #ef4444 !important;
}

body.dark-mode .fp-payment-method-row span {
    color: #94a3b8 !important;
}

body.dark-mode .fp-payment-method-row strong,
body.dark-mode .fp-order-summary-card strong[style*="text-transform:uppercase"] {
    color: #f8fafc !important;
}

body.dark-mode .fp-btn-link-sec {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .fp-btn-link-sec:hover {
    background: #334155 !important;
    color: #ffffff !important;
    border-color: #475569 !important;
}

/* ==========================================================================
   Foodpanda & Reference App Glassmorphism & Mobile Responsive Adaptations
   ========================================================================== */
@media (max-width: 992px) {
    .fp-tracking-grid {
        grid-template-columns: 1fr;
    }
    #map {
        height: 380px;
    }
    .fp-hero-header-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 14px;
    }
    .fp-hero-right {
        align-items: flex-start;
        width: 100%;
    }
    .fp-eta-pill {
        width: 100%;
        justify-content: space-between;
    }
}

/* Prevent global floating widgets from overlapping tracking page UI */
#floatingChatBtn,
#floatingOrderBtn,
#stickyOngoingOrderWidget {
    display: none !important;
}

/* ==========================================================================
   MOBILE NATIVE APP EXPERIENCE (Matching Reference Live Track UI)
   ========================================================================== */
.mob-track-header,
.mob-floating-rider-card,
.mob-map-crosshair-btn,
.mob-stepper-pin-callout {
    display: none;
}

@media (max-width: 768px) {
    .fp-tracking-page {
        padding: 6px 0 160px !important; /* safe clearance above mobile-bottom-nav */
        background: #f8fafc !important;
        min-height: 100vh;
    }
    body.dark-mode .fp-tracking-page {
        background: #0b1120 !important;
    }

    .fp-tracking-container {
        padding: 0 10px !important;
        max-width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 10px !important;
    }

    /* 1. Mobile Native App Header */
    .mob-track-header {
        order: 1 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 2px 4px 4px !important;
        width: 100% !important;
    }

    .mob-track-back-btn,
    .mob-track-bell-btn {
        width: 38px !important;
        height: 38px !important;
        border-radius: 50% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #101828 !important;
        text-decoration: none !important;
        font-size: 1.05rem !important;
        background: #ffffff !important;
        border: 1px solid rgba(226, 232, 240, 0.9) !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04) !important;
        transition: transform 0.15s ease !important;
    }
    .mob-track-back-btn:active,
    .mob-track-bell-btn:active {
        transform: scale(0.92) !important;
    }
    body.dark-mode .mob-track-back-btn,
    body.dark-mode .mob-track-bell-btn {
        background: #1e293b !important;
        border-color: #334155 !important;
        color: #f8fafc !important;
    }

    .mob-track-title {
        font-family: 'Outfit', sans-serif !important;
        font-size: 1.15rem !important;
        font-weight: 800 !important;
        color: #101828 !important;
        margin: 0 !important;
        letter-spacing: -0.3px !important;
    }
    body.dark-mode .mob-track-title {
        color: #f8fafc !important;
    }

    /* 2. Notification Toast */
    #fpNotificationToast {
        order: 2 !important;
        margin-bottom: 0 !important;
    }

    /* 3. Main Tracking Grid using display:contents to place Map at top and Stepper directly below */
    .fp-tracking-grid {
        display: contents !important;
    }

    /* 4. Full Featured Interactive Map Container */
    .fp-map-card {
        order: 3 !important;
        border-radius: 20px !important;
        overflow: hidden !important;
        position: relative !important;
        box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.08), 0 4px 8px -4px rgba(0, 0, 0, 0.04) !important;
        border: 1px solid rgba(226, 232, 240, 0.9) !important;
        margin: 0 !important;
        width: 100% !important;
    }
    body.dark-mode .fp-map-card {
        border-color: #334155 !important;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.45) !important;
    }

    #map {
        height: 420px !important;
        min-height: 380px !important;
        width: 100% !important;
        border-radius: 20px !important;
        background: #eef2f6 !important;
    }

    .fp-map-floating-bar {
        display: none !important;
    }

    /* 5. Floating Rider Card Overlay (Reference UI Match) */
    .mob-floating-rider-card {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        position: absolute !important;
        top: 10px !important;
        left: 10px !important;
        right: 10px !important;
        z-index: 999 !important;
        background: rgba(255, 255, 255, 0.96) !important;
        backdrop-filter: blur(14px) !important;
        -webkit-backdrop-filter: blur(14px) !important;
        border: 1px solid rgba(226, 232, 240, 0.9) !important;
        border-radius: 18px !important;
        padding: 8px 12px !important;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.12), 0 8px 10px -6px rgba(0, 0, 0, 0.08) !important;
        gap: 8px !important;
    }
    body.dark-mode .mob-floating-rider-card {
        background: rgba(30, 41, 59, 0.96) !important;
        border-color: rgba(51, 65, 85, 0.85) !important;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5) !important;
    }

    .mob-rider-card-left {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        flex: 1 !important;
        min-width: 0 !important;
    }

    .mob-rider-avatar-wrap {
        width: 44px !important;
        height: 44px !important;
        border-radius: 50% !important;
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%) !important;
        border: 2px solid #ffffff !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #0284c7 !important;
        font-size: 1.15rem !important;
        flex-shrink: 0 !important;
        box-shadow: 0 2px 8px rgba(2, 132, 199, 0.2) !important;
    }
    body.dark-mode .mob-rider-avatar-wrap {
        background: linear-gradient(135deg, rgba(2, 132, 199, 0.25) 0%, rgba(14, 165, 233, 0.15) 100%) !important;
        border-color: #334155 !important;
        color: #38bdf8 !important;
    }

    .mob-rider-meta {
        display: flex !important;
        flex-direction: column !important;
        min-width: 0 !important;
        line-height: 1.2 !important;
    }

    .mob-rider-tagline {
        font-size: 0.65rem !important;
        color: #64748b !important;
        font-weight: 600 !important;
        letter-spacing: 0.02em !important;
    }
    body.dark-mode .mob-rider-tagline {
        color: #94a3b8 !important;
    }

    .mob-rider-name-row {
        display: flex !important;
        align-items: center !important;
        gap: 4px !important;
        margin: 1px 0 !important;
    }

    .mob-rider-name {
        font-size: 0.9rem !important;
        font-weight: 800 !important;
        color: #101828 !important;
        font-family: 'Outfit', sans-serif !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: 95px !important;
    }
    body.dark-mode .mob-rider-name {
        color: #f8fafc !important;
    }

    .mob-rider-rating {
        font-size: 0.7rem !important;
        font-weight: 700 !important;
        color: #eab308 !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 2px !important;
    }

    .mob-rider-status-lbl {
        font-size: 0.68rem !important;
        color: #64748b !important;
        font-weight: 500 !important;
    }
    body.dark-mode .mob-rider-status-lbl {
        color: #94a3b8 !important;
    }

    .mob-rider-eta-val {
        font-family: 'Outfit', sans-serif !important;
        font-size: 1.05rem !important;
        font-weight: 800 !important;
        color: #2563eb !important;
        letter-spacing: -0.3px !important;
    }
    body.dark-mode .mob-rider-eta-val {
        color: #60a5fa !important;
    }

    .mob-rider-card-actions {
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
        flex-shrink: 0 !important;
    }

    .mob-circle-action-btn {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        background: transparent !important;
        border: 0 !important;
        padding: 0 !important;
        cursor: pointer !important;
        text-decoration: none !important;
    }

    .mob-circle-icon {
        width: 36px !important;
        height: 36px !important;
        border-radius: 50% !important;
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #475569 !important;
        font-size: 0.88rem !important;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05) !important;
        transition: transform 0.15s ease, background 0.15s ease !important;
    }
    .mob-circle-action-btn:active .mob-circle-icon {
        transform: scale(0.92) !important;
    }
    body.dark-mode .mob-circle-icon {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #cbd5e1 !important;
    }

    .mob-circle-lbl {
        font-size: 0.62rem !important;
        color: #64748b !important;
        font-weight: 600 !important;
        margin-top: 2px !important;
    }
    body.dark-mode .mob-circle-lbl {
        color: #94a3b8 !important;
    }

    /* 6. Floating GPS Recenter Crosshair Button */
    .mob-map-crosshair-btn {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        position: absolute !important;
        bottom: 14px !important;
        right: 12px !important;
        z-index: 999 !important;
        width: 44px !important;
        height: 44px !important;
        border-radius: 50% !important;
        background: #ffffff !important;
        border: 1px solid rgba(226, 232, 240, 0.9) !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.14) !important;
        color: #2563eb !important;
        font-size: 1.1rem !important;
        cursor: pointer !important;
        transition: transform 0.15s ease !important;
    }
    .mob-map-crosshair-btn:active {
        transform: scale(0.92) !important;
    }
    body.dark-mode .mob-map-crosshair-btn {
        background: #1e293b !important;
        border-color: #334155 !important;
        color: #60a5fa !important;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.45) !important;
    }

    /* Map Markers */
    .fp-marker-store-inner {
        background: #16a34a !important;
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(22, 163, 74, 0.4) !important;
    }
    .fp-marker-home-inner {
        background: #4f46e5 !important;
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.4) !important;
    }

    /* 7. Stepper Card directly below Map */
    .fp-status-hero-card {
        order: 4 !important;
        border-radius: 20px !important;
        margin: 0 !important;
        padding: 14px 10px !important;
        background: #ffffff !important;
        border: 1px solid rgba(226, 232, 240, 0.9) !important;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05) !important;
        width: 100% !important;
    }
    body.dark-mode .fp-status-hero-card {
        background: #1e293b !important;
        border-color: #334155 !important;
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.4) !important;
    }

    .fp-hero-header-row {
        display: none !important;
    }

    .fp-stepper-wrapper {
        padding: 4px 0 2px !important;
        position: relative !important;
        width: 100% !important;
    }

    .fp-stepper-track-bg {
        top: 20px !important;
        left: 22px !important;
        right: 22px !important;
        height: 4px !important;
        background: #e2e8f0 !important;
        border-radius: 4px !important;
    }
    body.dark-mode .fp-stepper-track-bg {
        background: #334155 !important;
    }

    .fp-stepper-track-fill {
        top: 20px !important;
        left: 22px !important;
        height: 4px !important;
        background: #16a34a !important;
        border-radius: 4px !important;
    }

    .fp-stepper-steps {
        display: flex !important;
        justify-content: space-between !important;
        width: 100% !important;
        position: relative !important;
        z-index: 3 !important;
        gap: 2px !important;
    }

    .fp-step-item {
        flex: 1 1 0 !important;
        width: auto !important;
        max-width: none !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
        padding: 0 2px !important;
    }

    .fp-step-icon-wrap {
        width: 40px !important;
        height: 40px !important;
        border-radius: 50% !important;
        font-size: 0.95rem !important;
        border-width: 2px !important;
        margin-bottom: 4px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05) !important;
    }

    /* Completed Step: Green with white check icon (Reference Match) */
    .fp-step-item.is-completed .fp-step-icon-wrap {
        background: #16a34a !important;
        border-color: #16a34a !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3) !important;
    }
    .fp-step-item.is-completed .fp-step-icon-wrap i::before {
        content: "\f00c" !important; /* FontAwesome fa-check */
    }
    .fp-step-item.is-completed .fp-step-label {
        color: #101828 !important;
        font-weight: 700 !important;
    }
    body.dark-mode .fp-step-item.is-completed .fp-step-label {
        color: #f8fafc !important;
    }

    /* Active Step: Vibrant Blue with motorcycle icon (Reference Match) */
    .fp-step-item.is-active .fp-step-icon-wrap {
        background: #2563eb !important;
        border-color: #2563eb !important;
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4) !important;
        transform: scale(1.05) !important;
    }
    .fp-step-item.is-active .fp-step-label {
        color: #2563eb !important;
        font-weight: 800 !important;
    }

    /* Pending Step */
    .fp-step-item:not(.is-completed):not(.is-active) .fp-step-icon-wrap {
        background: #f1f5f9 !important;
        border: 2px solid #cbd5e1 !important;
        color: #94a3b8 !important;
    }
    body.dark-mode .fp-step-item:not(.is-completed):not(.is-active) .fp-step-icon-wrap {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #64748b !important;
    }

    .fp-step-label {
        font-size: 0.68rem !important;
        line-height: 1.15 !important;
        white-space: normal !important;
        text-align: center !important;
        font-weight: 600 !important;
        word-break: break-word !important;
    }

    .fp-step-sub {
        display: block !important;
        font-size: 0.6rem !important;
        color: #64748b !important;
        margin-top: 1px !important;
        font-weight: 500 !important;
    }
    body.dark-mode .fp-step-sub {
        color: #94a3b8 !important;
    }

    /* Delivery PIN Callout under Stepper */
    .mob-stepper-pin-callout {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        background: #fff1f0 !important;
        border: 1px solid #fee4e2 !important;
        border-radius: 12px !important;
        padding: 8px 12px !important;
        margin-top: 10px !important;
    }
    body.dark-mode .mob-stepper-pin-callout {
        background: rgba(179, 38, 30, 0.18) !important;
        border-color: rgba(179, 38, 30, 0.35) !important;
    }
    .mob-pin-badge-inner {
        font-size: 0.8rem !important;
        color: #b3261e !important;
        font-weight: 700 !important;
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
    }
    body.dark-mode .mob-pin-badge-inner {
        color: #fca5a5 !important;
    }
    .mob-pin-badge-inner strong {
        font-family: monospace !important;
        letter-spacing: 2px !important;
        font-size: 0.94rem !important;
    }
    .mob-pin-hint {
        font-size: 0.7rem !important;
        color: #64748b !important;
    }
    body.dark-mode .mob-pin-hint {
        color: #94a3b8 !important;
    }

    /* 8. Details Sheet in Side Panel (Route, Chat, Order Summary) */
    .fp-side-panel {
        order: 5 !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 12px !important;
        margin-top: 0 !important;
        width: 100% !important;
    }

    .fp-side-panel .fp-rider-card,
    .fp-side-panel .fp-waiting-rider-card {
        display: none !important; /* Managed by floating rider card on mobile */
    }

    .fp-timeline-card,
    .fp-chat-card,
    .fp-order-summary-card,
    .fp-claim-pass-card,
    .fp-route-card,
    .fp-store-card,
    #fpProofCard {
        border-radius: 18px !important;
        padding: 14px !important;
        margin-bottom: 0 !important;
        border: 1px solid rgba(226, 232, 240, 0.9) !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    body.dark-mode .fp-timeline-card,
    body.dark-mode .fp-chat-card,
    body.dark-mode .fp-order-summary-card,
    body.dark-mode .fp-claim-pass-card,
    body.dark-mode .fp-route-card,
    body.dark-mode .fp-store-card,
    body.dark-mode #fpProofCard {
        border-color: #334155 !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35) !important;
    }

    .fp-action-links {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
        margin-top: 14px !important;
    }
    .fp-btn-link-sec {
        padding: 10px 8px !important;
        font-size: 0.84rem !important;
        text-align: center !important;
        justify-content: center !important;
    }
}

@media (max-width: 380px) {
    .fp-tracking-container {
        padding: 0 8px !important;
        gap: 8px !important;
    }
    #map {
        height: 380px !important;
        min-height: 350px !important;
    }
    .mob-floating-rider-card {
        top: 8px !important;
        left: 6px !important;
        right: 6px !important;
        padding: 6px 8px !important;
        gap: 6px !important;
    }
    .mob-rider-avatar-wrap {
        width: 38px !important;
        height: 38px !important;
        font-size: 1rem !important;
    }
    .mob-rider-name {
        font-size: 0.82rem !important;
        max-width: 80px !important;
    }
    .mob-rider-tagline {
        font-size: 0.6rem !important;
    }
    .mob-rider-status-lbl {
        font-size: 0.62rem !important;
    }
    .mob-rider-eta-val {
        font-size: 0.95rem !important;
    }
    .mob-circle-icon {
        width: 32px !important;
        height: 32px !important;
        font-size: 0.8rem !important;
    }
    .mob-circle-lbl {
        font-size: 0.58rem !important;
    }
    .fp-step-icon-wrap {
        width: 34px !important;
        height: 34px !important;
        font-size: 0.82rem !important;
        border-width: 1.5px !important;
    }
    .fp-step-label {
        font-size: 0.58rem !important;
        line-height: 1.1 !important;
    }
    .fp-step-sub {
        font-size: 0.52rem !important;
    }
    .fp-stepper-track-bg,
    .fp-stepper-track-fill {
        top: 17px !important;
        left: 14px !important;
        right: 14px !important;
    }
    .mob-pin-badge-inner {
        font-size: 0.74rem !important;
    }
    .mob-pin-hint {
        font-size: 0.62rem !important;
    }
}
</style>

<section class="fp-tracking-page">
    <div class="fp-tracking-container">
        
        <!-- Live Alert Notification Banner -->
        <div id="fpNotificationToast" style="display:none; background:#101828; color:#ffffff; padding:16px 20px; border-radius:14px; margin-bottom:18px; box-shadow:0 6px 20px rgba(16,24,40,0.14);">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                <div style="display:flex; align-items:center; gap:14px;">
                    <div style="width:42px; height:42px; border-radius:50%; background:var(--fp-primary); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.15rem; flex-shrink:0;">
                        <i class="<?php echo $is_pickup ? 'fas fa-store' : 'fas fa-motorcycle'; ?>"></i>
                    </div>
                    <div>
                        <strong id="fpToastTitle" style="display:block; font-size:1rem; color:#fff; font-weight:800; font-family:'Outfit',sans-serif;">Status Update!</strong>
                        <span id="fpToastMessage" style="font-size:0.86rem; color:#cbd5e1; line-height:1.4;">Your order status has been updated.</span>
                    </div>
                </div>
                <button onclick="document.getElementById('fpNotificationToast').style.display='none'" style="background:transparent; border:0; color:#94a3b8; font-size:1.4rem; cursor:pointer; padding:0 6px;">&times;</button>
            </div>
        </div>

        <!-- Mobile Native Header (Matches reference Live Track UI) -->
        <div class="mob-track-header">
            <a href="my_orders.php" class="mob-track-back-btn" title="Back to Orders">
                <i class="fas fa-chevron-left"></i>
            </a>
            <h2 class="mob-track-title">Live Track</h2>
            <a href="help_center.php" class="mob-track-bell-btn" title="Help & Support">
                <i class="far fa-bell"></i>
            </a>
        </div>

        <!-- Foodpanda Style Hero Order Status Card -->
        <div class="fp-status-hero-card">
            <div class="fp-hero-header-row">
                <div class="fp-hero-left">
                    <div class="fp-live-badge <?php echo $is_pickup ? 'pickup-mode' : ''; ?>">
                        <span class="fp-live-dot"></span> <?php echo $is_pickup ? 'Store Pick-up Order' : 'Live Order Tracking'; ?>
                    </div>
                    <h1 class="fp-hero-title" id="fpHeroTitle">
                        <?php 
                        $display_driver_name = htmlspecialchars(!empty($tracking_info['driver_name']) ? $tracking_info['driver_name'] : 'Your rider');
                        $display_driver_rating = (!empty($tracking_info['driver_rating']) && is_numeric($tracking_info['driver_rating'])) 
                            ? number_format((float)$tracking_info['driver_rating'], 1) 
                            : '5.0';
                        if ($is_pickup) {
                            echo 'Store is preparing your pick-up order';
                        } elseif (empty($tracking_info['driver_id']) || ($tracking_info['current_status'] ?? '') === 'pending') {
                            echo 'Waiting for Rider to Pick Up';
                        } elseif (($tracking_info['current_status'] ?? '') === 'assigned') {
                            echo $display_driver_name . ' accepted your delivery!';
                        } elseif (($tracking_info['current_status'] ?? '') === 'arrived_at_restaurant') {
                            echo $display_driver_name . ' arrived at the store!';
                        } elseif (in_array(($tracking_info['current_status'] ?? ''), ['picked_up', 'on_the_way'])) {
                            echo $display_driver_name . ' is on the way!';
                        } elseif (($tracking_info['current_status'] ?? '') === 'delivered') {
                            echo 'Order Delivered!';
                        } else {
                            echo 'Preparing your delicious order';
                        }
                        ?>
                    </h1>
                    <p class="fp-hero-subtitle" id="fpHeroSubtitle">
                        <?php 
                        if ($is_pickup) {
                            echo 'Your dishes are being cooked fresh and packed for collection at the store counter.';
                        } elseif (empty($tracking_info['driver_id']) || ($tracking_info['current_status'] ?? '') === 'pending') {
                            echo 'Your order is confirmed and being prepared. Nearby delivery riders are currently being notified to accept your delivery.';
                        } elseif (($tracking_info['current_status'] ?? '') === 'assigned') {
                            echo 'Your rider has accepted the delivery and is heading to the store to pick up your order.';
                        } elseif (($tracking_info['current_status'] ?? '') === 'arrived_at_restaurant') {
                            echo 'Your rider has arrived at the store and is waiting for the store owner to hand over your package.';
                        } elseif (in_array(($tracking_info['current_status'] ?? ''), ['picked_up', 'on_the_way'])) {
                            echo 'Your rider has picked up your food and is driving to your address.';
                        } elseif (($tracking_info['current_status'] ?? '') === 'delivered') {
                            echo 'Your order was successfully delivered. Thank you for choosing Lechon Delights!';
                        } else {
                            echo 'The kitchen is preparing your freshly roasted lechon with care.';
                        }
                        ?>
                    </p>
                </div>

                <div class="fp-hero-right">
                    <div class="fp-eta-pill <?php echo $is_pickup ? 'pickup' : ''; ?>" id="fpEtaPill">
                        <i class="<?php echo $is_pickup ? 'fas fa-calendar-check' : 'fas fa-stopwatch'; ?>"></i>
                        <div>
                            <span class="fp-eta-label"><?php echo $is_pickup ? 'Pick-up Schedule' : 'Estimated Delivery'; ?></span>
                            <span class="fp-eta-value" id="fpEtaValue">
                                <?php 
                                if ($is_pickup) {
                                    echo !empty($order['delivery_time']) ? htmlspecialchars($order['delivery_time']) : 'Today, Ready Soon';
                                } else {
                                    echo '20 - 35 mins';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    <div class="fp-order-number-pill">Order #<?php echo htmlspecialchars($order['order_number']); ?></div>
                    <?php if (!$is_pickup && !empty($order['delivery_pin'])): ?>
                        <div class="fp-delivery-pin-badge" style="background:#fff1f0; border:1px solid #fee4e2; color:#b3261e; font-weight:800; font-size:0.82rem; padding:4px 12px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;" title="Provide this 4-digit PIN to your rider upon arrival">
                            <i class="fas fa-key"></i> Delivery PIN: <span style="letter-spacing:2px; font-size:0.95rem; font-family:monospace;"><?php echo htmlspecialchars($order['delivery_pin']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            $init_trk_status = $tracking_info['current_status'] ?? 'pending';
            $step1_cls = 'is-completed';
            $step2_cls = '';
            $step3_cls = '';
            $step4_cls = '';
            $step2_time = 'In Progress';
            $step3_time = $is_pickup ? 'At Store' : 'Delivery';
            $stepper_pct = '30%';

            if ($is_pickup) {
                if (in_array($order['status'], ['ready', 'on_the_way'])) {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-completed'; $step3_cls = 'is-active'; $stepper_pct = '70%';
                } elseif ($order['status'] === 'delivered') {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-completed'; $step3_cls = 'is-completed'; $step4_cls = 'is-completed'; $stepper_pct = '100%';
                } else {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-active'; $stepper_pct = '30%';
                }
            } else {
                if (empty($tracking_info['driver_id']) || $init_trk_status === 'pending') {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-active'; $stepper_pct = '30%';
                } elseif ($init_trk_status === 'assigned') {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-completed'; $step2_time = 'Heading to store'; $step3_time = 'Waiting for pickup'; $stepper_pct = '40%';
                } elseif ($init_trk_status === 'arrived_at_restaurant') {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-completed'; $step3_cls = 'is-active'; $step2_time = 'At Store'; $step3_time = 'Awaiting Handover'; $stepper_pct = '60%';
                } elseif (in_array($init_trk_status, ['picked_up', 'on_the_way'])) {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-completed'; $step3_cls = 'is-active'; $step2_time = 'Picked Up'; $step3_time = 'En Route'; $stepper_pct = '75%';
                } elseif ($init_trk_status === 'arriving') {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-completed'; $step3_cls = 'is-active'; $step3_time = 'Arriving'; $stepper_pct = '88%';
                } elseif ($init_trk_status === 'delivered') {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-completed'; $step3_cls = 'is-completed'; $step4_cls = 'is-completed'; $stepper_pct = '100%';
                } else {
                    $step1_cls = 'is-completed'; $step2_cls = 'is-active'; $stepper_pct = '30%';
                }
            }
            ?>
            <!-- Foodpanda 4-Step Animated Progress Stepper -->
            <div class="fp-stepper-wrapper">
                <div class="fp-stepper-track-bg"></div>
                <div class="fp-stepper-track-fill" id="fpStepperFill" style="width: <?php echo $stepper_pct; ?>;"></div>

                <div class="fp-stepper-steps">
                    <!-- Step 1 -->
                    <div class="fp-step-item <?php echo $step1_cls; ?>" id="fpStep1">
                        <div class="fp-step-icon-wrap"><i class="fas fa-receipt"></i></div>
                        <span class="fp-step-label">Order Placed</span>
                        <span class="fp-step-sub"><?php echo date('g:i A', strtotime((string)$order['created_at'])); ?></span>
                    </div>

                    <!-- Step 2 -->
                    <div class="fp-step-item <?php echo $step2_cls; ?>" id="fpStep2">
                        <div class="fp-step-icon-wrap"><i class="fas fa-utensils"></i></div>
                        <span class="fp-step-label">Kitchen Preparing</span>
                        <span class="fp-step-sub" id="fpStep2Time"><?php echo $step2_time; ?></span>
                    </div>

                    <!-- Step 3 -->
                    <div class="fp-step-item <?php echo $step3_cls; ?>" id="fpStep3">
                        <div class="fp-step-icon-wrap">
                            <i class="<?php echo $is_pickup ? 'fas fa-bag-shopping' : 'fas fa-motorcycle'; ?>"></i>
                        </div>
                        <span class="fp-step-label" id="fpStep3Label"><?php echo $is_pickup ? 'Ready for Pick-up' : 'On the Way'; ?></span>
                        <span class="fp-step-sub" id="fpStep3Time"><?php echo $step3_time; ?></span>
                    </div>

                    <!-- Step 4 -->
                    <div class="fp-step-item <?php echo $step4_cls; ?>" id="fpStep4">
                        <div class="fp-step-icon-wrap"><i class="fas fa-circle-check"></i></div>
                        <span class="fp-step-label" id="fpStep4Label"><?php echo $is_pickup ? 'Picked Up' : 'Delivered'; ?></span>
                        <span class="fp-step-sub" id="fpStep4Time">Completed</span>
                    </div>
                </div>
            </div>

            <?php if (!$is_pickup && !empty($order['delivery_pin'])): ?>
                <div class="mob-stepper-pin-callout">
                    <div class="mob-pin-badge-inner">
                        <i class="fas fa-key"></i> Delivery PIN: <strong><?php echo htmlspecialchars($order['delivery_pin']); ?></strong>
                    </div>
                    <span class="mob-pin-hint">Provide to rider upon arrival</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Main Tracking Split Screen Grid -->
        <div class="fp-tracking-grid">
            
            <!-- Left Column: Full-Height Interactive Map -->
            <div class="fp-map-card">
                <!-- Mobile Floating Rider Card (Reference Match) -->
                <div class="mob-floating-rider-card" id="mobFloatingRiderCard">
                    <div class="mob-rider-card-left">
                        <div class="mob-rider-avatar-wrap">
                            <i class="<?php echo $is_pickup ? 'fas fa-store' : 'fas fa-motorcycle'; ?>"></i>
                        </div>
                        <div class="mob-rider-meta">
                            <span class="mob-rider-tagline"><?php echo $is_pickup ? 'Pick-up Counter' : 'Your Rider'; ?></span>
                            <div class="mob-rider-name-row">
                                <strong class="mob-rider-name" id="mobDriverName"><?php echo $display_driver_name; ?></strong>
                                <span class="mob-rider-rating" title="Driver Rating"><i class="fas fa-star"></i> <span id="mobDriverRating"><?php echo $display_driver_rating; ?></span></span>
                            </div>
                            <span class="mob-rider-status-lbl" id="mobRiderStatus">
                                <?php 
                                if ($is_pickup) {
                                    echo 'Ready for Pick-up';
                                } elseif (empty($tracking_info['driver_id']) || ($tracking_info['current_status'] ?? '') === 'pending') {
                                    echo 'Finding a Rider';
                                } elseif (($tracking_info['current_status'] ?? '') === 'assigned') {
                                    echo 'Heading to Store';
                                } elseif (($tracking_info['current_status'] ?? '') === 'arrived_at_restaurant') {
                                    echo 'At Store';
                                } elseif (in_array(($tracking_info['current_status'] ?? ''), ['picked_up', 'on_the_way'])) {
                                    echo 'Arriving In';
                                } elseif (($tracking_info['current_status'] ?? '') === 'delivered') {
                                    echo 'Delivered';
                                } else {
                                    echo 'In Progress';
                                }
                                ?>
                            </span>
                            <span class="mob-rider-eta-val" id="mobRiderEta">
                                <?php 
                                if ($is_pickup) {
                                    echo !empty($order['delivery_time']) ? htmlspecialchars($order['delivery_time']) : 'Ready Soon';
                                } else {
                                    echo '12 min';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    <div class="mob-rider-card-actions">
                        <?php 
                        $call_phone = $is_pickup ? ($store_details['phone'] ?? '') : ($tracking_info['driver_phone'] ?? '');
                        ?>
                        <a href="<?php echo !empty($call_phone) ? 'tel:' . htmlspecialchars($call_phone) : '#'; ?>" class="mob-circle-action-btn" id="mobCallBtn" title="Call">
                            <div class="mob-circle-icon"><i class="fas fa-phone-alt"></i></div>
                            <span class="mob-circle-lbl">Call</span>
                        </a>
                        <button type="button" class="mob-circle-action-btn" id="mobChatBtn" title="Message">
                            <div class="mob-circle-icon"><i class="fas fa-comment-dots"></i></div>
                            <span class="mob-circle-lbl">Message</span>
                        </button>
                    </div>
                </div>

                <!-- Floating Map Recenter Crosshairs Button -->
                <button type="button" class="mob-map-crosshair-btn" id="mobRecenterMapBtn" title="Fit & Center Route">
                    <i class="fas fa-crosshairs"></i>
                </button>
                <div class="fp-map-floating-bar">
                    <div class="fp-map-pill-stat">
                        <div class="stat-item">
                            <i class="<?php echo $is_pickup ? 'fas fa-store' : 'fas fa-route'; ?>" style="color:var(--fp-primary);"></i>
                            <span><?php echo $is_pickup ? 'Store:' : 'Dist:'; ?> <strong id="mapDistStat"><?php echo htmlspecialchars($store_details['store_name'] ?? 'Lechon Delights'); ?></strong></span>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-clock" style="color:#027a48;"></i>
                            <span><span id="mapEtaLabel"><?php echo $is_pickup ? 'Status:' : 'ETA:'; ?></span> <strong id="mapEtaStat"><?php echo ucfirst(htmlspecialchars((string)$order['status'])); ?></strong></span>
                        </div>
                    </div>

                    <div class="fp-map-actions">
                        <button type="button" class="fp-map-btn" id="btnFocusStore" title="Focus Store Location"><i class="fas fa-store"></i></button>
                        <?php if (!$is_pickup): ?>
                        <button type="button" class="fp-map-btn is-active" id="btnFocusRider" title="Auto-track / Focus Rider"><i class="fas fa-motorcycle"></i></button>
                        <button type="button" class="fp-map-btn" id="btnFocusHome" title="Focus Delivery Location"><i class="fas fa-house"></i></button>
                        <?php endif; ?>
                        <button type="button" class="fp-map-btn" id="btnFitRoute" title="Fit Map View"><i class="fas fa-expand"></i></button>
                    </div>
                </div>

                <div id="map"></div>
            </div>

            <!-- Right Column: Foodpanda Style Details Sheet -->
            <div class="fp-side-panel">

                <?php if ($is_pickup): ?>
                <!-- Pick-up Order: Claim Pass Card -->
                <div class="fp-claim-pass-card">
                    <div class="fp-claim-header">
                        <i class="fas fa-qrcode"></i> Store Pick-up Pass
                    </div>
                    <div class="fp-claim-code-box">
                        <span class="fp-claim-code-text">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                    </div>
                    <p class="fp-claim-hint">Please present this order number to the cashier or store staff when collecting your food.</p>
                </div>

                <!-- Pick-up Order: Store Details & Directions Card -->
                <div class="fp-store-card">
                    <div class="fp-store-header">
                        <h4><i class="fas fa-store" style="color:var(--fp-primary);"></i> Pick-up Location</h4>
                    </div>

                    <div class="fp-store-address-box">
                        <p><?php echo htmlspecialchars($store_details['store_name'] ?? 'Lechon Delights Store'); ?></p>
                        <span style="font-size:0.84rem; color:#475467; display:block; line-height:1.4; margin-bottom:8px;">
                            <?php echo htmlspecialchars($store_details['address'] ?? 'Cavite, Philippines'); ?>
                        </span>
                        <div class="fp-store-hours">
                            <i class="fas fa-clock" style="color:#b54708;"></i>
                            <span>Hours: <?php echo htmlspecialchars($store_details['opening_hours'] ?? '8:00 AM - 8:00 PM'); ?></span>
                        </div>
                    </div>

                    <div class="fp-store-actions">
                        <?php 
                        $dest_lat = $store_details['latitude'] ?? '14.4167';
                        $dest_lng = $store_details['longitude'] ?? '120.9333';
                        $gmaps_url = "https://www.google.com/maps/dir/?api=1&destination=" . urlencode($dest_lat . ',' . $dest_lng);
                        ?>
                        <a href="<?php echo $gmaps_url; ?>" target="_blank" class="fp-btn-directions">
                            <i class="fas fa-diamond-turn-right"></i> Open Navigation
                        </a>
                        <?php if (!empty($store_details['phone'])): ?>
                        <a href="tel:<?php echo htmlspecialchars($store_details['phone']); ?>" class="fp-btn-call">
                            <i class="fas fa-phone-alt"></i> Call Store
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php else: 
                $has_valid_rider = (!empty($tracking_info['driver_id']) && in_array(($tracking_info['current_status'] ?? ''), ['assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving', 'delivered'], true));
                ?>
                <!-- Waiting for Rider Card (Shown when order has not yet been accepted by a driver) -->
                <div class="fp-waiting-rider-card" id="fpWaitingRiderCard" style="<?php echo $has_valid_rider ? 'display:none;' : 'display:block;'; ?> background:#ffffff; border:1px solid #eaecf0; border-radius:16px; padding:18px 20px; margin-bottom:16px; box-shadow:0 1px 3px rgba(16,24,40,0.04);">
                    <div style="display:flex; align-items:center; gap:16px;">
                        <div class="fp-waiting-icon" style="width:48px; height:48px; border-radius:14px; background:#fff1f0; color:#b3261e; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0;">
                            <i class="fas fa-motorcycle"></i>
                        </div>
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <strong style="font-size:0.95rem; color:#101828;">Waiting for Rider to Accept & Pick Up</strong>
                                <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:10px; font-weight:700;">Finding a Rider</span>
                            </div>
                            <p style="font-size:0.82rem; color:#667085; margin:4px 0 0 0;">Nearby delivery riders are being notified. Once an active rider accepts your delivery, their name, contact, and live map tracker will appear here.</p>
                        </div>
                    </div>
                </div>

                <!-- Delivery Order: 1. Rider Profile Card (Live when assigned) -->
                <div class="fp-rider-card" id="fpRiderCard" style="<?php echo $has_valid_rider ? '' : 'display:none;'; ?>">
                    <div class="fp-rider-row">
                        <div class="fp-rider-meta">
                            <div class="fp-rider-avatar-wrap">
                                <div class="fp-rider-avatar">
                                    <i class="fas fa-user-ninja"></i>
                                </div>
                                <span class="fp-rider-online-dot"></span>
                            </div>
                            <div class="fp-rider-info">
                                <h4 id="fpDriverName"><?php echo htmlspecialchars($tracking_info['driver_name'] ?? 'Assigned Rider'); ?></h4>
                                <div class="fp-rider-tags">
                                    <span class="fp-tag-verified"><i class="fas fa-check-circle"></i> Verified Rider</span>
                                    <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:11px; font-weight:700;"><i class="fas fa-star text-warning"></i> <span id="desktopDriverRating"><?php echo $display_driver_rating; ?></span></span>
                                    <span class="fp-tag-vehicle" id="fpDriverVehicle"><i class="fas fa-motorcycle"></i> <?php echo htmlspecialchars(ucfirst((string)($tracking_info['driver_vehicle'] ?? 'Motorcycle'))); ?></span>
                                </div>
                            </div>
                        </div>

                        <div id="fpDriverPhoneWrap">
                            <a href="tel:<?php echo htmlspecialchars($tracking_info['driver_phone'] ?? ''); ?>" id="fpDriverPhoneLink" class="fp-btn-call" style="<?php echo empty($tracking_info['driver_phone']) ? 'display:none;' : ''; ?>">
                                <i class="fas fa-phone-alt"></i> Call
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Delivery Order: 1.5 Proof of Delivery Photo Card (When delivered) -->
                <div class="fp-card" id="fpProofCard" style="<?php echo !empty($initial_proof_path) ? '' : 'display:none;'; ?> margin-bottom: 16px; border: 1px solid #eaecf0; border-radius: 16px; padding: 18px 20px; background: #ffffff; box-shadow: 0 1px 3px rgba(16,24,40,0.04);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h4 style="font-size:0.95rem; font-weight:700; color:#101828; margin:0;"><i class="fas fa-camera" style="color:#027a48; margin-right:6px;"></i> Proof of Delivery</h4>
                        <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:11px; font-weight:600;"><i class="fas fa-check-circle"></i> Delivered</span>
                    </div>
                    <div style="text-align:center;">
                        <img id="fpProofImg" src="<?php echo !empty($initial_proof_path) ? htmlspecialchars($initial_proof_path) : ''; ?>" alt="Delivery Proof" style="max-height: 240px; width: auto; max-width: 100%; border-radius: 10px; border: 1px solid #d0d5dd; object-fit: cover; cursor: pointer;" onclick="window.open(this.src, '_blank')">
                        <p style="font-size:0.75rem; color:#667085; margin:8px 0 0 0;"><i class="fas fa-info-circle me-1"></i> Photo taken by rider upon handover at your doorstep. Click to view full image.</p>
                    </div>
                </div>

                <!-- Delivery Order: 1.7 Driver Star Rating Card -->
                <?php 
                $is_order_delivered = in_array(strtolower((string)$order['status']), ['delivered', 'completed'], true) 
                                   || in_array(strtolower((string)($tracking_info['current_status'] ?? '')), ['delivered', 'completed'], true);
                ?>
                <div class="fp-card fp-rate-driver-card" id="fpRateDriverCard" style="<?php echo (!$is_pickup && $is_order_delivered) ? '' : 'display:none;'; ?> margin-bottom: 16px; border: 1px solid #eaecf0; border-radius: 16px; padding: 18px 20px; background: #ffffff; box-shadow: 0 1px 3px rgba(16,24,40,0.04);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h4 style="font-size:0.95rem; font-weight:700; color:#101828; margin:0;">
                            <i class="fas fa-star" style="color:#eab308; margin-right:6px;"></i> Rate Your Delivery Rider
                        </h4>
                        <span class="badge" id="rateDriverStatusBadge" style="background:<?php echo $existing_delivery_review ? '#ecfdf3' : '#eff8ff'; ?>; color:<?php echo $existing_delivery_review ? '#027a48' : '#175cd3'; ?>; border:1px solid <?php echo $existing_delivery_review ? '#abefc6' : '#b2ddff'; ?>; font-size:11px; font-weight:700;">
                            <?php echo $existing_delivery_review ? 'Rated' : 'Feedback'; ?>
                        </span>
                    </div>

                    <!-- Interactive Star Rating Form -->
                    <div id="rateDriverFormWrap" style="<?php echo $existing_delivery_review ? 'display:none;' : 'display:block;'; ?>">
                        <p style="font-size:0.84rem; color:#475467; margin:0 0 12px 0;">
                            How was your delivery service with <strong id="rateDriverName"><?php echo htmlspecialchars($tracking_info['driver_name'] ?? 'your rider'); ?></strong>? Your rating reflects on their dashboard and helps them improve.
                        </p>

                        <div class="fp-star-picker" style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                            <div style="display:flex; gap:6px;" id="starRatingGroup">
                                <button type="button" class="fp-star-btn" data-star="1" style="background:transparent; border:none; cursor:pointer; font-size:1.65rem; color:#d0d5dd; padding:0 2px; transition:transform 0.15s, color 0.15s;"><i class="fas fa-star"></i></button>
                                <button type="button" class="fp-star-btn" data-star="2" style="background:transparent; border:none; cursor:pointer; font-size:1.65rem; color:#d0d5dd; padding:0 2px; transition:transform 0.15s, color 0.15s;"><i class="fas fa-star"></i></button>
                                <button type="button" class="fp-star-btn" data-star="3" style="background:transparent; border:none; cursor:pointer; font-size:1.65rem; color:#d0d5dd; padding:0 2px; transition:transform 0.15s, color 0.15s;"><i class="fas fa-star"></i></button>
                                <button type="button" class="fp-star-btn" data-star="4" style="background:transparent; border:none; cursor:pointer; font-size:1.65rem; color:#d0d5dd; padding:0 2px; transition:transform 0.15s, color 0.15s;"><i class="fas fa-star"></i></button>
                                <button type="button" class="fp-star-btn" data-star="5" style="background:transparent; border:none; cursor:pointer; font-size:1.65rem; color:#d0d5dd; padding:0 2px; transition:transform 0.15s, color 0.15s;"><i class="fas fa-star"></i></button>
                            </div>
                            <span id="starRatingLabel" style="font-size:0.84rem; font-weight:700; color:#eab308; margin-left:6px;">Select stars</span>
                        </div>

                        <input type="hidden" id="selectedDriverRating" value="0">

                        <div style="margin-bottom:12px;">
                            <textarea id="rateDriverComment" placeholder="Leave a short compliment or feedback for the driver (optional)..." maxlength="500" style="width:100%; border:1px solid #d0d5dd; border-radius:10px; padding:10px 12px; font-size:0.84rem; font-family:inherit; resize:vertical; min-height:60px; box-sizing:border-box; outline:none;"></textarea>
                        </div>

                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <button type="button" id="btnSubmitDriverRating" onclick="submitDriverRating()" style="background:#b3261e; color:#ffffff; border:none; border-radius:10px; padding:10px 20px; font-weight:700; font-size:0.88rem; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 8px rgba(179,38,30,0.25);">
                                <i class="fas fa-paper-plane"></i> Submit Driver Rating
                            </button>
                            <span id="rateDriverMsg" style="font-size:0.82rem; font-weight:600;"></span>
                        </div>
                    </div>

                    <!-- Already Rated Summary View -->
                    <div id="rateDriverCompletedWrap" style="<?php echo $existing_delivery_review ? 'display:block;' : 'display:none;'; ?>">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                            <span style="font-size:1.25rem; color:#eab308;" id="completedStarIcons">
                                <?php 
                                $rated_stars = (int)($existing_delivery_review['rating'] ?? 5);
                                for ($s = 1; $s <= 5; $s++):
                                    if ($s <= $rated_stars) {
                                        echo '<i class="fas fa-star"></i> ';
                                    } else {
                                        echo '<i class="far fa-star" style="color:#d0d5dd;"></i> ';
                                    }
                                endfor;
                                ?>
                            </span>
                            <strong style="font-size:0.92rem; color:#101828;" id="completedRatingText"><?php echo $rated_stars; ?> / 5 Stars</strong>
                        </div>
                        <p style="font-size:0.82rem; color:#475467; margin:0;" id="completedCommentText">
                            <?php 
                            if (!empty($existing_delivery_review['comment'])) {
                                echo '"' . htmlspecialchars($existing_delivery_review['comment']) . '"';
                            } else {
                                echo 'Thank you for rating your delivery rider! Your review helps them maintain quality service.';
                            }
                            ?>
                        </p>
                    </div>
                </div>

                <!-- Delivery Order: 2. Store & Delivery Route Timeline Card -->
                <div class="fp-timeline-card">
                    <div class="fp-timeline-title">
                        <i class="fas fa-location-arrow" style="color:var(--fp-primary);"></i> Delivery Route
                    </div>

                    <div class="fp-timeline-list">
                        <!-- Origin: Fulfillment Branch -->
                        <div class="fp-timeline-node origin">
                            <span class="fp-timeline-dot"><i class="fas fa-store"></i></span>
                            <span class="fp-timeline-label">Pick-up from</span>
                            <span class="fp-timeline-name"><?php echo htmlspecialchars($store_details['store_name'] ?? 'Lechon Delights Main Branch'); ?></span>
                            <span class="fp-timeline-desc"><?php echo htmlspecialchars($store_details['address'] ?? 'Cavite, Philippines'); ?></span>
                        </div>

                        <!-- Destination: Customer Address -->
                        <div class="fp-timeline-node destination">
                            <span class="fp-timeline-dot"><i class="fas fa-map-marker-alt"></i></span>
                            <span class="fp-timeline-label">Delivering to</span>
                            <span class="fp-timeline-name"><?php echo htmlspecialchars($order['customer_name'] ?: 'Customer'); ?></span>
                            <span class="fp-timeline-desc"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Delivery Order: 3. In-App Live Rider Chat -->
                <div class="fp-chat-card">
                    <div class="fp-chat-header">
                        <h4><i class="fas fa-comments" style="color:var(--fp-primary);"></i> Chat with Rider</h4>
                        <span id="fpChatStatus" style="font-size:0.75rem; color:#027a48; font-weight:700;">Live</span>
                    </div>

                    <div id="deliveryChatMessages" class="fp-chat-messages">
                        <div style="text-align:center; padding:16px; color:#667085; font-size:0.84rem;">Loading chat conversation...</div>
                    </div>

                    <!-- Quick response chips -->
                    <div class="fp-chat-chips">
                        <button type="button" class="fp-chip js-quick-chip" data-msg="I'm waiting outside">I'm waiting outside</button>
                        <button type="button" class="fp-chip js-quick-chip" data-msg="Please leave at front door">Leave at front door</button>
                        <button type="button" class="fp-chip js-quick-chip" data-msg="Take your time, thank you!">Thank you!</button>
                    </div>

                    <form id="deliveryChatForm" class="fp-chat-form">
                        <input type="text" id="deliveryChatInput" class="fp-chat-input" placeholder="Type a message to your rider..." maxlength="1000">
                        <button type="submit" id="deliveryChatSendBtn" class="fp-chat-send-btn"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Order Summary Accordion Card (Both Delivery and Pick-up) -->
                <div class="fp-order-summary-card">
                    <div class="fp-summary-header" onclick="toggleOrderSummary()">
                        <h4><i class="fas fa-bag-shopping" style="color:var(--fp-primary);"></i> Order Summary (<?php echo count($order_items); ?> items)</h4>
                        <i class="fas fa-chevron-down" id="summaryChevron" style="color:#667085; transition:transform 0.2s;"></i>
                    </div>

                    <div id="orderSummaryBody" style="display:block;">
                        <div class="fp-order-items-list">
                            <?php foreach ($order_items as $item): ?>
                            <div class="fp-order-item-row">
                                <div class="fp-item-left">
                                    <?php 
                                    $raw_img = trim((string)($item['product_image'] ?? ''));
                                    if ($raw_img === '') {
                                        $img_src = 'assets/images/promo_lechon.jpg';
                                    } elseif (strpos($raw_img, 'http://') === 0 || strpos($raw_img, 'https://') === 0 || strpos($raw_img, 'assets/') === 0) {
                                        $img_src = $raw_img;
                                    } elseif (strpos($raw_img, 'uploads/products/') === 0) {
                                        $img_src = $raw_img;
                                    } elseif (file_exists('uploads/products/' . basename($raw_img))) {
                                        $img_src = 'uploads/products/' . basename($raw_img);
                                    } else {
                                        $img_src = 'uploads/products/' . ltrim($raw_img, '/');
                                    }
                                    ?>
                                    <img src="<?php echo htmlspecialchars($img_src); ?>" class="fp-item-thumb" alt="<?php echo htmlspecialchars($item['product_name']); ?>" onerror="this.onerror=null;this.src='assets/images/promo_lechon.jpg';">
                                    <div>
                                        <div class="fp-item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <div class="fp-item-sub">
                                            Qty: <?php echo (int)$item['quantity']; ?>
                                            <?php if (!empty($item['size'])): ?> • <?php echo htmlspecialchars($item['size']); ?><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="fp-item-price">&#8369;<?php echo number_format((float)($item['total'] ?? ($item['price'] * $item['quantity'])), 2); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="fp-cost-breakdown">
                            <div class="fp-cost-row">
                                <span>Subtotal</span>
                                <strong>&#8369;<?php echo number_format((float)($order['subtotal'] ?? $order['total_amount']), 2); ?></strong>
                            </div>
                            <div class="fp-cost-row">
                                <span><?php echo $is_pickup ? 'Pick-up Fee' : 'Delivery Fee'; ?></span>
                                <strong><?php echo $is_pickup ? 'FREE (Store Pick-up)' : '&#8369;' . number_format((float)($order['delivery_fee'] ?? 0), 2); ?></strong>
                            </div>
                            <?php if (!empty($order['voucher_discount']) && (float)$order['voucher_discount'] > 0): ?>
                            <div class="fp-cost-row" style="color:#027a48;">
                                <span>Voucher Discount (<?php echo htmlspecialchars($order['voucher_code'] ?? 'PROMO'); ?>)</span>
                                <strong>-&#8369;<?php echo number_format((float)$order['voucher_discount'], 2); ?></strong>
                            </div>
                            <?php endif; ?>
                            <div class="fp-cost-row total">
                                <span>Total Paid</span>
                                <strong style="color:var(--fp-primary);">&#8369;<?php echo number_format((float)$order['total_amount'], 2); ?></strong>
                            </div>
                            <div class="fp-payment-method-row" style="margin-top:8px; font-size:0.78rem; color:#667085; display:flex; justify-content:space-between;">
                                <span>Payment Method:</span>
                                <strong style="text-transform:uppercase; color:#344054;"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$order['payment_method'])); ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="fp-action-links">
                        <a href="my_orders.php" class="fp-btn-link-sec"><i class="fas fa-arrow-left"></i> My Orders</a>
                        <a href="help_center.php" class="fp-btn-link-sec"><i class="fas fa-headset"></i> Need Help?</a>
                    </div>
                </div>

            </div>
        </div>

    </div>
</section>

<!-- Leaflet Map CSS and JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    const isPickupOrder = <?php echo json_encode($is_pickup); ?>;
    const orderId = <?php echo (int)$order_id; ?>;

    let map = null;
    let driverMarker = null;
    let customerMarker = null;
    let storeMarker = null;
    let routePolyline = null;
    let routeCasingPolyline = null;
    let routeDashPolyline = null;
    let secondaryRoutePolyline = null;
    let riderTrailPolyline = null;

    let customerLocationObj = null;
    let storeLocationObj = null;
    let lastDriverLatLng = null;
    let lastRouteRefreshAt = 0;
    let lastRouteCoordsKey = '';
    let isAutoTrackingRider = true;
    let hasAutoFitBounds = false;
    let driverMoveRaf = null;

    let trackingPollTimer = null;
    let chatPollTimer = null;
    let chatLoading = false;
    let chatLastMessageId = 0;
    let chatAvailable = false;
    let previousDeliveryStatus = '';

    const customerLat = <?php echo json_encode(isset($order['latitude']) ? (is_numeric($order['latitude']) ? (float)$order['latitude'] : null) : null); ?>;
    const customerLng = <?php echo json_encode(isset($order['longitude']) ? (is_numeric($order['longitude']) ? (float)$order['longitude'] : null) : null); ?>;
    const customerAddress = <?php echo json_encode((string)($order['delivery_address'] ?? '')); ?>;

    const initialDriverLat = <?php echo json_encode(isset($tracking_info['current_latitude']) && is_numeric($tracking_info['current_latitude']) ? (float)$tracking_info['current_latitude'] : null); ?>;
    const initialDriverLng = <?php echo json_encode(isset($tracking_info['current_longitude']) && is_numeric($tracking_info['current_longitude']) ? (float)$tracking_info['current_longitude'] : null); ?>;
    const initialDriverName = <?php echo json_encode((string)($tracking_info['driver_name'] ?? 'Assigned Rider')); ?>;
    const initialDeliveryStatus = <?php echo json_encode(strtolower(trim((string)($tracking_info['current_status'] ?? $order['status'] ?? 'pending')))); ?>;

    const storeLat = <?php echo json_encode(isset($store_details['latitude']) ? (is_numeric($store_details['latitude']) ? (float)$store_details['latitude'] : 14.4167) : 14.4167); ?>;
    const storeLng = <?php echo json_encode(isset($store_details['longitude']) ? (is_numeric($store_details['longitude']) ? (float)$store_details['longitude'] : 120.9333) : 120.9333); ?>;
    const storeName = <?php echo json_encode((string)($store_details['store_name'] ?? 'Lechon Delights Store')); ?>;

    // Custom Foodpanda Map Icon Definitions
    const riderIcon = L.divIcon({
        html: `
            <div class="fp-marker-rider">
                <div class="fp-marker-pulse-ring"></div>
                <div class="fp-marker-rider-inner">
                    <i class="fas fa-motorcycle"></i>
                </div>
            </div>
        `,
        className: 'fp-custom-rider-marker',
        iconSize: [44, 44],
        iconAnchor: [22, 22]
    });

    const storeIcon = L.divIcon({
        html: `
            <div class="fp-marker-store-inner" title="${storeName}">
                <i class="fas fa-store"></i>
            </div>
        `,
        className: 'fp-custom-store-marker',
        iconSize: [44, 44],
        iconAnchor: [22, 22]
    });

    const customerIcon = L.divIcon({
        html: `
            <div class="fp-marker-home-inner" title="Your Delivery Address">
                <i class="fas fa-house-chimney"></i>
            </div>
        `,
        className: 'fp-custom-home-marker',
        iconSize: [40, 40],
        iconAnchor: [20, 20]
    });

    function toggleOrderSummary() {
        const body = document.getElementById('orderSummaryBody');
        const chev = document.getElementById('summaryChevron');
        if (body.style.display === 'none') {
            body.style.display = 'block';
            chev.style.transform = 'rotate(0deg)';
        } else {
            body.style.display = 'none';
            chev.style.transform = 'rotate(180deg)';
        }
    }

    async function forwardGeocodeFromNominatim(addressText) {
        const raw = String(addressText || '').trim();
        if (!raw) return null;
        const candidates = [raw];
        const parts = raw.split(',').map(p => p.trim()).filter(Boolean);
        if (parts.length > 2) candidates.push(parts.slice(1).join(', '));
        if (parts.length >= 2) candidates.push(parts.slice(-2).join(', '));

        for (const query of candidates) {
            if (!query || query.length < 3) continue;
            try {
                const endpoint = 'https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=ph&limit=1&q=' + encodeURIComponent(query);
                const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
                if (!response.ok) continue;
                const payload = await response.json();
                if (Array.isArray(payload) && payload.length > 0) {
                    const lat = Number(payload[0]?.lat);
                    const lng = Number(payload[0]?.lon);
                    if (Number.isFinite(lat) && Number.isFinite(lng)) return { lat, lng };
                }
            } catch (e) {}
        }
        return null;
    }

    function initMap() {
        const centerPoint = (Number.isFinite(storeLat) && Number.isFinite(storeLng)) ? [storeLat, storeLng] : [14.4167, 120.9333];
        map = L.map('map', { zoomControl: false }).setView(centerPoint, 14);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        // Add Store Marker
        if (Number.isFinite(storeLat) && Number.isFinite(storeLng)) {
            storeLocationObj = [storeLat, storeLng];
            storeMarker = L.marker(storeLocationObj, { icon: storeIcon }).addTo(map);
            storeMarker.bindPopup(`<strong>${storeName}</strong><br>Pick-up Counter`).openPopup();
        }

        if (isPickupOrder) {
            // For Pick-up Orders: Center and highlight the Store location
            map.setView(centerPoint, 15);
            L.circle(centerPoint, {
                radius: 200,
                color: '#b3261e',
                weight: 2,
                fillColor: '#b3261e',
                fillOpacity: 0.1
            }).addTo(map);

            const btnFocusStore = document.getElementById('btnFocusStore');
            if (btnFocusStore) {
                btnFocusStore.addEventListener('click', () => {
                    if (storeMarker) map.setView(storeMarker.getLatLng(), 16);
                });
            }

            const btnFitRoute = document.getElementById('btnFitRoute');
            if (btnFitRoute) {
                btnFitRoute.addEventListener('click', () => {
                    if (storeMarker) map.setView(storeMarker.getLatLng(), 15);
                });
            }

            fetchTrackingData();
            trackingPollTimer = setInterval(fetchTrackingData, 4000);
            return;
        }

        // For Delivery Orders: Set up Route Lines & Customer Marker
        ensureRouteLayers();

        // Add Customer Marker
        if (Number.isFinite(customerLat) && Number.isFinite(customerLng)) {
            customerLocationObj = [customerLat, customerLng];
            customerMarker = L.marker(customerLocationObj, { icon: customerIcon }).addTo(map);
            map.setView(customerLocationObj, 14);
            if (lastDriverLatLng) {
                updateRouteAndEta(lastDriverLatLng[0], lastDriverLatLng[1], initialDeliveryStatus, true);
            }
        } else {
            forwardGeocodeFromNominatim(customerAddress).then(coords => {
                if (coords) {
                    customerLocationObj = [coords.lat, coords.lng];
                    customerMarker = L.marker(customerLocationObj, { icon: customerIcon }).addTo(map);
                    fitMapAllMarkers();
                    if (lastDriverLatLng) {
                        updateRouteAndEta(lastDriverLatLng[0], lastDriverLatLng[1], previousDeliveryStatus || initialDeliveryStatus, true);
                    }
                }
            });
        }

        // Attach Map Action Buttons for Delivery
        const btnFocusRider = document.getElementById('btnFocusRider');
        if (btnFocusRider) {
            btnFocusRider.classList.add('is-active');
            btnFocusRider.addEventListener('click', () => {
                isAutoTrackingRider = true;
                btnFocusRider.classList.add('is-active');
                if (driverMarker) {
                    map.setView(driverMarker.getLatLng(), 16, { animate: true });
                }
            });
        }

        const btnFocusHome = document.getElementById('btnFocusHome');
        if (btnFocusHome) {
            btnFocusHome.addEventListener('click', () => {
                isAutoTrackingRider = false;
                if (btnFocusRider) btnFocusRider.classList.remove('is-active');
                if (customerMarker) map.setView(customerMarker.getLatLng(), 16, { animate: true });
            });
        }

        const btnFocusStore = document.getElementById('btnFocusStore');
        if (btnFocusStore) {
            btnFocusStore.addEventListener('click', () => {
                isAutoTrackingRider = false;
                if (btnFocusRider) btnFocusRider.classList.remove('is-active');
                if (storeMarker) map.setView(storeMarker.getLatLng(), 16, { animate: true });
            });
        }

        const btnFitRoute = document.getElementById('btnFitRoute');
        if (btnFitRoute) {
            btnFitRoute.addEventListener('click', () => {
                isAutoTrackingRider = false;
                if (btnFocusRider) btnFocusRider.classList.remove('is-active');
                fitMapAllMarkers();
            });
        }

        map.on('dragstart', () => {
            isAutoTrackingRider = false;
            if (btnFocusRider) btnFocusRider.classList.remove('is-active');
        });

        // Mobile View Button Handlers
        const mobRecenterMapBtn = document.getElementById('mobRecenterMapBtn');
        if (mobRecenterMapBtn) {
            mobRecenterMapBtn.addEventListener('click', () => {
                isAutoTrackingRider = false;
                fitMapAllMarkers();
            });
        }

        const mobChatBtn = document.getElementById('mobChatBtn');
        if (mobChatBtn) {
            mobChatBtn.addEventListener('click', () => {
                const chatCard = document.querySelector('.fp-chat-card');
                const chatInput = document.getElementById('deliveryChatInput');
                if (chatCard) {
                    chatCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => { if (chatInput) chatInput.focus(); }, 400);
                }
            });
        }

        // Initialize driver marker immediately on page load if coordinates are available
        if (Number.isFinite(initialDriverLat) && Number.isFinite(initialDriverLng) && ['assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving'].includes(initialDeliveryStatus)) {
            updateDriverMarker(initialDriverLat, initialDriverLng, initialDriverName, initialDeliveryStatus);
            updateRouteAndEta(initialDriverLat, initialDriverLng, initialDeliveryStatus, true);
        }

        fetchTrackingData();
        trackingPollTimer = setInterval(fetchTrackingData, 2500);

        initDeliveryChat();

        // Ensure Leaflet map recalculates its dimensions after mobile layout reflow
        setTimeout(() => {
            if (map) {
                map.invalidateSize();
                fitMapAllMarkers();
            }
        }, 200);

        setTimeout(() => {
            if (map) {
                map.invalidateSize();
            }
        }, 800);

        window.addEventListener('resize', () => {
            if (map) map.invalidateSize();
        });
        window.addEventListener('orientationchange', () => {
            setTimeout(() => { if (map) map.invalidateSize(); }, 300);
        });
    }

    function fitMapAllMarkers() {
        if (!map) return;
        map.invalidateSize();
        const markers = [];
        if (driverMarker) markers.push(driverMarker);
        if (customerMarker) markers.push(customerMarker);
        if (storeMarker) markers.push(storeMarker);

        if (markers.length > 1) {
            const group = L.featureGroup(markers);
            map.fitBounds(group.getBounds().pad(0.18));
        } else if (markers.length === 1) {
            map.setView(markers[0].getLatLng(), 15);
        }
    }

    function playNotificationChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const audioCtx = new AudioCtx();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, audioCtx.currentTime);
            osc.frequency.setValueAtTime(880, audioCtx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.5);
        } catch (e) {}
    }

    function triggerToast(title, message) {
        const toast = document.getElementById('fpNotificationToast');
        const tTitle = document.getElementById('fpToastTitle');
        const tMsg = document.getElementById('fpToastMessage');
        if (toast && tTitle && tMsg) {
            tTitle.textContent = title;
            tMsg.textContent = message;
            toast.style.display = 'block';
        }
        playNotificationChime();
    }

    let isTrackingDataFetching = false;

    async function fetchTrackingData() {
        if (isTrackingDataFetching) return;
        isTrackingDataFetching = true;
        try {
            const res = await fetch(`get_tracking_info.php?order_id=${orderId}&_t=${Date.now()}`);
            const data = await res.json();
            if (!data.success) return;

            const status = String(data.status || 'pending').toLowerCase();
            
            // Check for status changes to notify customer
            if (previousDeliveryStatus && previousDeliveryStatus !== status) {
                if (isPickupOrder) {
                    if (['preparing', 'confirmed'].includes(status)) {
                        triggerToast('Kitchen is Cooking!', 'The store is now preparing your pick-up order.');
                    } else if (['ready', 'on_the_way'].includes(status)) {
                        triggerToast('Ready for Pick-up!', 'Your lechon order is ready at the store counter! You can head over now to collect it.');
                    } else if (status === 'delivered') {
                        triggerToast('Order Picked Up!', 'Thank you for picking up your order. Enjoy your meal!');
                    }
                } else {
                    if (['picked_up', 'on_the_way'].includes(status) && !['picked_up', 'on_the_way'].includes(previousDeliveryStatus)) {
                        triggerToast('Rider En Route!', `${data.driver_name || 'Your rider'} has picked up your order and is on the way.`);
                    } else if (status === 'arriving' && previousDeliveryStatus !== 'arriving') {
                        triggerToast('Rider Arriving Soon!', 'Your rider is right near your doorstep. Please get ready to receive your order.');
                    } else if (status === 'delivered') {
                        triggerToast('Order Delivered!', 'Your order has been delivered successfully. Enjoy your meal!');
                    }
                }
            }
            previousDeliveryStatus = status;

            if (isPickupOrder) {
                updateFoodpandaPickupStatusUI(status, data);
            } else {
                updateFoodpandaDeliveryStatusUI(status, data);
                const activeDeliveryStatuses = ['assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving'];
                if (activeDeliveryStatuses.includes(status) && data.latitude && data.longitude) {
                    updateDriverMarker(data.latitude, data.longitude, data.driver_name || 'Driver', status);
                    updateRouteAndEta(data.latitude, data.longitude, status);
                } else if (status === 'delivered') {
                    if (data.latitude && data.longitude) {
                        updateDriverMarker(data.latitude, data.longitude, data.driver_name || 'Driver', status);
                    }
                    clearRouteLines();
                } else {
                    if (driverMarker) {
                        map.removeLayer(driverMarker);
                        driverMarker = null;
                    }
                    clearRouteLines();
                }
            }
        } catch (err) {
            console.error('Error fetching live tracking:', err);
        } finally {
            isTrackingDataFetching = false;
        }
    }

    // Status UI for Pick-up Orders
    function updateFoodpandaPickupStatusUI(status, data) {
        const heroTitle = document.getElementById('fpHeroTitle');
        const heroSubtitle = document.getElementById('fpHeroSubtitle');
        const stepperFill = document.getElementById('fpStepperFill');
        const step1 = document.getElementById('fpStep1');
        const step2 = document.getElementById('fpStep2');
        const step3 = document.getElementById('fpStep3');
        const step4 = document.getElementById('fpStep4');
        const mapEtaStat = document.getElementById('mapEtaStat');

        [step1, step2, step3, step4].forEach(s => s.classList.remove('is-active', 'is-completed'));

        if (status === 'pending') {
            heroTitle.textContent = "Pick-up Order Received";
            heroSubtitle.textContent = "The store has received your order and will begin preparing it shortly.";
            stepperFill.style.width = "20%";
            step1.classList.add('is-active');
            if (mapEtaStat) mapEtaStat.textContent = "Pending";
        } else if (status === 'preparing' || status === 'confirmed') {
            heroTitle.textContent = "Kitchen is Roasting Your Lechon";
            heroSubtitle.textContent = "Your delicious dishes are being cooked fresh and packed for store pick-up.";
            stepperFill.style.width = "50%";
            step1.classList.add('is-completed');
            step2.classList.add('is-active');
            if (mapEtaStat) mapEtaStat.textContent = "Cooking";
        } else if (status === 'ready' || status === 'assigned' || status === 'on_the_way' || status === 'arriving') {
            heroTitle.textContent = "Ready for Pick-up at Store!";
            heroSubtitle.textContent = "Your order is packed and waiting at the counter. Present your Claim Code to the cashier.";
            stepperFill.style.width = "75%";
            step1.classList.add('is-completed');
            step2.classList.add('is-completed');
            step3.classList.add('is-active');
            document.getElementById('fpEtaValue').textContent = "Ready Now!";
            if (mapEtaStat) mapEtaStat.textContent = "Ready for Pick-up";
        } else if (status === 'delivered') {
            heroTitle.textContent = "Order Picked Up & Completed";
            heroSubtitle.textContent = "Thank you for collecting your order at Lechon Delights! Enjoy your meal.";
            stepperFill.style.width = "100%";
            step1.classList.add('is-completed');
            step2.classList.add('is-completed');
            step3.classList.add('is-completed');
            step4.classList.add('is-completed');
            document.getElementById('fpEtaValue').textContent = "Picked Up";
            if (mapEtaStat) mapEtaStat.textContent = "Completed";
        }
    }

    // Status UI for Delivery Orders
    function updateFoodpandaDeliveryStatusUI(status, data) {
        const heroTitle = document.getElementById('fpHeroTitle');
        const heroSubtitle = document.getElementById('fpHeroSubtitle');
        const stepperFill = document.getElementById('fpStepperFill');
        const step1 = document.getElementById('fpStep1');
        const step2 = document.getElementById('fpStep2');
        const step3 = document.getElementById('fpStep3');
        const step4 = document.getElementById('fpStep4');
        const riderCard = document.getElementById('fpRiderCard');
        const driverName = document.getElementById('fpDriverName');
        const phoneWrap = document.getElementById('fpDriverPhoneWrap');
        const phoneLink = document.getElementById('fpDriverPhoneLink');

        [step1, step2, step3, step4].forEach(s => s.classList.remove('is-active', 'is-completed'));

        const waitingCard = document.getElementById('fpWaitingRiderCard');
        const vehicleTag = document.getElementById('fpDriverVehicle');

        if (data.waiting_for_rider || status === 'pending' || (!data.driver_id && !data.driver_name)) {
            heroTitle.textContent = "Waiting for Rider to Pick Up";
            heroSubtitle.textContent = "Your order is confirmed and being prepared. Nearby delivery riders are currently being notified to accept your delivery.";
            stepperFill.style.width = "30%";
            step1.classList.add('is-completed');
            step2.classList.add('is-active');
            const step2Sub = document.getElementById('fpStep2Time');
            if (step2Sub) step2Sub.textContent = "In Progress";
            const step3Sub = document.getElementById('fpStep3Time');
            if (step3Sub) step3Sub.textContent = "Delivery";
            if (waitingCard) waitingCard.style.display = 'block';
            if (riderCard) riderCard.style.display = 'none';
        } else if (status === 'assigned') {
            const dName = data.driver_name || 'Your rider';
            heroTitle.textContent = `${dName} accepted your delivery!`;
            heroSubtitle.textContent = "Your rider has accepted the delivery and is heading to the store to pick up your order.";
            stepperFill.style.width = "40%";
            step1.classList.add('is-completed');
            step2.classList.add('is-completed');
            const step2Sub = document.getElementById('fpStep2Time');
            if (step2Sub) step2Sub.textContent = "Heading to store";
            const step3Sub = document.getElementById('fpStep3Time');
            if (step3Sub) step3Sub.textContent = "Waiting for pickup";
            if (waitingCard) waitingCard.style.display = 'none';
            if (riderCard) riderCard.style.display = 'block';
        } else if (status === 'arrived_at_restaurant') {
            const dName = data.driver_name || 'Your rider';
            heroTitle.textContent = `${dName} arrived at the store!`;
            heroSubtitle.textContent = "Your rider has arrived at the store and is waiting for the store owner to hand over your package.";
            stepperFill.style.width = "60%";
            step1.classList.add('is-completed');
            step2.classList.add('is-completed');
            step3.classList.add('is-active');
            const step2Sub = document.getElementById('fpStep2Time');
            if (step2Sub) step2Sub.textContent = "At Store";
            const step3Sub = document.getElementById('fpStep3Time');
            if (step3Sub) step3Sub.textContent = "Awaiting Handover";
            if (waitingCard) waitingCard.style.display = 'none';
            if (riderCard) riderCard.style.display = 'block';
        } else if (status === 'picked_up' || status === 'on_the_way') {
            const dName = data.driver_name || 'Rider';
            heroTitle.textContent = `${dName} is on the way!`;
            heroSubtitle.textContent = "Your rider has picked up your food and is driving to your address.";
            stepperFill.style.width = "75%";
            step1.classList.add('is-completed');
            step2.classList.add('is-completed');
            step3.classList.add('is-active');
            const step2Sub = document.getElementById('fpStep2Time');
            if (step2Sub) step2Sub.textContent = "Picked Up";
            const step3Sub = document.getElementById('fpStep3Time');
            if (step3Sub) step3Sub.textContent = "En Route";
            if (waitingCard) waitingCard.style.display = 'none';
            if (riderCard) riderCard.style.display = 'block';
        } else if (status === 'arriving') {
            heroTitle.textContent = "Rider is Arriving Soon!";
            heroSubtitle.textContent = "Your rider is right outside your location. Please prepare to receive your order.";
            stepperFill.style.width = "88%";
            step1.classList.add('is-completed');
            step2.classList.add('is-completed');
            step3.classList.add('is-active');
            const step3Sub = document.getElementById('fpStep3Time');
            if (step3Sub) step3Sub.textContent = "Arriving";
            if (waitingCard) waitingCard.style.display = 'none';
            if (riderCard) riderCard.style.display = 'block';
        } else if (status === 'delivered') {
            heroTitle.textContent = "Order Delivered!";
            heroSubtitle.textContent = "Your order was successfully delivered. Thank you for choosing Lechon Delights!";
            stepperFill.style.width = "100%";
            step1.classList.add('is-completed');
            step2.classList.add('is-completed');
            step3.classList.add('is-completed');
            step4.classList.add('is-completed');
            document.getElementById('fpEtaValue').textContent = "Delivered";
            if (waitingCard) waitingCard.style.display = 'none';
            if (riderCard) riderCard.style.display = 'block';
        }

        // Update Driver Card details
        if (data.driver_name || data.driver_id) {
            if (driverName) driverName.textContent = data.driver_name || 'Assigned Rider';
            if (vehicleTag) {
                vehicleTag.innerHTML = `<i class="fas fa-motorcycle me-1"></i> ${escapeHtml(data.driver_vehicle || 'Motorcycle')}`;
            }
            if (data.driver_phone) {
                if (phoneWrap) phoneWrap.style.display = 'block';
                if (phoneLink) {
                    phoneLink.style.display = 'inline-flex';
                    phoneLink.href = `tel:${data.driver_phone}`;
                }
            } else {
                if (phoneWrap) phoneWrap.style.display = 'none';
            }

            // Update Mobile Floating Rider Card
            const mobDriverName = document.getElementById('mobDriverName');
            if (mobDriverName) mobDriverName.textContent = data.driver_name || 'Assigned Rider';
            const mobCallBtn = document.getElementById('mobCallBtn');
            if (mobCallBtn && data.driver_phone) {
                mobCallBtn.href = `tel:${data.driver_phone}`;
            }

            // Sync Driver Star Rating live in mobile floating card and desktop card
            if (data.driver_rating) {
                const mobRating = document.getElementById('mobDriverRating');
                const deskRating = document.getElementById('desktopDriverRating');
                if (mobRating) mobRating.textContent = data.driver_rating;
                if (deskRating) deskRating.textContent = data.driver_rating;
            }
        }

        // Update Mobile Rider Status label
        const mobRiderStatus = document.getElementById('mobRiderStatus');
        if (mobRiderStatus) {
            if (status === 'assigned') mobRiderStatus.textContent = 'Heading to Store';
            else if (status === 'arrived_at_restaurant') mobRiderStatus.textContent = 'At Store';
            else if (status === 'picked_up' || status === 'on_the_way') mobRiderStatus.textContent = 'Arriving In';
            else if (status === 'arriving') mobRiderStatus.textContent = 'Arriving Soon';
            else if (status === 'delivered') mobRiderStatus.textContent = 'Delivered';
        }

        // Show Rate Driver Card once delivered
        if (status === 'delivered') {
            const rateCard = document.getElementById('fpRateDriverCard');
            if (rateCard) rateCard.style.display = 'block';
        }

        // Update Delivery Proof Photo Card
        if (data.proof_path) {
            const proofCard = document.getElementById('fpProofCard');
            const proofImg = document.getElementById('fpProofImg');
            if (proofCard && proofImg) {
                const fname = data.proof_path.split('/').pop();
                proofImg.src = 'uploads/proof_of_delivery/' + fname;
                proofCard.style.display = 'block';
            }
        }
    }

    function calculateHaversineDistanceKm(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    function ensureRouteLayers() {
        if (!map) return;
        if (!routeCasingPolyline) {
            const isDarkRoute = document.body.classList.contains('dark-mode') || document.documentElement.classList.contains('dark-mode') || document.documentElement.getAttribute('data-theme') === 'dark';
            routeCasingPolyline = L.polyline([], {
                color: isDarkRoute ? '#0f172a' : '#ffffff',
                weight: 8,
                opacity: 0.95,
                lineCap: 'round',
                lineJoin: 'round'
            }).addTo(map);
        }
        if (!routePolyline) {
            const isMobileScreen = window.innerWidth <= 768;
            routePolyline = L.polyline([], {
                color: isMobileScreen ? '#2563eb' : '#b3261e',
                weight: isMobileScreen ? 6 : 5,
                opacity: 0.95,
                lineCap: 'round',
                lineJoin: 'round'
            }).addTo(map);
        }
        if (!routeDashPolyline) {
            routeDashPolyline = L.polyline([], {
                color: '#fee4e2',
                weight: 2.5,
                opacity: 0.85,
                dashArray: '6, 10',
                lineCap: 'round',
                lineJoin: 'round'
            }).addTo(map);
        }
        if (!secondaryRoutePolyline) {
            secondaryRoutePolyline = L.polyline([], {
                color: '#667085',
                weight: 3.5,
                opacity: 0.7,
                dashArray: '6, 8',
                lineCap: 'round',
                lineJoin: 'round'
            }).addTo(map);
        }
        if (!riderTrailPolyline) {
            riderTrailPolyline = L.polyline([], {
                color: '#f97316',
                weight: 3.5,
                opacity: 0.85,
                dashArray: '4, 6',
                lineCap: 'round'
            }).addTo(map);
        }
    }

    function setRouteCoordinates(latLngs) {
        ensureRouteLayers();
        if (!latLngs || latLngs.length === 0) return;
        if (routeCasingPolyline) routeCasingPolyline.setLatLngs(latLngs);
        if (routePolyline) routePolyline.setLatLngs(latLngs);
        if (routeDashPolyline) routeDashPolyline.setLatLngs(latLngs);
    }

    function setSecondaryRouteCoordinates(latLngs) {
        ensureRouteLayers();
        if (secondaryRoutePolyline) {
            secondaryRoutePolyline.setLatLngs(latLngs || []);
        }
    }

    function clearRouteLines() {
        if (routeCasingPolyline) routeCasingPolyline.setLatLngs([]);
        if (routePolyline) routePolyline.setLatLngs([]);
        if (routeDashPolyline) routeDashPolyline.setLatLngs([]);
        if (secondaryRoutePolyline) secondaryRoutePolyline.setLatLngs([]);
    }

    function updateRouteHeadPoint(currentPos) {
        if (!routePolyline) return;
        const latLngs = routePolyline.getLatLngs();
        if (latLngs && latLngs.length > 0) {
            latLngs[0] = L.latLng(currentPos[0], currentPos[1]);
            setRouteCoordinates(latLngs);
        }
    }

    function formatDistanceDisplay(km) {
        const numKm = parseFloat(km) || 0;
        if (numKm < 0.95) {
            const meters = Math.max(20, Math.round(numKm * 1000));
            return `${meters} m`;
        }
        return `${numKm.toFixed(1)} km`;
    }

    function updateMapEtaPill(distFormatted, durMins, isHeadingToStore, status, rawDistKm = null) {
        const distEl = document.getElementById('mapDistStat');
        const etaEl = document.getElementById('mapEtaStat');
        const fpEtaVal = document.getElementById('fpEtaValue');
        const mobEtaVal = document.getElementById('mobRiderEta');

        let etaText = durMins > 1 ? `${durMins} mins` : '1 min';
        const numKm = (rawDistKm !== null) ? parseFloat(rawDistKm) : (parseFloat(distFormatted) || 0);
        if (numKm > 0 && numKm < 0.15 && ['on_the_way', 'arriving'].includes(status)) {
            etaText = 'Arriving now';
        }

        if (distEl) {
            if (isHeadingToStore) {
                distEl.textContent = `${distFormatted} to store`;
            } else {
                distEl.textContent = distFormatted;
            }
        }

        if (etaEl) {
            if (isHeadingToStore) {
                etaEl.textContent = (status === 'arrived_at_restaurant') ? 'At Restaurant' : `${etaText} to store`;
            } else {
                etaEl.textContent = etaText;
            }
        }

        const etaLabelEl = document.getElementById('mapEtaLabel');
        if (etaLabelEl && !isPickupOrder) {
            etaLabelEl.textContent = 'ETA:';
        }

        if (fpEtaVal && status !== 'delivered') {
            if (isHeadingToStore) {
                fpEtaVal.textContent = (status === 'arrived_at_restaurant') ? 'At Store (Waiting)' : `~${etaText} to store`;
            } else {
                fpEtaVal.textContent = `${etaText} (${distFormatted})`;
            }
        }

        if (mobEtaVal && status !== 'delivered') {
            if (isHeadingToStore) {
                mobEtaVal.textContent = (status === 'arrived_at_restaurant') ? 'At Store' : etaText;
            } else {
                mobEtaVal.textContent = `${etaText} • ${distFormatted}`;
            }
        }
    }

    function animateDriverMovement(targetPosition, durationMs = 1500) {
        if (!driverMarker || !targetPosition) return;
        const startLatLng = driverMarker.getLatLng();
        if (!startLatLng) {
            driverMarker.setLatLng(targetPosition);
            updateRouteHeadPoint(targetPosition);
            return;
        }

        const startLat = startLatLng.lat;
        const startLng = startLatLng.lng;
        const endLat = targetPosition[0];
        const endLng = targetPosition[1];

        // Avoid animation overhead for sub-meter fluctuations
        const dLat = Math.abs(endLat - startLat);
        const dLng = Math.abs(endLng - startLng);
        if (dLat < 0.000005 && dLng < 0.000005) {
            return;
        }

        if (driverMoveRaf) cancelAnimationFrame(driverMoveRaf);

        // Calculate travel heading angle and rotate the motorcycle pin
        const bearingDeg = (Math.atan2(endLng - startLng, endLat - startLat) * 180 / Math.PI + 360) % 360;
        const markerIconEl = (typeof driverMarker.getElement === 'function') ? driverMarker.getElement() : null;
        if (markerIconEl) {
            const innerPin = markerIconEl.querySelector('.fa-motorcycle');
            if (innerPin) {
                innerPin.style.transition = 'transform 0.4s ease';
                innerPin.style.transform = `rotate(${Math.round(bearingDeg - 45)}deg)`;
            }
        }

        const startAt = performance.now();

        const step = (now) => {
            const t = Math.min((now - startAt) / durationMs, 1);
            // Smooth ease-out cubic interpolation
            const eased = 1 - Math.pow(1 - t, 3);
            const curLat = startLat + (endLat - startLat) * eased;
            const curLng = startLng + (endLng - startLng) * eased;
            
            driverMarker.setLatLng([curLat, curLng]);
            updateRouteHeadPoint([curLat, curLng]);

            if (t < 1) {
                driverMoveRaf = requestAnimationFrame(step);
            } else {
                driverMoveRaf = null;
                if (isAutoTrackingRider && map) {
                    map.panTo([endLat, endLng], { animate: true, duration: 0.5 });
                }
            }
        };
        driverMoveRaf = requestAnimationFrame(step);
    }

    function updateDriverMarker(lat, lng, name, status) {
        if (!lat || !lng) {
            if (driverMarker) {
                map.removeLayer(driverMarker);
                driverMarker = null;
            }
            return;
        }

        const point = [parseFloat(lat), parseFloat(lng)];

        if (!driverMarker) {
            driverMarker = L.marker(point, { icon: riderIcon, title: name }).addTo(map);
            if (isAutoTrackingRider && map) {
                map.setView(point, 16);
            }
        } else {
            animateDriverMovement(point, 1400);
        }

        if (!hasAutoFitBounds) {
            fitMapAllMarkers();
            hasAutoFitBounds = true;
        }

        // Add point to trail
        ensureRouteLayers();
        if (riderTrailPolyline) {
            const pts = riderTrailPolyline.getLatLngs();
            const shouldAdd = pts.length === 0 || 
                Math.abs(pts[pts.length - 1].lat - point[0]) > 0.00003 || 
                Math.abs(pts[pts.length - 1].lng - point[1]) > 0.00003;
            if (shouldAdd) {
                pts.push(L.latLng(point[0], point[1]));
                if (pts.length > 200) pts.shift();
                riderTrailPolyline.setLatLngs(pts);
            }
        }

        lastDriverLatLng = point;
    }

    async function updateRouteAndEta(driverLat, driverLng, status = 'on_the_way', force = false) {
        ensureRouteLayers();
        if (!driverLat || !driverLng) return;

        // Determine destination target based on current fulfillment status
        let targetCoords = null;
        const isHeadingToStore = ['assigned', 'arrived_at_restaurant'].includes(status);

        if (isHeadingToStore && storeLocationObj) {
            targetCoords = storeLocationObj;
        } else if (customerLocationObj) {
            targetCoords = customerLocationObj;
        } else if (storeLocationObj) {
            targetCoords = storeLocationObj;
        }

        if (!targetCoords) return;

        const driverPoint = [parseFloat(driverLat), parseFloat(driverLng)];
        const destPoint = [parseFloat(targetCoords[0]), parseFloat(targetCoords[1])];

        // 1. INSTANT FALLBACK POLYLINE: Always ensure a visible line connects driver to destination immediately!
        const currentLatLngs = routePolyline ? routePolyline.getLatLngs() : [];
        if (!currentLatLngs || currentLatLngs.length === 0) {
            setRouteCoordinates([driverPoint, destPoint]);
            if (isHeadingToStore && customerLocationObj) {
                setSecondaryRouteCoordinates([storeLocationObj, customerLocationObj]);
            } else {
                setSecondaryRouteCoordinates([]);
            }
        } else {
            updateRouteHeadPoint(driverPoint);
        }

        // Direct distance calculation (Haversine) - ALWAYS CALCULATE & UPDATE LIVE UI IMMEDIATELY!
        const straightDistKm = calculateHaversineDistanceKm(driverPoint[0], driverPoint[1], destPoint[0], destPoint[1]);
        const estimatedRoadDistKm = straightDistKm * 1.25;
        const estimatedMins = Math.max(1, Math.round((estimatedRoadDistKm / 24) * 60));
        const formattedDist = formatDistanceDisplay(estimatedRoadDistKm);

        // Update distance and ETA pills instantly on every location update
        updateMapEtaPill(formattedDist, estimatedMins, isHeadingToStore, status, estimatedRoadDistKm);

        // Throttle external OSRM directions calls to avoid rate limits (refresh route every 6s or if moved > 25m)
        const now = Date.now();
        const coordsKey = `${Math.round(driverPoint[0] * 5000)}_${Math.round(driverPoint[1] * 5000)}_${status}`;
        if (!force && now - lastRouteRefreshAt < 6000 && lastRouteCoordsKey === coordsKey) {
            return;
        }
        lastRouteRefreshAt = now;
        lastRouteCoordsKey = coordsKey;

        // 2. FETCH PRECISE TURN-BY-TURN STREET GEOMETRY (Streets & Highways)
        try {
            const apiUrl = `api/get_directions.php?origin_lat=${driverPoint[0]}&origin_lng=${driverPoint[1]}&dest_lat=${destPoint[0]}&dest_lng=${destPoint[1]}`;
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 6000);
            
            const response = await fetch(apiUrl, { signal: controller.signal });
            clearTimeout(timeoutId);

            if (response.ok) {
                const data = await response.json();
                if (data.success && Array.isArray(data.coordinates) && data.coordinates.length > 0) {
                    setRouteCoordinates(data.coordinates);

                    if (isHeadingToStore && customerLocationObj) {
                        fetchSecondaryStreetRoute(storeLocationObj, customerLocationObj);
                    } else {
                        setSecondaryRouteCoordinates([]);
                    }

                    const routeDistFormatted = formatDistanceDisplay(data.distance_km);
                    updateMapEtaPill(routeDistFormatted, data.duration_mins, isHeadingToStore, status, data.distance_km);
                    return;
                }
            }
        } catch (e) {
            console.debug('Local directions proxy notice:', e.message);
        }

        // Direct fallback to OSRM if local proxy had network glitch
        try {
            const endpoint = `https://router.project-osrm.org/route/v1/driving/${driverPoint[1]},${driverPoint[0]};${destPoint[1]},${destPoint[0]}?overview=full&geometries=geojson`;
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 6000);
            
            const response = await fetch(endpoint, { signal: controller.signal });
            clearTimeout(timeoutId);

            if (response.ok) {
                const data = await response.json();
                if (data.routes && data.routes.length > 0) {
                    const route = data.routes[0];
                    const latLngs = route.geometry.coordinates.map(c => [c[1], c[0]]);
                    setRouteCoordinates(latLngs);

                    if (isHeadingToStore && customerLocationObj) {
                        setSecondaryRouteCoordinates([storeLocationObj, customerLocationObj]);
                    } else {
                        setSecondaryRouteCoordinates([]);
                    }

                    const durMins = Math.round(route.duration / 60);
                    const distKm = (route.distance / 1000).toFixed(1);

                    updateMapEtaPill(distKm, durMins, isHeadingToStore, status);
                }
            }
        } catch (e) {
            console.debug('OSRM direct fallback notice:', e.message);
        }
    }

    async function fetchSecondaryStreetRoute(fromCoords, toCoords) {
        if (!fromCoords || !toCoords) return;
        try {
            const url = `api/get_directions.php?origin_lat=${fromCoords[0]}&origin_lng=${fromCoords[1]}&dest_lat=${toCoords[0]}&dest_lng=${toCoords[1]}`;
            const res = await fetch(url);
            if (res.ok) {
                const d = await res.json();
                if (d.success && Array.isArray(d.coordinates) && d.coordinates.length > 0) {
                    setSecondaryRouteCoordinates(d.coordinates);
                    return;
                }
            }
        } catch (e) {}
        setSecondaryRouteCoordinates([fromCoords, toCoords]);
    }

    // In-App Rider Chat Controller (for Delivery)
    function initDeliveryChat() {
        const form = document.getElementById('deliveryChatForm');
        if (form) {
            form.addEventListener('submit', handleSendChat);
        }

        document.querySelectorAll('.js-quick-chip').forEach(btn => {
            btn.addEventListener('click', function() {
                const input = document.getElementById('deliveryChatInput');
                if (input) {
                    input.value = this.dataset.msg;
                    form.dispatchEvent(new Event('submit'));
                }
            });
        });

        loadDeliveryChat(true);
        chatPollTimer = setInterval(() => loadDeliveryChat(false), 4500);
    }

    async function loadDeliveryChat(initialLoad) {
        if (chatLoading) return;
        chatLoading = true;
        try {
            const res = await fetch(`api/delivery_chat.php?order_id=${orderId}&after_id=${chatLastMessageId}&limit=50`);
            const data = await res.json();
            if (!data.success) return;

            chatAvailable = !!data.chat_available;
            const container = document.getElementById('deliveryChatMessages');
            if (!container) return;

            if (initialLoad) {
                container.innerHTML = '';
            }

            const messages = Array.isArray(data.messages) ? data.messages : [];
            if (messages.length === 0 && chatLastMessageId === 0) {
                container.innerHTML = '<div style="text-align:center; padding:16px; color:#667085; font-size:0.84rem;">No messages yet. Send a note to your rider.</div>';
            }

            messages.forEach(msg => {
                appendChatMessage(msg);
                chatLastMessageId = Math.max(chatLastMessageId, Number(msg.id || 0));
            });
        } catch (e) {
            console.error('Chat load error:', e);
        } finally {
            chatLoading = false;
        }
    }

    async function handleSendChat(e) {
        e.preventDefault();
        const input = document.getElementById('deliveryChatInput');
        const sendBtn = document.getElementById('deliveryChatSendBtn');
        const text = (input.value || '').trim();
        if (!text) return;

        sendBtn.disabled = true;
        try {
            const res = await fetch('api/delivery_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId, message: text })
            });
            const data = await res.json();
            if (data.success && data.message) {
                input.value = '';
                appendChatMessage(data.message, true);
                chatLastMessageId = Math.max(chatLastMessageId, Number(data.message.id || 0));
            }
        } catch (err) {
            console.error('Send error:', err);
        } finally {
            sendBtn.disabled = false;
        }
    }

    function appendChatMessage(msg, forceScroll = false) {
        const container = document.getElementById('deliveryChatMessages');
        if (!container) return;
        if (container.querySelector('div[style*="text-align:center"]')) {
            container.innerHTML = '';
        }

        const role = msg.sender_role === 'driver' ? 'driver' : 'customer';
        const wrap = document.createElement('div');
        wrap.className = `fp-chat-msg ${role}`;

        const bubble = document.createElement('div');
        bubble.className = 'fp-chat-bubble';
        bubble.textContent = msg.message_text || msg.message || '';

        const time = document.createElement('div');
        time.className = 'fp-chat-time';
        time.textContent = msg.created_at ? new Date(msg.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Just now';

        wrap.appendChild(bubble);
        wrap.appendChild(time);
        container.appendChild(wrap);

        if (forceScroll || (container.scrollHeight - container.scrollTop - container.clientHeight < 80)) {
            container.scrollTop = container.scrollHeight;
        }
    }

    // ==========================================
    // Driver Star Rating Picker & Submission
    // ==========================================
    let currentSelectedDriverRating = 0;
    const driverStarLabels = {
        1: '1 - Poor',
        2: '2 - Fair',
        3: '3 - Good',
        4: '4 - Very Good',
        5: '5 - Excellent!'
    };

    function initDriverRatingPicker() {
        const starBtns = document.querySelectorAll('#starRatingGroup .fp-star-btn');
        const label = document.getElementById('starRatingLabel');
        const hiddenInput = document.getElementById('selectedDriverRating');
        if (!starBtns.length) return;

        starBtns.forEach(btn => {
            btn.addEventListener('mouseenter', () => {
                const val = parseInt(btn.getAttribute('data-star'));
                highlightDriverStars(val);
                if (label) label.textContent = driverStarLabels[val] || '';
            });

            btn.addEventListener('mouseleave', () => {
                highlightDriverStars(currentSelectedDriverRating);
                if (label) {
                    label.textContent = currentSelectedDriverRating > 0 ? (driverStarLabels[currentSelectedDriverRating] || '') : 'Select stars';
                }
            });

            btn.addEventListener('click', () => {
                currentSelectedDriverRating = parseInt(btn.getAttribute('data-star'));
                if (hiddenInput) hiddenInput.value = currentSelectedDriverRating;
                highlightDriverStars(currentSelectedDriverRating);
                if (label) label.textContent = driverStarLabels[currentSelectedDriverRating] || '';
            });
        });
    }

    function highlightDriverStars(val) {
        const starBtns = document.querySelectorAll('#starRatingGroup .fp-star-btn');
        starBtns.forEach(btn => {
            const starNum = parseInt(btn.getAttribute('data-star'));
            if (starNum <= val) {
                btn.style.color = '#eab308';
                btn.style.transform = 'scale(1.15)';
            } else {
                btn.style.color = '#d0d5dd';
                btn.style.transform = 'scale(1.0)';
            }
        });
    }

    async function submitDriverRating() {
        const ratingInput = document.getElementById('selectedDriverRating');
        const commentInput = document.getElementById('rateDriverComment');
        const btn = document.getElementById('btnSubmitDriverRating');
        const msg = document.getElementById('rateDriverMsg');

        const ratingVal = parseInt(ratingInput ? ratingInput.value : 0);
        const commentVal = commentInput ? commentInput.value.trim() : '';

        if (ratingVal < 1 || ratingVal > 5) {
            if (msg) {
                msg.style.color = '#b3261e';
                msg.textContent = 'Please select 1 to 5 stars.';
            }
            return;
        }

        if (btn) btn.disabled = true;
        if (msg) {
            msg.style.color = '#475467';
            msg.textContent = 'Submitting your rating...';
        }

        try {
            const res = await fetch('api/rate_driver.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_id: orderId,
                    rating: ratingVal,
                    comment: commentVal
                })
            });
            const data = await res.json();
            if (data.success) {
                const formWrap = document.getElementById('rateDriverFormWrap');
                const completedWrap = document.getElementById('rateDriverCompletedWrap');
                const badge = document.getElementById('rateDriverStatusBadge');
                const ratingText = document.getElementById('completedRatingText');
                const commentText = document.getElementById('completedCommentText');
                const starIconsWrap = document.getElementById('completedStarIcons');

                if (formWrap) formWrap.style.display = 'none';
                if (completedWrap) completedWrap.style.display = 'block';
                if (badge) {
                    badge.style.background = '#ecfdf3';
                    badge.style.color = '#027a48';
                    badge.style.borderColor = '#abefc6';
                    badge.textContent = 'Rated';
                }
                if (ratingText) ratingText.textContent = `${ratingVal} / 5 Stars`;
                if (commentText) {
                    commentText.textContent = commentVal ? `"${escapeHtml(commentVal)}"` : 'Thank you for rating your delivery rider! Your review helps them maintain quality service.';
                }
                if (starIconsWrap) {
                    let starsHtml = '';
                    for (let i = 1; i <= 5; i++) {
                        if (i <= ratingVal) {
                            starsHtml += '<i class="fas fa-star"></i> ';
                        } else {
                            starsHtml += '<i class="far fa-star" style="color:#d0d5dd;"></i> ';
                        }
                    }
                    starIconsWrap.innerHTML = starsHtml;
                }

                // Update live driver rating in mobile floating card and desktop card
                if (data.new_driver_rating) {
                    const mobRating = document.getElementById('mobDriverRating');
                    const deskRating = document.getElementById('desktopDriverRating');
                    if (mobRating) mobRating.textContent = data.new_driver_rating;
                    if (deskRating) deskRating.textContent = data.new_driver_rating;
                }
            } else {
                if (msg) {
                    msg.style.color = '#b3261e';
                    msg.textContent = data.message || 'Error saving rating.';
                }
                if (btn) btn.disabled = false;
            }
        } catch (e) {
            if (msg) {
                msg.style.color = '#b3261e';
                msg.textContent = 'Network error. Please try again.';
            }
            if (btn) btn.disabled = false;
        }
    }

    window.addEventListener('beforeunload', () => {
        if (trackingPollTimer) clearInterval(trackingPollTimer);
        if (chatPollTimer) clearInterval(chatPollTimer);
    });

    document.addEventListener('DOMContentLoaded', () => {
        initMap();
        initDriverRatingPicker();
    });
</script>

<?php
include 'includes/footer.php';
mysqli_close($conn);
?>
