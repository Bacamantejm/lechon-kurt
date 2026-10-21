<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
requirePermission('inventory.view');

if (!isset($_GET['id'])) {
    die("Invalid product");
}

$product_id = intval($_GET['id']);
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

// Get product and inventory info
$query = "SELECT p.id, p.product_id, p.name, p.price, 
                 COALESCE(i.current_stock, 0) as current_stock,
                 COALESCE(i.min_stock_level, 5) as min_stock_level
          FROM products p
          LEFT JOIN inventory i ON p.id = i.product_id AND i.inventory_date = ?
          WHERE p.id = ?" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "");
$stmt = mysqli_prepare($conn, $query);
if ($seller_scope_id !== null) {
    mysqli_stmt_bind_param($stmt, "sii", $date, $product_id, $seller_scope_id);
} else {
    mysqli_stmt_bind_param($stmt, "si", $date, $product_id);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$product = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$product) {
    die("Product not found");
}
?>

<div class="inventory-details">
    <!-- Section 1: Item & Current Stock Status -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <span class="form-section-title"><i class="fas fa-box"></i> Product Overview</span>
            <span class="form-req-pill">Active</span>
        </div>
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h6 class="fw-bold mb-1" style="color: #101828; font-size: 15px;"><?php echo htmlspecialchars($product['name']); ?></h6>
                <div class="text-muted small">Code: <?php echo htmlspecialchars($product['product_id']); ?> &bull; Unit Price: ₱<?php echo number_format($product['price'], 2); ?></div>
            </div>
            <span class="badge bg-light text-dark border px-2 py-1"><?php echo date('M d, Y', strtotime($date)); ?></span>
        </div>
        <div class="row g-2 pt-2 border-top mt-2">
            <div class="col-6">
                <div class="p-2 rounded bg-light">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">CURRENT ON-HAND</span>
                    <strong style="font-size: 16px; color: #101828;"><?php echo $product['current_stock']; ?> units</strong>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 rounded bg-light">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">SAFETY MINIMUM</span>
                    <strong style="font-size: 16px; color: #b3261e;"><?php echo $product['min_stock_level']; ?> units</strong>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Section 2: Adjustment Details -->
    <form method="POST" action="inventory.php" class="adjustment-form">
        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
        <input type="hidden" name="inventory_date" value="<?php echo $date; ?>">
        
        <div class="form-section-card mb-3">
            <div class="form-section-head">
                <span class="form-section-title"><i class="fas fa-sliders-h"></i> Adjustment Details</span>
                <span class="form-req-pill">Required</span>
            </div>
            
            <div class="form-group-modern">
                <label class="form-label-modern">Adjustment Type <span class="form-req-star">*</span></label>
                <div class="form-input-wrap">
                    <i class="fas fa-exchange-alt form-input-icon"></i>
                    <select name="adjustment_type" class="form-select" required>
                        <option value="">Select type...</option>
                        <option value="received">Stock Received (+)</option>
                        <option value="add">Manual Add Stock (+)</option>
                        <option value="reduce">Manual Reduce / Sold (-)</option>
                        <option value="damage">Damage / Spoilage / Loss (-)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group-modern">
                <label class="form-label-modern">Quantity <span class="form-req-star">*</span></label>
                <div class="form-input-wrap">
                    <i class="fas fa-cubes form-input-icon"></i>
                    <input type="number" name="quantity" class="form-control" min="1" placeholder="Enter quantity to adjust" required>
                </div>
            </div>
            
            <div class="form-group-modern">
                <label class="form-label-modern">Audit Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Supplier delivery batch #124 or damaged during storage"></textarea>
            </div>
        </div>
        
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="adjust_stock" value="1" class="btn-modal-primary">
                <i class="fas fa-check"></i> Save Stock Adjustment
            </button>
        </div>
    </form>
</div>

<style>
.inventory-details {
    padding: 10px 0;
}
.product-info {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #eee;
}
.product-info h4 {
    margin: 0 0 10px 0;
    font-size: 18px;
}
.product-info p {
    margin: 5px 0;
    font-size: 13px;
}
.stock-info {
    background-color: #f5f5f5;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}
.stock-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    font-size: 13px;
    border-bottom: 1px solid #ddd;
}
.stock-row:last-child {
    border-bottom: none;
}
.adjustment-form {
    margin-top: 20px;
}
.form-group {
    margin-bottom: 15px;
}
.form-group label {
    font-weight: 600;
    margin-bottom: 8px;
    display: block;
    font-size: 13px;
}
.form-select, .form-control {
    font-size: 13px;
    padding: 8px 12px;
    border-radius: 4px;
    border: 1px solid #ddd;
}
.btn {
    font-size: 13px;
    padding: 10px 15px;
    border-radius: 4px;
}
</style>
