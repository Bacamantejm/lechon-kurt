<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);

// Handle Add/Update Material
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_material'])) {
        $name = trim($_POST['name']);
        $unit = trim($_POST['unit']);
        $stock = floatval($_POST['current_stock']);
        $min = floatval($_POST['min_level']);
        $cost = floatval($_POST['cost_per_unit']);
        $id = isset($_POST['material_id']) ? intval($_POST['material_id']) : 0;

        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE materials SET name=?, unit=?, current_stock=?, min_level=?, cost_per_unit=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "ssdddi", $name, $unit, $stock, $min, $cost, $id);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO materials (name, unit, current_stock, min_level, cost_per_unit) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "ssddd", $name, $unit, $stock, $min, $cost);
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Material saved successfully.";
        } else {
            $_SESSION['error'] = "Error saving material.";
        }
        mysqli_stmt_close($stmt);
        header("Location: materials.php");
        exit;
    }
    
    // Handle Delete
    if (isset($_POST['delete_material'])) {
        $id = intval($_POST['material_id']);
        mysqli_query($conn, "DELETE FROM materials WHERE id=$id");
        $_SESSION['success'] = "Material deleted.";
        header("Location: materials.php");
        exit;
    }
}

$materials = mysqli_query($conn, "SELECT * FROM materials ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materials Management - MRP</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Raw Materials (Inventory)</h1>
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

                <div class="section-header">
                    <h2>Material List</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#materialModal" onclick="resetForm()">
                        <i class="fas fa-plus"></i> Add Material
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Stock</th>
                                <th>Unit</th>
                                <th>Cost/Unit</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = mysqli_fetch_assoc($materials)): 
                                $status_class = ($row['current_stock'] <= $row['min_level']) ? 'badge-danger' : 'badge-success';
                                $status_text = ($row['current_stock'] <= $row['min_level']) ? 'Low Stock' : 'Good';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo number_format($row['current_stock'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['unit']); ?></td>
                                <td>₱<?php echo number_format($row['cost_per_unit'], 2); ?></td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                <td>
                                    <button class="btn-icon" title="Request Stock" onclick="window.location.href='mrp.php?tab=requisitions'"><i class="fas fa-cart-plus"></i></button>
                                    <button class="btn-icon" onclick='editMaterial(<?php echo json_encode($row); ?>)'><i class="fas fa-edit"></i></button>
                                    <form method="POST" style="display:inline" data-sw-confirm="1" data-sw-confirm-title="Delete material?" data-sw-confirm-text="This material record will be permanently removed." data-sw-confirm-confirm-text="Yes, delete">
                                        <input type="hidden" name="material_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="delete_material" class="btn-icon btn-icon-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Material Modal -->
    <div class="modal fade modern-form-modal" id="materialModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <div class="modal-header-icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div>
                            <h5 class="modal-title" id="modalTitle">Add Material</h5>
                            <p class="modal-subtitle">Configure raw inventory items, supplier unit pricing, and low-level reorder alerts.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="material_id" id="material_id">
                        
                        <!-- Section 1: Item Details -->
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <span class="form-section-title"><i class="fas fa-tag"></i> Material Specification</span>
                                <span class="form-req-pill">Required</span>
                            </div>
                            <div class="form-group-modern">
                                <label class="form-label-modern">Material Name <span class="form-req-star">*</span></label>
                                <div class="form-input-wrap">
                                    <i class="fas fa-box-open form-input-icon"></i>
                                    <input type="text" name="name" id="name" class="form-control" placeholder="e.g. Whole Pig (Live/Dressed), Lemongrass" required>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Unit of Measure <span class="form-req-star">*</span></label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-balance-scale form-input-icon"></i>
                                            <input type="text" name="unit" id="unit" class="form-control" placeholder="e.g. kg, heads, bundles" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Cost per Unit (PHP) <span class="form-req-star">*</span></label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-peso-sign form-input-icon"></i>
                                            <input type="number" step="0.01" name="cost_per_unit" id="cost_per_unit" class="form-control" placeholder="0.00" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: Stock Allocation -->
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <span class="form-section-title"><i class="fas fa-cubes"></i> Stock & Thresholds</span>
                                <span class="form-req-pill">Required</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Current Stock <span class="form-req-star">*</span></label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-layer-group form-input-icon"></i>
                                            <input type="number" step="0.01" name="current_stock" id="current_stock" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Min Alert Level <span class="form-req-star">*</span></label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-bell form-input-icon"></i>
                                            <input type="number" step="0.01" name="min_level" id="min_level" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="save_material" class="btn-modal-primary"><i class="fas fa-save"></i> Save Material</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function resetForm() {
            document.getElementById('material_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('unit').value = '';
            document.getElementById('current_stock').value = '0';
            document.getElementById('min_level').value = '10';
            document.getElementById('cost_per_unit').value = '0';
            document.getElementById('modalTitle').innerText = 'Add Material';
        }
        function editMaterial(data) {
            document.getElementById('material_id').value = data.id;
            document.getElementById('name').value = data.name;
            document.getElementById('unit').value = data.unit;
            document.getElementById('current_stock').value = data.current_stock;
            document.getElementById('min_level').value = data.min_level;
            document.getElementById('cost_per_unit').value = data.cost_per_unit;
            document.getElementById('modalTitle').innerText = 'Edit Material';
            new bootstrap.Modal(document.getElementById('materialModal')).show();
        }
    </script>
</body>
</html>
