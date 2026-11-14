<?php
/**
 * supplier/catalog.php
 * Supplier Wholesale Catalog: Manage Unit Pricing, Packaging Units, MOQ, and Availability.
 */

$page_title = "Wholesale Catalog & Pricing | Supplier Portal";
require_once __DIR__ . '/header.php';

// Handle Catalog Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_catalog'])) {
    $catalog_entries = isset($_POST['catalog']) && is_array($_POST['catalog']) ? $_POST['catalog'] : [];

    $upsert_stmt = mysqli_prepare($conn, "
        INSERT INTO supplier_catalog (supplier_id, material_id, wholesale_price, packaging_unit, min_order_qty, lead_time_hours, is_available, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            wholesale_price = VALUES(wholesale_price),
            packaging_unit = VALUES(packaging_unit),
            min_order_qty = VALUES(min_order_qty),
            lead_time_hours = VALUES(lead_time_hours),
            is_available = VALUES(is_available),
            updated_at = NOW()
    ");

    $saved_count = 0;
    if ($upsert_stmt) {
        foreach ($catalog_entries as $mat_id => $data) {
            $m_id = intval($mat_id);
            if ($m_id <= 0) continue;

            $price = floatval($data['wholesale_price'] ?? 0);
            $pkg_unit = trim((string)($data['packaging_unit'] ?? 'kg'));
            $moq = floatval($data['min_order_qty'] ?? 1);
            $lead_hours = intval($data['lead_time_hours'] ?? 24);
            $is_avail = !empty($data['is_available']) ? 1 : 0;

            mysqli_stmt_bind_param($upsert_stmt, "iidsdii", $current_supplier_id, $m_id, $price, $pkg_unit, $moq, $lead_hours, $is_avail);
            if (mysqli_stmt_execute($upsert_stmt)) {
                $saved_count++;
            }
        }
        mysqli_stmt_close($upsert_stmt);
        $_SESSION['success'] = "Wholesale catalog updated successfully ($saved_count items saved).";
    } else {
        $_SESSION['error'] = "Failed to update catalog: " . mysqli_error($conn);
    }

    header("Location: catalog.php");
    exit;
}

// Fetch all system materials joined with this supplier's catalog pricing
$query = "
    SELECT m.id AS material_id, m.name AS material_name, m.unit AS default_unit, m.cost_per_unit AS baseline_cost,
           sc.id AS catalog_id,
           sc.wholesale_price,
           sc.packaging_unit,
           sc.min_order_qty,
           sc.lead_time_hours,
           sc.is_available,
           sc.updated_at AS last_priced_at
    FROM materials m
    LEFT JOIN supplier_catalog sc 
        ON sc.material_id = m.id AND sc.supplier_id = ?
    ORDER BY m.name ASC
";

$stmt = mysqli_prepare($conn, $query);
$materials_catalog = [];
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $current_supplier_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $materials_catalog[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>

<div class="container-fluid px-4">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 style="font-family:'Outfit',sans-serif; font-weight:800; margin:0; font-size:1.75rem; color:#101828;">
                Wholesale Ingredient Catalog
            </h2>
            <p style="color:#667085; margin:4px 0 0 0; font-size:0.95rem;">
                Configure wholesale unit pricing, packaging units, and minimum order quantities offered to shop owners.
            </p>
        </div>
        <div>
            <button type="submit" form="catalogForm" class="btn btn-system-primary">
                <i class="fas fa-save"></i> Save All Price Updates
            </button>
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

    <!-- Explanatory Callout Card -->
    <div class="system-card p-3 mb-4" style="background:#f8f9fa; border:1px solid #eaecf0;">
        <div class="d-flex align-items-start gap-3">
            <div style="width:36px; height:36px; border-radius:8px; background:#eff8ff; color:#175cd3; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="fas fa-info-circle"></i>
            </div>
            <div style="font-size:0.9rem; color:#475467; line-height:1.5;">
                <strong style="color:#101828;">How Pricing Works in Procurement:</strong> 
                Prices set below are auto-pulled when shop owners draft a procurement refill request. You can also adjust prices or quantities dynamically upon reviewing any pending request before you accept and lock it in.
            </div>
        </div>
    </div>

    <!-- Catalog Form & Table -->
    <form id="catalogForm" method="POST" action="catalog.php">
        <input type="hidden" name="save_catalog" value="1">
        
        <div class="system-card p-0 overflow-hidden">
            <div class="table-responsive">
                <table class="table align-middle table-hover mb-0">
                    <thead>
                        <tr style="border-bottom: 2px solid #eaecf0; color:#475467; font-size:0.82rem; text-transform:uppercase; letter-spacing:0.5px; background:#f8f9fa;">
                            <th style="padding-left:24px;">Ingredient Name</th>
                            <th>Default Unit</th>
                            <th>Wholesale Unit Price (₱)</th>
                            <th>Wholesale Packaging Unit</th>
                            <th>Min. Order Qty (MOQ)</th>
                            <th>Lead Time</th>
                            <th>In Stock</th>
                            <th style="padding-right:24px;" class="text-end">Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($materials_catalog)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    No raw ingredients registered yet. Shop owners can define materials when configuring product recipes.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($materials_catalog as $m): 
                                $mat_id = (int)$m['material_id'];
                                $wholesale_price = $m['wholesale_price'] !== null ? floatval($m['wholesale_price']) : floatval($m['baseline_cost']);
                                $packaging_unit = !empty($m['packaging_unit']) ? $m['packaging_unit'] : $m['default_unit'];
                                $moq = $m['min_order_qty'] !== null ? floatval($m['min_order_qty']) : 1.0;
                                $lead_time = $m['lead_time_hours'] !== null ? (int)$m['lead_time_hours'] : 24;
                                $is_avail = ($m['is_available'] !== null) ? ((int)$m['is_available'] === 1) : true;
                            ?>
                                <tr>
                                    <td style="padding-left:24px;">
                                        <div style="font-weight:700; color:#101828; font-size:0.95rem;">
                                            <i class="fas fa-cubes text-danger me-1"></i>
                                            <?php echo htmlspecialchars($m['material_name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo htmlspecialchars($m['default_unit']); ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 150px;">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" step="0.01" min="0" 
                                                   name="catalog[<?php echo $mat_id; ?>][wholesale_price]" 
                                                   value="<?php echo htmlspecialchars(number_format($wholesale_price, 2, '.', '')); ?>" 
                                                   class="form-control text-end fw-bold" required>
                                        </div>
                                    </td>
                                    <td style="max-width: 140px;">
                                        <input type="text" 
                                               name="catalog[<?php echo $mat_id; ?>][packaging_unit]" 
                                               value="<?php echo htmlspecialchars($packaging_unit); ?>" 
                                               placeholder="e.g. kg, sack, crate" 
                                               class="form-control form-control-sm" required>
                                    </td>
                                    <td style="max-width: 120px;">
                                        <input type="number" step="0.1" min="0.1" 
                                               name="catalog[<?php echo $mat_id; ?>][min_order_qty]" 
                                               value="<?php echo htmlspecialchars((string)$moq); ?>" 
                                               class="form-control form-control-sm text-end" required>
                                    </td>
                                    <td style="max-width: 120px;">
                                        <div class="input-group input-group-sm">
                                            <input type="number" min="1" max="168" 
                                                   name="catalog[<?php echo $mat_id; ?>][lead_time_hours]" 
                                                   value="<?php echo htmlspecialchars((string)$lead_time); ?>" 
                                                   class="form-control text-end">
                                            <span class="input-group-text">hrs</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" 
                                                   id="avail_<?php echo $mat_id; ?>" 
                                                   name="catalog[<?php echo $mat_id; ?>][is_available]" 
                                                   value="1" <?php echo $is_avail ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="avail_<?php echo $mat_id; ?>">
                                                <?php echo $is_avail ? 'Available' : 'Out of Stock'; ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td style="padding-right:24px;" class="text-end text-muted small">
                                        <?php if (!empty($m['last_priced_at'])): ?>
                                            <?php echo date('M d, Y', strtotime($m['last_priced_at'])); ?>
                                        <?php else: ?>
                                            <span class="text-secondary">Default</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-3 bg-light border-top d-flex justify-content-end">
                <button type="submit" class="btn btn-system-primary">
                    <i class="fas fa-save"></i> Save All Price Updates
                </button>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
