<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Ingredient
    if (isset($_POST['add_bom_item'])) {
        $product_id = intval($_POST['product_id']);
        $material_id = intval($_POST['material_id']);
        $qty = floatval($_POST['quantity']);
        
        $stmt = mysqli_prepare($conn, "INSERT INTO bill_of_materials (product_id, material_id, quantity_needed) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iid", $product_id, $material_id, $qty);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header("Location: bom.php?product_id=$product_id");
        exit;
    }
    
    // Delete Ingredient
    if (isset($_POST['delete_bom_item'])) {
        $id = intval($_POST['bom_id']);
        $pid = intval($_POST['product_id']);
        mysqli_query($conn, "DELETE FROM bill_of_materials WHERE id=$id");
        header("Location: bom.php?product_id=$pid");
        exit;
    }

    // Update Labor Cost
    if (isset($_POST['update_labor'])) {
        $pid = intval($_POST['product_id']);
        $labor_cost = floatval($_POST['labor_cost']);
        
        $stmt = mysqli_prepare($conn, "UPDATE products SET labor_cost = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "di", $labor_cost, $pid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['success'] = "Labor cost updated successfully.";
        header("Location: bom.php?product_id=$pid");
        exit;
    }
}

$selected_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

// Fetch Products
$products = mysqli_query($conn, "SELECT id, name FROM products ORDER BY name");

// Fetch Materials for Dropdown
$materials = mysqli_query($conn, "SELECT id, name, unit, cost_per_unit FROM materials ORDER BY name");
$materials_arr = [];
while($m = mysqli_fetch_assoc($materials)) $materials_arr[] = $m;

// Fetch BOM and Product Details
$bom_items = [];
$product_details = null;
$total_material_cost = 0;

if ($selected_product_id > 0) {
    // Get Product Info
    $prod_query = mysqli_query($conn, "SELECT * FROM products WHERE id = $selected_product_id");
    $product_details = mysqli_fetch_assoc($prod_query);

    // Get BOM Items with Cost calculation
    $bom_query = "SELECT b.*, m.name as material_name, m.unit, m.cost_per_unit 
                  FROM bill_of_materials b 
                  JOIN materials m ON b.material_id = m.id 
                  WHERE b.product_id = $selected_product_id";
    $res = mysqli_query($conn, $bom_query);
    while($row = mysqli_fetch_assoc($res)) {
        $row['item_cost'] = $row['quantity_needed'] * $row['cost_per_unit'];
        $total_material_cost += $row['item_cost'];
        $bom_items[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bill of Materials & Costing - MRP</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .cost-card { background: #f8f9fa; border-left: 4px solid #0d6efd; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .cost-value { font-size: 1.25rem; font-weight: bold; color: #212529; }
        .cost-label { font-size: 0.875rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .profit-positive { color: #198754; }
        .profit-negative { color: #dc3545; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Bill of Materials & Costing</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>

                <!-- Product Selector -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET">
                            <label class="fw-bold mb-2">Select Product to Configure:</label>
                            <div class="d-flex gap-2">
                                <select name="product_id" class="form-select" onchange="this.form.submit()">
                                    <option value="">-- Select Product --</option>
                                    <?php 
                                    mysqli_data_seek($products, 0);
                                    while($p = mysqli_fetch_assoc($products)): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $selected_product_id == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($selected_product_id > 0 && $product_details): 
                    $labor_cost = floatval($product_details['labor_cost']);
                    $total_production_cost = $total_material_cost + $labor_cost;
                    $selling_price = floatval($product_details['price']);
                    $profit = $selling_price - $total_production_cost;
                    $margin = ($selling_price > 0) ? ($profit / $selling_price) * 100 : 0;
                ?>
                
                <!-- Cost Analysis Dashboard -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="cost-card" style="border-color: #0dcaf0;">
                            <div class="cost-label">Material Cost</div>
                            <div class="cost-value">₱<?php echo number_format($total_material_cost, 2); ?></div>
                            <small class="text-muted">Sum of ingredients</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="cost-card" style="border-color: #ffc107;">
                            <div class="cost-label">Labor Cost</div>
                            <div class="cost-value">₱<?php echo number_format($labor_cost, 2); ?></div>
                            <small class="text-muted">
                                <a href="#" data-bs-toggle="modal" data-bs-target="#laborModal">Edit Labor Cost</a>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="cost-card" style="border-color: #0d6efd;">
                            <div class="cost-label">Total Production Cost</div>
                            <div class="cost-value">₱<?php echo number_format($total_production_cost, 2); ?></div>
                            <small class="text-muted">Materials + Labor</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="cost-card" style="border-color: <?php echo $profit >= 0 ? '#198754' : '#dc3545'; ?>;">
                            <div class="cost-label">Est. Profit / Unit</div>
                            <div class="cost-value <?php echo $profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                ₱<?php echo number_format($profit, 2); ?>
                            </div>
                            <small class="text-muted">Selling Price: ₱<?php echo number_format($selling_price, 2); ?> (<?php echo number_format($margin, 1); ?>% Margin)</small>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Ingredients Table -->
                    <div class="col-md-8">
                        <div class="section-header">
                            <h3>Ingredients & Materials</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th>Qty Needed</th>
                                        <th>Unit Cost</th>
                                        <th>Subtotal</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($bom_items)): ?>
                                        <tr><td colspan="5" class="text-center">No materials defined yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($bom_items as $item): ?>
                                        <tr>
                                            <td>
                                                <a href="mrp.php?tab=purchase_orders&material_id=<?php echo $item['material_id']; ?>"><?php echo htmlspecialchars($item['material_name']); ?></a>
                                            </td>
                                            <td><?php echo $item['quantity_needed'] . ' ' . $item['unit']; ?></td>
                                            <td>₱<?php echo number_format($item['cost_per_unit'], 2); ?></td>
                                            <td><strong>₱<?php echo number_format($item['item_cost'], 2); ?></strong></td>
                                            <td>
                                                <form method="POST" style="display:inline" data-sw-confirm="1" data-sw-confirm-title="Remove ingredient?" data-sw-confirm-text="This ingredient will be removed from the bill of materials." data-sw-confirm-confirm-text="Yes, remove">
                                                    <input type="hidden" name="bom_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                                                    <button type="submit" name="delete_bom_item" class="btn-icon btn-icon-danger"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add Ingredient Form -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">Add Ingredient</div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                                    <input type="hidden" name="add_bom_item" value="1">
                                    <div class="mb-3">
                                        <label>Material</label>
                                        <select name="material_id" class="form-select" required>
                                            <?php foreach($materials_arr as $m): ?>
                                                <option value="<?php echo $m['id']; ?>">
                                                    <?php echo htmlspecialchars($m['name']) . " (" . $m['unit'] . ") - ₱" . $m['cost_per_unit']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label>Quantity Needed</label>
                                        <input type="number" step="0.01" name="quantity" class="form-control" required placeholder="e.g. 0.5">
                                    </div>
                                    <button type="submit" class="btn btn-success w-100">Add to BOM</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Labor Cost Modal -->
    <div class="modal fade" id="laborModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Labor Cost</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                        <input type="hidden" name="update_labor" value="1">
                        <div class="mb-3">
                            <label>Labor Cost per Unit (₱)</label>
                            <input type="number" step="0.01" name="labor_cost" class="form-control" value="<?php echo isset($labor_cost) ? $labor_cost : '0.00'; ?>" required>
                            <small class="text-muted">Enter the estimated labor cost to produce one unit of this product.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>
