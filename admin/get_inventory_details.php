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
    <div class="product-info">
        <h4><?php echo htmlspecialchars($product['name']); ?></h4>
        <p><strong>Product ID:</strong> <?php echo htmlspecialchars($product['product_id']); ?></p>
        <p><strong>Price:</strong> ₱<?php echo number_format($product['price'], 2); ?></p>
    </div>
    
    <div class="stock-info">
        <div class="stock-row">
            <span>Date:</span>
            <strong><?php echo date('M d, Y', strtotime($date)); ?></strong>
        </div>
        <div class="stock-row">
            <span>Current Stock:</span>
            <strong><?php echo $product['current_stock']; ?> units</strong>
        </div>
        <div class="stock-row">
            <span>Minimum Level:</span>
            <strong><?php echo $product['min_stock_level']; ?> units</strong>
        </div>
    </div>
    
    <form method="POST" action="inventory.php" class="adjustment-form">
        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
        <input type="hidden" name="inventory_date" value="<?php echo $date; ?>">
        
        <div class="form-group">
            <label>Adjustment Type</label>
            <select name="adjustment_type" class="form-select" required>
                <option value="">Select type...</option>
                <option value="received">Stock Received</option>
                <option value="add">Add Stock</option>
                <option value="reduce">Reduce Stock (Sold)</option>
                <option value="damage">Damage/Loss</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Quantity</label>
            <input type="number" name="quantity" class="form-control" min="1" required>
        </div>
        
        <div class="form-group">
            <label>Notes (Optional)</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Enter adjustment notes..."></textarea>
        </div>
        
        <button type="submit" name="adjust_stock" value="1" class="btn btn-primary w-100">
            <i class="fas fa-check"></i> Adjust Stock
        </button>
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
