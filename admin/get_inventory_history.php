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
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

// Get product info
$product_query = "SELECT p.name FROM products p WHERE p.id = ?" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "");
$stmt = mysqli_prepare($conn, $product_query);
if ($seller_scope_id !== null) {
    mysqli_stmt_bind_param($stmt, "ii", $product_id, $seller_scope_id);
} else {
    mysqli_stmt_bind_param($stmt, "i", $product_id);
}
mysqli_stmt_execute($stmt);
$product_result = mysqli_stmt_get_result($stmt);
$product = mysqli_fetch_assoc($product_result);
mysqli_stmt_close($stmt);

if (!$product) {
    die("Product not found");
}

// Get inventory history
$history_query = "SELECT ih.*, u.full_name as admin_name 
                  FROM inventory_history ih
                  LEFT JOIN users u ON ih.admin_id = u.id
                  WHERE ih.product_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT 50";
$stmt = mysqli_prepare($conn, $history_query);
mysqli_stmt_bind_param($stmt, "i", $product_id);
mysqli_stmt_execute($stmt);
$history_result = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);
?>

<div class="inventory-history">
    <h5><?php echo htmlspecialchars($product['name']); ?> - History</h5>
    
    <div class="table-responsive">
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Previous Stock</th>
                    <th>New Stock</th>
                    <th>Notes</th>
                    <th>Admin</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $type_colors = [
                    'received' => '<span class="type-badge type-received">Received</span>',
                    'add' => '<span class="type-badge type-add">Added</span>',
                    'reduce' => '<span class="type-badge type-reduce">Sold</span>',
                    'damage' => '<span class="type-badge type-damage">Damage</span>'
                ];
                
                if (mysqli_num_rows($history_result) > 0) {
                    while ($record = mysqli_fetch_assoc($history_result)) {
                        $type_badge = $type_colors[$record['adjustment_type']] ?? $record['adjustment_type'];
                        $admin_name = $record['admin_name'] ?? 'System';
                        
                        echo "
                        <tr>
                            <td>" . date('M d, Y H:i', strtotime($record['created_at'])) . "</td>
                            <td>$type_badge</td>
                            <td>{$record['quantity_changed']}</td>
                            <td>{$record['previous_stock']}</td>
                            <td><strong>{$record['new_stock']}</strong></td>
                            <td>{$record['notes']}</td>
                            <td>" . htmlspecialchars($admin_name) . "</td>
                        </tr>
                        ";
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-center text-muted'>No history records</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.inventory-history {
    padding: 10px 0;
}
.inventory-history h5 {
    margin: 0 0 15px 0;
    font-size: 16px;
    font-weight: 600;
}
.history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.history-table thead {
    background-color: #f5f5f5;
    border-bottom: 2px solid #ddd;
}
.history-table th {
    padding: 10px;
    text-align: left;
    font-weight: 600;
    color: #333;
}
.history-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #eee;
}
.history-table tbody tr:hover {
    background-color: #f9f9f9;
}
.type-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
}
.type-received {
    background-color: #d4edda;
    color: #155724;
}
.type-add {
    background-color: #d1ecf1;
    color: #0c5460;
}
.type-reduce {
    background-color: #fff3cd;
    color: #856404;
}
.type-damage {
    background-color: #f8d7da;
    color: #721c24;
}
.text-center {
    text-align: center;
}
.text-muted {
    color: #999;
}
</style>
