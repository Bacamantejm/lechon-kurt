<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
requirePermission('mrp.view');

if (!isset($_GET['id'])) {
    die("Invalid Purchase Order");
}

$po_id = intval($_GET['id']);
$view = $_GET['view'] ?? 'details'; // 'details' or 'receive'
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

$po_query = mysqli_query($conn, "SELECT po.*, s.name as supplier_name
                                 FROM purchase_orders po
                                 LEFT JOIN suppliers s ON po.supplier_id = s.id
                                 WHERE po.id = " . (int)$po_id . ($seller_scope_id !== null ? "
                                   AND (po.created_by = " . (int)$seller_scope_id . "
                                        OR EXISTS (
                                            SELECT 1
                                            FROM purchase_order_items poi_scope
                                            INNER JOIN bill_of_materials bom_scope ON bom_scope.material_id = poi_scope.material_id
                                            INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
                                            WHERE poi_scope.purchase_order_id = po.id
                                              AND p_scope.seller_id = " . (int)$seller_scope_id . "
                                        )
                                   )" : "") . "
                                 LIMIT 1");
$po = mysqli_fetch_assoc($po_query);

if (!$po) {
    die("Purchase Order not found.");
}

$items_query = mysqli_query($conn, "
    SELECT poi.*, m.name as material_name, m.unit 
    FROM purchase_order_items poi 
    JOIN materials m ON poi.material_id = m.id 
    WHERE poi.purchase_order_id = " . (int)$po_id . ($seller_scope_id !== null ? "
      AND EXISTS (
          SELECT 1
          FROM bill_of_materials bom_scope
          INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
          WHERE bom_scope.material_id = poi.material_id
            AND p_scope.seller_id = " . (int)$seller_scope_id . "
      )" : "") . "
");

if ($view === 'receive') {
?>
<form method="POST" action="mrp.php">
    <input type="hidden" name="action" value="receive_stock">
    <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">

    <!-- Section 1: Order Reference -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-file-invoice text-danger"></i>
                Purchase Order Details
            </div>
            <span class="form-opt-pill">Inspection</span>
        </div>

        <div class="row g-2">
            <div class="col-sm-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">PO Number</small>
                    <span class="fw-bold" style="color: #101828; font-size: 14px;"><?php echo htmlspecialchars($po['po_number']); ?></span>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Supplier Name</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo htmlspecialchars($po['supplier_name']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Items Inspection -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-boxes text-danger"></i>
                Material Line Items
            </div>
            <span class="form-req-pill">Intake Verification</span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr class="text-muted" style="font-size: 12px; border-bottom: 2px solid #eaecf0;">
                        <th>Material</th>
                        <th class="text-center" width="120">Ordered</th>
                        <th class="text-center" width="130">Prev Received</th>
                        <th width="150">Receiving Now</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($item = mysqli_fetch_assoc($items_query)): 
                        $remaining = $item['quantity_ordered'] - $item['quantity_received'];
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold" style="color: #101828; font-size: 13px;"><?php echo htmlspecialchars($item['material_name']); ?></div>
                            <input type="hidden" name="item_id[]" value="<?php echo $item['id']; ?>">
                        </td>
                        <td class="text-center">
                            <span class="badge" style="background: #f2f4f7; color: #344054; font-size: 12px; font-weight: 600;">
                                <?php echo $item['quantity_ordered'] . ' ' . $item['unit']; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge" style="background: #ecfdf3; color: #027a48; font-size: 12px; font-weight: 600;">
                                <?php echo $item['quantity_received'] . ' ' . $item['unit']; ?>
                            </span>
                        </td>
                        <td>
                            <div class="form-input-wrap">
                                <input type="number" name="quantity_received[]" class="form-control form-control-sm text-end fw-bold" step="0.01" min="0" max="<?php echo $remaining; ?>" placeholder="0.00" style="padding-left: 10px !important;">
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal-footer px-0 pb-0">
        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn-modal-primary">Receive Stock</button>
    </div>
</form>
<?php
} else {
    // Placeholder for a full details view if needed later
    echo "<h4>Details for PO #{$po['po_number']}</h4>";
    echo "<p>This is where full PO details would go.</p>";
}

?>
