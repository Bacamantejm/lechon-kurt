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
        <div class="rider-card p-4">
            <div style="font-size: 48px; color: #98a2b3; margin-bottom: 12px;">
                <i class="fas fa-motorcycle"></i>
            </div>
            <h5 class="fw-bold mb-1">No Active Delivery in Progress</h5>
            <p class="text-muted small mb-4">You do not have any orders assigned to you right now. Go to Home to receive new requests.</p>
            <a href="index.php" class="btn-rider-primary">
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

// Coordinates
$store_lat = (float)($active_delivery['store_latitude'] ?? 14.3294);
$store_lng = (float)($active_delivery['store_longitude'] ?? 120.9367);
$cust_lat = !empty($active_delivery['customer_latitude']) ? (float)$active_delivery['customer_latitude'] : null;
$cust_lng = !empty($active_delivery['customer_longitude']) ? (float)$active_delivery['customer_longitude'] : null;

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
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <!-- Active Mission Header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:11px; font-weight:700;">
                <i class="fas fa-bolt me-1"></i> ACTIVE MISSION
            </span>
            <h5 class="mb-0 fw-bold mt-1" style="color: var(--primary-ink); font-size: 1.2rem;">
                Order #<?php echo htmlspecialchars($order_number); ?>
            </h5>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex align-items-center gap-1" style="border-radius: 10px; font-size: 12px; font-weight: 600;">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openExceptionModal()" style="border-radius: 10px; font-size: 12px; font-weight: 600;">
                <i class="fas fa-exclamation-triangle me-1"></i> Report Issue
            </button>
        </div>
    </div>

    <!-- 8-Step Visual Progress Tracker (Section 5) -->
    <div class="rider-card p-3 mb-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small fw-bold" style="font-size: 11px; text-transform: uppercase;">Delivery Progress Tracker</span>
            <span class="badge" style="background:#ecfdf3; color:#027a48; font-size: 11px; font-weight: 700;" id="currentStepLabel">
                Step <?php echo ($step_index + 1); ?> of 8
            </span>
        </div>

        <!-- Progress Steps Bar -->
        <div class="progress mb-3" style="height: 6px; border-radius: 4px; background: #eaecf0;">
            <div class="progress-bar" id="stepProgressBar" role="progressbar" style="width: <?php echo (($step_index + 1) / 8 * 100); ?>%; background: #b3261e;"></div>
        </div>

        <div class="d-flex justify-content-between text-center" style="font-size: 10.5px; color: var(--muted-ink);">
            <div class="<?php echo $step_index >= 0 ? 'fw-bold text-danger' : ''; ?>">Accept</div>
            <div class="<?php echo $step_index >= 1 ? 'fw-bold text-danger' : ''; ?>">To Store</div>
            <div class="<?php echo $step_index >= 2 ? 'fw-bold text-danger' : ''; ?>">At Store</div>
            <div class="<?php echo $step_index >= 3 ? 'fw-bold text-danger' : ''; ?>">Pickup</div>
            <div class="<?php echo $step_index >= 4 ? 'fw-bold text-danger' : ''; ?>">To Cust</div>
            <div class="<?php echo $step_index >= 5 ? 'fw-bold text-danger' : ''; ?>">At Cust</div>
            <div class="<?php echo $step_index >= 6 ? 'fw-bold text-danger' : ''; ?>">Pay/PIN</div>
            <div class="<?php echo $step_index >= 7 ? 'fw-bold text-success' : ''; ?>">Done</div>
        </div>
    </div>

    <!-- Responsive Two-Column Layout for Desktop & Mobile -->
    <div class="row g-3 g-lg-4">
        <!-- Left Column: Workflow Step Cards (Pickup, Drop-off, COD, PIN) -->
        <div class="col-12 col-lg-6">
            <!-- STAGE 1: RESTAURANT PICKUP WORKFLOW (Section 7) -->
            <div id="pickupSection" class="rider-card mb-3" style="<?php echo $step_index >= 3 ? 'display:none;' : ''; ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:11px;">
                            <i class="fas fa-store me-1"></i> PICKUP RESTAURANT
                        </span>
                        <h5 class="fw-bold mb-0 mt-1" style="font-size: 1.1rem;">
                            <?php echo htmlspecialchars($active_delivery['store_name'] ?? 'Fulfillment Restaurant'); ?>
                        </h5>
                        <div class="small text-muted"><?php echo htmlspecialchars($active_delivery['store_address'] ?? 'Branch Address'); ?></div>
                    </div>
                </div>

                <!-- Pickup Action Buttons -->
                <?php if ($step_index < 2): ?>
                    <button type="button" class="btn-rider-primary mb-2 py-3 fw-bold" onclick="confirmArrivalAtRestaurant()">
                        <i class="fas fa-map-pin me-1"></i> Arrived at Restaurant / Store
                    </button>
                <?php else: ?>
                    <div class="p-3 rounded mb-3" style="background:#fff8f8; border:1px solid #fee4e2; text-align:center;">
                        <div class="spinner-border text-danger mb-2" role="status" style="width: 2.2rem; height: 2.2rem;">
                            <span class="visually-hidden">Waiting...</span>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Waiting for Store Owner Approval</h6>
                        <div class="small text-muted mb-2">
                            The restaurant staff has been alerted that you arrived. Show <strong>Order #<?php echo htmlspecialchars($order_number); ?></strong> to the staff to release the food.
                        </div>
                        <div class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:12px; padding: 6px 12px;">
                            <i class="fas fa-clock me-1"></i> Awaiting Store Handover Approval
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark mb-1">Backup: Manual Code Verification</label>
                        <div class="input-group mb-1">
                            <span class="input-group-text"><i class="fas fa-qrcode"></i></span>
                            <input type="text" id="pickupVerifyInput" class="form-control" placeholder="Enter Order # or PIN" value="<?php echo htmlspecialchars($order_number); ?>">
                            <button type="button" class="btn btn-outline-success fw-bold" onclick="confirmPickupFromRestaurant()">
                                Confirm
                            </button>
                        </div>
                        <div class="form-text" style="font-size: 11px;">If store staff is unable to access their portal, verify with the Order # or PIN here.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- STAGE 2: CUSTOMER DELIVERY WORKFLOW (Section 8) -->
            <div id="dropoffSection" class="rider-card mb-3" style="<?php echo $step_index < 3 ? 'display:none;' : ''; ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:11px;">
                            <i class="fas fa-map-marker-alt me-1"></i> CUSTOMER DROP-OFF
                        </span>
                        <h5 class="fw-bold mb-0 mt-1" style="font-size: 1.1rem;">
                            <?php echo htmlspecialchars($active_delivery['customer_name'] ?? 'Customer'); ?>
                        </h5>
                        <div class="small text-muted"><?php echo htmlspecialchars($active_delivery['delivery_address'] ?? 'Delivery Address'); ?></div>
                    </div>
                </div>

                <?php if (!empty($active_delivery['delivery_instructions'])): ?>
                    <div class="p-2 px-3 rounded mb-3" style="background:#fffaeb; border: 1px solid #fedf89; font-size: 12px; color: #b54708;">
                        <i class="fas fa-info-circle me-1"></i> <strong>Note:</strong> <?php echo htmlspecialchars($active_delivery['delivery_instructions']); ?>
                    </div>
                <?php endif; ?>

                <!-- Contact Customer Action Buttons (Section 8) -->
                <div class="d-flex gap-2 mb-3">
                    <?php if (!empty($active_delivery['customer_phone'])): ?>
                        <a href="tel:<?php echo htmlspecialchars($active_delivery['customer_phone']); ?>" class="btn btn-outline-success flex-fill py-2 fw-bold" style="border-radius: 10px; font-size: 12.5px;">
                            <i class="fas fa-phone-alt me-1"></i> Call Customer
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary flex-fill py-2 fw-bold" onclick="openRiderCustomerChat(<?php echo $order_id; ?>, '<?php echo htmlspecialchars(addslashes($order_number)); ?>', '<?php echo htmlspecialchars(addslashes($active_delivery['customer_name'] ?? 'Customer')); ?>')" style="border-radius: 10px; font-size: 12.5px;">
                        <i class="fas fa-comments me-1"></i> Chat
                    </button>
                    <?php if ($step_index < 5): ?>
                        <button type="button" class="btn btn-danger flex-fill py-2 fw-bold" onclick="confirmArrivalAtCustomer()" style="background:#b3261e; border-color:#b3261e; border-radius: 10px; font-size: 12.5px;">
                            <i class="fas fa-flag-checkered me-1"></i> Arrived
                        </button>
                    <?php endif; ?>
                </div>

                <!-- COD CASH WORKFLOW (Section 9) -->
                <?php if ($is_cod): ?>
                    <div class="p-3 rounded mb-3" style="background: #fff8f8; border: 1px solid #fee4e2;">
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
                <?php else: ?>
                    <!-- ONLINE PAYMENT WORKFLOW (Section 10) -->
                    <div class="p-3 rounded mb-3" style="background: #ecfdf3; border: 1px solid #abefc6;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold" style="color: #027a48; font-size: 13px;">
                                <i class="fas fa-check-circle me-1"></i> PAYMENT STATUS: PAID ONLINE
                            </span>
                            <span class="fw-bold text-dark">₱<?php echo number_format($order_total, 2); ?></span>
                        </div>
                        <div class="small" style="color: #027a48;">
                            Payment has already been completed online. <strong>Do not collect cash from the customer.</strong>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- PROOF OF DELIVERY (Section 11) -->
                <div class="p-3 rounded mb-3" style="background: #ffffff; border: 1px solid var(--border-neutral);">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark d-flex justify-content-between align-items-center mb-1">
                            <span><i class="fas fa-camera text-danger me-1"></i> Delivery Proof Photo <span class="text-danger">*</span></span>
                            <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:10px;">Required</span>
                        </label>
                        <input type="file" id="deliveryProofImageInput" class="form-control" accept="image/*" capture="environment" onchange="previewProofImage(this)">
                        <div class="form-text" style="font-size: 11px;">Snap a clear picture of the food received by the customer or placed at the door.</div>
                        <div id="proofImagePreviewContainer" class="mt-2 text-center" style="display: none;">
                            <img id="proofImagePreview" src="" alt="Proof Preview" style="max-height: 180px; width: 100%; border-radius: 10px; border: 1px solid #eaecf0; object-fit: cover;">
                        </div>
                    </div>

                    <button type="button" class="btn-rider-success py-3" onclick="confirmFinalDelivery()">
                        <i class="fas fa-camera me-1"></i> Submit Photo & Complete Delivery
                    </button>
                </div>
            </div>
        </div>

        <!-- Right Column: Navigation Route Map & Order Items Detail -->
        <div class="col-12 col-lg-6">
            <!-- Navigation Map & External Launch (Section 6) -->
            <div class="rider-card p-0 mb-3" style="overflow: hidden;">
                <div class="p-2 px-3 d-flex align-items-center justify-content-between border-bottom" style="background: #ffffff;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-directions text-danger"></i>
                        <span class="fw-bold" style="font-size: 12.5px;" id="navTargetLabel">Navigating Route</span>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="#" id="googleMapsBtn" target="_blank" class="btn btn-sm btn-dark p-1 px-2" style="font-size: 11px; border-radius: 8px;">
                            <i class="fas fa-external-link-alt me-1"></i> Google Maps
                        </a>
                        <a href="#" id="wazeBtn" target="_blank" class="btn btn-sm btn-outline-secondary p-1 px-2" style="font-size: 11px; border-radius: 8px;">
                            Waze
                        </a>
                    </div>
                </div>
                <div id="activeDeliveryMap" style="height: 320px; width: 100%;"></div>
            </div>

            <!-- Items Summary Card -->
            <div class="rider-card mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold text-dark small"><i class="fas fa-utensils text-danger me-1"></i> Order Items Manifest (<?php echo count($order_items); ?>)</span>
                    <span class="badge bg-secondary"><?php echo $order_items ? 'Ready' : 'In Prep'; ?></span>
                </div>
                <div class="p-2 px-3 rounded" style="background: #f8f9fa; border: 1px solid var(--border-neutral);">
                    <ul class="list-unstyled mb-0" style="font-size: 12.5px;">
                        <?php foreach ($order_items as $item): ?>
                            <li class="d-flex justify-content-between py-2 border-bottom border-light">
                                <div>
                                    <strong><?php echo (int)$item['quantity']; ?>x</strong> <?php echo htmlspecialchars($item['product_name'] ?? 'Item'); ?>
                                </div>
                                <span class="fw-semibold text-dark">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="d-flex justify-content-between pt-2 mt-1 border-top fw-bold" style="font-size: 13px;">
                        <span>Total Amount:</span>
                        <span class="text-danger">₱<?php echo number_format($order_total, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- DELIVERY COMPLETED MODAL (Section 12)                          -->
<!-- ============================================================== -->
<div class="modal fade" id="deliveryCompletedModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered px-3">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
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
                    <div class="d-flex justify-content-between py-1 small">
                        <span class="text-muted">Customer Tip:</span>
                        <strong id="compTip">₱0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top mt-2">
                        <span class="fw-bold text-dark">Total Rider Earnings:</span>
                        <strong style="color:#027a48; font-size:1.25rem;" id="compTotalEarnings">₱<?php echo number_format($delivery_fee + 15.00, 2); ?></strong>
                    </div>
                </div>

                <div class="text-center text-muted small mb-3">
                    Payment: <strong><?php echo $is_cod ? 'COD Cash' : 'Paid Online'; ?></strong> &bull; Completed at: <span id="compTimestamp"><?php echo date('h:i A'); ?></span>
                </div>

                <a href="index.php" class="btn-rider-primary py-3">
                    <i class="fas fa-check-circle me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- REPORT ISSUE / EXCEPTION MODAL (Section 18 & 20)               -->
<!-- ============================================================== -->
<div class="modal fade" id="exceptionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered px-3">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-3">
                <h6 class="modal-title fw-bold text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Report Order Exception</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark">Issue Category</label>
                    <select id="issueCategory" class="form-select" onchange="toggleWaitTimerInput()">
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

                <div class="mb-3" id="waitTimerGroup">
                    <label class="form-label small fw-bold text-dark">Minutes Waited</label>
                    <input type="number" id="waitMinsInput" class="form-control" value="10" min="1" max="120">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark">Describe Issue</label>
                    <textarea id="issueDescription" class="form-control" rows="3" placeholder="Explain details so support can assist or reassign..."></textarea>
                </div>

                <button type="button" class="btn-rider-primary" onclick="submitSupportIssue()">
                    <i class="fas fa-paper-plane me-1"></i> Submit Ticket to Dispatch
                </button>
            </div>
        </div>
    </div>
</div>

<!-- RIDER CHAT MODAL -->
<div class="modal fade" id="riderChatModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered px-3">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-2">
                <div>
                    <h6 class="modal-title fw-bold mb-0" id="chatCustomerName">Customer Chat</h6>
                    <small class="text-muted" id="chatOrderSubtitle">Order #<?php echo htmlspecialchars($order_number); ?></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2" style="height: 320px; overflow-y: auto; background: #f8f9fa;" id="chatMessagesBox">
                <div class="text-center text-muted small py-4">Loading messages...</div>
            </div>
            <div class="modal-footer p-2 border-top">
                <div class="input-group">
                    <input type="text" id="chatMessageInput" class="form-control" placeholder="Type message to customer..." onkeydown="if(event.key==='Enter') sendRiderChatMessage()">
                    <button class="btn btn-danger" type="button" onclick="sendRiderChatMessage()">
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
const custLat = <?php echo json_encode($cust_lat); ?>;
const custLng = <?php echo json_encode($cust_lng); ?>;

let riderMarker = null;
let targetMarker = null;
let routeLine = null;
let routeCasingLine = null;
let lastRiderRouteFetch = 0;
let lastRiderRouteCoords = '';

function initActiveMap() {
    if (typeof L === 'undefined') return;
    const container = document.getElementById('activeDeliveryMap');
    if (!container) return;

    activeDeliveryMap = L.map('activeDeliveryMap', {
        center: [currentRiderLat, currentRiderLng],
        zoom: 14,
        zoomControl: false
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(activeDeliveryMap);

    const riderIcon = L.divIcon({
        className: 'custom-pin-rider',
        html: `<div class="rider-custom-pin pin-rider"><i class="fas fa-motorcycle"></i></div>`,
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    });

    riderMarker = L.marker([currentRiderLat, currentRiderLng], { 
        icon: riderIcon,
        draggable: true
    }).addTo(activeDeliveryMap);

    riderMarker.on('dragend', (e) => {
        const pos = e.target.getLatLng();
        handleManualRiderLocationChange(pos.lat, pos.lng);
    });

    activeDeliveryMap.on('click', (e) => {
        handleManualRiderLocationChange(e.latlng.lat, e.latlng.lng);
    });

    updateMapNavigationTarget();
}

function ensureRiderRouteLayers() {
    if (!activeDeliveryMap) return;
    if (!routeCasingLine) {
        routeCasingLine = L.polyline([], {
            color: '#ffffff',
            weight: 8,
            opacity: 0.95,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(activeDeliveryMap);
    }
    if (!routeLine) {
        routeLine = L.polyline([], {
            color: '#b3261e',
            weight: 5,
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

    // Direct fallback straight line in case API is delayed
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
                    activeDeliveryMap.fitBounds(L.latLngBounds(data.coordinates).pad(0.2), { padding: [30, 30] });
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
                    activeDeliveryMap.fitBounds(L.latLngBounds(latLngs).pad(0.2), { padding: [30, 30] });
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

    let destLat = (stepIndex < 3) ? storeLat : (custLat || storeLat);
    let destLng = (stepIndex < 3) ? storeLng : (custLng || storeLng);

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

function updateMapNavigationTarget() {
    if (!activeDeliveryMap) return;

    // If step < 3, target is store. If step >= 3, target is customer.
    let destLat = (stepIndex < 3) ? storeLat : (custLat || storeLat);
    let destLng = (stepIndex < 3) ? storeLng : (custLng || storeLng);
    const targetLabel = (stepIndex < 3) ? 'Navigating to Restaurant' : 'Navigating to Customer Drop-off';

    const lbl = document.getElementById('navTargetLabel');
    if (lbl) lbl.textContent = targetLabel;

    // External App Links
    const gmapsBtn = document.getElementById('googleMapsBtn');
    if (gmapsBtn) {
        gmapsBtn.href = `https://www.google.com/maps/dir/?api=1&origin=${currentRiderLat},${currentRiderLng}&destination=${destLat},${destLng}`;
    }
    const wazeBtn = document.getElementById('wazeBtn');
    if (wazeBtn) {
        wazeBtn.href = `https://waze.com/ul?ll=${destLat},${destLng}&navigate=yes`;
    }

    if (targetMarker) activeDeliveryMap.removeLayer(targetMarker);

    const destIcon = L.divIcon({
        className: 'custom-pin-dest',
        html: `<div class="rider-custom-pin ${stepIndex < 3 ? 'pin-store' : 'pin-customer'}"><i class="fas ${stepIndex < 3 ? 'fa-store' : 'fa-flag-checkered'}"></i></div>`,
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    });

    targetMarker = L.marker([destLat, destLng], { icon: destIcon }).addTo(activeDeliveryMap);

    fetchRiderStreetRoute(currentRiderLat, currentRiderLng, destLat, destLng, true);
}

function updateProgressUI(newStep) {
    stepIndex = newStep;
    const pct = ((stepIndex + 1) / 8 * 100);
    const bar = document.getElementById('stepProgressBar');
    if (bar) bar.style.width = pct + '%';
    const lbl = document.getElementById('currentStepLabel');
    if (lbl) lbl.textContent = `Step ${stepIndex + 1} of 8`;

    updateMapNavigationTarget();

    if (stepIndex >= 3) {
        document.getElementById('pickupSection').style.display = 'none';
        document.getElementById('dropoffSection').style.display = 'block';
    }
}

// RESTAURANT WORKFLOW ACTIONS
function confirmArrivalAtRestaurant() {
    advanceStep('arrived_at_restaurant', () => {
        updateProgressUI(2);
        Swal.fire({
            icon: 'info',
            title: 'Arrived at Restaurant',
            text: 'Please show Order #' + '<?php echo $order_number; ?>' + ' to the restaurant staff to collect the order.',
            confirmButtonColor: '#b3261e'
        });
        setTimeout(() => location.reload(), 1500);
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
                Swal.fire('Pickup Confirmed', 'Food collected! Proceeding to customer drop-off.', 'success');
                updateProgressUI(3);
                setTimeout(() => location.reload(), 1200);
            } else {
                Swal.fire('Verification Failed', data.message, 'warning');
            }
        });
}

// CUSTOMER WORKFLOW ACTIONS
function confirmArrivalAtCustomer() {
    advanceStep('arrived_at_customer', () => {
        updateProgressUI(5);
        Swal.fire('Arrived at Customer', 'Customer notified. Please collect payment or delivery PIN.', 'info');
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
        text: 'Uploading photo proof of delivery and completing order...',
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
    const wait = document.getElementById('waitMinsInput').value;

    if (!desc) {
        Swal.fire('Description Required', 'Please enter details for dispatch.', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'submit_issue');
    formData.append('order_id', activeOrderId);
    formData.append('category', cat);
    formData.append('description', desc);
    formData.append('waiting_time_minutes', wait);

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
function openRiderCustomerChat(orderId, orderNum, custName) {
    document.getElementById('chatCustomerName').textContent = custName || 'Customer';
    document.getElementById('chatOrderSubtitle').textContent = 'Order #' + orderNum;
    new bootstrap.Modal(document.getElementById('riderChatModal')).show();

    loadChatMessages();
    if (chatPollTimer) clearInterval(chatPollTimer);
    chatPollTimer = setInterval(loadChatMessages, 3000);

    const modalEl = document.getElementById('riderChatModal');
    if (modalEl && !modalEl._hasHideListener) {
        modalEl._hasHideListener = true;
        modalEl.addEventListener('hidden.bs.modal', () => {
            if (chatPollTimer) clearInterval(chatPollTimer);
        });
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
                    box.innerHTML = '<div class="text-center text-muted small py-4">No messages yet. Send a note to the customer!</div>';
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
                            <div class="p-2 px-3 rounded shadow-sm" style="max-width: 80%; font-size: 13px; background: ${m.sender_role === 'driver' ? '#b3261e' : '#ffffff'}; color: ${m.sender_role === 'driver' ? '#ffffff' : '#101828'};">
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
    fetch('../api/delivery_chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: activeOrderId, message: msg })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadChatMessages();
        }
    })
    .catch(e => console.debug('Send chat error:', e));
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
                            text: 'The store owner has approved the order handover. You may now proceed with customer delivery!',
                            confirmButtonColor: '#b3261e'
                        }).then(() => {
                            updateProgressUI(3);
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
    broadcastRiderLocation((lat, lng) => {
        onRiderLocationChanged(lat, lng);
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
