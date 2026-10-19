<?php
$page_title = 'Active Delivery HUD';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
require_once __DIR__ . '/header.php';

$rider_pk = (int)$rider['id'];
$emp_id = (int)($rider['employee_id'] ?? 0);

// Get currently active delivery
$active_delivery = getRiderActiveDelivery($rider_pk, $emp_id);

if (!$active_delivery) {
    // If no active delivery, redirect to dashboard or show empty state
    ?>
    <div class="px-3 pt-5 text-center">
        <div class="rider-card p-4 mx-auto" style="max-width: 440px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
            <div style="font-size: 48px; color: #98a2b3; margin-bottom: 12px;">
                <i class="fas fa-motorcycle"></i>
            </div>
            <h5 class="fw-bold mb-1" style="color: var(--primary-ink);">No Active Delivery in Progress</h5>
            <p class="text-muted small mb-4">You do not have any orders assigned to you right now. Go to Home to receive new requests.</p>
            <a href="index.php" class="btn-rider-primary py-2 px-4" style="border-radius: 12px;">
                <i class="fas fa-home me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}

$order_id = (int)$active_delivery['order_id'];
$tracking_id = (int)$active_delivery['id'];
$order_number = $active_delivery['order_number'];
$current_status = $active_delivery['current_status'] ?? 'assigned';

// Fetch items for this order
$items_q = mysqli_query($conn, "SELECT oi.*, p.name AS product_name FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = $order_id");
$order_items = [];
if ($items_q) {
    while ($it = mysqli_fetch_assoc($items_q)) {
        $order_items[] = $it;
    }
}

// Payment method determination
$is_cod = (stripos($active_delivery['payment_method'] ?? '', 'cod') !== false || stripos($active_delivery['payment_method'] ?? '', 'cash') !== false);
$order_total = (float)($active_delivery['total_amount'] ?? 0);
$delivery_fee = (float)($active_delivery['delivery_fee'] ?? 50.00);

// Coordinates with robust fallbacks
$store_lat = (float)($active_delivery['store_latitude'] ?? 14.3294);
$store_lng = (float)($active_delivery['store_longitude'] ?? 120.9367);
$cust_lat = !empty($active_delivery['customer_latitude']) ? (float)$active_delivery['customer_latitude'] : (!empty($active_delivery['latitude']) ? (float)$active_delivery['latitude'] : null);
$cust_lng = !empty($active_delivery['customer_longitude']) ? (float)$active_delivery['customer_longitude'] : (!empty($active_delivery['longitude']) ? (float)$active_delivery['longitude'] : null);
if ($cust_lat === null || $cust_lat == 0.0) {
    $cust_lat = round($store_lat - 0.0125, 6);
    $cust_lng = round($store_lng + 0.0085, 6);
}

$store_name = trim($active_delivery['store_name'] ?? 'Restaurant Branch');
$store_address = trim(($active_delivery['store_address'] ?? '') . ' ' . ($active_delivery['store_city'] ?? ''));
if ($store_address === '') {
    $store_address = 'Store Fulfillment Branch';
}

// Determine current step index (0 to 7) based on status
$step_index = 0;
if ($current_status === 'assigned') {
    $step_index = 1; // Going to Restaurant
} elseif ($current_status === 'arrived_at_restaurant') {
    $step_index = 2; // Arrived at Restaurant / Awaiting Handover
} elseif ($current_status === 'picked_up') {
    $step_index = 3; // Food Handover / Picked Up
} elseif ($current_status === 'on_the_way') {
    $step_index = 4; // Going to Customer
} elseif ($current_status === 'arriving') {
    $step_index = 5; // Arrived at Customer
} elseif ($current_status === 'delivered') {
    $step_index = 7; // Completed
}

// Dynamic ETA calculations
$eta_minutes = 12;
$eta_sub = 'Estimated ' . date('g:i A', strtotime('+15 minutes')) . ' &bull; on time';
if (!empty($active_delivery['estimated_delivery'])) {
    $eta_ts = strtotime($active_delivery['estimated_delivery']);
    if ($eta_ts && $eta_ts > time()) {
        $calc_mins = (int)ceil(($eta_ts - time()) / 60);
        $eta_minutes = max(1, $calc_mins);
        $eta_sub = 'Estimated ' . date('g:i A', $eta_ts) . ' &bull; on schedule';
    }
}

if ($step_index < 2) {
    $eta_headline = 'Arriving in ' . $eta_minutes . ' min';
    $eta_status_label = 'Heading to Restaurant &bull; ' . $eta_sub;
} elseif ($step_index == 2) {
    $eta_headline = 'At Restaurant';
    $eta_status_label = 'Waiting for Food Handover';
} elseif ($step_index == 3 || $step_index == 4) {
    $eta_headline = 'Arriving in ' . $eta_minutes . ' min';
    $eta_status_label = 'On the way to Customer &bull; ' . $eta_sub;
} elseif ($step_index >= 5 && $step_index < 7) {
    $eta_headline = 'Arrived at Customer';
    $eta_status_label = 'Drop-off & Handover';
} else {
    $eta_headline = 'Order Delivered';
    $eta_status_label = 'Completed successfully';
}

$customer_display_name = trim($active_delivery['customer_name'] ?? 'Valued Customer');
$customer_initials = strtoupper(substr($customer_display_name, 0, 2));
$delivery_pin_code = trim((string)($active_delivery['delivery_pin'] ?? ''));
if ($delivery_pin_code === '') {
    $delivery_pin_code = substr(preg_replace('/[^0-9]/', '', (string)$order_id . '3917'), 0, 4);
}
?>

<style>
/* ==========================================================================
   GLASSMORPHISM DELIVERY HUD DESIGN SYSTEM (RIDER PORTAL)
   ========================================================================== */
:root {
    --glass-bg: rgba(255, 255, 255, 0.88);
    --glass-bg-sub: rgba(255, 255, 255, 0.72);
    --glass-border: rgba(255, 255, 255, 0.75);
    --glass-shadow: 0 -12px 36px rgba(16, 24, 40, 0.12);
    --hud-brand: #b3261e;
    --hud-brand-hover: #981b15;
    --hud-success: #027a48;
}

body.dark-mode {
    --glass-bg: rgba(15, 23, 42, 0.88);
    --glass-bg-sub: rgba(30, 41, 59, 0.72);
    --glass-border: rgba(255, 255, 255, 0.12);
    --glass-shadow: 0 -12px 40px rgba(0, 0, 0, 0.45);
}

/* Hide standard topbar on mobile to eliminate header overlap */
@media (max-width: 767.98px) {
    .rider-topbar {
        display: none !important;
    }
}

.rider-glass-hud-page {
    position: relative;
    width: 100%;
    min-height: calc(100vh - 80px);
    overflow-x: hidden;
    padding-bottom: 30px;
}

/* Floating topbar on mobile */
.rider-floating-topbar {
    position: absolute;
    top: 14px;
    left: 14px;
    right: 14px;
    z-index: 1010;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    pointer-events: none;
}
.rider-floating-topbar > * {
    pointer-events: auto;
}

.rider-glass-btn-round {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.85);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #101828;
    font-size: 14px;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    flex-shrink: 0;
}
.rider-glass-btn-round:hover,
.rider-glass-btn-round:active {
    background: #ffffff;
    color: var(--hud-brand);
    transform: scale(1.06);
}

.rider-glass-order-pill {
    flex: 1;
    max-width: 250px;
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.85);
    border-radius: 99px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    font-size: 12.5px;
    font-weight: 700;
    color: #101828;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.rider-glass-btn-pill {
    padding: 8px 14px;
    border-radius: 99px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.85);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    font-size: 12px;
    font-weight: 700;
    color: var(--hud-brand);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    flex-shrink: 0;
}
.rider-glass-btn-pill:hover,
.rider-glass-btn-pill:active {
    background: #fff1f0;
    color: var(--hud-brand-hover);
    transform: scale(1.04);
}

/* Map area */
.rider-glass-map-wrapper {
    position: relative;
    width: 100%;
    height: 48vh;
    min-height: 320px;
    max-height: 460px;
    background: #e5e7eb;
}
#activeDeliveryMap {
    width: 100%;
    height: 100%;
    z-index: 1;
}

.rider-map-glass-controls {
    position: absolute;
    bottom: 30px;
    right: 14px;
    z-index: 990;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.rider-map-glass-btn {
    padding: 6px 12px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.8);
    box-shadow: 0 3px 12px rgba(0,0,0,0.12);
    font-size: 11px;
    font-weight: 700;
    color: #1d2939;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.rider-map-glass-btn:hover {
    background: #ffffff;
    color: var(--hud-brand);
    transform: translateY(-1px);
}

/* Glassmorphism Bottom Sheet */
.rider-glass-sheet {
    position: relative;
    margin-top: -24px;
    z-index: 1002;
    background: var(--glass-bg);
    backdrop-filter: blur(24px) saturate(180%);
    -webkit-backdrop-filter: blur(24px) saturate(180%);
    border: 1px solid var(--glass-border);
    border-bottom: none;
    border-radius: 28px 28px 0 0;
    box-shadow: var(--glass-shadow);
    padding: 12px 18px 140px 18px; /* Safe 140px bottom padding eliminates overlapping with fixed footer bottom nav */
}

/* Centered Grab Handle */
.rider-glass-handle {
    width: 44px;
    height: 4.5px;
    border-radius: 99px;
    background: rgba(0, 0, 0, 0.18);
    margin: 4px auto 14px auto;
}
body.dark-mode .rider-glass-handle {
    background: rgba(255, 255, 255, 0.25);
}

/* ETA Banner */
.rider-glass-eta-title {
    font-size: 24px;
    font-weight: 800;
    color: var(--primary-ink);
    line-height: 1.2;
    margin-bottom: 4px;
    letter-spacing: -0.02em;
}
.rider-glass-eta-subtitle {
    font-size: 13px;
    font-weight: 600;
    color: var(--hud-success);
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* 4-Step Stepper (Clean modern layout matching reference image) */
.rider-glass-stepper {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 20px;
}
.rider-glass-step-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.rider-glass-step-bar {
    height: 4.5px;
    border-radius: 99px;
    background: #e4e7ec;
    transition: background 0.3s ease;
}
.rider-glass-step-item.is-done .rider-glass-step-bar {
    background: var(--hud-success);
}
.rider-glass-step-item.is-active .rider-glass-step-bar {
    background: var(--hud-brand);
}
.rider-glass-step-label {
    font-size: 11px;
    font-weight: 600;
    color: #667085;
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}
.rider-glass-step-item.is-done .rider-glass-step-label {
    color: var(--hud-success);
    font-weight: 700;
}
.rider-glass-step-item.is-active .rider-glass-step-label {
    color: var(--hud-brand);
    font-weight: 800;
}

/* Customer / Contact Card */
.rider-glass-contact-card {
    background: var(--glass-bg-sub);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);
    border-radius: 18px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
    box-shadow: 0 2px 12px rgba(16, 24, 40, 0.04);
}
.rider-glass-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--hud-brand);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 15px;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(179, 38, 30, 0.25);
}
.rider-glass-contact-info {
    flex: 1;
    min-width: 0;
}
.rider-glass-contact-name {
    font-size: 14.5px;
    font-weight: 800;
    color: var(--primary-ink);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 2px;
}
.rider-glass-contact-sub {
    font-size: 11.5px;
    color: #667085;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.rider-glass-action-btns {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.rider-glass-action-btn {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid transparent;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    transition: transform 0.15s ease, background 0.15s ease;
}
.rider-glass-action-btn:hover {
    transform: scale(1.08);
}
.rider-glass-btn-chat {
    background: #ffffff;
    color: #175cd3;
    border-color: #d0d5dd;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}
.rider-glass-btn-call {
    background: var(--hud-success);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(2, 122, 72, 0.3);
}
.rider-glass-btn-nav {
    background: #ffffff;
    color: var(--hud-brand);
    border-color: #eaecf0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}
.rider-glass-btn-nav:hover {
    background: #fff1f0;
    color: var(--hud-brand-hover);
}

/* Inner card containers for COD & Handover */
.rider-glass-inner {
    background: var(--glass-bg-sub);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 14px;
    box-shadow: 0 2px 10px rgba(16, 24, 40, 0.03);
}

/* Desktop layout optimizations (2-column layout: Map Column on left, HUD Sheet on right) */
@media (min-width: 992px) {
    .rider-glass-hud-container {
        display: flex;
        gap: 24px;
        align-items: flex-start;
        padding-top: 10px;
    }
    .rider-glass-map-col {
        flex: 1.25;
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-width: 0;
    }
    .rider-glass-map-wrapper {
        width: 100%;
        height: 620px;
        min-height: 520px;
        max-height: none;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        border: 1px solid var(--glass-border);
        position: relative;
    }
    .rider-glass-sheet {
        flex: 1;
        min-width: 380px;
        max-width: 470px;
        margin-top: 0;
        border-radius: 24px;
        border: 1px solid var(--glass-border);
        padding: 24px 24px 60px 24px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.06);
    }
    .rider-floating-topbar {
        position: relative;
        top: 0;
        left: 0;
        right: 0;
        margin-bottom: 0;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        pointer-events: auto;
    }
    .rider-floating-topbar .rider-glass-order-pill {
        max-width: 320px;
    }
}

@media (max-width: 991.98px) {
    .rider-glass-hud-container {
        display: block;
        position: relative;
    }
    .rider-glass-map-col {
        width: 100%;
        position: relative;
    }
    .rider-glass-map-wrapper {
        width: 100%;
        height: 50vh;
        min-height: 320px;
        max-height: 460px;
        background: #e5e7eb;
        position: relative;
    }
    .rider-floating-topbar {
        position: absolute;
        top: 14px;
        left: 14px;
        right: 14px;
        z-index: 1010;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        pointer-events: none;
    }
    .rider-glass-sheet {
        position: relative;
        margin-top: -24px;
        z-index: 1002;
    }
}

/* Ultra-narrow 320px screen guard */
@media (max-width: 380px) {
    .rider-glass-eta-title {
        font-size: 20px;
    }
    .rider-glass-step-label {
        font-size: 9.5px;
    }
    .rider-glass-code-val {
        font-size: 18px;
        letter-spacing: 1.5px;
    }
    .rider-glass-order-pill {
        max-width: 170px;
        font-size: 11px;
    }
}
</style>

<div class="rider-glass-hud-page">
    <div class="rider-glass-hud-container">
        <!-- MAP COLUMN: Topbar Controls + Full Interactive Map -->
        <div class="rider-glass-map-col">
            <!-- Mobile & Desktop Floating Topbar -->
            <div class="rider-floating-topbar">
                <a href="index.php" class="rider-glass-btn-round" title="Back to Dashboard">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <div class="rider-glass-order-pill">
                    #<?php echo htmlspecialchars($order_number); ?> &bull; <?php echo htmlspecialchars($step_index < 3 ? ($active_delivery['store_name'] ?? 'Store') : ($customer_display_name)); ?>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="rider-glass-btn-pill" onclick="recenterDeliveryMap()" title="Recenter View">
                        <i class="fas fa-crosshairs text-danger"></i>
                        <span class="d-none d-sm-inline">Center</span>
                    </button>
                    <button type="button" class="rider-glass-btn-pill" onclick="openExceptionModal()" title="Report Issue">
                        <i class="fas fa-headset"></i>
                        <span>Help</span>
                    </button>
                </div>
            </div>

            <!-- Interactive Map Section with Route Polyline -->
            <div class="rider-glass-map-wrapper">
                <div id="activeDeliveryMap"></div>
                <!-- Quick GPS Launch Controls Overlaid on Map -->
                <div class="rider-map-glass-controls">
                    <button type="button" id="btnSimulateDrive" class="rider-map-glass-btn border-0 cursor-pointer" onclick="toggleDriveSimulation()" title="Simulate real-time driving for testing">
                        <i class="fas fa-play text-success" id="simDriveIcon"></i>
                        <span id="simDriveText">Test Drive</span>
                    </button>
                    <a href="#" id="googleMapsBtn" target="_blank" class="rider-map-glass-btn">
                        <i class="fas fa-location-arrow text-danger"></i> Google Maps
                    </a>
                    <a href="#" id="wazeBtn" target="_blank" class="rider-map-glass-btn">
                        <i class="fab fa-waze text-primary"></i> Waze
                    </a>
                </div>
            </div>
        </div>

        <!-- Bottom Sheet (Glassmorphism HUD Card) -->
        <div class="rider-glass-sheet">
            <!-- Center Grab Handle Pill -->
            <div class="rider-glass-handle"></div>

            <!-- Prominent ETA & Status Header -->
            <div class="rider-glass-eta-title" id="hudEtaHeadline">
                <?php echo htmlspecialchars($eta_headline); ?>
            </div>
            <div class="rider-glass-eta-subtitle" id="hudEtaStatus">
                <i class="fas fa-clock"></i>
                <span><?php echo $eta_status_label; ?></span>
            </div>

            <!-- 4-Step Stepper (Confirmed -> Cooking -> On the way -> Delivered) -->
            <div class="rider-glass-stepper">
                <div class="rider-glass-step-item <?php echo $step_index >= 0 ? ($step_index > 1 ? 'is-done' : 'is-active') : ''; ?>">
                    <div class="rider-glass-step-bar"></div>
                    <div class="rider-glass-step-label">
                        <i class="fas fa-check-circle"></i> Confirmed
                    </div>
                </div>
                <div class="rider-glass-step-item <?php echo $step_index >= 2 ? ($step_index > 2 ? 'is-done' : 'is-active') : ''; ?>">
                    <div class="rider-glass-step-bar"></div>
                    <div class="rider-glass-step-label">
                        <i class="fas fa-utensils"></i> Prep / Store
                    </div>
                </div>
                <div class="rider-glass-step-item <?php echo $step_index >= 3 ? ($step_index >= 7 ? 'is-done' : 'is-active') : ''; ?>">
                    <div class="rider-glass-step-bar"></div>
                    <div class="rider-glass-step-label">
                        <i class="fas fa-motorcycle"></i> On the way
                    </div>
                </div>
                <div class="rider-glass-step-item <?php echo $step_index >= 7 ? 'is-done' : ''; ?>">
                    <div class="rider-glass-step-bar"></div>
                    <div class="rider-glass-step-label">
                        <i class="fas fa-flag-checkered"></i> Delivered
                    </div>
                </div>
            </div>

            <!-- Customer / Contact Profile Card (Glassmorphic Sub-card) -->
            <div class="rider-glass-contact-card">
                <div class="rider-glass-avatar">
                    <?php echo $customer_initials; ?>
                </div>
                <div class="rider-glass-contact-info">
                    <div class="rider-glass-contact-name">
                        <?php echo htmlspecialchars($customer_display_name); ?>
                    </div>
                    <div class="rider-glass-contact-sub" title="<?php echo htmlspecialchars($active_delivery['delivery_address'] ?? ''); ?>">
                        <i class="fas fa-map-marker-alt text-danger me-1"></i><?php echo htmlspecialchars($active_delivery['delivery_address'] ?? 'Customer Drop-off Address'); ?>
                    </div>
                </div>
                <div class="rider-glass-action-btns">
                    <!-- Live Chat Button with Unread Badge -->
                    <button type="button" class="rider-glass-action-btn rider-glass-btn-chat position-relative" onclick="openRiderCustomerChat(<?php echo $order_id; ?>, '<?php echo htmlspecialchars(addslashes($order_number)); ?>', '<?php echo htmlspecialchars(addslashes($customer_display_name)); ?>')" title="Chat with Customer">
                        <i class="fas fa-comment-dots"></i>
                        <span id="riderChatUnreadBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="font-size: 10px; padding: 3px 6px;">
                            0
                        </span>
                    </button>
                    <?php if (!empty($active_delivery['customer_phone'])): ?>
                        <a href="tel:<?php echo htmlspecialchars($active_delivery['customer_phone']); ?>" class="rider-glass-action-btn rider-glass-btn-call" title="Call <?php echo htmlspecialchars($active_delivery['customer_phone']); ?>">
                            <i class="fas fa-phone-alt"></i>
                        </a>
                    <?php endif; ?>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $cust_lat; ?>,<?php echo $cust_lng; ?>" target="_blank" class="rider-glass-action-btn rider-glass-btn-nav" title="Navigate to Customer Location">
                        <i class="fas fa-directions"></i>
                    </a>
                </div>
            </div>

            <!-- Delivery Code / Verification Box (Dashed card matching reference image) -->
            <div class="rider-glass-code-box">
                <div class="rider-glass-code-left">
                    <div class="rider-glass-code-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div style="min-width: 0;">
                        <div class="rider-glass-code-title">Delivery code</div>
                        <div class="rider-glass-code-desc">Ask customer for code upon handover</div>
                    </div>
                </div>
                <div class="rider-glass-code-val">
                    <?php echo htmlspecialchars($delivery_pin_code); ?>
                </div>
            </div>

            <!-- Items & Payment Summary Manifest Row -->
            <div class="rider-glass-summary-row">
                <div class="d-flex align-items-center gap-2 text-truncate">
                    <i class="fas fa-receipt text-danger"></i>
                    <span class="text-truncate">
                        <?php echo count($order_items); ?> items &bull; ₱<?php echo number_format($order_total, 2); ?> &bull; <?php echo $is_cod ? '<strong class="text-danger">COD Cash</strong>' : '<strong class="text-success">Paid Online</strong>'; ?>
                    </span>
                </div>
                <a href="#orderItemsCollapse" data-bs-toggle="collapse" class="text-decoration-none text-muted small fw-bold" style="white-space: nowrap;">
                    View <i class="fas fa-chevron-down ms-1"></i>
                </a>
            </div>

            <!-- Collapsible Items Manifest -->
            <div class="collapse mb-3" id="orderItemsCollapse">
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.7); border: 1px solid var(--border-neutral); font-size: 12.5px;">
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($order_items as $item): ?>
                            <li class="d-flex justify-content-between py-1 border-bottom border-light">
                                <span><strong><?php echo (int)$item['quantity']; ?>x</strong> <?php echo htmlspecialchars($item['product_name'] ?? 'Item'); ?></span>
                                <span class="fw-semibold">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!empty($active_delivery['delivery_instructions'])): ?>
                        <div class="mt-2 pt-2 border-top text-danger small">
                            <i class="fas fa-info-circle me-1"></i> <strong>Note:</strong> <?php echo htmlspecialchars($active_delivery['delivery_instructions']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STAGE WORKFLOW CONTROLS -->
            <div id="workflowActionsContainer">
                <?php if ($step_index < 2): ?>
                    <!-- Step 1: Going to Restaurant -->
                    <button type="button" class="btn-rider-primary-glass" onclick="confirmArrivalAtRestaurant()">
                        <i class="fas fa-store"></i>
                        <span>Arrived at Restaurant / Store</span>
                    </button>

                <?php elseif ($step_index == 2): ?>
                    <!-- Step 2: At Store - Food Handover Approval -->
                    <div class="rider-glass-inner text-center">
                        <div class="spinner-border text-danger mb-2" role="status" style="width: 2rem; height: 2rem;">
                            <span class="visually-hidden">Waiting...</span>
                        </div>
                        <h6 class="fw-bold mb-1">Awaiting Store Food Handover</h6>
                        <div class="small text-muted mb-3">
                            Show <strong>Order #<?php echo htmlspecialchars($order_number); ?></strong> to restaurant staff to collect the order.
                        </div>

                        <div class="input-group mb-2">
                            <span class="input-group-text bg-white"><i class="fas fa-qrcode"></i></span>
                            <input type="text" id="pickupVerifyInput" class="form-control" placeholder="Order # or PIN" value="<?php echo htmlspecialchars($order_number); ?>">
                            <button type="button" class="btn btn-outline-success fw-bold" onclick="confirmPickupFromRestaurant()">
                                Confirm Pickup
                            </button>
                        </div>
                        <div class="form-text" style="font-size: 11px;">If store staff is unable to access portal, confirm with Order # or PIN above.</div>
                    </div>

                <?php elseif ($step_index == 3 || $step_index == 4): ?>
                    <!-- Step 3: On the way to Customer -->
                    <button type="button" class="btn-rider-primary-glass" onclick="confirmArrivalAtCustomer()">
                        <i class="fas fa-flag-checkered"></i>
                        <span>Arrived at Customer Drop-off</span>
                    </button>

                <?php elseif ($step_index >= 5 && $step_index < 7): ?>
                    <!-- Step 4: At Customer - COD & Delivery Proof -->
                    <?php if ($is_cod): ?>
                        <div class="rider-glass-inner mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-danger small"><i class="fas fa-coins me-1"></i> CASH TO COLLECT</span>
                                <strong style="color: #b3261e; font-size: 1.35rem;">₱<?php echo number_format($order_total, 2); ?></strong>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-bold text-dark mb-1">Cash Received from Customer</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" id="cashReceivedInput" class="form-control fw-bold" placeholder="e.g. <?php echo ceil($order_total / 100) * 100; ?>" oninput="calculateChange()">
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center p-2 rounded mb-2" style="background:#ffffff; border:1px solid #eaecf0;">
                                <span class="small text-muted fw-bold">CHANGE TO GIVE:</span>
                                <strong style="color: #027a48; font-size: 1.15rem;" id="changeToGiveDisplay">₱0.00</strong>
                            </div>

                            <button type="button" class="btn btn-sm btn-dark w-100 py-2 fw-bold" id="confirmCodBtn" onclick="submitCodPayment()">
                                <i class="fas fa-check-circle me-1"></i> Confirm Cash Collected
                            </button>
                        </div>
                    <?php endif; ?>

                    <div class="rider-glass-inner">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark d-flex justify-content-between align-items-center mb-1">
                                <span><i class="fas fa-camera text-danger me-1"></i> Photo Proof of Delivery <span class="text-danger">*</span></span>
                                <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:10px;">Required</span>
                            </label>
                            <input type="file" id="deliveryProofImageInput" class="form-control" accept="image/*" capture="environment" onchange="previewProofImage(this)">
                            <div class="form-text" style="font-size: 11px;">Snap a clear picture of the food received by the customer.</div>
                            <div id="proofImagePreviewContainer" class="mt-2 text-center" style="display: none;">
                                <img id="proofImagePreview" src="" alt="Proof Preview" style="max-height: 180px; width: 100%; border-radius: 12px; border: 1px solid #eaecf0; object-fit: cover;">
                            </div>
                        </div>

                        <button type="button" class="btn-rider-success-glass" onclick="confirmFinalDelivery()">
                            <i class="fas fa-camera me-1"></i> Complete Delivery
                        </button>
                    </div>
                <?php else: ?>
                    <div class="p-3 text-center text-success fw-bold">
                        <i class="fas fa-check-circle me-1"></i> Order Delivery Completed!
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- DELIVERY COMPLETED MODAL                                       -->
<!-- ============================================================== -->
<div class="modal fade" id="deliveryCompletedModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered px-3">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden;">
            <div class="p-4 text-center text-white" style="background: #027a48;">
                <div style="width: 64px; height: 64px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 12px;">
                    <i class="fas fa-check"></i>
                </div>
                <h4 class="fw-bold mb-1">DELIVERY COMPLETED ✓</h4>
                <div class="text-white-50 small">Order #<span id="compOrderNumber"><?php echo htmlspecialchars($order_number); ?></span></div>
            </div>

            <div class="modal-body p-3">
                <div class="p-3 rounded mb-3" style="background:#f8f9fa; border:1px solid var(--border-neutral);">
                    <div class="d-flex justify-content-between py-1 small">
                        <span class="text-muted">Delivery Fee:</span>
                        <strong id="compDeliveryFee">₱<?php echo number_format($delivery_fee, 2); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 small">
                        <span class="text-muted">Completion Bonus:</span>
                        <strong id="compBonus">₱15.00</strong>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top mt-2">
                        <span class="fw-bold text-dark">Total Rider Earnings:</span>
                        <strong style="color:#027a48; font-size:1.25rem;" id="compTotalEarnings">₱<?php echo number_format($delivery_fee + 15.00, 2); ?></strong>
                    </div>
                </div>

                <div class="text-center text-muted small mb-3">
                    Payment: <strong><?php echo $is_cod ? 'COD Cash' : 'Paid Online'; ?></strong> &bull; Completed at: <span id="compTimestamp"><?php echo date('h:i A'); ?></span>
                </div>

                <a href="index.php" class="btn-rider-primary py-3 w-100" style="border-radius: 12px;">
                    <i class="fas fa-check-circle me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- REPORT ISSUE / EXCEPTION MODAL                                 -->
<!-- ============================================================== -->
<div class="modal fade" id="exceptionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered px-3">
        <div class="modal-content border-0 shadow" style="border-radius: 20px;">
            <div class="modal-header border-bottom py-3">
                <h6 class="modal-title fw-bold text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Report Order Issue</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark">Issue Category</label>
                    <select id="issueCategory" class="form-select">
                        <option value="customer_unavailable">Customer Unavailable / Unreachable</option>
                        <option value="restaurant_problem">Restaurant Food Not Ready / Delayed</option>
                        <option value="wrong_address">Wrong Customer Address Pin</option>
                        <option value="damaged_food">Food Spilled / Damaged in Transit</option>
                        <option value="vehicle_problem">Motorcycle Breakdown / Flat Tire</option>
                        <option value="emergency">Road Accident / Personal Emergency</option>
                        <option value="cancellation_request">Request Order Cancellation</option>
                        <option value="other">Other Inquiry</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark">Describe Issue</label>
                    <textarea id="issueDescription" class="form-control" rows="3" placeholder="Explain details so dispatch can assist..."></textarea>
                </div>

                <button type="button" class="btn-rider-primary w-100 py-3" style="border-radius: 12px;" onclick="submitSupportIssue()">
                    <i class="fas fa-paper-plane me-1"></i> Submit Ticket to Dispatch
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- RIDER CUSTOMER CHAT MODAL                                      -->
<!-- ============================================================== -->
<div class="modal fade" id="riderChatModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered px-3" style="max-width: 480px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header border-bottom py-2 px-3" style="background: #ffffff;">
                <div class="d-flex align-items-center gap-2">
                    <div style="width: 38px; height: 38px; border-radius: 50%; background: #fee4e2; color: #b3261e; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0 text-dark" id="chatCustomerName">Customer Chat</h6>
                        <small class="text-muted" id="chatOrderSubtitle">Order #<?php echo htmlspecialchars($order_number); ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <!-- Quick preset reply chips for fast typing on motorcycle -->
            <div class="px-3 pt-2 pb-1 border-bottom bg-light d-flex gap-2 overflow-auto text-nowrap" style="scrollbar-width: none;">
                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 rounded-pill" style="font-size: 11px;" onclick="sendQuickReply('I am on my way with your order! 🛵')">
                    On my way 🛵
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 rounded-pill" style="font-size: 11px;" onclick="sendQuickReply('I have arrived outside your location! 📍')">
                    Arrived outside 📍
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 rounded-pill" style="font-size: 11px;" onclick="sendQuickReply('Please have payment / cash ready 💵')">
                    Payment ready 💵
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 rounded-pill" style="font-size: 11px;" onclick="sendQuickReply('Slight delay due to road traffic ⏱️')">
                    Traffic delay ⏱️
                </button>
            </div>

            <div class="modal-body p-3" style="height: 330px; overflow-y: auto; background: #f8f9fa;" id="chatMessagesBox">
                <div class="text-center text-muted small py-4">Loading messages...</div>
            </div>

            <div class="modal-footer p-2 border-top bg-white">
                <div class="input-group">
                    <input type="text" id="chatMessageInput" class="form-control" placeholder="Type message to customer..." onkeydown="if(event.key==='Enter') sendRiderChatMessage()">
                    <button class="btn btn-danger" type="button" onclick="sendRiderChatMessage()" style="background:#b3261e; border-color:#b3261e;">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const activeOrderId = <?php echo $order_id; ?>;
const activeTrackingId = <?php echo $tracking_id; ?>;
const orderTotalAmount = <?php echo $order_total; ?>;
const isCodOrder = <?php echo $is_cod ? 'true' : 'false'; ?>;
let stepIndex = <?php echo $step_index; ?>;
let activeDeliveryMap = null;

let currentRiderLat = <?php echo json_encode($rider['current_latitude'] ? (float)$rider['current_latitude'] : 14.3294); ?>;
let currentRiderLng = <?php echo json_encode($rider['current_longitude'] ? (float)$rider['current_longitude'] : 120.9367); ?>;
const storeLat = <?php echo $store_lat; ?>;
const storeLng = <?php echo $store_lng; ?>;
const custLat = <?php echo (float)$cust_lat; ?>;
const custLng = <?php echo (float)$cust_lng; ?>;
const storeName = <?php echo json_encode($store_name); ?>;
const storeAddress = <?php echo json_encode($store_address); ?>;
const custName = <?php echo json_encode($customer_display_name); ?>;
const custAddress = <?php echo json_encode($active_delivery['delivery_address'] ?? 'Customer Drop-off'); ?>;

let riderMarker = null;
let storeMarker = null;
let custMarker = null;
let routeLine = null;
let routeCasingLine = null;
let lastRiderRouteFetch = 0;
let lastRiderRouteCoords = '';

function initActiveMap(retryCount = 0) {
    if (typeof L === 'undefined') {
        if (retryCount < 20) {
            setTimeout(() => initActiveMap(retryCount + 1), 150);
        } else {
            console.error('Leaflet library failed to load after retries');
        }
        return;
    }

    const container = document.getElementById('activeDeliveryMap');
    if (!container) return;

    if (activeDeliveryMap) {
        try { activeDeliveryMap.remove(); } catch (e) {}
        activeDeliveryMap = null;
    }

    activeDeliveryMap = L.map('activeDeliveryMap', {
        center: [currentRiderLat, currentRiderLng],
        zoom: 14,
        zoomControl: true
    });

    // Primary OpenStreetMap tile layer
    const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(activeDeliveryMap);

    osmLayer.on('tileerror', function() {
        console.debug('Switching to backup map tiles...');
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            maxZoom: 19,
            subdomains: 'abcd'
        }).addTo(activeDeliveryMap);
    });

    // 1. Rider Motorcycle Marker (Red pulsating pin)
    const riderIcon = L.divIcon({
        className: 'custom-pin-rider',
        html: `<div style="width:40px;height:40px;border-radius:50%;background:#b3261e;border:3px solid #ffffff;box-shadow:0 4px 14px rgba(179,38,30,0.5);display:flex;align-items:center;justify-content:center;color:#ffffff;font-size:17px;cursor:pointer;"><i class="fas fa-motorcycle"></i></div>`,
        iconSize: [40, 40],
        iconAnchor: [20, 20]
    });

    riderMarker = L.marker([currentRiderLat, currentRiderLng], { 
        icon: riderIcon,
        draggable: true
    }).addTo(activeDeliveryMap);
    riderMarker.bindPopup(`<strong>Your Location (Rider)</strong><br><small class="text-muted">Drag anywhere or click map to test GPS position</small>`);

    riderMarker.on('dragend', (e) => {
        const pos = e.target.getLatLng();
        handleManualRiderLocationChange(pos.lat, pos.lng);
    });

    activeDeliveryMap.on('click', (e) => {
        handleManualRiderLocationChange(e.latlng.lat, e.latlng.lng);
    });

    // 2. Restaurant / Store Marker (Black store pin)
    const storeIcon = L.divIcon({
        className: 'custom-pin-store',
        html: `<div style="width:38px;height:38px;border-radius:12px;background:#18181b;border:2.5px solid #ffffff;box-shadow:0 4px 12px rgba(0,0,0,0.35);display:flex;align-items:center;justify-content:center;color:#ffffff;font-size:16px;"><i class="fas fa-store"></i></div>`,
        iconSize: [38, 38],
        iconAnchor: [19, 19]
    });
    storeMarker = L.marker([storeLat, storeLng], { icon: storeIcon }).addTo(activeDeliveryMap);
    storeMarker.bindPopup(`<strong>Store: ${escapeRiderHtml(storeName)}</strong><br><small class="text-muted">${escapeRiderHtml(storeAddress)}</small>`);

    // 3. Customer Location Marker (Green home pin - ALWAYS VISIBLE)
    const custIcon = L.divIcon({
        className: 'custom-pin-cust',
        html: `<div style="width:40px;height:40px;border-radius:50%;background:#027a48;border:3px solid #ffffff;box-shadow:0 4px 14px rgba(2,122,72,0.45);display:flex;align-items:center;justify-content:center;color:#ffffff;font-size:16px;"><i class="fas fa-home"></i></div>`,
        iconSize: [40, 40],
        iconAnchor: [20, 20]
    });
    custMarker = L.marker([custLat, custLng], { icon: custIcon }).addTo(activeDeliveryMap);
    custMarker.bindPopup(`<strong>Customer: ${escapeRiderHtml(custName)}</strong><br><small class="text-muted">${escapeRiderHtml(custAddress)}</small>`);

    // Invalidate size on load to prevent blank gray tiles
    setTimeout(() => { if (activeDeliveryMap) activeDeliveryMap.invalidateSize(); }, 100);
    setTimeout(() => { if (activeDeliveryMap) activeDeliveryMap.invalidateSize(); }, 350);
    setTimeout(() => { if (activeDeliveryMap) activeDeliveryMap.invalidateSize(); }, 800);
    setTimeout(() => { if (activeDeliveryMap) activeDeliveryMap.invalidateSize(); }, 1500);
    window.addEventListener('resize', () => { if (activeDeliveryMap) activeDeliveryMap.invalidateSize(); });

    updateMapNavigationTarget();
    recenterDeliveryMap();
}

function recenterDeliveryMap() {
    if (!activeDeliveryMap) return;
    const pts = [[currentRiderLat, currentRiderLng]];
    if (storeLat && storeLng) pts.push([storeLat, storeLng]);
    if (custLat && custLng) pts.push([custLat, custLng]);

    if (pts.length > 1) {
        activeDeliveryMap.fitBounds(L.latLngBounds(pts).pad(0.25), { padding: [40, 40], maxZoom: 16 });
    } else {
        activeDeliveryMap.setView([currentRiderLat, currentRiderLng], 15);
    }
}

function ensureRiderRouteLayers() {
    if (!activeDeliveryMap) return;
    if (!routeCasingLine) {
        routeCasingLine = L.polyline([], {
            color: '#ffffff',
            weight: 7,
            opacity: 0.95,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(activeDeliveryMap);
    }
    if (!routeLine) {
        routeLine = L.polyline([], {
            color: '#b3261e',
            weight: 4.5,
            opacity: 0.95,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(activeDeliveryMap);
    }
}

function setRiderRouteCoordinates(latLngs) {
    ensureRiderRouteLayers();
    if (!latLngs || latLngs.length === 0) return;
    if (routeCasingLine) routeCasingLine.setLatLngs(latLngs);
    if (routeLine) routeLine.setLatLngs(latLngs);
}

function updateRiderRouteHead(lat, lng) {
    if (!routeLine) return;
    const latLngs = routeLine.getLatLngs();
    if (latLngs && latLngs.length > 0) {
        latLngs[0] = L.latLng(lat, lng);
        setRiderRouteCoordinates(latLngs);
    }
}

async function fetchRiderStreetRoute(originLat, originLng, destLat, destLng, force = false) {
    ensureRiderRouteLayers();
    if (!originLat || !originLng || !destLat || !destLng) return;

    const cur = routeLine ? routeLine.getLatLngs() : [];
    if (!cur || cur.length === 0) {
        setRiderRouteCoordinates([[originLat, originLng], [destLat, destLng]]);
    }

    const key = `${Math.round(originLat * 1000)}_${Math.round(originLng * 1000)}_${Math.round(destLat * 1000)}_${Math.round(destLng * 1000)}`;
    const now = Date.now();
    if (!force && now - lastRiderRouteFetch < 5000 && lastRiderRouteCoords === key) {
        updateRiderRouteHead(originLat, originLng);
        return;
    }
    lastRiderRouteFetch = now;
    lastRiderRouteCoords = key;

    try {
        const apiUrl = `../api/get_directions.php?origin_lat=${originLat}&origin_lng=${originLng}&dest_lat=${destLat}&dest_lng=${destLng}`;
        const res = await fetch(apiUrl);
        if (res.ok) {
            const data = await res.json();
            if (data.success && Array.isArray(data.coordinates) && data.coordinates.length > 0) {
                setRiderRouteCoordinates(data.coordinates);
                if (force && activeDeliveryMap) {
                    recenterDeliveryMap();
                }
                return;
            }
        }
    } catch (e) {
        console.debug('Directions proxy fallback:', e);
    }

    try {
        const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${originLng},${originLat};${destLng},${destLat}?overview=full&geometries=geojson`;
        const res2 = await fetch(osrmUrl);
        if (res2.ok) {
            const data2 = await res2.json();
            if (data2.routes && data2.routes.length > 0) {
                const latLngs = data2.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
                setRiderRouteCoordinates(latLngs);
                if (force && activeDeliveryMap) {
                    recenterDeliveryMap();
                }
                return;
            }
        }
    } catch (e2) {
        console.debug('OSRM fallback notice:', e2);
    }
}

function onRiderLocationChanged(lat, lng) {
    currentRiderLat = lat;
    currentRiderLng = lng;
    if (riderMarker) riderMarker.setLatLng([lat, lng]);

    let destLat = (stepIndex < 3) ? storeLat : custLat;
    let destLng = (stepIndex < 3) ? storeLng : custLng;

    updateRiderRouteHead(lat, lng);
    fetchRiderStreetRoute(lat, lng, destLat, destLng, false);
}

function handleManualRiderLocationChange(lat, lng) {
    onRiderLocationChanged(lat, lng);

    const formData = new FormData();
    formData.append('action', 'update_location');
    formData.append('latitude', lat);
    formData.append('longitude', lng);
    formData.append('accuracy', 10);
    fetch('api_rider.php', { method: 'POST', body: formData })
        .catch(err => console.debug('Manual location sync error:', err));
}

let isDriveSimulating = false;
let driveSimTimer = null;
let driveSimWaypoints = [];
let driveSimIndex = 0;

function toggleDriveSimulation() {
    if (isDriveSimulating) {
        stopDriveSimulation();
        return;
    }
    startDriveSimulation();
}

function stopDriveSimulation() {
    isDriveSimulating = false;
    if (driveSimTimer) clearInterval(driveSimTimer);
    driveSimTimer = null;
    const icon = document.getElementById('simDriveIcon');
    const txt = document.getElementById('simDriveText');
    if (icon) icon.className = 'fas fa-play text-success';
    if (txt) txt.textContent = 'Test Drive';
}

function startDriveSimulation() {
    let destLat = (stepIndex < 3) ? storeLat : custLat;
    let destLng = (stepIndex < 3) ? storeLng : custLng;
    if (!destLat || !destLng) {
        Swal.fire({ icon: 'warning', title: 'Destination Missing', text: 'Destination coordinates are not set.' });
        return;
    }

    let coords = [];
    if (routeLine) {
        const raw = routeLine.getLatLngs();
        if (raw && raw.length > 1) {
            coords = raw.map(p => [p.lat, p.lng]);
        }
    }

    if (coords.length < 5) {
        coords = [];
        const steps = 30;
        for (let i = 0; i <= steps; i++) {
            const frac = i / steps;
            coords.push([
                currentRiderLat + (destLat - currentRiderLat) * frac,
                currentRiderLng + (destLng - currentRiderLng) * frac
            ]);
        }
    }

    driveSimWaypoints = coords;
    driveSimIndex = 0;
    isDriveSimulating = true;

    const icon = document.getElementById('simDriveIcon');
    const txt = document.getElementById('simDriveText');
    if (icon) icon.className = 'fas fa-stop text-danger';
    if (txt) txt.textContent = 'Stop Demo';

    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'info',
        title: 'Test Drive started! Broadcasting live movement...',
        showConfirmButton: false,
        timer: 2500
    });

    driveSimTimer = setInterval(() => {
        if (!isDriveSimulating) return;
        driveSimIndex++;
        if (driveSimIndex >= driveSimWaypoints.length) {
            stopDriveSimulation();
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Test drive arrived at destination!',
                showConfirmButton: false,
                timer: 3000
            });
            return;
        }

        const pt = driveSimWaypoints[driveSimIndex];
        handleManualRiderLocationChange(pt[0], pt[1]);
        if (activeDeliveryMap) {
            activeDeliveryMap.panTo([pt[0], pt[1]], { animate: true, duration: 0.5 });
        }
    }, 2000);
}

function updateMapNavigationTarget() {
    if (!activeDeliveryMap) return;

    let destLat = (stepIndex < 3) ? storeLat : custLat;
    let destLng = (stepIndex < 3) ? storeLng : custLng;

    const gmapsBtn = document.getElementById('googleMapsBtn');
    if (gmapsBtn) {
        gmapsBtn.href = `https://www.google.com/maps/dir/?api=1&origin=${currentRiderLat},${currentRiderLng}&destination=${destLat},${destLng}`;
    }
    const wazeBtn = document.getElementById('wazeBtn');
    if (wazeBtn) {
        wazeBtn.href = `https://waze.com/ul?ll=${destLat},${destLng}&navigate=yes`;
    }

    fetchRiderStreetRoute(currentRiderLat, currentRiderLng, destLat, destLng, true);
}

// RESTAURANT WORKFLOW ACTIONS
function confirmArrivalAtRestaurant() {
    advanceStep('arrived_at_restaurant', () => {
        Swal.fire({
            icon: 'info',
            title: 'Arrived at Restaurant',
            text: 'Please show Order #' + '<?php echo $order_number; ?>' + ' to the restaurant staff to collect the order.',
            confirmButtonColor: '#b3261e'
        }).then(() => {
            location.reload();
        });
    });
}

function confirmPickupFromRestaurant() {
    const code = document.getElementById('pickupVerifyInput').value.trim();
    const formData = new FormData();
    formData.append('action', 'confirm_pickup');
    formData.append('order_id', activeOrderId);
    formData.append('tracking_id', activeTrackingId);
    formData.append('verification_code', code);

    fetch('api_rider.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Pickup Confirmed!',
                    text: 'Food collected! Proceeding to customer drop-off.',
                    confirmButtonColor: '#b3261e'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Verification Failed', data.message, 'warning');
            }
        });
}

// CUSTOMER WORKFLOW ACTIONS
function confirmArrivalAtCustomer() {
    advanceStep('arrived_at_customer', () => {
        Swal.fire({
            icon: 'info',
            title: 'Arrived at Customer',
            text: 'Customer notified. Please collect payment (if COD) and delivery code.',
            confirmButtonColor: '#b3261e'
        }).then(() => {
            location.reload();
        });
    });
}

function calculateChange() {
    const cashVal = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
    const diff = cashVal - orderTotalAmount;
    const changeDisplay = document.getElementById('changeToGiveDisplay');
    if (diff >= 0) {
        changeDisplay.textContent = '₱' + diff.toFixed(2);
        changeDisplay.style.color = '#027a48';
    } else {
        changeDisplay.textContent = 'Insufficient (₱' + Math.abs(diff).toFixed(2) + ' short)';
        changeDisplay.style.color = '#b3261e';
    }
}

function submitCodPayment() {
    const cashVal = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
    if (cashVal < orderTotalAmount) {
        Swal.fire('Insufficient Cash', 'Cash received must be at least ₱' + orderTotalAmount.toFixed(2), 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'confirm_cod_payment');
    formData.append('order_id', activeOrderId);
    formData.append('tracking_id', activeTrackingId);
    formData.append('order_total', orderTotalAmount);
    formData.append('cash_received', cashVal);

    fetch('api_rider.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Recorded',
                    html: `Cash Received: <strong>₱${cashVal.toFixed(2)}</strong><br>Change: <strong>₱${data.change_given.toFixed(2)}</strong>`,
                    confirmButtonColor: '#027a48'
                });
                const btn = document.getElementById('confirmCodBtn');
                btn.disabled = true;
                btn.className = 'btn btn-sm btn-success w-100 py-2 fw-bold';
                btn.innerHTML = '<i class="fas fa-check-double me-1"></i> Cash Collected & Change Settled';
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        });
}

function previewProofImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('proofImagePreview').src = e.target.result;
            document.getElementById('proofImagePreviewContainer').style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function confirmFinalDelivery() {
    const photoInput = document.getElementById('deliveryProofImageInput');
    if (!photoInput || !photoInput.files || photoInput.files.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Photo Proof Required',
            text: 'Please take or attach a photo proof of delivery before completing the order.',
            confirmButtonColor: '#b3261e'
        });
        return;
    }

    Swal.fire({
        title: 'Completing Delivery...',
        text: 'Uploading photo proof of delivery...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    const compData = new FormData();
    compData.append('action', 'complete_delivery');
    compData.append('order_id', activeOrderId);
    compData.append('tracking_id', activeTrackingId);
    compData.append('proof_image', photoInput.files[0]);

    fetch('api_rider.php', { method: 'POST', body: compData })
        .then(r => r.json())
        .then(compRes => {
            if (compRes.success) {
                Swal.close();
                const modal = new bootstrap.Modal(document.getElementById('deliveryCompletedModal'));
                modal.show();
            } else {
                Swal.fire('Error', compRes.message, 'error');
            }
        })
        .catch(() => {
            Swal.fire('Network Error', 'Failed to submit proof of delivery.', 'error');
        });
}

function advanceStep(stepName, onSuccess) {
    const formData = new FormData();
    formData.append('action', 'advance_step');
    formData.append('order_id', activeOrderId);
    formData.append('tracking_id', activeTrackingId);
    formData.append('step', stepName);

    fetch('api_rider.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success && typeof onSuccess === 'function') {
                onSuccess(data);
            } else if (!data.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Action Failed',
                    text: data.message || 'Could not update delivery progress.',
                    confirmButtonColor: '#b3261e'
                });
            }
        })
        .catch(err => {
            Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Could not connect to server. Please try again.',
                confirmButtonColor: '#b3261e'
            });
        });
}

function openExceptionModal() {
    new bootstrap.Modal(document.getElementById('exceptionModal')).show();
}

function submitSupportIssue() {
    const cat = document.getElementById('issueCategory').value;
    const desc = document.getElementById('issueDescription').value.trim();

    if (!desc) {
        Swal.fire('Description Required', 'Please enter details for dispatch.', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'submit_issue');
    formData.append('order_id', activeOrderId);
    formData.append('category', cat);
    formData.append('description', desc);
    formData.append('waiting_time_minutes', 10);

    fetch('api_rider.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('exceptionModal')).hide();
                Swal.fire('Ticket Submitted', data.message, 'success');
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        });
}

// Delivery Chat Integration
let chatPollTimer = null;
let lastUnreadCheckCount = 0;

function checkUnreadCustomerChat() {
    fetch(`../api/delivery_chat.php?action=get_messages&order_id=${activeOrderId}&peek_unread=1`)
        .then(r => r.json())
        .then(data => {
            if (data && data.success && Array.isArray(data.messages)) {
                const count = parseInt(data.unread_count || 0, 10);
                const badge = document.getElementById('riderChatUnreadBadge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count > 9 ? '9+' : count;
                        badge.classList.remove('d-none');
                    } else {
                        badge.classList.add('d-none');
                    }
                }

                // If new customer message arrived and modal is not open, notify rider
                const modal = document.getElementById('riderChatModal');
                const isModalOpen = modal && modal.classList.contains('show');
                if (!isModalOpen && count > 0 && count > lastUnreadCheckCount) {
                    const lastMsg = data.messages[data.messages.length - 1];
                    if (lastMsg && lastMsg.sender_role !== 'driver') {
                        const toastMsg = lastMsg.message_text || lastMsg.message || 'New message';
                        if (typeof Swal !== 'undefined') {
                            const Toast = Swal.mixin({
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 4500,
                                timerProgressBar: true
                            });
                            Toast.fire({
                                icon: 'info',
                                title: 'Customer Message',
                                text: toastMsg
                            });
                        }
                    }
                }
                lastUnreadCheckCount = count;
            }
        })
        .catch(() => {});
}

function openRiderCustomerChat(orderId, orderNum, custName) {
    document.getElementById('chatCustomerName').textContent = custName || 'Customer';
    document.getElementById('chatOrderSubtitle').textContent = 'Order #' + orderNum;
    new bootstrap.Modal(document.getElementById('riderChatModal')).show();

    // Reset badge on open
    const badge = document.getElementById('riderChatUnreadBadge');
    if (badge) badge.classList.add('d-none');
    lastUnreadCheckCount = 0;

    loadChatMessages();
    if (chatPollTimer) clearInterval(chatPollTimer);
    chatPollTimer = setInterval(loadChatMessages, 2500);

    const modalEl = document.getElementById('riderChatModal');
    if (modalEl && !modalEl._hasHideListener) {
        modalEl._hasHideListener = true;
        modalEl.addEventListener('hidden.bs.modal', () => {
            if (chatPollTimer) clearInterval(chatPollTimer);
        });
    }
}

function sendQuickReply(text) {
    const input = document.getElementById('chatMessageInput');
    if (input) {
        input.value = text;
        sendRiderChatMessage();
    }
}

function loadChatMessages() {
    fetch(`../api/delivery_chat.php?action=get_messages&order_id=${activeOrderId}`)
        .then(r => r.json())
        .then(data => {
            if (data && data.success && Array.isArray(data.messages)) {
                const box = document.getElementById('chatMessagesBox');
                if (!box) return;
                if (data.messages.length === 0) {
                    box.innerHTML = '<div class="text-center text-muted small py-4"><i class="fas fa-comments fa-2x mb-2 text-muted"></i><br>No messages yet. Send a note to the customer!</div>';
                    return;
                }
                box.innerHTML = data.messages.map(m => {
                    const text = m.message_text || m.message || '';
                    let timeDisplay = m.created_at || '';
                    try {
                        if (timeDisplay) timeDisplay = new Date(timeDisplay.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    } catch (e) {}
                    return `
                        <div class="mb-2 d-flex ${m.sender_role === 'driver' ? 'justify-content-end' : 'justify-content-start'}">
                            <div class="p-2 px-3 rounded shadow-sm" style="max-width: 80%; font-size: 13px; background: ${m.sender_role === 'driver' ? '#b3261e' : '#ffffff'}; color: ${m.sender_role === 'driver' ? '#ffffff' : '#101828'}; border: 1px solid ${m.sender_role === 'driver' ? '#b3261e' : '#eaecf0'};">
                                <div>${escapeRiderHtml(text)}</div>
                                <div class="text-end" style="font-size: 9.5px; opacity: 0.8; margin-top: 2px;">${escapeRiderHtml(timeDisplay)}</div>
                            </div>
                        </div>
                    `;
                }).join('');
                box.scrollTop = box.scrollHeight;
            }
        })
        .catch(e => console.debug('Chat load error:', e));
}

function sendRiderChatMessage() {
    const input = document.getElementById('chatMessageInput');
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    const box = document.getElementById('chatMessagesBox');
    if (box) {
        const timeNow = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const tempDiv = document.createElement('div');
        tempDiv.className = 'mb-2 d-flex justify-content-end';
        tempDiv.innerHTML = `
            <div class="p-2 px-3 rounded shadow-sm" style="max-width: 80%; font-size: 13px; background: #b3261e; color: #ffffff; border: 1px solid #b3261e;">
                <div>${escapeRiderHtml(msg)}</div>
                <div class="text-end" style="font-size: 9.5px; opacity: 0.8; margin-top: 2px;">${escapeRiderHtml(timeNow)} <i class="fas fa-check"></i></div>
            </div>
        `;
        box.appendChild(tempDiv);
        box.scrollTop = box.scrollHeight;
    }

    fetch('../api/delivery_chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: activeOrderId, message: msg })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadChatMessages();
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Message Not Sent',
                text: data.message || 'Could not send message. Please retry.',
                confirmButtonColor: '#b3261e'
            });
        }
    })
    .catch(e => {
        console.debug('Send chat error:', e);
    });
}

function escapeRiderHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Real-time sync: Listen for restaurant pickup handover confirmation
let pickupSyncInterval = null;
let lastRejectionNotice = null;
function startPickupSyncPolling() {
    if (stepIndex >= 3) return;
    if (pickupSyncInterval) clearInterval(pickupSyncInterval);

    pickupSyncInterval = setInterval(() => {
        if (stepIndex >= 3) {
            clearInterval(pickupSyncInterval);
            return;
        }

        fetch(`api_rider.php?action=check_delivery_status&tracking_id=${activeTrackingId}&order_id=${activeOrderId}`)
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    if (data.is_picked_up) {
                        clearInterval(pickupSyncInterval);
                        Swal.fire({
                            icon: 'success',
                            title: 'Order Picked Up!',
                            text: 'The store owner has approved the order handover. Proceed with customer delivery!',
                            confirmButtonColor: '#b3261e'
                        }).then(() => {
                            location.reload();
                        });
                    } else if (data.rejection_note && data.rejection_note !== lastRejectionNotice) {
                        lastRejectionNotice = data.rejection_note;
                        Swal.fire({
                            icon: 'warning',
                            title: 'Handover Paused by Store',
                            text: data.rejection_note,
                            confirmButtonColor: '#b3261e'
                        });
                    }
                }
            })
            .catch(() => {});
    }, 3500);
}

document.addEventListener('DOMContentLoaded', () => {
    initActiveMap();
    startPickupSyncPolling();
    checkUnreadCustomerChat();
    setInterval(checkUnreadCustomerChat, 4000);
    broadcastRiderLocation((lat, lng) => {
        onRiderLocationChanged(lat, lng);
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
