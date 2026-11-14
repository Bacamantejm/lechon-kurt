<?php
/**
 * supplier/history.php
 * Historical logs and fulfillment records for suppliers.
 */

$page_title = "Fulfillment History | Supplier Portal";
require_once __DIR__ . '/header.php';

// Search filter
$search = trim((string)($_GET['search'] ?? ''));

$where_clauses = ["so.supplier_id = ?", "so.status IN ('completed', 'rejected', 'cancelled')"];
$param_types = "i";
$param_values = [$current_supplier_id];

if (!empty($search)) {
    $where_clauses[] = "(so.order_code LIKE ? OR u.business_name LIKE ? OR u.full_name LIKE ?)";
    $search_param = "%$search%";
    $param_types .= "sss";
    $param_values[] = $search_param;
    $param_values[] = $search_param;
    $param_values[] = $search_param;
}

$where_sql = implode(" AND ", $where_clauses);

$history_query = "
    SELECT so.*, 
           COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(u.full_name), ''), 'Franchise Branch') AS store_name,
           u.phone AS store_phone,
           COUNT(soi.id) AS total_items
    FROM supplier_orders so
    LEFT JOIN users u ON u.id = so.store_id
    LEFT JOIN supplier_order_items soi ON soi.order_id = so.id
    WHERE $where_sql
    GROUP BY so.id
    ORDER BY so.completed_at DESC, so.created_at DESC
";

$h_stmt = mysqli_prepare($conn, $history_query);
$history_list = [];
if ($h_stmt) {
    mysqli_stmt_bind_param($h_stmt, $param_types, ...$param_values);
    mysqli_stmt_execute($h_stmt);
    $h_res = mysqli_stmt_get_result($h_stmt);
    while ($hr = mysqli_fetch_assoc($h_res)) {
        $history_list[] = $hr;
    }
    mysqli_stmt_close($h_stmt);
}
?>

<div class="container-fluid px-4">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 style="font-family:'Outfit',sans-serif; font-weight:800; margin:0; font-size:1.75rem; color:#101828;">
                Fulfillment &amp; Invoice History
            </h2>
            <p style="color:#667085; margin:4px 0 0 0; font-size:0.95rem;">
                Historical record of all delivered, completed, or closed procurement orders.
            </p>
        </div>
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search Order Code or Shop..." class="form-control" style="width:260px;">
            <button type="submit" class="btn btn-system-primary">
                <i class="fas fa-search"></i>
            </button>
            <?php if (!empty($search)): ?>
                <a href="history.php" class="btn btn-system-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="system-card p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table align-middle table-hover mb-0">
                <thead>
                    <tr style="border-bottom: 2px solid #eaecf0; color:#475467; font-size:0.82rem; text-transform:uppercase; background:#f8f9fa;">
                        <th style="padding-left:24px;">Order Code</th>
                        <th>Store Name</th>
                        <th>Items Count</th>
                        <th>Total Amount</th>
                        <th>Completed Date</th>
                        <th>Status</th>
                        <th style="padding-right:24px;" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history_list)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-box-archive fa-2x mb-2 text-secondary"></i>
                                <div>No completed or archived procurement orders found.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history_list as $row): ?>
                            <tr>
                                <td style="padding-left:24px;">
                                    <div style="font-weight:700; color:#101828;">
                                        <?php echo htmlspecialchars($row['order_code']); ?>
                                    </div>
                                    <div style="font-size:0.78rem; color:#667085;">
                                        Created: <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:700; color:#101828;">
                                        <?php echo htmlspecialchars($row['store_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo (int)$row['total_items']; ?> item(s)
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight:800; color:#101828;">
                                        ₱<?php echo number_format($row['total_amount'], 2); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($row['completed_at'])): ?>
                                        <div style="font-size:0.88rem; color:#344054;">
                                            <i class="fas fa-calendar-check text-success me-1"></i>
                                            <?php echo date('M d, Y h:i A', strtotime($row['completed_at'])); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($row['status']); ?>">
                                        <?php echo htmlspecialchars($row['status']); ?>
                                    </span>
                                </td>
                                <td style="padding-right:24px;" class="text-end">
                                    <a href="request_details.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-system-outline">
                                        <i class="fas fa-eye"></i> View Summary
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
