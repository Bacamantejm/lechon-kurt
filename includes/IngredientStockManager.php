<?php
/**
 * IngredientStockManager.php
 * Handles Bill of Materials (BOM) ingredient tracking, automated deduction upon order placement,
 * low-stock alerting, store-level inventory synchronization, and automatic menu item disabling/reactivation.
 */

if (!defined('IN_APP') && !defined('ROOT_PATH')) {
    // Basic protection if loaded directly
}

class IngredientStockManager {

    /**
     * Deduct required ingredients for all products in an order.
     * Prevents double deductions using a transaction guard.
     */
    public static function deductOrderIngredients($conn, $order_id) {
        $order_id = intval($order_id);
        if ($order_id <= 0 || !$conn) {
            return false;
        }

        // 1. Check if this order has already had ingredients deducted
        $check_deducted = mysqli_prepare($conn, "SELECT id, seller_id, order_number FROM orders WHERE id = ? LIMIT 1");
        if (!$check_deducted) return false;
        mysqli_stmt_bind_param($check_deducted, "i", $order_id);
        mysqli_stmt_execute($check_deducted);
        $order_res = mysqli_stmt_get_result($check_deducted);
        $order_row = mysqli_fetch_assoc($order_res);
        mysqli_stmt_close($check_deducted);

        if (!$order_row) {
            return false;
        }

        $store_id = intval($order_row['seller_id'] ?? 0);
        $order_num = $order_row['order_number'] ?? ('ORD-' . $order_id);

        // Check if an audit log for this order deduction already exists to ensure idempotency
        $dup_check = mysqli_prepare($conn, "SELECT id FROM inventory_history WHERE notes LIKE ? LIMIT 1");
        if ($dup_check) {
            $deduct_note_pattern = "%Deducted for Order #" . $order_num . "%";
            mysqli_stmt_bind_param($dup_check, "s", $deduct_note_pattern);
            mysqli_stmt_execute($dup_check);
            mysqli_stmt_store_result($dup_check);
            if (mysqli_stmt_num_rows($dup_check) > 0) {
                mysqli_stmt_close($dup_check);
                // Already deducted, do not deduct again
                return true;
            }
            mysqli_stmt_close($dup_check);
        }

        // 2. Fetch all items in this order
        $items_stmt = mysqli_prepare($conn, "SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        if (!$items_stmt) return false;
        mysqli_stmt_bind_param($items_stmt, "i", $order_id);
        mysqli_stmt_execute($items_stmt);
        $items_res = mysqli_stmt_get_result($items_stmt);

        $affected_materials = [];

        while ($item = mysqli_fetch_assoc($items_res)) {
            $prod_id = intval($item['product_id']);
            $order_qty = floatval($item['quantity']);
            if ($prod_id <= 0 || $order_qty <= 0) continue;

            // Fetch Bill of Materials (BOM) recipe for this product
            $bom_stmt = mysqli_prepare($conn, "SELECT bom.material_id, bom.quantity_needed, m.name, m.unit 
                                                FROM bill_of_materials bom
                                                INNER JOIN materials m ON m.id = bom.material_id
                                                WHERE bom.product_id = ?");
            if ($bom_stmt) {
                mysqli_stmt_bind_param($bom_stmt, "i", $prod_id);
                mysqli_stmt_execute($bom_stmt);
                $bom_res = mysqli_stmt_get_result($bom_stmt);

                while ($recipe = mysqli_fetch_assoc($bom_res)) {
                    $mat_id = intval($recipe['material_id']);
                    $needed_per_unit = floatval($recipe['quantity_needed']);
                    $total_deduct = $order_qty * $needed_per_unit;

                    if ($mat_id > 0 && $total_deduct > 0) {
                        self::deductSingleIngredientStock($conn, $store_id, $mat_id, $total_deduct, $order_num, $recipe['name']);
                        $affected_materials[$mat_id] = true;
                    }
                }
                mysqli_stmt_close($bom_stmt);
            }
        }
        mysqli_stmt_close($items_stmt);

        // 3. Re-evaluate and sync availability for all products in this store
        if ($store_id > 0) {
            self::syncProductAvailabilityForStore($conn, $store_id);
        }

        return true;
    }

    /**
     * Deduct required ingredients for a pre-order.
     */
    public static function deductPreorderIngredients($conn, $preorder_id) {
        $preorder_id = intval($preorder_id);
        if ($preorder_id <= 0 || !$conn) return false;

        $po_stmt = mysqli_prepare($conn, "SELECT po.id, po.product_id, po.quantity, p.seller_id 
                                           FROM pre_orders po 
                                           LEFT JOIN products p ON p.id = po.product_id 
                                           WHERE po.id = ? LIMIT 1");
        if (!$po_stmt) return false;
        mysqli_stmt_bind_param($po_stmt, "i", $preorder_id);
        mysqli_stmt_execute($po_stmt);
        $res = mysqli_stmt_get_result($po_stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($po_stmt);

        if (!$row) return false;

        $store_id = intval($row['seller_id'] ?? 1);
        $product_id = intval($row['product_id']);
        $quantity = floatval($row['quantity']);
        $ref_code = 'PRE-' . $preorder_id;

        return self::deductProductIngredients($conn, $store_id, $product_id, $quantity, $ref_code);
    }

    /**
     * Deduct required BOM ingredients for a specific product and quantity.
     */
    public static function deductProductIngredients($conn, $store_id, $product_id, $quantity, $ref_code = '') {
        $store_id = intval($store_id);
        $product_id = intval($product_id);
        $quantity = floatval($quantity);
        if ($product_id <= 0 || $quantity <= 0 || !$conn) return false;

        $bom_stmt = mysqli_prepare($conn, "SELECT bom.material_id, bom.quantity_needed, m.name, m.unit 
                                            FROM bill_of_materials bom
                                            INNER JOIN materials m ON m.id = bom.material_id
                                            WHERE bom.product_id = ?");
        if ($bom_stmt) {
            mysqli_stmt_bind_param($bom_stmt, "i", $product_id);
            mysqli_stmt_execute($bom_stmt);
            $bom_res = mysqli_stmt_get_result($bom_stmt);

            while ($recipe = mysqli_fetch_assoc($bom_res)) {
                $mat_id = intval($recipe['material_id']);
                $needed_per_unit = floatval($recipe['quantity_needed']);
                $total_deduct = $quantity * $needed_per_unit;

                if ($mat_id > 0 && $total_deduct > 0) {
                    self::deductSingleIngredientStock($conn, $store_id, $mat_id, $total_deduct, $ref_code, $recipe['name']);
                }
            }
            mysqli_stmt_close($bom_stmt);
        }

        if ($store_id > 0) {
            self::syncProductAvailabilityForStore($conn, $store_id);
        }
        return true;
    }

    /**
     * Deduct stock for a single ingredient from store_material_inventory or materials table.
     */
    private static function deductSingleIngredientStock($conn, $store_id, $material_id, $deduct_qty, $order_num, $material_name) {
        $material_id = intval($material_id);
        $store_id = intval($store_id);
        $deduct_qty = floatval($deduct_qty);

        // 1. Try deducting from store_material_inventory if store_id is set
        $has_store_inv = false;
        if ($store_id > 0) {
            // Check if store record exists
            $check_smi = mysqli_prepare($conn, "SELECT id, current_stock FROM store_material_inventory WHERE store_id = ? AND material_id = ? LIMIT 1");
            if ($check_smi) {
                mysqli_stmt_bind_param($check_smi, "ii", $store_id, $material_id);
                mysqli_stmt_execute($check_smi);
                $smi_res = mysqli_stmt_get_result($check_smi);
                $smi_row = mysqli_fetch_assoc($smi_res);
                mysqli_stmt_close($check_smi);

                if ($smi_row) {
                    $has_store_inv = true;
                    $prev_stock = floatval($smi_row['current_stock']);
                    $new_stock = max(0.0, $prev_stock - $deduct_qty);

                    $upd_smi = mysqli_prepare($conn, "UPDATE store_material_inventory SET current_stock = ?, updated_at = NOW() WHERE id = ?");
                    if ($upd_smi) {
                        mysqli_stmt_bind_param($upd_smi, "di", $new_stock, $smi_row['id']);
                        mysqli_stmt_execute($upd_smi);
                        mysqli_stmt_close($upd_smi);
                    }
                }
            }
        }

        // 2. Also update primary materials table to keep global/local stock in sync
        $prev_mat_stock = 0.0;
        $get_mat = mysqli_prepare($conn, "SELECT current_stock FROM materials WHERE id = ? LIMIT 1");
        if ($get_mat) {
            mysqli_stmt_bind_param($get_mat, "i", $material_id);
            mysqli_stmt_execute($get_mat);
            $mat_res = mysqli_stmt_get_result($get_mat);
            if ($m_row = mysqli_fetch_assoc($mat_res)) {
                $prev_mat_stock = floatval($m_row['current_stock']);
            }
            mysqli_stmt_close($get_mat);
        }

        $new_mat_stock = max(0.0, $prev_mat_stock - $deduct_qty);
        $upd_mat = mysqli_prepare($conn, "UPDATE materials SET current_stock = ?, last_updated = NOW() WHERE id = ?");
        if ($upd_mat) {
            mysqli_stmt_bind_param($upd_mat, "di", $new_mat_stock, $material_id);
            mysqli_stmt_execute($upd_mat);
            mysqli_stmt_close($upd_mat);
        }

        // 3. Record audit history in inventory_history
        $hist_note = "Deducted for Order #" . $order_num . " (" . $material_name . " -" . number_format($deduct_qty, 2) . ")";
        $hist_stmt = mysqli_prepare($conn, "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, created_at) 
                                             VALUES (0, 'reduce', ?, ?, ?, ?, NOW())");
        if ($hist_stmt) {
            $int_changed = intval(ceil($deduct_qty));
            $int_prev = intval($prev_mat_stock);
            $int_new = intval($new_mat_stock);
            mysqli_stmt_bind_param($hist_stmt, "iiis", $int_changed, $int_prev, $int_new, $hist_note);
            mysqli_stmt_execute($hist_stmt);
            mysqli_stmt_close($hist_stmt);
        }
    }

    /**
     * Re-evaluate and sync availability status for all products belonging to a store.
     * If any required ingredient is out of stock (current_stock <= 0 or < quantity_needed),
     * the product is automatically marked is_available = 0.
     * When ingredients are restocked, products are automatically marked is_available = 1.
     */
    public static function syncProductAvailabilityForStore($conn, $store_id) {
        $store_id = intval($store_id);
        if ($store_id <= 0 || !$conn) {
            return;
        }

        // Fetch all active products for this store
        $prod_stmt = mysqli_prepare($conn, "SELECT id, name, is_available FROM products WHERE seller_id = ? AND is_active = 1");
        if (!$prod_stmt) return;
        mysqli_stmt_bind_param($prod_stmt, "i", $store_id);
        mysqli_stmt_execute($prod_stmt);
        $prod_res = mysqli_stmt_get_result($prod_stmt);

        while ($p = mysqli_fetch_assoc($prod_res)) {
            $p_id = intval($p['id']);
            $current_avail = intval($p['is_available']);

            // Check BOM for this product
            $bom_check = mysqli_prepare($conn, "
                SELECT bom.quantity_needed, 
                       COALESCE(smi.current_stock, m.current_stock, 0) as effective_stock,
                       m.name as material_name
                FROM bill_of_materials bom
                INNER JOIN materials m ON m.id = bom.material_id
                LEFT JOIN store_material_inventory smi ON smi.material_id = bom.material_id AND smi.store_id = ?
                WHERE bom.product_id = ?
            ");

            if ($bom_check) {
                mysqli_stmt_bind_param($bom_check, "ii", $store_id, $p_id);
                mysqli_stmt_execute($bom_check);
                $bom_res = mysqli_stmt_get_result($bom_check);

                $can_make = true;
                $depleted_ingredients = [];

                while ($b = mysqli_fetch_assoc($bom_res)) {
                    $needed = floatval($b['quantity_needed']);
                    $have = floatval($b['effective_stock']);

                    if ($have < $needed || $have <= 0) {
                        $can_make = false;
                        $depleted_ingredients[] = $b['material_name'];
                    }
                }
                mysqli_stmt_close($bom_check);

                // If recipe has ingredients defined
                if (mysqli_num_rows($bom_res) > 0) {
                    if (!$can_make && $current_avail != 0) {
                        // Mark out of stock
                        $upd_p = mysqli_prepare($conn, "UPDATE products SET is_available = 0, stock = 0 WHERE id = ?");
                        if ($upd_p) {
                            mysqli_stmt_bind_param($upd_p, "i", $p_id);
                            mysqli_stmt_execute($upd_p);
                            mysqli_stmt_close($upd_p);
                        }

                        // Send shop alert notification
                        self::createStoreNotification(
                            $conn, 
                            $store_id, 
                            'ingredient_out_of_stock',
                            'Item Disabled: ' . $p['name'],
                            'Product has been auto-disabled because required ingredients ran out: ' . implode(', ', $depleted_ingredients)
                        );
                    } elseif ($can_make && $current_avail == 0) {
                        // Restore availability
                        $upd_p = mysqli_prepare($conn, "UPDATE products SET is_available = 1 WHERE id = ?");
                        if ($upd_p) {
                            mysqli_stmt_bind_param($upd_p, "i", $p_id);
                            mysqli_stmt_execute($upd_p);
                            mysqli_stmt_close($upd_p);
                        }
                    }
                }
            }
        }
        mysqli_stmt_close($prod_stmt);
    }

    /**
     * Restock store ingredients when a supplier delivery is completed.
     */
    public static function restockIngredients($conn, $store_id, $items, $order_code = '') {
        $store_id = intval($store_id);
        if ($store_id <= 0 || empty($items) || !$conn) {
            return false;
        }

        foreach ($items as $item) {
            $material_id = intval($item['material_id']);
            $qty = floatval($item['quantity_supplied'] ?? $item['quantity'] ?? 0);
            $unit = trim((string)($item['unit'] ?? 'kg'));

            if ($material_id > 0 && $qty > 0) {
                // Upsert store_material_inventory
                $upsert_smi = mysqli_prepare($conn, "
                    INSERT INTO store_material_inventory (store_id, material_id, current_stock, unit, last_restocked_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        current_stock = current_stock + VALUES(current_stock),
                        last_restocked_at = NOW()
                ");
                if ($upsert_smi) {
                    mysqli_stmt_bind_param($upsert_smi, "iids", $store_id, $material_id, $qty, $unit);
                    mysqli_stmt_execute($upsert_smi);
                    mysqli_stmt_close($upsert_smi);
                }

                // Also increment materials table
                $upd_mat = mysqli_prepare($conn, "UPDATE materials SET current_stock = current_stock + ?, last_updated = NOW() WHERE id = ?");
                if ($upd_mat) {
                    mysqli_stmt_bind_param($upd_mat, "di", $qty, $material_id);
                    mysqli_stmt_execute($upd_mat);
                    mysqli_stmt_close($upd_mat);
                }

                // Audit history
                $hist_note = "Restocked via Supplier Delivery" . ($order_code ? (" #" . $order_code) : "");
                $hist_stmt = mysqli_prepare($conn, "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, created_at) 
                                                     VALUES (0, 'received', ?, 0, ?, ?, NOW())");
                if ($hist_stmt) {
                    $int_qty = intval(ceil($qty));
                    mysqli_stmt_bind_param($hist_stmt, "iis", $int_qty, $int_qty, $hist_note);
                    mysqli_stmt_execute($hist_stmt);
                    mysqli_stmt_close($hist_stmt);
                }
            }
        }

        // Re-evaluate and unblock all products that now have sufficient stock
        self::syncProductAvailabilityForStore($conn, $store_id);

        return true;
    }

    /**
     * Self-heal database schema for procurement and finance workflow.
     */
    public static function ensureProcurementSchema($conn) {
        static $ensured = false;
        if ($ensured || !$conn) return;

        require_once __DIR__ . '/supplier_helper.php';
        if (function_exists('ensureSupplierProcurementTables')) {
            ensureSupplierProcurementTables($conn);
        }

        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS `procurement_shopping_lists` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `store_id` INT NOT NULL,
              `title` VARCHAR(150) NOT NULL DEFAULT 'Market Shopping List',
              `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
              `items_json` JSON NOT NULL,
              `target_budget` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `actual_spent` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `notes` TEXT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_store_shopping` (`store_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $ensured = true;
    }

    /**
     * Log a self-procured purchase (Shop owner buys stock independently at local market/groceries).
     * Adds stock immediately, updates inventory, unblocks menu items, and submits expense to Finance Dept.
     */
    public static function logSelfProcuredPurchase($conn, $store_id, $vendor_name, $items, $total_amount, $invoice_no = '', $receipt_image = '', $payment_method = 'Cash', $notes = '') {
        self::ensureProcurementSchema($conn);
        $store_id = intval($store_id);
        $total_amount = floatval($total_amount);
        $vendor_name = trim((string)$vendor_name) ?: 'Local Market / Wholesale Club';
        if ($store_id <= 0 || empty($items) || !$conn) {
            return false;
        }

        $order_code = 'SELF-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        // 1. Create order record in supplier_orders
        $ins_ord = mysqli_prepare($conn, "
            INSERT INTO supplier_orders 
                (order_code, supplier_id, store_id, requested_by, procurement_type, vendor_name, receipt_image, invoice_number, status, urgency_level, subtotal_amount, total_amount, payment_status, finance_status, store_notes, received_by_store_at, completed_at, created_at)
            VALUES 
                (?, 0, ?, ?, 'self_procured', ?, ?, ?, 'completed', 'normal', ?, ?, 'paid', 'pending', ?, NOW(), NOW(), NOW())
        ");

        if (!$ins_ord) {
            error_log("Failed to insert self-procured order: " . mysqli_error($conn));
            return false;
        }

        mysqli_stmt_bind_param($ins_ord, "siisssddds", $order_code, $store_id, $store_id, $vendor_name, $receipt_image, $invoice_no, $total_amount, $total_amount, $notes);
        if (!mysqli_stmt_execute($ins_ord)) {
            error_log("Execute failed for self-procured order: " . mysqli_stmt_error($ins_ord));
            mysqli_stmt_close($ins_ord);
            return false;
        }
        $order_id = mysqli_insert_id($conn);
        mysqli_stmt_close($ins_ord);

        // 2. Insert line items
        $ins_item = mysqli_prepare($conn, "
            INSERT INTO supplier_order_items 
                (order_id, material_id, quantity_requested, quantity_supplied, unit, locked_unit_price, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $items_for_restock = [];
        $items_summary_arr = [];

        foreach ($items as $item) {
            $m_id = intval($item['material_id'] ?? 0);
            $qty_bought = max(1, intval(round(floatval($item['quantity_bought'] ?? $item['quantity_supplied'] ?? 0))));
            $unit = trim((string)($item['unit'] ?? 'kg'));
            $unit_cost = floatval($item['unit_cost'] ?? 0);
            $line_total = floatval($item['line_total'] ?? ($qty_bought * $unit_cost));
            $mat_name = trim((string)($item['material_name'] ?? ''));

            if ($m_id > 0 && $qty_bought > 0) {
                if ($ins_item) {
                    mysqli_stmt_bind_param($ins_item, "iiddsdd", $order_id, $m_id, $qty_bought, $qty_bought, $unit, $unit_cost, $line_total);
                    mysqli_stmt_execute($ins_item);
                }

                $items_for_restock[] = [
                    'material_id' => $m_id,
                    'quantity_supplied' => $qty_bought,
                    'unit' => $unit
                ];

                $items_summary_arr[] = ($mat_name ?: "Item #$m_id") . " ({$qty_bought} {$unit})";
            }
        }
        if ($ins_item) mysqli_stmt_close($ins_item);

        // 3. Immediately restock the store ingredient inventory & reactivate menu items
        self::restockIngredients($conn, $store_id, $items_for_restock, $order_code);

        // 4. Submit expense record to Finance Department for liquidation approval
        $items_summary_str = implode(', ', $items_summary_arr);
        $expense_desc = "Self-Procured Stock Replenishment: $order_code ($items_summary_str) from $vendor_name";

        $ins_exp = mysqli_prepare($conn, "
            INSERT INTO expenses 
                (category, description, vendor, amount, payment_method, receipt_image, status, is_recurring, expense_date, recorded_by, source_type, source_id, owner_user_id, is_system_generated)
            VALUES 
                ('Raw Materials', ?, ?, ?, ?, ?, 'pending', 0, NOW(), ?, 'self_procurement', ?, ?, 1)
        ");

        if ($ins_exp) {
            mysqli_stmt_bind_param($ins_exp, "ssdssiii", $expense_desc, $vendor_name, $total_amount, $payment_method, $receipt_image, $store_id, $order_id, $store_id);
            mysqli_stmt_execute($ins_exp);
            mysqli_stmt_close($ins_exp);
        }

        // 5. Audit Log
        mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                             VALUES ($order_id, NULL, 'completed', $store_id, 'Self-procured purchase logged. Stock restocked and submitted to Finance Department.')");

        // 6. Notify Finance Department admins
        $f_admins = mysqli_query($conn, "SELECT id FROM users WHERE user_type = 'admin' LIMIT 5");
        if ($f_admins) {
            while ($fa = mysqli_fetch_assoc($f_admins)) {
                self::createStoreNotification(
                    $conn, 
                    (int)$fa['id'], 
                    'finance_expense_review', 
                    'Procurement Expense Pending Review', 
                    "Store #$store_id submitted a self-procured receipt for ₱" . number_format($total_amount, 2) . " ($order_code) from $vendor_name."
                );
            }
        }

        return $order_code;
    }

    /**
     * Supplier submits invoice to Finance Department for budget approval.
     */
    public static function submitSupplierInvoiceToFinance($conn, $order_id, $supplier_user_id, $notes = '') {
        self::ensureProcurementSchema($conn);
        $order_id = intval($order_id);
        if ($order_id <= 0 || !$conn) return false;

        $ord_chk = mysqli_query($conn, "SELECT id, order_code, store_id, supplier_id, total_amount, status FROM supplier_orders WHERE id = $order_id LIMIT 1");
        $ord = $ord_chk ? mysqli_fetch_assoc($ord_chk) : null;
        if (!$ord) return false;

        $total_amount = floatval($ord['total_amount']);

        // Update supplier_order status
        $upd = mysqli_prepare($conn, "
            UPDATE supplier_orders 
            SET status = 'awaiting_finance_approval', 
                finance_status = 'pending',
                supplier_notes = COALESCE(NULLIF(?, ''), supplier_notes),
                updated_at = NOW() 
            WHERE id = ?
        ");
        if ($upd) {
            mysqli_stmt_bind_param($upd, "si", $notes, $order_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }

        // Check if matching expense record exists, or insert new
        $chk_exp = mysqli_query($conn, "SELECT id FROM expenses WHERE source_type = 'supplier_procurement' AND source_id = $order_id LIMIT 1");
        if ($chk_exp && mysqli_num_rows($chk_exp) > 0) {
            mysqli_query($conn, "UPDATE expenses SET amount = $total_amount, status = 'pending', updated_at = NOW() WHERE source_type = 'supplier_procurement' AND source_id = $order_id");
        } else {
            $supp_name_q = mysqli_query($conn, "SELECT name FROM suppliers WHERE id = " . intval($ord['supplier_id']) . " LIMIT 1");
            $s_name = ($supp_name_q && ($sr = mysqli_fetch_assoc($supp_name_q))) ? $sr['name'] : 'Supplier Vendor';

            $exp_desc = "Supplier Procurement Invoice: " . $ord['order_code'] . " from $s_name";
            $ins_exp = mysqli_prepare($conn, "
                INSERT INTO expenses 
                    (category, description, vendor, amount, payment_method, status, is_recurring, expense_date, recorded_by, source_type, source_id, owner_user_id, is_system_generated)
                VALUES 
                    ('Supplier Invoice', ?, ?, ?, 'Bank Transfer', 'pending', 0, NOW(), ?, 'supplier_procurement', ?, ?, 1)
            ");
            if ($ins_exp) {
                mysqli_stmt_bind_param($ins_exp, "ssdiii", $exp_desc, $s_name, $total_amount, $supplier_user_id, $order_id, $ord['store_id']);
                mysqli_stmt_execute($ins_exp);
                mysqli_stmt_close($ins_exp);
            }
        }

        // Audit log
        mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                             VALUES ($order_id, '{$ord['status']}', 'awaiting_finance_approval', $supplier_user_id, 'Supplier submitted invoice for Finance approval: $notes')");

        // Notify Finance Department
        $f_admins = mysqli_query($conn, "SELECT id FROM users WHERE user_type = 'admin' LIMIT 5");
        if ($f_admins) {
            while ($fa = mysqli_fetch_assoc($f_admins)) {
                self::createStoreNotification(
                    $conn, 
                    (int)$fa['id'], 
                    'finance_invoice_approval', 
                    'Supplier Invoice Pending Approval', 
                    "Invoice for refill request {$ord['order_code']} (₱" . number_format($total_amount, 2) . ") requires Finance Department approval."
                );
            }
        }

        return true;
    }

    /**
     * Finance Department approves procurement order & budget.
     * Updates status in supplier dashboard and provides "Proceed" button.
     */
    public static function approveProcurementByFinance($conn, $order_id, $finance_user_id, $finance_notes = '') {
        self::ensureProcurementSchema($conn);
        $order_id = intval($order_id);
        $finance_user_id = intval($finance_user_id);
        if ($order_id <= 0 || !$conn) return false;

        $ord_chk = mysqli_query($conn, "SELECT * FROM supplier_orders WHERE id = $order_id LIMIT 1");
        $ord = $ord_chk ? mysqli_fetch_assoc($ord_chk) : null;
        if (!$ord) return false;

        // If self-procured, keeps status completed but marks finance_status approved
        $target_status = ($ord['procurement_type'] === 'self_procured') ? 'completed' : 'finance_approved';

        $upd = mysqli_prepare($conn, "
            UPDATE supplier_orders 
            SET status = ?, 
                finance_status = 'approved',
                finance_approved_by = ?,
                finance_approved_at = NOW(),
                finance_notes = COALESCE(NULLIF(?, ''), finance_notes),
                updated_at = NOW() 
            WHERE id = ?
        ");
        if ($upd) {
            mysqli_stmt_bind_param($upd, "sisi", $target_status, $finance_user_id, $finance_notes, $order_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }

        // Approve matching expense record
        mysqli_query($conn, "
            UPDATE expenses 
            SET status = 'approved' 
            WHERE source_id = $order_id 
              AND source_type IN ('supplier_procurement', 'self_procurement')
        ");

        // Audit Log
        mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                             VALUES ($order_id, '{$ord['status']}', '$target_status', $finance_user_id, 'Finance Department approved transaction. $finance_notes')");

        // Notify Supplier (if supplier procurement)
        if ($ord['supplier_id'] > 0) {
            $get_sup_user = mysqli_query($conn, "SELECT user_id FROM suppliers WHERE id = " . intval($ord['supplier_id']) . " LIMIT 1");
            if ($get_sup_user && ($sur = mysqli_fetch_assoc($get_sup_user))) {
                $s_uid = (int)($sur['user_id'] ?? 0);
                if ($s_uid > 0) {
                    self::createStoreNotification(
                        $conn, 
                        $s_uid, 
                        'supplier_finance_approved', 
                        'Invoice Approved by Finance!', 
                        "Finance Department has approved {$ord['order_code']}. You may now proceed with packing and fulfillment."
                    );
                }
            }
        }

        // Notify Store Owner
        self::createStoreNotification(
            $conn, 
            (int)$ord['store_id'], 
            'store_procurement_approved', 
            'Procurement Budget Approved', 
            "Finance Department approved procurement order {$ord['order_code']}."
        );

        return true;
    }

    /**
     * Store Owner confirms receipt of goods in procurement.
     * Automatically increments ingredient stock in inventory and finalizes invoice in Finance.
     */
    public static function confirmStoreDeliveryReceipt($conn, $order_id, $store_id, $notes = '') {
        self::ensureProcurementSchema($conn);
        $order_id = intval($order_id);
        $store_id = intval($store_id);
        if ($order_id <= 0 || !$conn) return false;

        $ord_chk = mysqli_query($conn, "SELECT * FROM supplier_orders WHERE id = $order_id AND store_id = $store_id LIMIT 1");
        $ord = $ord_chk ? mysqli_fetch_assoc($ord_chk) : null;
        if (!$ord) return false;

        // Fetch supplied items
        $fetch_items_q = mysqli_prepare($conn, "SELECT material_id, quantity_supplied, unit FROM supplier_order_items WHERE order_id = ?");
        $items_to_restock = [];
        if ($fetch_items_q) {
            mysqli_stmt_bind_param($fetch_items_q, "i", $order_id);
            mysqli_stmt_execute($fetch_items_q);
            $fi_res = mysqli_stmt_get_result($fetch_items_q);
            while ($fi = mysqli_fetch_assoc($fi_res)) {
                $items_to_restock[] = $fi;
            }
            mysqli_stmt_close($fetch_items_q);
        }

        // 1. Mark order completed & received
        $upd = mysqli_prepare($conn, "
            UPDATE supplier_orders 
            SET status = 'completed', 
                received_by_store_at = NOW(),
                completed_at = NOW(),
                store_notes = COALESCE(NULLIF(?, ''), store_notes),
                updated_at = NOW() 
            WHERE id = ?
        ");
        if ($upd) {
            mysqli_stmt_bind_param($upd, "si", $notes, $order_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }

        // 2. Increment store ingredient stocks & unblock menu products
        self::restockIngredients($conn, $store_id, $items_to_restock, $ord['order_code']);

        // 3. Mark final invoice settled in Finance
        mysqli_query($conn, "UPDATE expenses SET status = 'approved' WHERE source_type = 'supplier_procurement' AND source_id = $order_id");

        // 4. Audit Log
        mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                             VALUES ($order_id, '{$ord['status']}', 'completed', $store_id, 'Store owner confirmed receipt of delivery. Stock replenished.')");

        // 5. Notify Supplier
        if ($ord['supplier_id'] > 0) {
            $get_sup_user = mysqli_query($conn, "SELECT user_id FROM suppliers WHERE id = " . intval($ord['supplier_id']) . " LIMIT 1");
            if ($get_sup_user && ($sur = mysqli_fetch_assoc($get_sup_user))) {
                $s_uid = (int)($sur['user_id'] ?? 0);
                if ($s_uid > 0) {
                    self::createStoreNotification(
                        $conn, 
                        $s_uid, 
                        'supplier_delivery_received', 
                        'Delivery Confirmed by Store Owner', 
                        "Store owner confirmed receipt for {$ord['order_code']}. Order fulfilled successfully!"
                    );
                }
            }
        }

        // 6. Notify Finance Department
        $f_admins = mysqli_query($conn, "SELECT id FROM users WHERE user_type = 'admin' LIMIT 5");
        if ($f_admins) {
            while ($fa = mysqli_fetch_assoc($f_admins)) {
                self::createStoreNotification(
                    $conn, 
                    (int)$fa['id'], 
                    'finance_procurement_finalized', 
                    'Procurement Delivery Finalized', 
                    "Final delivery for {$ord['order_code']} (₱" . number_format($ord['total_amount'], 2) . ") was verified and accepted by the store owner."
                );
            }
        }

        return true;
    }

    /**
     * Send in-app notification to the shop owner
     */
    private static function createStoreNotification($conn, $store_id, $type, $title, $message) {
        $notif_stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        if ($notif_stmt) {
            mysqli_stmt_bind_param($notif_stmt, "isss", $store_id, $type, $title, $message);
            mysqli_stmt_execute($notif_stmt);
            mysqli_stmt_close($notif_stmt);
        }
    }
}
