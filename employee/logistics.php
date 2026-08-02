<?php
require_once 'session_check.php'; // Use the improved session check
require_once 'header.php'; // Use employee header which includes sidebar
require_once '../includes/config.php';
require_once '../EnhancedLogisticsService.php';
$google_maps_api_key = function_exists('getGoogleMapsApiKey')
    ? getGoogleMapsApiKey()
    : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));
$google_geocoding_enabled = function_exists('shouldUseGoogleGeocoding') ? shouldUseGoogleGeocoding() : true;

if (empty($is_driver)) {
    $_SESSION['error'] = 'Your account is not assigned to logistics delivery operations.';
    header('Location: dashboard.php');
    exit();
}

if (!function_exists('logisticsOrdersColumnExists')) {
    function logisticsOrdersColumnExists($conn, $column_name) {
        static $cache = [];
        $column_name = trim((string)$column_name);
        if ($column_name === '') {
            return false;
        }
        if (array_key_exists($column_name, $cache)) {
            return $cache[$column_name];
        }
        $safe_column = mysqli_real_escape_string($conn, $column_name);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `orders` LIKE '{$safe_column}'");
        $cache[$column_name] = ($result && mysqli_num_rows($result) > 0);
        return $cache[$column_name];
    }
}

$active_deliveries = [];
$historical_deliveries = [];
$weekly_stats = ['total_weekly' => 0, 'avg_time_weekly' => 0];

if ($employee_id !== null) {
    $logisticsService = new EnhancedLogisticsService($conn);
    // Fetch all deliveries for the driver
    $all_deliveries = $logisticsService->getDeliveriesForDriver($employee_id);
    $weekly_stats = $logisticsService->getDriverWeeklyStats($employee_id);

    $active_statuses = ['assigned', 'picked_up', 'on_the_way', 'arriving'];
    $historical_statuses = ['delivered', 'failed', 'cancelled'];

    foreach ($all_deliveries as $delivery) {
        if (in_array($delivery['current_status'], $active_statuses)) {
            $active_deliveries[] = $delivery;
        } elseif (in_array($delivery['current_status'], $historical_statuses)) {
            $historical_deliveries[] = $delivery;
        }
    }

    // Sort active deliveries to show the oldest assigned task first
    usort($active_deliveries, function($a, $b) {
        return strtotime($a['created_at']) <=> strtotime($b['created_at']);
    });

    // Attach customer coordinates when present in orders table.
    $has_order_latitude = logisticsOrdersColumnExists($conn, 'latitude');
    $has_order_longitude = logisticsOrdersColumnExists($conn, 'longitude');
    if ($has_order_latitude && $has_order_longitude && !empty($active_deliveries)) {
        $order_ids = [];
        foreach ($active_deliveries as $delivery_row) {
            $row_order_id = (int)($delivery_row['order_id'] ?? 0);
            if ($row_order_id > 0) {
                $order_ids[$row_order_id] = true;
            }
        }

        if (!empty($order_ids)) {
            $order_id_list = implode(',', array_map('intval', array_keys($order_ids)));
            $coord_result = mysqli_query($conn, "SELECT id, latitude, longitude FROM orders WHERE id IN ({$order_id_list})");
            $coord_map = [];
            if ($coord_result) {
                while ($coord_row = mysqli_fetch_assoc($coord_result)) {
                    $coord_map[(int)$coord_row['id']] = [
                        'latitude' => isset($coord_row['latitude']) && is_numeric($coord_row['latitude']) ? (float)$coord_row['latitude'] : null,
                        'longitude' => isset($coord_row['longitude']) && is_numeric($coord_row['longitude']) ? (float)$coord_row['longitude'] : null
                    ];
                }
            }

            foreach ($active_deliveries as &$delivery_row) {
                $row_order_id = (int)($delivery_row['order_id'] ?? 0);
                $delivery_row['customer_latitude'] = $coord_map[$row_order_id]['latitude'] ?? null;
                $delivery_row['customer_longitude'] = $coord_map[$row_order_id]['longitude'] ?? null;
            }
            unset($delivery_row);
        }
    }
}

// --- Search and Pagination Logic for History ---
$search_history = isset($_GET['search_history']) ? trim($_GET['search_history']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = 10;

// Filter historical deliveries if a search term is provided
$filtered_historical_deliveries = $historical_deliveries;
if (!empty($search_history)) {
    $filtered_historical_deliveries = array_filter($historical_deliveries, function($delivery) use ($search_history) {
        $search_lower = strtolower($search_history);
        return str_contains(strtolower($delivery['order_number']), $search_lower) || str_contains(strtolower($delivery['customer_name']), $search_lower);
    });
}

// Sort all historical deliveries by date descending
usort($filtered_historical_deliveries, function($a, $b) {
    return strtotime($b['updated_at'] ?? $b['created_at']) <=> strtotime($a['updated_at'] ?? $a['created_at']);
});

// Paginate the filtered results
$total_items = count($filtered_historical_deliveries);
$total_pages = ceil($total_items / $items_per_page);
$offset = ($page - 1) * $items_per_page;
$paginated_historical_deliveries = array_slice($filtered_historical_deliveries, $offset, $items_per_page);

// Fetch employee name for topbar (matching dashboard logic)
$stmt_user = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$employee_user = $result_user->fetch_assoc();
$stmt_user->close();
?>

<style>
    .delivery-chat-modal-messages {
        max-height: 360px;
        overflow-y: auto;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #f8f9fa;
        padding: 12px;
    }
    .delivery-chat-empty {
        text-align: center;
        color: #6b7280;
        padding: 20px 8px;
    }
    .delivery-chat-message {
        margin-bottom: 10px;
        max-width: 86%;
        display: flex;
        flex-direction: column;
    }
    .delivery-chat-message.driver {
        margin-left: auto;
        align-items: flex-end;
    }
    .delivery-chat-message.customer {
        margin-right: auto;
        align-items: flex-start;
    }
    .delivery-chat-bubble {
        padding: 8px 12px;
        border-radius: 12px;
        line-height: 1.35;
        font-size: 0.92rem;
        word-break: break-word;
    }
    .delivery-chat-message.driver .delivery-chat-bubble {
        background: #1976d2;
        color: #fff;
    }
    .delivery-chat-message.customer .delivery-chat-bubble {
        background: #fff;
        color: #333;
        border: 1px solid #e5e7eb;
    }
    .delivery-chat-time {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 2px;
    }
    .delivery-chat-unavailable {
        margin-bottom: 10px;
        padding: 10px;
        border-radius: 8px;
        border: 1px dashed #d1d5db;
        background: #f3f4f6;
        color: #6b7280;
        font-size: 0.9rem;
    }
    .delivery-chat-input-wrap {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        margin-top: 10px;
    }
    .delivery-chat-input-wrap textarea {
        flex: 1;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 8px 10px;
        min-height: 42px;
        max-height: 140px;
        resize: vertical;
    }
    .delivery-chat-input-wrap button {
        border: 0;
        border-radius: 8px;
        background: #1976d2;
        color: #fff;
        font-weight: 600;
        padding: 9px 14px;
    }
    .delivery-chat-input-wrap button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .live-nav-map {
        width: 100%;
        height: 420px;
        border-radius: 12px;
        border: 1px solid #dbe3ec;
        background: #f8fafc;
    }
    .live-nav-metrics {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 12px;
    }
    .live-nav-metric-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 5px 0;
        font-size: 0.92rem;
        color: #334155;
    }
    .live-nav-metric-row strong {
        color: #111827;
    }
    .live-nav-target {
        margin-top: 12px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 10px;
        font-size: 0.9rem;
        color: #334155;
    }
    .live-nav-target h6 {
        margin-bottom: 8px;
        color: #0f172a;
    }
</style>

<!-- Top Navigation Bar (Copied from dashboard) -->
<div class="admin-topbar">
    <div class="topbar-content">
        <div class="d-flex align-items-center">
            <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
            <div class="topbar-title">
                <h1>My Assigned Deliveries</h1>
            </div>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <div class="notification-wrapper">
                <button class="theme-toggler" id="notificationBell" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="badge bg-danger" id="notificationCount"></span>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span>Notifications</span>
                        <a href="#" id="markAllRead">Mark all as read</a>
                    </div>
                    <div class="notification-list" id="notificationList"><div class="text-center p-3">Loading...</div></div>
                    <div class="notification-footer"><a href="notifications.php">View all notifications</a></div>
                </div>
            </div>
            <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                <i class="fas fa-moon"></i>
            </button>
            <div class="date-display" id="currentDate" style="color: #666; font-size: 0.9rem; margin-right: 15px;"></div>
            <div class="admin-profile">
                <span><?php echo htmlspecialchars($employee_user['full_name']); ?></span>
                <i class="fas fa-user-circle"></i>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="admin-main">

    <!-- Performance Summary -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3 mb-md-0">
            <div class="stat-card h-100">
                <div class="stat-icon" style="background-color: #e3f2fd;">
                    <i class="fas fa-truck-loading" style="color: #1976d2;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $weekly_stats['total_weekly']; ?></h3>
                    <p>Deliveries Completed This Week</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card h-100">
                <div class="stat-icon" style="background-color: #e8f5e9;">
                    <i class="fas fa-stopwatch" style="color: #388e3c;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $weekly_stats['avg_time_weekly']; ?> mins</h3>
                    <p>Average Delivery Time</p>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" id="deliveryTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-deliveries" type="button" role="tab" aria-controls="active-deliveries" aria-selected="true">
                Active Deliveries <span class="badge bg-primary ms-1"><?= count($active_deliveries) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#delivery-history" type="button" role="tab" aria-controls="delivery-history" aria-selected="false">
                Delivery History
            </button>
        </li>
    </ul>

    <div class="tab-content" id="deliveryTabContent">
        <div class="tab-pane fade show active" id="active-deliveries" role="tabpanel" aria-labelledby="active-tab">
            <?php if ($user_type === 'employee' && !$employee_id): ?>
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Configuration Error:</strong> Your user account is of type 'employee' but is not linked to an employee record. Please contact an administrator to fix your profile.
                </div>
            <?php elseif (empty($active_deliveries)): ?>
                <div class="recent-section" style="background: transparent; box-shadow: none; padding: 0;">
                    <div class="alert alert-info text-center p-5" style="background-color: #fff; border-radius: 15px; border: none; box-shadow: 0 5px 15px rgba(0,0,0,0.05);">
                        <i class="fas fa-truck fa-3x mb-3 text-muted"></i>
                        <h4>No active deliveries assigned to you.</h4>
                        <p class="text-muted">Please check back later or contact your manager.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="recent-section" style="background: transparent; box-shadow: none; padding: 0;">
                    <div class="row">
                    <?php foreach ($active_deliveries as $delivery): ?>
                        <div class="col-12" id="delivery-card-<?php echo $delivery['id']; ?>">
                            <div class="card delivery-card status-<?php echo htmlspecialchars($delivery['current_status']); ?> mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="m-0">Order #<?php echo htmlspecialchars($delivery['order_number']); ?></h5>
                                    <span class="badge bg-primary p-2"><?php echo ucwords(str_replace('_', ' ', $delivery['current_status'])); ?></span>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 delivery-details">
                                            <p><i class="fas fa-user fa-fw text-muted"></i> <strong>Customer:</strong> <?php echo htmlspecialchars($delivery['customer_name']); ?></p>
                                            <p><i class="fas fa-phone fa-fw text-muted"></i> <strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($delivery['customer_phone']); ?>"><?php echo htmlspecialchars($delivery['customer_phone']); ?></a></p>
                                            <p><i class="fas fa-map-marker-alt fa-fw text-muted"></i> <strong>Address:</strong> <?php echo htmlspecialchars($delivery['delivery_address']); ?></p>
                                            <p><i class="fas fa-money-bill-wave fa-fw text-muted"></i> <strong>Amount to Collect:</strong> &#8369;<?php echo number_format($delivery['total_amount'], 2); ?> (Cash)</p>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="delivery-actions d-flex justify-content-md-end flex-wrap gap-2">
                                                <?php $status = $delivery['current_status']; ?>
                                                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($delivery['delivery_address']); ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="fas fa-map-marked-alt"></i> Map</a>
                                                <button class="btn btn-primary btn-sm open-live-navigation"
                                                        data-order-id="<?php echo (int)$delivery['order_id']; ?>"
                                                        data-tracking-id="<?php echo (int)$delivery['id']; ?>"
                                                        data-order-number="<?php echo htmlspecialchars($delivery['order_number'], ENT_QUOTES); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($delivery['customer_name'], ENT_QUOTES); ?>"
                                                        data-delivery-address="<?php echo htmlspecialchars($delivery['delivery_address'], ENT_QUOTES); ?>"
                                                        data-customer-lat="<?php echo htmlspecialchars((string)($delivery['customer_latitude'] ?? ''), ENT_QUOTES); ?>"
                                                        data-customer-lng="<?php echo htmlspecialchars((string)($delivery['customer_longitude'] ?? ''), ENT_QUOTES); ?>">
                                                    <i class="fas fa-location-arrow"></i> Live Navigation
                                                </button>
                                                <button class="btn btn-secondary btn-sm open-delivery-chat"
                                                        data-order-id="<?php echo intval($delivery['order_id']); ?>"
                                                        data-order-number="<?php echo htmlspecialchars($delivery['order_number'], ENT_QUOTES); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($delivery['customer_name'], ENT_QUOTES); ?>">
                                                    <i class="fas fa-comments"></i> Chat
                                                </button>
                                                <button class="btn btn-info text-white btn-sm" onclick="updateStatus(<?php echo $delivery['id']; ?>, 'on_the_way')" 
                                                    <?php if (!in_array($status, ['assigned', 'picked_up'])) echo 'disabled'; ?>>On The Way</button>
                                                <button class="btn btn-warning text-dark btn-sm" onclick="updateStatus(<?php echo $delivery['id']; ?>, 'arriving')" <?php if ($status !== 'on_the_way') echo 'disabled'; ?>>Arriving</button>
                                                <button class="btn btn-success btn-sm" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#proofOfDeliveryModal"
                                                        data-tracking-id="<?php echo $delivery['id']; ?>"
                                                        data-order-id="<?php echo $delivery['order_id']; ?>"
                                                        data-driver-id="<?php echo $delivery['driver_id']; ?>"
                                                        <?php if (!in_array($status, ['on_the_way', 'arriving'])) echo 'disabled'; ?>>
                                                    <i class="fas fa-check-circle"></i> Delivered
                                                </button>
                                                <button class="btn btn-danger btn-sm" onclick="markFailed(<?php echo $delivery['id']; ?>)">Failed</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="tab-pane fade" id="delivery-history" role="tabpanel" aria-labelledby="history-tab">
            <div class="recent-section">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3>Completed & Failed Deliveries</h3>
                    <form method="GET" class="d-flex" style="max-width: 300px;">
                        <input type="hidden" name="tab" value="history">
                        <input type="text" name="search_history" class="form-control form-control-sm" placeholder="Search Order# or Customer" value="<?= htmlspecialchars($search_history) ?>">
                        <button type="submit" class="btn btn-primary btn-sm ms-2"><i class="fas fa-search"></i></button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Final Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paginated_historical_deliveries)): ?>
                                <tr><td colspan="5" class="text-center py-4">No delivery history found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($paginated_historical_deliveries as $delivery): ?>
                                    <?php
                                    $status_class = 'badge-info';
                                    if ($delivery['current_status'] == 'delivered') $status_class = 'badge-approved';
                                    if ($delivery['current_status'] == 'failed' || $delivery['current_status'] == 'cancelled') $status_class = 'badge-rejected';
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($delivery['order_number']) ?></strong></td>
                                        <td><?= htmlspecialchars($delivery['customer_name']) ?></td>
                                        <td><?= date('M d, Y h:i A', strtotime($delivery['updated_at'] ?? $delivery['created_at'])) ?></td>
                                        <td><span class="status-badge <?= $status_class ?>"><?= ucwords(str_replace('_', ' ', $delivery['current_status'])) ?></span></td>
                                        <td><?= htmlspecialchars($delivery['delivery_notes'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Delivery History Pagination" class="mt-4 d-flex justify-content-center">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=history&page=<?= $page - 1 ?>&search_history=<?= urlencode($search_history) ?>">Previous</a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?tab=history&page=<?= $i ?>&search_history=<?= urlencode($search_history) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=history&page=<?= $page + 1 ?>&search_history=<?= urlencode($search_history) ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
</div>
</div>

<div class="modal fade" id="liveNavigationModal" tabindex="-1" aria-labelledby="liveNavigationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="liveNavigationModalLabel">Live Navigation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2">
                    <strong>Order:</strong> <span id="liveNavOrderNumber">-</span> |
                    <strong>Customer:</strong> <span id="liveNavCustomerName">-</span>
                </div>
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div id="liveNavigationMap" class="live-nav-map"></div>
                    </div>
                    <div class="col-lg-4">
                        <div class="live-nav-metrics">
                            <div class="live-nav-metric-row"><span>Distance</span><strong id="liveNavDistance">--</strong></div>
                            <div class="live-nav-metric-row"><span>ETA</span><strong id="liveNavEta">--</strong></div>
                            <div class="live-nav-metric-row"><span>Expected Drop-off</span><strong id="liveNavDropoff">--</strong></div>
                            <div class="live-nav-metric-row"><span>Last GPS Update</span><strong id="liveNavLastUpdate">--</strong></div>
                        </div>
                        <div class="live-nav-target">
                            <h6>Customer Drop-off</h6>
                            <div id="liveNavAddress">-</div>
                        </div>
                        <a id="liveNavExternalLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm mt-3 w-100">
                            <i class="fas fa-route"></i> Open Route in Google Maps
                        </a>
                        <div id="liveNavStatusMsg" class="small text-muted mt-2"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deliveryChatModal" tabindex="-1" aria-labelledby="deliveryChatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deliveryChatModalLabel">Delivery Chat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2">
                    <strong>Order:</strong> <span id="deliveryChatOrderNumber">-</span> |
                    <strong>Customer:</strong> <span id="deliveryChatCustomerName">-</span>
                </div>
                <div id="deliveryChatUnavailable" class="delivery-chat-unavailable" style="display:none;"></div>
                <div id="deliveryChatMessages" class="delivery-chat-modal-messages">
                    <div class="delivery-chat-empty">Loading messages...</div>
                </div>
                <form id="deliveryChatForm" class="delivery-chat-input-wrap">
                    <textarea id="deliveryChatInput" placeholder="Type message to customer..." maxlength="2000"></textarea>
                    <button type="submit" id="deliveryChatSendBtn">Send</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'proof_of_delivery_modal.php'; ?>
<script>
    document.body.classList.add('driver-app');
    document.body.dataset.driverTracking = 'enabled';
    window.driverTrackingConfig = {
        enabled: true,
        apiUrl: '../api/update_driver_location.php',
        updateInterval: 15000
    };
</script>
<script src="../js/driver_geolocation_tracker.js"></script>
<script>
    let deliveryChatModal = null;
    let activeChatOrderId = 0;
    let activeChatLastMessageId = 0;
    let activeChatPollTimer = null;
    let activeChatLoading = false;
    let activeChatAvailable = false;
    let liveNavigationModal = null;
    let liveNavMap = null;
    let liveNavDirectionsService = null;
    let liveNavDirectionsRenderer = null;
    let liveNavDriverMarker = null;
    let liveNavCustomerMarker = null;
    let liveNavWatchId = null;
    let liveNavIsOpen = false;
    let liveNavLastRouteAt = 0;
    let liveNavLastDriverPosition = null;
    let liveNavTarget = null;
    let liveNavGoogleReady = false;
    let liveNavGoogleGeocodingAvailable = <?php echo $google_geocoding_enabled ? 'true' : 'false'; ?>;

    window.initDriverNavigationMap = function() {
        liveNavGoogleReady = true;
        if (liveNavIsOpen && liveNavTarget) {
            ensureLiveNavigationMap();
            refreshCustomerDestination().catch((error) => {
                console.error('Failed to load customer destination:', error);
            });
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('tab') === 'history') {
            const historyTab = new bootstrap.Tab(document.getElementById('history-tab'));
            if (historyTab) {
                historyTab.show();
            }
        }

        initThemeToggler();
        initDeliveryChatModal();
        initLiveNavigationModal();
    });

    function initThemeToggler() {
        const themeToggler = document.getElementById('themeToggler');
        if (!themeToggler) {
            return;
        }

        const body = document.body;
        const icon = themeToggler.querySelector('i');

        try {
            if (localStorage.getItem('theme') === 'dark') {
                body.classList.add('dark-mode');
                if (icon) {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                }
            }
        } catch (e) {
            console.warn('localStorage access denied:', e.message);
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            } catch (e) {
                console.warn('Could not save theme preference:', e.message);
            }
            if (icon) {
                icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
        });
    }

    function initLiveNavigationModal() {
        const modalEl = document.getElementById('liveNavigationModal');
        if (!modalEl) {
            return;
        }

        liveNavigationModal = new bootstrap.Modal(modalEl);

        document.querySelectorAll('.open-live-navigation').forEach((button) => {
            button.addEventListener('click', () => openLiveNavigation(button));
        });

        modalEl.addEventListener('shown.bs.modal', () => {
            liveNavIsOpen = true;
            if (liveNavMap && window.google && google.maps) {
                google.maps.event.trigger(liveNavMap, 'resize');
            }
        });

        modalEl.addEventListener('hidden.bs.modal', () => {
            liveNavIsOpen = false;
            stopLiveDriverWatch();
            liveNavLastDriverPosition = null;
            liveNavLastRouteAt = 0;
            clearLiveNavMetrics();

            if (liveNavDirectionsRenderer) {
                liveNavDirectionsRenderer.set('directions', null);
            }
            if (liveNavDriverMarker) {
                liveNavDriverMarker.setMap(null);
                liveNavDriverMarker = null;
            }
            if (liveNavCustomerMarker) {
                liveNavCustomerMarker.setMap(null);
                liveNavCustomerMarker = null;
            }
            liveNavTarget = null;
            setLiveNavStatus('');
        });
    }

    function openLiveNavigation(button) {
        if (!button || !liveNavigationModal) {
            return;
        }

        const parsedLat = parseFloat(button.dataset.customerLat || '');
        const parsedLng = parseFloat(button.dataset.customerLng || '');
        liveNavTarget = {
            orderId: Number(button.dataset.orderId || 0),
            trackingId: Number(button.dataset.trackingId || 0),
            orderNumber: button.dataset.orderNumber || '-',
            customerName: button.dataset.customerName || '-',
            deliveryAddress: button.dataset.deliveryAddress || '',
            customerLat: Number.isFinite(parsedLat) ? parsedLat : null,
            customerLng: Number.isFinite(parsedLng) ? parsedLng : null,
            customerLatLng: null
        };

        document.getElementById('liveNavOrderNumber').textContent = liveNavTarget.orderNumber;
        document.getElementById('liveNavCustomerName').textContent = liveNavTarget.customerName;
        document.getElementById('liveNavAddress').textContent = liveNavTarget.deliveryAddress || '-';
        document.getElementById('liveNavLastUpdate').textContent = '--';
        clearLiveNavMetrics();
        setLiveNavStatus('Starting live navigation...');

        setLiveNavExternalLink(null, null, liveNavTarget.deliveryAddress);
        liveNavigationModal.show();

        if (!ensureLiveNavigationMap()) {
            setLiveNavStatus('Map is still loading. You can open route externally for now.');
            return;
        }

        refreshCustomerDestination()
            .then(() => {
                startLiveDriverWatch();
            })
            .catch((error) => {
                console.error('Customer location setup failed:', error);
                setLiveNavStatus('Unable to resolve customer map location. Route preview may be limited.');
                startLiveDriverWatch();
            });
    }

    function ensureLiveNavigationMap() {
        if (!(window.google && google.maps)) {
            return false;
        }

        const mapEl = document.getElementById('liveNavigationMap');
        if (!mapEl) {
            return false;
        }

        if (!liveNavMap) {
            liveNavMap = new google.maps.Map(mapEl, {
                zoom: 13,
                center: { lat: 14.5995, lng: 120.9842 },
                mapTypeId: 'roadmap',
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false
            });

            liveNavDirectionsService = new google.maps.DirectionsService();
            liveNavDirectionsRenderer = new google.maps.DirectionsRenderer({
                map: liveNavMap,
                suppressMarkers: true,
                preserveViewport: false,
                polylineOptions: {
                    strokeColor: '#2563eb',
                    strokeOpacity: 0.9,
                    strokeWeight: 5
                }
            });
        }

        return true;
    }

    async function refreshCustomerDestination() {
        if (!liveNavTarget) {
            return;
        }
        if (!ensureLiveNavigationMap()) {
            return;
        }

        let destination = null;
        if (Number.isFinite(liveNavTarget.customerLat) && Number.isFinite(liveNavTarget.customerLng)) {
            destination = {
                lat: liveNavTarget.customerLat,
                lng: liveNavTarget.customerLng
            };
        } else if (liveNavTarget.deliveryAddress) {
            destination = await geocodeAddressToLatLng(liveNavTarget.deliveryAddress);
        }

        if (!destination) {
            setLiveNavStatus('Customer location coordinates unavailable. Please verify address.');
            return;
        }

        liveNavTarget.customerLatLng = destination;

        if (!liveNavCustomerMarker) {
            liveNavCustomerMarker = new google.maps.Marker({
                map: liveNavMap,
                position: destination,
                title: 'Customer Location',
                icon: {
                    url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                }
            });
        } else {
            liveNavCustomerMarker.setPosition(destination);
        }

        setLiveNavExternalLink(null, destination, liveNavTarget.deliveryAddress);
        if (!liveNavDriverMarker) {
            liveNavMap.setCenter(destination);
            liveNavMap.setZoom(15);
        }
    }

    function isGoogleGeocodingUnavailableStatus(status) {
        const normalized = String(status || '').trim().toUpperCase();
        return normalized === 'REQUEST_DENIED'
            || normalized === 'OVER_QUERY_LIMIT'
            || normalized === 'OVER_DAILY_LIMIT'
            || normalized === 'INVALID_REQUEST';
    }

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

    function geocodeAddressToLatLng(address) {
        return new Promise((resolve) => {
            if (!address) {
                resolve(null);
                return;
            }

            const resolveWithFallback = async () => {
                resolve(await forwardGeocodeFromNominatim(address));
            };

            if (!liveNavGoogleGeocodingAvailable || !(window.google && google.maps)) {
                resolveWithFallback();
                return;
            }

            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address: address }, async (results, status) => {
                if (status === 'OK' && results && results[0] && results[0].geometry && results[0].geometry.location) {
                    const loc = results[0].geometry.location;
                    resolve({ lat: loc.lat(), lng: loc.lng() });
                    return;
                }
                if (isGoogleGeocodingUnavailableStatus(status)) {
                    liveNavGoogleGeocodingAvailable = false;
                }
                resolve(await forwardGeocodeFromNominatim(address));
            });
        });
    }

    function startLiveDriverWatch() {
        stopLiveDriverWatch();

        if (!navigator.geolocation) {
            setLiveNavStatus('GPS is not supported on this device/browser.');
            return;
        }

        const options = {
            enableHighAccuracy: true,
            timeout: 12000,
            maximumAge: 4000
        };

        navigator.geolocation.getCurrentPosition(
            (position) => handleLiveDriverPosition(position, true),
            () => setLiveNavStatus('Unable to get your current GPS position.'),
            options
        );

        liveNavWatchId = navigator.geolocation.watchPosition(
            (position) => handleLiveDriverPosition(position, false),
            () => setLiveNavStatus('GPS update failed. Please keep location permission enabled.'),
            options
        );
    }

    function stopLiveDriverWatch() {
        if (liveNavWatchId !== null && navigator.geolocation) {
            navigator.geolocation.clearWatch(liveNavWatchId);
        }
        liveNavWatchId = null;
    }

    function handleLiveDriverPosition(position, forceRouteRefresh) {
        if (!liveNavIsOpen || !position || !position.coords) {
            return;
        }
        if (!ensureLiveNavigationMap()) {
            return;
        }

        const lat = Number(position.coords.latitude);
        const lng = Number(position.coords.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }

        const driverLatLng = new google.maps.LatLng(lat, lng);
        let heading = 0;
        if (liveNavLastDriverPosition && google.maps.geometry && google.maps.geometry.spherical) {
            heading = google.maps.geometry.spherical.computeHeading(liveNavLastDriverPosition, driverLatLng) || 0;
        }

        if (!liveNavDriverMarker) {
            liveNavDriverMarker = new google.maps.Marker({
                map: liveNavMap,
                position: driverLatLng,
                title: 'Your Location',
                icon: {
                    path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
                    scale: 7,
                    rotation: heading,
                    fillColor: '#dc2626',
                    fillOpacity: 1,
                    strokeColor: '#ffffff',
                    strokeWeight: 2
                }
            });
        } else {
            liveNavDriverMarker.setPosition(driverLatLng);
            const markerIcon = liveNavDriverMarker.getIcon() || {};
            liveNavDriverMarker.setIcon({
                path: markerIcon.path || google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
                scale: markerIcon.scale || 7,
                rotation: heading,
                fillColor: markerIcon.fillColor || '#dc2626',
                fillOpacity: markerIcon.fillOpacity || 1,
                strokeColor: markerIcon.strokeColor || '#ffffff',
                strokeWeight: markerIcon.strokeWeight || 2
            });
        }

        liveNavLastDriverPosition = driverLatLng;
        document.getElementById('liveNavLastUpdate').textContent = formatLiveNavTime(new Date(position.timestamp || Date.now()));

        recalculateLiveRoute(forceRouteRefresh === true);
    }

    function recalculateLiveRoute(forceRefresh = false) {
        if (!liveNavDirectionsService || !liveNavDriverMarker || !liveNavTarget || !liveNavTarget.customerLatLng) {
            return;
        }

        const now = Date.now();
        if (!forceRefresh && (now - liveNavLastRouteAt) < 5000) {
            return;
        }
        liveNavLastRouteAt = now;

        const origin = liveNavDriverMarker.getPosition();
        const destination = liveNavTarget.customerLatLng;

        liveNavDirectionsService.route({
            origin: origin,
            destination: destination,
            travelMode: google.maps.TravelMode.DRIVING
        }, (response, status) => {
            if (status !== 'OK' || !response || !response.routes || !response.routes[0] || !response.routes[0].legs || !response.routes[0].legs[0]) {
                clearLiveNavMetrics();
                setLiveNavStatus('Unable to calculate route right now.');
                return;
            }

            if (liveNavDirectionsRenderer) {
                liveNavDirectionsRenderer.setDirections(response);
            }

            const leg = response.routes[0].legs[0];
            updateLiveNavMetrics(leg);
            setLiveNavExternalLink(origin, destination, liveNavTarget.deliveryAddress);
            setLiveNavStatus('Live route updated.');
        });
    }

    function updateLiveNavMetrics(leg) {
        const distanceText = (leg.distance && leg.distance.text) ? leg.distance.text : '--';
        const etaText = (leg.duration && leg.duration.text) ? leg.duration.text : '--';
        const durationSeconds = Number((leg.duration && leg.duration.value) ? leg.duration.value : 0);
        const dropOffText = durationSeconds > 0
            ? formatLiveNavTime(new Date(Date.now() + (durationSeconds * 1000)))
            : '--';

        document.getElementById('liveNavDistance').textContent = distanceText;
        document.getElementById('liveNavEta').textContent = etaText;
        document.getElementById('liveNavDropoff').textContent = dropOffText;
    }

    function clearLiveNavMetrics() {
        document.getElementById('liveNavDistance').textContent = '--';
        document.getElementById('liveNavEta').textContent = '--';
        document.getElementById('liveNavDropoff').textContent = '--';
    }

    function formatLiveNavTime(dateObj) {
        if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) {
            return '--';
        }
        return dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function setLiveNavStatus(message) {
        const statusEl = document.getElementById('liveNavStatusMsg');
        if (!statusEl) {
            return;
        }
        statusEl.textContent = message || '';
    }

    function setLiveNavExternalLink(origin, destination, fallbackAddress) {
        const linkEl = document.getElementById('liveNavExternalLink');
        if (!linkEl) {
            return;
        }

        if (destination && typeof destination.lat !== 'undefined' && typeof destination.lng !== 'undefined') {
            const destinationLat = typeof destination.lat === 'function' ? destination.lat() : destination.lat;
            const destinationLng = typeof destination.lng === 'function' ? destination.lng() : destination.lng;
            let url = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destinationLat + ',' + destinationLng)}&travelmode=driving`;

            if (origin && typeof origin.lat !== 'undefined' && typeof origin.lng !== 'undefined') {
                const originLat = typeof origin.lat === 'function' ? origin.lat() : origin.lat;
                const originLng = typeof origin.lng === 'function' ? origin.lng() : origin.lng;
                url += `&origin=${encodeURIComponent(originLat + ',' + originLng)}`;
            }

            linkEl.href = url;
            return;
        }

        linkEl.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(fallbackAddress || '')}`;
    }

    function initDeliveryChatModal() {
        const modalEl = document.getElementById('deliveryChatModal');
        if (!modalEl) {
            return;
        }

        deliveryChatModal = new bootstrap.Modal(modalEl);

        document.querySelectorAll('.open-delivery-chat').forEach((button) => {
            button.addEventListener('click', () => {
                const orderId = Number(button.dataset.orderId || 0);
                const orderNumber = button.dataset.orderNumber || '-';
                const customerName = button.dataset.customerName || '-';
                openDeliveryChat(orderId, orderNumber, customerName);
            });
        });

        const form = document.getElementById('deliveryChatForm');
        if (form) {
            form.addEventListener('submit', sendDeliveryChatMessage);
        }

        modalEl.addEventListener('hidden.bs.modal', () => {
            clearInterval(activeChatPollTimer);
            activeChatPollTimer = null;
            activeChatOrderId = 0;
            activeChatLastMessageId = 0;
            activeChatLoading = false;
            activeChatAvailable = false;
        });
    }

    function openDeliveryChat(orderId, orderNumber, customerName) {
        if (!orderId || !deliveryChatModal) {
            return;
        }

        activeChatOrderId = orderId;
        activeChatLastMessageId = 0;
        activeChatLoading = false;

        document.getElementById('deliveryChatOrderNumber').textContent = orderNumber;
        document.getElementById('deliveryChatCustomerName').textContent = customerName;
        document.getElementById('deliveryChatMessages').innerHTML = '<div class="delivery-chat-empty">Loading messages...</div>';
        document.getElementById('deliveryChatInput').value = '';

        deliveryChatModal.show();
        loadDeliveryChat(true);

        clearInterval(activeChatPollTimer);
        activeChatPollTimer = setInterval(() => loadDeliveryChat(false), 5000);
    }

    async function loadDeliveryChat(initialLoad) {
        if (!activeChatOrderId || activeChatLoading) {
            return;
        }

        activeChatLoading = true;
        try {
            const limit = initialLoad ? 100 : 50;
            const response = await fetch(`../api/delivery_chat.php?order_id=${activeChatOrderId}&after_id=${activeChatLastMessageId}&limit=${limit}`);
            const data = await response.json();

            if (!data.success) {
                setDeliveryChatAvailability(false, data.message || 'Unable to load delivery chat.');
                return;
            }

            setDeliveryChatAvailability(!!data.chat_available);

            if (initialLoad) {
                document.getElementById('deliveryChatMessages').innerHTML = '';
            }

            const messages = Array.isArray(data.messages) ? data.messages : [];
            if (messages.length === 0 && activeChatLastMessageId === 0) {
                const container = document.getElementById('deliveryChatMessages');
                if (!container.children.length) {
                    container.innerHTML = '<div class="delivery-chat-empty">No messages yet.</div>';
                }
            }

            messages.forEach((message) => {
                appendDeliveryChatMessage(message);
                activeChatLastMessageId = Math.max(activeChatLastMessageId, Number(message.id || 0));
            });
        } catch (error) {
            console.error('Delivery chat load error:', error);
        } finally {
            activeChatLoading = false;
        }
    }

    async function sendDeliveryChatMessage(event) {
        event.preventDefault();

        if (!activeChatOrderId || !activeChatAvailable) {
            return;
        }

        const input = document.getElementById('deliveryChatInput');
        const sendBtn = document.getElementById('deliveryChatSendBtn');
        const messageText = (input.value || '').trim();

        if (!messageText) {
            return;
        }

        sendBtn.disabled = true;
        try {
            const response = await fetch('../api/delivery_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_id: activeChatOrderId,
                    message: messageText
                })
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to send message.');
            }

            input.value = '';
            if (data.message) {
                appendDeliveryChatMessage(data.message, true);
                activeChatLastMessageId = Math.max(activeChatLastMessageId, Number(data.message.id || 0));
            }
        } catch (error) {
            console.error('Delivery chat send error:', error);
            Swal.fire('Error', error.message || 'Failed to send message.', 'error');
        } finally {
            sendBtn.disabled = false;
        }
    }

    function setDeliveryChatAvailability(isAvailable, message = '') {
        activeChatAvailable = isAvailable;

        const unavailable = document.getElementById('deliveryChatUnavailable');
        const input = document.getElementById('deliveryChatInput');
        const sendBtn = document.getElementById('deliveryChatSendBtn');

        if (!isAvailable) {
            unavailable.style.display = 'block';
            unavailable.textContent = message || 'Chat is unavailable until the customer account is linked and the order is active.';
            input.disabled = true;
            sendBtn.disabled = true;
            input.placeholder = 'Chat unavailable';
            return;
        }

        unavailable.style.display = 'none';
        input.disabled = false;
        sendBtn.disabled = false;
        input.placeholder = 'Type message to customer...';
    }

    function appendDeliveryChatMessage(message, forceScroll = false) {
        const container = document.getElementById('deliveryChatMessages');
        const nearBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 70;
        const emptyEl = container.querySelector('.delivery-chat-empty');
        if (emptyEl) {
            emptyEl.remove();
        }

        const role = message.sender_role === 'driver' ? 'driver' : 'customer';
        const wrapper = document.createElement('div');
        wrapper.className = `delivery-chat-message ${role}`;

        const bubble = document.createElement('div');
        bubble.className = 'delivery-chat-bubble';
        bubble.innerHTML = escapeHtml(String(message.message_text || '')).replace(/\n/g, '<br>');

        const time = document.createElement('div');
        time.className = 'delivery-chat-time';
        time.textContent = formatDeliveryChatTime(message.created_at || '');

        wrapper.appendChild(bubble);
        wrapper.appendChild(time);
        container.appendChild(wrapper);

        if (forceScroll || nearBottom) {
            container.scrollTop = container.scrollHeight;
        }
    }

    function formatDeliveryChatTime(rawDate) {
        if (!rawDate) {
            return '';
        }
        const parsed = new Date(rawDate.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return rawDate;
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

    function updateStatus(trackingId, newStatus, notes = '') {
        Swal.fire({
            title: 'Update Status?',
            text: `Set status to "${newStatus.replace(/_/g, ' ')}"?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, update!',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }

            const performUpdate = (latitude = null, longitude = null) => {
                const formData = new FormData();
                formData.append('tracking_id', trackingId);
                formData.append('status', newStatus);
                formData.append('notes', notes);

                if (latitude !== null && longitude !== null) {
                    formData.append('latitude', latitude);
                    formData.append('longitude', longitude);
                }

                fetch('ajax_update_delivery.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        Swal.fire('Error', data.message || 'Failed to update status', 'error');
                        return;
                    }

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Status updated!',
                        showConfirmButton: false,
                        timer: 2000
                    });

                    const cardContainer = document.getElementById('delivery-card-' + trackingId);
                    if (!cardContainer) {
                        return;
                    }

                    const card = cardContainer.querySelector('.card');
                    const statusBadge = card ? card.querySelector('.badge') : null;
                    if (statusBadge) {
                        statusBadge.textContent = newStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    }

                    if (card) {
                        card.className = 'card delivery-card mb-4';
                        card.classList.add('status-' + newStatus);
                    }

                    const actionsDiv = card ? card.querySelector('.delivery-actions') : null;
                    if (!actionsDiv) {
                        return;
                    }

                    const onTheWayBtn = actionsDiv.querySelector('button[onclick*=\"on_the_way\"]');
                    const arrivingBtn = actionsDiv.querySelector('button[onclick*=\"arriving\"]');
                    const deliveredBtn = actionsDiv.querySelector('button[data-bs-target=\"#proofOfDeliveryModal\"]');

                    if (onTheWayBtn) {
                        onTheWayBtn.disabled = !['assigned', 'picked_up'].includes(newStatus);
                    }
                    if (arrivingBtn) {
                        arrivingBtn.disabled = newStatus !== 'on_the_way';
                    }
                    if (deliveredBtn) {
                        deliveredBtn.disabled = !['on_the_way', 'arriving'].includes(newStatus);
                    }
                })
                .catch(() => Swal.fire('Error', 'An unexpected error occurred.', 'error'));
            };

            if ((newStatus === 'on_the_way' || newStatus === 'arriving') && window.geoTracker && typeof window.geoTracker.getCurrentLocation === 'function') {
                window.geoTracker.getCurrentLocation()
                    .then((position) => {
                        performUpdate(position.coords.latitude, position.coords.longitude);
                    })
                    .catch(() => performUpdate());
                return;
            }

            performUpdate();
        });
    }

    function markFailed(trackingId) {
        Swal.fire({
            title: 'Delivery Failed',
            input: 'textarea',
            inputLabel: 'Reason for failure',
            inputPlaceholder: 'e.g., Customer not available, wrong address...',
            inputAttributes: { autocapitalize: 'off' },
            showCancelButton: true,
            confirmButtonText: 'Confirm Failure',
            confirmButtonColor: '#d33',
            preConfirm: (reason) => {
                if (!reason) {
                    Swal.showValidationMessage('A reason is required to mark a delivery as failed.');
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                updateStatus(trackingId, 'failed', result.value);
            }
        });
    }
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo rawurlencode($google_maps_api_key); ?>&libraries=geometry,places&loading=async&callback=initDriverNavigationMap" async defer></script>

<?php require_once 'footer.php'; ?>
