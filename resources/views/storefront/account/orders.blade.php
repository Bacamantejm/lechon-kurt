@extends('layouts.app')

@section('content')
<?php
global $conn;
if (!isset($conn) || !($conn instanceof \mysqli)) {
    require_once base_path('includes/config.php');
    if (!isset($conn) || !($conn instanceof \mysqli)) {
        $conn = @mysqli_connect(
            defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '127.0.0.1'),
            defined('DB_USER') ? DB_USER : (getenv('DB_USERNAME') ?: 'root'),
            defined('DB_PASS') ? DB_PASS : (getenv('DB_PASSWORD') ?: ''),
            defined('DB_NAME') ? DB_NAME : (getenv('DB_DATABASE') ?: 'lechon_db')
        );
    }
}
require_once base_path('includes/partner_voucher_helper.php');
require_once base_path('includes/partner_order_policy_helper.php');

$user_id = auth()->id() ?? (int)($_SESSION['user_id'] ?? 1);
$current_tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'orders';

// Pagination setup
$orders_per_page = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $orders_per_page;

// Get total number of active orders
$count_query = "SELECT COUNT(*) as total FROM orders WHERE user_id = ? AND (is_archived IS NULL OR is_archived = 0)";
$count_stmt = mysqli_prepare($conn, $count_query);
mysqli_stmt_bind_param($count_stmt, "i", $user_id);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_orders = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_orders / $orders_per_page);
mysqli_stmt_close($count_stmt);

$orders_stats = [
    'total_orders' => (int)$total_orders,
    'active_orders' => 0,
    'fulfilled_orders' => 0,
    'lifetime_spent' => 0.00
];

if ($total_orders > 0) {
    $stats_query = "
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('pending', 'confirmed', 'preparing') THEN 1 ELSE 0 END), 0) AS active_orders,
            COALESCE(SUM(CASE WHEN status IN ('delivered', 'completed') THEN 1 ELSE 0 END), 0) AS fulfilled_orders,
            COALESCE(SUM(CASE WHEN status IN ('delivered', 'completed') THEN total_amount ELSE 0 END), 0) AS lifetime_spent
        FROM orders
        WHERE user_id = ?
          AND (is_archived IS NULL OR is_archived = 0)
    ";

    $stats_stmt = mysqli_prepare($conn, $stats_query);
    if ($stats_stmt) {
        mysqli_stmt_bind_param($stats_stmt, "i", $user_id);
        mysqli_stmt_execute($stats_stmt);
        $stats_result = mysqli_stmt_get_result($stats_stmt);
        if ($stats_row = mysqli_fetch_assoc($stats_result)) {
            $orders_stats['active_orders'] = (int)$stats_row['active_orders'];
            $orders_stats['fulfilled_orders'] = (int)$stats_row['fulfilled_orders'];
            $orders_stats['lifetime_spent'] = (float)$stats_row['lifetime_spent'];
        }
        mysqli_stmt_close($stats_stmt);
    }
}

// Check which orders have unreviewed items to determine if the "Leave a Review" button should be shown
$orders_with_unreviewed_items = []; // Initialize the variable
if ($total_orders > 0) {
    // Get all order IDs for the current user that are delivered or completed and not archived
    $eligible_order_ids_query = "SELECT id FROM orders WHERE user_id = ? AND status IN ('delivered', 'completed') AND (is_archived IS NULL OR is_archived = 0)";
    $stmt_eligible_ids = mysqli_prepare($conn, $eligible_order_ids_query);
    mysqli_stmt_bind_param($stmt_eligible_ids, "i", $user_id);
    mysqli_stmt_execute($stmt_eligible_ids);
    $eligible_order_ids_result = mysqli_stmt_get_result($stmt_eligible_ids);
    $eligible_order_ids = array_column(mysqli_fetch_all($eligible_order_ids_result, MYSQLI_ASSOC), 'id');
    mysqli_stmt_close($stmt_eligible_ids);

    if (!empty($eligible_order_ids)) {
        $placeholders = implode(',', array_fill(0, count($eligible_order_ids), '?'));
        $types = str_repeat('i', count($eligible_order_ids));

        // Find order IDs that have at least one unreviewed item
        $unreviewed_items_query = "SELECT DISTINCT order_id FROM order_items WHERE order_id IN ($placeholders) AND is_reviewed = 0";
        $stmt_unreviewed = mysqli_prepare($conn, $unreviewed_items_query);
        mysqli_stmt_bind_param($stmt_unreviewed, $types, ...$eligible_order_ids);
        mysqli_stmt_execute($stmt_unreviewed);
        $unreviewed_items_result = mysqli_stmt_get_result($stmt_unreviewed);
        $orders_with_unreviewed_items = array_column(mysqli_fetch_all($unreviewed_items_result, MYSQLI_ASSOC), 'order_id');
        mysqli_stmt_close($stmt_unreviewed);
    }
}

// Get active orders with order items
$query = "
    SELECT 
        o.id,
        o.order_number,
        o.customer_name,
        o.customer_email,
        o.customer_phone,
        o.delivery_address,
        o.delivery_date,
        o.delivery_time,
        o.payment_method,
        o.subtotal,
        o.delivery_fee,
        o.voucher_code,
        o.voucher_discount,
        o.total_amount,
        o.status,
        o.special_instructions,
        o.created_at,
        o.updated_at,
        o.delivery_option,
        o.pickup_location,
        o.delivery_location,
        o.is_archived,
        oi.product_name,
        oi.price,
        oi.quantity,
        oi.size,
        oi.addons,
        oi.total as item_total,
        p.image as product_image
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.user_id = ? AND (o.is_archived IS NULL OR o.is_archived = 0)
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "iii", $user_id, $orders_per_page, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Group orders by order_id
$orders = [];
while ($row = mysqli_fetch_assoc($result)) {
    $order_id = $row['id'];
    
    if (!isset($orders[$order_id])) {
        $orders[$order_id] = [
            'id' => $row['id'],
            'order_number' => $row['order_number'],
            'customer_name' => $row['customer_name'],
            'customer_email' => $row['customer_email'],
            'customer_phone' => $row['customer_phone'],
            'delivery_address' => $row['delivery_address'],
            'delivery_date' => $row['delivery_date'],
            'delivery_time' => $row['delivery_time'],
            'payment_method' => $row['payment_method'],
            'subtotal' => $row['subtotal'],
            'delivery_fee' => $row['delivery_fee'],
            'voucher_code' => $row['voucher_code'] ?? '',
            'voucher_discount' => $row['voucher_discount'] ?? 0,
            'total_amount' => $row['total_amount'],
            'status' => $row['status'],
            'special_instructions' => $row['special_instructions'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'delivery_option' => $row['delivery_option'],
            'pickup_location' => $row['pickup_location'],
            'delivery_location' => $row['delivery_location'],
            'is_archived' => $row['is_archived'],
            'items' => []
        ];
    }
    
    if ($row['product_name']) {
        $orders[$order_id]['items'][] = [
            'product_name' => $row['product_name'],
            'price' => $row['price'],
            'quantity' => $row['quantity'],
            'size' => $row['size'],
            'addons' => $row['addons'],
            'item_total' => $row['item_total'],
            'product_image' => $row['product_image'] ?? ''
        ];
    }
}
mysqli_stmt_close($stmt);

foreach ($orders as &$order) {
    $policy = popGetOrderPolicy($conn, (int)$order['id']);
    $policy_check = popEvaluateOrderCancellation($policy, (string)($order['status'] ?? ''));
    $order['partner_policy'] = $policy;
    $order['can_customer_cancel'] = !empty($policy_check['allowed']);
    $order['cancellation_policy_message'] = (string)($policy_check['message'] ?? '');
    $order['refund_terms'] = (string)($policy['refund_terms'] ?? '');
    $order['refund_photo_required'] = !empty($policy['require_refund_photo_for_damage']);
}
unset($order);
?>

<section class="orders-page-container">
    <div class="container">
        <!-- Page Header -->
        <div class="orders-header">
            <div class="header-text">
                <h1>My Orders</h1>
                <p>Manage your orders, track active deliveries, and view purchase receipts</p>
            </div>
        </div>

        <!-- Flat E-Commerce Tab Navigation -->
        <div class="orders-nav-tabs">
            <a href="my_orders.php?tab=orders" class="nav-tab-item <?php echo $current_tab === 'orders' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-bag"></i>
                <span>Regular Orders</span>
                <?php if (!empty($orders_stats['total_orders'])): ?>
                    <span class="tab-count"><?php echo (int)$orders_stats['total_orders']; ?></span>
                <?php endif; ?>
            </a>
            <a href="my_orders.php?tab=preorders" class="nav-tab-item <?php echo $current_tab === 'preorders' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span>Pre-Orders</span>
            </a>
        </div>
        
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- REGULAR ORDERS TAB -->
        <?php if ($current_tab === 'orders'): ?>
        
        <?php if (empty($orders)): ?>
            <div class="empty-orders-state">
                <div class="empty-icon-wrap">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <h3>No orders placed yet</h3>
                <p>When you place orders, they will appear here with real-time status tracking.</p>
                <a href="menu.php" class="btn-browse-menu">
                    Browse Menu
                </a>
            </div>
        <?php else: ?>
            <div class="orders-card-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $status_key = strtolower(trim((string)$order['status']));
                    $progress_map = [
                        'pending' => 1,
                        'confirmed' => 2,
                        'preparing' => 3,
                        'assigned' => 3,
                        'on_the_way' => 3,
                        'arriving' => 3,
                        'delivered' => 4,
                        'completed' => 4,
                        'cancelled' => 0
                    ];
                    $order_progress = $progress_map[$status_key] ?? 1;
                    $delivery_option = strtolower((string)($order['delivery_option'] ?? 'delivery'));
                    
                    $status_display_map = [
                        'pending' => 'Pending Confirmation',
                        'confirmed' => 'Order Confirmed',
                        'preparing' => 'In Preparation',
                        'assigned' => 'In Transit',
                        'on_the_way' => 'In Transit',
                        'arriving' => 'Arriving Soon',
                        'delivered' => 'Delivered',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled'
                    ];
                    $status_display_text = $status_display_map[$status_key] ?? ucfirst($order['status']);
                    $item_count = count($order['items']);
                    ?>
                    <div class="ecom-order-card" data-status="<?php echo htmlspecialchars($status_key); ?>">
                        <!-- Card Top Bar: Shop Name, Order #, Status -->
                        <div class="ecom-card-top">
                            <div class="ecom-shop-info">
                                <i class="fas fa-store shop-icon"></i>
                                <span class="shop-name">Lechon Delights</span>
                                <span class="divider">&bull;</span>
                                <span class="order-number">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                                <span class="divider">&bull;</span>
                                <span class="order-date"><?php echo date('M j, Y', strtotime($order['created_at'])); ?></span>
                                <span class="type-badge type-<?php echo htmlspecialchars($delivery_option); ?>">
                                    <?php echo ucfirst($delivery_option); ?>
                                </span>
                            </div>
                            <div class="ecom-status-label status-<?php echo htmlspecialchars($status_key); ?>">
                                <span class="status-dot"></span>
                                <?php echo htmlspecialchars($status_display_text); ?>
                            </div>
                        </div>

                        <!-- Card Middle: Item List -->
                        <div class="ecom-card-middle">
                            <?php foreach ($order['items'] as $item): ?>
                                <?php
                                $item_img = !empty($item['product_image']) ? $item['product_image'] : 'assets/images/promo_lechon.jpg';
                                if (!file_exists($item_img) && file_exists('uploads/products/' . basename($item_img))) {
                                    $item_img = 'uploads/products/' . basename($item_img);
                                }
                                ?>
                                <div class="ecom-item-row">
                                    <img src="<?php echo htmlspecialchars($item_img); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" class="item-img" onError="this.onerror=null;this.src='assets/images/promo_lechon.jpg';">
                                    <div class="item-details">
                                        <h4 class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></h4>
                                        <div class="item-meta">
                                            <?php if (!empty($item['size'])): ?>
                                                <span class="meta-tag">Size: <?php echo htmlspecialchars($item['size']); ?></span>
                                            <?php endif; ?>
                                            <span class="meta-qty">Qty: <?php echo (int)$item['quantity']; ?></span>
                                        </div>
                                    </div>
                                    <div class="item-price">
                                        â‚±<?php echo number_format($item['item_total'], 2); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Card Bottom: Total Summary & Actions -->
                        <div class="ecom-card-bottom">
                            <div class="summary-line">
                                <span class="payment-method">Paid via <strong><?php echo ucfirst(str_replace('_', ' ', (string)$order['payment_method'])); ?></strong></span>
                                <div class="total-wrap">
                                    <span class="total-label">Order Total (<?php echo $item_count; ?> <?php echo $item_count === 1 ? 'item' : 'items'; ?>):</span>
                                    <span class="total-amount">â‚±<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                            </div>
                            
                            <div class="actions-line">
                                <button type="button" class="btn-toggle-drawer" onclick="toggleOrderDetails(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-chevron-down" id="toggleIcon_<?php echo $order['id']; ?>"></i>
                                    <span id="toggleText_<?php echo $order['id']; ?>">View Order Details</span>
                                </button>

                                <div class="action-buttons-group">
                                    <?php if (in_array(strtolower($order['status']), ['pending', 'confirmed', 'preparing', 'processing'], true) && !empty($order['can_customer_cancel'])): ?>
                                        <button type="button" class="btn-action-outline btn-cancel" onclick="cancelOrder(<?php echo $order['id']; ?>, <?php echo json_encode((string)$order['cancellation_policy_message']); ?>)">
                                            Cancel Order
                                        </button>
                                    <?php endif; ?>

                                    <?php if (in_array(strtolower($order['status']), ['delivered', 'completed'])): ?>
                                        <?php if (in_array($order['id'], $orders_with_unreviewed_items)): ?>
                                            <a href="leave_review.php?order_id=<?php echo $order['id']; ?>" class="btn-action-outline btn-review">
                                                <i class="fas fa-star"></i> Review
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn-action-outline" onclick="requestRefund(<?php echo $order['id']; ?>, <?php echo !empty($order['refund_photo_required']) ? 'true' : 'false'; ?>, <?php echo json_encode((string)$order['refund_terms']); ?>)">
                                            Request Refund
                                        </button>
                                    <?php endif; ?>

                                    <?php if (in_array(strtolower($order['status']), ['delivered', 'completed', 'cancelled'])): ?>
                                        <form method="POST" action="my_orders.php" style="display: inline-block;">
                                            <input type="hidden" name="reorder_items" value="1">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                            <button type="submit" class="btn-action-outline btn-reorder">
                                                Re-order
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <a href="receipt.php?order_id=<?php echo $order['id']; ?>" class="btn-action-outline" target="_blank">
                                        Receipt
                                    </a>

                                    <button type="button" class="btn-action-outline btn-archive" onclick="archiveOrder(<?php echo $order['id']; ?>)" title="Archive Order">
                                        <i class="fas fa-archive"></i>
                                    </button>

                                    <?php if ($delivery_option !== 'pickup'): ?>
                                        <a href="track_order.php?order_id=<?php echo (int)$order['id']; ?>" class="btn-action-primary">
                                            Track Order
                                        </a>
                                    <?php else: ?>
                                        <a href="track_order.php?order_id=<?php echo (int)$order['id']; ?>" class="btn-action-primary btn-pickup">
                                            View Pickup
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Collapsible Details Drawer -->
                        <div class="ecom-details-drawer" id="orderDetails_<?php echo $order['id']; ?>" style="display: none;">
                            <div class="drawer-inner">
                                <?php if ($status_key === 'cancelled'): ?>
                                    <div class="cxl-banner">
                                        <i class="fas fa-info-circle"></i> This order was cancelled.
                                    </div>
                                <?php else: ?>
                                    <!-- Simple Timeline -->
                                    <div class="timeline-stepper">
                                        <div class="step <?php echo $order_progress >= 1 ? 'active' : ''; ?>">
                                            <div class="dot"></div>
                                            <span>Placed</span>
                                        </div>
                                        <div class="line <?php echo $order_progress >= 2 ? 'active' : ''; ?>"></div>
                                        <div class="step <?php echo $order_progress >= 2 ? 'active' : ''; ?>">
                                            <div class="dot"></div>
                                            <span>Confirmed</span>
                                        </div>
                                        <div class="line <?php echo $order_progress >= 3 ? 'active' : ''; ?>"></div>
                                        <div class="step <?php echo $order_progress >= 3 ? 'active' : ''; ?>">
                                            <div class="dot"></div>
                                            <span>Preparing</span>
                                        </div>
                                        <div class="line <?php echo $order_progress >= 4 ? 'active' : ''; ?>"></div>
                                        <div class="step <?php echo $order_progress >= 4 ? 'active' : ''; ?>">
                                            <div class="dot"></div>
                                            <span>Delivered</span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="drawer-grid">
                                    <div class="info-group">
                                        <span class="label">Recipient Name</span>
                                        <span class="val"><?php echo htmlspecialchars($order['customer_name']); ?> (<?php echo htmlspecialchars($order['customer_phone']); ?>)</span>
                                    </div>
                                    <div class="info-group">
                                        <span class="label">Schedule</span>
                                        <span class="val"><?php echo date('F j, Y', strtotime($order['delivery_date'])); ?> @ <?php echo htmlspecialchars($order['delivery_time']); ?></span>
                                    </div>
                                    <div class="info-group">
                                        <span class="label">Address / Location</span>
                                        <span class="val"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                                    </div>
                                    <?php if (!empty($order['special_instructions'])): ?>
                                        <div class="info-group full">
                                            <span class="label">Instructions</span>
                                            <span class="val"><?php echo htmlspecialchars($order['special_instructions']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($order['partner_policy']['cancellation_terms']) || !empty($order['refund_terms'])): ?>
                                    <div class="terms-note">
                                        <?php if (!empty($order['partner_policy']['cancellation_terms'])): ?>
                                            <div><strong>Cancellation Terms:</strong> <?php echo htmlspecialchars((string)$order['partner_policy']['cancellation_terms']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($order['refund_terms'])): ?>
                                            <div><strong>Refund Terms:</strong> <?php echo htmlspecialchars((string)$order['refund_terms']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="ecom-pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="page-num"><i class="fas fa-chevron-left"></i> Prev</a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="page-num <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="page-num">Next <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php endif; ?> <!-- End of Regular Orders Tab -->
        
        <?php if ($current_tab === 'preorders'): ?>
            <?php include 'preorder_tab_content.php'; ?>
        <?php endif; ?> <!-- End of Pre-Orders Tab -->
    </div>
</section>

<!-- SweetAlert2 Assets -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Streamlined Production E-Commerce Orders System */
.orders-page-container {
    padding: 28px 0 140px;
    background: #f8f9fa;
    min-height: 85vh;
}

/* Page Header */
.orders-header {
    margin-bottom: 12px;
}
.header-text h1 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #101828;
    margin: 0 0 2px 0;
    letter-spacing: -0.2px;
}
.header-text p {
    font-size: 0.82rem;
    color: #667085;
    margin: 0;
}

/* Flat E-Commerce Tab Nav */
.orders-nav-tabs {
    display: flex;
    align-items: center;
    gap: 32px;
    border-bottom: 1px solid #eaecf0;
    margin-bottom: 24px;
    background: transparent;
}
.nav-tab-item {
    padding: 12px 0;
    font-size: 0.98rem;
    font-weight: 600;
    color: #667085;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2.5px solid transparent;
    transition: all 0.2s ease;
    margin-bottom: -1px;
}
.nav-tab-item:hover {
    color: #b3261e;
}
.nav-tab-item.active {
    color: #b3261e;
    border-bottom-color: #b3261e;
    font-weight: 700;
}
.tab-count {
    background: #fee4e2;
    color: #b3261e;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 12px;
}

/* Alert Boxes */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 0.92rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Empty State */
.empty-orders-state {
    text-align: center;
    padding: 60px 20px;
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 12px;
    max-width: 480px;
    margin: 40px auto;
}
.empty-icon-wrap {
    font-size: 3rem;
    color: #d0d5dd;
    margin-bottom: 16px;
}
.empty-orders-state h3 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #101828;
    margin: 0 0 6px 0;
}
.empty-orders-state p {
    font-size: 0.92rem;
    color: #667085;
    margin: 0 0 20px 0;
}
.btn-browse-menu {
    background: #b3261e;
    color: #ffffff;
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: background-color 0.2s ease;
}
.btn-browse-menu:hover {
    background: #9c1f18;
}

/* Orders List */
.orders-card-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* Modern E-Commerce Order Card */
.ecom-order-card {
    background: #ffffff;
    border: 1px solid #e4e7ec;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    position: relative;
    z-index: 1;
}

/* Card Top Header */
.ecom-card-top {
    padding: 14px 20px;
    border-bottom: 1px solid #f2f4f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    background: #ffffff;
}
.ecom-shop-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.88rem;
    color: #475467;
    flex-wrap: wrap;
}
.shop-icon { color: #b3261e; font-size: 0.95rem; }
.shop-name { font-weight: 700; color: #101828; }
.divider { color: #d0d5dd; }
.order-number { font-family: monospace; font-weight: 600; color: #344054; }
.order-date { color: #667085; font-size: 0.84rem; }
.type-badge {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    text-transform: capitalize;
}
.type-delivery { background: #eff6ff; color: #1d4ed8; }
.type-pickup { background: #fefce8; color: #854d0e; }

.ecom-status-label {
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.ecom-status-label.status-pending { color: #d97706; }
.ecom-status-label.status-confirmed { color: #2563eb; }
.ecom-status-label.status-preparing, .ecom-status-label.status-assigned, .ecom-status-label.status-on_the_way, .ecom-status-label.status-arriving { color: #ea580c; }
.ecom-status-label.status-delivered, .ecom-status-label.status-completed { color: #16a34a; }
.ecom-status-label.status-cancelled { color: #dc2626; }

/* Card Middle Body */
.ecom-card-middle {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.ecom-item-row {
    display: flex;
    align-items: center;
    gap: 14px;
}
.item-img {
    width: 72px;
    height: 72px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid #f2f4f7;
    flex-shrink: 0;
    background: #f8fafc;
}
.item-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.item-name {
    font-size: 0.98rem;
    font-weight: 700;
    color: #101828;
    margin: 0;
    line-height: 1.3;
}
.item-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    color: #667085;
}
.meta-tag {
    background: #f2f4f7;
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 600;
}
.item-price {
    font-size: 0.95rem;
    font-weight: 700;
    color: #101828;
    text-align: right;
}

/* Card Bottom Footer */
.ecom-card-bottom {
    background: #fafafa;
    border-top: 1px solid #f2f4f7;
    padding: 14px 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.summary-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.payment-method {
    font-size: 0.84rem;
    color: #667085;
}
.total-wrap {
    display: flex;
    align-items: baseline;
    gap: 6px;
}
.total-label {
    font-size: 0.85rem;
    color: #475467;
}
.total-amount {
    font-size: 1.15rem;
    font-weight: 800;
    color: #b3261e;
}

.actions-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding-top: 6px;
}
.btn-toggle-drawer {
    background: transparent;
    border: none;
    color: #667085;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 0;
    transition: color 0.2s ease;
}
.btn-toggle-drawer:hover {
    color: #b3261e;
}

.action-buttons-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.btn-action-outline {
    background: #ffffff;
    border: 1px solid #d0d5dd;
    color: #344054;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    transition: all 0.2s ease;
}
.btn-action-outline:hover {
    background: #f8fafc;
    border-color: #98a2b3;
    color: #101828;
}
.btn-action-outline.btn-cancel {
    color: #b3261e;
    border-color: #fda29b;
}
.btn-action-outline.btn-cancel:hover {
    background: #fef2f2;
}
.btn-action-outline.btn-review {
    color: #d97706;
    border-color: #fde68a;
}
.btn-action-outline.btn-review:hover {
    background: #fffbe8;
}

.btn-action-primary {
    background: #b3261e;
    color: #ffffff !important;
    border: 1px solid #b3261e;
    padding: 8px 18px;
    border-radius: 8px;
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    transition: background-color 0.2s ease;
}
.btn-action-primary:hover {
    background: #9c1f18;
}
.btn-action-primary.btn-pickup {
    background: #171922;
    border-color: #171922;
}
.btn-action-primary.btn-pickup:hover {
    background: #2a211d;
}

/* Collapsible Drawer */
.ecom-details-drawer {
    border-top: 1px dashed #eaecf0;
    background: #ffffff;
}
.drawer-inner {
    padding: 20px;
}
.cxl-banner {
    background: #fef2f2;
    color: #991b1b;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 0.88rem;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Timeline Stepper */
.timeline-stepper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding: 14px 18px;
    background: #f8fafc;
    border: 1px solid #eaecf0;
    border-radius: 8px;
}
.timeline-stepper .step {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #98a2b3;
}
.timeline-stepper .step.active {
    color: #101828;
}
.timeline-stepper .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #d0d5dd;
}
.timeline-stepper .step.active .dot {
    background: #b3261e;
}
.timeline-stepper .line {
    flex: 1;
    height: 2px;
    background: #eaecf0;
    margin: 0 12px;
}
.timeline-stepper .line.active {
    background: #b3261e;
}

.drawer-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}
.info-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.info-group.full {
    grid-column: 1 / -1;
}
.info-group .label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #667085;
}
.info-group .val {
    font-size: 0.9rem;
    font-weight: 600;
    color: #101828;
}

.terms-note {
    background: #fffbeb;
    border: 1px solid #fef08a;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 0.82rem;
    color: #78350f;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* Pagination */
.ecom-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin-top: 28px;
}
.page-num {
    padding: 6px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 6px;
    background: #ffffff;
    color: #344054;
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 600;
}
.page-num:hover, .page-num.active {
    background: #b3261e;
    border-color: #b3261e;
    color: #ffffff;
}

/* SweetAlert Policy Styling */
.swal-policy-note {
    text-align: left;
    background: #fff7ed;
    color: #9a3412;
    border: 1px solid #fed7aa;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 10px;
    font-size: 13px;
}
.swal-proof-hint { text-align: left; font-size: 12px; color: #64748b; margin-top: 4px; }
.swal-proof-hint.required { color: #b91c1c; font-weight: 600; }

@media (max-width: 768px) {
    .ecom-card-top, .ecom-card-bottom, .actions-line {
        flex-direction: column;
        align-items: flex-start;
    }
    .action-buttons-group {
        width: 100%;
        flex-direction: column;
    }
    .btn-action-outline, .btn-action-primary {
        width: 100%;
        justify-content: center;
    }
    .timeline-stepper {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .timeline-stepper .line { display: none; }
}
</style>

<script>
function archiveOrder(orderId) {
    Swal.fire({
        title: 'Archive Order?',
        text: "This order will be moved to archives.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#b3261e',
        cancelButtonColor: '#667085',
        confirmButtonText: 'Yes, archive'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('archive_order', 'true');
            formData.append('order_id', orderId);
            
            fetch('my_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                Swal.fire('Archived!', 'Order moved to archives.', 'success').then(() => {
                    location.reload();
                });
            });
        }
    });
}

function cancelOrder(orderId, policyMessage = '') {
    Swal.fire({
        title: 'Cancel Order?',
        icon: 'warning',
        html: `
            ${policyMessage ? `<div class="swal-policy-note">${escapeHtml(policyMessage)}</div>` : ''}
            <textarea id="cancelReasonInput" class="swal2-textarea" placeholder="Reason for cancellation (optional)"></textarea>
        `,
        showCancelButton: true,
        confirmButtonColor: '#b3261e',
        cancelButtonColor: '#667085',
        confirmButtonText: 'Confirm Cancel',
        preConfirm: () => document.getElementById('cancelReasonInput').value.trim()
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('cancel_order', 'true');
            formData.append('order_id', orderId);
            formData.append('reason', result.value || '');
            formData.append('ajax', 'true');
            
            fetch('my_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Cancelled', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

function cancelPreOrder(preOrderId) {
    Swal.fire({
        title: 'Cancel Pre-Order?',
        text: "Are you sure you want to cancel this pre-order?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#b3261e',
        cancelButtonColor: '#667085',
        confirmButtonText: 'Yes, cancel it'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('cancel_preorder', 'true');
            formData.append('pre_order_id', preOrderId);
            formData.append('ajax', 'true');
            
            fetch('my_orders.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Cancelled', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

function refundReasonNeedsProof(reason) {
    return ['damaged product', 'broken product'].includes(String(reason || '').trim().toLowerCase());
}

function escapeHtml(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function requestRefund(orderId, proofRequired = false, refundTerms = '') {
    Swal.fire({
        title: 'Request Refund',
        icon: 'question',
        html: `
            ${refundTerms ? `<div class="swal-policy-note">${escapeHtml(refundTerms)}</div>` : ''}
            <select id="refundReasonInput" class="swal2-select" style="display:block;width:100%;">
                <option value="">Select refund reason</option>
                <option value="Damaged Product">Damaged Product</option>
                <option value="Broken Product">Broken Product</option>
                <option value="Wrong Item">Wrong Item</option>
                <option value="Missing Item">Missing Item</option>
                <option value="Quality Issue">Quality Issue</option>
                <option value="Other">Other</option>
            </select>
            <textarea id="refundDetailsInput" class="swal2-textarea" placeholder="Provide additional details..."></textarea>
            <input id="refundProofInput" type="file" class="swal2-file" accept="image/png,image/jpeg,image/webp">
            <div id="refundProofHint" class="swal-proof-hint">Attach proof photo if required.</div>
        `,
        showCancelButton: true,
        confirmButtonColor: '#b3261e',
        cancelButtonColor: '#667085',
        confirmButtonText: 'Submit Request',
        preConfirm: () => {
            const refundReason = document.getElementById('refundReasonInput').value;
            const refundDetails = document.getElementById('refundDetailsInput').value.trim();
            const proofFile = document.getElementById('refundProofInput').files[0] || null;

            if (!refundReason) {
                Swal.showValidationMessage('Please choose a refund reason.');
                return false;
            }
            if (proofRequired && refundReasonNeedsProof(refundReason) && !proofFile) {
                Swal.showValidationMessage('Proof photo required for damaged product claims.');
                return false;
            }
            return { refundReason, refundDetails, proofFile };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('request_refund', 'true');
            formData.append('order_id', orderId);
            formData.append('refund_reason', result.value.refundReason);
            formData.append('refund_details', result.value.refundDetails || '');
            if (result.value.proofFile) formData.append('refund_proof', result.value.proofFile);
            formData.append('ajax', 'true');
            
            fetch('my_orders.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Submitted', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

function toggleOrderDetails(orderId) {
    const drawer = document.getElementById('orderDetails_' + orderId);
    const icon = document.getElementById('toggleIcon_' + orderId);
    const text = document.getElementById('toggleText_' + orderId);
    
    if (drawer) {
        if (drawer.style.display === 'none' || drawer.style.display === '') {
            drawer.style.display = 'block';
            if (icon) icon.className = 'fas fa-chevron-up';
            if (text) text.textContent = 'Hide Order Details';
        } else {
            drawer.style.display = 'none';
            if (icon) icon.className = 'fas fa-chevron-down';
            if (text) text.textContent = 'View Order Details';
        }
    }
}
</script>
@endsection