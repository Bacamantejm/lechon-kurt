<?php
/**
 * Partner Advertisements & Promos Helper
 */

if (!function_exists('paEnsureAdvertisementSchema')) {
    function paEnsureAdvertisementSchema($conn) {
        static $ensured = false;
        if ($ensured || !($conn instanceof mysqli)) {
            return;
        }
        $ensured = true;

        $sql = "CREATE TABLE IF NOT EXISTS `partner_advertisements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `seller_id` int(11) NOT NULL,
            `title` varchar(150) NOT NULL,
            `subtitle` varchar(255) DEFAULT NULL,
            `promo_code` varchar(60) DEFAULT NULL,
            `discount_tag` varchar(60) DEFAULT NULL,
            `banner_image` varchar(255) DEFAULT NULL,
            `target_url` varchar(255) DEFAULT NULL,
            `bg_theme` varchar(80) NOT NULL DEFAULT 'gradient-red',
            `status` enum('active','inactive','expired') NOT NULL DEFAULT 'active',
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_seller_status` (`seller_id`, `status`),
            KEY `idx_active_dates` (`status`, `start_date`, `end_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        mysqli_query($conn, $sql);

        // Seed initial featured ads if table is empty
        $check_res = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `partner_advertisements`");
        if ($check_res) {
            $row = mysqli_fetch_assoc($check_res);
            if ((int)($row['cnt'] ?? 0) === 0) {
                // Find active partner seller IDs to associate default promos
                $seller_res = mysqli_query($conn, "SELECT id, business_name FROM users WHERE account_type = 'organization' LIMIT 3");
                $sellers = [];
                if ($seller_res) {
                    while ($s = mysqli_fetch_assoc($seller_res)) {
                        $sellers[] = (int)$s['id'];
                    }
                    mysqli_free_result($seller_res);
                }
                $default_seller = !empty($sellers) ? $sellers[0] : 1;

                $seed_sql = "INSERT INTO `partner_advertisements` (`seller_id`, `title`, `subtitle`, `promo_code`, `discount_tag`, `target_url`, `bg_theme`, `status`) VALUES
                ({$default_seller}, 'Weekend Lechon Feast 20% OFF', 'Order any whole or half lechon belly and save 20% on checkout!', 'FEAST20', '20% OFF', 'menu.php?seller_id={$default_seller}', 'gradient-red', 'active'),
                ({$default_seller}, 'Free Delivery Special Deal', 'Free delivery on all Cavite orders over ₱1,500. Limited time promo!', 'FREEDEL', 'FREE DELIVERY', 'index.php#marketplaceStores', 'gradient-orange', 'active')";
                
                if (count($sellers) > 1) {
                    $s2 = $sellers[1];
                    $seed_sql .= ", ({$s2}, 'Crispy Lechon Belly Combo Deal', 'Get 1kg Roasted Lechon Belly + 2 Liters Drinks at a special discounted price.', 'BELLYCOMBO', 'SPECIAL COMBO', 'menu.php?seller_id={$s2}', 'gradient-dark', 'active')";
                }

                mysqli_query($conn, $seed_sql);
            }
            mysqli_free_result($check_res);
        }
    }
}

if (!function_exists('paGetActiveAdvertisements')) {
    function paGetActiveAdvertisements($conn, $limit = 6) {
        if (!($conn instanceof mysqli)) {
            return [];
        }
        paEnsureAdvertisementSchema($conn);

        $limit = max(1, (int)$limit);
        $today = date('Y-m-d');
        
        $sql = "SELECT a.*, u.business_name, u.business_logo, u.profile_image, u.address, u.city 
                FROM partner_advertisements a 
                LEFT JOIN users u ON a.seller_id = u.id 
                WHERE a.status = 'active' 
                  AND (a.start_date IS NULL OR a.start_date <= ?) 
                  AND (a.end_date IS NULL OR a.end_date >= ?) 
                ORDER BY a.id DESC LIMIT ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, "ssi", $today, $today, $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $ads = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $ads[] = $row;
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        return $ads;
    }
}

if (!function_exists('paGetSellerAdvertisements')) {
    function paGetSellerAdvertisements($conn, $seller_id) {
        if (!($conn instanceof mysqli) || (int)$seller_id <= 0) {
            return [];
        }
        paEnsureAdvertisementSchema($conn);

        $seller_id = (int)$seller_id;
        $sql = "SELECT * FROM partner_advertisements WHERE seller_id = ? ORDER BY id DESC";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, "i", $seller_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $ads = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $ads[] = $row;
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        return $ads;
    }
}
