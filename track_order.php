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
   Responsive Adaptations
   ========================================================================== */
@media (max-width: 992px) {
    .fp-tracking-grid {
        grid-template-columns: 1fr;
    }
    #map {
        height: 420px;
    }
    .fp-hero-header-row {
        flex-direction: column;
        align-items: flex-start;
    }
    .fp-hero-right {
        align-items: flex-start;
        width: 100%;
    }
    .fp-eta-pill {
        width: 100%;
        justify-content: space-between;
    }
    .fp-stepper-steps {
        overflow-x: auto;
    }
    .fp-step-item {
        width: 75px;
    }
    .fp-step-icon-wrap {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    .fp-stepper-track-bg, .fp-stepper-track-fill {
        top: 28px;
        left: 35px;
        right: 35px;
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
                        <div style="background:#fff1f0; border:1px solid #fee4e2; color:#b3261e; font-weight:800; font-size:0.82rem; padding:4px 12px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;" title="Provide this 4-digit PIN to your rider upon arrival">
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
        </div>

        <!-- Main Tracking Split Screen Grid -->
        <div class="fp-tracking-grid">
            
            <!-- Left Column: Full-Height Interactive Map -->
            <div class="fp-map-card">
                <div class="fp-map-floating-bar">
                    <div class="fp-map-pill-stat">
                        <div class="stat-item">
                            <i class="<?php echo $is_pickup ? 'fas fa-store' : 'fas fa-route'; ?>" style="color:var(--fp-primary);"></i>
                            <span><?php echo $is_pickup ? 'Store:' : 'Dist:'; ?> <strong id="mapDistStat"><?php echo htmlspecialchars($store_details['store_name'] ?? 'Lechon Delights'); ?></strong></span>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-clock" style="color:#027a48;"></i>
                            <span>Status: <strong id="mapEtaStat"><?php echo ucfirst(htmlspecialchars((string)$order['status'])); ?></strong></span>
                        </div>
                    </div>

                    <div class="fp-map-actions">
                        <button type="button" class="fp-map-btn" id="btnFocusStore" title="Focus Store Location"><i class="fas fa-store"></i></button>
                        <?php if (!$is_pickup): ?>
                        <button type="button" class="fp-map-btn" id="btnFocusRider" title="Focus Rider"><i class="fas fa-motorcycle"></i></button>
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
                        <div style="width:48px; height:48px; border-radius:14px; background:#fff1f0; color:#b3261e; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0;">
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
                                    $img_src = !empty($item['product_image']) ? 'uploads/products/' . htmlspecialchars($item['product_image']) : 'assets/images/promo_lechon.jpg';
                                    ?>
                                    <img src="<?php echo $img_src; ?>" class="fp-item-thumb" alt="<?php echo htmlspecialchars($item['product_name']); ?>" onerror="this.onerror=null;this.src='assets/images/promo_lechon.jpg';">
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
                            <div style="margin-top:8px; font-size:0.78rem; color:#667085; display:flex; justify-content:space-between;">
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
    const isPickupOrder = <?php echo json_encode($is_pickup); ?>;
    const orderId = <?php echo (int)$order_id; ?>;

    let map = null;
    let driverMarker = null;
    let customerMarker = null;
    let storeMarker = null;
    let routePolyline = null;
    let riderTrailPolyline = null;

    let customerLocationObj = null;
    let storeLocationObj = null;
    let lastDriverLatLng = null;
    let lastRouteRefreshAt = 0;
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
        routePolyline = L.polyline([], {
            color: '#b3261e',
            weight: 5,
            opacity: 0.85,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(map);

        riderTrailPolyline = L.polyline([], {
            color: '#ef6b2e',
            weight: 4,
            opacity: 0.9,
            dashArray: '6, 8'
        }).addTo(map);

        // Add Customer Marker
        if (Number.isFinite(customerLat) && Number.isFinite(customerLng)) {
            customerLocationObj = [customerLat, customerLng];
            customerMarker = L.marker(customerLocationObj, { icon: customerIcon }).addTo(map);
            map.setView(customerLocationObj, 14);
        } else {
            forwardGeocodeFromNominatim(customerAddress).then(coords => {
                if (coords) {
                    customerLocationObj = [coords.lat, coords.lng];
                    customerMarker = L.marker(customerLocationObj, { icon: customerIcon }).addTo(map);
                    fitMapAllMarkers();
                    if (lastDriverLatLng) {
                        updateRouteAndEta(lastDriverLatLng[0], lastDriverLatLng[1], true);
                    }
                }
            });
        }

        // Attach Map Action Buttons for Delivery
        const btnFocusRider = document.getElementById('btnFocusRider');
        if (btnFocusRider) {
            btnFocusRider.addEventListener('click', () => {
                if (driverMarker) map.setView(driverMarker.getLatLng(), 16);
            });
        }

        const btnFocusHome = document.getElementById('btnFocusHome');
        if (btnFocusHome) {
            btnFocusHome.addEventListener('click', () => {
                if (customerMarker) map.setView(customerMarker.getLatLng(), 16);
            });
        }

        const btnFocusStore = document.getElementById('btnFocusStore');
        if (btnFocusStore) {
            btnFocusStore.addEventListener('click', () => {
                if (storeMarker) map.setView(storeMarker.getLatLng(), 16);
            });
        }

        const btnFitRoute = document.getElementById('btnFitRoute');
        if (btnFitRoute) {
            btnFitRoute.addEventListener('click', fitMapAllMarkers);
        }

        // Initialize driver marker immediately on page load if coordinates are available
        if (Number.isFinite(initialDriverLat) && Number.isFinite(initialDriverLng) && ['assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving'].includes(initialDeliveryStatus)) {
            updateDriverMarker(initialDriverLat, initialDriverLng, initialDriverName, initialDeliveryStatus);
            if (['picked_up', 'on_the_way', 'arriving'].includes(initialDeliveryStatus)) {
                updateRouteAndEta(initialDriverLat, initialDriverLng);
            }
        }

        fetchTrackingData();
        trackingPollTimer = setInterval(fetchTrackingData, 3500);

        initDeliveryChat();
    }

    function fitMapAllMarkers() {
        if (!map) return;
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

    async function fetchTrackingData() {
        try {
            const res = await fetch(`get_tracking_info.php?order_id=${orderId}`);
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
                    if (['picked_up', 'on_the_way', 'arriving'].includes(status)) {
                        updateRouteAndEta(data.latitude, data.longitude);
                    }
                } else if (status === 'delivered') {
                    if (data.latitude && data.longitude) {
                        updateDriverMarker(data.latitude, data.longitude, data.driver_name || 'Driver', status);
                    }
                    if (routePolyline) {
                        map.removeLayer(routePolyline);
                        routePolyline = null;
                    }
                } else {
                    if (driverMarker) {
                        map.removeLayer(driverMarker);
                        driverMarker = null;
                    }
                    if (routePolyline) {
                        map.removeLayer(routePolyline);
                        routePolyline = null;
                    }
                }
            }
        } catch (err) {
            console.error('Error fetching live tracking:', err);
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

    function animateDriverMovement(targetPosition, durationMs = 1200) {
        if (!driverMarker || !targetPosition) return;
        const startLatLng = driverMarker.getLatLng();
        if (!startLatLng) {
            driverMarker.setLatLng(targetPosition);
            return;
        }

        if (driverMoveRaf) cancelAnimationFrame(driverMoveRaf);

        const startLat = startLatLng.lat;
        const startLng = startLatLng.lng;
        const endLat = targetPosition[0];
        const endLng = targetPosition[1];
        const startAt = performance.now();

        const step = (now) => {
            const t = Math.min((now - startAt) / durationMs, 1);
            const eased = 1 - Math.pow(1 - t, 3);
            const curLat = startLat + (endLat - startLat) * eased;
            const curLng = startLng + (endLng - startLng) * eased;
            driverMarker.setLatLng([curLat, curLng]);
            if (t < 1) {
                driverMoveRaf = requestAnimationFrame(step);
            } else {
                driverMoveRaf = null;
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
        } else {
            animateDriverMovement(point, 1100);
        }

        if (!hasAutoFitBounds) {
            fitMapAllMarkers();
            hasAutoFitBounds = true;
        }

        // Add point to trail
        if (riderTrailPolyline) {
            const pts = riderTrailPolyline.getLatLngs();
            pts.push(point);
            riderTrailPolyline.setLatLngs(pts);
        }

        lastDriverLatLng = point;
    }

    async function updateRouteAndEta(driverLat, driverLng, force = false) {
        if (!customerLocationObj) return;
        const now = Date.now();
        if (!force && now - lastRouteRefreshAt < 12000) return;
        lastRouteRefreshAt = now;

        try {
            const endpoint = `https://router.project-osrm.org/route/v1/driving/${driverLng},${driverLat};${customerLocationObj[1]},${customerLocationObj[0]}?overview=full&geometries=geojson`;
            const response = await fetch(endpoint);
            if (!response.ok) return;
            const data = await response.json();
            if (data.routes && data.routes.length > 0) {
                const route = data.routes[0];
                const latLngs = route.geometry.coordinates.map(c => [c[1], c[0]]);
                if (routePolyline) routePolyline.setLatLngs(latLngs);

                const durMins = Math.round(route.duration / 60);
                const etaText = durMins > 1 ? `${durMins} mins` : '1 min';
                const distKm = (route.distance / 1000).toFixed(1);

                document.getElementById('mapDistStat').textContent = `${distKm} km`;
                document.getElementById('mapEtaStat').textContent = etaText;
                
                if (previousDeliveryStatus !== 'delivered') {
                    document.getElementById('fpEtaValue').textContent = `${etaText} (${distKm} km)`;
                }
            }
        } catch (e) {
            console.error('Route calculation error:', e);
        }
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

    window.addEventListener('beforeunload', () => {
        if (trackingPollTimer) clearInterval(trackingPollTimer);
        if (chatPollTimer) clearInterval(chatPollTimer);
    });

    document.addEventListener('DOMContentLoaded', () => {
        initMap();
    });
</script>

<?php
include 'includes/footer.php';
mysqli_close($conn);
?>
