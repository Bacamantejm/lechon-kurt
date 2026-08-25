<?php

if (!function_exists('favoritesIsCustomerUserSession')) {
    function favoritesIsCustomerUserSession() {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        $normalized_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
        return in_array($normalized_user_type, ['', 'customer', 'user'], true);
    }
}

if (!function_exists('favoritesEnsureTable')) {
    function favoritesEnsureTable(mysqli $conn) {
        static $table_ready = false;
        if ($table_ready) {
            return true;
        }

        $sql = "CREATE TABLE IF NOT EXISTS `customer_favorites` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `favorite_type` enum('store','product') NOT NULL,
                    `store_key` varchar(120) DEFAULT NULL,
                    `product_id` int(11) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_customer_store` (`user_id`,`favorite_type`,`store_key`),
                    UNIQUE KEY `uniq_customer_product` (`user_id`,`favorite_type`,`product_id`),
                    KEY `idx_customer_favorites_user` (`user_id`),
                    KEY `idx_customer_favorites_product` (`product_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $table_ready = mysqli_query($conn, $sql) === true;
        return $table_ready;
    }
}

if (!function_exists('favoritesNormalizeStoreKey')) {
    function favoritesNormalizeStoreKey($value) {
        $text = strtolower(trim((string)$value));
        $text = preg_replace('/[^a-z0-9\-]+/', '-', $text);
        $text = trim((string)$text, '-');
        if ($text === '') {
            return '';
        }
        return substr($text, 0, 120);
    }
}

if (!function_exists('favoritesFetchUserFavoriteStoreKeyMap')) {
    function favoritesFetchUserFavoriteStoreKeyMap(mysqli $conn, $user_id) {
        $map = [];
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !favoritesEnsureTable($conn)) {
            return $map;
        }

        $sql = "SELECT store_key
                FROM customer_favorites
                WHERE user_id = ? AND favorite_type = 'store' AND store_key IS NOT NULL";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return $map;
        }

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $key = favoritesNormalizeStoreKey($row['store_key'] ?? '');
                if ($key !== '') {
                    $map[$key] = true;
                }
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);

        return $map;
    }
}

if (!function_exists('favoritesFetchUserFavoriteProductIdMap')) {
    function favoritesFetchUserFavoriteProductIdMap(mysqli $conn, $user_id) {
        $map = [];
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !favoritesEnsureTable($conn)) {
            return $map;
        }

        $sql = "SELECT product_id
                FROM customer_favorites
                WHERE user_id = ? AND favorite_type = 'product' AND product_id IS NOT NULL";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return $map;
        }

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $product_id = (int)($row['product_id'] ?? 0);
                if ($product_id > 0) {
                    $map[$product_id] = true;
                }
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);

        return $map;
    }
}

if (!function_exists('favoritesGetTotalCount')) {
    function favoritesGetTotalCount(mysqli $conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !favoritesEnsureTable($conn)) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM customer_favorites
                WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);

        return max(0, (int)($row['total'] ?? 0));
    }
}

if (!function_exists('favoritesToggleStore')) {
    function favoritesToggleStore(mysqli $conn, $user_id, $store_key) {
        $user_id = (int)$user_id;
        $store_key = favoritesNormalizeStoreKey($store_key);
        if ($user_id <= 0 || $store_key === '' || !favoritesEnsureTable($conn)) {
            return ['success' => false, 'is_favorite' => false];
        }

        $exists_sql = "SELECT id
                       FROM customer_favorites
                       WHERE user_id = ? AND favorite_type = 'store' AND store_key = ?
                       LIMIT 1";
        $exists_stmt = mysqli_prepare($conn, $exists_sql);
        if (!$exists_stmt) {
            return ['success' => false, 'is_favorite' => false];
        }
        mysqli_stmt_bind_param($exists_stmt, "is", $user_id, $store_key);
        mysqli_stmt_execute($exists_stmt);
        $exists_result = mysqli_stmt_get_result($exists_stmt);
        $existing = $exists_result ? mysqli_fetch_assoc($exists_result) : null;
        if ($exists_result) {
            mysqli_free_result($exists_result);
        }
        mysqli_stmt_close($exists_stmt);

        if ($existing) {
            $delete_sql = "DELETE FROM customer_favorites WHERE id = ? LIMIT 1";
            $delete_stmt = mysqli_prepare($conn, $delete_sql);
            if (!$delete_stmt) {
                return ['success' => false, 'is_favorite' => true];
            }
            $favorite_id = (int)$existing['id'];
            mysqli_stmt_bind_param($delete_stmt, "i", $favorite_id);
            $ok = mysqli_stmt_execute($delete_stmt);
            mysqli_stmt_close($delete_stmt);
            if (!$ok) {
                return ['success' => false, 'is_favorite' => true];
            }
            return ['success' => true, 'is_favorite' => false];
        }

        $insert_sql = "INSERT INTO customer_favorites (user_id, favorite_type, store_key, product_id)
                       VALUES (?, 'store', ?, NULL)";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        if (!$insert_stmt) {
            return ['success' => false, 'is_favorite' => false];
        }
        mysqli_stmt_bind_param($insert_stmt, "is", $user_id, $store_key);
        $ok = mysqli_stmt_execute($insert_stmt);
        mysqli_stmt_close($insert_stmt);

        return ['success' => (bool)$ok, 'is_favorite' => (bool)$ok];
    }
}

if (!function_exists('favoritesToggleProduct')) {
    function favoritesToggleProduct(mysqli $conn, $user_id, $product_id) {
        $user_id = (int)$user_id;
        $product_id = (int)$product_id;
        if ($user_id <= 0 || $product_id <= 0 || !favoritesEnsureTable($conn)) {
            return ['success' => false, 'is_favorite' => false];
        }

        $exists_sql = "SELECT id
                       FROM customer_favorites
                       WHERE user_id = ? AND favorite_type = 'product' AND product_id = ?
                       LIMIT 1";
        $exists_stmt = mysqli_prepare($conn, $exists_sql);
        if (!$exists_stmt) {
            return ['success' => false, 'is_favorite' => false];
        }
        mysqli_stmt_bind_param($exists_stmt, "ii", $user_id, $product_id);
        mysqli_stmt_execute($exists_stmt);
        $exists_result = mysqli_stmt_get_result($exists_stmt);
        $existing = $exists_result ? mysqli_fetch_assoc($exists_result) : null;
        if ($exists_result) {
            mysqli_free_result($exists_result);
        }
        mysqli_stmt_close($exists_stmt);

        if ($existing) {
            $delete_sql = "DELETE FROM customer_favorites WHERE id = ? LIMIT 1";
            $delete_stmt = mysqli_prepare($conn, $delete_sql);
            if (!$delete_stmt) {
                return ['success' => false, 'is_favorite' => true];
            }
            $favorite_id = (int)$existing['id'];
            mysqli_stmt_bind_param($delete_stmt, "i", $favorite_id);
            $ok = mysqli_stmt_execute($delete_stmt);
            mysqli_stmt_close($delete_stmt);
            if (!$ok) {
                return ['success' => false, 'is_favorite' => true];
            }
            return ['success' => true, 'is_favorite' => false];
        }

        $insert_sql = "INSERT INTO customer_favorites (user_id, favorite_type, store_key, product_id)
                       VALUES (?, 'product', NULL, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        if (!$insert_stmt) {
            return ['success' => false, 'is_favorite' => false];
        }
        mysqli_stmt_bind_param($insert_stmt, "ii", $user_id, $product_id);
        $ok = mysqli_stmt_execute($insert_stmt);
        mysqli_stmt_close($insert_stmt);

        return ['success' => (bool)$ok, 'is_favorite' => (bool)$ok];
    }
}

if (!function_exists('favoritesFetchUserInStockFavorites')) {
    function favoritesFetchUserInStockFavorites(mysqli $conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !favoritesEnsureTable($conn)) {
            return [];
        }

        $sql = "SELECT
                    p.id,
                    p.name,
                    p.price,
                    p.image,
                    p.category,
                    p.avg_rating,
                    p.seller_id,
                    COALESCE(i.current_stock, p.stock, 0) AS current_stock,
                    COALESCE(NULLIF(TRIM(u.business_name), ''), 'Lechon Delights Kitchen') AS store_name
                FROM customer_favorites cf
                INNER JOIN products p ON cf.product_id = p.id
                LEFT JOIN inventory i ON (i.product_id = p.id AND i.date = CURRENT_DATE())
                LEFT JOIN users u ON p.seller_id = u.id
                WHERE cf.user_id = ?
                  AND cf.favorite_type = 'product'
                  AND p.is_archived = 0
                  AND p.is_active = 1
                  AND COALESCE(i.current_stock, p.stock, 0) > 0
                ORDER BY cf.created_at DESC";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $items = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $items[] = [
                    'id' => (int)$row['id'],
                    'name' => trim((string)$row['name']),
                    'price' => (float)$row['price'],
                    'image' => (string)$row['image'],
                    'category' => (string)$row['category'],
                    'seller_id' => (int)$row['seller_id'],
                    'current_stock' => (int)$row['current_stock'],
                    'store_name' => (string)$row['store_name']
                ];
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);

        return $items;
    }
}
