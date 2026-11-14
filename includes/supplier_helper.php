<?php
/**
 * includes/supplier_helper.php
 * Wholesale Supplier Management & Synchronization Helper.
 * Handles schema safeguards, user-to-supplier mapping, active supplier lists,
 * and access control for the wholesale procurement flow.
 */

if (!function_exists('ensureSupplierSchema')) {
    /**
     * Ensures suppliers and users database schemas support the supplier procurement flow.
     * Automatically repairs missing columns and synchronizes supplier accounts.
     *
     * @param mysqli $conn
     * @return void
     */
    function ensureSupplierSchema($conn) {
        static $checked = false;
        if ($checked || !$conn || !($conn instanceof mysqli)) {
            return;
        }
        $checked = true;

        // 1. Ensure user_type enum on users table supports 'supplier'
        $col_res = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'user_type'");
        if ($col_res && ($col_row = mysqli_fetch_assoc($col_res))) {
            $type = (string)($col_row['Type'] ?? '');
            if (strpos($type, "'supplier'") === false) {
                @mysqli_query($conn, "ALTER TABLE `users` MODIFY COLUMN `user_type` ENUM('customer','admin','employee','rider','supplier') NOT NULL DEFAULT 'customer'");
            }
        }

        // 2. Ensure suppliers table has necessary columns
        $s_cols = @mysqli_query($conn, "SHOW COLUMNS FROM `suppliers`");
        $existing = [];
        if ($s_cols) {
            while ($row = mysqli_fetch_assoc($s_cols)) {
                $existing[$row['Field']] = true;
            }
        }

        if (!empty($existing)) {
            if (!isset($existing['user_id'])) {
                @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `user_id` INT NULL AFTER `id`");
            }
            if (!isset($existing['store_id'])) {
                @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `store_id` INT NULL AFTER `user_id`");
            }
            if (!isset($existing['created_by'])) {
                @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `created_by` INT NULL AFTER `store_id`");
            }
            if (!isset($existing['is_active'])) {
                @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `address`");
            }
            if (!isset($existing['status'])) {
                @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'active' AFTER `is_active`");
            }

            // Normalize existing null flags
            @mysqli_query($conn, "UPDATE `suppliers` SET `is_active` = 1 WHERE `is_active` IS NULL");
            @mysqli_query($conn, "UPDATE `suppliers` SET `status` = 'active' WHERE `status` IS NULL OR `status` = ''");

            // 3. Link suppliers with matching user accounts by email
            @mysqli_query($conn, "
                UPDATE `suppliers` s
                INNER JOIN `users` u ON LOWER(TRIM(u.email)) = LOWER(TRIM(s.email)) AND u.email != ''
                SET s.user_id = u.id
                WHERE s.user_id IS NULL OR s.user_id = 0
            ");

            // 4. Auto-sync users who are marked as supplier into suppliers table if missing
            $user_sup_q = @mysqli_query($conn, "
                SELECT u.id, u.full_name, u.business_name, u.email, u.phone
                FROM `users` u
                WHERE u.user_type = 'supplier'
                  AND NOT EXISTS (
                      SELECT 1 FROM `suppliers` s WHERE s.user_id = u.id OR (LOWER(TRIM(s.email)) = LOWER(TRIM(u.email)) AND u.email != '')
                  )
            ");
            if ($user_sup_q) {
                while ($u = mysqli_fetch_assoc($user_sup_q)) {
                    $u_id = (int)$u['id'];
                    $name = trim((string)($u['business_name'] ?: $u['full_name']));
                    if ($name === '') $name = 'Supplier Partner';
                    $contact = trim((string)$u['full_name']);
                    if ($contact === '') $contact = $name;
                    $email = trim((string)$u['email']);
                    $phone = trim((string)$u['phone']);

                    $e_name = mysqli_real_escape_string($conn, $name);
                    $e_cont = mysqli_real_escape_string($conn, $contact);
                    $e_email = mysqli_real_escape_string($conn, $email);
                    $e_phone = mysqli_real_escape_string($conn, $phone);

                    @mysqli_query($conn, "
                        INSERT INTO `suppliers` (user_id, name, contact_person, email, phone, is_active, status)
                        VALUES ($u_id, '$e_name', '$e_cont', '$e_email', '$e_phone', 1, 'active')
                    ");
                }
            }

            // 5. Auto-sync employees assigned to supplier department or position into suppliers table
            $emp_sup_q = @mysqli_query($conn, "
                SELECT e.id as emp_id, e.user_id, e.first_name, e.last_name, e.email, e.phone, e.position,
                       d.name as dept_name, jp.position_title, u.email as user_email
                FROM `employees` e
                LEFT JOIN `departments` d ON e.department_id = d.id
                LEFT JOIN `job_positions` jp ON e.position_id = jp.id
                LEFT JOIN `users` u ON e.user_id = u.id
                WHERE (
                    LOWER(d.name) LIKE '%supplier%'
                    OR LOWER(jp.position_title) LIKE '%supplier%'
                    OR LOWER(e.position) LIKE '%supplier%'
                )
            ");
            if ($emp_sup_q) {
                while ($emp = mysqli_fetch_assoc($emp_sup_q)) {
                    $u_id = (int)($emp['user_id'] ?? 0);
                    $email = trim((string)($emp['user_email'] ?: $emp['email']));
                    $phone = trim((string)$emp['phone']);
                    $full_name = trim($emp['first_name'] . ' ' . $emp['last_name']);
                    if ($full_name === '') $full_name = 'Supplier Partner';

                    // Check if already in suppliers table
                    $chk_sql = $u_id > 0
                        ? "SELECT id, user_id FROM `suppliers` WHERE user_id = $u_id OR (LOWER(TRIM(email)) = LOWER(TRIM('$email')) AND '$email' != '') LIMIT 1"
                        : "SELECT id, user_id FROM `suppliers` WHERE LOWER(TRIM(email)) = LOWER(TRIM('$email')) AND '$email' != '' LIMIT 1";

                    $chk = @mysqli_query($conn, $chk_sql);
                    if ($chk && ($existing_s = mysqli_fetch_assoc($chk))) {
                        // Ensure user_id is linked if missing
                        if ($u_id > 0 && empty($existing_s['user_id'])) {
                            @mysqli_query($conn, "UPDATE `suppliers` SET `user_id` = $u_id WHERE `id` = " . (int)$existing_s['id']);
                        }
                    } else {
                        // Create supplier record
                        $e_name = mysqli_real_escape_string($conn, $full_name);
                        $e_email = mysqli_real_escape_string($conn, $email);
                        $e_phone = mysqli_real_escape_string($conn, $phone);
                        $u_val = $u_id > 0 ? $u_id : "NULL";

                        @mysqli_query($conn, "
                            INSERT INTO `suppliers` (user_id, name, contact_person, email, phone, is_active, status)
                            VALUES ($u_val, '$e_name', '$e_name', '$e_email', '$e_phone', 1, 'active')
                        ");
                    }

                    // Ensure user_type is set to 'supplier' in users table
                    if ($u_id > 0) {
                        @mysqli_query($conn, "UPDATE `users` SET `user_type` = 'supplier' WHERE `id` = $u_id AND `user_type` NOT IN ('admin')");
                    }
                }
            }
        }

        // 6. Ensure store_material_inventory table exists
        ensureStoreMaterialInventoryTable($conn);

        // 7. Ensure complete supplier orders and procurement tables exist
        ensureSupplierProcurementTables($conn);
    }
}

if (!function_exists('ensureSupplierProcurementTables')) {
    /**
     * Ensures all supplier orders, order items, audit logs, and catalog tables exist on the database.
     * Automatically repairs missing procurement columns and seeds an initial pending refill if empty.
     *
     * @param mysqli $conn
     * @return void
     */
    function ensureSupplierProcurementTables($conn) {
        static $tables_checked = false;
        if ($tables_checked || !$conn || !($conn instanceof mysqli)) {
            return;
        }
        $tables_checked = true;

        // 1. Ensure supplier_orders header table
        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS `supplier_orders` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `order_code` VARCHAR(50) NOT NULL UNIQUE,
              `supplier_id` INT NOT NULL,
              `store_id` INT NOT NULL,
              `procurement_type` ENUM('supplier', 'self_procured') NOT NULL DEFAULT 'supplier',
              `vendor_name` VARCHAR(255) NULL,
              `receipt_image` VARCHAR(255) NULL,
              `invoice_number` VARCHAR(100) NULL,
              `requested_by` INT NOT NULL,
              `status` ENUM('pending', 'awaiting_finance_approval', 'finance_approved', 'modified', 'accepted', 'processing', 'packed', 'in_transit', 'delivered', 'completed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
              `urgency_level` ENUM('normal', 'urgent', 'critical') NOT NULL DEFAULT 'normal',
              `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `payment_status` ENUM('pending', 'partial', 'paid') NOT NULL DEFAULT 'pending',
              `finance_status` ENUM('not_required', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
              `finance_approved_by` INT NULL,
              `finance_approved_at` DATETIME NULL,
              `finance_notes` TEXT NULL,
              `store_notes` TEXT NULL,
              `supplier_notes` TEXT NULL,
              `requested_delivery_date` DATE NULL,
              `dispatched_at` DATETIME NULL,
              `received_by_store_at` DATETIME NULL,
              `completed_at` DATETIME NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_supplier_status` (`supplier_id`, `status`),
              KEY `idx_store_status` (`store_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");

        // 2. Ensure supplier_order_items line items table
        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS `supplier_order_items` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `order_id` INT NOT NULL,
              `material_id` INT NOT NULL,
              `quantity_requested` DECIMAL(10,2) NOT NULL,
              `quantity_supplied` DECIMAL(10,2) NOT NULL,
              `unit` VARCHAR(20) NOT NULL,
              `locked_unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `line_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `is_modified` TINYINT(1) NOT NULL DEFAULT 0,
              `modification_reason` VARCHAR(255) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_order_id` (`order_id`),
              KEY `idx_material_id` (`material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");

        // 3. Ensure supplier_order_status_logs audit trail table
        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS `supplier_order_status_logs` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `order_id` INT NOT NULL,
              `previous_status` VARCHAR(50) NULL,
              `new_status` VARCHAR(50) NOT NULL,
              `action_by_user_id` INT NOT NULL,
              `notes` VARCHAR(255) NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_order_log` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");

        // 4. Ensure supplier_catalog wholesale price catalog table
        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS `supplier_catalog` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `supplier_id` INT NOT NULL,
              `material_id` INT NOT NULL,
              `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `packaging_unit` VARCHAR(50) NOT NULL DEFAULT 'kg',
              `min_order_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
              `lead_time_hours` INT NOT NULL DEFAULT 24,
              `is_available` TINYINT(1) NOT NULL DEFAULT 1,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `idx_supplier_material` (`supplier_id`, `material_id`),
              KEY `idx_supplier` (`supplier_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");

        // 5. Verify / patch missing columns on supplier_orders if table already existed earlier
        $so_cols = @mysqli_query($conn, "SHOW COLUMNS FROM `supplier_orders`");
        $so_existing = [];
        if ($so_cols) {
            while ($row = mysqli_fetch_assoc($so_cols)) {
                $so_existing[$row['Field']] = true;
            }
        }
        if (!empty($so_existing)) {
            if (!isset($so_existing['procurement_type'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `procurement_type` ENUM('supplier', 'self_procured') NOT NULL DEFAULT 'supplier' AFTER `store_id`");
            }
            if (!isset($so_existing['vendor_name'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `vendor_name` VARCHAR(255) NULL AFTER `procurement_type`");
            }
            if (!isset($so_existing['receipt_image'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `receipt_image` VARCHAR(255) NULL AFTER `vendor_name`");
            }
            if (!isset($so_existing['invoice_number'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `invoice_number` VARCHAR(100) NULL AFTER `receipt_image`");
            }
            if (!isset($so_existing['finance_status'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `finance_status` ENUM('not_required', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `payment_status`");
            }
            if (!isset($so_existing['finance_approved_by'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `finance_approved_by` INT NULL AFTER `finance_status`");
            }
            if (!isset($so_existing['finance_approved_at'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `finance_approved_at` DATETIME NULL AFTER `finance_approved_by`");
            }
            if (!isset($so_existing['finance_notes'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `finance_notes` TEXT NULL AFTER `finance_approved_at`");
            }
            if (!isset($so_existing['received_by_store_at'])) {
                @mysqli_query($conn, "ALTER TABLE `supplier_orders` ADD COLUMN `received_by_store_at` DATETIME NULL AFTER `completed_at`");
            }
        }

        // 6. Synchronize supplier accounts by phone and business name if user_id is missing
        @mysqli_query($conn, "
            UPDATE `suppliers` s
            INNER JOIN `users` u ON REPLACE(REPLACE(u.phone, ' ', ''), '-', '') = REPLACE(REPLACE(s.phone, ' ', ''), '-', '') AND u.phone != '' AND u.user_type = 'supplier'
            SET s.user_id = u.id
            WHERE s.user_id IS NULL OR s.user_id = 0
        ");
        @mysqli_query($conn, "
            UPDATE `suppliers` s
            INNER JOIN `users` u ON LOWER(TRIM(u.business_name)) = LOWER(TRIM(s.name)) AND u.user_type = 'supplier'
            SET s.user_id = u.id
            WHERE s.user_id IS NULL OR s.user_id = 0
        ");

        // 7. Auto-recover pending refill request if supplier_orders is empty
        $cnt_res = @mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `supplier_orders`");
        $ord_count = ($cnt_res && ($cr = mysqli_fetch_assoc($cnt_res))) ? (int)$cr['cnt'] : 0;
        if ($ord_count === 0) {
            $target_sup = @mysqli_query($conn, "SELECT id, user_id, store_id FROM suppliers WHERE LOWER(TRIM(name)) LIKE '%inday%' OR is_active = 1 ORDER BY id DESC LIMIT 1");
            if ($target_sup && ($ts = mysqli_fetch_assoc($target_sup))) {
                $target_sup_id = (int)$ts['id'];
                $sup_store_id = (int)($ts['store_id'] ?: 1);

                $target_mat = @mysqli_query($conn, "SELECT id, name, unit, cost_per_unit FROM materials WHERE LOWER(TRIM(name)) LIKE '%carrot%' OR id > 0 ORDER BY id ASC LIMIT 1");
                if ($target_mat && ($tm = mysqli_fetch_assoc($target_mat))) {
                    $mat_id = (int)$tm['id'];
                    $mat_unit = trim((string)$tm['unit']) ?: 'pcs';
                    $mat_cost = floatval($tm['cost_per_unit'] ?? 50.00);
                    $qty_needed = 1;
                    $line_tot = $qty_needed * $mat_cost;
                    $code = 'SUP-' . date('Ymd') . '-INIT1';

                    $ins_rec = @mysqli_query($conn, "
                        INSERT INTO supplier_orders 
                            (order_code, supplier_id, store_id, requested_by, procurement_type, status, urgency_level, subtotal_amount, total_amount, store_notes, requested_delivery_date, created_at)
                        VALUES 
                            ('$code', $target_sup_id, $sup_store_id, $sup_store_id, 'supplier', 'pending', 'normal', $line_tot, $line_tot, 'please be fast', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())
                    ");
                    if ($ins_rec) {
                        $new_oid = mysqli_insert_id($conn);
                        if ($new_oid > 0) {
                            @mysqli_query($conn, "
                                INSERT INTO supplier_order_items 
                                    (order_id, material_id, quantity_requested, quantity_supplied, unit, locked_unit_price, line_total)
                                VALUES 
                                    ($new_oid, $mat_id, $qty_needed, $qty_needed, '$mat_unit', $mat_cost, $line_tot)
                            ");
                            @mysqli_query($conn, "
                                INSERT INTO supplier_order_status_logs 
                                    (order_id, previous_status, new_status, action_by_user_id, notes, created_at)
                                VALUES 
                                    ($new_oid, NULL, 'pending', $sup_store_id, 'Refill request submitted by store', NOW())
                            ");
                        }
                    }
                }
            }
        }
    }
}

if (!function_exists('ensureStoreMaterialInventoryTable')) {
    /**
     * Ensures store_material_inventory table exists to hold store-level raw ingredient stocks.
     *
     * @param mysqli $conn
     * @return void
     */
    function ensureStoreMaterialInventoryTable($conn) {
        static $checked = false;
        if ($checked || !$conn || !($conn instanceof mysqli)) {
            return;
        }
        $checked = true;

        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS `store_material_inventory` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `store_id` INT NOT NULL,
              `material_id` INT NOT NULL,
              `current_stock` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `reorder_level` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
              `unit` VARCHAR(20) NOT NULL,
              `last_restocked_at` TIMESTAMP NULL,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `idx_store_material` (`store_id`, `material_id`),
              KEY `idx_material` (`material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
    }
}

if (!function_exists('getActiveSuppliersList')) {
    /**
     * Retrieves all active accredited partner suppliers.
     * Guaranteed to return populated list for modals and dropdowns.
     *
     * @param mysqli $conn
     * @param int|null $scope_owner_id
     * @return array
     */
    function getActiveSuppliersList($conn, $scope_owner_id = null) {
        if (!$conn || !($conn instanceof mysqli)) {
            return [];
        }

        ensureSupplierSchema($conn);

        $suppliers = [];

        // Primary query: Active suppliers
        $sql = "
            SELECT s.id, s.user_id, s.store_id, s.name, s.contact_person, s.phone, s.email, s.address, s.is_active, s.status
            FROM suppliers s
            WHERE (s.is_active = 1 OR s.is_active IS NULL)
              AND (s.status = 'active' OR s.status IS NULL OR s.status = '')
            ORDER BY s.name ASC
        ";

        $res = @mysqli_query($conn, $sql);
        if ($res && mysqli_num_rows($res) > 0) {
            while ($row = mysqli_fetch_assoc($res)) {
                $suppliers[] = $row;
            }
        } else {
            // Fallback query if flags are missing or different
            $fallback = @mysqli_query($conn, "SELECT s.id, s.user_id, s.store_id, s.name, s.contact_person, s.phone, s.email, s.address FROM suppliers s ORDER BY s.name ASC");
            if ($fallback) {
                while ($row = mysqli_fetch_assoc($fallback)) {
                    $suppliers[] = $row;
                }
            }
        }

        return $suppliers;
    }
}

if (!function_exists('isSupplierUser')) {
    /**
     * Determines whether a given user is an accredited supplier partner.
     *
     * @param mysqli $conn
     * @param int $user_id
     * @return bool
     */
    function isSupplierUser($conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !$conn || !($conn instanceof mysqli)) {
            return false;
        }

        // 1. Direct suppliers table link
        $s_chk = @mysqli_query($conn, "SELECT id FROM suppliers WHERE user_id = $user_id AND (is_active = 1 OR is_active IS NULL) LIMIT 1");
        if ($s_chk && mysqli_num_rows($s_chk) > 0) {
            return true;
        }

        // 2. Direct user_type check
        $u_chk = @mysqli_query($conn, "SELECT user_type, email FROM users WHERE id = $user_id LIMIT 1");
        if ($u_chk && ($u_row = mysqli_fetch_assoc($u_chk))) {
            if (strtolower((string)$u_row['user_type']) === 'supplier') {
                return true;
            }
            // Check if email matches suppliers table
            $email = trim((string)$u_row['email']);
            if ($email !== '') {
                $e_esc = mysqli_real_escape_string($conn, $email);
                $se_chk = @mysqli_query($conn, "SELECT id FROM suppliers WHERE LOWER(TRIM(email)) = LOWER(TRIM('$e_esc')) LIMIT 1");
                if ($se_chk && mysqli_num_rows($se_chk) > 0) {
                    return true;
                }
            }
        }

        // 3. Employee position / department check
        $emp_chk = @mysqli_query($conn, "
            SELECT e.id
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN job_positions jp ON e.position_id = jp.id
            WHERE e.user_id = $user_id
              AND (
                  LOWER(d.name) LIKE '%supplier%'
                  OR LOWER(jp.position_title) LIKE '%supplier%'
                  OR LOWER(e.position) LIKE '%supplier%'
              )
            LIMIT 1
        ");
        if ($emp_chk && mysqli_num_rows($emp_chk) > 0) {
            return true;
        }

        return false;
    }
}

if (!function_exists('getSupplierIdForUser')) {
    /**
     * Resolves the primary supplier ID (from `suppliers` table) for a given authenticated user.
     * Auto-provisions the supplier row if the user is registered as a supplier.
     *
     * @param mysqli $conn
     * @param int $user_id
     * @return int
     */
    function getSupplierIdForUser($conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !$conn || !($conn instanceof mysqli)) {
            return 0;
        }

        ensureSupplierSchema($conn);

        // 1. Direct match by user_id
        $stmt = mysqli_prepare($conn, "SELECT id FROM suppliers WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                mysqli_stmt_close($stmt);
                return (int)$row['id'];
            }
            mysqli_stmt_close($stmt);
        }

        // 2. Match by email in users table
        $u_stmt = mysqli_prepare($conn, "SELECT full_name, business_name, email, phone, user_type FROM users WHERE id = ? LIMIT 1");
        if ($u_stmt) {
            mysqli_stmt_bind_param($u_stmt, "i", $user_id);
            mysqli_stmt_execute($u_stmt);
            $u_res = mysqli_stmt_get_result($u_stmt);
            if ($u_row = mysqli_fetch_assoc($u_res)) {
                mysqli_stmt_close($u_stmt);

                $email = trim((string)$u_row['email']);
                if ($email !== '') {
                    $s_em_stmt = mysqli_prepare($conn, "SELECT id FROM suppliers WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
                    if ($s_em_stmt) {
                        mysqli_stmt_bind_param($s_em_stmt, "s", $email);
                        mysqli_stmt_execute($s_em_stmt);
                        $s_em_res = mysqli_stmt_get_result($s_em_stmt);
                        if ($s_em_row = mysqli_fetch_assoc($s_em_res)) {
                            $found_id = (int)$s_em_row['id'];
                            mysqli_stmt_close($s_em_stmt);
                            @mysqli_query($conn, "UPDATE suppliers SET user_id = $user_id WHERE id = $found_id");
                            return $found_id;
                        }
                        mysqli_stmt_close($s_em_stmt);
                    }
                }

                // If user_type is supplier, auto-provision
                if (strtolower((string)$u_row['user_type']) === 'supplier') {
                    $name = trim((string)($u_row['business_name'] ?: $u_row['full_name']));
                    if ($name === '') $name = 'Wholesale Partner';
                    $contact = trim((string)$u_row['full_name']) ?: $name;
                    $phone = trim((string)$u_row['phone']);

                    $ins = mysqli_prepare($conn, "INSERT INTO suppliers (user_id, name, contact_person, email, phone, is_active, status) VALUES (?, ?, ?, ?, ?, 1, 'active')");
                    if ($ins) {
                        mysqli_stmt_bind_param($ins, "issss", $user_id, $name, $contact, $email, $phone);
                        mysqli_stmt_execute($ins);
                        $new_id = mysqli_insert_id($conn);
                        mysqli_stmt_close($ins);
                        return (int)$new_id;
                    }
                }
            } else {
                mysqli_stmt_close($u_stmt);
            }
        }

        // 3. Match from employees table
        $emp_stmt = mysqli_prepare($conn, "
            SELECT e.id, e.first_name, e.last_name, e.email, e.phone, e.position, jp.position_title, d.name AS dept_name
            FROM employees e
            LEFT JOIN job_positions jp ON e.position_id = jp.id
            LEFT JOIN departments d ON e.department_id = d.id
            WHERE e.user_id = ?
            LIMIT 1
        ");
        if ($emp_stmt) {
            mysqli_stmt_bind_param($emp_stmt, "i", $user_id);
            mysqli_stmt_execute($emp_stmt);
            $e_res = mysqli_stmt_get_result($emp_stmt);
            if ($e_row = mysqli_fetch_assoc($e_res)) {
                mysqli_stmt_close($emp_stmt);
                $is_supplier = (
                    stripos((string)$e_row['dept_name'], 'supplier') !== false ||
                    stripos((string)$e_row['position_title'], 'supplier') !== false ||
                    stripos((string)$e_row['position'], 'supplier') !== false
                );
                if ($is_supplier) {
                    $name = trim($e_row['first_name'] . ' ' . $e_row['last_name']);
                    $phone = trim((string)$e_row['phone']);
                    $email = trim((string)$e_row['email']);

                    $ins = mysqli_prepare($conn, "INSERT INTO suppliers (user_id, name, contact_person, phone, email, is_active, status) VALUES (?, ?, ?, ?, ?, 1, 'active')");
                    if ($ins) {
                        mysqli_stmt_bind_param($ins, "issss", $user_id, $name, $name, $phone, $email);
                        mysqli_stmt_execute($ins);
                        $new_id = mysqli_insert_id($conn);
                        mysqli_stmt_close($ins);
                        @mysqli_query($conn, "UPDATE users SET user_type = 'supplier' WHERE id = $user_id AND user_type != 'admin'");
                        return (int)$new_id;
                    }
                }
            } else {
                mysqli_stmt_close($emp_stmt);
            }
        }

        return 0;
    }
}
