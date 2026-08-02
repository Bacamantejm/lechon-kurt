<?php

if (!function_exists('pvHasTableColumn')) {
    function pvHasTableColumn($conn, $table_name, $column_name) {
        static $cache = [];
        $table_name = trim((string)$table_name);
        $column_name = trim((string)$column_name);
        if ($table_name === '' || $column_name === '' || !($conn instanceof mysqli)) {
            return false;
        }

        $cache_key = $table_name . '::' . $column_name;
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $safe_column = mysqli_real_escape_string($conn, $column_name);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
        $exists = $result && mysqli_num_rows($result) > 0;
        if ($result) {
            mysqli_free_result($result);
        }

        $cache[$cache_key] = $exists;
        return $exists;
    }
}

if (!function_exists('pvEnsureVoucherSchema')) {
    function pvEnsureVoucherSchema($conn) {
        static $ensured = false;
        if ($ensured || !($conn instanceof mysqli)) {
            return;
        }
        $ensured = true;

        $vouchers_sql = "
            CREATE TABLE IF NOT EXISTS `partner_vouchers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `seller_id` int(11) NOT NULL,
                `code` varchar(60) NOT NULL,
                `name` varchar(120) NOT NULL,
                `description` text DEFAULT NULL,
                `discount_type` enum('percent','fixed') NOT NULL DEFAULT 'fixed',
                `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
                `min_order_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `max_discount_amount` decimal(10,2) DEFAULT NULL,
                `start_at` datetime DEFAULT NULL,
                `end_at` datetime DEFAULT NULL,
                `usage_limit` int(11) DEFAULT NULL,
                `usage_count` int(11) NOT NULL DEFAULT 0,
                `per_user_limit` int(11) NOT NULL DEFAULT 1,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_seller_code` (`seller_id`,`code`),
                KEY `idx_code` (`code`),
                KEY `idx_seller_active` (`seller_id`,`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        mysqli_query($conn, $vouchers_sql);

        $redemptions_sql = "
            CREATE TABLE IF NOT EXISTS `partner_voucher_redemptions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `voucher_id` int(11) NOT NULL,
                `order_id` int(11) NOT NULL,
                `user_id` int(11) NOT NULL,
                `seller_id` int(11) NOT NULL,
                `voucher_code` varchar(60) NOT NULL,
                `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `order_subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_order` (`order_id`),
                KEY `idx_voucher_user` (`voucher_id`,`user_id`),
                KEY `idx_seller_created` (`seller_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        mysqli_query($conn, $redemptions_sql);

        if (!pvHasTableColumn($conn, 'orders', 'voucher_id')) {
            mysqli_query($conn, "ALTER TABLE `orders` ADD COLUMN `voucher_id` int(11) DEFAULT NULL AFTER `delivery_fee`");
        }
        if (!pvHasTableColumn($conn, 'orders', 'voucher_code')) {
            mysqli_query($conn, "ALTER TABLE `orders` ADD COLUMN `voucher_code` varchar(60) DEFAULT NULL AFTER `voucher_id`");
        }
        if (!pvHasTableColumn($conn, 'orders', 'voucher_discount')) {
            mysqli_query($conn, "ALTER TABLE `orders` ADD COLUMN `voucher_discount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `voucher_code`");
        }
    }
}

if (!function_exists('pvNormalizeVoucherCode')) {
    function pvNormalizeVoucherCode($code) {
        $code = strtoupper(trim((string)$code));
        return preg_replace('/[^A-Z0-9\-_]/', '', $code);
    }
}

if (!function_exists('pvAttachSellerIdsToCartItems')) {
    function pvAttachSellerIdsToCartItems($conn, $cart) {
        $rows = [];
        if (!is_array($cart) || empty($cart)) {
            return $rows;
        }

        $numeric_ids = [];
        $string_product_ids = [];
        foreach ($cart as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $numeric_id = (int)($item['id'] ?? 0);
            $string_product_id = trim((string)($item['product_id'] ?? ''));
            if ($numeric_id > 0) {
                $numeric_ids[] = $numeric_id;
            }
            if ($string_product_id !== '') {
                $string_product_ids[] = $string_product_id;
            }
        }

        $numeric_ids = array_values(array_unique($numeric_ids));
        $string_product_ids = array_values(array_unique($string_product_ids));

        $seller_by_numeric_id = [];
        $seller_by_string_id = [];

        if ($numeric_ids) {
            $id_sql = implode(',', array_map('intval', $numeric_ids));
            $query = "SELECT id, product_id, seller_id FROM products WHERE id IN ({$id_sql})";
            $result = mysqli_query($conn, $query);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $db_id = (int)($row['id'] ?? 0);
                    $db_product_id = trim((string)($row['product_id'] ?? ''));
                    $db_seller_id = (int)($row['seller_id'] ?? 0);
                    if ($db_id > 0) {
                        $seller_by_numeric_id[$db_id] = $db_seller_id;
                    }
                    if ($db_product_id !== '') {
                        $seller_by_string_id[$db_product_id] = $db_seller_id;
                    }
                }
                mysqli_free_result($result);
            }
        }

        if ($string_product_ids) {
            $escaped = array_map(static function ($value) use ($conn) {
                return "'" . mysqli_real_escape_string($conn, $value) . "'";
            }, $string_product_ids);
            $codes_sql = implode(',', $escaped);
            $query = "SELECT id, product_id, seller_id FROM products WHERE product_id IN ({$codes_sql})";
            $result = mysqli_query($conn, $query);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $db_id = (int)($row['id'] ?? 0);
                    $db_product_id = trim((string)($row['product_id'] ?? ''));
                    $db_seller_id = (int)($row['seller_id'] ?? 0);
                    if ($db_id > 0) {
                        $seller_by_numeric_id[$db_id] = $db_seller_id;
                    }
                    if ($db_product_id !== '') {
                        $seller_by_string_id[$db_product_id] = $db_seller_id;
                    }
                }
                mysqli_free_result($result);
            }
        }

        foreach ($cart as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $numeric_id = (int)($item['id'] ?? 0);
            $string_product_id = trim((string)($item['product_id'] ?? ''));
            $seller_id = 0;

            if ($numeric_id > 0 && isset($seller_by_numeric_id[$numeric_id])) {
                $seller_id = (int)$seller_by_numeric_id[$numeric_id];
            } elseif ($string_product_id !== '' && isset($seller_by_string_id[$string_product_id])) {
                $seller_id = (int)$seller_by_string_id[$string_product_id];
            }

            $rows[] = [
                'id' => $numeric_id,
                'product_id' => $string_product_id,
                'quantity' => $qty,
                'price' => (float)($item['price'] ?? 0),
                'seller_id' => $seller_id
            ];
        }

        return $rows;
    }
}

if (!function_exists('pvGetCartScope')) {
    function pvGetCartScope($conn, $cart) {
        $items = pvAttachSellerIdsToCartItems($conn, $cart);
        if (empty($items)) {
            return [
                'has_items' => false,
                'single_seller' => false,
                'seller_id' => 0,
                'subtotal' => 0.0,
                'item_count' => 0,
                'message' => 'Your cart is empty.'
            ];
        }

        $seller_set = [];
        $unknown_seller_items = 0;
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float)$item['price'] * (int)$item['quantity'];
            $seller_id = (int)($item['seller_id'] ?? 0);
            if ($seller_id > 0) {
                $seller_set[$seller_id] = true;
            } else {
                $unknown_seller_items++;
            }
        }

        $seller_ids = array_keys($seller_set);
        $is_single_seller = count($seller_ids) === 1 && $unknown_seller_items === 0;
        $message = '';

        if (!$is_single_seller) {
            if (count($seller_ids) > 1) {
                $message = 'Voucher can only be used when all cart items are from one shop.';
            } elseif ($unknown_seller_items > 0) {
                $message = 'Some cart items could not be matched to a shop.';
            } else {
                $message = 'Voucher is unavailable for this cart.';
            }
        }

        return [
            'has_items' => true,
            'single_seller' => $is_single_seller,
            'seller_id' => $is_single_seller ? (int)$seller_ids[0] : 0,
            'subtotal' => round($subtotal, 2),
            'item_count' => count($items),
            'message' => $message
        ];
    }
}

if (!function_exists('pvGetCheckoutTenantScope')) {
    function pvGetCheckoutTenantScope($conn, $cart) {
        $items = pvAttachSellerIdsToCartItems($conn, $cart);
        if (empty($items)) {
            return [
                'has_items' => false,
                'is_valid' => false,
                'single_seller' => false,
                'seller_id' => 0,
                'seller_ids' => [],
                'unknown_item_count' => 0,
                'item_count' => 0,
                'message' => 'Your cart is empty.'
            ];
        }

        $seller_set = [];
        $unknown_item_count = 0;

        foreach ($items as $item) {
            $seller_id = (int)($item['seller_id'] ?? 0);
            if ($seller_id > 0) {
                $seller_set[$seller_id] = true;
            } else {
                $unknown_item_count++;
            }
        }

        $seller_ids = array_map('intval', array_keys($seller_set));
        sort($seller_ids);
        $single_seller = count($seller_ids) === 1 && $unknown_item_count === 0;
        $is_valid = $single_seller;

        $message = '';
        if (!$is_valid) {
            if (count($seller_ids) > 1) {
                $message = 'Your cart has items from multiple stores. Please checkout one store at a time.';
            } elseif ($unknown_item_count > 0) {
                $message = 'Some cart items are no longer available for checkout. Please update your cart and try again.';
            } else {
                $message = 'Your cart is not eligible for checkout right now.';
            }
        }

        return [
            'has_items' => true,
            'is_valid' => $is_valid,
            'single_seller' => $single_seller,
            'seller_id' => $single_seller ? (int)$seller_ids[0] : 0,
            'seller_ids' => $seller_ids,
            'unknown_item_count' => $unknown_item_count,
            'item_count' => count($items),
            'message' => $message
        ];
    }
}

if (!function_exists('pvGetVoucherByCodeForSeller')) {
    function pvGetVoucherByCodeForSeller($conn, $seller_id, $voucher_code) {
        $seller_id = (int)$seller_id;
        $voucher_code = pvNormalizeVoucherCode($voucher_code);
        if ($seller_id <= 0 || $voucher_code === '') {
            return null;
        }

        $stmt = mysqli_prepare($conn, "SELECT * FROM partner_vouchers WHERE seller_id = ? AND code = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, "is", $seller_id, $voucher_code);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $voucher = $result ? mysqli_fetch_assoc($result) : null;
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        return $voucher ?: null;
    }
}

if (!function_exists('pvComputeVoucherDiscount')) {
    function pvComputeVoucherDiscount($voucher, $subtotal) {
        $subtotal = (float)$subtotal;
        if (!is_array($voucher) || $subtotal <= 0) {
            return 0.0;
        }

        $discount_type = strtolower(trim((string)($voucher['discount_type'] ?? 'fixed')));
        $discount_value = (float)($voucher['discount_value'] ?? 0);
        $max_discount = isset($voucher['max_discount_amount']) ? (float)$voucher['max_discount_amount'] : 0.0;

        if ($discount_value <= 0) {
            return 0.0;
        }

        $discount = 0.0;
        if ($discount_type === 'percent') {
            $discount = $subtotal * ($discount_value / 100);
        } else {
            $discount = $discount_value;
        }

        if ($max_discount > 0 && $discount > $max_discount) {
            $discount = $max_discount;
        }
        if ($discount > $subtotal) {
            $discount = $subtotal;
        }
        if ($discount < 0) {
            $discount = 0;
        }

        return round($discount, 2);
    }
}

if (!function_exists('pvValidateVoucherForCart')) {
    function pvValidateVoucherForCart($conn, $voucher, $user_id, $scope) {
        if (!is_array($voucher)) {
            return ['success' => false, 'message' => 'Voucher code is invalid.'];
        }
        if (!is_array($scope) || empty($scope['single_seller'])) {
            return ['success' => false, 'message' => $scope['message'] ?? 'Voucher requires one shop per order.'];
        }

        $seller_id = (int)($scope['seller_id'] ?? 0);
        $subtotal = (float)($scope['subtotal'] ?? 0);
        if ($seller_id <= 0 || $subtotal <= 0) {
            return ['success' => false, 'message' => 'Voucher could not be applied to this cart.'];
        }

        if ((int)($voucher['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Voucher is inactive.'];
        }

        if ((int)($voucher['seller_id'] ?? 0) !== $seller_id) {
            return ['success' => false, 'message' => 'Voucher does not belong to this shop.'];
        }

        $now_ts = time();
        $start_at = trim((string)($voucher['start_at'] ?? ''));
        $end_at = trim((string)($voucher['end_at'] ?? ''));
        if ($start_at !== '') {
            $start_ts = strtotime($start_at);
            if ($start_ts !== false && $now_ts < $start_ts) {
                return ['success' => false, 'message' => 'Voucher is not active yet.'];
            }
        }
        if ($end_at !== '') {
            $end_ts = strtotime($end_at);
            if ($end_ts !== false && $now_ts > $end_ts) {
                return ['success' => false, 'message' => 'Voucher has already expired.'];
            }
        }

        $min_order = (float)($voucher['min_order_amount'] ?? 0);
        if ($min_order > 0 && $subtotal < $min_order) {
            return ['success' => false, 'message' => 'Minimum order of PHP ' . number_format($min_order, 2) . ' is required.'];
        }

        $usage_limit = (int)($voucher['usage_limit'] ?? 0);
        $usage_count = (int)($voucher['usage_count'] ?? 0);
        if ($usage_limit > 0 && $usage_count >= $usage_limit) {
            return ['success' => false, 'message' => 'Voucher usage limit has been reached.'];
        }

        $per_user_limit = (int)($voucher['per_user_limit'] ?? 0);
        if ($per_user_limit > 0 && (int)$user_id > 0) {
            $voucher_id = (int)($voucher['id'] ?? 0);
            $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM partner_voucher_redemptions WHERE voucher_id = ? AND user_id = ?");
            if ($count_stmt) {
                mysqli_stmt_bind_param($count_stmt, "ii", $voucher_id, $user_id);
                mysqli_stmt_execute($count_stmt);
                $count_result = mysqli_stmt_get_result($count_stmt);
                $count_row = $count_result ? mysqli_fetch_assoc($count_result) : null;
                if ($count_result) {
                    mysqli_free_result($count_result);
                }
                mysqli_stmt_close($count_stmt);

                $used_by_user = (int)($count_row['cnt'] ?? 0);
                if ($used_by_user >= $per_user_limit) {
                    return ['success' => false, 'message' => 'You already reached this voucher limit.'];
                }
            }
        }

        $discount_amount = pvComputeVoucherDiscount($voucher, $subtotal);
        if ($discount_amount <= 0) {
            return ['success' => false, 'message' => 'Voucher discount is not applicable.'];
        }

        return [
            'success' => true,
            'message' => 'Voucher applied successfully.',
            'discount_amount' => $discount_amount
        ];
    }
}

if (!function_exists('pvClearAppliedVoucherSession')) {
    function pvClearAppliedVoucherSession() {
        unset($_SESSION['applied_voucher']);
    }
}

if (!function_exists('pvGetAppliedVoucherSession')) {
    function pvGetAppliedVoucherSession() {
        $raw = $_SESSION['applied_voucher'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $voucher_id = (int)($raw['voucher_id'] ?? 0);
        $seller_id = (int)($raw['seller_id'] ?? 0);
        $voucher_code = pvNormalizeVoucherCode($raw['voucher_code'] ?? '');
        if ($voucher_id <= 0 || $seller_id <= 0 || $voucher_code === '') {
            return null;
        }

        return [
            'voucher_id' => $voucher_id,
            'seller_id' => $seller_id,
            'voucher_code' => $voucher_code
        ];
    }
}

if (!function_exists('pvApplyVoucherCodeForSession')) {
    function pvApplyVoucherCodeForSession($conn, $user_id, $raw_code, $cart) {
        pvEnsureVoucherSchema($conn);

        $voucher_code = pvNormalizeVoucherCode($raw_code);
        if ($voucher_code === '') {
            return ['success' => false, 'message' => 'Please enter a valid voucher code.'];
        }

        $scope = pvGetCartScope($conn, $cart);
        if (empty($scope['has_items'])) {
            pvClearAppliedVoucherSession();
            return ['success' => false, 'message' => 'Your cart is empty.'];
        }
        if (empty($scope['single_seller'])) {
            pvClearAppliedVoucherSession();
            return ['success' => false, 'message' => $scope['message'] ?: 'Voucher requires one shop per order.'];
        }

        $voucher = pvGetVoucherByCodeForSeller($conn, (int)$scope['seller_id'], $voucher_code);
        if (!$voucher) {
            return ['success' => false, 'message' => 'Voucher code is invalid for this shop.'];
        }

        $validation = pvValidateVoucherForCart($conn, $voucher, (int)$user_id, $scope);
        if (empty($validation['success'])) {
            return ['success' => false, 'message' => $validation['message'] ?? 'Voucher cannot be applied.'];
        }

        $_SESSION['applied_voucher'] = [
            'voucher_id' => (int)$voucher['id'],
            'voucher_code' => (string)$voucher['code'],
            'seller_id' => (int)$voucher['seller_id'],
            'applied_at' => date('c')
        ];

        return [
            'success' => true,
            'message' => 'Voucher applied.',
            'voucher_id' => (int)$voucher['id'],
            'voucher_code' => (string)$voucher['code'],
            'voucher_name' => (string)($voucher['name'] ?? ''),
            'discount_amount' => (float)$validation['discount_amount'],
            'scope' => $scope
        ];
    }
}

if (!function_exists('pvResolveAppliedVoucherState')) {
    function pvResolveAppliedVoucherState($conn, $user_id, $cart) {
        pvEnsureVoucherSchema($conn);

        $scope = pvGetCartScope($conn, $cart);
        $session_data = pvGetAppliedVoucherSession();
        if (!$session_data) {
            return [
                'applied' => false,
                'voucher_id' => 0,
                'voucher_code' => '',
                'voucher_name' => '',
                'discount_amount' => 0.0,
                'scope' => $scope,
                'message' => ''
            ];
        }

        if (empty($scope['has_items']) || empty($scope['single_seller'])) {
            pvClearAppliedVoucherSession();
            return [
                'applied' => false,
                'voucher_id' => 0,
                'voucher_code' => '',
                'voucher_name' => '',
                'discount_amount' => 0.0,
                'scope' => $scope,
                'message' => $scope['message'] ?? 'Voucher was removed because your cart changed.'
            ];
        }

        if ((int)$scope['seller_id'] !== (int)$session_data['seller_id']) {
            pvClearAppliedVoucherSession();
            return [
                'applied' => false,
                'voucher_id' => 0,
                'voucher_code' => '',
                'voucher_name' => '',
                'discount_amount' => 0.0,
                'scope' => $scope,
                'message' => 'Voucher was removed because your cart shop changed.'
            ];
        }

        $voucher_id = (int)$session_data['voucher_id'];
        $stmt = mysqli_prepare($conn, "SELECT * FROM partner_vouchers WHERE id = ? LIMIT 1");
        if (!$stmt) {
            pvClearAppliedVoucherSession();
            return [
                'applied' => false,
                'voucher_id' => 0,
                'voucher_code' => '',
                'voucher_name' => '',
                'discount_amount' => 0.0,
                'scope' => $scope,
                'message' => 'Voucher lookup failed.'
            ];
        }

        mysqli_stmt_bind_param($stmt, "i", $voucher_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $voucher = $result ? mysqli_fetch_assoc($result) : null;
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);

        if (!$voucher) {
            pvClearAppliedVoucherSession();
            return [
                'applied' => false,
                'voucher_id' => 0,
                'voucher_code' => '',
                'voucher_name' => '',
                'discount_amount' => 0.0,
                'scope' => $scope,
                'message' => 'Voucher no longer exists.'
            ];
        }

        $validation = pvValidateVoucherForCart($conn, $voucher, (int)$user_id, $scope);
        if (empty($validation['success'])) {
            pvClearAppliedVoucherSession();
            return [
                'applied' => false,
                'voucher_id' => 0,
                'voucher_code' => '',
                'voucher_name' => '',
                'discount_amount' => 0.0,
                'scope' => $scope,
                'message' => $validation['message'] ?? 'Voucher is no longer valid.'
            ];
        }

        return [
            'applied' => true,
            'voucher_id' => (int)$voucher['id'],
            'voucher_code' => (string)$voucher['code'],
            'voucher_name' => (string)($voucher['name'] ?? ''),
            'seller_id' => (int)$voucher['seller_id'],
            'discount_amount' => (float)$validation['discount_amount'],
            'scope' => $scope,
            'message' => ''
        ];
    }
}

if (!function_exists('pvRedeemVoucherForOrder')) {
    function pvRedeemVoucherForOrder($conn, $voucher_state, $order_id, $user_id, $order_subtotal) {
        if (!is_array($voucher_state) || empty($voucher_state['applied'])) {
            return ['success' => true];
        }

        $voucher_id = (int)($voucher_state['voucher_id'] ?? 0);
        $seller_id = (int)($voucher_state['seller_id'] ?? 0);
        $voucher_code = pvNormalizeVoucherCode($voucher_state['voucher_code'] ?? '');
        $discount_amount = (float)($voucher_state['discount_amount'] ?? 0);

        if ($voucher_id <= 0 || $seller_id <= 0 || $voucher_code === '' || $discount_amount <= 0 || (int)$order_id <= 0) {
            return ['success' => true];
        }

        $inserted = false;
        $insert_sql = "INSERT INTO partner_voucher_redemptions
            (voucher_id, order_id, user_id, seller_id, voucher_code, discount_amount, order_subtotal, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        if (!$insert_stmt) {
            return ['success' => false, 'message' => 'Failed to prepare voucher redemption record.'];
        }

        $order_subtotal = round((float)$order_subtotal, 2);
        mysqli_stmt_bind_param(
            $insert_stmt,
            "iiiisdd",
            $voucher_id,
            $order_id,
            $user_id,
            $seller_id,
            $voucher_code,
            $discount_amount,
            $order_subtotal
        );

        if (!mysqli_stmt_execute($insert_stmt)) {
            $error_no = mysqli_errno($conn);
            mysqli_stmt_close($insert_stmt);
            if ($error_no !== 1062) {
                return ['success' => false, 'message' => 'Failed to save voucher redemption.'];
            }
        } else {
            $inserted = true;
            mysqli_stmt_close($insert_stmt);
        }

        if ($inserted) {
            $update_sql = "UPDATE partner_vouchers
                           SET usage_count = usage_count + 1
                           WHERE id = ?
                             AND (usage_limit IS NULL OR usage_limit = 0 OR usage_count < usage_limit)";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            if (!$update_stmt) {
                return ['success' => false, 'message' => 'Failed to update voucher usage count.'];
            }
            mysqli_stmt_bind_param($update_stmt, "i", $voucher_id);
            mysqli_stmt_execute($update_stmt);
            $updated_rows = mysqli_stmt_affected_rows($update_stmt);
            mysqli_stmt_close($update_stmt);

            if ($updated_rows < 1) {
                return ['success' => false, 'message' => 'Voucher usage limit was reached.'];
            }
        }

        return ['success' => true];
    }
}
