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
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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

// Get tracking info
$logisticsService = new LogisticsService($conn);
$tracking_info = $logisticsService->getTrackingByOrderId($order_id);

$page_title = "Track Order #" . $order['order_number'];
include 'includes/header.php';
?>

<style>
    .tracking-page {
        padding: 50px 0 80px;
        background: linear-gradient(180deg, #fff9f2 0%, #fff4e8 100%);
        min-height: 85vh;
    }
    .tracking-container { max-width: 1240px; margin: 0 auto; padding: 0 16px; }
    
    .tracking-header { text-align: center; margin-bottom: 28px; }
    .tracking-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #fff0eb;
        color: #b3261e;
        border: 1px solid #efddcd;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin-bottom: 12px;
    }
    .tracking-header h1 {
        font-size: clamp(2rem, 4.5vw, 2.6rem);
        color: #171922;
        font-weight: 800;
        margin-bottom: 6px;
        letter-spacing: -0.3px;
    }
    .tracking-header h1 span {
        background: linear-gradient(135deg, #b3261e, #ef6b2e);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .tracking-order-num {
        display: inline-block;
        font-size: 0.95rem;
        color: #7b6d64;
        background: #ffffff;
        border: 1px solid #efddcd;
        padding: 4px 14px;
        border-radius: 8px;
        font-family: monospace;
        font-weight: 600;
    }

    .tracking-meta {
        margin-top: 22px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
    }
    .tracking-meta-card {
        background: #ffffff;
        border: 1px solid #efddcd;
        border-radius: 16px;
        padding: 16px 18px;
        box-shadow: 0 8px 24px rgba(42, 33, 29, 0.04);
        display: flex;
        align-items: center;
        gap: 14px;
        text-align: left;
        transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.22s ease;
    }
    .tracking-meta-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(179, 38, 30, 0.08);
        border-color: #e8d4c3;
    }
    .tracking-meta-card i {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #b3261e, #ef6b2e);
        color: #ffffff;
        font-size: 1.1rem;
        box-shadow: 0 6px 16px rgba(179, 38, 30, 0.2);
        flex-shrink: 0;
    }
    .tracking-meta-card span {
        display: block;
        font-size: 0.76rem;
        color: #7b6d64;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
    }
    .tracking-meta-card strong {
        display: block;
        color: #171922;
        font-size: 1.05rem;
        font-weight: 800;
        margin-top: 2px;
    }

    .tracking-grid { display: grid; grid-template-columns: 1.85fr 1fr; gap: 24px; }
    
    #map {
        height: 600px;
        width: 100%;
        border-radius: 18px;
        border: 1px solid #efddcd;
        box-shadow: 0 14px 34px rgba(23, 25, 34, 0.08);
        overflow: hidden;
    }

    .status-panel {
        background: #ffffff;
        border-radius: 18px;
        padding: 24px;
        border: 1px solid #efddcd;
        box-shadow: 0 14px 34px rgba(23, 25, 34, 0.07);
    }
    .status-panel h3 {
        font-size: 1.3rem;
        font-weight: 800;
        margin-bottom: 18px;
        border-bottom: 1px solid #f3e8de;
        padding-bottom: 14px;
        color: #171922;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .status-panel h3 .live-indicator {
        font-size: 0.75rem;
        background: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #c8e6c9;
        padding: 3px 10px;
        border-radius: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .status-panel h3 .live-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #2e7d32;
        animation: blink 1.5s infinite;
    }
    @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

    .eta-badge {
        background: linear-gradient(135deg, #fff9f2 0%, #fff4e8 100%);
        color: #b3261e;
        padding: 14px 16px;
        border-radius: 14px;
        font-weight: 800;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
        border: 1px solid #efddcd;
        box-shadow: 0 4px 14px rgba(179, 38, 30, 0.05);
        width: 100%;
    }
    .eta-badge i { font-size: 1.25rem; color: #ef6b2e; }

    .route-metrics {
        background: #fffaf5;
        border: 1px solid #efddcd;
        border-radius: 14px;
        padding: 14px 16px;
        margin-bottom: 18px;
    }
    .route-metric-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.88rem;
        color: #7b6d64;
        padding: 6px 0;
        border-bottom: 1px dashed #f3e3d4;
    }
    .route-metric-row:last-child { border-bottom: 0; }
    .route-metric-row span { display: flex; align-items: center; gap: 8px; }
    .route-metric-row span i { color: #ef6b2e; width: 14px; text-align: center; }
    .route-metric-row strong {
        color: #171922;
        font-weight: 800;
    }

    .status-item { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; background: #fffaf5; border: 1px solid #efddcd; border-radius: 14px; padding: 14px; }
    .status-icon { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
    .status-icon.pending { background: #fff3e0; color: #f57c00; }
    .status-icon.assigned { background: #fff0eb; color: #b3261e; }
    .status-icon.on_the_way { background: #fff0eb; color: #ef6b2e; }
    .status-icon.delivered { background: #e8f5e9; color: #2e7d32; }
    .status-text strong { display: block; font-size: 1.05rem; color: #171922; font-weight: 800; }
    .status-text span { font-size: 0.88rem; color: #667085; line-height: 1.45; margin-top: 2px; display: block; }

    .driver-info { margin-top: 20px; padding-top: 18px; border-top: 1px solid #f3e8de; }
    .driver-info h4 { margin-bottom: 12px; color: #171922; font-size: 1rem; font-weight: 800; }
    .driver-card-mini { display: flex; align-items: center; justify-content: space-between; background: #fffaf5; border: 1px solid #efddcd; border-radius: 14px; padding: 12px 14px; }
    .driver-meta-name { font-weight: 800; color: #171922; font-size: 0.98rem; }
    .btn-call-driver {
        background: linear-gradient(135deg, #b3261e, #ef6b2e);
        color: #ffffff !important;
        border: 0;
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 4px 12px rgba(179, 38, 30, 0.2);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .btn-call-driver:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(179, 38, 30, 0.3); color: #fff !important; }

    .delivery-address-info { margin-top: 20px; padding-top: 18px; border-top: 1px solid #f3e8de; }
    .delivery-address-info h4 { margin-bottom: 8px; color: #171922; font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
    .delivery-address-info h4 i { color: #b3261e; }
    .delivery-address-info p { color: #667085; font-size: 0.92rem; margin: 0; line-height: 1.5; }

    .delivery-chat-panel { margin-top: 20px; padding-top: 18px; border-top: 1px solid #f3e8de; }
    .delivery-chat-panel h4 { margin-bottom: 12px; color: #171922; font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
    .delivery-chat-panel h4 i { color: #ef6b2e; }
    .delivery-chat-messages {
        max-height: 240px;
        overflow-y: auto;
        background: #fffaf5;
        border: 1px solid #efddcd;
        border-radius: 14px;
        padding: 14px;
        margin-bottom: 12px;
    }
    .chat-empty { color: #7b6d64; text-align: center; padding: 16px; font-size: 0.9rem; }
    .chat-message.customer .chat-bubble { background: linear-gradient(135deg, #b3261e, #ef6b2e); color: #ffffff; border-radius: 14px 14px 2px 14px; box-shadow: 0 4px 12px rgba(179, 38, 30, 0.15); }
    .chat-message.driver .chat-bubble { background: #ffffff; color: #171922; border: 1px solid #efddcd; border-radius: 14px 14px 14px 2px; }
    .chat-time { font-size: 0.72rem; color: #7b6d64; margin-top: 3px; }
    
    .delivery-chat-form { display: flex; gap: 8px; align-items: flex-end; }
    .delivery-chat-form textarea {
        flex: 1;
        min-height: 44px;
        max-height: 120px;
        border: 1px solid #efddcd;
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 0.92rem;
        background: #ffffff;
        color: #171922;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .delivery-chat-form textarea:focus {
        outline: none;
        border-color: #b3261e;
        box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
    }
    .delivery-chat-form button {
        border: 0;
        border-radius: 12px;
        background: linear-gradient(135deg, #b3261e, #ef6b2e);
        color: #ffffff;
        padding: 11px 20px;
        font-weight: 700;
        box-shadow: 0 4px 14px rgba(179, 38, 30, 0.2);
        transition: transform 0.18s ease, background 0.18s ease;
    }
    .delivery-chat-form button:hover:not(:disabled) {
        transform: translateY(-1px);
    }

    .view-proof-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 10px;
        padding: 8px 16px;
        background: #2e7d32;
        color: white !important;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.88rem;
    }
    .rate-delivery-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 10px;
        padding: 8px 16px;
        background: #ffb300;
        color: #171922 !important;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.88rem;
        border: none;
        cursor: pointer;
    }
    .rate-delivery-btn.disabled {
        background: #e9ecef;
        color: #6c757d !important;
        cursor: not-allowed;
    }
    @media (max-width: 992px) {
        .tracking-meta { grid-template-columns: 1fr; }
        .tracking-grid { grid-template-columns: 1fr; }
        #map { height: 420px; }
    }
</style>

<section class="tracking-page">
    <div class="container tracking-container">
        <div class="tracking-header">
            <div class="tracking-badge"><i class="fas fa-satellite-dish"></i> Live Order Tracking</div>
            <h1>Track <span>Your Order</span></h1>
            <div class="tracking-order-num">#<?php echo htmlspecialchars($order['order_number']); ?></div>
            <div class="tracking-meta">
                <div class="tracking-meta-card">
                    <i class="fas fa-truck"></i>
                    <div>
                        <span>Delivery Type</span>
                        <strong><?php echo ucfirst(htmlspecialchars((string)($order['delivery_option'] ?? 'delivery'))); ?></strong>
                    </div>
                </div>
                <div class="tracking-meta-card">
                    <i class="fas fa-calendar-check"></i>
                    <div>
                        <span>Schedule</span>
                        <strong><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$order['delivery_date']))); ?></strong>
                    </div>
                </div>
                <div class="tracking-meta-card">
                    <i class="fas fa-credit-card"></i>
                    <div>
                        <span>Total Amount</span>
                        <strong>&#8369;<?php echo number_format((float)$order['total_amount'], 2); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($tracking_info): ?>
        <div id="trackingNotificationToast" style="display:none; background:#171922; color:#fff; border-left:4px solid #ef6b2e; padding:14px 18px; border-radius:14px; margin-bottom:18px; box-shadow:0 10px 25px rgba(23,25,34,0.15);">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:14px;">
                    <div style="width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#b3261e,#ef6b2e); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.2rem; flex-shrink:0; box-shadow:0 4px 12px rgba(179,38,30,0.3);">
                        <i class="fas fa-motorcycle"></i>
                    </div>
                    <div>
                        <strong id="toastTitle" style="display:block; font-size:1.05rem; color:#fff; font-weight:800;">Rider En Route!</strong>
                        <span id="toastMessage" style="font-size:0.92rem; color:#cbd5e1; line-height:1.4;">Your rider has picked up your order and left the store.</span>
                    </div>
                </div>
                <button onclick="document.getElementById('trackingNotificationToast').style.display='none'" style="background:transparent; border:0; color:#94a3b8; font-size:1.3rem; cursor:pointer; padding:0 6px;">&times;</button>
            </div>
        </div>
        <div class="tracking-grid">
            <div id="map"></div>
            <div class="status-panel">
                <h3>Delivery Status <span class="live-indicator"><span class="live-dot"></span> LIVE</span></h3>
                <div id="eta-container" style="display:none;">
                    <div class="eta-badge"><i class="fas fa-stopwatch"></i> ETA: <span id="eta-time">Calculating...</span></div>
                </div>
                <div id="routeMetrics" class="route-metrics" style="display:none;">
                    <div class="route-metric-row"><span><i class="fas fa-route"></i> Distance</span><strong id="eta-distance">--</strong></div>
                    <div class="route-metric-row"><span><i class="fas fa-flag-checkered"></i> Estimated Drop-off</span><strong id="eta-dropoff">--</strong></div>
                    <div class="route-metric-row"><span><i class="fas fa-sync-alt"></i> Last Rider Update</span><strong id="driver-last-update">--</strong></div>
                </div>
                <div id="status-display">
                    <!-- Status will be updated by JS -->
                </div>
                <div class="driver-info" id="driver-info-panel" style="display: none;">
                    <h4>Driver Information</h4>
                    <div class="driver-card-mini">
                        <div>
                            <div class="driver-meta-name" id="driverName">--</div>
                            <span style="font-size:0.8rem; color:#7b6d64;">Assigned Delivery Rider</span>
                        </div>
                        <div id="driverPhoneRow" style="display: none;">
                            <a id="driverPhoneLink" href="#" class="btn-call-driver"><i class="fas fa-phone-alt"></i> Call</a>
                        </div>
                    </div>
                </div>
                <div class="delivery-address-info">
                    <h4><i class="fas fa-map-marker-alt"></i> Delivery Address</h4>
                    <p><?php echo htmlspecialchars($order['delivery_address']); ?></p>
                </div>
                <div class="delivery-chat-panel">
                    <h4><i class="fas fa-comments"></i> Chat with Rider</h4>
                    <div id="deliveryChatUnavailable" class="chat-unavailable" style="display: none;"></div>
                    <div id="deliveryChatMessages" class="delivery-chat-messages">
                        <div class="chat-empty">Loading chat...</div>
                    </div>
                    <form id="deliveryChatForm" class="delivery-chat-form">
                        <textarea id="deliveryChatInput" placeholder="Type your message..." maxlength="2000"></textarea>
                        <button type="submit" id="deliveryChatSendBtn">Send</button>
                    </form>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info text-center">
            Tracking information is not yet available for this order. It will appear here once a driver is assigned.
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($tracking_info): ?>
<!-- Leaflet Map API -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<!-- Leaflet Map API dependencies loaded dynamically -->

<script>
    let map;
    let driverMarker;
    let customerMarker;
    let routePolyline;
    let riderTrailPolyline;
    let customerLocationObj = null;
    let lastDriverLatLng = null;
    let lastRouteRefreshAt = 0;
    let hasAutoFitBounds = false;
    let driverMoveRaf = null;
    let driverPulseRaf = null;
    let driverPulseCircle = null;
    let activeDeliveryStatus = '';
    let trackingPollTimer = null;
    let chatPollTimer = null;
    let chatLoading = false;
    let chatLastMessageId = 0;
    let chatAvailable = false;
    let chatRole = 'customer';

    const orderId = <?php echo $order_id; ?>;
    const customerLat = <?php echo json_encode(isset($order['latitude']) ? (is_numeric($order['latitude']) ? (float)$order['latitude'] : null) : null); ?>;
    const customerLng = <?php echo json_encode(isset($order['longitude']) ? (is_numeric($order['longitude']) ? (float)$order['longitude'] : null) : null); ?>;
    const customerAddress = <?php echo json_encode((string)($order['delivery_address'] ?? '')); ?>;
    const routeVisibleStatuses = ['assigned', 'picked_up', 'on_the_way', 'arriving'];
    const movingAnimationStatuses = ['assigned', 'picked_up', 'on_the_way', 'arriving'];

    async function forwardGeocodeFromNominatim(addressText) {
        const raw = String(addressText || '').trim();
        if (!raw) return null;

        const parts = raw.split(',').map(p => p.trim()).filter(Boolean);
        const candidates = [];
        
        candidates.push(raw);
        if (parts.length > 2) {
            candidates.push(parts.slice(1).join(', '));
        }
        const cleaned = raw.replace(/\b\d{4}\b/g, '').replace(/CALABARZON|MIMAROPA|BICOL|Central Luzon|NCR|Metro Manila/gi, '').trim();
        if (cleaned && cleaned !== raw) {
            candidates.push(cleaned);
            if (parts.length > 2) {
                candidates.push(parts.slice(1).join(', ').replace(/\b\d{4}\b/g, '').replace(/CALABARZON|MIMAROPA|BICOL|Central Luzon|NCR|Metro Manila/gi, '').trim());
            }
        }
        if (parts.length >= 2) {
            candidates.push(parts.slice(-2).join(', '));
        }

        for (const query of candidates) {
            if (!query || query.length < 3) continue;
            try {
                const endpoint = 'https://nominatim.openstreetmap.org/search?format=jsonv2'
                    + '&addressdetails=1'
                    + '&countrycodes=ph'
                    + '&limit=1'
                    + '&q=' + encodeURIComponent(query);
                const response = await fetch(endpoint, {
                    method: 'GET',
                    headers: { Accept: 'application/json' }
                });
                if (!response.ok) continue;
                const payload = await response.json();
                if (Array.isArray(payload) && payload.length > 0) {
                    const first = payload[0] || {};
                    const lat = Number(first?.lat);
                    const lng = Number(first?.lon);
                    if (Number.isFinite(lat) && Number.isFinite(lng)) {
                        return { lat, lng };
                    }
                }
            } catch (error) {
                // Continue to next candidate query
            }
        }
        return null;
    }

    const driverIcon = L.divIcon({
        html: `<div style="background:linear-gradient(135deg, #b3261e, #ef6b2e); color:#fff; width:44px; height:44px; border-radius:50%; border:3px solid #fff; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 14px rgba(179,38,30,0.4);"><i class="fas fa-motorcycle" style="font-size:1.2rem;"></i></div>`,
        className: 'custom-driver-icon',
        iconSize: [44, 44],
        iconAnchor: [22, 22]
    });

    const customerIcon = L.divIcon({
        html: `<div style="background:#171922; color:#fff; width:38px; height:38px; border-radius:50%; border:3px solid #fff; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 10px rgba(0,0,0,0.3);"><i class="fas fa-home" style="font-size:1.05rem; color:#ef6b2e;"></i></div>`,
        className: 'custom-customer-icon',
        iconSize: [38, 38],
        iconAnchor: [19, 19]
    });

    function normalizeLocationSource(source) {
        return String(source || '').trim().toLowerCase();
    }

    function statusCanShowRoute(status) {
        return routeVisibleStatuses.includes(String(status || '').toLowerCase());
    }

    function statusCanAnimateMovement(status) {
        return movingAnimationStatuses.includes(String(status || '').toLowerCase());
    }

    function animateDriverTransition(targetLatLng, durationMs = 1200) {
        if (!driverMarker || !targetLatLng) return;

        const startLatLng = driverMarker.getLatLng();
        if (!startLatLng) {
            driverMarker.setLatLng(targetLatLng);
            return;
        }

        if (driverMoveRaf) {
            cancelAnimationFrame(driverMoveRaf);
            driverMoveRaf = null;
        }

        const startLat = startLatLng.lat;
        const startLng = startLatLng.lng;
        const endLat = targetLatLng[0];
        const endLng = targetLatLng[1];
        const startAt = performance.now();

        const tick = (now) => {
            const t = Math.min((now - startAt) / durationMs, 1);
            const eased = 1 - Math.pow(1 - t, 3);
            const lat = startLat + (endLat - startLat) * eased;
            const lng = startLng + (endLng - startLng) * eased;
            driverMarker.setLatLng([lat, lng]);

            if (t < 1) {
                driverMoveRaf = requestAnimationFrame(tick);
            } else {
                driverMoveRaf = null;
            }
        };

        driverMoveRaf = requestAnimationFrame(tick);
    }

    function triggerDriverMovementPulse(centerLatLng) {
        if (!map || !centerLatLng) return;

        if (!driverPulseCircle) {
            driverPulseCircle = L.circle(centerLatLng, {
                radius: 0,
                color: '#b3261e',
                weight: 2,
                opacity: 0,
                fillColor: '#b3261e',
                fillOpacity: 0
            }).addTo(map);
        }

        if (driverPulseRaf) {
            cancelAnimationFrame(driverPulseRaf);
            driverPulseRaf = null;
        }

        const startAt = performance.now();
        const durationMs = 950;

        const pulseTick = (now) => {
            const t = Math.min((now - startAt) / durationMs, 1);
            const eased = 1 - Math.pow(1 - t, 2);
            driverPulseCircle.setLatLng(centerLatLng);
            driverPulseCircle.setRadius(6 + 44 * eased);
            driverPulseCircle.setStyle({
                opacity: Math.max(0, 0.45 - t * 0.45),
                fillOpacity: Math.max(0, 0.18 - t * 0.18)
            });

            if (t < 1) {
                driverPulseRaf = requestAnimationFrame(pulseTick);
            } else {
                driverPulseCircle.setRadius(0);
                driverPulseCircle.setStyle({ opacity: 0, fillOpacity: 0 });
                driverPulseRaf = null;
            }
        };

        driverPulseRaf = requestAnimationFrame(pulseTick);
    }

    function initMap() {
        console.log("Initializing Leaflet Tracking Map...");
        
        map = L.map('map').setView([14.5995, 120.9842], 14);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        routePolyline = L.polyline([], {
            color: '#b3261e',
            opacity: 0.85,
            weight: 5
        }).addTo(map);

        riderTrailPolyline = L.polyline([], {
            color: '#ef6b2e',
            opacity: 0.95,
            weight: 4,
            dashArray: '6, 8'
        }).addTo(map);

        if (Number.isFinite(customerLat) && Number.isFinite(customerLng)) {
            const customerPosition = [customerLat, customerLng];
            customerLocationObj = customerPosition;
            customerMarker = L.marker(customerPosition, {
                title: 'Your Location',
                icon: customerIcon
            }).addTo(map);
            map.setView(customerPosition, 14);
        } else {
            const applyCustomerPosition = (lat, lng) => {
                if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) return false;
                const point = [Number(lat), Number(lng)];
                customerLocationObj = point;
                map.setView(point, 14);
                customerMarker = L.marker(point, {
                    title: 'Your Location',
                    icon: customerIcon
                }).addTo(map);
                return true;
            };

            const applyFallbackAddress = async () => {
                const fallback = await forwardGeocodeFromNominatim(customerAddress);
                if (!fallback) return false;
                const ok = applyCustomerPosition(fallback.lat, fallback.lng);
                if (ok && lastDriverLatLng) {
                    fitMapToDriverAndCustomer();
                    updateRouteAndEta(lastDriverLatLng[0], lastDriverLatLng[1], true);
                }
                return ok;
            };

            applyFallbackAddress();
        }

        requestBrowserNotificationPermission();
        fetchTrackingData();
        trackingPollTimer = setInterval(fetchTrackingData, 4000);

        initDeliveryChat();
    }

    let previousDeliveryStatus = '';

    function requestBrowserNotificationPermission() {
        if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    }

    function playNotificationChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const audioCtx = new AudioCtx();
            const osc1 = audioCtx.createOscillator();
            const osc2 = audioCtx.createOscillator();
            const gain = audioCtx.createGain();

            osc1.type = 'sine';
            osc2.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, audioCtx.currentTime);
            osc2.frequency.setValueAtTime(880, audioCtx.currentTime + 0.12);

            gain.gain.setValueAtTime(0.25, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);

            osc1.connect(gain);
            osc2.connect(gain);
            gain.connect(audioCtx.destination);

            osc1.start(audioCtx.currentTime);
            osc2.start(audioCtx.currentTime + 0.12);
            osc1.stop(audioCtx.currentTime + 0.12);
            osc2.stop(audioCtx.currentTime + 0.5);
        } catch (e) {}
    }

    function triggerRiderDepartureNotification(driverName = 'Your Driver') {
        const toast = document.getElementById('trackingNotificationToast');
        const toastTitle = document.getElementById('toastTitle');
        const toastMsg = document.getElementById('toastMessage');

        if (toast && toastTitle && toastMsg) {
            toastTitle.textContent = "🚴 Rider En Route!";
            toastMsg.textContent = `${driverName} has picked up your order and is now en route to your delivery address!`;
            toast.style.display = 'block';
        }

        playNotificationChime();

        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification("Lechon Delights - Rider En Route!", {
                    body: `${driverName} has picked up your order and is on the way!`,
                    icon: 'assets/images/logo.png'
                });
            } catch (e) {}
        }
    }

    async function fetchTrackingData() {
        try {
            const response = await fetch(`get_tracking_info.php?order_id=${orderId}`);
            const data = await response.json();

            if (!data.success) {
                return;
            }

            const newStatus = String(data.status || '').toLowerCase();
            if (previousDeliveryStatus && previousDeliveryStatus !== newStatus) {
                if (['picked_up', 'on_the_way'].includes(newStatus) && !['picked_up', 'on_the_way'].includes(previousDeliveryStatus)) {
                    triggerRiderDepartureNotification(data.driver_name || 'Your Rider');
                }
            }
            previousDeliveryStatus = newStatus;

            activeDeliveryStatus = newStatus;
            updateStatusPanel(data);
            updateDriverMarker(
                data.latitude,
                data.longitude,
                data.last_location_update || null,
                data.driver_name || 'Driver',
                data.location_source || '',
                activeDeliveryStatus
            );

            if (statusCanShowRoute(activeDeliveryStatus)) {
                updateRouteAndEta(data.latitude, data.longitude, true);
            } else {
                resetRouteEtaDetails();
            }
        } catch (error) {
            console.error('Error fetching tracking data:', error);
        }
    }

    function updateDriverMarker(lat, lng, lastUpdateRaw = null, driverName = 'Driver', locationSource = '', deliveryStatus = '') {
        if (lat === null || lng === null) {
            if (driverMarker) {
                map.removeLayer(driverMarker);
                driverMarker = null;
            }
            if (driverMoveRaf) {
                cancelAnimationFrame(driverMoveRaf);
                driverMoveRaf = null;
            }
            if (driverPulseRaf) {
                cancelAnimationFrame(driverPulseRaf);
                driverPulseRaf = null;
            }
            if (driverPulseCircle) {
                map.removeLayer(driverPulseCircle);
                driverPulseCircle = null;
            }
            lastDriverLatLng = null;
            if (riderTrailPolyline) {
                riderTrailPolyline.setLatLngs([]);
            }
            resetRouteEtaDetails();
            return;
        }

        const nextLat = parseFloat(lat);
        const nextLng = parseFloat(lng);
        if (!Number.isFinite(nextLat) || !Number.isFinite(nextLng)) {
            return;
        }

        const driverPosition = [nextLat, nextLng];
        const shouldAnimateMove = statusCanAnimateMovement(deliveryStatus);
        
        let movedDistanceMeters = 0;
        if (lastDriverLatLng && typeof L !== 'undefined') {
            const fromPoint = L.latLng(lastDriverLatLng[0], lastDriverLatLng[1]);
            const toPoint = L.latLng(nextLat, nextLng);
            movedDistanceMeters = fromPoint.distanceTo(toPoint) || 0;
        }

        if (!driverMarker) {
            driverMarker = L.marker(driverPosition, {
                title: `${driverName} Location`,
                icon: driverIcon
            }).addTo(map);
        } else {
            if (shouldAnimateMove && movedDistanceMeters >= 2) {
                animateDriverTransition(driverPosition, 1300);
                triggerDriverMovementPulse(driverPosition);
            } else {
                driverMarker.setLatLng(driverPosition);
            }
            driverMarker.setTitle(`${driverName} Location`);
        }

        if (!hasAutoFitBounds) {
            fitMapToDriverAndCustomer();
            hasAutoFitBounds = true;
        }

        const shouldAppendTrail = !lastDriverLatLng || movedDistanceMeters >= 4;

        if (shouldAppendTrail) {
            const paths = riderTrailPolyline.getLatLngs();
            paths.push(driverPosition);
            riderTrailPolyline.setLatLngs(paths);
        }

        lastDriverLatLng = driverPosition;

        const lastUpdateEl = document.getElementById('driver-last-update');
        if (lastUpdateEl) {
            const label = lastUpdateRaw ? formatServerDateTime(lastUpdateRaw) : 'Just now';
            let sourceLabel = '';
            const normSource = normalizeLocationSource(locationSource);
            if (normSource === 'employees_geo_tracking' || normSource === 'logistics_tracking') {
                sourceLabel = ' (Live GPS)';
            } else if (normSource === 'store_origin') {
                sourceLabel = ' (Store Pickup)';
            }
            lastUpdateEl.textContent = `${label}${sourceLabel}`;
        }
    }

    function fitMapToDriverAndCustomer() {
        if (!map || !driverMarker) {
            return;
        }

        if (customerMarker) {
            const group = L.featureGroup([customerMarker, driverMarker]);
            map.fitBounds(group.getBounds().pad(0.15));
            return;
        }

        map.setView(driverMarker.getLatLng(), 15);
    }

    async function updateRouteAndEta(driverLat, driverLng, force = false) {
        const numericDriverLat = parseFloat(driverLat);
        const numericDriverLng = parseFloat(driverLng);
        if (!Number.isFinite(numericDriverLat) || !Number.isFinite(numericDriverLng) || !customerLocationObj) {
            resetRouteEtaDetails();
            return;
        }

        const now = Date.now();
        if (!force && now - lastRouteRefreshAt < 10000) {
            return;
        }
        lastRouteRefreshAt = now;

        try {
            const endpoint = `https://router.project-osrm.org/route/v1/driving/${numericDriverLng},${numericDriverLat};${customerLocationObj[1]},${customerLocationObj[0]}?overview=full&geometries=geojson`;
            const response = await fetch(endpoint);
            if (!response.ok) {
                resetRouteEtaDetails();
                return;
            }
            const data = await response.json();
            if (data.routes && data.routes.length > 0) {
                const route = data.routes[0];
                const coordinates = route.geometry.coordinates;
                const durationSeconds = route.duration;
                const distanceMeters = route.distance;

                const latLngs = coordinates.map(coord => [coord[1], coord[0]]);
                if (routePolyline) {
                    routePolyline.setLatLngs(latLngs);
                }

                const durationMinutes = Math.round(durationSeconds / 60);
                const duration = durationMinutes > 0 ? `${durationMinutes} mins` : '1 min';
                const distanceKm = (distanceMeters / 1000).toFixed(1);
                const distance = `${distanceKm} km`;
                const dropOffTime = formatClockTime(new Date(Date.now() + durationSeconds * 1000));

                document.getElementById('eta-container').style.display = 'block';
                document.getElementById('eta-time').textContent = duration;
                document.getElementById('routeMetrics').style.display = 'block';
                document.getElementById('eta-distance').textContent = distance;
                document.getElementById('eta-dropoff').textContent = dropOffTime;
            } else {
                resetRouteEtaDetails();
            }
        } catch (e) {
            console.error('OSRM route fetch failed:', e);
            resetRouteEtaDetails();
        }
    }

    function resetRouteEtaDetails() {
        document.getElementById('eta-container').style.display = 'none';
        document.getElementById('routeMetrics').style.display = 'none';
        document.getElementById('eta-time').textContent = '--';
        document.getElementById('eta-distance').textContent = '--';
        document.getElementById('eta-dropoff').textContent = '--';
        if (routePolyline) {
            routePolyline.setLatLngs([]);
        }
    }

    function formatClockTime(dateObj) {
        if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) {
            return '--';
        }
        return dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function formatServerDateTime(rawValue) {
        if (!rawValue) {
            return '--';
        }
        const parsed = new Date(String(rawValue).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return String(rawValue);
        }
        return parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function updateStatusPanel(data) {
        const statusDisplay = document.getElementById('status-display');
        const driverPanel = document.getElementById('driver-info-panel');
        const driverPhoneRow = document.getElementById('driverPhoneRow');
        const driverPhoneLink = document.getElementById('driverPhoneLink');

        let iconClass = 'pending';
        let icon = 'fas fa-box-open';
        let statusText = 'Pending';
        let statusDesc = 'Your order is being processed.';

        const driverName = data.driver_name || 'Driver';
        const driverPhone = data.driver_phone || '';

        switch (data.status) {
            case 'assigned':
                iconClass = 'assigned';
                icon = 'fas fa-user-check';
                statusText = data.driver_name ? `Rider Assigned: ${driverName}` : 'Rider Assigned';
                statusDesc = data.driver_name 
                    ? `<strong>${driverName}</strong> is assigned and on their way to pick up your order.` 
                    : 'A driver is on their way to pick up your order.';
                if (driverPhone) {
                    statusDesc += `<br><a href="tel:${driverPhone}" class="btn-call-driver" style="margin-top:10px;"><i class="fas fa-phone-alt"></i> Call ${driverName} (${driverPhone})</a>`;
                }
                break;
            case 'picked_up':
            case 'on_the_way':
                iconClass = 'on_the_way';
                icon = 'fas fa-motorcycle';
                statusText = data.driver_name ? `En Route: ${driverName}` : 'On The Way';
                statusDesc = data.driver_name 
                    ? `<strong>${driverName}</strong> has picked up your order and is en route to your location.` 
                    : 'Your order has been picked up and is en route to you.';
                if (driverPhone) {
                    statusDesc += `<br><a href="tel:${driverPhone}" class="btn-call-driver" style="margin-top:10px;"><i class="fas fa-phone-alt"></i> Call ${driverName} (${driverPhone})</a>`;
                }
                break;
            case 'arriving':
                iconClass = 'on_the_way';
                icon = 'fas fa-map-marker-alt';
                statusText = data.driver_name ? `Arriving Soon: ${driverName}` : 'Arriving Soon';
                statusDesc = data.driver_name 
                    ? `<strong>${driverName}</strong> is near your delivery address.` 
                    : 'Your driver is near your location.';
                if (driverPhone) {
                    statusDesc += `<br><a href="tel:${driverPhone}" class="btn-call-driver" style="margin-top:10px;"><i class="fas fa-phone-alt"></i> Call ${driverName} (${driverPhone})</a>`;
                }
                break;
            case 'delivered':
                iconClass = 'delivered';
                icon = 'fas fa-check-circle';
                statusText = 'Delivered';
                statusDesc = 'Your order has been successfully delivered. Thank you!';
                break;
        }

        if (data.status === 'delivered' && data.proof_path) {
            statusDesc += `<br><a href="${data.proof_path}" target="_blank" class="view-proof-link"><i class="fas fa-camera"></i> View Proof of Delivery</a>`;
        }

        if (data.status === 'delivered') {
            if (data.delivery_review_exists) {
                statusDesc += '<br><button class="rate-delivery-btn disabled" disabled><i class="fas fa-star"></i> Delivery Rated</button>';
            } else {
                statusDesc += `<br><a href="leave_review.php?order_id=${orderId}" class="rate-delivery-btn"><i class="fas fa-star"></i> Rate Delivery Service</a>`;
            }
        }

        if (!statusCanShowRoute(data.status || '')) {
            resetRouteEtaDetails();
        }

        statusDisplay.innerHTML = `<div class="status-item"><div class="status-icon ${iconClass}"><i class="${icon}"></i></div><div class="status-text"><strong>${statusText}</strong><span>${statusDesc}</span></div></div>`;

        if (data.driver_name) {
            driverPanel.style.display = 'block';
            document.getElementById('driverName').textContent = data.driver_name;

            if (data.driver_phone) {
                driverPhoneRow.style.display = 'block';
                driverPhoneLink.textContent = data.driver_phone;
                driverPhoneLink.href = `tel:${data.driver_phone}`;
            } else {
                driverPhoneRow.style.display = 'none';
            }
        } else {
            driverPanel.style.display = 'none';
        }
    }

    function initDeliveryChat() {
        const chatForm = document.getElementById('deliveryChatForm');
        if (!chatForm || chatForm.dataset.bound === '1') {
            return;
        }

        chatForm.dataset.bound = '1';
        chatForm.addEventListener('submit', sendDeliveryChatMessage);

        loadDeliveryChat(true);
        chatPollTimer = setInterval(() => loadDeliveryChat(false), 5000);
    }

    async function loadDeliveryChat(initialLoad) {
        if (chatLoading) {
            return;
        }

        chatLoading = true;
        try {
            const limit = initialLoad ? 100 : 50;
            const response = await fetch(`api/delivery_chat.php?order_id=${orderId}&after_id=${chatLastMessageId}&limit=${limit}`);
            const data = await response.json();

            if (!data.success) {
                updateChatAvailability(false, data.message || 'Chat unavailable right now.');
                return;
            }

            chatRole = data.role || 'customer';
            updateChatAvailability(!!data.chat_available);

            if (initialLoad) {
                const container = document.getElementById('deliveryChatMessages');
                container.innerHTML = '';
            }

            const messages = Array.isArray(data.messages) ? data.messages : [];
            if (messages.length === 0 && chatLastMessageId === 0) {
                const container = document.getElementById('deliveryChatMessages');
                if (!container.children.length) {
                    container.innerHTML = '<div class="chat-empty">No messages yet. Start chatting with your rider.</div>';
                }
            }

            messages.forEach((message) => {
                appendChatMessage(message);
                chatLastMessageId = Math.max(chatLastMessageId, Number(message.id || 0));
            });
        } catch (error) {
            console.error('Failed to load delivery chat:', error);
        } finally {
            chatLoading = false;
        }
    }

    async function sendDeliveryChatMessage(event) {
        event.preventDefault();

        const input = document.getElementById('deliveryChatInput');
        const sendBtn = document.getElementById('deliveryChatSendBtn');
        const message = (input.value || '').trim();

        if (!message || !chatAvailable) {
            return;
        }

        sendBtn.disabled = true;
        try {
            const response = await fetch('api/delivery_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_id: orderId,
                    message: message
                })
            });

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Failed to send message.');
            }

            input.value = '';
            if (data.message) {
                appendChatMessage(data.message, true);
                chatLastMessageId = Math.max(chatLastMessageId, Number(data.message.id || 0));
            }
        } catch (error) {
            console.error('Failed to send chat message:', error);
        } finally {
            sendBtn.disabled = false;
        }
    }

    function updateChatAvailability(isAvailable, message = '') {
        chatAvailable = isAvailable;
        const unavailableEl = document.getElementById('deliveryChatUnavailable');
        const input = document.getElementById('deliveryChatInput');
        const sendBtn = document.getElementById('deliveryChatSendBtn');

        if (!isAvailable) {
            unavailableEl.style.display = 'block';
            unavailableEl.textContent = message || 'Chat will be available once a rider is assigned to your order.';
            input.disabled = true;
            sendBtn.disabled = true;
            input.placeholder = 'Chat is currently unavailable';
            return;
        }

        unavailableEl.style.display = 'none';
        input.disabled = false;
        sendBtn.disabled = false;
        input.placeholder = 'Type your message...';
    }

    function appendChatMessage(message, forceScroll = false) {
        const container = document.getElementById('deliveryChatMessages');
        const hasStickyBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 70;

        const empty = container.querySelector('.chat-empty');
        if (empty) {
            empty.remove();
        }

        const role = message.sender_role === 'driver' ? 'driver' : 'customer';
        const wrapper = document.createElement('div');
        wrapper.className = `chat-message ${role}`;

        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble';
        bubble.innerHTML = escapeHtml(String(message.message_text || '')).replace(/\n/g, '<br>');

        const time = document.createElement('div');
        time.className = 'chat-time';
        time.textContent = formatChatTime(message.created_at || '');

        wrapper.appendChild(bubble);
        wrapper.appendChild(time);
        container.appendChild(wrapper);

        if (forceScroll || hasStickyBottom) {
            container.scrollTop = container.scrollHeight;
        }
    }

    function formatChatTime(raw) {
        if (!raw) {
            return '';
        }

        const parsed = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return raw;
        }

        return parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    window.addEventListener('beforeunload', () => {
        if (trackingPollTimer) {
            clearInterval(trackingPollTimer);
        }
        if (chatPollTimer) {
            clearInterval(chatPollTimer);
        }
        if (driverMoveRaf) {
            cancelAnimationFrame(driverMoveRaf);
            driverMoveRaf = null;
        }
        if (driverPulseRaf) {
            cancelAnimationFrame(driverPulseRaf);
            driverPulseRaf = null;
        }
        if (driverPulseCircle && map) {
            map.removeLayer(driverPulseCircle);
            driverPulseCircle = null;
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof initMap === 'function') {
            initMap();
        }
    });
</script>
<?php endif; ?>

<?php
include 'includes/footer.php';
mysqli_close($conn);
?>
