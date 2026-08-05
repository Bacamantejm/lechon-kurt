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
        padding: 56px 0 70px;
        background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        min-height: 80vh;
    }
    .tracking-container { max-width: 1240px; margin: 0 auto; }
    .tracking-header { text-align: center; margin-bottom: 24px; }
    .tracking-header h1 {
        font-size: clamp(1.9rem, 4.4vw, 2.5rem);
        color: #b71c1c;
        margin-bottom: 8px;
        letter-spacing: 0.2px;
    }
    .tracking-header p { font-size: 1.03rem; color: #5f6c7a; margin: 0; }
    .tracking-meta {
        margin-top: 18px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }
    .tracking-meta-card {
        background: #fff;
        border: 1px solid #e6ebf1;
        border-radius: 12px;
        padding: 12px 14px;
        box-shadow: 0 5px 14px rgba(15, 23, 42, 0.06);
        display: flex;
        align-items: center;
        gap: 10px;
        text-align: left;
    }
    .tracking-meta-card i {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #ffebee;
        color: #c62828;
    }
    .tracking-meta-card span {
        display: block;
        font-size: 0.78rem;
        color: #7b8794;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        font-weight: 700;
    }
    .tracking-meta-card strong {
        display: block;
        color: #243142;
        font-size: 0.92rem;
        font-weight: 800;
        margin-top: 1px;
    }
    .tracking-grid { display: grid; grid-template-columns: 1.8fr 1fr; gap: 22px; }
    #map {
        height: 560px;
        width: 100%;
        border-radius: 14px;
        border: 1px solid #e6ebf1;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.11);
    }
    .status-panel {
        background: white;
        border-radius: 14px;
        padding: 22px;
        border: 1px solid #e6ebf1;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.09);
    }
    .status-panel h3 {
        font-size: 1.35rem;
        margin-bottom: 16px;
        border-bottom: 1px solid #eef1f5;
        padding-bottom: 12px;
        color: #172230;
    }
    .status-item { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
    .status-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .eta-badge {
        background-color: #e3f2fd;
        color: #0c4a92;
        padding: 10px 12px;
        border-radius: 10px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px;
        border: 1px solid #b8d5f5;
        width: 100%;
    }
    .route-metrics {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 12px;
        margin-bottom: 14px;
    }
    .route-metric-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.9rem;
        color: #334155;
        padding: 3px 0;
    }
    .route-metric-row strong {
        color: #0f172a;
    }
    .status-icon.pending { background: #fff3e0; color: #f57c00; }
    .status-icon.assigned { background: #e3f2fd; color: #1976d2; }
    .status-icon.on_the_way { background: #e0f7fa; color: #0097a7; }
    .status-icon.delivered { background: #e8f5e9; color: #388e3c; }
    .status-text strong { display: block; font-size: 1.03rem; color: #1f2937; }
    .status-text span { font-size: 0.88rem; color: #606f7f; line-height: 1.45; }
    .driver-info { margin-top: 18px; padding-top: 16px; border-top: 1px solid #eef1f5; }
    .driver-info p { margin: 5px 0; }
    .delivery-chat-panel { margin-top: 18px; padding-top: 16px; border-top: 1px solid #eef1f5; }
    .delivery-chat-panel h4 { margin-bottom: 10px; color: #18253a; }
    .delivery-chat-messages {
        max-height: 240px;
        overflow-y: auto;
        background: #f8fafc;
        border: 1px solid #e4e9f0;
        border-radius: 10px;
        padding: 12px;
        margin-bottom: 10px;
    }
    .chat-empty {
        color: #6b7280;
        text-align: center;
        padding: 12px;
        font-size: 0.9rem;
    }
    .chat-message {
        margin-bottom: 10px;
        display: flex;
        flex-direction: column;
        max-width: 85%;
    }
    .chat-message.customer { margin-left: auto; align-items: flex-end; }
    .chat-message.driver { margin-right: auto; align-items: flex-start; }
    .chat-bubble {
        border-radius: 12px;
        padding: 8px 12px;
        word-break: break-word;
        line-height: 1.35;
        font-size: 0.92rem;
    }
    .chat-message.customer .chat-bubble { background: #c62828; color: #fff; }
    .chat-message.driver .chat-bubble { background: #ffffff; color: #333; border: 1px solid #e5e7eb; }
    .chat-time { font-size: 0.75rem; color: #6b7280; margin-top: 2px; }
    .chat-unavailable {
        font-size: 0.9rem;
        color: #6b7280;
        background: #f3f4f6;
        border: 1px dashed #d1d5db;
        border-radius: 8px;
        padding: 10px;
        margin-bottom: 10px;
    }
    .delivery-chat-form { display: flex; gap: 8px; align-items: flex-end; }
    .delivery-chat-form textarea {
        flex: 1;
        min-height: 42px;
        max-height: 120px;
        border: 1px solid #d7dde5;
        border-radius: 10px;
        padding: 8px 10px;
        resize: vertical;
    }
    .delivery-chat-form button {
        border: 0;
        border-radius: 10px;
        background: #c62828;
        color: #fff;
        padding: 9px 14px;
        font-weight: 600;
    }
    .delivery-chat-form button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .view-proof-link {
        display: inline-block;
        margin-top: 10px;
        padding: 8px 15px;
        background-color: #28a745;
        color: white;
        text-decoration: none;
        border-radius: 5px;
        font-weight: 600;
    }
    .rate-delivery-btn {
        display: inline-block;
        margin-top: 10px;
        padding: 8px 15px;
        background-color: #ffc107;
        color: #333;
        text-decoration: none;
        border-radius: 5px;
        font-weight: 600;
        border: none;
        cursor: pointer;
    }
    .rate-delivery-btn.disabled {
        background-color: #e9ecef;
        color: #6c757d;
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
            <h1>Track Your Order</h1>
            <p>Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
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
        <div class="tracking-grid">
            <div id="map"></div>
            <div class="status-panel">
                <h3>Delivery Status</h3>
                <div id="eta-container" style="display:none;">
                    <div class="eta-badge"><i class="fas fa-clock"></i> ETA: <span id="eta-time">Calculating...</span></div>
                </div>
                <div id="routeMetrics" class="route-metrics" style="display:none;">
                    <div class="route-metric-row"><span>Distance</span><strong id="eta-distance">--</strong></div>
                    <div class="route-metric-row"><span>Estimated Drop-off</span><strong id="eta-dropoff">--</strong></div>
                    <div class="route-metric-row"><span>Last Rider Update</span><strong id="driver-last-update">--</strong></div>
                </div>
                <div id="status-display">
                    <!-- Status will be updated by JS -->
                </div>
                <div class="driver-info" id="driver-info-panel" style="display: none;">
                    <h4>Driver Information</h4>
                    <p><strong>Name:</strong> <span id="driverName"></span></p>
                    <p id="driverPhoneRow" style="display: none;"><strong>Phone:</strong> <a id="driverPhoneLink" href="#" rel="noopener"></a></p>
                </div>
                <div class="delivery-address-info" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                    <h4>Delivery Address</h4>
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
    const routeVisibleStatuses = ['picked_up', 'on_the_way', 'arriving'];
    const movingAnimationStatuses = ['on_the_way', 'arriving'];

    async function forwardGeocodeFromNominatim(addressText) {
        const query = String(addressText || '').trim();
        if (!query) return null;
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
            if (!response.ok) return null;
            const payload = await response.json();
            if (!Array.isArray(payload) || !payload.length) return null;
            const first = payload[0] || {};
            const lat = Number(first?.lat);
            const lng = Number(first?.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
            return { lat, lng };
        } catch (error) {
            return null;
        }
    }

    const driverIcon = L.divIcon({
        html: `<div style="background:#ef6b2e; color:#fff; width:40px; height:40px; border-radius:50%; border:3px solid #fff; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 8px rgba(0,0,0,0.4);"><i class="fas fa-motorcycle" style="font-size:1.1rem;"></i></div>`,
        className: 'custom-driver-icon',
        iconSize: [40, 40],
        iconAnchor: [20, 20]
    });

    const customerIcon = L.divIcon({
        html: `<div style="background:#b3261e; color:#fff; width:36px; height:36px; border-radius:50%; border:3px solid #fff; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-home" style="font-size:1rem;"></i></div>`,
        className: 'custom-customer-icon',
        iconSize: [36, 36],
        iconAnchor: [18, 18]
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
                color: '#c62828',
                weight: 2,
                opacity: 0,
                fillColor: '#c62828',
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
            color: '#2563eb',
            opacity: 0.85,
            weight: 5
        }).addTo(map);

        riderTrailPolyline = L.polyline([], {
            color: '#dc2626',
            opacity: 0.95,
            weight: 4
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
                return applyCustomerPosition(fallback.lat, fallback.lng);
            };

            applyFallbackAddress();
        }

        fetchTrackingData();
        trackingPollTimer = setInterval(fetchTrackingData, 8000);

        initDeliveryChat();
    }

    async function fetchTrackingData() {
        try {
            const response = await fetch(`get_tracking_info.php?order_id=${orderId}`);
            const data = await response.json();

            if (!data.success) {
                return;
            }

            activeDeliveryStatus = String(data.status || '').toLowerCase();
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
            const label = lastUpdateRaw ? formatServerDateTime(lastUpdateRaw) : '--';
            const source = normalizeLocationSource(locationSource) === 'employees_geo_tracking' ? ' (Live GPS)' : '';
            lastUpdateEl.textContent = `${label}${source}`;
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

        switch (data.status) {
            case 'assigned':
                iconClass = 'assigned';
                icon = 'fas fa-user-check';
                statusText = 'Driver Assigned';
                statusDesc = 'A driver is on their way to pick up your order.';
                break;
            case 'picked_up':
            case 'on_the_way':
                iconClass = 'on_the_way';
                icon = 'fas fa-truck';
                statusText = 'On The Way';
                statusDesc = 'Your order has been picked up and is en route to you.';
                break;
            case 'arriving':
                iconClass = 'on_the_way';
                icon = 'fas fa-map-marker-alt';
                statusText = 'Arriving Soon';
                statusDesc = 'Your driver is near your location.';
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
