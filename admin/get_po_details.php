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
    <p><strong>PO Number:</strong> <?php echo $po['po_number']; ?></p>
    <p><strong>Supplier:</strong> <?php echo $po['supplier_name']; ?></p>
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Material</th>
                <th>Ordered</th>
                <th>Received so far</th>
                <th>Receiving Now</th>
            </tr>
        </thead>
        <tbody>
            <?php while($item = mysqli_fetch_assoc($items_query)): 
                $remaining = $item['quantity_ordered'] - $item['quantity_received'];
            ?>
            <tr>
                <td>
                    <?php echo htmlspecialchars($item['material_name']); ?>
                    <input type="hidden" name="item_id[]" value="<?php echo $item['id']; ?>">
                </td>
                <td><?php echo $item['quantity_ordered'] . ' ' . $item['unit']; ?></td>
                <td><?php echo $item['quantity_received'] . ' ' . $item['unit']; ?></td>
                <td>
                    <input type="number" name="quantity_received[]" class="form-control form-control-sm" step="0.01" min="0" max="<?php echo $remaining; ?>" placeholder="0.00">
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Receive Stock</button>
    </div>
</form>
<?php
} else {
    // Placeholder for a full details view if needed later
    echo "<h4>Details for PO #{$po['po_number']}</h4>";
    echo "<p>This is where full PO details would go.</p>";
}

?>
