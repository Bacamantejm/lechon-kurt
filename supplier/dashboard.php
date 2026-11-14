<?php
/**
 * supplier/dashboard.php
 * Supplier Dashboard: Incoming Refill Requests Queue, Metrics, & Real-time Status Tracking.
 */

$page_title = "Incoming Requests Queue | Supplier Portal";
require_once __DIR__ . '/header.php';

// Filter by status tab
$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'pending';
$allowed_tabs = ['all', 'pending', 'awaiting_finance', 'finance_approved', 'in_transit', 'completed'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'pending';
}

// 1. Calculate Summary Metrics
$metrics = [
    'pending' => 0,
    'awaiting_finance' => 0,
    'finance_approved' => 0,
    'processing' => 0,
    'in_transit' => 0,
    'completed' => 0,
    'total_revenue' => 0.00
];

$supp_id_filter = !empty($supp_id_in_str) ? $supp_id_in_str : (int)$current_supplier_id;

$m_q = @mysqli_query($conn, "
    SELECT status, COUNT(*) as cnt, SUM(total_amount) as total_val
    FROM supplier_orders 
    WHERE supplier_id IN ($supp_id_filter)
    GROUP BY status
");
if ($m_q) {
    while ($m_row = mysqli_fetch_assoc($m_q)) {
        $st = $m_row['status'];
        if ($st === 'awaiting_finance_approval') {
            $metrics['awaiting_finance'] = (int)$m_row['cnt'];
        } elseif ($st === 'finance_approved') {
            $metrics['finance_approved'] = (int)$m_row['cnt'];
        } elseif (isset($metrics[$st])) {
            $metrics[$st] = (int)$m_row['cnt'];
        }
        if ($st === 'completed') {
            $metrics['total_revenue'] = (float)$m_row['total_val'];
        }
    }
}

// 2. Fetch Orders according to active tab
$where_clauses = ["so.supplier_id IN ($supp_id_filter)"];

if ($active_tab === 'pending') {
    $where_clauses[] = "so.status = 'pending'";
} elseif ($active_tab === 'awaiting_finance') {
    $where_clauses[] = "so.status = 'awaiting_finance_approval'";
} elseif ($active_tab === 'finance_approved') {
    $where_clauses[] = "so.status = 'finance_approved'";
} elseif ($active_tab === 'in_transit') {
    $where_clauses[] = "so.status IN ('packed', 'in_transit')";
} elseif ($active_tab === 'completed') {
    $where_clauses[] = "so.status = 'completed'";
}

$where_sql = implode(" AND ", $where_clauses);

$orders_query = "
    SELECT so.*, 
           COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(u.full_name), ''), 'Franchise Branch') AS store_name,
           u.phone AS store_phone,
           u.email AS store_email,
           COUNT(soi.id) AS total_items,
           GROUP_CONCAT(CONCAT(m.name, ' (', COALESCE(NULLIF(soi.quantity_requested, 0), soi.quantity_supplied, 1), ' ', soi.unit, ')') SEPARATOR ', ') AS items_preview
    FROM supplier_orders so
    LEFT JOIN users u ON u.id = so.store_id
    LEFT JOIN supplier_order_items soi ON soi.order_id = so.id
    LEFT JOIN materials m ON m.id = soi.material_id
    WHERE $where_sql
    GROUP BY so.id
    ORDER BY 
        CASE 
            WHEN so.urgency_level = 'critical' THEN 1
            WHEN so.urgency_level = 'urgent' THEN 2
            ELSE 3
        END,
        so.created_at DESC
";

$orders_res = @mysqli_query($conn, $orders_query);
$orders_list = [];
if ($orders_res) {
    while ($row = mysqli_fetch_assoc($orders_res)) {
        $orders_list[] = $row;
    }
}
?>

<div class="container-fluid px-4">
    <!-- Top Action & Greeting Banner -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 style="font-family:'Outfit',sans-serif; font-weight:800; margin:0; font-size:1.75rem; color:#101828;">
                Procurement &amp; Refill Requests
            </h2>
            <p style="color:#667085; margin:4px 0 0 0; font-size:0.95rem;">
                Review incoming shop inventory refill requests, verify wholesale quotes, and process deliveries.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="catalog.php" class="btn btn-system-outline">
                <i class="fas fa-tags"></i> Manage Wholesale Prices
            </a>
            <a href="history.php" class="btn btn-system-outline">
                <i class="fas fa-file-invoice"></i> Completed Invoices
            </a>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="system-card p-3 mb-0" style="border-left: 4px solid #b3261e;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:0.82rem; font-weight:700; color:#667085; text-transform:uppercase;">Pending Review</div>
                        <div style="font-size:1.75rem; font-weight:800; color:#b3261e; margin-top:2px;">
                            <?php echo $metrics['pending']; ?>
                        </div>
                    </div>
                    <div style="width:44px; height:44px; border-radius:10px; background:#fff1f0; color:#b3261e; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                        <i class="fas fa-hourglass-start"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="system-card p-3 mb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:0.82rem; font-weight:700; color:#667085; text-transform:uppercase;">Awaiting Finance</div>
                        <div style="font-size:1.75rem; font-weight:800; color:#b54708; margin-top:2px;">
                            <?php echo $metrics['awaiting_finance']; ?>
                        </div>
                    </div>
                    <div style="width:44px; height:44px; border-radius:10px; background:#fffaeb; color:#b54708; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="system-card p-3 mb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:0.82rem; font-weight:700; color:#667085; text-transform:uppercase;">Finance Approved (Ready)</div>
                        <div style="font-size:1.75rem; font-weight:800; color:#027a48; margin-top:2px;">
                            <?php echo $metrics['finance_approved']; ?>
                        </div>
                    </div>
                    <div style="width:44px; height:44px; border-radius:10px; background:#ecfdf3; color:#027a48; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="system-card p-3 mb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:0.82rem; font-weight:700; color:#667085; text-transform:uppercase;">Total Delivered (₱)</div>
                        <div style="font-size:1.75rem; font-weight:800; color:#027a48; margin-top:2px;">
                            ₱<?php echo number_format($metrics['total_revenue'], 2); ?>
                        </div>
                    </div>
                    <div style="width:44px; height:44px; border-radius:10px; background:#ecfdf3; color:#027a48; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert / Toast Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success d-flex align-items-center justify-content-between mb-4" style="background:#ecfdf3; border:1px solid #abefc6; color:#027a48; border-radius:10px;">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger d-flex align-items-center justify-content-between mb-4" style="background:#fff1f0; border:1px solid #fee4e2; color:#b3261e; border-radius:10px;">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="system-card p-0 mb-4 overflow-hidden">
        <div class="d-flex border-bottom bg-light px-3 pt-2 gap-2 overflow-auto" style="border-color:#eaecf0 !important;">
            <a href="dashboard.php?tab=pending" class="px-3 py-2 text-decoration-none fw-semibold border-bottom <?php echo $active_tab === 'pending' ? 'border-2 border-danger text-danger' : 'border-transparent text-secondary'; ?>">
                <i class="fas fa-bell"></i> Pending Requests
                <?php if ($metrics['pending'] > 0): ?>
                    <span class="badge rounded-pill bg-danger ms-1"><?php echo $metrics['pending']; ?></span>
                <?php endif; ?>
            </a>
            <a href="dashboard.php?tab=awaiting_finance" class="px-3 py-2 text-decoration-none fw-semibold border-bottom <?php echo $active_tab === 'awaiting_finance' ? 'border-2 border-danger text-danger' : 'border-transparent text-secondary'; ?>">
                <i class="fas fa-hourglass-half"></i> Awaiting Finance
                <?php if ($metrics['awaiting_finance'] > 0): ?>
                    <span class="badge rounded-pill bg-warning text-dark ms-1"><?php echo $metrics['awaiting_finance']; ?></span>
                <?php endif; ?>
            </a>
            <a href="dashboard.php?tab=finance_approved" class="px-3 py-2 text-decoration-none fw-semibold border-bottom <?php echo $active_tab === 'finance_approved' ? 'border-2 border-danger text-danger' : 'border-transparent text-secondary'; ?>">
                <i class="fas fa-check-circle"></i> Finance Approved (Ready)
                <?php if ($metrics['finance_approved'] > 0): ?>
                    <span class="badge rounded-pill bg-success ms-1"><?php echo $metrics['finance_approved']; ?></span>
                <?php endif; ?>
            </a>
            <a href="dashboard.php?tab=in_transit" class="px-3 py-2 text-decoration-none fw-semibold border-bottom <?php echo $active_tab === 'in_transit' ? 'border-2 border-danger text-danger' : 'border-transparent text-secondary'; ?>">
                <i class="fas fa-truck"></i> Dispatched / In Transit
            </a>
            <a href="dashboard.php?tab=completed" class="px-3 py-2 text-decoration-none fw-semibold border-bottom <?php echo $active_tab === 'completed' ? 'border-2 border-danger text-danger' : 'border-transparent text-secondary'; ?>">
                <i class="fas fa-check-double"></i> Completed Deliveries
            </a>
            <a href="dashboard.php?tab=all" class="px-3 py-2 text-decoration-none fw-semibold border-bottom <?php echo $active_tab === 'all' ? 'border-2 border-danger text-danger' : 'border-transparent text-secondary'; ?>">
                <i class="fas fa-list"></i> All Requests
            </a>
        </div>

        <!-- Requests Table -->
        <div class="p-4">
            <?php if (empty($orders_list)): ?>
                <div class="text-center py-5">
                    <div style="width:64px; height:64px; border-radius:50%; background:#f2f4f7; color:#98a2b3; display:inline-flex; align-items:center; justify-content:center; font-size:1.75rem; margin-bottom:16px;">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <h5 style="font-weight:700; color:#101828;">No Refill Requests Found</h5>
                    <p style="color:#667085; max-width:400px; margin:0 auto; font-size:0.92rem;">
                        There are currently no procurement orders matching the "<?php echo htmlspecialchars($active_tab); ?>" filter. New refill requests from store owners will automatically appear here.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead>
                            <tr style="border-bottom: 2px solid #eaecf0; color:#475467; font-size:0.82rem; text-transform:uppercase; letter-spacing:0.5px;">
                                <th>Request Code</th>
                                <th>Shop Name &amp; Contact</th>
                                <th>Urgency</th>
                                <th>Requested Items</th>
                                <th>Estimated Amount</th>
                                <th>Target Date</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders_list as $ord): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700; color:#101828;">
                                            <?php echo htmlspecialchars($ord['order_code']); ?>
                                        </div>
                                        <div style="font-size:0.78rem; color:#667085;">
                                            <?php echo date('M d, Y h:i A', strtotime($ord['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:#101828; display:flex; align-items:center; gap:6px;">
                                            <i class="fas fa-store text-danger"></i>
                                            <?php echo htmlspecialchars($ord['store_name']); ?>
                                        </div>
                                        <div style="font-size:0.8rem; color:#667085;">
                                            <?php echo htmlspecialchars($ord['store_phone'] ?: $ord['store_email']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="urgency-badge urgency-<?php echo htmlspecialchars($ord['urgency_level']); ?>">
                                            <?php echo htmlspecialchars($ord['urgency_level']); ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 280px;">
                                        <div style="font-weight:600; color:#344054; font-size:0.88rem;">
                                            <i class="fas fa-boxes-stacked text-secondary"></i> <?php echo (int)$ord['total_items']; ?> item(s)
                                        </div>
                                        <div class="text-truncate" style="font-size:0.8rem; color:#667085;" title="<?php echo htmlspecialchars($ord['items_preview'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($ord['items_preview'] ?? 'Custom items'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:800; color:#101828; font-size:0.95rem;">
                                            ₱<?php echo number_format($ord['total_amount'], 2); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($ord['requested_delivery_date'])): ?>
                                            <div style="font-size:0.88rem; font-weight:600; color:#344054;">
                                                <i class="fas fa-calendar-day text-secondary"></i>
                                                <?php echo date('M d, Y', strtotime($ord['requested_delivery_date'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.85rem;">Standard</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($ord['status']); ?>">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', $ord['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($ord['status'] === 'finance_approved'): ?>
                                            <a href="request_details.php?id=<?php echo (int)$ord['id']; ?>" class="btn btn-sm btn-system-primary" style="background:#b3261e; border-color:#b3261e; font-weight:700;">
                                                <i class="fas fa-truck-fast"></i> Proceed to Dispatch
                                            </a>
                                        <?php elseif ($ord['status'] === 'awaiting_finance_approval'): ?>
                                            <a href="request_details.php?id=<?php echo (int)$ord['id']; ?>" class="btn btn-sm btn-system-outline">
                                                <i class="fas fa-hourglass-half"></i> View Invoice
                                            </a>
                                        <?php else: ?>
                                            <a href="request_details.php?id=<?php echo (int)$ord['id']; ?>" class="btn btn-sm btn-system-primary">
                                                <i class="fas fa-eye"></i> Review &amp; Fulfill
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
