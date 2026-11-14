<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/DSSInsightsService.php';

checkAdminAccess();
requirePermission('inventory.view');
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$default_inventory_seed_stock = 10;

function inventoryPartnerOwnsProduct($conn, $product_id, $seller_scope_id) {
    if ($seller_scope_id === null) {
        return true;
    }

    $product_id = (int)$product_id;
    if ($product_id <= 0) {
        return false;
    }

    $scope_stmt = mysqli_prepare($conn, "SELECT id FROM products WHERE id = ? AND seller_id = ? LIMIT 1");
    if (!$scope_stmt) {
        return false;
    }
    mysqli_stmt_bind_param($scope_stmt, "ii", $product_id, $seller_scope_id);
    mysqli_stmt_execute($scope_stmt);
    $scope_result = mysqli_stmt_get_result($scope_stmt);
    $owned = $scope_result && mysqli_fetch_assoc($scope_result);
    mysqli_stmt_close($scope_stmt);

    return (bool)$owned;
}

/**
 * Safe helper to keep DSS widgets non-blocking if optional tables are missing.
 */
function safeInventoryDssCall($callback, $default) {
    try {
        return $callback();
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Keep inventory history enum compatible with newer action labels used in this module.
 */
function ensureInventoryHistoryActionTypes($conn) {
    $result = mysqli_query($conn, "SHOW COLUMNS FROM inventory_history LIKE 'adjustment_type'");
    if (!$result || mysqli_num_rows($result) === 0) {
        return;
    }

    $row = mysqli_fetch_assoc($result);
    $type = strtolower($row['Type'] ?? '');
    $required_values = ['received', 'add', 'reduce', 'damage', 'correction', 'created', 'restored', 'archived', 'automation'];
    $missing = false;

    foreach ($required_values as $value) {
        if (strpos($type, "'" . $value . "'") === false) {
            $missing = true;
            break;
        }
    }

    if ($missing) {
        mysqli_query(
            $conn,
            "ALTER TABLE inventory_history MODIFY adjustment_type ENUM('received','add','reduce','damage','correction','created','restored','archived','automation') NOT NULL DEFAULT 'correction'"
        );
    }
}

/**
 * One-click inventory automation:
 * - auto-create missing inventory rows (all non-archived products for today, active products for other dates)
 * - if target date is today, set all product inventory rows to 10 units
 * - otherwise normalize negative stocks to 0 for target date
 * - if target date is today, sync master product stock and active status
 * - send low-stock notification to admins (deduplicated by exact message)
 */
function runInventoryAutomation($conn, $target_date, $admin_id) {
    $summary = [
        'created_missing' => 0,
        'today_seeded' => 0,
        'normalized_negative' => 0,
        'stock_synced' => 0,
        'activated' => 0,
        'deactivated' => 0,
        'alerts_sent' => 0,
        'master_sync_skipped' => false,
        'errors' => []
    ];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
        $summary['errors'][] = 'Invalid automation date.';
        return $summary;
    }

    $today = date('Y-m-d');

    // 1) Auto-create missing daily inventory rows for active products.
    // If target date is today, seed newly created rows at 10 units.
    $default_today_seed_stock = 10;
    if ($target_date === $today) {
        $create_missing_sql = "
            INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated)
            SELECT
                p.id,
                ?,
                ?,
                GREATEST(COALESCE(last_snapshot.min_stock_level, 5), 1),
                NOW()
            FROM products p
            LEFT JOIN inventory existing
                ON existing.product_id = p.id
               AND existing.inventory_date = ?
               AND existing.is_archived = 0
            LEFT JOIN (
                SELECT i.product_id, i.min_stock_level
                FROM inventory i
                INNER JOIN (
                    SELECT product_id, MAX(id) AS max_id
                    FROM inventory
                    WHERE is_archived = 0
                    GROUP BY product_id
                ) latest ON latest.max_id = i.id
                WHERE i.is_archived = 0
            ) last_snapshot ON last_snapshot.product_id = p.id
            WHERE p.is_archived = 0
              AND existing.id IS NULL
        ";
        $create_stmt = mysqli_prepare($conn, $create_missing_sql);
        if ($create_stmt) {
            mysqli_stmt_bind_param($create_stmt, "sis", $target_date, $default_today_seed_stock, $target_date);
        }
    } else {
        $create_missing_sql = "
            INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated)
            SELECT
                p.id,
                ?,
                GREATEST(COALESCE(last_snapshot.current_stock, p.stock, 0), 0),
                GREATEST(COALESCE(last_snapshot.min_stock_level, 5), 1),
                NOW()
            FROM products p
            LEFT JOIN inventory existing
                ON existing.product_id = p.id
               AND existing.inventory_date = ?
               AND existing.is_archived = 0
            LEFT JOIN (
                SELECT i.product_id, i.current_stock, i.min_stock_level
                FROM inventory i
                INNER JOIN (
                    SELECT product_id, MAX(id) AS max_id
                    FROM inventory
                    WHERE is_archived = 0
                    GROUP BY product_id
                ) latest ON latest.max_id = i.id
                WHERE i.is_archived = 0
            ) last_snapshot ON last_snapshot.product_id = p.id
            WHERE p.is_archived = 0
              AND p.is_active = 1
              AND existing.id IS NULL
        ";
        $create_stmt = mysqli_prepare($conn, $create_missing_sql);
        if ($create_stmt) {
            mysqli_stmt_bind_param($create_stmt, "ss", $target_date, $target_date);
        }
    }

    if ($create_stmt) {
        if (mysqli_stmt_execute($create_stmt)) {
            $summary['created_missing'] = mysqli_stmt_affected_rows($create_stmt);
        } else {
            $summary['errors'][] = 'Unable to auto-create missing inventory rows.';
        }
        mysqli_stmt_close($create_stmt);
    } else {
        $summary['errors'][] = 'Unable to prepare missing inventory automation.';
    }

    // 2) If target date is today, force all product inventory rows to 10 units.
    // Otherwise, only normalize negative values to 0.
    if ($target_date === $today) {
        $seed_today_stmt = mysqli_prepare(
            $conn,
            "UPDATE inventory i
             INNER JOIN products p ON p.id = i.product_id
             SET i.current_stock = ?, i.last_updated = NOW()
             WHERE i.inventory_date = ? AND i.is_archived = 0 AND p.is_archived = 0 AND i.current_stock <> ?"
        );
        if ($seed_today_stmt) {
            mysqli_stmt_bind_param($seed_today_stmt, "isi", $default_today_seed_stock, $target_date, $default_today_seed_stock);
            if (mysqli_stmt_execute($seed_today_stmt)) {
                $summary['today_seeded'] = mysqli_stmt_affected_rows($seed_today_stmt);
            } else {
                $summary['errors'][] = 'Unable to apply 10-unit stock seeding for today.';
            }
            mysqli_stmt_close($seed_today_stmt);
        } else {
            $summary['errors'][] = 'Unable to prepare today stock seeding.';
        }
    } else {
        $normalize_stmt = mysqli_prepare(
            $conn,
            "UPDATE inventory SET current_stock = 0, last_updated = NOW() WHERE inventory_date = ? AND is_archived = 0 AND current_stock < 0"
        );
        if ($normalize_stmt) {
            mysqli_stmt_bind_param($normalize_stmt, "s", $target_date);
            if (mysqli_stmt_execute($normalize_stmt)) {
                $summary['normalized_negative'] = mysqli_stmt_affected_rows($normalize_stmt);
            } else {
                $summary['errors'][] = 'Unable to normalize negative stocks.';
            }
            mysqli_stmt_close($normalize_stmt);
        } else {
            $summary['errors'][] = 'Unable to prepare stock normalization.';
        }
    }

    // 3) Sync master products stock/visibility only for today's inventory.
    if ($target_date === $today) {
        $impact_sql = "
            SELECT
                SUM(CASE WHEN i.current_stock > 0 AND p.is_active = 0 THEN 1 ELSE 0 END) AS activated,
                SUM(CASE WHEN i.current_stock <= 0 AND p.is_active = 1 THEN 1 ELSE 0 END) AS deactivated,
                SUM(CASE WHEN p.stock <> GREATEST(i.current_stock, 0) THEN 1 ELSE 0 END) AS stock_synced
            FROM products p
            INNER JOIN inventory i
                ON i.product_id = p.id
               AND i.inventory_date = ?
               AND i.is_archived = 0
            WHERE p.is_archived = 0
        ";
        $impact_stmt = mysqli_prepare($conn, $impact_sql);
        if ($impact_stmt) {
            mysqli_stmt_bind_param($impact_stmt, "s", $target_date);
            mysqli_stmt_execute($impact_stmt);
            $impact_result = mysqli_stmt_get_result($impact_stmt);
            $impact_row = $impact_result ? mysqli_fetch_assoc($impact_result) : null;
            if ($impact_row) {
                $summary['activated'] = (int)($impact_row['activated'] ?? 0);
                $summary['deactivated'] = (int)($impact_row['deactivated'] ?? 0);
                $summary['stock_synced'] = (int)($impact_row['stock_synced'] ?? 0);
            }
            mysqli_stmt_close($impact_stmt);
        }

        $sync_sql = "
            UPDATE products p
            INNER JOIN inventory i
                ON i.product_id = p.id
               AND i.inventory_date = ?
               AND i.is_archived = 0
            SET
                p.stock = GREATEST(i.current_stock, 0),
                p.is_active = CASE WHEN i.current_stock > 0 THEN 1 ELSE 0 END
            WHERE p.is_archived = 0
              AND (
                  p.stock <> GREATEST(i.current_stock, 0)
                  OR p.is_active <> CASE WHEN i.current_stock > 0 THEN 1 ELSE 0 END
              )
        ";
        $sync_stmt = mysqli_prepare($conn, $sync_sql);
        if ($sync_stmt) {
            mysqli_stmt_bind_param($sync_stmt, "s", $target_date);
            if (!mysqli_stmt_execute($sync_stmt)) {
                $summary['errors'][] = 'Unable to sync master product stock/status.';
            }
            mysqli_stmt_close($sync_stmt);
        } else {
            $summary['errors'][] = 'Unable to prepare product sync automation.';
        }

        // 4) Notify admins once per unique low-stock message.
        $low_stock_stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS low_count
             FROM inventory i
             INNER JOIN products p ON p.id = i.product_id
             WHERE i.inventory_date = ? AND i.is_archived = 0 AND p.is_archived = 0 AND i.current_stock <= i.min_stock_level"
        );
        if ($low_stock_stmt) {
            mysqli_stmt_bind_param($low_stock_stmt, "s", $target_date);
            mysqli_stmt_execute($low_stock_stmt);
            $low_stock_result = mysqli_stmt_get_result($low_stock_stmt);
            $low_stock_row = $low_stock_result ? mysqli_fetch_assoc($low_stock_result) : null;
            $low_count = (int)($low_stock_row['low_count'] ?? 0);
            mysqli_stmt_close($low_stock_stmt);

            if ($low_count > 0 && function_exists('getAllAdminIds') && function_exists('createNotification')) {
                $admins = getAllAdminIds($conn);
                $alert_title = "Inventory Low Stock Alert";
                $alert_message = "Automation found {$low_count} low-stock item(s) for {$target_date}. Please review Inventory > Stock Management.";
                $exists_stmt = mysqli_prepare(
                    $conn,
                    "SELECT id FROM notifications WHERE user_id = ? AND type = 'inventory_low_stock_alert' AND related_type = 'inventory' AND message = ? LIMIT 1"
                );

                foreach ($admins as $uid) {
                    $already_sent = false;
                    if ($exists_stmt) {
                        mysqli_stmt_bind_param($exists_stmt, "is", $uid, $alert_message);
                        mysqli_stmt_execute($exists_stmt);
                        $exists_result = mysqli_stmt_get_result($exists_stmt);
                        $already_sent = $exists_result && mysqli_fetch_assoc($exists_result);
                    }

                    if (!$already_sent && createNotification($conn, (int)$uid, 'inventory_low_stock_alert', $alert_title, $alert_message, null, 'inventory')) {
                        $summary['alerts_sent']++;
                    }
                }

                if ($exists_stmt) {
                    mysqli_stmt_close($exists_stmt);
                }
            }
        }
    } else {
        $summary['master_sync_skipped'] = true;
    }

    return $summary;
}

/**
 * Partner-safe inventory automation (scoped to one seller only):
 * - create missing rows for selected date
 * - set selected-date rows to 10 units
 * - if selected date is today, sync product stock/is_active
 */
function runPartnerInventoryAutomation($conn, $target_date, $seller_scope_id, $admin_id) {
    $summary = [
        'created_missing' => 0,
        'today_seeded' => 0,
        'normalized_negative' => 0,
        'stock_synced' => 0,
        'activated' => 0,
        'deactivated' => 0,
        'alerts_sent' => 0,
        'master_sync_skipped' => false,
        'errors' => []
    ];

    $seller_scope_id = (int)$seller_scope_id;
    if ($seller_scope_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
        $summary['errors'][] = 'Invalid partner automation scope/date.';
        return $summary;
    }

    $seed_stock = 10;
    $today = date('Y-m-d');

    $create_sql = "
        INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated)
        SELECT
            p.id,
            ?,
            ?,
            GREATEST(COALESCE(last_snapshot.min_stock_level, 5), 1),
            NOW()
        FROM products p
        LEFT JOIN inventory existing
            ON existing.product_id = p.id
           AND existing.inventory_date = ?
           AND existing.is_archived = 0
        LEFT JOIN (
            SELECT i.product_id, i.min_stock_level
            FROM inventory i
            INNER JOIN (
                SELECT product_id, MAX(id) AS max_id
                FROM inventory
                WHERE is_archived = 0
                GROUP BY product_id
            ) latest ON latest.max_id = i.id
            WHERE i.is_archived = 0
        ) last_snapshot ON last_snapshot.product_id = p.id
        WHERE p.is_archived = 0
          AND p.seller_id = ?
          AND existing.id IS NULL
    ";
    $create_stmt = mysqli_prepare($conn, $create_sql);
    if ($create_stmt) {
        mysqli_stmt_bind_param($create_stmt, "sisi", $target_date, $seed_stock, $target_date, $seller_scope_id);
        if (mysqli_stmt_execute($create_stmt)) {
            $summary['created_missing'] = mysqli_stmt_affected_rows($create_stmt);
        } else {
            $summary['errors'][] = 'Unable to create missing scoped inventory rows.';
        }
        mysqli_stmt_close($create_stmt);
    } else {
        $summary['errors'][] = 'Unable to prepare scoped inventory creation.';
    }

    $seed_stmt = mysqli_prepare(
        $conn,
        "UPDATE inventory i
         INNER JOIN products p ON p.id = i.product_id
         SET i.current_stock = ?, i.last_updated = NOW()
         WHERE i.inventory_date = ?
           AND i.is_archived = 0
           AND p.is_archived = 0
           AND p.seller_id = ?
           AND i.current_stock <> ?"
    );
    if ($seed_stmt) {
        mysqli_stmt_bind_param($seed_stmt, "isii", $seed_stock, $target_date, $seller_scope_id, $seed_stock);
        if (mysqli_stmt_execute($seed_stmt)) {
            $summary['today_seeded'] = mysqli_stmt_affected_rows($seed_stmt);
        } else {
            $summary['errors'][] = 'Unable to seed scoped inventory rows to 10.';
        }
        mysqli_stmt_close($seed_stmt);
    } else {
        $summary['errors'][] = 'Unable to prepare scoped stock seeding.';
    }

    if ($target_date === $today) {
        $impact_stmt = mysqli_prepare(
            $conn,
            "SELECT
                SUM(CASE WHEN i.current_stock > 0 AND p.is_active = 0 THEN 1 ELSE 0 END) AS activated,
                SUM(CASE WHEN i.current_stock <= 0 AND p.is_active = 1 THEN 1 ELSE 0 END) AS deactivated,
                SUM(CASE WHEN p.stock <> GREATEST(i.current_stock, 0) THEN 1 ELSE 0 END) AS stock_synced
             FROM products p
             INNER JOIN inventory i ON i.product_id = p.id AND i.inventory_date = ? AND i.is_archived = 0
             WHERE p.is_archived = 0 AND p.seller_id = ?"
        );
        if ($impact_stmt) {
            mysqli_stmt_bind_param($impact_stmt, "si", $target_date, $seller_scope_id);
            mysqli_stmt_execute($impact_stmt);
            $impact_res = mysqli_stmt_get_result($impact_stmt);
            $impact_row = $impact_res ? mysqli_fetch_assoc($impact_res) : null;
            if ($impact_row) {
                $summary['activated'] = (int)($impact_row['activated'] ?? 0);
                $summary['deactivated'] = (int)($impact_row['deactivated'] ?? 0);
                $summary['stock_synced'] = (int)($impact_row['stock_synced'] ?? 0);
            }
            mysqli_stmt_close($impact_stmt);
        }

        $sync_stmt = mysqli_prepare(
            $conn,
            "UPDATE products p
             INNER JOIN inventory i ON i.product_id = p.id AND i.inventory_date = ? AND i.is_archived = 0
             SET p.stock = GREATEST(i.current_stock, 0),
                 p.is_active = CASE WHEN i.current_stock > 0 THEN 1 ELSE 0 END
             WHERE p.is_archived = 0
               AND p.seller_id = ?
               AND (
                   p.stock <> GREATEST(i.current_stock, 0)
                   OR p.is_active <> CASE WHEN i.current_stock > 0 THEN 1 ELSE 0 END
               )"
        );
        if ($sync_stmt) {
            mysqli_stmt_bind_param($sync_stmt, "si", $target_date, $seller_scope_id);
            if (!mysqli_stmt_execute($sync_stmt)) {
                $summary['errors'][] = 'Unable to sync scoped product stock/status.';
            }
            mysqli_stmt_close($sync_stmt);
        } else {
            $summary['errors'][] = 'Unable to prepare scoped product sync.';
        }
    } else {
        $summary['master_sync_skipped'] = true;
    }

    return $summary;
}

// Ensure is_archived column exists in inventory table
$check_inv_column = mysqli_query($conn, "SHOW COLUMNS FROM inventory LIKE 'is_archived'");
if (mysqli_num_rows($check_inv_column) == 0) {
    mysqli_query($conn, "ALTER TABLE inventory ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
}

// Ensure inventory_date column exists
$check_date_column = mysqli_query($conn, "SHOW COLUMNS FROM inventory LIKE 'inventory_date'");
if (mysqli_num_rows($check_date_column) == 0) {
    mysqli_query($conn, "ALTER TABLE inventory ADD COLUMN inventory_date DATE NOT NULL AFTER product_id");
}

// Fix indexes: Add new unique key first, then drop old one to maintain FK integrity
$check_new_idx = mysqli_query($conn, "SHOW INDEX FROM inventory WHERE Key_name = 'product_date_unique'");
if (mysqli_num_rows($check_new_idx) == 0) {
    // Check for duplicates before adding unique key
    $dup_check = mysqli_query($conn, "SELECT product_id, inventory_date, COUNT(*) as cnt FROM inventory GROUP BY product_id, inventory_date HAVING cnt > 1");
    if (mysqli_num_rows($dup_check) > 0) {
        // Handle duplicates: Keep the one with highest ID (latest)
        mysqli_query($conn, "DELETE t1 FROM inventory t1 INNER JOIN inventory t2 WHERE t1.id < t2.id AND t1.product_id = t2.product_id AND t1.inventory_date = t2.inventory_date");
    }
    mysqli_query($conn, "ALTER TABLE inventory ADD UNIQUE KEY product_date_unique (product_id, inventory_date)");
}

$check_old_idx = mysqli_query($conn, "SHOW INDEX FROM inventory WHERE Key_name = 'product_id'");
if (mysqli_num_rows($check_old_idx) > 0) {
    mysqli_query($conn, "ALTER TABLE inventory DROP INDEX product_id");
}

// Ensure newer history action types are accepted.
ensureInventoryHistoryActionTypes($conn);

// Get selected date (validated)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

// Handle Recipe Ingredients Save (strictly whole numbers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product_recipe'])) {
    $product_id = intval($_POST['product_id'] ?? 0);
    $scope_target_store = $seller_scope_id !== null ? (int)$seller_scope_id : (int)($_SESSION['user_id'] ?? 0);

    if (!inventoryPartnerOwnsProduct($conn, $product_id, $seller_scope_id)) {
        $_SESSION['error'] = "You can only manage recipes for your own store products.";
    } else {
        // Delete existing BOM records for this product
        mysqli_query($conn, "DELETE FROM bill_of_materials WHERE product_id = " . intval($product_id));

        $ins_bom = mysqli_prepare($conn, "INSERT INTO bill_of_materials (product_id, material_id, quantity_needed) VALUES (?, ?, ?)");
        if (!empty($_POST['recipe_ingredients']) && is_array($_POST['recipe_ingredients'])) {
            foreach ($_POST['recipe_ingredients'] as $mat_id => $rdata) {
                if (!empty($rdata['selected'])) {
                    $m_id = intval($mat_id);
                    // Strictly enforce whole numbers (integers >= 1)
                    $qty = intval(round(floatval($rdata['qty'] ?? 1)));
                    if ($m_id > 0 && $qty > 0 && $ins_bom) {
                        mysqli_stmt_bind_param($ins_bom, "iid", $product_id, $m_id, $qty);
                        mysqli_stmt_execute($ins_bom);
                    }
                }
            }
        }
        if ($ins_bom) mysqli_stmt_close($ins_bom);

        // Recalculate whole-number product stock capacity from available store materials
        $chk_yield = mysqli_query($conn, "
            SELECT bom.quantity_needed, 
                   COALESCE(smi.current_stock, m.current_stock, 0) as stock
            FROM bill_of_materials bom
            INNER JOIN materials m ON m.id = bom.material_id
            LEFT JOIN store_material_inventory smi ON smi.material_id = m.id AND smi.store_id = {$scope_target_store}
            WHERE bom.product_id = " . intval($product_id)
        );
        $calc_yield = PHP_INT_MAX;
        $has_items = false;
        while ($chk_yield && ($cy = mysqli_fetch_assoc($chk_yield))) {
            $has_items = true;
            $need = max(1, (int)round((float)$cy['quantity_needed']));
            $have = (int)floor((float)$cy['stock']);
            $can_make = (int)floor($have / $need);
            if ($can_make < $calc_yield) {
                $calc_yield = $can_make;
            }
        }
        if (!$has_items || $calc_yield === PHP_INT_MAX) {
            $calc_yield = 0;
        }
        $calc_yield = max(0, $calc_yield);

        // Update products table stock and availability
        $is_avail = $calc_yield > 0 ? 1 : 0;
        mysqli_query($conn, "UPDATE products SET stock = {$calc_yield}, is_available = {$is_avail} WHERE id = " . intval($product_id));

        // Update or insert today's inventory row with this calculated stock
        $today_str = date('Y-m-d');
        $chk_inv = mysqli_query($conn, "SELECT id FROM inventory WHERE product_id = {$product_id} AND inventory_date = '{$today_str}' AND is_archived = 0 LIMIT 1");
        if ($chk_inv && ($inv_r = mysqli_fetch_assoc($chk_inv))) {
            mysqli_query($conn, "UPDATE inventory SET current_stock = {$calc_yield}, last_updated = NOW() WHERE id = " . (int)$inv_r['id']);
        } else {
            mysqli_query($conn, "INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated) VALUES ({$product_id}, '{$today_str}', {$calc_yield}, 5, NOW())");
        }

        // Trigger store-wide sync
        require_once dirname(__DIR__) . '/includes/IngredientStockManager.php';
        IngredientStockManager::syncProductAvailabilityForStore($conn, $scope_target_store);

        $_SESSION['success'] = "Recipe saved! Stock quantity updated to {$calc_yield} units based on available raw ingredients.";
        header("Location: inventory.php?date=" . urlencode($selected_date));
        exit;
    }
}

// Handle Raw Ingredient Stock Adjustment / Restock (strictly whole numbers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_ingredient_stock'])) {
    $material_id = intval($_POST['material_id'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 0); // Strictly whole number
    $adj_type = $_POST['adjustment_type'] ?? 'add'; // 'add' or 'set'
    $scope_target_store = $seller_scope_id !== null ? (int)$seller_scope_id : (int)($_SESSION['user_id'] ?? 0);

    // Get current stock
    $cur_q = mysqli_query($conn, "SELECT current_stock FROM store_material_inventory WHERE store_id = {$scope_target_store} AND material_id = {$material_id} LIMIT 1");
    $cur_stock = 0;
    if ($cur_q && ($crow = mysqli_fetch_assoc($cur_q))) {
        $cur_stock = (int)floor((float)$crow['current_stock']);
    } else {
        $m_q = mysqli_query($conn, "SELECT current_stock FROM materials WHERE id = {$material_id} LIMIT 1");
        if ($m_q && ($mrow = mysqli_fetch_assoc($m_q))) {
            $cur_stock = (int)floor((float)$mrow['current_stock']);
        }
    }

    $new_stock = $adj_type === 'set' ? max(0, $quantity) : max(0, $cur_stock + $quantity);

    // Update store_material_inventory
    mysqli_query($conn, "
        INSERT INTO store_material_inventory (store_id, material_id, current_stock, unit, last_restocked_at)
        SELECT {$scope_target_store}, id, {$new_stock}, unit, NOW() FROM materials WHERE id = {$material_id}
        ON DUPLICATE KEY UPDATE current_stock = {$new_stock}, last_restocked_at = NOW()
    ");
    // Also update master materials table
    mysqli_query($conn, "UPDATE materials SET current_stock = {$new_stock}, last_updated = NOW() WHERE id = {$material_id}");

    // Sync all dependent products in store
    require_once dirname(__DIR__) . '/includes/IngredientStockManager.php';
    IngredientStockManager::syncProductAvailabilityForStore($conn, $scope_target_store);

    $_SESSION['success'] = "Raw ingredient stock updated to {$new_stock}! All dependent menu product quantities have been recalculated.";
    header("Location: inventory.php?view=ingredients&date=" . urlencode($selected_date));
    exit;
}

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    $product_id = intval($_POST['product_id']);
    $adjustment_type = $_POST['adjustment_type']; // 'add', 'reduce', 'damage', 'received'
    $quantity = intval($_POST['quantity']);
    $notes = $_POST['notes'] ?? '';
    $inventory_date = $_POST['inventory_date'];

    if (!inventoryPartnerOwnsProduct($conn, $product_id, $seller_scope_id)) {
        $_SESSION['error'] = "You can only adjust inventory for your own store products.";
    } else {
    
    // First check if inventory record exists
    $check_query = "SELECT id, current_stock FROM inventory WHERE product_id = ? AND inventory_date = ?";
    $stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($stmt, "is", $product_id, $inventory_date);
    mysqli_stmt_execute($stmt);
    $check_result = mysqli_stmt_get_result($stmt);
    $inv_row = mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($stmt);
    
    if (!$inv_row) {
        // Create inventory record if doesn't exist
        $create_query = "INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated) VALUES (?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $create_query);
        $default_min_stock = 5;
        mysqli_stmt_bind_param($stmt, "isii", $product_id, $inventory_date, $quantity, $default_min_stock);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $current_stock = $quantity;
    } else {
        $current_stock = $inv_row['current_stock'];
    }
    
    // Calculate new stock based on adjustment type
    $new_stock = $current_stock;
    switch ($adjustment_type) {
        case 'add':
            $new_stock = $current_stock + $quantity;
            break;
        case 'reduce':
            $new_stock = max(0, $current_stock - $quantity);
            break;
        case 'damage':
            $new_stock = max(0, $current_stock - $quantity);
            break;
        case 'received':
            $new_stock = $current_stock + $quantity;
            break;
    }
    
    // Update inventory
    $update_query = "UPDATE inventory SET current_stock = ?, last_updated = NOW() WHERE product_id = ? AND inventory_date = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($stmt, "iis", $new_stock, $product_id, $inventory_date);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    // Automatically re-activate product if it was out of stock
    if ($new_stock > 0 && $current_stock <= 0) {
        // If stock was 0 and is now replenished, activate the product
        $product_status_query = "UPDATE products SET is_active = 1 WHERE id = ?";
    } else {
        // No status change needed if stock was already > 0, or if it's still 0.
        $product_status_query = null;
    }

    if (isset($product_status_query)) {
        $status_stmt = mysqli_prepare($conn, $product_status_query);
        mysqli_stmt_bind_param($status_stmt, "i", $product_id);
        mysqli_stmt_execute($status_stmt);
        mysqli_stmt_close($status_stmt);
    }
    
    // Log the adjustment
    $log_notes = "$notes (Date: $inventory_date)";
    $log_query = "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $log_query);
    $admin_id_for_log = $_SESSION['user_id'];
    mysqli_stmt_bind_param($stmt, "isiiisi", $product_id, $adjustment_type, $quantity, $current_stock, $new_stock, $log_notes, $admin_id_for_log);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
        $_SESSION['success'] = "Stock adjusted successfully.";
    }
}

// Handle inventory creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_inventory'])) {
    $product_id = intval($_POST['product_id'] ?? 0);
    $initial_stock = intval($_POST['initial_stock'] ?? 0);
    $auto_topup_existing = intval($_POST['auto_topup_existing'] ?? 0) === 1;
    if ($initial_stock <= 0) {
        $initial_stock = $default_inventory_seed_stock;
    }
    $min_stock_level = intval($_POST['min_stock_level'] ?? 5);
    $inventory_date = $_POST['inventory_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inventory_date)) {
        $inventory_date = date('Y-m-d');
    }

    if ($product_id === 0) {
        $_SESSION['error'] = "Please select a valid product.";
    } elseif (!inventoryPartnerOwnsProduct($conn, $product_id, $seller_scope_id)) {
        $_SESSION['error'] = "You can only create inventory for your own store products.";
    } else {
        // Check if inventory already exists
        $check_sql = "SELECT id, is_archived, current_stock FROM inventory WHERE product_id = ? AND inventory_date = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "is", $product_id, $inventory_date);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $check_row = mysqli_fetch_assoc($check_result);
        mysqli_stmt_close($check_stmt);

        if ($check_row && $check_row['is_archived'] == 0) {
            if (!$auto_topup_existing) {
                $_SESSION['error'] = "Inventory already exists for this product on this date. Enable auto top-up to add stock.";
            } else {
                $previous_stock = intval($check_row['current_stock'] ?? 0);
                $new_stock = $previous_stock + $initial_stock;
                $topup_sql = "UPDATE inventory SET current_stock = ?, min_stock_level = ?, last_updated = NOW() WHERE product_id = ? AND inventory_date = ? AND is_archived = 0";
                $topup_stmt = mysqli_prepare($conn, $topup_sql);
                mysqli_stmt_bind_param($topup_stmt, "iiis", $new_stock, $min_stock_level, $product_id, $inventory_date);

                if ($topup_stmt && mysqli_stmt_execute($topup_stmt)) {
                    $log_sql = "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) VALUES (?, 'automation', ?, ?, ?, 'Auto top-up from create inventory', ?, NOW())";
                    $log_stmt = mysqli_prepare($conn, $log_sql);
                    $admin_id = intval($_SESSION['user_id'] ?? 0);
                    if ($log_stmt) {
                        mysqli_stmt_bind_param($log_stmt, "iiiii", $product_id, $initial_stock, $previous_stock, $new_stock, $admin_id);
                        mysqli_stmt_execute($log_stmt);
                        mysqli_stmt_close($log_stmt);
                    }
                    $_SESSION['success'] = "Inventory already existed, auto top-up applied (+{$initial_stock}).";
                } else {
                    $_SESSION['error'] = "Inventory exists but auto top-up failed.";
                }
                if ($topup_stmt) {
                    mysqli_stmt_close($topup_stmt);
                }
            }
        } elseif ($check_row && $check_row['is_archived'] == 1) {
            // Restore archived inventory
            $update_sql = "UPDATE inventory SET is_archived = 0, current_stock = ?, min_stock_level = ?, last_updated = NOW() WHERE product_id = ? AND inventory_date = ?";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "iiis", $initial_stock, $min_stock_level, $product_id, $inventory_date);
            
            if (mysqli_stmt_execute($update_stmt)) {
                // Log restoration
                $log_sql = "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) VALUES (?, 'restored', ?, 0, ?, 'Inventory restored from archive', ?, NOW())";
                $log_stmt = mysqli_prepare($conn, $log_sql);
                $admin_id = $_SESSION['user_id'];
                mysqli_stmt_bind_param($log_stmt, "iiii", $product_id, $initial_stock, $initial_stock, $admin_id);
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);
                
                $_SESSION['success'] = "Inventory restored successfully.";
            } else {
                $_SESSION['error'] = "Unable to restore inventory.";
            }
            mysqli_stmt_close($update_stmt);
        } else {
            $insert_sql = "INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated) VALUES (?, ?, ?, ?, NOW())";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($insert_stmt, "isii", $product_id, $inventory_date, $initial_stock, $min_stock_level);

            if ($insert_stmt && mysqli_stmt_execute($insert_stmt)) {
                // Log creation
                $log_sql = "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $log_stmt = mysqli_prepare($conn, $log_sql);
                $adjustment_type = 'created';
                $notes = 'Initial inventory created';
                $admin_id = $_SESSION['user_id'];
                $zero = 0;
                mysqli_stmt_bind_param($log_stmt, "isiiisi", $product_id, $adjustment_type, $initial_stock, $zero, $initial_stock, $notes, $admin_id);
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);

                $_SESSION['success'] = "Inventory created successfully.";
            } else {
                $_SESSION['error'] = "Unable to create inventory.";
            }
            if ($insert_stmt) {
                mysqli_stmt_close($insert_stmt);
            }
        }
    }
}

// Handle bulk inventory creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_create_inventory'])) {
    $inventory_date = $_POST['inventory_date'] ?? date('Y-m-d');
    $bulk_auto_topup_existing = intval($_POST['bulk_auto_topup_existing'] ?? 0) === 1;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inventory_date)) {
        $inventory_date = date('Y-m-d');
    }

    $product_ids = $_POST['bulk_product_ids'] ?? [];
    $initial_stocks = $_POST['bulk_initial_stocks'] ?? [];
    $min_stock_levels = $_POST['bulk_min_stock_levels'] ?? [];

    if (!is_array($product_ids) || count($product_ids) === 0) {
        $_SESSION['error'] = "Please add at least one inventory row.";
    } else {
        $created = 0;
        $restored = 0;
        $topped_up = 0;
        $skipped = 0;
        $invalid = 0;
        $failed = 0;
        $seen_products = [];
        $admin_id = intval($_SESSION['user_id'] ?? 0);

        $product_check_sql = "SELECT id FROM products WHERE id = ? AND is_archived = 0" . ($seller_scope_id !== null ? " AND seller_id = ?" : "") . " LIMIT 1";
        $product_check_stmt = mysqli_prepare($conn, $product_check_sql);
        $check_stmt = mysqli_prepare($conn, "SELECT id, is_archived FROM inventory WHERE product_id = ? AND inventory_date = ?");
        $restore_stmt = mysqli_prepare($conn, "UPDATE inventory SET is_archived = 0, current_stock = ?, min_stock_level = ?, last_updated = NOW() WHERE product_id = ? AND inventory_date = ?");
        $insert_stmt = mysqli_prepare($conn, "INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated) VALUES (?, ?, ?, ?, NOW())");
        $topup_stmt = mysqli_prepare($conn, "UPDATE inventory SET current_stock = ?, min_stock_level = ?, last_updated = NOW() WHERE product_id = ? AND inventory_date = ? AND is_archived = 0");
        $restore_log_stmt = mysqli_prepare($conn, "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) VALUES (?, 'restored', ?, 0, ?, 'Inventory restored from bulk create', ?, NOW())");
        $create_log_stmt = mysqli_prepare($conn, "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) VALUES (?, 'created', ?, 0, ?, 'Initial inventory created using bulk create', ?, NOW())");
        $topup_log_stmt = mysqli_prepare($conn, "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) VALUES (?, 'automation', ?, ?, ?, 'Auto top-up from bulk create', ?, NOW())");

        if (!$product_check_stmt || !$check_stmt || !$restore_stmt || !$insert_stmt || !$topup_stmt || !$restore_log_stmt || !$create_log_stmt || !$topup_log_stmt) {
            if ($product_check_stmt) mysqli_stmt_close($product_check_stmt);
            if ($check_stmt) mysqli_stmt_close($check_stmt);
            if ($restore_stmt) mysqli_stmt_close($restore_stmt);
            if ($insert_stmt) mysqli_stmt_close($insert_stmt);
            if ($topup_stmt) mysqli_stmt_close($topup_stmt);
            if ($restore_log_stmt) mysqli_stmt_close($restore_log_stmt);
            if ($create_log_stmt) mysqli_stmt_close($create_log_stmt);
            if ($topup_log_stmt) mysqli_stmt_close($topup_log_stmt);
            $_SESSION['error'] = "Bulk create is temporarily unavailable. Please try again.";
        } else {
            foreach ($product_ids as $idx => $raw_product_id) {
                $product_id = intval($raw_product_id);
                $initial_stock = intval($initial_stocks[$idx] ?? 0);
                if ($initial_stock <= 0) {
                    $initial_stock = $default_inventory_seed_stock;
                }
                $min_stock_level = intval($min_stock_levels[$idx] ?? 5);

                if ($product_id <= 0 || $initial_stock < 0 || $min_stock_level < 1) {
                    $invalid++;
                    continue;
                }
                if (isset($seen_products[$product_id])) {
                    $skipped++;
                    continue;
                }
                $seen_products[$product_id] = true;

                if ($seller_scope_id !== null) {
                    mysqli_stmt_bind_param($product_check_stmt, "ii", $product_id, $seller_scope_id);
                } else {
                    mysqli_stmt_bind_param($product_check_stmt, "i", $product_id);
                }
                mysqli_stmt_execute($product_check_stmt);
                $product_exists_result = mysqli_stmt_get_result($product_check_stmt);
                if (!$product_exists_result || !mysqli_fetch_assoc($product_exists_result)) {
                    $invalid++;
                    continue;
                }

                mysqli_stmt_bind_param($check_stmt, "is", $product_id, $inventory_date);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                $existing_row = $check_result ? mysqli_fetch_assoc($check_result) : null;

                if ($existing_row && intval($existing_row['is_archived']) === 0) {
                    if ($bulk_auto_topup_existing) {
                        $previous_stock = intval($existing_row['current_stock'] ?? 0);
                        $new_stock = $previous_stock + $initial_stock;
                        mysqli_stmt_bind_param($topup_stmt, "iiis", $new_stock, $min_stock_level, $product_id, $inventory_date);
                        if (mysqli_stmt_execute($topup_stmt)) {
                            mysqli_stmt_bind_param($topup_log_stmt, "iiiii", $product_id, $initial_stock, $previous_stock, $new_stock, $admin_id);
                            mysqli_stmt_execute($topup_log_stmt);
                            $topped_up++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                if ($existing_row && intval($existing_row['is_archived']) === 1) {
                    mysqli_stmt_bind_param($restore_stmt, "iiis", $initial_stock, $min_stock_level, $product_id, $inventory_date);
                    if (mysqli_stmt_execute($restore_stmt)) {
                        mysqli_stmt_bind_param($restore_log_stmt, "iiii", $product_id, $initial_stock, $initial_stock, $admin_id);
                        mysqli_stmt_execute($restore_log_stmt);
                        $restored++;
                    } else {
                        $failed++;
                    }
                    continue;
                }

                mysqli_stmt_bind_param($insert_stmt, "isii", $product_id, $inventory_date, $initial_stock, $min_stock_level);
                if (mysqli_stmt_execute($insert_stmt)) {
                    mysqli_stmt_bind_param($create_log_stmt, "iiii", $product_id, $initial_stock, $initial_stock, $admin_id);
                    mysqli_stmt_execute($create_log_stmt);
                    $created++;
                } else {
                    $failed++;
                }
            }

            mysqli_stmt_close($product_check_stmt);
            mysqli_stmt_close($check_stmt);
            mysqli_stmt_close($restore_stmt);
            mysqli_stmt_close($insert_stmt);
            mysqli_stmt_close($topup_stmt);
            mysqli_stmt_close($restore_log_stmt);
            mysqli_stmt_close($create_log_stmt);
            mysqli_stmt_close($topup_log_stmt);

            $parts = [];
            if ($created > 0) {
                $parts[] = $created . " created";
            }
            if ($restored > 0) {
                $parts[] = $restored . " restored";
            }
            if ($topped_up > 0) {
                $parts[] = $topped_up . " topped up";
            }
            if ($skipped > 0) {
                $parts[] = $skipped . " skipped";
            }
            if ($invalid > 0) {
                $parts[] = $invalid . " invalid";
            }
            if ($failed > 0) {
                $parts[] = $failed . " failed";
            }
            $summary = count($parts) > 0 ? implode(", ", $parts) : "No rows processed";

            if (($created + $restored + $topped_up) > 0) {
                $_SESSION['success'] = "Bulk inventory completed: " . $summary . ".";
            } else {
                $_SESSION['error'] = "No inventory rows were created: " . $summary . ".";
            }
        }
    }
}

// Run one-click inventory automation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_inventory_automation'])) {
    $automation_date = $_POST['automation_date'] ?? $selected_date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $automation_date)) {
        $automation_date = $selected_date;
    }

    if ($seller_scope_id !== null) {
        $automation_summary = runPartnerInventoryAutomation($conn, $automation_date, (int)$seller_scope_id, intval($_SESSION['user_id'] ?? 0));
        if (!empty($automation_summary['errors'])) {
            $_SESSION['error'] = "Partner automation finished with issues: " . implode(" ", $automation_summary['errors']);
        } else {
            $message = "Partner automation completed for {$automation_date}: " .
                "{$automation_summary['created_missing']} missing row(s) created, " .
                "{$automation_summary['today_seeded']} row(s) seeded to 10 units, " .
                "{$automation_summary['stock_synced']} product stock value(s) synced, " .
                "{$automation_summary['activated']} product(s) activated, " .
                "{$automation_summary['deactivated']} product(s) deactivated.";

            if ($automation_summary['master_sync_skipped']) {
                $message .= " Master product sync is only applied when automation date is today.";
            }

            $_SESSION['success'] = $message;
        }
    } else {
        $automation_summary = runInventoryAutomation($conn, $automation_date, intval($_SESSION['user_id'] ?? 0));
        if (!empty($automation_summary['errors'])) {
            $_SESSION['error'] = "Automation finished with issues: " . implode(" ", $automation_summary['errors']);
        } else {
            $message = "Automation completed for {$automation_date}: " .
                "{$automation_summary['created_missing']} missing row(s) created, " .
                "{$automation_summary['today_seeded']} row(s) seeded to 10 units for today, " .
                "{$automation_summary['normalized_negative']} negative stock value(s) fixed, " .
                "{$automation_summary['stock_synced']} product stock value(s) synced, " .
                "{$automation_summary['activated']} product(s) activated, " .
                "{$automation_summary['deactivated']} product(s) deactivated, " .
                "{$automation_summary['alerts_sent']} alert(s) sent.";

            if ($automation_summary['master_sync_skipped']) {
                $message .= " Master product sync is only applied when automation date is today.";
            }
            $_SESSION['success'] = $message;
        }
    }
}

// Handle inventory deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inventory'])) {
    $product_id = intval($_POST['product_id'] ?? 0);

    if ($product_id === 0) {
        $_SESSION['error'] = "Invalid product.";
    } elseif (!inventoryPartnerOwnsProduct($conn, $product_id, $seller_scope_id)) {
        $_SESSION['error'] = "You can only archive inventory for your own store products.";
    } else {
        // Soft delete / Archive
        $delete_sql = "UPDATE inventory SET is_archived = 1 WHERE product_id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $product_id);

        if ($delete_stmt && mysqli_stmt_execute($delete_stmt)) {
            // Log archiving
            $log_sql = "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, admin_id, created_at) 
                        SELECT product_id, 'archived', 0, current_stock, current_stock, 'Inventory archived', ?, NOW() 
                        FROM inventory WHERE product_id = ?";
            $log_stmt = mysqli_prepare($conn, $log_sql);
            $admin_id = $_SESSION['user_id'];
            mysqli_stmt_bind_param($log_stmt, "ii", $admin_id, $product_id);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);

            $_SESSION['success'] = "Inventory archived successfully.";
        } else {
            $_SESSION['error'] = "Unable to archive inventory.";
        }
        if ($delete_stmt) {
            mysqli_stmt_close($delete_stmt);
        }
    }
}

// Handle minimum stock level update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_min_stock'])) {
    $product_id = intval($_POST['product_id'] ?? 0);
    $min_stock_level = intval($_POST['min_stock_level'] ?? 5);

    if ($product_id === 0 || $min_stock_level < 0) {
        $_SESSION['error'] = "Invalid data.";
    } elseif (!inventoryPartnerOwnsProduct($conn, $product_id, $seller_scope_id)) {
        $_SESSION['error'] = "You can only update minimum stock for your own store products.";
    } else {
        $update_sql = "UPDATE inventory SET min_stock_level = ? WHERE product_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $min_stock_level, $product_id);

        if ($update_stmt && mysqli_stmt_execute($update_stmt)) {
            $_SESSION['success'] = "Minimum stock level updated.";
        } else {
            $_SESSION['error'] = "Unable to update minimum stock level.";
        }
        if ($update_stmt) {
            mysqli_stmt_close($update_stmt);
        }
    }
}

// Get filter
$search = isset($_GET['search']) ? $_GET['search'] : '';
$stock_status = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';
// Build query with prepared statements
$where_clauses = ["p.is_archived = 0"];
$having_clauses = [];
$params = [];
$param_types = '';

// Add selected_date as the first parameter for the JOIN condition
$params[] = $selected_date;
$param_types .= 's';

if ($seller_scope_id !== null) {
    $where_clauses[] = "p.seller_id = ?";
    $params[] = $seller_scope_id;
    $param_types .= 'i';
}

if ($search !== '') {
    $where_clauses[] = "p.name LIKE ?";
    $params[] = "%{$search}%";
    $param_types .= 's';
}
if ($stock_status === 'low') {
    $having_clauses[] = "daily_stock <= min_stock_level AND has_inventory = 1";
} elseif ($stock_status === 'out') {
    $having_clauses[] = "daily_stock = 0 AND has_inventory = 1";
}

$where_clause = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
$having_clause = count($having_clauses) > 0 ? "HAVING " . implode(" AND ", $having_clauses) : "";

$inventory_query = "SELECT p.id, p.product_id, p.name, p.price, p.category, 
                           COALESCE(MAX(i.current_stock), 0) as daily_stock,
                           COALESCE(MAX(i.min_stock_level), 5) as min_stock_level,
                           MAX(i.last_updated) as last_updated,
                           MAX(CASE WHEN i.id IS NOT NULL THEN 1 ELSE 0 END) as has_inventory
                    FROM products p
                    LEFT JOIN inventory i ON p.id = i.product_id AND i.inventory_date = ? AND i.is_archived = 0
                    $where_clause
                    GROUP BY p.id, p.product_id, p.name, p.price, p.category
                    $having_clause
                    ORDER BY p.name ASC";

$inventory_stmt = mysqli_prepare($conn, $inventory_query);
if ($inventory_stmt && count($params) > 0) {
    $bind_params = [$inventory_stmt, $param_types];
    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind_params);
}

if ($inventory_stmt) {
    mysqli_stmt_execute($inventory_stmt);
    $inventory_result = mysqli_stmt_get_result($inventory_stmt);
} else {
    $inventory_result = false;
    $_SESSION['error'] = "Unable to load inventory.";
}

// Get pre-order demand for the selected date
$preorder_demand_query = "
    SELECT po.id, u.full_name, po.product_name, po.quantity, po.preferred_pickup_time
    FROM pre_orders po
    JOIN users u ON po.user_id = u.id
    JOIN products p_scope ON p_scope.id = po.product_id
    WHERE po.preferred_pickup_date = ?
    AND po.reservation_status NOT IN ('completed', 'cancelled')
    " . ($seller_scope_id !== null ? "AND p_scope.seller_id = ?" : "") . "
    ORDER BY po.preferred_pickup_time ASC
";
$preorder_stmt = mysqli_prepare($conn, $preorder_demand_query);
if ($seller_scope_id !== null) {
    mysqli_stmt_bind_param($preorder_stmt, "si", $selected_date, $seller_scope_id);
} else {
    mysqli_stmt_bind_param($preorder_stmt, "s", $selected_date);
}
mysqli_stmt_execute($preorder_stmt);
$preorder_result = mysqli_stmt_get_result($preorder_stmt);

// Calculate total demand per product for the day
$daily_demand = [];
if ($preorder_result && mysqli_num_rows($preorder_result) > 0) {
    while ($preorder_row = mysqli_fetch_assoc($preorder_result)) {
        $product_name = $preorder_row['product_name'];
        if (!isset($daily_demand[$product_name])) $daily_demand[$product_name] = 0;
        $daily_demand[$product_name] += $preorder_row['quantity'];
    }
    mysqli_data_seek($preorder_result, 0); // Rewind for table display
}

// Get products without inventory
$products_without_inventory = [];
$prod_query = "
    SELECT p.id, p.name
    FROM products p
    LEFT JOIN inventory i
        ON p.id = i.product_id
       AND i.inventory_date = ?
       AND i.is_archived = 0
    WHERE i.id IS NULL
      AND p.is_archived = 0" . ($seller_scope_id !== null ? "
      AND p.seller_id = ?" : "") . "
    ORDER BY p.name
";
$prod_stmt = mysqli_prepare($conn, $prod_query);
if ($prod_stmt) {
    if ($seller_scope_id !== null) {
        mysqli_stmt_bind_param($prod_stmt, "si", $selected_date, $seller_scope_id);
    } else {
        mysqli_stmt_bind_param($prod_stmt, "s", $selected_date);
    }
    mysqli_stmt_execute($prod_stmt);
    $prod_result = mysqli_stmt_get_result($prod_stmt);
    if ($prod_result) {
        while ($prod = mysqli_fetch_assoc($prod_result)) {
            $products_without_inventory[] = $prod;
        }
    }
    mysqli_stmt_close($prod_stmt);
}

// Product list for bulk inventory modal
$all_products = [];
$all_prod_query = "
    SELECT id, name
    FROM products
    WHERE is_archived = 0" . ($seller_scope_id !== null ? " AND seller_id = " . (int)$seller_scope_id : "") . "
    ORDER BY name
";
$all_prod_result = mysqli_query($conn, $all_prod_query);
if ($all_prod_result) {
    while ($row = mysqli_fetch_assoc($all_prod_result)) {
        $all_products[] = $row;
    }
}

$products_with_inventory_lookup = [];
$_products_without_inventory_map = [];
foreach ($products_without_inventory as $prod_without) {
    $without_id = (int)($prod_without['id'] ?? 0);
    if ($without_id > 0) {
        $_products_without_inventory_map[$without_id] = true;
    }
}
foreach ($all_products as $prod_row_for_lookup) {
    $pid = (int)($prod_row_for_lookup['id'] ?? 0);
    if ($pid > 0 && !isset($_products_without_inventory_map[$pid])) {
        $products_with_inventory_lookup[$pid] = true;
    }
}

// Active view mode: 'products' (default) or 'ingredients'
$active_view = isset($_GET['view']) && $_GET['view'] === 'ingredients' ? 'ingredients' : 'products';
$scope_store_id = $seller_scope_id !== null ? (int)$seller_scope_id : (int)($_SESSION['user_id'] ?? 0);

// Fetch raw materials for recipe stock derivation and raw ingredients table
$mat_q = mysqli_query($conn, "
    SELECT m.id, m.name, m.unit, m.min_level, m.cost_per_unit,
           COALESCE(smi.current_stock, m.current_stock, 0) as current_stock,
           COALESCE(smi.reorder_level, m.min_level, 5) as effective_reorder_level
    FROM materials m
    LEFT JOIN store_material_inventory smi ON smi.material_id = m.id AND smi.store_id = {$scope_store_id}
    ORDER BY m.name ASC
");
$all_materials = [];
$materials_by_id = [];
while ($mat_q && ($mrow = mysqli_fetch_assoc($mat_q))) {
    $mrow['current_stock_int'] = (int)floor((float)$mrow['current_stock']);
    $mrow['effective_reorder_int'] = (int)ceil((float)$mrow['effective_reorder_level']);
    $all_materials[] = $mrow;
    $materials_by_id[(int)$mrow['id']] = $mrow;
}

// Fetch all product recipes from bill_of_materials
$bom_q = mysqli_query($conn, "
    SELECT bom.product_id, bom.material_id, bom.quantity_needed,
           m.name as material_name, m.unit as material_unit
    FROM bill_of_materials bom
    INNER JOIN materials m ON m.id = bom.material_id
    ORDER BY bom.product_id, m.name
");
$product_recipes = [];
$material_usage_map = [];
while ($bom_q && ($brow = mysqli_fetch_assoc($bom_q))) {
    $pid = (int)$brow['product_id'];
    $mid = (int)$brow['material_id'];
    if (!isset($product_recipes[$pid])) {
        $product_recipes[$pid] = [];
    }
    $brow['quantity_needed_int'] = max(1, (int)round((float)$brow['quantity_needed']));
    $brow['material_stock'] = (int)($materials_by_id[$mid]['current_stock_int'] ?? 0);
    $product_recipes[$pid][] = $brow;

    if (!isset($material_usage_map[$mid])) {
        $material_usage_map[$mid] = [];
    }
    foreach ($all_products as $ap) {
        if ((int)$ap['id'] === $pid) {
            $material_usage_map[$mid][] = $ap['name'];
            break;
        }
    }
}

// DSS inventory overview and planning recommendations for selected date.
$inventory_overview = [
    'tracked_items' => 0,
    'out_of_stock' => 0,
    'low_stock' => 0,
    'reorder_items' => 0,
    'recommended_units' => 0
];

$overview_sql = "
    SELECT
        COUNT(*) AS tracked_items,
        SUM(CASE WHEN COALESCE(i.current_stock, 0) <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN COALESCE(i.current_stock, 0) > 0 AND COALESCE(i.current_stock, 0) <= COALESCE(i.min_stock_level, 5) THEN 1 ELSE 0 END) AS low_stock
    FROM products p
    LEFT JOIN inventory i
        ON p.id = i.product_id
       AND i.inventory_date = ?
       AND i.is_archived = 0
    WHERE p.is_archived = 0" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "") . "
";
$overview_stmt = mysqli_prepare($conn, $overview_sql);
if ($overview_stmt) {
    if ($seller_scope_id !== null) {
        mysqli_stmt_bind_param($overview_stmt, "si", $selected_date, $seller_scope_id);
    } else {
        mysqli_stmt_bind_param($overview_stmt, "s", $selected_date);
    }
    mysqli_stmt_execute($overview_stmt);
    $overview_result = mysqli_stmt_get_result($overview_stmt);
    $overview_row = $overview_result ? mysqli_fetch_assoc($overview_result) : null;
    if ($overview_row) {
        $inventory_overview['tracked_items'] = (int)($overview_row['tracked_items'] ?? 0);
        $inventory_overview['out_of_stock'] = (int)($overview_row['out_of_stock'] ?? 0);
        $inventory_overview['low_stock'] = (int)($overview_row['low_stock'] ?? 0);
    }
    mysqli_stmt_close($overview_stmt);
}

$planning_rows = [];
$planning_sql = "
    SELECT
        p.id,
        p.name,
        COALESCE(MAX(i.current_stock), 0) AS stock,
        COALESCE(MAX(i.min_stock_level), 5) AS min_stock,
        COALESCE(SUM(po.quantity), 0) AS preorder_demand
    FROM products p
    LEFT JOIN inventory i
        ON p.id = i.product_id
       AND i.inventory_date = ?
       AND i.is_archived = 0
    LEFT JOIN pre_orders po
        ON po.product_id = p.id
       AND po.preferred_pickup_date = ?
       AND po.reservation_status NOT IN ('completed', 'cancelled')
    WHERE p.is_archived = 0" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "") . "
    GROUP BY p.id, p.name
    ORDER BY p.name ASC
";
$planning_stmt = mysqli_prepare($conn, $planning_sql);
if ($planning_stmt) {
    if ($seller_scope_id !== null) {
        mysqli_stmt_bind_param($planning_stmt, "ssi", $selected_date, $selected_date, $seller_scope_id);
    } else {
        mysqli_stmt_bind_param($planning_stmt, "ss", $selected_date, $selected_date);
    }
    mysqli_stmt_execute($planning_stmt);
    $planning_result = mysqli_stmt_get_result($planning_stmt);
    if ($planning_result) {
        while ($row = mysqli_fetch_assoc($planning_result)) {
            $stock = (int)$row['stock'];
            $min_stock = max(0, (int)$row['min_stock']);
            $preorder_demand = (int)$row['preorder_demand'];
            $coverage_gap = max(0, $preorder_demand - $stock);
            $buffer_gap = max(0, $min_stock - $stock);
            $recommended_qty = max($coverage_gap, $buffer_gap);

            if ($recommended_qty > 0 || $stock <= 0) {
                $planning_rows[] = [
                    'product_id' => (int)$row['id'],
                    'name' => $row['name'],
                    'stock' => $stock,
                    'min_stock' => $min_stock,
                    'preorder_demand' => $preorder_demand,
                    'recommended_qty' => $recommended_qty,
                    'severity' => ($stock <= 0 || $coverage_gap > 0) ? 'critical' : 'high'
                ];
            }
        }
    }
    mysqli_stmt_close($planning_stmt);
}

usort($planning_rows, function ($a, $b) {
    if ($a['severity'] === $b['severity']) {
        return $b['recommended_qty'] <=> $a['recommended_qty'];
    }
    return ($a['severity'] === 'critical') ? -1 : 1;
});
$planning_rows = array_slice($planning_rows, 0, 7);

$inventory_overview['reorder_items'] = count($planning_rows);
$inventory_overview['recommended_units'] = array_sum(array_column($planning_rows, 'recommended_qty'));

$insights_service = new DSSInsightsService($conn);
$forecast_summary = safeInventoryDssCall(function () use ($insights_service) {
    return $insights_service->getForecastingSummary(7);
}, ['predicted_orders' => 0, 'avg_confidence' => 0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td,
        body.dark-mode .modal-content,
        body.dark-mode .modal-header,
        body.dark-mode .modal-footer,
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode label,
        body.dark-mode .modal-title {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small {
            color: #b0b0b0 !important;
        }
        
        body.dark-mode .table-responsive {
             background-color: var(--card-bg-dark) !important;
             border-color: var(--border-color-dark) !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .insight-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .insight-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 14px;
        }

        .insight-label {
            color: #6c757d;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .insight-value {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
        }

        .decision-panel {
            position: relative;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
            border: 1px solid #e8edf3;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 18px;
            overflow: hidden;
        }

        .decision-panel::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #f59e0b, #f97316);
        }

        .decision-panel .decision-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .decision-panel .decision-actions .btn {
            border-radius: 999px;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
        }

        .decision-panel ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
        }

        .decision-panel li {
            margin: 0;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 10px 12px 10px 34px;
            line-height: 1.45;
            position: relative;
        }

        .decision-panel li::before {
            content: "\f0eb";
            font-family: "Font Awesome 6 Free", "Font Awesome 5 Free";
            font-weight: 900;
            position: absolute;
            left: 12px;
            top: 10px;
            color: #d97706;
            font-size: 0.78rem;
        }

        body.dark-mode .insight-card,
        body.dark-mode .decision-panel {
            background-color: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .decision-panel {
            background: linear-gradient(180deg, #2d2d2d 0%, #252525 100%) !important;
        }

        body.dark-mode .decision-panel li {
            background: rgba(245, 158, 11, 0.08);
            border-color: #6b4f1d;
        }

        body.dark-mode .decision-panel li::before {
            color: #fbbf24;
        }

        body.dark-mode .insight-value {
            color: var(--text-color-dark) !important;
        }

        /* Inventory page styling enhancements */
        .inventory-page .insight-card {
            position: relative;
            overflow: hidden;
            border-radius: 14px;
        }

        .inventory-page .insight-card::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #c62828, #ef5350);
        }

        .inventory-page .decision-panel {
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.06);
        }

        .inventory-page .section-header {
            background: #ffffff;
            border: 1px solid #e8edf3;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
        }

        .inventory-page .section-header.mt-5 {
            margin-top: 1.5rem !important;
        }

        .inventory-page .filter-form {
            display: grid;
            grid-template-columns: minmax(150px, 180px) minmax(220px, 1fr) minmax(150px, 220px) auto;
            gap: 10px;
            align-items: center;
            width: 100%;
        }

        .inventory-page .table-responsive {
            border-radius: 14px;
            overflow: auto;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.05);
            border: 1px solid #e8edf3;
            margin-bottom: 16px;
        }

        .inventory-page .admin-table thead th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.35px;
            color: #475569;
        }

        .inventory-page .admin-table tbody tr:hover {
            background: #f7fafc;
        }

        .inventory-page .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.78rem;
        }

        .inventory-page .stock-info { background: #e8f1ff; color: #174ea6; }
        .inventory-page .stock-danger { background: #fde8e8; color: #a50e0e; }
        .inventory-page .stock-warning { background: #fff7e6; color: #9a6700; }
        .inventory-page .stock-success { background: #e8f5e9; color: #1b5e20; }

        .inventory-page .btn-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dde5ef;
            background: #fff;
            color: #334155;
            transition: all 0.2s ease;
        }

        .inventory-page .btn-icon:hover {
            transform: translateY(-1px);
            color: #c62828;
            border-color: #e3b4b4;
        }

        .inventory-page .btn-icon-danger:hover {
            color: #ef4444;
            border-color: #f5b7b7;
        }

        .inventory-page .row.mb-4 .card {
            border-radius: 14px;
            border: 1px solid #e8edf3;
            box-shadow: 0 10px 20px rgba(17, 24, 39, 0.05);
            overflow: hidden;
        }

        .inventory-page .row.mb-4 .card-header {
            font-weight: 700;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        @media (max-width: 1200px) {
            .inventory-page .filter-form {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 640px) {
            .inventory-page .filter-form {
                grid-template-columns: 1fr;
            }
        }

        body.dark-mode.inventory-page .section-header,
        body.dark-mode.inventory-page .table-responsive,
        body.dark-mode.inventory-page .row.mb-4 .card {
            background: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode.inventory-page .stock-info,
        body.dark-mode.inventory-page .stock-danger,
        body.dark-mode.inventory-page .stock-warning,
        body.dark-mode.inventory-page .stock-success {
            background: rgba(255, 255, 255, 0.08) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode.inventory-page .btn-icon {
            background: #1f2937 !important;
            border-color: #374151 !important;
            color: #d1d5db !important;
        }
    </style>
</head>
<body class="admin-polish inventory-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Inventory Management</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <div class="insight-grid">
                    <div class="insight-card">
                        <div class="insight-label">Tracked Products</div>
                        <div class="insight-value"><?php echo number_format($inventory_overview['tracked_items']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Out of Stock</div>
                        <div class="insight-value"><?php echo number_format($inventory_overview['out_of_stock']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Low Stock</div>
                        <div class="insight-value"><?php echo number_format($inventory_overview['low_stock']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Items Needing Reorder</div>
                        <div class="insight-value"><?php echo number_format($inventory_overview['reorder_items']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">Recommended Reorder Units</div>
                        <div class="insight-value"><?php echo number_format($inventory_overview['recommended_units']); ?></div>
                    </div>
                    <div class="insight-card">
                        <div class="insight-label">7-Day Forecasted Orders</div>
                        <div class="insight-value"><?php echo number_format((float)($forecast_summary['predicted_orders'] ?? 0), 0); ?></div>
                    </div>
                </div>

                <div class="decision-panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <h5 class="mb-0"><i class="fas fa-lightbulb text-warning me-1"></i> DSS Inventory Planning</h5>
                        <div class="decision-actions">
                            <a href="mrp.php?tab=low_stock" class="btn btn-sm btn-outline-warning"><i class="fas fa-file-alt me-1"></i>Procurement</a>
                            <a href="products.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-store me-1"></i>Products</a>
                            <a href="dss_reports.php" class="btn btn-sm btn-outline-success"><i class="fas fa-chart-bar me-1"></i>DSS Report</a>
                        </div>
                    </div>
                    <?php if (!empty($planning_rows)): ?>
                        <ul class="mt-3">
                            <?php foreach ($planning_rows as $plan): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($plan['name']); ?></strong>:
                                    stock <?php echo (int)$plan['stock']; ?>, preorder demand <?php echo (int)$plan['preorder_demand']; ?>,
                                    suggested reorder <strong><?php echo (int)$plan['recommended_qty']; ?></strong> unit(s).
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="alert alert-success mt-3 mb-0">
                            Inventory levels are healthy for <?php echo htmlspecialchars($selected_date); ?>. No urgent reorder recommendations.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- View Switcher Tabs: Menu Products vs Raw Ingredients -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div class="btn-group" role="group">
                        <a href="?view=products&date=<?php echo urlencode($selected_date); ?>" class="btn btn-sm" style="<?php echo $active_view === 'products' ? 'background:#b3261e; border-color:#b3261e; color:#fff;' : 'background:#fff; border:1px solid #d0d5dd; color:#344054;'; ?> font-weight:600; padding:8px 16px;">
                            <i class="fas fa-utensils me-1"></i> Menu Products Stock (<?php echo count($all_products); ?>)
                        </a>
                        <a href="?view=ingredients&date=<?php echo urlencode($selected_date); ?>" class="btn btn-sm" style="<?php echo $active_view === 'ingredients' ? 'background:#b3261e; border-color:#b3261e; color:#fff;' : 'background:#fff; border:1px solid #d0d5dd; color:#344054;'; ?> font-weight:600; padding:8px 16px;">
                            <i class="fas fa-carrot me-1"></i> Raw Ingredients Inventory (<?php echo count($all_materials); ?>)
                        </a>
                    </div>
                    <div class="text-muted small">
                        <i class="fas fa-info-circle text-primary me-1"></i> Product stock is automatically derived from raw ingredient inventory availability (in whole numbers).
                    </div>
                </div>

                <?php if ($active_view === 'products'): ?>
                <div class="section-header">
                    <h2>Menu Products Stock Management</h2>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createInventoryModal" <?php echo count($all_products) === 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-plus"></i> Create Inventory
                        </button>
                        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkCreateInventoryModal" <?php echo count($all_products) === 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-layer-group"></i> Bulk Create
                        </button>
                        <?php if ($seller_scope_id === null): ?>
                            <form method="POST" class="d-inline-block">
                                <input type="hidden" name="run_inventory_automation" value="1">
                                <input type="hidden" name="automation_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                                <button type="submit" class="btn btn-outline-dark">
                                    <i class="fas fa-robot"></i> Run Automation
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <form method="GET" class="filter-form">
                        <input type="hidden" name="view" value="products">
                        <input type="date" name="date" value="<?php echo $selected_date; ?>" class="form-control" onchange="this.form.submit()">
                        <input type="text" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                        <select name="stock_status" class="form-select" onchange="this.form.submit()">
                            <option value="">All Stock</option>
                            <option value="low" <?php echo $stock_status === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="out" <?php echo $stock_status === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Available Stock (From Ingredients)</th>
                                <th>Min Safe Level</th>
                                <th>Status</th>
                                <th>Recipe Ingredients</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($inventory_result && mysqli_num_rows($inventory_result) > 0) {
                                while ($inv = mysqli_fetch_assoc($inventory_result)) {
                                    $pid = (int)$inv['id'];
                                    $recipe = $product_recipes[$pid] ?? [];
                                    $has_recipe = !empty($recipe);

                                    $calc_yield = PHP_INT_MAX;
                                    $limiting_ingredient = '';
                                    if ($has_recipe) {
                                        foreach ($recipe as $ing) {
                                            $mat_id = (int)$ing['material_id'];
                                            $have = (int)($materials_by_id[$mat_id]['current_stock_int'] ?? 0);
                                            $need = max(1, (int)$ing['quantity_needed_int']);
                                            $can_make = (int)floor($have / $need);
                                            if ($can_make < $calc_yield) {
                                                $calc_yield = $can_make;
                                                $limiting_ingredient = $ing['material_name'];
                                            }
                                        }
                                        if ($calc_yield === PHP_INT_MAX) $calc_yield = 0;
                                        $daily_stock = max(0, $calc_yield);
                                    } else {
                                        $daily_stock = (int)$inv['daily_stock'];
                                    }

                                    $min_level = (int)$inv['min_stock_level'];
                                    
                                    // Status is based on whole number daily stock
                                    if (!$inv['has_inventory'] && !$has_recipe) {
                                        $status_badge = '<span class="status-badge badge-info">Not Tracked</span>';
                                        $status_color = 'info';
                                    } elseif ($daily_stock <= 0) {
                                        $status_badge = '<span class="status-badge badge-danger">Out of Stock</span>';
                                        $status_color = 'danger';
                                    } elseif ($daily_stock <= $min_level) {
                                        $status_badge = '<span class="status-badge badge-warning">Low Stock</span>';
                                        $status_color = 'warning';
                                    } else {
                                        $status_badge = '<span class="status-badge badge-success">In Stock</span>';
                                        $status_color = 'success';
                                    }

                                    $recipe_json = htmlspecialchars(json_encode([
                                        'id' => $inv['id'],
                                        'name' => $inv['name'],
                                        'category' => $inv['category'],
                                        'price' => $inv['price'],
                                        'recipe' => $recipe,
                                        'calculated_yield' => $daily_stock,
                                        'has_recipe' => $has_recipe
                                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                                    
                                    echo "
                                    <tr>
                                        <td>
                                            <strong>{$inv['name']}</strong>
                                            <div class='text-muted small' style='font-size:11px;'>ID: #PROD-{$inv['product_id']}</div>
                                        </td>
                                        <td>{$inv['category']}</td>
                                        <td>&#8369;" . number_format($inv['price'], 2) . "</td>
                                        <td>
                                            <span class='stock-badge stock-{$status_color}'><strong>{$daily_stock} units</strong></span>";
                                    
                                    if ($has_recipe) {
                                        if ($daily_stock <= 0) {
                                            echo "<small class='d-block text-danger mt-1' style='font-size:11px;'><i class='fas fa-exclamation-circle'></i> Depleted: {$limiting_ingredient}</small>";
                                        } else {
                                            echo "<small class='d-block text-success mt-1' style='font-size:11px;'><i class='fas fa-check-circle'></i> Limit: {$limiting_ingredient}</small>";
                                        }
                                    } else {
                                        echo "<small class='d-block text-muted mt-1' style='font-size:11px;'><i class='fas fa-info-circle'></i> Manual Stock</small>";
                                    }

                                    echo "
                                        </td>
                                        <td>{$min_level} units</td>
                                        <td>$status_badge</td>
                                        <td>
                                            <button type='button' class='btn btn-sm " . ($has_recipe ? 'btn-outline-secondary' : 'btn-outline-danger') . "' onclick='openProductRecipeModal({$recipe_json})' style='font-size:12px; font-weight:600;'>
                                                <i class='fas fa-layer-group me-1'></i> " . ($has_recipe ? "Recipe (" . count($recipe) . " items)" : "+ Link Ingredients") . "
                                            </button>
                                        </td>
                                        <td>";
                                    
                                    if ($inv['has_inventory']) {
                                        echo "
                                            <button class='btn-icon' data-bs-toggle='modal' data-bs-target='#inventoryModal' onclick='loadInventoryDetails({$inv['id']}, \"$selected_date\")' title='Adjust Stock'>
                                                <i class='fas fa-edit'></i>
                                            </button>
                                            <button class='btn-icon' data-bs-toggle='modal' data-bs-target='#minStockModal' onclick=\"setMinStockForm({$inv['id']}, {$inv['min_stock_level']})\" title='Set Min Level'>
                                                <i class='fas fa-sliders-h'></i>
                                            </button>
                                            <button class='btn-icon' data-bs-toggle='modal' data-bs-target='#historyModal' onclick='loadInventoryHistory({$inv['id']})' title='View History'>
                                                <i class='fas fa-history'></i>
                                            </button>
                                            <button class='btn-icon btn-icon-danger' onclick='deleteInventory({$inv['id']})' title='Archive'>
                                                <i class='fas fa-archive'></i>
                                            </button>";
                                    } else {
                                        echo "
                                            <button class='btn btn-sm btn-outline-primary' data-bs-toggle='modal' data-bs-target='#createInventoryModal' onclick=\"setProductId({$inv['id']})\" title='Create Inventory'>
                                                <i class='fas fa-plus'></i> Create
                                            </button>";
                                    }
                                    
                                    echo "
                                        </td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='8' class='text-center text-muted'>No products found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <!-- Raw Ingredients Inventory View -->
                <div class="section-header">
                    <h2>Raw Ingredients Inventory &amp; Store Stock</h2>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#adjustIngredientModal" onclick="openIngredientAdjustModal(0, '', '', 0)" style="background:#b3261e; border-color:#b3261e; font-weight:600;">
                            <i class="fas fa-plus"></i> Quick Restock / Adjust Ingredient
                        </button>
                        <a href="materials.php" class="btn btn-outline-secondary" style="font-weight:600;">
                            <i class="fas fa-list"></i> Full Materials Catalog
                        </a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Raw Material / Ingredient</th>
                                <th>Current Store Stock (Whole Numbers)</th>
                                <th>Reorder Level</th>
                                <th>Stock Status</th>
                                <th>Used in Menu Products</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_materials)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        No raw materials registered yet. <a href="materials.php" class="text-danger fw-bold">Click here to register ingredients</a>.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_materials as $mat): 
                                    $mid = (int)$mat['id'];
                                    $mname = htmlspecialchars($mat['name']);
                                    $munit = htmlspecialchars($mat['unit']);
                                    $mstock = (int)$mat['current_stock_int'];
                                    $mreorder = (int)$mat['effective_reorder_int'];
                                    $used_prods = $material_usage_map[$mid] ?? [];
                                    
                                    if ($mstock <= 0) {
                                        $s_badge = '<span class="status-badge badge-danger">Depleted (0)</span>';
                                    } elseif ($mstock <= $mreorder) {
                                        $s_badge = '<span class="status-badge badge-warning">Low Stock</span>';
                                    } else {
                                        $s_badge = '<span class="status-badge badge-success">Safe Stock</span>';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $mname; ?></strong>
                                            <div class="text-muted small" style="font-size:11px;">ID: #ING-<?php echo str_pad($mid, 4, '0', STR_PAD_LEFT); ?></div>
                                        </td>
                                        <td>
                                            <span class="stock-badge <?php echo $mstock <= 0 ? 'stock-danger' : ($mstock <= $mreorder ? 'stock-warning' : 'stock-success'); ?>">
                                                <strong><?php echo $mstock; ?> <?php echo $munit; ?></strong>
                                            </span>
                                        </td>
                                        <td><?php echo $mreorder; ?> <?php echo $munit; ?></td>
                                        <td><?php echo $s_badge; ?></td>
                                        <td>
                                            <?php if (empty($used_prods)): ?>
                                                <span class="text-muted small">Not linked to any product</span>
                                            <?php else: ?>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php foreach ($used_prods as $upname): ?>
                                                        <span class="badge bg-light text-dark border" style="font-size:11px;"><?php echo htmlspecialchars($upname); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick='openIngredientAdjustModal(<?php echo $mid; ?>, <?php echo json_encode($mname); ?>, <?php echo json_encode($munit); ?>, <?php echo $mstock; ?>)' style="font-weight:600; font-size:12px;">
                                                <i class="fas fa-edit me-1"></i> Adjust / Restock
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                                            </button>";
                                    }
                                    
                                    echo "
                                        </td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='9' class='text-center text-muted'>No products found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pre-Order Demand Section -->
                <div class="section-header mt-5">
                    <h2>Pre-Order Demand for <?php echo date('F d, Y', strtotime($selected_date)); ?></h2>
                </div>

                <?php if (!empty($daily_demand)): ?>
                <div class="row mb-4">
                    <?php foreach($daily_demand as $product => $total_qty): ?>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-header bg-warning text-dark">
                                <?php echo htmlspecialchars($product); ?>
                            </div>
                            <div class="card-body">
                                <h5 class="card-title display-5 fw-bold"><?php echo $total_qty; ?></h5>
                                <p class="card-text">units required</p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Pre-Order #</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Pickup Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($preorder_result && mysqli_num_rows($preorder_result) > 0) {
                                while ($preorder = mysqli_fetch_assoc($preorder_result)) {
                                    echo "
                                    <tr>
                                        <td><strong>#{$preorder['id']}</strong></td>
                                        <td>" . htmlspecialchars($preorder['full_name']) . "</td>
                                        <td>" . htmlspecialchars($preorder['product_name']) . "</td>
                                        <td><strong>" . $preorder['quantity'] . "</strong></td>
                                        <td>" . htmlspecialchars($preorder['preferred_pickup_time']) . "</td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center text-muted'>No pre-orders scheduled for this date.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Create Inventory Modal -->
    <div class="modal fade" id="bulkCreateInventoryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Create Inventory Records</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="bulk_create_inventory" value="1">
                        <input type="hidden" name="bulk_auto_topup_existing" value="0">
                        <div class="row g-3 align-items-end mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Inventory Date *</label>
                                <input type="date" name="inventory_date" id="bulkInventoryDate" class="form-control" value="<?php echo $selected_date; ?>" required>
                            </div>
                            <div class="col-md-8 d-flex justify-content-md-end gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-primary" id="addBulkRowBtn">
                                    <i class="fas fa-plus"></i> Add Row
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="autoFillMissingBtn">
                                    <i class="fas fa-magic"></i> Add Products Without Inventory (<?php echo count($products_without_inventory); ?>)
                                </button>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="bulkAutoTopupExisting" name="bulk_auto_topup_existing" value="1" checked>
                            <label class="form-check-label" for="bulkAutoTopupExisting">
                                Auto top-up existing rows (+<?php echo (int)$default_inventory_seed_stock; ?>)
                            </label>
                        </div>
                        <p class="text-muted small mb-2">Add multiple products and submit once to speed up inventory creation.</p>
                        <div id="bulkRows"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create All Inventory Rows</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <template id="bulkRowTemplate">
        <div class="row g-2 align-items-end border rounded p-2 mb-2 bulk-row">
            <div class="col-md-6">
                <label class="form-label">Product *</label>
                <select name="bulk_product_ids[]" class="form-select bulk-product-select" required>
                    <option value="">Select product</option>
                    <?php foreach ($all_products as $prod): ?>
                        <option value="<?php echo $prod['id']; ?>"><?php echo htmlspecialchars($prod['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Initial Stock *</label>
                <input type="number" name="bulk_initial_stocks[]" class="form-control" min="0" value="<?php echo (int)$default_inventory_seed_stock; ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Min Level *</label>
                <input type="number" name="bulk_min_stock_levels[]" class="form-control" min="1" value="5" required>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger w-100 remove-bulk-row">
                    <i class="fas fa-times"></i> Remove
                </button>
            </div>
        </div>
    </template>
    
    <!-- Inventory Adjustment Modal -->
    <div class="modal fade modern-form-modal" id="inventoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div>
                        <h5 class="modal-title">Adjust Inventory</h5>
                        <p class="modal-subtitle">Record stock changes, received batches, or write off losses.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="inventoryDetails">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Inventory Modal -->
    <div class="modal fade modern-form-modal" id="createInventoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div>
                        <h5 class="modal-title">Create Inventory Record</h5>
                        <p class="modal-subtitle">Initialize daily stock allotment and minimum safety thresholds.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="create_inventory" value="1">
                        <input type="hidden" name="inventory_date" value="<?php echo $selected_date; ?>">
                        <input type="hidden" name="auto_topup_existing" value="0">
                        
                        <!-- Section 1: Product Selection -->
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <span class="form-section-title"><i class="fas fa-box"></i> Product Selection</span>
                                <span class="form-req-pill">Required</span>
                            </div>
                            <div class="form-group-modern">
                                <label class="form-label-modern">Product Item <span class="form-req-star">*</span></label>
                                <div class="form-input-wrap">
                                    <i class="fas fa-tag form-input-icon"></i>
                                    <select name="product_id" id="createProductId" class="form-select" required>
                                        <option value="">Select product</option>
                                        <?php foreach ($all_products as $prod): ?>
                                            <?php $has_existing_row = !empty($products_with_inventory_lookup[(int)$prod['id']]); ?>
                                            <option value="<?php echo $prod['id']; ?>">
                                                <?php echo htmlspecialchars($prod['name']); ?><?php echo $has_existing_row ? ' (has inventory row)' : ' (new row)'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="createProductRecipeInfo" class="alert alert-info py-2 px-3 small mt-2 d-none" style="font-size: 12px; border-radius: 8px;">
                                    <i class="fas fa-boxes-stacked me-1"></i> <span id="createRecipeYieldText">Calculated from raw ingredients.</span>
                                    <button type="button" class="btn btn-sm btn-link p-0 ms-2 text-decoration-none fw-bold" id="btnUseCalcStock" onclick="applyCalculatedStockToModal()">Use This Stock</button>
                                </div>
                                <div class="form-helper-text"><i class="fas fa-info-circle"></i> Stock quantities are automatically calculated from raw ingredient inventory.</div>
                            </div>
                            <div class="form-group-modern">
                                <label class="form-label-modern">Inventory Date</label>
                                <div class="form-input-wrap">
                                    <i class="fas fa-calendar-day form-input-icon"></i>
                                    <input type="date" class="form-control" value="<?php echo $selected_date; ?>" disabled>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: Stock Levels -->
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <span class="form-section-title"><i class="fas fa-cubes"></i> Stock Quantities (Whole Numbers)</span>
                                <span class="form-req-pill">Required</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Initial Stock (Whole Number) <span class="form-req-star">*</span></label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-layer-group form-input-icon"></i>
                                            <input type="number" step="1" name="initial_stock" id="createInitialStock" class="form-control" min="0" value="<?php echo (int)$default_inventory_seed_stock; ?>" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Minimum Safety Level <span class="form-req-star">*</span></label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-bell form-input-icon"></i>
                                            <input type="number" step="1" name="min_stock_level" class="form-control" min="1" value="5" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="autoTopupExisting" name="auto_topup_existing" value="1" checked>
                                <label class="form-check-label" for="autoTopupExisting" style="font-size: 13px; color: #344054;">
                                    Auto top-up existing row (+<?php echo (int)$default_inventory_seed_stock; ?>)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-modal-primary"><i class="fas fa-plus"></i> Create Inventory</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Product Recipe Ingredients Modal -->
    <div class="modal fade modern-form-modal" id="productRecipeModal" tabindex="-1" aria-labelledby="productRecipeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon" style="background:#fff1f0; color:#b3261e;">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div>
                        <h5 class="modal-title" id="productRecipeModalLabel">Product Recipe &amp; Ingredients Setup</h5>
                        <p class="modal-subtitle">Define raw ingredients required per 1 unit/serving in whole numbers.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="inventory.php" id="productRecipeForm">
                    <input type="hidden" name="save_product_recipe" value="1">
                    <input type="hidden" name="product_id" id="recipe_modal_product_id">
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                    
                    <div class="modal-body">
                        <!-- Product Info Header Card -->
                        <div class="p-3 mb-3 border rounded-3 bg-light d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <span class="text-muted small text-uppercase fw-semibold" style="font-size:11px;">Menu Product</span>
                                <h5 class="mb-0 fw-bold text-dark" id="recipe_modal_product_name">—</h5>
                                <span class="text-muted small" id="recipe_modal_product_meta">Category &bull; Price</span>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small d-block" style="font-size:11px;">Ingredient-Derived Capacity</span>
                                <span class="badge bg-success fs-6" id="recipe_modal_live_yield" style="font-size: 1rem !important;">0 units</span>
                            </div>
                        </div>

                        <!-- Whole Numbers Required Note -->
                        <div class="alert alert-light border small text-muted mb-3" style="font-size:12px; background:#fff8ef; border-color:#fedf89 !important;">
                            <i class="fas fa-info-circle text-warning me-1"></i> <strong>Whole Numbers Required:</strong> Enter the quantity needed of each raw ingredient in <strong>whole numbers</strong> (e.g. 1 kg, 2 pcs, 1 pack). Product stock quantities in Inventory will automatically be calculated based on your ingredient availability.
                        </div>

                        <!-- Ingredients Checklist -->
                        <div class="form-section-card">
                            <div class="form-section-head d-flex justify-content-between align-items-center">
                                <span class="form-section-title"><i class="fas fa-carrot"></i> Select Ingredients &amp; Quantities</span>
                                <span class="badge bg-light text-dark border">Step: 1 (Whole Numbers)</span>
                            </div>

                            <div class="table-responsive border rounded-2 bg-white">
                                <table class="table table-sm align-middle mb-0" style="font-size: 13px;">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 45px;" class="text-center">Use</th>
                                            <th>Raw Material / Ingredient</th>
                                            <th style="width: 140px;">Store Stock</th>
                                            <th style="width: 180px;">Qty Needed / Unit</th>
                                            <th style="width: 110px;" class="text-end">Max Yield</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recipe_modal_ingredients_tbody">
                                        <!-- Populated dynamically via JS -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Live Summary Alert -->
                        <div id="recipe_modal_summary_alert" class="alert alert-secondary py-2 px-3 small mt-3 mb-0" style="font-size: 12px;">
                            <i class="fas fa-calculator me-1"></i> Select at least 1 ingredient to calculate available stock.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-modal-primary" style="background:#b3261e; border-color:#b3261e;">
                            <i class="fas fa-check"></i> Save Recipe &amp; Calculate Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Restock / Adjust Raw Ingredient Modal -->
    <div class="modal fade modern-form-modal" id="adjustIngredientModal" tabindex="-1" aria-labelledby="adjustIngredientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon" style="background:#fff1f0; color:#b3261e;">
                        <i class="fas fa-carrot"></i>
                    </div>
                    <div>
                        <h5 class="modal-title" id="adjustIngredientModalLabel">Adjust / Restock Raw Ingredient</h5>
                        <p class="modal-subtitle">Update raw material stock in whole numbers. Dependent products will automatically recalculate.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="inventory.php">
                    <input type="hidden" name="adjust_ingredient_stock" value="1">
                    <div class="modal-body">
                        <div class="form-group-modern mb-3">
                            <label class="form-label-modern">Select Ingredient <span class="form-req-star">*</span></label>
                            <select name="material_id" id="adj_ing_material_id" class="form-select" required onchange="handleIngredientSelectChange(this)">
                                <option value="">Select ingredient</option>
                                <?php foreach ($all_materials as $m): ?>
                                    <option value="<?php echo $m['id']; ?>" data-name="<?php echo htmlspecialchars($m['name']); ?>" data-unit="<?php echo htmlspecialchars($m['unit']); ?>" data-stock="<?php echo (int)$m['current_stock_int']; ?>">
                                        <?php echo htmlspecialchars($m['name']); ?> (Current: <?php echo (int)$m['current_stock_int']; ?> <?php echo htmlspecialchars($m['unit']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="p-3 mb-3 border rounded-2 bg-light d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small">Current Store Stock</span>
                                <h6 class="mb-0 fw-bold text-dark" id="adj_ing_current_stock_text">—</h6>
                            </div>
                            <span class="badge bg-secondary" id="adj_ing_unit_badge">—</span>
                        </div>

                        <div class="form-group-modern mb-3">
                            <label class="form-label-modern">Action Type <span class="form-req-star">*</span></label>
                            <select name="adjustment_type" class="form-select" required>
                                <option value="add">Add to Stock (Restocked trip / delivery)</option>
                                <option value="set">Set Exact Stock Count (Physical count audit)</option>
                            </select>
                        </div>

                        <div class="form-group-modern mb-3">
                            <label class="form-label-modern">Quantity (Whole Number) <span class="form-req-star">*</span></label>
                            <div class="form-input-wrap">
                                <i class="fas fa-layer-group form-input-icon"></i>
                                <input type="number" step="1" min="0" name="quantity" id="adj_ing_quantity" class="form-control" placeholder="e.g. 10" value="10" required onkeydown="if(event.key==='.'||event.key===',') event.preventDefault();" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                            </div>
                            <div class="form-helper-text"><i class="fas fa-info-circle"></i> Must be a whole number integer (no decimals).</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-modal-primary" style="background:#b3261e; border-color:#b3261e;">
                            <i class="fas fa-save"></i> Save Ingredient Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Min Stock Modal -->
    <div class="modal fade modern-form-modal" id="minStockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div>
                        <h5 class="modal-title">Update Minimum Stock Level</h5>
                        <p class="modal-subtitle">Set threshold quantity to trigger automated reorder notifications.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="update_min_stock" value="1">
                        <input type="hidden" name="product_id" id="minStockProductId">
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <span class="form-section-title"><i class="fas fa-bell"></i> Threshold Limit</span>
                                <span class="form-req-pill">Required</span>
                            </div>
                            <div class="form-group-modern">
                                <label class="form-label-modern">Minimum Stock Quantity <span class="form-req-star">*</span></label>
                                <div class="form-input-wrap">
                                    <i class="fas fa-sort-numeric-up-alt form-input-icon"></i>
                                    <input type="number" name="min_stock_level" id="minStockValue" class="form-control" min="1" required>
                                </div>
                                <div class="form-helper-text"><i class="fas fa-info-circle"></i> An alert will appear on the dashboard whenever stock dips to or below this level.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-modal-primary"><i class="fas fa-save"></i> Update Min Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Inventory Form -->
    <form id="deleteInventoryForm" method="POST" style="display:none;">
        <input type="hidden" name="delete_inventory" value="1">
        <input type="hidden" name="product_id" id="deleteProductId">
    </form>
    
    <!-- Inventory History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Inventory History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="historyDetails">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Theme Toggler
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        // Check local storage
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Session Messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?php echo $_SESSION['success']; ?>',
                timer: 2000,
                showConfirmButton: false
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?php echo $_SESSION['error']; ?>'
            });
        <?php unset($_SESSION['error']); endif; ?>

        function loadInventoryDetails(productId, date) {
            $.ajax({
                url: 'get_inventory_details.php',
                type: 'GET',
                data: { id: productId, date: date },
                success: function(response) {
                    $('#inventoryDetails').html(response);
                }
            });
        }
        
        function loadInventoryHistory(productId) {
            $.ajax({
                url: 'get_inventory_history.php',
                type: 'GET',
                data: { id: productId },
                success: function(response) {
                    $('#historyDetails').html(response);
                }
            });
        }

        function setProductId(productId) {
            $('#createProductId').val(productId);
        }

        const bulkRows = document.getElementById('bulkRows');
        const bulkRowTemplate = document.getElementById('bulkRowTemplate');
        const bulkModalEl = document.getElementById('bulkCreateInventoryModal');
        const autoFillMissingBtn = document.getElementById('autoFillMissingBtn');
        const addBulkRowBtn = document.getElementById('addBulkRowBtn');
        const productsWithoutInventory = <?php echo json_encode($products_without_inventory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function addBulkRow(productId = '', initialStock = <?php echo (int)$default_inventory_seed_stock; ?>, minStockLevel = 5) {
            if (!bulkRowTemplate || !bulkRows) return;
            const row = bulkRowTemplate.content.firstElementChild.cloneNode(true);
            const productSelect = row.querySelector('.bulk-product-select');
            const initialInput = row.querySelector('input[name="bulk_initial_stocks[]"]');
            const minInput = row.querySelector('input[name="bulk_min_stock_levels[]"]');

            if (productSelect) productSelect.value = String(productId);
            if (initialInput) initialInput.value = String(initialStock);
            if (minInput) minInput.value = String(minStockLevel);
            bulkRows.appendChild(row);
        }

        if (addBulkRowBtn) {
            addBulkRowBtn.addEventListener('click', function () {
                addBulkRow();
            });
        }

        if (autoFillMissingBtn) {
            autoFillMissingBtn.addEventListener('click', function () {
                if (!bulkRows) return;
                bulkRows.innerHTML = '';
                if (Array.isArray(productsWithoutInventory) && productsWithoutInventory.length > 0) {
                    productsWithoutInventory.forEach(function (product) {
                        addBulkRow(product.id, <?php echo (int)$default_inventory_seed_stock; ?>, 5);
                    });
                } else {
                    addBulkRow();
                }
            });
        }

        if (bulkRows) {
            bulkRows.addEventListener('click', function (event) {
                const button = event.target.closest('.remove-bulk-row');
                if (!button) return;

                const row = button.closest('.bulk-row');
                if (!row) return;

                row.remove();
                if (bulkRows.children.length === 0) {
                    addBulkRow();
                }
            });
        }

        if (bulkModalEl) {
            bulkModalEl.addEventListener('shown.bs.modal', function () {
                if (bulkRows && bulkRows.children.length === 0) {
                    addBulkRow();
                }
            });
        }

        function setMinStockForm(productId, currentMinStock) {
            $('#minStockProductId').val(productId);
            $('#minStockValue').val(currentMinStock);
        }

        // Raw Materials & Recipe Ingredients Data for dynamic calculations
        const allAvailableMaterials = <?php echo json_encode($all_materials, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const allProductRecipes = <?php echo json_encode($product_recipes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function openProductRecipeModal(product) {
            document.getElementById('recipe_modal_product_id').value = product.id;
            document.getElementById('recipe_modal_product_name').textContent = product.name;
            document.getElementById('recipe_modal_product_meta').textContent = product.category + ' • ₱' + parseFloat(product.price).toFixed(2);

            const tbody = document.getElementById('recipe_modal_ingredients_tbody');
            tbody.innerHTML = '';

            const recipeMap = {};
            if (product.recipe && Array.isArray(product.recipe)) {
                product.recipe.forEach(r => {
                    recipeMap[parseInt(r.material_id, 10)] = parseInt(r.quantity_needed_int, 10) || 1;
                });
            }

            if (allAvailableMaterials && allAvailableMaterials.length > 0) {
                allAvailableMaterials.forEach(mat => {
                    const mid = parseInt(mat.id, 10);
                    const isChecked = recipeMap.hasOwnProperty(mid);
                    const qtyVal = isChecked ? Math.max(1, recipeMap[mid]) : 1;
                    const stock = parseInt(mat.current_stock_int, 10) || 0;
                    const reorder = parseInt(mat.effective_reorder_int, 10) || 5;

                    const row = document.createElement('tr');
                    row.className = 'recipe-ing-row';
                    row.setAttribute('data-id', mid);
                    row.setAttribute('data-name', mat.name);
                    row.setAttribute('data-stock', stock);
                    row.setAttribute('data-unit', mat.unit);

                    let stockBadgeClass = stock <= 0 ? 'bg-danger' : (stock <= reorder ? 'bg-warning text-dark' : 'bg-light text-dark border');

                    row.innerHTML = `
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input recipe-chk" name="recipe_ingredients[${mid}][selected]" value="1" id="rec_chk_${mid}" ${isChecked ? 'checked' : ''} onchange="recalculateLiveRecipeYield()">
                        </td>
                        <td>
                            <label for="rec_chk_${mid}" class="fw-bold mb-0 text-dark" style="cursor:pointer;">${mat.name}</label>
                            <span class="text-muted small d-block" style="font-size:11px;">Unit: ${mat.unit}</span>
                        </td>
                        <td>
                            <span class="badge ${stockBadgeClass}" style="font-size:11px;">
                                ${stock} ${mat.unit}
                            </span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <input type="number" step="1" min="1" name="recipe_ingredients[${mid}][qty]" class="form-control form-control-sm text-end recipe-qty" value="${qtyVal ? Math.round(qtyVal) : 1}" ${isChecked ? '' : 'disabled'} onkeydown="if(event.key==='.'||event.key===',') event.preventDefault();" oninput="this.value = this.value.replace(/[^0-9]/g, ''); recalculateLiveRecipeYield();" style="width: 80px;">
                                <span class="small text-muted fw-semibold" style="font-size:11px;">${mat.unit}</span>
                            </div>
                        </td>
                        <td class="text-end">
                            <span class="recipe-item-yield fw-bold text-muted" style="font-size:12px;">—</span>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">No raw materials registered in system. <a href="materials.php" class="text-danger fw-bold">Register materials here</a>.</td></tr>';
            }

            recalculateLiveRecipeYield();
            new bootstrap.Modal(document.getElementById('productRecipeModal')).show();
        }

        function recalculateLiveRecipeYield() {
            let minYield = Infinity;
            let limitingName = '';
            let selectedCount = 0;

            document.querySelectorAll('.recipe-ing-row').forEach(row => {
                const chk = row.querySelector('.recipe-chk');
                const qtyInput = row.querySelector('.recipe-qty');
                const yieldCell = row.querySelector('.recipe-item-yield');
                const stock = parseInt(row.getAttribute('data-stock'), 10) || 0;
                const name = row.getAttribute('data-name') || '';

                if (chk && chk.checked) {
                    selectedCount++;
                    qtyInput.disabled = false;
                    let qty = parseInt(qtyInput.value, 10);
                    if (isNaN(qty) || qty < 1) {
                        qty = 1;
                        qtyInput.value = 1;
                    }
                    const canMake = Math.floor(stock / qty);
                    yieldCell.textContent = canMake + ' units';
                    if (canMake < minYield) {
                        minYield = canMake;
                        limitingName = name;
                    }
                } else {
                    qtyInput.disabled = true;
                    yieldCell.textContent = '—';
                }
            });

            const yieldBadge = document.getElementById('recipe_modal_live_yield');
            const summaryAlert = document.getElementById('recipe_modal_summary_alert');

            if (selectedCount === 0) {
                yieldBadge.textContent = '0 units';
                yieldBadge.className = 'badge bg-secondary fs-6';
                summaryAlert.className = 'alert alert-secondary py-2 px-3 small mt-3 mb-0';
                summaryAlert.innerHTML = '<i class="fas fa-info-circle me-1"></i> No ingredients selected. Check the ingredients required to prepare this product.';
            } else {
                const finalYield = (minYield === Infinity || minYield < 0) ? 0 : minYield;
                yieldBadge.textContent = finalYield + ' units';
                if (finalYield === 0) {
                    yieldBadge.className = 'badge bg-danger fs-6';
                    summaryAlert.className = 'alert alert-danger py-2 px-3 small mt-3 mb-0';
                    summaryAlert.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> <strong>Out of Stock:</strong> Raw material <strong>' + limitingName + '</strong> has insufficient stock. This menu item cannot be prepared.';
                } else {
                    yieldBadge.className = 'badge bg-success fs-6';
                    summaryAlert.className = 'alert alert-success py-2 px-3 small mt-3 mb-0';
                    summaryAlert.innerHTML = '<i class="fas fa-check-circle me-1"></i> Available ingredient stock allows up to <strong>' + finalYield + ' units</strong> of this product (limited by <strong>' + limitingName + '</strong>).';
                }
            }
        }

        function openIngredientAdjustModal(id = 0, name = '', unit = '', currentStock = 0) {
            const selectEl = document.getElementById('adj_ing_material_id');
            const stockText = document.getElementById('adj_ing_current_stock_text');
            const unitBadge = document.getElementById('adj_ing_unit_badge');
            const qtyInput = document.getElementById('adj_ing_quantity');

            if (id > 0) {
                selectEl.value = String(id);
                stockText.textContent = currentStock + ' ' + unit;
                unitBadge.textContent = unit;
            } else {
                selectEl.value = '';
                stockText.textContent = '—';
                unitBadge.textContent = '—';
            }
            qtyInput.value = 10;
            new bootstrap.Modal(document.getElementById('adjustIngredientModal')).show();
        }

        function handleIngredientSelectChange(selectEl) {
            const opt = selectEl.options[selectEl.selectedIndex];
            const stockText = document.getElementById('adj_ing_current_stock_text');
            const unitBadge = document.getElementById('adj_ing_unit_badge');
            if (opt && opt.value) {
                const stock = opt.getAttribute('data-stock') || '0';
                const unit = opt.getAttribute('data-unit') || '';
                stockText.textContent = stock + ' ' + unit;
                unitBadge.textContent = unit;
            } else {
                stockText.textContent = '—';
                unitBadge.textContent = '—';
            }
        }

        // Live recipe yield preview on Create Inventory Modal
        const createProdSelect = document.getElementById('createProductId');
        if (createProdSelect) {
            createProdSelect.addEventListener('change', function() {
                const pid = parseInt(this.value, 10);
                const infoBox = document.getElementById('createProductRecipeInfo');
                const yieldText = document.getElementById('createRecipeYieldText');
                const btnUse = document.getElementById('btnUseCalcStock');

                if (allProductRecipes && allProductRecipes[pid] && allProductRecipes[pid].length > 0) {
                    const recipe = allProductRecipes[pid];
                    let minYield = Infinity;
                    let limitingName = '';
                    recipe.forEach(ing => {
                        const have = parseInt(ing.material_stock, 10) || 0;
                        const need = Math.max(1, parseInt(ing.quantity_needed_int, 10) || 1);
                        const canMake = Math.floor(have / need);
                        if (canMake < minYield) {
                            minYield = canMake;
                            limitingName = ing.material_name;
                        }
                    });
                    const finalYield = (minYield === Infinity || minYield < 0) ? 0 : minYield;
                    window._currentCalculatedStock = finalYield;
                    infoBox.classList.remove('d-none');
                    yieldText.innerHTML = '<strong>' + finalYield + ' units</strong> can be prepared based on raw ingredients (' + (finalYield <= 0 ? '<span class="text-danger">Limited by ' + limitingName + '</span>' : 'Limited by ' + limitingName) + ').';
                    btnUse.classList.remove('d-none');
                } else {
                    infoBox.classList.remove('d-none');
                    yieldText.innerHTML = 'No recipe linked yet. Click "+ Link Ingredients" in the table to set raw ingredients in whole numbers.';
                    btnUse.classList.add('d-none');
                }
            });
        }

        function applyCalculatedStockToModal() {
            if (window._currentCalculatedStock !== undefined) {
                const input = document.getElementById('createInitialStock');
                if (input) {
                    input.value = window._currentCalculatedStock;
                }
            }
        }

        function deleteInventory(productId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "This will archive the inventory record.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, archive it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#deleteProductId').val(productId);
                    $('#deleteInventoryForm').submit();
                }
            })
        }
    </script>
</body>
</html>
