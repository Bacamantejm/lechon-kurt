<?php
/**
 * store/orders.php
 * Store Owner (Merchant) Order Details and Order List View
 * Enforces strict multi-tenant store isolation: order.store_id === auth.current_store_id
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$root_path = dirname(__DIR__);
require_once $root_path . '/includes/config.php';
require_once $root_path . '/includes/security.php';
require_once $root_path . '/includes/CommissionAndWalletService.php';

if (function_exists('ensureOrdersTableSchema')) {
    ensureOrdersTableSchema($conn);
}

// Check user authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$current_role = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$is_super_admin = ($current_role === 'super_admin');

$walletService = CommissionAndWalletService::getInstance($conn);
$current_store_id = $walletService->getStoreIdForUser($current_user_id);

// If super admin and store_id parameter provided in URL, allow context switching
if ($is_super_admin && isset($_GET['store_id'])) {
    $current_store_id = (int)$_GET['store_id'];
}

// Check merchant access authorization
if (!$is_super_admin && ($current_store_id === null || $current_store_id <= 0)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied — Store Portal</title>
        <link rel="stylesheet" href="../css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
            .denied-card { background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 32px 28px; max-width: 480px; width: 100%; text-align: center; box-shadow: 0 4px 20px rgba(16,24,40,0.06); }
            .denied-icon { width: 64px; height: 64px; border-radius: 50%; background: #fff1f0; color: #b3261e; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; margin-bottom: 16px; border: 1px solid #fee4e2; }
            h2 { font-size: 1.35rem; font-weight: 700; color: #101828; margin-bottom: 8px; }
            p { font-size: 0.9rem; color: #475467; line-height: 1.5; margin-bottom: 24px; }
            .btn-home { background: #b3261e; color: #fff; font-weight: 600; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; }
            .btn-home:hover { background: #981b15; color: #fff; }
        </style>
    </head>
    <body>
        <div class="denied-card">
            <div class="denied-icon"><i class="fas fa-store-slash"></i></div>
            <h2>Merchant Account Required</h2>
            <p>Your current user account is not linked to any registered store location. If you are an approved franchise partner, please contact platform administration.</p>
            <a href="../admin/index.php" class="btn-home"><i class="fas fa-arrow-left me-1"></i> Return to Admin Hub</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$store_info = $walletService->getStoreDetails($current_store_id ?: 1);

// Route: Single Order Detail vs Order List
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id > 0) {
    // ==========================================
    // DETAIL VIEW: /store/orders/:id
    // ==========================================

    // Fetch order
    $has_store_id = function_exists('appColumnExists') ? appColumnExists($conn, 'orders', 'store_id') : false;
    $store_join_col = $has_store_id ? "COALESCE(NULLIF(o.store_id, 0), o.pickup_location)" : "o.pickup_location";

    $stmt = mysqli_prepare($conn, "
        SELECT o.*, 
               sl.store_name, sl.address AS store_address, sl.city AS store_city, sl.province AS store_province,
               COALESCE(u_cust.full_name, o.customer_name) AS safe_customer_name
        FROM orders o
        LEFT JOIN store_locations sl ON sl.store_id = {$store_join_col}
        LEFT JOIN users u_cust ON u_cust.id = o.user_id
        WHERE o.id = ? 
        LIMIT 1");
    $order = null;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $order_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $order = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }

    if (!$order) {
        http_response_code(404);
        die("Order #{$order_id} not found.");
    }

    $order_store_id = (int)(($order['store_id'] ?? 0) ?: ($order['pickup_location'] ?? 0));

    // CRITICAL DATA ISOLATION ENFORCEMENT:
    // Verify order.store_id === auth.current_store_id
    if (!$is_super_admin && $order_store_id !== (int)$current_store_id) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 Forbidden — Multi-Tenant Isolation</title>
            <link rel="stylesheet" href="../css/bootstrap.min.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
                .isolation-card { background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 36px 30px; max-width: 500px; width: 100%; text-align: center; box-shadow: 0 4px 20px rgba(16,24,40,0.06); }
                .isolation-icon { width: 68px; height: 68px; border-radius: 50%; background: #fff1f0; color: #b3261e; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 18px; border: 1px solid #fee4e2; }
                h2 { font-size: 1.35rem; font-weight: 700; color: #101828; margin-bottom: 10px; }
                p { font-size: 0.9rem; color: #475467; line-height: 1.5; margin-bottom: 24px; }
                .policy-notice { background: #fff8f8; border: 1px dashed #fca5a5; border-radius: 8px; padding: 12px; font-size: 12px; color: #991b1b; text-align: left; margin-bottom: 24px; }
                .btn-back { background: #b3261e; color: #fff; font-weight: 600; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; }
                .btn-back:hover { background: #981b15; color: #fff; }
            </style>
        </head>
        <body>
            <div class="isolation-card">
                <div class="isolation-icon"><i class="fas fa-user-shield"></i></div>
                <h2>Store Privacy Boundary Enforced</h2>
                <div class="policy-notice">
                    <i class="fas fa-lock me-1"></i> <strong>Critical Data Isolation Rule:</strong><br>
                    Shop owners are restricted exclusively to orders originating from their own registered store. You cannot view order breakdowns, financials, or customer details belonging to other branches.
                </div>
                <p>This order belongs to another merchant branch (Store ID #<?php echo $order_store_id; ?>). You are currently authenticated under <strong><?php echo htmlspecialchars($store_info['store_name'] ?? ('Store #' . $current_store_id)); ?></strong>.</p>
                <a href="orders.php" class="btn-back"><i class="fas fa-arrow-left me-1"></i> Return to My Store Orders</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Get payout split calculation
    $split = $walletService->recordOrUpdateSplit($order_id, false);

    // Fetch order items (scoped to this store)
    $items_stmt = mysqli_prepare($conn, "SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
    mysqli_stmt_bind_param($items_stmt, "i", $order_id);
    mysqli_stmt_execute($items_stmt);
    $items_res = mysqli_stmt_get_result($items_stmt);
    $items = [];
    while ($items_res && ($row = mysqli_fetch_assoc($items_res))) {
        $items[] = $row;
    }
    mysqli_stmt_close($items_stmt);

    // Fetch logistics tracking and rider snapshot
    $rider_info = null;
    $tracking_stmt = mysqli_prepare($conn, "
        SELECT lt.*, 
               r.rider_code, r.vehicle_type, r.vehicle_plate, r.rating AS rider_rating,
               COALESCE(NULLIF(lt.driver_name, ''), u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), 'Assigned Rider') AS rider_display_name,
               COALESCE(NULLIF(lt.driver_phone, ''), u_rdr.phone, e_rdr.phone, '') AS rider_display_phone
        FROM logistics_tracking lt
        LEFT JOIN riders r ON r.id = lt.driver_id
        LEFT JOIN users u_rdr ON u_rdr.id = r.user_id
        LEFT JOIN employees e_rdr ON e_rdr.id = r.employee_id
        WHERE lt.order_id = ?
        LIMIT 1");
    if ($tracking_stmt) {
        mysqli_stmt_bind_param($tracking_stmt, "i", $order_id);
        mysqli_stmt_execute($tracking_stmt);
        $t_res = mysqli_stmt_get_result($tracking_stmt);
        $rider_info = $t_res ? mysqli_fetch_assoc($t_res) : null;
        mysqli_stmt_close($tracking_stmt);
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Order #<?php echo htmlspecialchars($order['order_number']); ?> — Store Order Breakdown</title>
        <link rel="stylesheet" href="../css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary: #b3261e;
                --primary-hover: #981b15;
                --bg: #f8f9fa;
                --card-bg: #ffffff;
                --ink: #101828;
                --muted: #475467;
                --border: #eaecf0;
                --border-strong: #d0d5dd;
            }
            body { background-color: var(--bg); color: var(--ink); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding-bottom: 140px; }
            .store-header-bar { background: #ffffff; border-bottom: 1px solid var(--border); padding: 14px 20px; position: sticky; top: 0; z-index: 100; }
            .store-header-wrap { max-width: 900px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
            .store-header-title { display: flex; align-items: center; gap: 12px; }
            .btn-back-nav { border: 1px solid var(--border-strong); background: #ffffff; color: #344054; border-radius: 8px; padding: 7px 12px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
            .btn-back-nav:hover { background: #f8fafc; color: #101828; }
            
            .content-container { max-width: 900px; margin: 24px auto; padding: 0 16px; }
            
            /* Order Breakdown Card */
            .breakdown-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 1px 3px rgba(16,24,40,0.04); overflow: hidden; margin-bottom: 20px; }
            .breakdown-header { padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
            .breakdown-header h1 { font-size: 1.18rem; font-weight: 700; color: var(--ink); margin: 0; }
            .origin-badge { font-size: 12px; font-weight: 600; color: var(--primary); background: #fff1f0; border: 1px solid #fee4e2; border-radius: 999px; padding: 4px 12px; display: inline-flex; align-items: center; gap: 6px; }
            
            .breakdown-body { padding: 22px; }
            
            /* The Prominent Kinita ng Tindahan Highlight */
            .takehome-box { background: #ecfdf3; border: 1px solid #abefc6; border-radius: 12px; padding: 18px 20px; margin: 18px 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
            .takehome-label { display: flex; flex-direction: column; }
            .takehome-label-main { font-size: 1.15rem; font-weight: 800; color: #027a48; letter-spacing: -0.01em; }
            .takehome-label-sub { font-size: 12px; color: #05603a; font-weight: 500; }
            .takehome-amount { font-size: 1.85rem; font-weight: 900; color: #027a48; letter-spacing: -0.02em; }
            
            /* Calculation line items */
            .calc-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px dashed var(--border); font-size: 0.95rem; }
            .calc-row:last-child { border-bottom: none; }
            .calc-label { color: var(--muted); font-weight: 500; }
            .calc-val { color: var(--ink); font-weight: 600; }
            .calc-deduct { color: var(--primary); font-weight: 600; }
            
            /* Delivery Snapshot Card */
            .snapshot-section { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-top: 20px; }
            .snapshot-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #475467; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
            .snapshot-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
            .snapshot-item { font-size: 13px; }
            .snapshot-item span { display: block; color: var(--muted); font-size: 11.5px; font-weight: 500; margin-bottom: 2px; }
            .snapshot-item strong { color: var(--ink); font-weight: 600; }
            
            /* Payment Mode Badges */
            .badge-cod { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; border-radius: 8px; padding: 6px 10px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
            .badge-online { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; border-radius: 8px; padding: 6px 10px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
            
            /* Status Badges */
            .status-badge { font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; text-transform: capitalize; }
            .status-badge.delivered { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
            .status-badge.preparing { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }
            .status-badge.confirmed { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }
            .status-badge.pending { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }
            .status-badge.cancelled { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }

            /* Items Table */
            .items-card { background: #ffffff; border: 1px solid var(--border); border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(16,24,40,0.04); margin-bottom: 20px; }
            .table th { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); border-bottom: 1px solid var(--border); padding: 10px; }
            .table td { font-size: 13.5px; padding: 12px 10px; vertical-align: middle; border-bottom: 1px solid var(--border); }
        </style>
    </head>
    <body>
        <header class="store-header-bar">
            <div class="store-header-wrap">
                <div class="store-header-title">
                    <a href="orders.php" class="btn-back-nav"><i class="fas fa-arrow-left"></i> <span>Orders</span></a>
                    <span class="origin-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($store_info['store_name'] ?? 'Store #' . $current_store_id); ?></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="status-badge <?php echo htmlspecialchars($order['status']); ?>"><?php echo htmlspecialchars(ucfirst($order['status'])); ?></span>
                </div>
            </div>
        </header>

        <main class="content-container">
            <!-- 1. The Financial Breakdown Card -->
            <div class="breakdown-card">
                <div class="breakdown-header">
                    <div>
                        <h1>Order #<?php echo htmlspecialchars($order['order_number']); ?></h1>
                        <small class="text-muted"><i class="far fa-clock me-1"></i> Placed on <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></small>
                    </div>
                    <div>
                        <span class="origin-badge">
                            <i class="fas fa-map-marker-alt"></i> 
                            <?php echo htmlspecialchars($store_info['store_name'] ?? 'Main Store'); ?> &bull; <?php echo htmlspecialchars($store_info['city'] ?? $order['store_city'] ?? 'Branch'); ?>
                        </span>
                    </div>
                </div>

                <div class="breakdown-body">
                    <!-- Order Origin & Branch -->
                    <div class="p-3 rounded mb-3" style="background: #f8fafc; border: 1px solid var(--border);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong style="color: var(--ink);"><i class="fas fa-building text-secondary me-1"></i> Order Origin:</strong>
                                <span class="ms-1 fw-semibold text-dark"><?php echo htmlspecialchars($store_info['store_name'] ?? 'Store #' . $current_store_id); ?></span>
                            </div>
                            <div class="small text-muted">
                                <i class="fas fa-map-pin me-1"></i> <?php echo htmlspecialchars(($store_info['address'] ?? '') . ', ' . ($store_info['city'] ?? '')); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Gross Order Items Subtotal -->
                    <div class="calc-row">
                        <span class="calc-label">Gross Order Items Subtotal</span>
                        <span class="calc-val">&#8369;<?php echo number_format((float)$split['food_subtotal'], 2); ?></span>
                    </div>

                    <!-- Platform Commission Deducted -->
                    <div class="calc-row">
                        <span class="calc-label">
                            Platform Commission Deducted (<?php echo number_format((float)$split['platform_commission_rate'], 1); ?>%)
                            <?php if ((float)$split['platform_service_fee'] > 0): ?>
                                <small class="text-muted">+ &#8369;<?php echo number_format((float)$split['platform_service_fee'], 2); ?> flat fee</small>
                            <?php endif; ?>
                        </span>
                        <span class="calc-deduct">-&#8369;<?php echo number_format((float)$split['platform_cut'], 2); ?></span>
                    </div>

                    <!-- Kinita ng Tindahan (Net Store Take-Home) PROMINENT HIGHLIGHT -->
                    <div class="takehome-box">
                        <div class="takehome-label">
                            <span class="takehome-label-main"><i class="fas fa-coins me-1"></i> Kinita ng Tindahan (Net Store Take-Home)</span>
                            <span class="takehome-label-sub">Directly credited to your Store Wallet as withdrawable balance.</span>
                        </div>
                        <div class="takehome-amount">
                            &#8369;<?php echo number_format((float)$split['store_net_cut'], 2); ?>
                        </div>
                    </div>

                    <!-- Delivery Snapshot for this Order -->
                    <div class="snapshot-section">
                        <div class="snapshot-title"><i class="fas fa-motorcycle text-danger"></i> Delivery Snapshot for this Order</div>
                        <div class="snapshot-grid">
                            <div class="snapshot-item">
                                <span>Assigned Rider:</span>
                                <?php if ($rider_info && !empty($rider_info['driver_id'])): ?>
                                    <strong><?php echo htmlspecialchars($rider_info['rider_display_name']); ?></strong>
                                    <?php if (!empty($rider_info['vehicle_plate'])): ?>
                                        <div class="small text-muted"><i class="fas fa-id-card me-1"></i> Plate: <?php echo htmlspecialchars($rider_info['vehicle_plate']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($rider_info['rider_display_phone'])): ?>
                                        <div class="small"><a href="tel:<?php echo htmlspecialchars($rider_info['rider_display_phone']); ?>" class="text-success text-decoration-none"><i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($rider_info['rider_display_phone']); ?></a></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted fst-italic">No rider assigned yet</span>
                                <?php endif; ?>
                            </div>

                            <div class="snapshot-item">
                                <span>Delivery Fee (Paid by customer to rider):</span>
                                <strong>&#8369;<?php echo number_format((float)$split['delivery_fee'], 2); ?></strong>
                                <?php if ((float)$split['customer_tip'] > 0): ?>
                                    <div class="small text-muted">+ &#8369;<?php echo number_format((float)$split['customer_tip'], 2); ?> customer tip</div>
                                <?php endif; ?>
                            </div>

                            <div class="snapshot-item">
                                <span>Payment Mode:</span>
                                <?php if ($split['payment_method'] === 'cod'): ?>
                                    <span class="badge-cod">
                                        <i class="fas fa-hand-holding-usd"></i> [Cash on Delivery]
                                    </span>
                                    <div class="small text-muted mt-1">Rider to collect physical cash; store earnings credited directly to Store Wallet.</div>
                                <?php else: ?>
                                    <span class="badge-online">
                                        <i class="fas fa-credit-card"></i> [Online / Paid]
                                    </span>
                                    <div class="small text-muted mt-1">Digitally paid; store earnings credited to Store Wallet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Ordered Food Items -->
            <div class="items-card">
                <h5 class="mb-3 fw-bold" style="font-size: 1.05rem; color: var(--ink);"><i class="fas fa-utensils me-2 text-danger"></i> Food Items (Your Store)</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-end">Item Price</th>
                                <th class="text-end">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    </td>
                                    <td class="text-center fw-bold"><?php echo (int)$item['quantity']; ?></td>
                                    <td class="text-end">&#8369;<?php echo number_format((float)$item['price'], 2); ?></td>
                                    <td class="text-end fw-bold text-dark">&#8369;<?php echo number_format((float)$item['total'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Customer & Delivery Destination -->
            <div class="breakdown-card">
                <div class="breakdown-header">
                    <h5 class="mb-0 fw-bold" style="font-size: 1.05rem;"><i class="fas fa-map-marker-alt text-primary me-2"></i> Delivery Destination & Customer</h5>
                </div>
                <div class="breakdown-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <span class="text-muted d-block small">Customer Name</span>
                            <strong class="text-dark"><?php echo htmlspecialchars($order['safe_customer_name']); ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block small">Contact Phone</span>
                            <strong class="text-dark"><?php echo htmlspecialchars($order['customer_phone']); ?></strong>
                        </div>
                        <div class="col-12">
                            <span class="text-muted d-block small">Delivery Address</span>
                            <p class="mb-0 text-dark"><?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?></p>
                        </div>
                        <?php if (!empty($order['special_instructions'])): ?>
                            <div class="col-12">
                                <span class="text-muted d-block small">Special Cooking / Preparation Notes</span>
                                <div class="p-2 rounded" style="background: #fff8f8; border: 1px solid #fee4e2; font-size: 13px;">
                                    <?php echo nl2br(htmlspecialchars($order['special_instructions'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// LIST VIEW: /store/orders
// Strictly Store-Scoped Multi-Tenant Isolation
// ==========================================

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$has_store_id = function_exists('appColumnExists') ? appColumnExists($conn, 'orders', 'store_id') : false;
$has_is_archived = function_exists('appColumnExists') ? appColumnExists($conn, 'orders', 'is_archived') : false;
$archived_clause = $has_is_archived ? "AND o.is_archived = 0" : "";

$store_where_conditions = ["o.pickup_location = ?"];
$bind_types = "s";
$bind_values = [(string)$current_store_id];

if ($has_store_id) {
    $store_where_conditions[] = "o.store_id = ?";
    $bind_types .= "i";
    $bind_values[] = (int)$current_store_id;
}

if (function_exists('appColumnExists') && appColumnExists($conn, 'orders', 'seller_id') && appTableExists($conn, 'store_locations')) {
    $store_where_conditions[] = "o.seller_id IN (SELECT owner_user_id FROM store_locations WHERE store_id = ?)";
    $bind_types .= "i";
    $bind_values[] = (int)$current_store_id;
}

$store_scope_sql = "(" . implode(" OR ", $store_where_conditions) . ")";

// Count orders for this store ONLY
$count_sql = "SELECT COUNT(*) AS total FROM orders o WHERE {$store_scope_sql} {$archived_clause}";
$count_stmt = mysqli_prepare($conn, $count_sql);
$total_orders = 0;
if ($count_stmt) {
    mysqli_stmt_bind_param($count_stmt, $bind_types, ...$bind_values);
    mysqli_stmt_execute($count_stmt);
    $count_res = mysqli_stmt_get_result($count_stmt);
    $total_orders = ($count_res instanceof mysqli_result) ? (int)(mysqli_fetch_assoc($count_res)['total'] ?? 0) : 0;
    mysqli_stmt_close($count_stmt);
}

// Fetch orders strictly belonging to this store
$has_splits = function_exists('appTableExists') ? appTableExists($conn, 'order_payout_splits') : false;
$has_lt = function_exists('appTableExists') ? appTableExists($conn, 'logistics_tracking') : false;
$has_riders = function_exists('appTableExists') ? appTableExists($conn, 'riders') : false;
$has_users = function_exists('appTableExists') ? appTableExists($conn, 'users') : false;
$has_employees = function_exists('appTableExists') ? appTableExists($conn, 'employees') : false;

$splits_join = $has_splits ? "LEFT JOIN order_payout_splits ops ON ops.order_id = o.id AND ops.store_id = ?" : "";
$splits_cols = $has_splits ? "ops.food_subtotal, ops.platform_commission_amount, ops.store_net_cut, ops.split_status," : "NULL AS food_subtotal, NULL AS platform_commission_amount, NULL AS store_net_cut, 'unsplit' AS split_status,";

$lt_join = $has_lt ? "LEFT JOIN logistics_tracking lt ON lt.order_id = o.id" : "";
$riders_join = ($has_lt && $has_riders) ? "LEFT JOIN riders r ON r.id = lt.driver_id" : "";
$users_join = ($has_lt && $has_riders && $has_users) ? "LEFT JOIN users u_rdr ON u_rdr.id = r.user_id" : "";
$employees_join = ($has_lt && $has_riders && $has_employees) ? "LEFT JOIN employees e_rdr ON e_rdr.id = r.employee_id" : "";

$rider_code_col = ($has_lt && $has_riders) ? "r.rider_code," : "'' AS rider_code,";
$rider_name_col = ($has_lt && $has_riders && $has_users && $has_employees)
    ? "COALESCE(u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), lt.driver_name, 'Rider') AS rider_name"
    : ($has_lt ? "COALESCE(lt.driver_name, 'Rider') AS rider_name" : "'Rider' AS rider_name");

$orders_sql = "
    SELECT o.id, o.order_number, o.customer_name, o.delivery_option, o.payment_method, o.status, o.subtotal, o.delivery_fee, o.created_at,
           {$splits_cols}
           {$rider_code_col}
           {$rider_name_col}
    FROM orders o
    {$splits_join}
    {$lt_join}
    {$riders_join}
    {$users_join}
    {$employees_join}
    WHERE {$store_scope_sql} {$archived_clause}
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?";

$list_bind_types = ($has_splits ? "i" : "") . $bind_types . "ii";
$list_bind_values = [];
if ($has_splits) {
    $list_bind_values[] = (int)$current_store_id;
}
foreach ($bind_values as $bv) {
    $list_bind_values[] = $bv;
}
$list_bind_values[] = (int)$per_page;
$list_bind_values[] = (int)$offset;

$orders_stmt = mysqli_prepare($conn, $orders_sql);
$store_orders = [];
if ($orders_stmt) {
    mysqli_stmt_bind_param($orders_stmt, $list_bind_types, ...$list_bind_values);
    mysqli_stmt_execute($orders_stmt);
    $orders_res = mysqli_stmt_get_result($orders_stmt);
    while ($orders_res && ($row = mysqli_fetch_assoc($orders_res))) {
        $store_orders[] = $row;
    }
    mysqli_stmt_close($orders_stmt);
}

// Fetch store wallet overview
$wallet = $walletService->getStoreWallet($current_store_id) ?: [
    'available_balance' => 0,
    'total_lifetime_earnings' => 0,
    'pending_payout_amount' => 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Orders — <?php echo htmlspecialchars($store_info['store_name'] ?? 'Merchant Portal'); ?></title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #b3261e;
            --primary-hover: #981b15;
            --bg: #f8f9fa;
            --card-bg: #ffffff;
            --ink: #101828;
            --muted: #475467;
            --border: #eaecf0;
        }
        body { background-color: var(--bg); color: var(--ink); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding-bottom: 140px; }
        .store-header-bar { background: #ffffff; border-bottom: 1px solid var(--border); padding: 14px 20px; position: sticky; top: 0; z-index: 100; }
        .store-header-wrap { max-width: 1100px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .content-container { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
        .wallet-banner { background: #ffffff; border: 1px solid var(--border); border-radius: 16px; padding: 20px 24px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 1px 3px rgba(16,24,40,0.04); }
        .wallet-stat { display: flex; flex-direction: column; }
        .wallet-stat span { font-size: 12px; color: var(--muted); font-weight: 500; }
        .wallet-stat strong { font-size: 1.5rem; font-weight: 800; color: #027a48; letter-spacing: -0.01em; }
        .table-card { background: #ffffff; border: 1px solid var(--border); border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(16,24,40,0.04); }
        .table-card .card-header { background: #ffffff; border-bottom: 1px solid var(--border); padding: 16px 20px; font-weight: 700; font-size: 1.05rem; }
        .table th { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); padding: 12px; }
        .table td { font-size: 13px; padding: 14px 12px; vertical-align: middle; border-bottom: 1px solid var(--border); }
        .btn-view-order { border: 1px solid var(--border); background: #ffffff; color: var(--ink); border-radius: 8px; font-weight: 600; font-size: 12px; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s ease; }
        .btn-view-order:hover { background: #f8fafc; border-color: #d0d5dd; color: var(--primary); }
        .badge-cod { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; border-radius: 6px; padding: 3px 8px; font-size: 11px; font-weight: 700; }
        .badge-online { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; border-radius: 6px; padding: 3px 8px; font-size: 11px; font-weight: 700; }
        .status-badge { font-size: 11.5px; font-weight: 700; padding: 3px 8px; border-radius: 999px; text-transform: capitalize; }
        .status-badge.delivered { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
        .status-badge.preparing { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }
        .status-badge.confirmed { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }
        .status-badge.pending { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }
        .status-badge.cancelled { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }
    </style>
</head>
<body>
    <header class="store-header-bar">
        <div class="store-header-wrap">
            <div class="d-flex align-items-center gap-2">
                <span style="font-weight: 700; font-size: 1.15rem; color: var(--ink);">
                    <i class="fas fa-store text-danger me-2"></i> <?php echo htmlspecialchars($store_info['store_name'] ?? 'My Store'); ?>
                </span>
                <span class="badge" style="background: #f1f5f9; color: #475467; font-size: 11px; border: 1px solid #e2e8f0;">
                    Branch #<?php echo $current_store_id; ?> &bull; <?php echo htmlspecialchars($store_info['city'] ?? 'Active'); ?>
                </span>
            </div>
            <div>
                <a href="../admin/orders.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-columns me-1"></i> Admin Portal
                </a>
                <a href="../admin/store_wallet.php" class="btn btn-sm text-white ms-2" style="background: var(--primary); border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-wallet me-1"></i> Store Wallet
                </a>
            </div>
        </div>
    </header>

    <main class="content-container">
        <!-- Store Wallet Quick Header -->
        <div class="wallet-banner">
            <div class="d-flex align-items-center gap-4 flex-wrap">
                <div class="wallet-stat">
                    <span>Available Withdrawable Balance</span>
                    <strong>&#8369;<?php echo number_format((float)$wallet['available_balance'], 2); ?></strong>
                </div>
                <div class="wallet-stat">
                    <span>Total Lifetime Earnings</span>
                    <strong style="color: #101828;">&#8369;<?php echo number_format((float)$wallet['total_lifetime_earnings'], 2); ?></strong>
                </div>
                <div class="wallet-stat">
                    <span>Total Orders</span>
                    <strong style="color: #101828;"><?php echo number_format($total_orders); ?></strong>
                </div>
            </div>
            <div>
                <a href="../admin/store_wallet.php" class="btn btn-outline-success fw-bold btn-sm" style="border-radius: 8px;">
                    <i class="fas fa-money-bill-wave me-1"></i> Request Payout
                </a>
            </div>
        </div>

        <!-- Store Orders Table -->
        <div class="table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-receipt text-danger me-2"></i> Store Orders List (Isolated to <?php echo htmlspecialchars($store_info['store_name'] ?? 'Your Store'); ?>)</span>
                <span class="small text-muted">Showing <?php echo count($store_orders); ?> of <?php echo $total_orders; ?> orders</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Food Subtotal</th>
                            <th>Platform Fee</th>
                            <th>Kinita ng Tindahan</th>
                            <th>Rider</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($store_orders)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No orders found for this store.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($store_orders as $o): ?>
                                <?php
                                $food_sub = (float)($o['food_subtotal'] ?: $o['subtotal']);
                                $plat_fee = (float)($o['platform_commission_amount'] ?: ($food_sub * 0.15));
                                $net_store = (float)($o['store_net_cut'] ?: ($food_sub - $plat_fee));
                                $pm_raw = strtolower((string)$o['payment_method']);
                                $is_cod = (strpos($pm_raw, 'cod') !== false || strpos($pm_raw, 'cash') !== false);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($o['order_number']); ?></div>
                                        <div class="text-muted" style="font-size: 11px;"><?php echo date('M d, Y h:i A', strtotime($o['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($o['customer_name']); ?></div>
                                        <div class="text-muted" style="font-size: 11px;"><?php echo ucfirst($o['delivery_option']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($is_cod): ?>
                                            <span class="badge-cod"><i class="fas fa-hand-holding-usd"></i> COD</span>
                                        <?php else: ?>
                                            <span class="badge-online"><i class="fas fa-credit-card"></i> Online</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($o['status']); ?>"><?php echo ucfirst($o['status']); ?></span>
                                    </td>
                                    <td>&#8369;<?php echo number_format($food_sub, 2); ?></td>
                                    <td class="text-danger">-&#8369;<?php echo number_format($plat_fee, 2); ?></td>
                                    <td>
                                        <strong class="text-success" style="font-size: 14px;">
                                            &#8369;<?php echo number_format($net_store, 2); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($o['rider_name']) && $o['rider_name'] !== 'Rider'): ?>
                                            <span class="small text-dark fw-semibold"><i class="fas fa-motorcycle text-muted me-1"></i> <?php echo htmlspecialchars($o['rider_name']); ?></span>
                                        <?php else: ?>
                                            <span class="small text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="orders.php?id=<?php echo (int)$o['id']; ?>" class="btn-view-order">
                                            <i class="fas fa-eye"></i> Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
