<?php
/**
 * shopping_list.php
 * Mobile-friendly and printable shopping list for shop owners self-procuring stock at local markets.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/IngredientStockManager.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
IngredientStockManager::ensureProcurementSchema($conn);

// Fetch low stock items for this store
$query = "
    SELECT m.id, m.name, m.unit, m.cost_per_unit,
           COALESCE(smi.current_stock, m.current_stock, 0) as current_stock,
           COALESCE(smi.reorder_level, m.min_level, 10) as reorder_level
    FROM materials m
    LEFT JOIN store_material_inventory smi ON smi.material_id = m.id AND smi.store_id = ?
    ORDER BY (COALESCE(smi.current_stock, m.current_stock, 0) <= COALESCE(smi.reorder_level, m.min_level, 10)) DESC, m.name ASC
";

$stmt = mysqli_prepare($conn, $query);
$items = [];
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $items[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$preselected_ids = isset($_GET['items']) ? explode(',', $_GET['items']) : [];
$preselected_ids = array_map('intval', $preselected_ids);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Market Shopping List | Lechon Delights</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8f9fa;
            color: #101828;
            padding-bottom: 80px;
        }
        .shopping-header {
            background: #ffffff;
            border-bottom: 1px solid #eaecf0;
            padding: 16px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .item-row {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 10px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .item-row.checked {
            background: #f2f4f7;
            border-color: #d0d5dd;
            opacity: 0.65;
        }
        .item-row.checked .item-name {
            text-decoration: line-through;
            color: #667085;
        }
        .item-check {
            width: 24px;
            height: 24px;
            accent-color: #b3261e;
            cursor: pointer;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff; }
            .shopping-header { border-bottom: 2px solid #000; }
        }
    </style>
</head>
<body>

<div class="shopping-header">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <h4 style="font-family:'Outfit',sans-serif; font-weight:800; margin:0; color:#b3261e;">
                <i class="fas fa-cart-shopping me-2"></i> Market Shopping List
            </h4>
            <div style="font-size:0.82rem; color:#667085;">
                <?php echo date('l, F d, Y'); ?> &bull; Self-Procurement Trip
            </div>
        </div>
        <div class="d-flex gap-2 no-print">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="seller_products.php?tab=ingredients" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-arrow-left"></i> Exit
            </a>
        </div>
    </div>
</div>

<div class="container my-4">
    <div class="card p-3 mb-3 border-0 shadow-sm rounded-3 bg-white no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span style="font-size:0.9rem; color:#475467;">
                <i class="fas fa-info-circle text-primary me-1"></i> Tap any item to check it off as you purchase it at the local market.
            </span>
            <button type="button" class="btn btn-sm btn-danger fw-bold" onclick="proceedToLogPurchase()">
                <i class="fas fa-receipt me-1"></i> Done Shopping &bull; Log Receipt &amp; Restock
            </button>
        </div>
    </div>

    <div id="shopping_items_list">
        <?php foreach ($items as $item): 
            $stk = floatval($item['current_stock']);
            $reorder = floatval($item['reorder_level']);
            $is_low = $stk <= $reorder;
            $isChecked = in_array((int)$item['id'], $preselected_ids, true) || $is_low;
            $needed_qty = max(5.0, ceil($reorder * 1.5 - $stk));
        ?>
            <div class="item-row" data-id="<?php echo (int)$item['id']; ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>" data-unit="<?php echo htmlspecialchars($item['unit']); ?>" data-cost="<?php echo htmlspecialchars($item['cost_per_unit']); ?>">
                <div class="d-flex align-items-center gap-3">
                    <input type="checkbox" class="item-check" onchange="toggleItemCheck(this)" <?php echo $isChecked ? 'checked' : ''; ?>>
                    <div>
                        <div class="item-name fw-bold" style="font-size:1.05rem;">
                            <?php echo htmlspecialchars($item['name']); ?>
                        </div>
                        <div style="font-size:0.8rem; color:#667085;">
                            Current Stock: <?php echo number_format($stk, 2) . ' ' . htmlspecialchars($item['unit']); ?> 
                            &bull; Reorder Level: <?php echo number_format($reorder, 2) . ' ' . htmlspecialchars($item['unit']); ?>
                        </div>
                    </div>
                </div>
                <div class="text-end">
                    <div class="fw-bold" style="color:#b3261e; font-size:1.1rem;">
                        Target: <?php echo number_format($needed_qty, 1) . ' ' . htmlspecialchars($item['unit']); ?>
                    </div>
                    <div style="font-size:0.8rem; color:#667085;">
                        Est: ₱<?php echo number_format($item['cost_per_unit'], 2); ?> / <?php echo htmlspecialchars($item['unit']); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function toggleItemCheck(chk) {
    const row = chk.closest('.item-row');
    if (chk.checked) {
        row.classList.remove('checked');
    } else {
        row.classList.add('checked');
    }
}

function proceedToLogPurchase() {
    const checkedRows = document.querySelectorAll('.item-row input.item-check:checked');
    const selectedIds = [];
    checkedRows.forEach(ch => {
        selectedIds.push(ch.closest('.item-row').getAttribute('data-id'));
    });
    const url = 'seller_products.php?tab=ingredients&open_self_procure=1&ids=' + selectedIds.join(',');
    window.location.href = url;
}
</script>
</body>
</html>
