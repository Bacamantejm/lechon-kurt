<?php
session_start();
$current_page = 'menu';
$page_title = "Menu & Order | Lechon Delights";

require_once 'includes/config.php';

$scoped_partner_seller_id = 0;
if (!empty($_SESSION['user_id']) && isset($conn) && $conn) {
    $logged_user_id = (int)$_SESSION['user_id'];
    $is_super_admin = (strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin' || strtolower(trim((string)($_SESSION['user_type'] ?? ''))) === 'super_admin');
    if (!$is_super_admin) {
        $check_scope_stmt = mysqli_prepare($conn, "SELECT u.id FROM users u WHERE u.id = ? AND (u.account_type = 'organization' OR EXISTS (SELECT 1 FROM franchise_applications fa WHERE fa.user_id = u.id AND fa.status = 'approved')) LIMIT 1");
        if ($check_scope_stmt) {
            mysqli_stmt_bind_param($check_scope_stmt, "i", $logged_user_id);
            mysqli_stmt_execute($check_scope_stmt);
            $check_scope_res = mysqli_stmt_get_result($check_scope_stmt);
            if ($check_scope_row = mysqli_fetch_assoc($check_scope_res)) {
                $scoped_partner_seller_id = (int)$check_scope_row['id'];
            }
            mysqli_stmt_close($check_scope_stmt);
        }
        if ($scoped_partner_seller_id <= 0) {
            $staff_scope_stmt = mysqli_prepare($conn, "SELECT pmu.partner_user_id FROM partner_managed_users pmu WHERE pmu.managed_user_id = ? LIMIT 1");
            if ($staff_scope_stmt) {
                mysqli_stmt_bind_param($staff_scope_stmt, "i", $logged_user_id);
                mysqli_stmt_execute($staff_scope_stmt);
                $staff_scope_res = mysqli_stmt_get_result($staff_scope_stmt);
                if ($staff_scope_row = mysqli_fetch_assoc($staff_scope_res)) {
                    $scoped_partner_seller_id = (int)$staff_scope_row['partner_user_id'];
                }
                mysqli_stmt_close($staff_scope_stmt);
            }
        }
    }
}

if ($scoped_partner_seller_id > 0) {
    $requested_seller_id = $scoped_partner_seller_id;
} else {
    $requested_seller_id = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
}
$requested_branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
if ($requested_seller_id > 0) {
    $_SESSION['storefront_seller_id'] = $requested_seller_id;
} else {
    unset($_SESSION['storefront_seller_id']);
}
unset($_SESSION['current_delivery_quote']);

include 'includes/header.php';
$storefront_name = '';
$storefront_subtitle = 'Choose from our delicious selection of lechon and Filipino dishes';
$storefront_partner_account = null;
$storefront_partner_branches = [];

// Initialize cart in session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Initialize delivery options
if (!isset($_SESSION['delivery_option'])) {
    $_SESSION['delivery_option'] = 'pickup'; // Default to pickup
}
if (!isset($_SESSION['delivery_location'])) {
    $_SESSION['delivery_location'] = 'cavite'; // Default to Cavite
}

// Delivery fee quotes are now calculated dynamically from the nearest store and the customer's pinned location.
$delivery_fees = [];

// Get products from database
require_once 'includes/config.php';
require_once __DIR__ . '/includes/favorites_helper.php';
require_once __DIR__ . '/includes/delivery_pricing_helper.php';
require_once __DIR__ . '/includes/partner_dashboard_helper.php';

function menuAddressLabel(string $address = '', string $barangay = '', string $city = '', string $province = ''): string
{
    $raw_parts = [];
    foreach ([$address, $barangay, $city, $province] as $part) {
        $value = trim((string)$part);
        if ($value === '') {
            continue;
        }
        $segments = array_values(array_filter(array_map('trim', explode(',', $value))));
        if (!empty($segments)) {
            foreach ($segments as $segment) {
                $raw_parts[] = $segment;
            }
        } else {
            $raw_parts[] = $value;
        }
    }

    $parts = [];
    $seen = [];
    foreach ($raw_parts as $part) {
        $norm = strtolower(trim((string)preg_replace('/\s+/', ' ', $part)));
        if ($norm === '' || isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $parts[] = trim($part);
    }

    return !empty($parts) ? implode(', ', $parts) : '';
}
function menuIsUsableAddress(string $value): bool
{
    $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $value)));
    if ($normalized === '') {
        return false;
    }
    if (strlen($normalized) < 7) {
        return false;
    }
    $invalid = ['asd', 'asdasd', 'test', 'sample', 'n/a', 'na', 'none', '-', '--'];
    return !in_array($normalized, $invalid, true);
}

$has_review_reply_schema = pdhEnsureProductReviewReplySchema($conn);

$deliveryPricingConfig = dpGetDeliveryPricingConfig();

$favorite_product_ids = [];
if (favoritesIsCustomerUserSession()) {
    $favorite_product_ids = favoritesFetchUserFavoriteProductIdMap($conn, (int)$_SESSION['user_id']);
}

$storefront_select_fields = "id, full_name, email, phone, address, account_type, business_name, business_type, business_registration, created_at, is_active";
if (userAccountControlColumnExists($conn, 'profile_image')) {
    $storefront_select_fields .= ", profile_image";
}
if (userAccountControlColumnExists($conn, 'business_logo')) {
    $storefront_select_fields .= ", business_logo";
}

if ($requested_seller_id > 0) {
    $store_stmt = mysqli_prepare($conn, "SELECT {$storefront_select_fields}
        FROM users
        WHERE id = ?
        LIMIT 1");
    if ($store_stmt) {
        mysqli_stmt_bind_param($store_stmt, "i", $requested_seller_id);
        mysqli_stmt_execute($store_stmt);
        $store_result = mysqli_stmt_get_result($store_stmt);
        $store_row = $store_result ? mysqli_fetch_assoc($store_result) : null;
        $storefront_name = trim((string)($store_row['business_name'] ?? ''));
        if ($storefront_name === '') {
            $storefront_name = trim((string)($store_row['full_name'] ?? ''));
        }
        if (is_array($store_row)) {
            $storefront_partner_account = [
                'id' => (int)($store_row['id'] ?? 0),
                'full_name' => trim((string)($store_row['full_name'] ?? '')),
                'email' => trim((string)($store_row['email'] ?? '')),
                'phone' => trim((string)($store_row['phone'] ?? '')),
                'address' => trim((string)($store_row['address'] ?? '')),
                'account_type' => strtolower(trim((string)($store_row['account_type'] ?? 'individual'))),
                'business_name' => trim((string)($store_row['business_name'] ?? '')),
                'business_type' => trim((string)($store_row['business_type'] ?? '')),
                'business_registration' => trim((string)($store_row['business_registration'] ?? '')),
                'created_at' => trim((string)($store_row['created_at'] ?? '')),
                'is_active' => (int)($store_row['is_active'] ?? 0),
                'profile_image' => trim((string)($store_row['profile_image'] ?? '')),
                'business_logo' => trim((string)($store_row['business_logo'] ?? '')),
            ];
        }
        if ($store_result) mysqli_free_result($store_result);
        mysqli_stmt_close($store_stmt);
    }

    if ($storefront_name !== '') {
        $storefront_subtitle = 'Order directly from ' . $storefront_name . ' and browse their posted products.';
    }
}

$current_user_address = '';
if (isset($_SESSION['user_id'])) {
    $user_query = "SELECT address FROM users WHERE id = ? LIMIT 1";
    $user_stmt = mysqli_prepare($conn, $user_query);
    if ($user_stmt) {
        mysqli_stmt_bind_param($user_stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($user_stmt);
        $user_result = mysqli_stmt_get_result($user_stmt);
        $user_row = mysqli_fetch_assoc($user_result);
        $current_user_address = trim($user_row['address'] ?? '');
        mysqli_stmt_close($user_stmt);
    }
}

// Store locations for pickup (database-driven, with static fallback)
$store_locations = [];
$store_scope_filter = ($scoped_partner_seller_id > 0) ? " AND owner_user_id = " . (int)$scoped_partner_seller_id : "";
$store_query = "SELECT store_id, owner_user_id, store_name, address, city, province, phone, opening_hours, latitude, longitude
                FROM store_locations
                WHERE is_active = 1 {$store_scope_filter}
                ORDER BY store_name";
$store_result = mysqli_query($conn, $store_query);

if ($store_result && mysqli_num_rows($store_result) > 0) {
    while ($store = mysqli_fetch_assoc($store_result)) {
        $street_address = trim((string)($store['address'] ?? ''));
        $city_name = trim((string)($store['city'] ?? ''));
        $province_name = trim((string)($store['province'] ?? ''));
        $store_locations[] = [
            'id' => (int)$store['store_id'],
            'owner_user_id' => (int)($store['owner_user_id'] ?? 0),
            'name' => (string)$store['store_name'],
            'street_address' => $street_address,
            'address' => menuAddressLabel($street_address, '', $city_name, $province_name),
            'city' => $city_name,
            'province' => $province_name,
            'phone' => (string)($store['phone'] ?? ''),
            'hours' => (string)($store['opening_hours'] ?? ''),
            'latitude' => is_null($store['latitude']) ? null : (float)$store['latitude'],
            'longitude' => is_null($store['longitude']) ? null : (float)$store['longitude']
        ];
    }
}

if (empty($store_locations)) {
    $store_locations = [
        [
            'id' => 1,
            'owner_user_id' => 0,
            'name' => 'Dasmariñas Branch',
            'street_address' => 'Governor Drive, Sampaloc 1',
            'address' => 'Governor Drive, Sampaloc 1, Dasmariñas, Cavite',
            'city' => 'Dasmariñas',
            'province' => 'Cavite',
            'phone' => '(046) 416-1234',
            'hours' => '8:00 AM - 10:00 PM',
            'latitude' => 14.32940000,
            'longitude' => 120.93670000
        ],
        [
            'id' => 2,
            'owner_user_id' => 0,
            'name' => 'Imus Branch',
            'street_address' => 'Nueno Avenue, Poblacion',
            'address' => 'Nueno Avenue, Poblacion, Imus, Cavite',
            'city' => 'Imus',
            'province' => 'Cavite',
            'phone' => '(046) 471-5678',
            'hours' => '8:00 AM - 10:00 PM',
            'latitude' => 14.42970000,
            'longitude' => 120.93670000
        ],
        [
            'id' => 3,
            'owner_user_id' => 0,
            'name' => 'General Trias Branch',
            'street_address' => 'Arnaldo Highway, San Francisco',
            'address' => 'Arnaldo Highway, San Francisco, General Trias, Cavite',
            'city' => 'General Trias',
            'province' => 'Cavite',
            'phone' => '(046) 509-9012',
            'hours' => '8:00 AM - 10:00 PM',
            'latitude' => 14.38690000,
            'longitude' => 120.88090000
        ],
        [
            'id' => 4,
            'owner_user_id' => 0,
            'name' => 'Bacoor Branch',
            'street_address' => 'Aguinaldo Highway, Talaba',
            'address' => 'Aguinaldo Highway, Talaba, Bacoor, Cavite',
            'city' => 'Bacoor',
            'province' => 'Cavite',
            'phone' => '(046) 417-3456',
            'hours' => '8:00 AM - 10:00 PM',
            'latitude' => 14.46040000,
            'longitude' => 120.96340000
        ],
        [
            'id' => 5,
            'owner_user_id' => 0,
            'name' => 'Tagaytay Branch',
            'street_address' => 'Emilio Aguinaldo Hwy, Silang Junction South',
            'address' => 'Emilio Aguinaldo Hwy, Silang Junction South, Tagaytay, Cavite',
            'city' => 'Tagaytay',
            'province' => 'Cavite',
            'phone' => '(046) 483-7890',
            'hours' => '8:00 AM - 10:00 PM',
            'latitude' => 14.11530000,
            'longitude' => 120.96210000
        ]
    ];
}

$default_pickup_location = (int)($_SESSION['pickup_location'] ?? ($store_locations[0]['id'] ?? 1));
if ($requested_branch_id > 0) {
    foreach ($store_locations as $branch_option) {
        if ((int)$branch_option['id'] === $requested_branch_id) {
            $default_pickup_location = $requested_branch_id;
            $_SESSION['pickup_location'] = $requested_branch_id;
            break;
        }
    }
}

if ($requested_seller_id > 0) {
    foreach ($store_locations as $branch_option) {
        if ((int)($branch_option['owner_user_id'] ?? 0) === $requested_seller_id) {
            $storefront_partner_branches[] = $branch_option;
        }
    }
}

// Compute total sold per product for best/top-seller filtering
$total_sold_map = [];
$sales_query = "SELECT
                    p.id,
                    COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END), 0) AS total_sold
                FROM products p
                LEFT JOIN order_items oi
                    ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                LEFT JOIN orders o
                    ON oi.order_id = o.id
                    AND o.is_archived = 0
                    AND o.status <> 'cancelled'
                WHERE p.is_archived = 0
                GROUP BY p.id";
$sales_result = mysqli_query($conn, $sales_query);
if ($sales_result) {
    while ($sales_row = mysqli_fetch_assoc($sales_result)) {
        $total_sold_map[(int)$sales_row['id']] = (int)$sales_row['total_sold'];
    }
}
$query = "SELECT p.*, COALESCE(i.current_stock, p.stock) as stock,
                 COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(u.full_name), ''), '') AS store_name
          FROM products p 
          LEFT JOIN inventory i ON p.id = i.product_id AND i.inventory_date = CURDATE() AND i.is_archived = 0
          LEFT JOIN users u ON p.seller_id = u.id
          WHERE p.is_archived = 0 AND p.is_active = 1";
if ($requested_seller_id > 0) {
    $query .= " AND p.seller_id = " . (int)$requested_seller_id;
}
$query .= " ORDER BY p.category, p.name";
$result = mysqli_query($conn, $query);

if (!$result) {
    die("Database query failed: " . mysqli_error($conn));
}

$menu_categories = [];
$product_details = [];

// Check if we got any products
if (mysqli_num_rows($result) > 0) {
    while ($product = mysqli_fetch_assoc($result)) {
        $category = $product['category'];
        if (!isset($menu_categories[$category])) {
            $menu_categories[$category] = [];
        }
        
        // Parse product details
        $sizes = ['Regular']; // Always include Regular size
        $addons = [];
        $weights = [];
        $good_for = [];
        $size_prices = [];
        
        // Set default Regular size values
        $weights['Regular'] = $product['weight_info'] ?? '';
        $good_for['Regular'] = $product['pax_info'] ?? '';
        $size_prices['Regular'] = $product['price'];
        
        // Parse sizes if they exist
        if ($product['sizes']) {
            $sizes_data = json_decode($product['sizes'], true);
            if ($sizes_data && is_array($sizes_data)) {
                foreach ($sizes_data as $size) {
                    if (isset($size['name'])) {
                        $size_name = $size['name'];
                        if (!in_array($size_name, $sizes)) {
                            $sizes[] = $size_name;
                        }
                        $weights[$size_name] = $size['weight'] ?? $product['weight_info'] ?? '';
                        $good_for[$size_name] = $size['good_for'] ?? $product['pax_info'] ?? '';
                        $size_prices[$size_name] = $size['price'] ?? $product['price'];
                    }
                }
            }
        }
        
        // Parse addons if they exist
        if ($product['addons']) {
            $addons_data = json_decode($product['addons'], true);
            if ($addons_data && is_array($addons_data)) {
                $addons = $addons_data;
            }
        }
        
        $product_info = [
            'id' => $product['id'], // Numeric ID
            'product_id' => $product['product_id'], // String product_id like 'wl-001'
            'name' => $product['name'],
            'description' => $product['description'],
            'store_name' => trim((string)($product['store_name'] ?? '')),
            'base_price' => $product['price'],
            'image' => $product['image'],
            'sizes' => $sizes,
            'weights' => $weights,
            'good_for' => $good_for,
            'size_prices' => $size_prices,
            'addons' => $addons,
            'category' => $category,
            'general_weight' => $product['weight_info'],
            'general_pax' => $product['pax_info'],
            'stock' => $product['stock'] ?? 0,
            'avg_rating' => $product['avg_rating'] ?? 0,
            'review_count' => $product['review_count'] ?? 0,
            'total_sold' => $total_sold_map[(int)$product['id']] ?? 0
        ];
        
        $menu_categories[$category][] = $product_info;
        $product_details[$product['id']] = $product_info;
    }
} else {
    error_log("No products found in database");
}

// Store product details in session for cart
$_SESSION['product_details'] = $product_details;
$_SESSION['delivery_fees'] = $delivery_fees;
$_SESSION['store_locations'] = $store_locations;

$selected_pickup_branch = null;
foreach ($store_locations as $branch_option) {
    if ((int)($branch_option['id'] ?? 0) === (int)$default_pickup_location) {
        $selected_pickup_branch = $branch_option;
        break;
    }
}

$breadcrumb_location = 'Marketplace';
$storefront_breadcrumb_address = '';
if ($requested_seller_id > 0) {
    $seller_branch = $storefront_partner_branches[0] ?? null;
    $seller_branch_address = $seller_branch ? menuAddressLabel(
        (string)($seller_branch['street_address'] ?? $seller_branch['address'] ?? ''),
        '',
        (string)($seller_branch['city'] ?? ''),
        (string)($seller_branch['province'] ?? '')
    ) : '';
    $account_address = menuAddressLabel(
        (string)($storefront_partner_account['address'] ?? ''),
        '',
        '',
        ''
    );
    $official_business_address = '';

    $fa_stmt = mysqli_prepare($conn, "SELECT business_address, barangay_name, city_name, province_name, business_name, business_type, contact_person, contact_phone, contact_email, application_number, reviewed_at FROM franchise_applications WHERE user_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    if ($fa_stmt) {
        mysqli_stmt_bind_param($fa_stmt, "i", $requested_seller_id);
        mysqli_stmt_execute($fa_stmt);
        $fa_result = mysqli_stmt_get_result($fa_stmt);
        $fa_row = $fa_result ? mysqli_fetch_assoc($fa_result) : null;
        if ($fa_result) mysqli_free_result($fa_result);
        mysqli_stmt_close($fa_stmt);
        if (is_array($fa_row)) {
            if ($storefront_name === '') {
                $storefront_name = trim((string)($fa_row['business_name'] ?? ''));
                if ($storefront_name !== '') {
                    $storefront_subtitle = 'Order directly from ' . $storefront_name . ' and browse their posted products.';
                }
            }
            $official_business_address = menuAddressLabel(
                (string)($fa_row['business_address'] ?? ''),
                (string)($fa_row['barangay_name'] ?? ''),
                (string)($fa_row['city_name'] ?? ''),
                (string)($fa_row['province_name'] ?? '')
            );
        }
    }

    if (!menuIsUsableAddress($official_business_address)) {
        $official_business_address = menuIsUsableAddress($account_address) ? $account_address : $official_business_address;
    }
    if (!menuIsUsableAddress($official_business_address)) {
        $official_business_address = menuIsUsableAddress($seller_branch_address) ? $seller_branch_address : $official_business_address;
    }

    if ($requested_branch_id > 0 && $selected_pickup_branch) {
        $requested_branch_address = menuAddressLabel(
            (string)($selected_pickup_branch['street_address'] ?? $selected_pickup_branch['address'] ?? ''),
            '',
            (string)($selected_pickup_branch['city'] ?? ''),
            (string)($selected_pickup_branch['province'] ?? '')
        );
        $storefront_breadcrumb_address = menuIsUsableAddress($requested_branch_address) ? $requested_branch_address : $official_business_address;
    } else {
        $storefront_breadcrumb_address = $official_business_address;
    }
}

if ($storefront_breadcrumb_address === '' && $selected_pickup_branch) {
    $storefront_breadcrumb_address = menuAddressLabel(
        (string)($selected_pickup_branch['street_address'] ?? $selected_pickup_branch['address'] ?? ''),
        '',
        (string)($selected_pickup_branch['city'] ?? ''),
        (string)($selected_pickup_branch['province'] ?? '')
    );
}

if ($storefront_breadcrumb_address !== '') {
    $breadcrumb_location = $storefront_breadcrumb_address;
}

$store_display_name = $storefront_name !== '' ? $storefront_name : 'Lechon Delights';
$store_display_subtitle = $storefront_subtitle;

$category_keys = array_values(array_filter(array_keys($menu_categories), static function ($category) {
    return trim((string)$category) !== '';
}));
$store_category_line = !empty($category_keys) ? implode(' • ', array_slice($category_keys, 0, 2)) : 'Lechon • Filipino';

$store_item_count = count($product_details);
$store_min_price = null;
$store_rating_weighted = 0.0;
$store_review_total = 0;
$store_logo_image = '';

if (is_array($storefront_partner_account) && function_exists('resolveUserAvatarPathFromRow')) {
    $logo_candidate = trim((string)resolveUserAvatarPathFromRow($storefront_partner_account));
    if ($logo_candidate !== '') {
        $logo_candidate_path = ltrim($logo_candidate, '/');
        if (is_file(__DIR__ . '/' . $logo_candidate_path)) {
            $store_logo_image = $logo_candidate_path;
        }
    }
}

foreach ($product_details as $product_info) {
    if (isset($product_info['size_prices']) && is_array($product_info['size_prices']) && !empty($product_info['size_prices'])) {
        $product_min = min(array_map('floatval', $product_info['size_prices']));
        $store_min_price = $store_min_price === null ? $product_min : min($store_min_price, $product_min);
    }

    $item_reviews = (int)($product_info['review_count'] ?? 0);
    $item_rating = (float)($product_info['avg_rating'] ?? 0);
    if ($item_reviews > 0 && $item_rating > 0) {
        $store_rating_weighted += ($item_rating * $item_reviews);
        $store_review_total += $item_reviews;
    }

    if ($store_logo_image === '') {
        $image_path = trim((string)($product_info['image'] ?? ''));
        if ($image_path !== '') {
            if (stripos($image_path, 'http://') === 0 || stripos($image_path, 'https://') === 0) {
                $store_logo_image = $image_path;
            } elseif (strpos($image_path, '/') === false) {
                $store_logo_image = 'images/menu/' . $image_path;
            } else {
                $store_logo_image = $image_path;
            }
        }
    }
}

$store_rating_value = $store_review_total > 0 ? ($store_rating_weighted / $store_review_total) : 0;
$store_rating_label = $store_review_total > 0 ? number_format($store_rating_value, 1) . '/5 (' . number_format($store_review_total) . ')' : 'No ratings yet';
$store_price_label = $store_min_price !== null ? 'Starts at PHP ' . number_format($store_min_price, 2) : 'No active menu yet';

$store_initials_parts = preg_split('/\s+/', trim($store_display_name));
$store_initials = '';
if (is_array($store_initials_parts)) {
    foreach ($store_initials_parts as $part) {
        if ($part === '') continue;
        $store_initials .= strtoupper(substr($part, 0, 1));
        if (strlen($store_initials) >= 2) break;
    }
}
if ($store_initials === '') $store_initials = 'LD';

$mobile_quick_order_bottom = (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'customer') ? '86px' : '14px';

$store_recent_reviews = [];
$product_ids_for_reviews = array_values(array_map('intval', array_keys($product_details)));
if (!empty($product_ids_for_reviews)) {
    $review_ids_sql = implode(',', $product_ids_for_reviews);
    $review_reply_select = $has_review_reply_schema
        ? "pr.seller_reply, pr.seller_reply_at, COALESCE(NULLIF(TRIM(reply_u.full_name), ''), 'Store') AS seller_reply_name,"
        : "'' AS seller_reply, '' AS seller_reply_at, 'Store' AS seller_reply_name,";
    $review_reply_join = $has_review_reply_schema
        ? "LEFT JOIN users reply_u ON reply_u.id = pr.seller_reply_by"
        : '';
    $reviews_query = "
        SELECT
            pr.product_id,
            pr.rating,
            pr.comment,
            pr.created_at,
            {$review_reply_select}
            p.name AS product_name,
            COALESCE(NULLIF(TRIM(u.full_name), ''), 'Customer') AS reviewer_name
        FROM product_reviews pr
        INNER JOIN products p ON p.id = pr.product_id
        LEFT JOIN users u ON u.id = pr.user_id
        {$review_reply_join}
        WHERE pr.is_approved = 1
          AND pr.product_id IN ($review_ids_sql)";

    if ($requested_seller_id > 0) {
        $reviews_query .= " AND p.seller_id = " . (int)$requested_seller_id;
    }

    $reviews_query .= " ORDER BY pr.created_at DESC LIMIT 18";
    $reviews_result = mysqli_query($conn, $reviews_query);

    if ($reviews_result) {
        while ($review_row = mysqli_fetch_assoc($reviews_result)) {
            $reviewer_name = trim((string)($review_row['reviewer_name'] ?? 'Customer'));
            $first_name = $reviewer_name;
            if ($reviewer_name !== '') {
                $name_parts = preg_split('/\s+/', $reviewer_name);
                $first_name = trim((string)($name_parts[0] ?? $reviewer_name));
                if ($first_name === '') {
                    $first_name = $reviewer_name;
                }
            }

            $created_at_raw = (string)($review_row['created_at'] ?? '');
            $created_at_label = 'Recently';
            if ($created_at_raw !== '') {
                $timestamp = strtotime($created_at_raw);
                if ($timestamp !== false) {
                    $created_at_label = date('M j, Y', $timestamp);
                }
            }

            $store_recent_reviews[] = [
                'first_name' => $first_name !== '' ? $first_name : 'Customer',
                'rating' => max(1, min(5, (int)($review_row['rating'] ?? 0))),
                'comment' => trim((string)($review_row['comment'] ?? '')),
                'created_at' => $created_at_label,
                'product_name' => trim((string)($review_row['product_name'] ?? 'Menu Item')),
                'seller_reply' => trim((string)($review_row['seller_reply'] ?? '')),
                'seller_reply_at' => trim((string)($review_row['seller_reply_at'] ?? '')),
                'seller_reply_name' => trim((string)($review_row['seller_reply_name'] ?? 'Store'))
            ];
        }
        mysqli_free_result($reviews_result);
    }
}

// Fetch active store-specific deals/vouchers for this shop
$store_deals = [];
$lookup_seller_id = (int)($requested_seller_id ?: ($store_owner_id ?? 0));
if ($lookup_seller_id > 0 && isset($conn) && $conn instanceof mysqli) {
    require_once __DIR__ . '/includes/partner_voucher_helper.php';
    pvEnsureVoucherSchema($conn);
    $vd_stmt = mysqli_prepare($conn, "
        SELECT id, code, name, description, discount_type, discount_value, min_order_amount, max_discount_amount 
        FROM partner_vouchers 
        WHERE seller_id = ? AND is_active = 1 
          AND (start_at IS NULL OR start_at <= NOW()) 
          AND (end_at IS NULL OR end_at >= NOW()) 
        ORDER BY id DESC 
        LIMIT 4
    ");
    if ($vd_stmt) {
        mysqli_stmt_bind_param($vd_stmt, "i", $lookup_seller_id);
        mysqli_stmt_execute($vd_stmt);
        $vd_res = mysqli_stmt_get_result($vd_stmt);
        if ($vd_res) {
            while ($vd_row = mysqli_fetch_assoc($vd_res)) {
                $store_deals[] = $vd_row;
            }
            mysqli_free_result($vd_res);
        }
        mysqli_stmt_close($vd_stmt);
    }
}
?>
<style>
/* Immediate Navigation & Tab Styles */
.panda-cat-tab {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    padding: 10px 0 !important;
    color: #64748b !important;
    font-size: 0.92rem !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    border-bottom: 3px solid transparent !important;
    transition: all 0.2s ease !important;
    cursor: pointer !important;
}
.panda-cat-tab:hover {
    color: #171922 !important;
    text-decoration: none !important;
}
.panda-cat-tab.active {
    color: #171922 !important;
    font-weight: 800 !important;
    border-bottom-color: #171922 !important;
    text-decoration: none !important;
}
.panda-cat-count {
    font-size: 0.84rem !important;
    color: #64748b !important;
    font-weight: 500 !important;
}
.panda-cat-tab.active .panda-cat-count {
    color: #171922 !important;
    font-weight: 700 !important;
}

/* Store Breadcrumb & Metadata Links */
.store-breadcrumb a {
    color: #64748b !important;
    text-decoration: none !important;
    font-size: 0.84rem !important;
}
.store-breadcrumb a:hover {
    color: #b3261e !important;
}
.storefront-meta-row-secondary a {
    color: #475467 !important;
    text-decoration: none !important;
}
.storefront-meta-row-secondary a:hover {
    color: #171922 !important;
}

/* Smooth Image Display & Zero-Jump Sizing */
.store-logo-tile {
    width: 100px !important;
    height: 100px !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    flex-shrink: 0 !important;
    background: #e2e8f0 !important;
}
.store-logo-tile img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    display: block !important;
    transition: transform 0.25s ease !important;
}
.item-image {
    position: relative !important;
    height: 140px !important;
    overflow: hidden !important;
    background: #f8fafc !important;
    border-radius: 16px 16px 0 0 !important;
}
.item-image img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    display: block !important;
    transition: transform 0.25s ease !important;
}
.menu-item:hover .item-image img {
    transform: scale(1.03) !important;
}

/* Dark Mode Theme Engine for Menu & Quick Order */
body.dark-mode .panda-cat-tab {
    color: #94a3b8 !important;
    text-decoration: none !important;
}
body.dark-mode .panda-cat-tab:hover {
    color: #ffffff !important;
    text-decoration: none !important;
}
body.dark-mode .panda-cat-tab.active {
    color: #ef4444 !important;
    border-bottom-color: #ef4444 !important;
    text-decoration: none !important;
}
body.dark-mode .panda-cat-count {
    color: #64748b !important;
}
body.dark-mode .panda-cat-tab.active .panda-cat-count {
    color: #ef4444 !important;
}
body.dark-mode .store-breadcrumb a {
    color: #94a3b8 !important;
}
body.dark-mode .store-breadcrumb a:hover {
    color: #ffffff !important;
}
body.dark-mode .storefront-meta-row-secondary a {
    color: #cbd5e1 !important;
}
body.dark-mode .storefront-meta-row-secondary a:hover {
    color: #ffffff !important;
}
body.dark-mode .quick-order-panel {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}
body.dark-mode .quick-order-tabs {
    background: #0f172a !important;
    border: 1px solid #334155 !important;
}
body.dark-mode .quick-order-tab {
    color: #94a3b8 !important;
}
body.dark-mode .quick-order-tab.active {
    background: #1e293b !important;
    color: #f8fafc !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3) !important;
}
body.dark-mode .quick-order-hero {
    background: #111827 !important;
    border: 1px solid #334155 !important;
}
body.dark-mode .quick-order-hero h3 {
    color: #f8fafc !important;
}
body.dark-mode .quick-order-hero p {
    color: #94a3b8 !important;
}
body.dark-mode .quick-order-summary {
    color: #f8fafc !important;
}
body.dark-mode .quick-order-meta {
    color: #94a3b8 !important;
}
body.dark-mode .quick-order-items-empty {
    color: #94a3b8 !important;
}
body.dark-mode .quick-order-item {
    border-bottom-color: #334155 !important;
}
body.dark-mode .quick-order-item-title {
    color: #f8fafc !important;
}
body.dark-mode .quick-order-item-price {
    color: #ef4444 !important;
}
body.dark-mode .summary-row {
    color: #f8fafc !important;
}
body.dark-mode .summary-row strong,
body.dark-mode #quickOrderTotal {
    color: #f8fafc !important;
}
body.dark-mode .quick-order-link {
    color: #f8fafc !important;
}
body.dark-mode .quick-order-checkout:disabled {
    background: #334155 !important;
    color: #64748b !important;
}
body.dark-mode .quick-order-checkout:not(:disabled) {
    background: #b3261e !important;
    color: #ffffff !important;
}
</style>

<!-- Product Preview Modal -->
<div class="product-preview-modal" id="productPreviewModal" style="display:none;">
    <div class="preview-modal-content">
        <button class="preview-close" id="previewClose">&times;</button>
        <div class="preview-body">
            <div class="preview-image">
                <img id="previewProductImage" src="" alt="">
            </div>
            <div class="preview-details">
                <h3 id="previewProductName"></h3>
                <p class="preview-description" id="previewDescription"></p>
                
                <div class="preview-price-section">
                    <h4>Price: <span id="previewPrice" class="price-amount">PHP 0.00</span></h4>
                    <div class="size-weight-info" id="sizeWeightInfo"></div>
                </div>
                
                <div class="preview-options">
                    <div class="size-selection">
                        <label>Select Size:</label>
                        <div class="size-buttons" id="previewSizeButtons"></div>
                    </div>
                    
                    <div class="addons-selection" id="previewAddonsSection">
                        <label>Add-ons (Optional):</label>
                        <div class="addons-list" id="previewAddonsList"></div>
                    </div>
                    
                    <div class="quantity-selection">
                        <label>Quantity:</label>
                        <div class="quantity-control">
                            <button type="button" class="qty-minus">-</button>
                            <input type="number" class="qty-input" value="1" min="1" max="20">
                            <button type="button" class="qty-plus">+</button>
                        </div>
                    </div>
                </div>
                
                <div class="order-summary" id="previewOrderSummary">
                    <h4>Order Summary</h4>
                    <div class="summary-details">
                        <p>Product: <span id="summaryProduct"></span></p>
                        <p>Size: <span id="summarySize">Regular</span></p>
                        <p>Add-ons: <span id="summaryAddons">None</span></p>
                        <p>Quantity: <span id="summaryQuantity">1</span></p>
                        <p>Unit Price: <span id="summaryUnitPrice">PHP 0.00</span></p>
                        <p class="summary-total">Item Total: <span id="summaryTotal">PHP 0.00</span></p>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
                    <button class="btn-primary add-to-cart-confirm" id="addToCartConfirm" style="flex: 1; min-width: 160px;">
                        <i class="fas fa-cart-plus"></i> Add to Cart
                    </button>
                    <a href="preorder.php" id="previewPreorderLink" style="display: none; align-items: center; justify-content: center; gap: 6px; padding: 12px 18px; border-radius: 12px; font-weight: 700; font-size: 0.88rem; background: #fffaeb; color: #b54708; border: 1px solid #fedf89; text-decoration: none; white-space: nowrap;">
                        <i class="fas fa-calendar-check"></i> Reserve Event Date
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="preview-overlay" id="previewOverlay"></div>
</div>

<!-- Foodpanda Storefront Reviews Modal -->
<div class="product-preview-modal" id="storefrontReviewsModal" style="display:none;">
    <div class="preview-modal-content" style="max-width: 640px; border-radius: 24px; padding: 24px; z-index: 2002;">
        <button type="button" class="preview-close" id="closeStorefrontReviewsModal" style="top:18px; right:18px;">&times;</button>
        <div class="preview-body" style="display:block; padding:0;">
            <h2 style="font-family:'Outfit',sans-serif; font-size:1.35rem; font-weight:800; color:#171922; margin:0 0 2px;">
                <?php echo htmlspecialchars($store_display_name); ?>
            </h2>
            <p style="color:#64748b; font-size:0.9rem; margin:0 0 20px; font-weight:600;">Reviews</p>
            
            <!-- Rating Breakdown Header Card -->
            <div style="background:#fff9f2; border:1px solid #efddcd; border-radius:18px; padding:20px; margin-bottom:20px; display:flex; gap:20px; align-items:center;">
                <div style="text-align:center; min-width:110px; border-right:1px solid #efddcd; padding-right:16px;">
                    <div style="font-size:2.8rem; font-weight:800; color:#171922; line-height:1;">
                        <?php echo $store_rating_value > 0 ? number_format($store_rating_value, 1) : '0'; ?>
                    </div>
                    <div style="color:#f59e0b; font-size:0.9rem; margin:6px 0 4px;">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <i class="<?php echo $s <= round($store_rating_value) ? 'fas' : 'far'; ?> fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:0.78rem; color:#64748b; font-weight:600;">
                        All ratings (<?php echo number_format($store_review_total); ?>)
                    </div>
                </div>
                
                <div style="flex:1;">
                    <?php
                    $star_counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
                    foreach ($store_recent_reviews as $r) {
                        $st = max(1, min(5, (int)($r['rating'] ?? 0)));
                        $star_counts[$st] = ($star_counts[$st] ?? 0) + 1;
                    }
                    $total_reviews_count = max(1, count($store_recent_reviews));
                    for ($s = 5; $s >= 1; $s--):
                        $pct = round(($star_counts[$s] / $total_reviews_count) * 100);
                    ?>
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:0.8rem; color:#64748b; font-weight:600;">
                        <span style="min-width:24px; text-align:right;"><?php echo $s; ?> <i class="fas fa-star" style="color:#f59e0b; font-size:0.75rem;"></i></span>
                        <div style="flex:1; height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden;">
                            <div style="width:<?php echo $pct; ?>%; height:100%; background:#f59e0b; border-radius:999px;"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- Filter Pills -->
            <div class="store-review-filters" style="display:flex; gap:8px; margin-bottom:18px; overflow-x:auto; padding-bottom:4px;">
                <button type="button" class="store-review-filter-btn active" data-filter="top">Top reviews</button>
                <button type="button" class="store-review-filter-btn" data-filter="newest">Newest</button>
                <button type="button" class="store-review-filter-btn" data-filter="highest">Highest rating</button>
                <button type="button" class="store-review-filter-btn" data-filter="lowest">Lowest rating</button>
            </div>
            
            <!-- Reviews List -->
            <div id="storefrontReviewsList" style="max-height:360px; overflow-y:auto; display:flex; flex-direction:column; gap:14px; padding-right:4px;">
                <?php if (!empty($store_recent_reviews)): ?>
                    <?php foreach ($store_recent_reviews as $review): ?>
                        <?php
                        $r_rating = (int)($review['rating'] ?? 5);
                        $r_date = (string)($review['created_at'] ?? '');
                        $r_comment = trim((string)($review['comment'] ?? ''));
                        $r_timestamp = strtotime($r_date) ?: 0;
                        ?>
                        <div class="store-review-item" 
                             data-rating="<?php echo $r_rating; ?>" 
                             data-timestamp="<?php echo $r_timestamp; ?>" 
                             data-has-comment="<?php echo $r_comment !== '' ? '1' : '0'; ?>"
                             style="background:#ffffff; border:1px solid #efddcd; border-radius:16px; padding:16px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <strong style="font-size:0.95rem; font-weight:800; color:#2a211d;"><?php echo htmlspecialchars($review['first_name']); ?></strong>
                                <span style="font-size:0.78rem; color:#7b6d64; font-weight:500;"><?php echo htmlspecialchars($review['created_at']); ?></span>
                            </div>
                            <div style="color:#ef6b2e; font-size:0.85rem; margin-bottom:8px;">
                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                    <i class="<?php echo $star <= $r_rating ? 'fas' : 'far'; ?> fa-star"></i>
                                <?php endfor; ?>
                            </div>
                            <p style="font-size:0.9rem; color:#2a211d; line-height:1.5; margin:0 0 8px;">
                                <?php echo htmlspecialchars($r_comment !== '' ? $r_comment : 'Customer did not leave a written comment.'); ?>
                            </p>
                            <?php if ($review['seller_reply'] !== ''): ?>
                                <div style="background:#fff9f2; border-left:3px solid #ef6b2e; padding:10px 12px; border-radius:0 10px 10px 0; margin-top:8px;">
                                    <div style="font-size:0.8rem; font-weight:700; color:#ef6b2e; margin-bottom:2px;">
                                        <i class="fas fa-reply"></i> <?php echo htmlspecialchars($review['seller_reply_name']); ?> replied
                                    </div>
                                    <div style="font-size:0.86rem; color:#2a211d;"><?php echo htmlspecialchars($review['seller_reply']); ?></div>
                                </div>
                            <?php endif; ?>
                            <div style="font-size:0.78rem; color:#7b6d64; margin-top:6px; font-weight:600;">
                                Item: <?php echo htmlspecialchars($review['product_name']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center; padding:30px 20px; color:#7b6d64;">
                        <i class="far fa-comment-dots" style="font-size:2.5rem; color:#efddcd; margin-bottom:10px; display:block;"></i>
                        <p style="margin:0; font-weight:600;">No approved reviews yet for this storefront.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="preview-overlay" id="storefrontReviewsOverlay"></div>
</div>

<section class="storefront-header">
    <div class="container">
        <nav class="store-breadcrumb" aria-label="Breadcrumb">
            <a href="locations.php"><?php echo htmlspecialchars($breadcrumb_location); ?></a>
            <span class="breadcrumb-sep">&rsaquo;</span>
            <a href="index.php#marketplaceStores">Store list</a>
            <span class="breadcrumb-sep">&rsaquo;</span>
            <span class="is-current"><?php echo htmlspecialchars($store_display_name); ?></span>
        </nav>
        <div class="storefront-overview">
            <div class="store-logo-tile">
                <?php if ($store_logo_image !== ''): ?>
                    <img src="<?php echo htmlspecialchars($store_logo_image); ?>" alt="<?php echo htmlspecialchars($store_display_name); ?>" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <span class="store-logo-initials" style="display:none;"><?php echo htmlspecialchars($store_initials); ?></span>
                <?php else: ?>
                    <span class="store-logo-initials"><?php echo htmlspecialchars($store_initials); ?></span>
                <?php endif; ?>
            </div>
            <div class="storefront-copy">
                <p class="storefront-categories"><?php echo htmlspecialchars($store_category_line); ?></p>
                <h1><?php echo htmlspecialchars($store_display_name); ?></h1>
                <p class="storefront-subtitle"><?php echo htmlspecialchars($store_display_subtitle); ?></p>
                <div class="storefront-meta-row">
                    <span><i class="fas fa-motorcycle"></i> Delivery and pickup available</span>
                    <span><i class="fas fa-receipt"></i> <?php echo htmlspecialchars($store_price_label); ?></span>
                    <span><i class="fas fa-utensils"></i> <?php echo number_format($store_item_count); ?> items</span>
                </div>
                <div class="storefront-meta-row storefront-meta-row-secondary">
                    <a href="javascript:void(0);" id="openStorefrontReviewsBtn"><i class="fas fa-star" style="color:#f59e0b;"></i> <?php echo htmlspecialchars($store_rating_label); ?> <span style="text-decoration:underline; font-weight:700; margin-left:4px;">See reviews</span></a>
                    <a href="#menu"><i class="far fa-comment-dots"></i> See menu</a>
                    <a href="locations.php"><i class="fas fa-circle-info"></i> More info</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Menu Section -->
<section class="menu-section" id="menu">
    <div class="container">
        <?php if (!empty($store_deals)): ?>
            <div class="store-deals-strip" style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 18px 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 34px; height: 34px; border-radius: 10px; background: #fff1f0; color: #b3261e; display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fas fa-tags"></i>
                        </div>
                        <div>
                            <h3 style="margin: 0; font-family: 'Outfit', sans-serif; font-size: 1.1rem; font-weight: 800; color: #101828;">Store Exclusive Deals</h3>
                            <p style="margin: 0; font-size: 0.78rem; color: #667085;">Special discounts available only when ordering from <?php echo htmlspecialchars($store_display_name); ?>.</p>
                        </div>
                    </div>
                    <span style="font-size: 0.76rem; font-weight: 700; color: #027a48; background: #ecfdf3; border: 1px solid #abefc6; padding: 4px 10px; border-radius: 999px;">
                        <i class="fas fa-badge-check"></i> <?php echo count($store_deals); ?> Store Offer<?php echo count($store_deals) > 1 ? 's' : ''; ?> Available
                    </span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px;">
                    <?php foreach ($store_deals as $deal): ?>
                        <?php
                            $is_pct = strtolower((string)$deal['discount_type']) === 'percent';
                            $val_text = $is_pct ? (rtrim(rtrim(number_format((float)$deal['discount_value'], 2), '0'), '.') . '% OFF') : ('₱' . number_format((float)$deal['discount_value'], 0) . ' OFF');
                            $min_order = (float)$deal['min_order_amount'];
                            $min_text = $min_order > 0 ? ('Min. spend ₱' . number_format($min_order, 0)) : 'No min. spend';
                            $deal_code = htmlspecialchars((string)$deal['code']);
                        ?>
                        <div style="background: #f8f9fa; border: 1px dashed #d0d5dd; border-radius: 12px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <div>
                                <div style="font-weight: 800; color: #b3261e; font-size: 1.05rem;"><?php echo $val_text; ?></div>
                                <div style="font-size: 0.78rem; color: #344054; font-weight: 700; margin-top: 1px;"><?php echo htmlspecialchars((string)$deal['name']); ?></div>
                                <div style="font-size: 0.72rem; color: #667085;"><?php echo $min_text; ?></div>
                            </div>
                            <button type="button" onclick="claimStoreVoucher('<?php echo $deal_code; ?>', this)" style="background: #ffffff; border: 1px solid #d0d5dd; color: #344054; font-size: 0.76rem; font-weight: 700; padding: 7px 12px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; transition: all 0.15s ease;">
                                <i class="fas fa-copy"></i> <?php echo $deal_code; ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Foodpanda Unified Sticky Category Navigation Bar -->
        <div class="panda-menu-sticky-bar" id="pandaMenuStickyBar">
            <div class="panda-menu-bar-inner">
                <!-- Search in Menu Input -->
                <div class="panda-menu-search-box">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="search" id="menuSearchInput" placeholder="Search in menu" autocomplete="off">
                </div>

                <!-- Left Scroll Arrow -->
                <button type="button" class="panda-cat-arrow arrow-left" id="pandaCatScrollLeft" aria-label="Scroll categories left">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <!-- Category Tabs Strip -->
                <div class="panda-cat-strip-wrap" id="pandaCatStripWrap">
                    <div class="panda-cat-strip" id="pandaCatStrip">
                        <?php $cat_index = 0; foreach ($menu_categories as $category => $items): ?>
                            <?php
                            $cat_slug = strtolower(str_replace(' ', '-', $category));
                            $cat_count = count($items);
                            $is_active_cat = ($cat_index === 0);
                            ?>
                            <a href="#<?php echo htmlspecialchars($cat_slug); ?>" class="panda-cat-tab<?php echo $is_active_cat ? ' active' : ''; ?>" data-category="<?php echo htmlspecialchars($cat_slug); ?>">
                                <?php echo htmlspecialchars($category); ?> <span class="panda-cat-count">(<?php echo $cat_count; ?>)</span>
                            </a>
                        <?php $cat_index++; endforeach; ?>
                    </div>
                </div>

                <!-- Right Scroll Arrow -->
                <button type="button" class="panda-cat-arrow arrow-right" id="pandaCatScrollRight" aria-label="Scroll categories right">
                    <i class="fas fa-chevron-right"></i>
                </button>
                
                <!-- Filter Dropdown -->
                <div class="panda-menu-filter-select-wrap">
                    <select id="menuSortFilter" class="panda-menu-filter-select">
                        <option value="default" selected>Default Sort</option>
                        <option value="lowest_price">Lowest Price</option>
                        <option value="highest_price">Highest Price</option>
                        <option value="best_top_seller">Best / Top Seller</option>
                    </select>
                </div>
            </div>
        </div>
        <p id="menuSearchResultInfo" class="menu-search-result-info" style="display:none;"></p>

        <div class="menu-layout">
            <div class="menu-main-column">

        <?php if (empty($menu_categories)): ?>
        <div class="menu-filter-bar" style="margin-top:16px;">
            <label>Storefront update:</label>
            <span style="color:#6b7280;font-weight:600;">This store has no active posted products yet.</span>
            <a href="index.php#marketplaceStores" class="btn-primary" style="margin-left:auto;">Back to Stores</a>
        </div>
        <?php endif; ?>

        <!-- Menu Items by Category -->
        <?php foreach ($menu_categories as $category => $items): ?>
        <div class="menu-category" id="<?php echo strtolower(str_replace(' ', '-', $category)); ?>">
            <h2 class="category-title"><?php echo $category; ?></h2>
            <div class="menu-items-grid">
                <?php foreach ($items as $item): ?>
                <?php
                    $item_search_tokens = [
                        (string)($item['name'] ?? ''),
                        (string)($item['category'] ?? ''),
                        (string)strip_tags((string)($item['description'] ?? '')),
                        (string)($item['store_name'] ?? ''),
                        implode(' ', array_filter(array_map('strval', (array)($item['sizes'] ?? [])))),
                        implode(' ', array_filter(array_map('strval', (array)($item['addons'] ?? [])))),
                        implode(' ', array_filter(array_map('strval', (array)($item['weights'] ?? [])))),
                        implode(' ', array_filter(array_map('strval', (array)($item['good_for'] ?? []))))
                    ];
                    $item_search_text = strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($item_search_tokens)))));
                    $is_product_favorite = !empty($favorite_product_ids[(int)$item['id']]);
                ?>
                 <div class="menu-item <?php if ($item['stock'] <= 0) echo 'item-unavailable'; ?>"
                     data-product-id="<?php echo $item['id']; ?>"
                     data-min-price="<?php echo htmlspecialchars((string)min($item['size_prices'])); ?>"
                     data-search="<?php echo htmlspecialchars($item_search_text); ?>"
                     data-total-sold="<?php echo (int)$item['total_sold']; ?>">
<?php
    $imagePath = $item['image'] ?? '';
    $placeholderText = urlencode($item['name']);
    if (empty($imagePath)) {
        $imageSrc = 'https://via.placeholder.com/400x300?text=' . $placeholderText;
    } elseif (stripos($imagePath, 'http://') === 0 || stripos($imagePath, 'https://') === 0) {
        $imageSrc = $imagePath;
    } elseif (strpos($imagePath, '/') === false) {
        $imageSrc = 'images/menu/' . $imagePath;
    } else {
        $imageSrc = $imagePath;
    }
?>
                    <?php if ($item['stock'] <= 0): ?>
                        <div class="item-sold-out-overlay">
                            <span>Sold Out</span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Top Image Banner -->
                    <div class="item-image">
                        <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" 
                             onerror="this.src='https://via.placeholder.com/400x300?text=<?php echo $placeholderText; ?>'">
                        <button
                            type="button"
                            class="product-favorite-btn<?php echo $is_product_favorite ? ' is-active' : ''; ?>"
                            data-favorite-toggle="1"
                            data-favorite-type="product"
                            data-favorite-product-id="<?php echo (int)$item['id']; ?>"
                            data-favorite-active="<?php echo $is_product_favorite ? '1' : '0'; ?>"
                            aria-pressed="<?php echo $is_product_favorite ? 'true' : 'false'; ?>"
                            title="<?php echo $is_product_favorite ? 'Remove from favorites' : 'Save to favorites'; ?>">
                            <i class="<?php echo $is_product_favorite ? 'fas' : 'far'; ?> fa-heart"></i>
                        </button>
                        <?php if (($item['total_sold'] ?? 0) > 0): ?>
                        <span class="top-seller-badge"><i class="fas fa-fire"></i> Top Seller</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Card Body Content -->
                    <div class="item-content">
                        <?php 
                        $is_whole_roast = stripos($item['name'], 'whole') !== false || stripos($item['category'], 'whole') !== false || stripos($item['name'], 'lechon baka') !== false;
                        if ($is_whole_roast): 
                        ?>
                            <div style="margin-bottom: 6px;">
                                <span style="display:inline-flex; align-items:center; gap:4px; font-size:0.7rem; font-weight:800; color:#b54708; background:#fffaeb; border:1px solid #fedf89; padding:2px 8px; border-radius:6px;">
                                    <i class="fas fa-clock"></i> 4–6 hrs Roasting • Advance Order
                                </span>
                            </div>
                        <?php endif; ?>
                        <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                        
                        <div class="item-rating">
                            <?php
                            $rating = floatval($item['avg_rating']);
                            $review_count = intval($item['review_count']);
                            for ($i = 1; $i <= 5; $i++) {
                                if ($i <= $rating) {
                                    echo '<i class="fas fa-star"></i>';
                                } elseif ($i - 0.5 <= $rating) {
                                    echo '<i class="fas fa-star-half-alt"></i>';
                                } else {
                                    echo '<i class="far fa-star"></i>';
                                }
                            }
                            ?>
                            <span class="review-count">(<?php echo $review_count; ?>)</span>
                        </div>
                        
                        <p class="item-description"><?php echo htmlspecialchars($item['description']); ?></p>
                        
                        <!-- Bottom Action Bar: Price Pill + (+) Plus Button -->
                        <div class="item-card-bottom">
                            <div class="panda-price-pill">
                                PHP <?php echo number_format(min($item['size_prices']), 2); ?>
                            </div>
                            
                            <button type="button" class="panda-quick-add-btn view-details-btn add-to-cart"
                                    data-id="<?php echo $item['id']; ?>"
                                    data-product-id="<?php echo $item['product_id']; ?>"
                                    data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                    data-description="<?php echo htmlspecialchars($item['description']); ?>"
                                    data-image="<?php echo htmlspecialchars($imageSrc); ?>"
                                    data-sizes='<?php echo json_encode($item['sizes']); ?>'
                                    data-weights='<?php echo json_encode($item['weights']); ?>'
                                    data-good-for='<?php echo json_encode($item['good_for']); ?>'
                                    data-size-prices='<?php echo json_encode($item['size_prices']); ?>'
                                    data-addons='<?php echo json_encode($item['addons']); ?>'
                                    data-avg-rating='<?php echo $item['avg_rating']; ?>'
                                    data-stock="<?php echo $item['stock']; ?>"
                                    <?php if ($item['stock'] <= 0) echo 'disabled'; ?>
                                    title="View details / Add to cart">
                                <i class="fas fa-<?php echo ($item['stock'] <= 0) ? 'ban' : 'plus'; ?>"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

            </div>

            <aside class="menu-side-column">
                <div class="menu-side-stack">
                    <section class="quick-order-panel" id="quickOrderPanel">
                        <div class="quick-order-tabs" role="tablist" aria-label="Order option">
                            <button type="button"
                                    class="quick-order-tab<?php echo ($_SESSION['delivery_option'] ?? 'pickup') === 'delivery' ? ' active' : ''; ?>"
                                    data-delivery-option="delivery"
                                    onclick="switchDeliveryOptionTab('delivery')">
                                Delivery
                            </button>
                            <button type="button"
                                    class="quick-order-tab<?php echo ($_SESSION['delivery_option'] ?? 'pickup') === 'pickup' ? ' active' : ''; ?>"
                                    data-delivery-option="pickup"
                                    onclick="switchDeliveryOptionTab('pickup')">
                                Pick-up
                            </button>
                        </div>

                        <div class="quick-order-hero">
                            <div class="quick-order-hero-icon">
                                <i class="fas fa-bag-shopping"></i>
                            </div>
                            <h3>Free delivery on your first order</h3>
                            <p id="quickOrderHint">Add items to unlock free delivery</p>
                        </div>

                        <div class="quick-order-summary">
                            <p class="quick-order-meta" id="quickOrderMeta">Pick-up selected</p>
                            <div class="quick-order-items" id="quickOrderItems" aria-live="polite">
                                <p class="quick-order-items-empty">No items in cart yet.</p>
                            </div>
                            <div class="summary-row">
                                <span>Total <small>(incl. fees and tax)</small></span>
                                <strong id="quickOrderTotal">&#8369;0.00</strong>
                            </div>
                            <button type="button" class="quick-order-link" id="quickSeeSummaryBtn">See summary</button>
                        </div>

                        <button type="button" class="quick-order-checkout" id="quickCheckoutBtn" disabled>
                            Review payment and address
                        </button>
                    </section>
                </div>
            </aside>
        </div>
    </div>
</section>

<!-- Cart Sidebar -->
<div class="cart-overlay" id="cartOverlay"></div>
<div class="cart-sidebar" id="cartSidebar">
    <div class="cart-header" style="display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; border-bottom: 1px solid #efddcd; background: #ffffff;">
        <button class="cart-close" id="cartClose" style="background: none; border: none; font-size: 1.6rem; color: #171922; cursor: pointer; padding: 0; display: inline-flex; align-items: center; justify-content: center; margin: 0; line-height: 1;">&times;</button>
        <div style="text-align: center; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; margin-right: 24px;">
            <span style="font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1.15rem; color: #171922; line-height: 1.2;">Basket</span>
            <small style="font-size: 0.72rem; color: #667085; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; margin-top: 2px;"><i class="far fa-clock"></i> Delivery time: 30-40 min</small>
        </div>
    </div>
    
    <div class="cart-body" id="cartBody" style="padding: 24px; overflow-y: auto; flex: 1;">
        <!-- Store Name Header in Drawer -->
        <div class="cart-store-header" style="margin-bottom: 20px; text-align: left;">
            <h4 style="font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1.25rem; color: #171922; margin: 0;"><?php echo htmlspecialchars($store_display_name); ?></h4>
        </div>

        <div class="cart-empty" id="cartEmpty">
            <i class="fas fa-shopping-cart"></i>
            <p>Your cart is empty</p>
            <a href="#menu" class="btn-primary">Browse Menu</a>
        </div>
        
        <div class="cart-items" id="cartItems" style="display: none;">
            <!-- Cart items will be dynamically added here -->
        </div>
    </div>
    
    <div class="cart-footer" id="cartFooter" style="display: none; padding: 24px; border-top: 1px solid #efddcd; background: #ffffff; margin-top: auto;">
        <div class="cart-summary" style="margin-bottom: 20px;">
            <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem; color: #667085; font-weight: 600;">
                <span>Subtotal</span>
                <span id="cartSubtotal" style="color: #171922; font-weight: 700;">PHP 0.00</span>
            </div>
            <div style="font-size: 0.82rem; color: #7b6d64; text-align: left; line-height: 1.4; margin-bottom: 16px;">
                Delivery Fee will be shown after you review order
            </div>
            <div class="summary-row total" style="display: flex; justify-content: space-between; border-top: 1px solid #efddcd; padding-top: 16px; font-size: 1.15rem; font-weight: 800; color: #171922;">
                <span style="font-family: 'Outfit', sans-serif;">Total</span>
                <span id="cartTotal">PHP 0.00</span>
            </div>
        </div>
        
        <div class="cart-actions" style="display: flex; flex-direction: column; gap: 8px;">
            <button class="btn-primary" id="checkoutBtn" <?php echo empty($_SESSION['cart']) ? 'disabled' : ''; ?> style="width: 100% !important; background: #b3261e !important; color: #fff !important; border: none !important; border-radius: 8px !important; padding: 14px !important; font-weight: 700 !important; font-size: 1rem !important; cursor: pointer !important; text-align: center !important;">
                Review Order
            </button>
            <button class="btn-secondary" id="clearCart" style="background: none; border: none; color: #7b6d64; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 8px 0; margin: 0 auto; display: block;">Clear Cart</button>
        </div>
    </div>
</div>

<!-- Mobile Compact Order Bar -->
<div class="mobile-quick-order" id="mobileQuickOrder" aria-label="Quick order controls">
    <div class="mobile-quick-order-inner">
        <div class="mobile-quick-order-top">
            <div class="mobile-quick-order-tabs" role="tablist" aria-label="Mobile order option">
                <button type="button"
                        class="mobile-quick-order-tab<?php echo ($_SESSION['delivery_option'] ?? 'pickup') === 'delivery' ? ' active' : ''; ?>"
                        data-delivery-option="delivery"
                        onclick="switchDeliveryOptionTab('delivery')">
                    Delivery
                </button>
                <button type="button"
                        class="mobile-quick-order-tab<?php echo ($_SESSION['delivery_option'] ?? 'pickup') === 'pickup' ? ' active' : ''; ?>"
                        data-delivery-option="pickup"
                        onclick="switchDeliveryOptionTab('pickup')">
                    Pick-up
                </button>
            </div>
            <button type="button" class="mobile-quick-order-link" id="mobileSeeSummaryBtn">Summary</button>
        </div>
        <div class="mobile-quick-order-bottom">
            <div class="mobile-quick-order-meta-wrap">
                <p class="mobile-quick-order-meta" id="mobileQuickOrderMeta">Pick-up selected</p>
                <strong class="mobile-quick-order-total" id="mobileQuickOrderTotal">&#8369;0.00</strong>
            </div>
            <button type="button" class="mobile-quick-order-checkout" id="mobileQuickCheckoutBtn" disabled>Checkout</button>
        </div>
    </div>
</div>

<!-- Add SweetAlert2 Library -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
function claimStoreVoucher(code, btn) {
    if (!code) return;
    const textToCopy = String(code).trim();
    try {
        sessionStorage.setItem('pending_welcome_voucher', textToCopy);
    } catch(e) {}

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textToCopy).catch(() => {});
    }

    if (btn) {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Applied!';
        btn.style.background = '#ecfdf3';
        btn.style.color = '#027a48';
        btn.style.borderColor = '#abefc6';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.style.background = '#ffffff';
            btn.style.color = '#344054';
            btn.style.borderColor = '#d0d5dd';
        }, 2500);
    }

    if (typeof Swal !== 'undefined') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
        Toast.fire({
            icon: 'success',
            title: 'Store Voucher ' + textToCopy + ' activated! It will apply to this shop at checkout.'
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Product Preview Modal
    const productPreviewModal = document.getElementById('productPreviewModal');
    const previewOverlay = document.getElementById('previewOverlay');
    const previewClose = document.getElementById('previewClose');
    const addToCartConfirm = document.getElementById('addToCartConfirm');
    
    const menuSortFilter = document.getElementById('menuSortFilter');
    const menuSearchInput = document.getElementById('menuSearchInput');
    const menuSearchResultInfo = document.getElementById('menuSearchResultInfo');
    const useNearMeStoreBtn = document.getElementById('useNearMeStore');
    const nearMeStatus = document.getElementById('nearMeStatus');
    const currentUserAddress = <?php echo json_encode($current_user_address); ?>;
    const storeLocations = <?php echo json_encode($store_locations); ?>;
    const defaultPickupLocation = <?php echo (int)$default_pickup_location; ?>;
    const quickOrderTabs = Array.from(document.querySelectorAll('.quick-order-tab'));
    const quickOrderHint = document.getElementById('quickOrderHint');
    const quickOrderMeta = document.getElementById('quickOrderMeta');
    const quickOrderItems = document.getElementById('quickOrderItems');
    const quickOrderTotal = document.getElementById('quickOrderTotal');
    const quickCheckoutBtn = document.getElementById('quickCheckoutBtn');
    const quickSeeSummaryBtn = document.getElementById('quickSeeSummaryBtn');
    const mobileQuickOrderTabs = Array.from(document.querySelectorAll('.mobile-quick-order-tab'));
    const mobileQuickOrderMeta = document.getElementById('mobileQuickOrderMeta');
    const mobileQuickOrderTotal = document.getElementById('mobileQuickOrderTotal');
    const mobileQuickCheckoutBtn = document.getElementById('mobileQuickCheckoutBtn');
    const mobileSeeSummaryBtn = document.getElementById('mobileSeeSummaryBtn');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const categoryNav = document.querySelector('.category-nav, .panda-menu-sticky-bar');
    const catStripWrap = document.getElementById('pandaCatStripWrap');
    const catScrollLeft = document.getElementById('pandaCatScrollLeft');
    const catScrollRight = document.getElementById('pandaCatScrollRight');

    if (catScrollLeft && catStripWrap) {
        catScrollLeft.addEventListener('click', function() {
            catStripWrap.scrollBy({ left: -240, behavior: 'smooth' });
        });
    }
    if (catScrollRight && catStripWrap) {
        catScrollRight.addEventListener('click', function() {
            catStripWrap.scrollBy({ left: 240, behavior: 'smooth' });
        });
    }

    // Category Tabs Active Line Transfer & Scroll Spy
    const pandaCatTabs = Array.from(document.querySelectorAll('.panda-cat-tab'));
    const menuCategorySections = Array.from(document.querySelectorAll('.menu-category'));

    function setPandaCatTabActive(targetTab) {
        if (!targetTab) return;
        pandaCatTabs.forEach(function(tab) {
            tab.classList.remove('active');
        });
        targetTab.classList.add('active');

        if (catStripWrap) {
            const wrapRect = catStripWrap.getBoundingClientRect();
            const tabRect = targetTab.getBoundingClientRect();
            if (tabRect.left < wrapRect.left || tabRect.right > wrapRect.right) {
                const scrollLeft = targetTab.offsetLeft - (wrapRect.width / 2) + (tabRect.width / 2);
                catStripWrap.scrollTo({ left: scrollLeft, behavior: 'smooth' });
            }
        }
    }

    pandaCatTabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const catSlug = this.getAttribute('data-category');
            const targetSection = document.getElementById(catSlug);

            setPandaCatTabActive(this);

            if (targetSection) {
                const headerOffset = (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--site-header-offset')) || 92) + 75;
                const elementPosition = targetSection.getBoundingClientRect().top + window.pageYOffset;
                const offsetPosition = elementPosition - headerOffset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        });
    });

    window.addEventListener('scroll', function() {
        if (menuCategorySections.length === 0) return;
        
        const headerOffset = (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--site-header-offset')) || 92) + 110;
        const scrollPosition = window.pageYOffset + headerOffset;

        let currentCategorySection = null;
        for (let i = menuCategorySections.length - 1; i >= 0; i--) {
            const section = menuCategorySections[i];
            if (section.style.display === 'none') continue;
            if (section.offsetTop <= scrollPosition) {
                currentCategorySection = section;
                break;
            }
        }

        if (!currentCategorySection && menuCategorySections.length > 0) {
            currentCategorySection = menuCategorySections.find(function(s) { return s.style.display !== 'none'; });
        }

        if (currentCategorySection) {
            const catSlug = currentCategorySection.getAttribute('id');
            const matchingTab = pandaCatTabs.find(function(t) { return t.getAttribute('data-category') === catSlug; });
            if (matchingTab && !matchingTab.classList.contains('active')) {
                setPandaCatTabActive(matchingTab);
            }
        }
    }, { passive: true });

    let menuSearchQuery = '';

    const menuUrlParams = new URLSearchParams(window.location.search);
    const initialMenuSearch = (menuUrlParams.get('search') || '').trim();
    const initialMenuFilter = (menuUrlParams.get('filter') || 'default').trim().toLowerCase();
    const allowedMenuFilters = new Set(['default', 'lowest_price', 'highest_price', 'best_top_seller', 'near_me']);
    const initialProductId = (menuUrlParams.get('product_id') || '').trim();
    let currentProduct = null;

    if (initialProductId) {
        setTimeout(function() {
            const targetBtn = document.querySelector(`.add-to-cart[data-id="${initialProductId}"], .add-to-cart[data-product-id="${initialProductId}"]`);
            if (targetBtn) {
                targetBtn.click();
            }
        }, 300);
    }

    function toRadians(value) {
        return value * (Math.PI / 180);
    }

    function calculateDistanceKm(lat1, lon1, lat2, lon2) {
        const earthRadiusKm = 6371;
        const dLat = toRadians(lat2 - lat1);
        const dLon = toRadians(lon2 - lon1);
        const a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return earthRadiusKm * c;
    }

    function setNearMeStatus(message, isError = false) {
        if (!nearMeStatus) return;
        nearMeStatus.textContent = message;
        nearMeStatus.classList.toggle('error', isError);
    }

    function syncMenuSideStackOffset() {
        if (!categoryNav) return;
        const navHeight = categoryNav.offsetHeight || 0;
        if (navHeight > 0) {
            document.documentElement.style.setProperty('--menu-category-nav-height', `${navHeight}px`);
        }
        const rootStyles = getComputedStyle(document.documentElement);
        const headerOffset = parseFloat(rootStyles.getPropertyValue('--site-header-offset')) || 72;
        const resolvedTop = headerOffset + navHeight + 14;
        document.documentElement.style.setProperty('--menu-side-stack-top', `${resolvedTop}px`);
    }

    function normalizeMenuSearch(value) {
        return (value || '').toString().toLowerCase().trim().replace(/\s+/g, ' ');
    }

    function formatCurrency(value) {
        const amount = Number(value || 0);
        return `\u20B1${amount.toFixed(2)}`;
    }

    function syncCartCountBadges(count) {
        const normalizedCount = Math.max(0, Number(count || 0));
        ['cartBadge', 'floatingCartBadge'].forEach((id) => {
            const badge = document.getElementById(id);
            if (badge) {
                badge.textContent = String(normalizedCount);
            }
        });
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function resolveCartItemImageFallback(item) {
        const itemName = String(item?.name || 'Lechon');
        return `https://via.placeholder.com/80x80?text=${encodeURIComponent(itemName)}`;
    }

    function resolveCartItemImageSource(item) {
        const rawImage = String(item?.image || '').trim();
        if (rawImage === '') {
            return resolveCartItemImageFallback(item);
        }
        if (/^https?:\/\//i.test(rawImage)) {
            return rawImage;
        }
        if (rawImage.includes('/')) {
            return rawImage;
        }
        return `images/menu/${rawImage}`;
    }

    function setQuickOrderTabsActive(option) {
        document.querySelectorAll('.quick-order-tab, .mobile-quick-order-tab').forEach((tab) => {
            tab.classList.toggle('active', tab.dataset.deliveryOption === option);
        });
    }

    window.switchDeliveryOptionTab = async function(option) {
        const normalizedOption = option === 'delivery' ? 'delivery' : 'pickup';
        setQuickOrderTabsActive(normalizedOption);
        try {
            await setDeliveryOption(normalizedOption);
        } catch (error) {
            console.error('Failed to update delivery option:', error);
        }
    };

    function updateQuickOrderSummary(data) {
        if (!quickOrderTotal && !mobileQuickOrderTotal) return;

        const safeData = (data && typeof data === 'object') ? data : {};
        const subtotal = Number(safeData.subtotal || 0);
        const deliveryOption = (safeData.delivery_option || 'pickup').toLowerCase();
        const deliveryFee = deliveryOption === 'delivery' ? Number(safeData.delivery_fee || 0) : 0;
        const itemCount = Number(safeData.cart_count || 0);
        const items = Array.isArray(safeData.items) ? safeData.items : [];
        const finalTotal = subtotal + deliveryFee;

        if (quickOrderTotal) {
            quickOrderTotal.textContent = formatCurrency(finalTotal);
        }
        if (mobileQuickOrderTotal) {
            mobileQuickOrderTotal.textContent = formatCurrency(finalTotal);
        }

        if (quickOrderMeta) {
            const optionLabel = deliveryOption === 'delivery' ? 'Delivery' : 'Pick-up';
            quickOrderMeta.textContent = itemCount > 0 ? `${optionLabel} selected - ${itemCount} item${itemCount === 1 ? '' : 's'}` : `${optionLabel} selected`;
            if (mobileQuickOrderMeta) {
                mobileQuickOrderMeta.textContent = quickOrderMeta.textContent;
            }
        } else if (mobileQuickOrderMeta) {
            const optionLabel = deliveryOption === 'delivery' ? 'Delivery' : 'Pick-up';
            mobileQuickOrderMeta.textContent = itemCount > 0 ? `${optionLabel} selected - ${itemCount} item${itemCount === 1 ? '' : 's'}` : `${optionLabel} selected`;
        }

        if (quickOrderHint) {
            if (itemCount === 0) {
                quickOrderHint.textContent = 'Add items to unlock free delivery';
            } else if (deliveryOption === 'delivery' && deliveryFee > 0) {
                quickOrderHint.textContent = `Estimated delivery fee: ${formatCurrency(deliveryFee)}`;
            } else if (deliveryOption === 'delivery') {
                quickOrderHint.textContent = 'Delivery selected. Confirm your address before checkout.';
            } else {
                quickOrderHint.textContent = 'Pick-up selected. No delivery fee added.';
            }
        }

        if (quickOrderItems) {
            if (items.length <= 0) {
                quickOrderItems.innerHTML = '<p class="quick-order-items-empty">No items in cart yet.</p>';
            } else {
                quickOrderItems.innerHTML = items.map((item) => {
                    const itemName = escapeHtml(item.name || 'Menu item');
                    const itemQty = Number(item.quantity || 0);
                    const itemPrice = Number(item.price || 0);
                    const lineTotal = itemPrice * itemQty;
                    const imageSrc = escapeHtml(resolveCartItemImageSource(item));
                    const imageFallback = escapeHtml(resolveCartItemImageFallback(item));
                    const sizeText = (item.size && item.size !== 'Regular') ? `Size: ${escapeHtml(item.size)}` : '';
                    const addons = Array.isArray(item.addons) ? item.addons.filter(Boolean) : [];
                    const addonsText = addons.length > 0 ? `Add-ons: ${escapeHtml(addons.join(', '))}` : '';
                    const detailText = [sizeText, addonsText].filter(Boolean).join(' | ');

                    return `
                        <div class="quick-order-item">
                            <div class="quick-order-item-info">
                                <div class="quick-order-item-thumb">
                                    <img src="${imageSrc}" alt="${itemName}" loading="lazy" onerror="this.onerror=null;this.src='${imageFallback}'">
                                </div>
                                <div class="quick-order-item-main">
                                    <strong>${itemName}</strong>
                                    ${detailText ? `<span>${detailText}</span>` : ''}
                                </div>
                            </div>
                            <div class="quick-order-item-meta">
                                <span>x${itemQty}</span>
                                <strong>${formatCurrency(lineTotal)}</strong>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        if (quickCheckoutBtn) {
            quickCheckoutBtn.disabled = itemCount <= 0;
        }
        if (mobileQuickCheckoutBtn) {
            mobileQuickCheckoutBtn.disabled = itemCount <= 0;
        }

        setQuickOrderTabsActive(deliveryOption);
    }

    async function setDeliveryOption(option) {
        const normalizedOption = option === 'delivery' ? 'delivery' : 'pickup';
        setQuickOrderTabsActive(normalizedOption);

        const payload = new URLSearchParams({ delivery_option: normalizedOption });
        if (normalizedOption === 'pickup') {
            const selectedPickupStoreId = document.querySelector('input[name="pickup_location"]:checked')?.value
                || (typeof defaultPickupLocation !== 'undefined' ? defaultPickupLocation : 1);
            payload.set('pickup_location', String(selectedPickupStoreId));
        }

        try {
            const response = await fetch('update_delivery_option.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: payload
            });

            const result = await response.json();
            if (result.success && typeof updateCartSidebar === 'function') {
                await updateCartSidebar();
            }
        } catch (error) {
            console.error('Delivery option update failed:', error);
        }
    }

    async function handleCheckoutStart() {
        if (window.cartData && Number(window.cartData.cart_count || 0) > 0) {
            const activeDeliveryOption =
                document.querySelector('.quick-order-tab.active')?.dataset.deliveryOption
                || document.querySelector('.mobile-quick-order-tab.active')?.dataset.deliveryOption
                || (window.cartData?.delivery_option || 'pickup');
            try {
                await setDeliveryOption(activeDeliveryOption);
            } catch (error) {
                console.error('Unable to sync delivery option before checkout:', error);
            }
            window.location.href = 'checkout.php';
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Cart is empty',
            text: 'Please add items to your cart before checkout.',
            confirmButtonColor: '#b3261e'
        });
    }

    function openDeliveryModal() {
        const isMobileViewport = window.matchMedia('(max-width: 768px)').matches;
        const quickOrderPanel = isMobileViewport ? document.getElementById('mobileQuickOrder') : document.getElementById('quickOrderPanel');
        if (quickOrderPanel) {
            quickOrderPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function selectStoreById(storeId) {
        const radio = document.querySelector(`input[name="pickup_location"][value="${storeId}"]`);
        if (radio) {
            radio.checked = true;
            return true;
        }
        return false;
    }

    function findNearestStoreFromCoordinates(lat, lng) {
        let nearestStore = null;
        let nearestDistance = Number.POSITIVE_INFINITY;

        storeLocations.forEach((store) => {
            if (store.latitude === null || store.longitude === null || typeof store.latitude === 'undefined' || typeof store.longitude === 'undefined') {
                return;
            }

            const distance = calculateDistanceKm(lat, lng, parseFloat(store.latitude), parseFloat(store.longitude));
            if (distance < nearestDistance) {
                nearestDistance = distance;
                nearestStore = store;
            }
        });

        return { nearestStore, nearestDistance };
    }

    function inferNearestStoreFromAddress(addressText) {
        if (!addressText) return null;

        const normalized = addressText.toLowerCase();
        const matchedStore = storeLocations.find((store) => {
            const city = (store.city || '').toLowerCase();
            const province = (store.province || '').toLowerCase();
            return (city && normalized.includes(city)) || (province && normalized.includes(province));
        });

        return matchedStore || null;
    }

    function getBrowserPosition() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation not supported'));
                return;
            }

            navigator.geolocation.getCurrentPosition(resolve, reject, {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 300000
            });
        });
    }

    async function applyNearMeStoreSelection() {
        setNearMeStatus('Finding nearest store...');

        try {
            const position = await getBrowserPosition();
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const { nearestStore, nearestDistance } = findNearestStoreFromCoordinates(lat, lng);

            if (nearestStore && selectStoreById(nearestStore.id)) {
                setNearMeStatus(`Nearest branch selected: ${nearestStore.name} (${nearestDistance.toFixed(1)} km)`);
                return true;
            }
        } catch (err) {
            const fallbackStore = inferNearestStoreFromAddress(currentUserAddress);
            if (fallbackStore && selectStoreById(fallbackStore.id)) {
                setNearMeStatus(`Using your saved address, nearest branch selected: ${fallbackStore.name}`);
                return true;
            }
        }

        setNearMeStatus('Unable to detect nearest branch. Please select a pickup store manually.', true);
        return false;
    }

    function updateCategoryVisibility() {
        document.querySelectorAll('.menu-category').forEach((section) => {
            const items = Array.from(section.querySelectorAll('.menu-item'));
            const hasVisibleItems = items.some((item) => item.style.display !== 'none');
            section.style.display = hasVisibleItems ? '' : 'none';

            const sectionId = section.getAttribute('id');
            const navLink = document.querySelector(`.category-link[href="#${sectionId}"]`);
            if (navLink) {
                navLink.style.display = hasVisibleItems ? '' : 'none';
            }
        });
    }

    const trackedProductViews = new Set();
    let productViewTrackingTimer = null;

    function getVisibleProductIds() {
        const ids = [];
        document.querySelectorAll('.menu-item').forEach((item) => {
            if (item.style.display === 'none') return;
            const productId = parseInt(item.getAttribute('data-product-id') || '0', 10);
            if (productId > 0) {
                ids.push(productId);
            }
        });
        return Array.from(new Set(ids));
    }

    async function trackVisibleProductViews() {
        const visibleIds = getVisibleProductIds().filter((id) => !trackedProductViews.has(id));
        if (visibleIds.length === 0) return;

        visibleIds.forEach((id) => trackedProductViews.add(id));

        try {
            await fetch('api/track_product_views.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ product_ids: visibleIds })
            });
        } catch (error) {
            console.error('Unable to track product views:', error);
        }
    }

    function scheduleProductViewTracking() {
        if (productViewTrackingTimer) {
            clearTimeout(productViewTrackingTimer);
        }
        productViewTrackingTimer = setTimeout(trackVisibleProductViews, 250);
    }

    function applyMenuFilter(mode) {
        let totalVisibleItems = 0;
        document.querySelectorAll('.menu-items-grid').forEach((grid) => {
            const items = Array.from(grid.querySelectorAll('.menu-item'));

            items.forEach((item) => {
                const haystack = normalizeMenuSearch(item.dataset.search || item.textContent || '');
                const matchesQuery = menuSearchQuery === '' || haystack.includes(menuSearchQuery);
                item.style.display = matchesQuery ? '' : 'none';
            });

            if (mode === 'best_top_seller') {
                items.forEach((item) => {
                    if (item.style.display === 'none') return;
                    const totalSold = parseInt(item.dataset.totalSold || '0', 10);
                    if (totalSold <= 0) {
                        item.style.display = 'none';
                    }
                });

                const visibleItems = items.filter((item) => item.style.display !== 'none');
                totalVisibleItems += visibleItems.length;
                visibleItems.sort((a, b) => {
                    return parseInt(b.dataset.totalSold || '0', 10) - parseInt(a.dataset.totalSold || '0', 10);
                }).forEach((item) => grid.appendChild(item));
            } else {
                const sortedItems = [...items];

                if (mode === 'lowest_price') {
                    sortedItems.sort((a, b) => parseFloat(a.dataset.minPrice || '0') - parseFloat(b.dataset.minPrice || '0'));
                } else if (mode === 'highest_price') {
                    sortedItems.sort((a, b) => parseFloat(b.dataset.minPrice || '0') - parseFloat(a.dataset.minPrice || '0'));
                } else {
                    sortedItems.sort((a, b) => parseInt(a.dataset.defaultIndex || '0', 10) - parseInt(b.dataset.defaultIndex || '0', 10));
                }

                sortedItems.forEach((item) => grid.appendChild(item));
                totalVisibleItems += items.filter((item) => item.style.display !== 'none').length;
            }
        });

        updateCategoryVisibility();
        if (menuSearchResultInfo) {
            if (menuSearchQuery === '' && (mode || 'default') === 'default') {
                menuSearchResultInfo.style.display = 'none';
                menuSearchResultInfo.textContent = '';
            } else {
                menuSearchResultInfo.style.display = '';
                const modeLabel = mode === 'best_top_seller'
                    ? 'Top Seller'
                    : (mode === 'lowest_price'
                        ? 'Lowest Price'
                        : (mode === 'highest_price' ? 'Highest Price' : 'Default'));
                menuSearchResultInfo.textContent = `${totalVisibleItems} item${totalVisibleItems === 1 ? '' : 's'} found` +
                    (menuSearchQuery ? ` for "${menuSearchInput ? menuSearchInput.value.trim() : ''}"` : '') +
                    ` | Filter: ${modeLabel}`;
            }
        }

        scheduleProductViewTracking();
    }

    if (menuSortFilter) {
        menuSortFilter.addEventListener('change', async function() {
            const selected = this.value;

            if (selected === 'near_me') {
                applyMenuFilter('default');
                const pickupOptionCard = document.querySelector('.delivery-option[data-option="pickup"]');
                const deliveryOptionCard = document.querySelector('.delivery-option[data-option="delivery"]');
                if (deliveryOptionCard) deliveryOptionCard.classList.remove('active');
                if (pickupOptionCard) pickupOptionCard.classList.add('active');
                await applyNearMeStoreSelection();
                openDeliveryModal();
                return;
            }

            applyMenuFilter(selected);
        });
    }

    if (menuSearchInput) {
        menuSearchInput.addEventListener('input', function () {
            menuSearchQuery = normalizeMenuSearch(this.value);
            applyMenuFilter(menuSortFilter ? menuSortFilter.value : 'default');
        });
    }

    if (menuSearchInput && initialMenuSearch !== '') {
        menuSearchInput.value = initialMenuSearch;
        menuSearchQuery = normalizeMenuSearch(initialMenuSearch);
    }
    if (menuSortFilter && allowedMenuFilters.has(initialMenuFilter)) {
        menuSortFilter.value = initialMenuFilter;
    }
    applyMenuFilter(menuSortFilter ? menuSortFilter.value : 'default');

    let menuSideStackSyncFrame = null;
    function scheduleMenuSideStackSync() {
        if (menuSideStackSyncFrame !== null) return;
        menuSideStackSyncFrame = window.requestAnimationFrame(() => {
            menuSideStackSyncFrame = null;
            syncMenuSideStackOffset();
        });
    }

    syncMenuSideStackOffset();
    window.addEventListener('resize', scheduleMenuSideStackSync);
    window.addEventListener('load', scheduleMenuSideStackSync);
    setTimeout(scheduleMenuSideStackSync, 120);

    document.addEventListener('click', function(e) {
        const tabBtn = e.target.closest('.quick-order-tab, .mobile-quick-order-tab');
        if (tabBtn) {
            const selectedOption = tabBtn.dataset.deliveryOption === 'delivery' ? 'delivery' : 'pickup';
            setDeliveryOption(selectedOption);
        }
    });

    if (quickSeeSummaryBtn) {
        quickSeeSummaryBtn.addEventListener('click', openCartSidebar);
    }
    if (mobileSeeSummaryBtn) {
        mobileSeeSummaryBtn.addEventListener('click', openCartSidebar);
    }

    if (quickCheckoutBtn) {
        quickCheckoutBtn.addEventListener('click', handleCheckoutStart);
    }
    if (mobileQuickCheckoutBtn) {
        mobileQuickCheckoutBtn.addEventListener('click', handleCheckoutStart);
    }

    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', handleCheckoutStart);
    }

// View product details
document.addEventListener('click', function(e) {
    if (e.target.closest('.view-details-btn')) {
        const button = e.target.closest('.view-details-btn');
        currentProduct = {
            id: button.getAttribute('data-id'),
            product_id: button.getAttribute('data-product-id'), // String product_id
            name: button.getAttribute('data-name'),
            description: button.getAttribute('data-description'),
            image: button.getAttribute('data-image'),
            sizes: JSON.parse(button.getAttribute('data-sizes')),
            weights: JSON.parse(button.getAttribute('data-weights')),
            goodFor: JSON.parse(button.getAttribute('data-good-for')),
            sizePrices: JSON.parse(button.getAttribute('data-size-prices')),
            addons: JSON.parse(button.getAttribute('data-addons')),
            stock: parseInt(button.getAttribute('data-stock'))
        };
        
        showProductPreview(currentProduct);
    }
});
    
    function showProductPreview(product) {
        // Set product image
        const img = document.getElementById('previewProductImage');
        const placeholderText = encodeURIComponent(product.name);
        const resolvedImage = product.image || `https://via.placeholder.com/400x300?text=${placeholderText}`;
        img.src = resolvedImage;
        img.onerror = function() {
            this.src = `https://via.placeholder.com/400x300?text=${placeholderText}`;
        };
        
        // Set product info
        document.getElementById('previewProductName').textContent = product.name;
        document.getElementById('previewDescription').textContent = product.description;
        document.getElementById('summaryProduct').textContent = product.name;
        
        // Update quantity input max based on stock
        const qtyInput = document.querySelector('.qty-input');
        if (qtyInput) {
            qtyInput.max = product.stock;
            qtyInput.value = 1;
            
            // Add or update stock display
            let stockDisplay = document.getElementById('previewStockDisplay');
            if (!stockDisplay) {
                stockDisplay = document.createElement('div');
                stockDisplay.id = 'previewStockDisplay';
                stockDisplay.style.fontSize = '0.85rem';
                stockDisplay.style.color = '#666';
                stockDisplay.style.marginTop = '5px';
                document.querySelector('.quantity-selection').appendChild(stockDisplay);
            }
            stockDisplay.textContent = `${product.stock} units available`;
        }
        
        // Create size buttons
        const sizeButtons = document.getElementById('previewSizeButtons');
        sizeButtons.innerHTML = '';
        
        product.sizes.forEach((size, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'size-preview-btn' + (index === 0 ? ' active' : '');
            button.textContent = size;
            button.dataset.size = size;
            button.dataset.price = product.sizePrices[size];
            
            // Add weight and pax info to button
            const weightInfo = product.weights[size] ? ` | ${product.weights[size]}` : '';
            const paxInfo = product.goodFor[size] ? ` | ${product.goodFor[size]}` : '';
            
            if (weightInfo || paxInfo) {
                const infoSpan = document.createElement('span');
                infoSpan.className = 'size-info';
                infoSpan.textContent = weightInfo + paxInfo;
                button.appendChild(infoSpan);
            }
            
            button.addEventListener('click', function() {
                document.querySelectorAll('.size-preview-btn').forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                updateOrderSummary();
            });
            
            sizeButtons.appendChild(button);
        });
        
        // Create addons checkboxes
        const addonsList = document.getElementById('previewAddonsList');
        addonsList.innerHTML = '';
        
        if (product.addons && product.addons.length > 0) {
            document.getElementById('previewAddonsSection').style.display = 'block';
            
            product.addons.forEach(addon => {
                const label = document.createElement('label');
                label.className = 'addon-checkbox';
                label.innerHTML = `
                    <input type="checkbox" value="${addon}">
                    <span>${addon}</span>
                `;
                label.querySelector('input').addEventListener('change', updateOrderSummary);
                addonsList.appendChild(label);
            });
        } else {
            document.getElementById('previewAddonsSection').style.display = 'none';
        }
        // Set initial price
        updateOrderSummary();

        // Toggle advance pre-order reservation link for whole roasts
        const isWholeRoast = (product.name && (product.name.toLowerCase().includes('whole') || product.name.toLowerCase().includes('baka'))) || (product.category && product.category.toLowerCase().includes('whole'));
        const preorderLink = document.getElementById('previewPreorderLink');
        if (preorderLink) {
            if (isWholeRoast) {
                preorderLink.style.display = 'inline-flex';
                preorderLink.href = 'preorder.php?product_id=' + encodeURIComponent(product.id || product.product_id || '');
            } else {
                preorderLink.style.display = 'none';
            }
        }
        
        // Show modal
        if (productPreviewModal) {
            productPreviewModal.style.display = 'block';
            setTimeout(() => {
                productPreviewModal.classList.add('active');
            }, 10);
        }
    }
    
    function updateOrderSummary() {
        const activeSizeBtn = document.querySelector('.size-preview-btn.active');
        if (!activeSizeBtn || !currentProduct) return;
        
        const size = activeSizeBtn.dataset.size;
        const unitPrice = parseFloat(activeSizeBtn.dataset.price);
        const quantity = parseInt(document.querySelector('.qty-input').value) || 1;
        
        // Get selected addons
        const selectedAddons = [];
        document.querySelectorAll('.addon-checkbox input:checked').forEach(checkbox => {
            selectedAddons.push(checkbox.value);
        });
        
        // Update display
        document.getElementById('previewPrice').textContent = `PHP ${unitPrice.toFixed(2)}`;
        document.getElementById('summarySize').textContent = size;
        document.getElementById('summaryAddons').textContent = selectedAddons.length > 0 ? selectedAddons.join(', ') : 'None';
        document.getElementById('summaryQuantity').textContent = quantity;
        document.getElementById('summaryUnitPrice').textContent = `PHP ${unitPrice.toFixed(2)}`;
        
        const total = unitPrice * quantity;
        document.getElementById('summaryTotal').textContent = `PHP ${total.toFixed(2)}`;
        
        // Update size weight info
        const weightInfoDiv = document.getElementById('sizeWeightInfo');
        let weightInfo = '';
        if (currentProduct.weights && currentProduct.weights[size]) {
            weightInfo += `<div class="weight-info"><i class="fas fa-weight"></i> ${currentProduct.weights[size]}</div>`;
        }
        if (currentProduct.goodFor && currentProduct.goodFor[size]) {
            weightInfo += `<div class="pax-info"><i class="fas fa-users"></i> ${currentProduct.goodFor[size]}</div>`;
        }
        if (weightInfoDiv) weightInfoDiv.innerHTML = weightInfo;
    }

    // Quantity controls in preview modal
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('qty-minus') || e.target.classList.contains('qty-plus')) {
            const input = e.target.parentElement.querySelector('.qty-input');
            let value = parseInt(input.value) || 1;
            
            if (e.target.classList.contains('qty-minus')) {
                if (value > 1) {
                    input.value = value - 1;
                }
            } else {
                const maxStock = currentProduct ? currentProduct.stock : 20;
                if (value < maxStock) {
                    input.value = value + 1;
                }
            }
            updateOrderSummary();
        }
    });

    // Close preview modal
    function closeProductPreview() {
        if (!productPreviewModal) return;
        productPreviewModal.classList.remove('active');
        setTimeout(() => {
            productPreviewModal.style.display = 'none';
        }, 300);
    }

    if (previewClose) previewClose.addEventListener('click', closeProductPreview);
    if (previewOverlay) previewOverlay.addEventListener('click', closeProductPreview);

    if (addToCartConfirm) {
        addToCartConfirm.addEventListener('click', async function() {
            if (!currentProduct) return;
            
            const activeSizeBtn = document.querySelector('.size-preview-btn.active');
            const size = activeSizeBtn ? activeSizeBtn.dataset.size : 'Regular';
            const price = activeSizeBtn ? parseFloat(activeSizeBtn.dataset.price) : (currentProduct.price || 0);
            const quantity = parseInt(document.querySelector('.qty-input').value) || 1;
            
            const selectedAddons = [];
            document.querySelectorAll('.addon-checkbox input:checked').forEach(cb => {
                selectedAddons.push(cb.value);
            });

            addToCartConfirm.disabled = true;
            addToCartConfirm.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';

            try {
                const formData = new FormData();
                formData.append('product_id', currentProduct.id || currentProduct.product_id || 0);
                formData.append('quantity', quantity);
                formData.append('size', size);
                formData.append('price', price);
                formData.append('addons', JSON.stringify(selectedAddons));

                const response = await fetch('add_to_cart.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    closeProductPreview();
                    updateCartSidebar();

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Added to Cart!',
                            text: data.message || 'Item successfully added to your cart.',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    }
                } else {
                    if (data.code === 'MIXED_TENANT_ADD_BLOCKED') {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Switch Store Order?',
                                text: data.message || 'Your cart has items from another store. Clear cart and start an order from this shop?',
                                showCancelButton: true,
                                confirmButtonColor: '#b3261e',
                                cancelButtonColor: '#6c757d',
                                confirmButtonText: '<i class="fas fa-rotate"></i> Yes, Clear &amp; Add Item',
                                cancelButtonText: 'Keep Existing Cart'
                            }).then(async (result) => {
                                if (result.isConfirmed) {
                                    formData.append('clear_and_add', '1');
                                    try {
                                        const retryRes = await fetch('add_to_cart.php', {
                                            method: 'POST',
                                            body: formData
                                        });
                                        const retryData = await retryRes.json();
                                        if (retryData.success) {
                                            closeProductPreview();
                                            updateCartSidebar();
                                            Swal.fire({
                                                icon: 'success',
                                                title: 'Switched Store!',
                                                text: 'Cart was cleared and your new item was added.',
                                                toast: true,
                                                position: 'top-end',
                                                showConfirmButton: false,
                                                timer: 2500
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'error',
                                                title: 'Cannot Add Item',
                                                text: retryData.message || 'Failed to add item.',
                                                confirmButtonColor: '#b3261e'
                                            });
                                        }
                                    } catch (e) {
                                        console.error(e);
                                    }
                                }
                            });
                        } else {
                            if (confirm(data.message + "\n\nClick OK to clear cart and add this item.")) {
                                formData.append('clear_and_add', '1');
                                fetch('add_to_cart.php', { method: 'POST', body: formData })
                                    .then(r => r.json())
                                    .then(rd => { if (rd.success) { location.reload(); } });
                            }
                        }
                    } else if (data.code === 'MIXED_TENANT_CART_EXISTING') {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Cart Contains Multiple Stores',
                                text: data.message,
                                showCancelButton: true,
                                confirmButtonColor: '#b3261e',
                                confirmButtonText: 'Go to Cart',
                                cancelButtonText: 'Close'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    openCartSidebar();
                                }
                            });
                        } else {
                            alert(data.message);
                        }
                    } else {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Cannot Add Item',
                                text: data.message || 'Failed to add item to cart.',
                                confirmButtonColor: '#b3261e'
                            });
                        } else {
                            alert(data.message);
                        }
                    }
                }
            } catch (err) {
                console.error('Add to cart error:', err);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while adding the item to cart. Please try again.',
                        confirmButtonColor: '#b3261e'
                    });
                }
            } finally {
                addToCartConfirm.disabled = false;
                addToCartConfirm.innerHTML = '<i class="fas fa-cart-plus"></i> Add to Cart';
            }
        });
    }

    // Foodpanda Storefront Reviews Modal controls
    const openStorefrontReviewsBtn = document.getElementById('openStorefrontReviewsBtn');
    const storefrontReviewsModal = document.getElementById('storefrontReviewsModal');
    const closeStorefrontReviewsModal = document.getElementById('closeStorefrontReviewsModal');
    const storefrontReviewsOverlay = document.getElementById('storefrontReviewsOverlay');

    function openStorefrontReviews() {
        if (!storefrontReviewsModal) return;
        storefrontReviewsModal.style.display = 'flex';
        setTimeout(() => {
            storefrontReviewsModal.classList.add('active');
        }, 10);
    }

    function closeStorefrontReviews() {
        if (!storefrontReviewsModal) return;
        storefrontReviewsModal.classList.remove('active');
        setTimeout(() => {
            storefrontReviewsModal.style.display = 'none';
        }, 300);
    }

    if (openStorefrontReviewsBtn) {
        openStorefrontReviewsBtn.addEventListener('click', openStorefrontReviews);
    }
    if (closeStorefrontReviewsModal) {
        closeStorefrontReviewsModal.addEventListener('click', closeStorefrontReviews);
    }
    if (storefrontReviewsOverlay) {
        storefrontReviewsOverlay.addEventListener('click', closeStorefrontReviews);
    }
    
    // Filter & Sort Storefront Reviews
    document.querySelectorAll('.store-review-filter-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.store-review-filter-btn').forEach(function(b) {
                b.classList.remove('active');
            });
            this.classList.add('active');

            const filter = this.getAttribute('data-filter');
            const container = document.getElementById('storefrontReviewsList');
            if (!container) return;

            const items = Array.from(container.querySelectorAll('.store-review-item'));
            items.sort(function(a, b) {
                const ratingA = parseInt(a.getAttribute('data-rating')) || 0;
                const ratingB = parseInt(b.getAttribute('data-rating')) || 0;
                const timeA = parseInt(a.getAttribute('data-timestamp')) || 0;
                const timeB = parseInt(b.getAttribute('data-timestamp')) || 0;
                const hasCommentA = parseInt(a.getAttribute('data-has-comment')) || 0;
                const hasCommentB = parseInt(b.getAttribute('data-has-comment')) || 0;

                if (filter === 'newest') {
                    return timeB - timeA;
                } else if (filter === 'highest') {
                    if (ratingB !== ratingA) return ratingB - ratingA;
                    return timeB - timeA;
                } else if (filter === 'lowest') {
                    if (ratingA !== ratingB) return ratingA - ratingB;
                    return timeB - timeA;
                } else {
                    if (hasCommentB !== hasCommentA) return hasCommentB - hasCommentA;
                    if (ratingB !== ratingA) return ratingB - ratingA;
                    return timeB - timeA;
                }
            });

            items.forEach(function(item) {
                container.appendChild(item);
            });
        });
    });
    
    // Cart Sidebar Functions
    const cartSidebar = document.getElementById('cartSidebar');
    const cartOverlay = document.getElementById('cartOverlay');
    const cartToggleButtons = [document.getElementById('cartToggle'), document.getElementById('floatingCartBtn')].filter(Boolean);
    const cartClose = document.getElementById('cartClose');
    
    function openCartSidebar() {
        cartSidebar.classList.add('active');
        cartOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeCartSidebar() {
        cartSidebar.classList.remove('active');
        cartOverlay.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    cartToggleButtons.forEach((button) => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            openCartSidebar();
        });
    });
    cartClose.addEventListener('click', closeCartSidebar);
    cartOverlay.addEventListener('click', closeCartSidebar);
    
    // Update cart sidebar
    async function updateCartSidebar() {
        try {
            const response = await fetch('get_cart.php');
            const data = await response.json();
            window.cartData = data;
            
            const cartItems = document.getElementById('cartItems');
            const cartEmpty = document.getElementById('cartEmpty');
            const cartFooter = document.getElementById('cartFooter');
            const cartCount = document.getElementById('cartCount');
            
            if (data.items && data.items.length > 0) {
                // Show cart items
                cartEmpty.style.display = 'none';
                cartItems.style.display = 'block';
                cartFooter.style.display = 'block';
                
                // Update cart count
                if (cartCount) {
                    cartCount.textContent = data.cart_count;
                }
                syncCartCountBadges(data.cart_count);
                
                // Clear existing items
                cartItems.innerHTML = '';
                
                // Add items to cart
                data.items.forEach((item, index) => {
                    const cartItem = document.createElement('div');
                    cartItem.className = 'cart-item';
                    cartItem.dataset.index = index;
                    const cartImageSrc = resolveCartItemImageSource(item);
                    const cartImageFallback = resolveCartItemImageFallback(item);
                    cartItem.innerHTML = `
                        <div class="cart-item-quantity" style="display: flex; align-items: center; gap: 8px; margin-right: 12px;">
                            <button class="qty-decrease" data-index="${index}" style="border: 1px solid #efddcd; background: #fff; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; color: #667085; font-weight: bold; cursor: pointer; font-size: 0.85rem; padding: 0;">-</button>
                            <span style="font-weight: 700; font-size: 0.95rem; color: #171922; min-width: 14px; text-align: center;">${item.quantity}</span>
                            <button class="qty-increase" data-index="${index}" style="border: 1px solid #efddcd; background: #fff; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; color: #667085; font-weight: bold; cursor: pointer; font-size: 0.85rem; padding: 0;">+</button>
                        </div>
                        <div class="cart-item-image" style="width: 48px; height: 48px; border-radius: 6px; overflow: hidden; flex-shrink: 0; margin-right: 12px; background-color: #f5f5f5;">
                            <img src="${escapeHtml(cartImageSrc)}" alt="${escapeHtml(item.name || 'Lechon item')}" 
                                 onerror="this.onerror=null;this.src='${escapeHtml(cartImageFallback)}'" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <div class="cart-item-details" style="flex: 1; text-align: left; padding: 0;">
                            <div class="cart-item-name" style="font-weight: 700; color: #171922; font-size: 0.92rem; margin-bottom: 2px;">${item.name}</div>
                            ${item.size !== 'Regular' ? `<div class="cart-item-size" style="font-size: 0.78rem; color: #667085; margin: 0;">Size: ${item.size}</div>` : ''}
                            ${item.addons && item.addons.length > 0 ? 
                                `<div class="cart-item-addons" style="font-size: 0.78rem; color: #667085; margin: 0;">Add-ons: ${item.addons.join(', ')}</div>` : ''}
                        </div>
                        <div class="cart-item-price-col" style="text-align: right; margin-left: 12px; font-weight: 700; color: #171922; font-size: 0.92rem; display: flex; flex-direction: column; justify-content: space-between; align-items: flex-end;">
                            <div>${formatCurrency(parseFloat(item.price) * item.quantity)}</div>
                            <button class="cart-item-remove" data-index="${index}" style="background: none; border: none; color: #7b6d64; cursor: pointer; font-size: 0.8rem; display: block; margin-top: 4px; padding: 0;" title="Remove item">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;
                    cartItems.appendChild(cartItem);
                });
                
                const deliveryFee = (data.delivery_option || 'pickup') === 'delivery' ? Number(data.delivery_fee || 0) : 0;
                const grandTotal = Number(data.subtotal || 0) + deliveryFee;

                // Update totals
                document.getElementById('cartSubtotal').textContent = formatCurrency(data.subtotal);
                document.getElementById('cartTotal').textContent = formatCurrency(grandTotal);

                // Enable checkout button
                if (checkoutBtn) {
                    checkoutBtn.disabled = false;
                }
            } else {
                // Empty cart
                cartEmpty.style.display = 'block';
                cartItems.style.display = 'none';
                cartFooter.style.display = 'none';
                
                if (cartCount) {
                    cartCount.textContent = '0';
                }
                syncCartCountBadges(0);
                
                // Disable checkout button
                if (checkoutBtn) {
                    checkoutBtn.disabled = true;
                }
            }

            updateQuickOrderSummary(data);
        } catch (error) {
            console.error('Error updating cart sidebar:', error);
        }
    }
    
    // Cart item quantity controls
    document.addEventListener('click', async function(e) {
        if (e.target.closest('.qty-decrease') || e.target.closest('.qty-increase') || e.target.closest('.cart-item-remove')) {
            const button = e.target.closest('.qty-decrease, .qty-increase, .cart-item-remove');
            const index = button.getAttribute('data-index');
            const action = button.classList.contains('qty-decrease') ? 'decrease' : 
                          button.classList.contains('qty-increase') ? 'increase' : 'remove';
            
            try {
                const response = await fetch('update_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'index': index,
                        'action': action
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    updateCartSidebar();
                    
                    // Show notification
                    Swal.fire({
                        icon: 'success',
                        title: action === 'remove' ? 'Removed!' : 'Updated!',
                        text: action === 'remove' ? 'Item removed from cart' : 'Cart updated',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500
                    });
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }
    });
    
    // Clear cart
    document.getElementById('clearCart').addEventListener('click', async function() {
        Swal.fire({
            title: 'Clear Cart?',
            text: 'Are you sure you want to clear your entire cart?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#b3261e',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, clear cart',
            cancelButtonText: 'Cancel'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    const response = await fetch('update_cart.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'action': 'clear'
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        updateCartSidebar();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Cart Cleared!',
                            text: 'Your cart has been cleared.',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1500
                        });
                    }
                } catch (error) {
                    console.error('Error:', error);
                }
            }
        });
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // closeProductPreview(); // This is handled by its own function now
            closeCartSidebar();
        }
    });
    
    
    // Cart item quantity controls
    document.addEventListener('click', async function(e) {
        if (e.target.closest('.qty-decrease') || e.target.closest('.qty-increase') || e.target.closest('.cart-item-remove')) {
            const button = e.target.closest('.qty-decrease, .qty-increase, .cart-item-remove');
            const index = button.getAttribute('data-index');
            const action = button.classList.contains('qty-decrease') ? 'decrease' : 
                          button.classList.contains('qty-increase') ? 'increase' : 'remove';
            
            try {
                const response = await fetch('update_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'index': index,
                        'action': action
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    updateCartSidebar();
                    
                    // Show notification
                    Swal.fire({
                        icon: 'success',
                        title: action === 'remove' ? 'Removed!' : 'Updated!',
                        text: action === 'remove' ? 'Item removed from cart' : 'Cart updated',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500
                    });
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }
    });
    
    // Clear cart
    document.getElementById('clearCart').addEventListener('click', async function() {
        Swal.fire({
            title: 'Clear Cart?',
            text: 'Are you sure you want to clear your entire cart?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#b3261e',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, clear cart',
            cancelButtonText: 'Cancel'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    const response = await fetch('update_cart.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'action': 'clear'
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        updateCartSidebar();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Cart Cleared!',
                            text: 'Your cart has been cleared.',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1500
                        });
                    }
                } catch (error) {
                    console.error('Error:', error);
                }
            }
        });
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // closeProductPreview(); // This is handled by its own function now
            closeCartSidebar();
        }
    });
    
    // Intercept all header cart buttons to open sidebar modal instead of redirecting to cart.php
    document.querySelectorAll('.cart-btn, #cartToggle').forEach(btn => {
        btn.removeAttribute('onclick');
        btn.setAttribute('data-no-redirect', '1');
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openCartSidebar();
        });
    });
    
    // Initialize cart sidebar
    updateCartSidebar();

    // Auto open cart modal if redirected with open_cart=1 parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_cart') === '1') {
        window.history.replaceState({}, document.title, window.location.pathname);
        setTimeout(openCartSidebar, 150);
    }
});
</script>

<style>
/* Storefront Review Filters */
.store-review-filter-btn {
    padding: 7px 16px !important;
    border-radius: 999px !important;
    background-color: #fff9f2 !important;
    color: #2a211d !important;
    font-size: 0.84rem !important;
    font-weight: 600 !important;
    border: 1px solid #efddcd !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
}

.store-review-filter-btn:hover {
    border-color: #b3261e !important;
    color: #b3261e !important;
    background-color: #fffaf3 !important;
}

.store-review-filter-btn.active {
    background-color: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
    font-weight: 700 !important;
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.2) !important;
}

/* Menu Page Styles */
:root {
    --primary-color: #b3261e;
    --primary-dark: #8f261a;
    --text-main: #2d3436;
    --text-light: #636e72;
    --bg-light: #f8f9fa;
    --card-radius: 16px;
    --motion-ease: cubic-bezier(.22, 1, .36, 1);
    --motion-fast: .22s;
    --motion-base: .28s;
    --motion-slow: .36s;
    --transition: all var(--motion-base) var(--motion-ease);
    --transition-fast: all var(--motion-fast) var(--motion-ease);
    --transition-lift: transform var(--motion-fast) var(--motion-ease), box-shadow var(--motion-fast) var(--motion-ease), border-color var(--motion-fast) var(--motion-ease), background-color var(--motion-fast) var(--motion-ease), color var(--motion-fast) var(--motion-ease);
    --transition-fade: transform var(--motion-base) var(--motion-ease), opacity var(--motion-base) var(--motion-ease);
    --shadow-sm: 0 4px 6px rgba(0,0,0,0.05);
    --shadow-md: 0 10px 20px rgba(0,0,0,0.08);
    --shadow-hover: 0 20px 40px rgba(0,0,0,0.12);
    --menu-category-nav-height: 68px;
    --menu-category-nav-gap: 14px;
    --menu-side-stack-top: calc(var(--site-header-offset, 72px) + var(--menu-category-nav-height, 58px) + 14px);
}

.page-header {
    background: #ffffff;
    border-bottom: 1px solid #eaecf0;
    color: #101828;
    text-align: center;
    padding: 36px 20px 28px;
    position: static;
    margin-bottom: 0;
}

.page-header h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 2.2rem;
    font-weight: 800;
    margin-bottom: 6px;
    color: #101828;
    text-shadow: none;
    letter-spacing: -0.02em;
}

.page-header p {
    font-size: 0.95rem;
    color: #475467;
    max-width: 600px;
    margin: 0 auto;
    font-weight: 400;
    opacity: 1;
}

/* Foodpanda Unified Sticky Category Navigation Bar */
.panda-menu-sticky-bar {
    position: sticky !important;
    top: var(--site-header-offset, 72px) !important;
    z-index: 1100 !important;
    background: #ffffff !important;
    border: 1px solid #eaecf0 !important;
    border-radius: 16px !important;
    box-shadow: 0 4px 16px rgba(16, 24, 40, 0.05) !important;
    margin: 0 0 24px 0 !important;
    padding: 10px 16px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

.panda-menu-bar-inner {
    max-width: 1320px !important;
    margin: 0 auto !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    position: relative !important;
}

.panda-menu-search-box {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #f1f5f9 !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 999px !important;
    padding: 0 14px !important;
    height: 38px !important;
    flex-shrink: 0 !important;
    min-width: 180px !important;
    max-width: 220px !important;
}

.panda-menu-search-box i {
    color: #64748b !important;
    font-size: 0.85rem !important;
}

.panda-menu-search-box input {
    border: none !important;
    background: transparent !important;
    outline: none !important;
    font-size: 0.86rem !important;
    color: #171922 !important;
    width: 100% !important;
    font-weight: 500 !important;
}

.panda-cat-arrow {
    width: 32px !important;
    height: 32px !important;
    border-radius: 50% !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #171922 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
    flex-shrink: 0 !important;
    transition: all 0.2s ease !important;
    font-size: 0.8rem !important;
}

.panda-cat-arrow:hover {
    background: #171922 !important;
    color: #ffffff !important;
    border-color: #171922 !important;
}

.panda-cat-strip-wrap {
    flex: 1 !important;
    overflow-x: auto !important;
    scroll-behavior: smooth !important;
    scrollbar-width: none !important;
}

.panda-cat-strip-wrap::-webkit-scrollbar {
    display: none !important;
}

.panda-cat-strip {
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
    white-space: nowrap !important;
}

.panda-cat-tab {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    padding: 10px 0 !important;
    color: #64748b !important;
    font-size: 0.92rem !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    border-bottom: 3px solid transparent !important;
    transition: all 0.2s ease !important;
    cursor: pointer !important;
}

.panda-cat-tab:hover {
    color: #171922 !important;
}

.panda-cat-tab.active {
    color: #171922 !important;
    font-weight: 800 !important;
    border-bottom-color: #171922 !important;
}

.panda-cat-count {
    font-size: 0.84rem !important;
    color: #64748b !important;
    font-weight: 500 !important;
}

.panda-cat-tab.active .panda-cat-count {
    color: #171922 !important;
    font-weight: 700 !important;
}

.panda-menu-filter-select-wrap {
    flex-shrink: 0 !important;
}

.panda-menu-filter-select {
    height: 38px !important;
    padding: 0 12px !important;
    border-radius: 10px !important;
    border: 1px solid #cbd5e1 !important;
    background: #ffffff !important;
    font-size: 0.84rem !important;
    font-weight: 600 !important;
    color: #171922 !important;
    cursor: pointer !important;
    outline: none !important;
}

.menu-filter-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 15px;
    margin-bottom: 30px;
}

.menu-search-field {
    min-width: min(100%, 360px);
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 0 14px;
    min-height: 44px;
    border: 1px solid #e7d1bf;
    border-radius: 999px;
    background: #fff;
}

.menu-search-field i {
    color: #7e3a2b;
    font-size: 0.9rem;
}

.menu-search-field input {
    width: 100%;
    border: none;
    background: transparent;
    outline: none;
    font-size: 0.92rem;
    color: var(--text-main);
}

.menu-filter-bar label {
    font-weight: 500;
    color: var(--text-light);
    margin: 0;
}

.menu-filter-bar select {
    min-width: 200px;
    border: 1px solid #e0e0e0;
    border-radius: 50px;
    padding: 10px 20px;
    font-size: 0.9rem;
    color: var(--text-main);
    background-color: #fff;
    cursor: pointer;
    transition: var(--transition);
    outline: none;
}

.menu-filter-bar select:hover,
.menu-filter-bar select:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}

.category-list {
    display: flex;
    justify-content: center; /* Center items */
    overflow-x: auto;
    gap: 10px;
    padding: 10px 0;
    -ms-overflow-style: none;  /* IE and Edge */
    scrollbar-width: none;  /* Firefox */
}

.category-list::-webkit-scrollbar {
    display: none;
}

.category-link {
    white-space: nowrap;
    padding: 10px 25px;
    background-color: transparent;
    color: var(--text-light);
    text-decoration: none;
    border-radius: 50px;
    font-weight: 500;
    transition: var(--transition);
    border: 1px solid transparent;
    font-size: 0.95rem;
}



.category-list {
    display: flex;
    justify-content: center; /* Center items */
    overflow-x: auto;
    gap: 10px;
    padding: 10px 0;
    -ms-overflow-style: none;  /* IE and Edge */
    scrollbar-width: none;  /* Firefox */
}

.category-list::-webkit-scrollbar {
    display: none;
}

.category-link {
    white-space: nowrap;
    padding: 10px 25px;
    background-color: transparent;
    color: var(--text-light);
    text-decoration: none;
    border-radius: 50px;
    font-weight: 500;
    transition: var(--transition);
    border: 1px solid transparent;
    font-size: 0.95rem;
}

.category-link:hover,
.category-link.active {
    background-color: var(--primary-color);
    color: white;
    box-shadow: 0 4px 10px rgba(179, 38, 30, 0.3);
}

/* Menu Categories */
.menu-category {
    margin-bottom: 60px !important;
    scroll-margin-top: 140px !important;
}

.category-title {
    font-family: 'Outfit', sans-serif !important;
    font-size: 1.8rem !important;
    font-weight: 800 !important;
    color: #171922 !important;
    margin: 0 0 20px 0 !important;
    padding: 0 !important;
    border: none !important;
    box-shadow: none !important;
    outline: none !important;
    background: transparent !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.menu-items-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)) !important;
    gap: 16px !important;
}

.menu-item {
    background: #ffffff !important;
    border: 1px solid #efddcd !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.03) !important;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
    position: relative !important;
}

.menu-item:hover {
    transform: translateY(-4px) !important;
    box-shadow: 0 10px 24px rgba(179, 38, 30, 0.08) !important;
    border-color: #e8d4c3 !important;
}

.item-image {
    position: relative !important;
    height: 140px !important;
    overflow: hidden !important;
    background: #f8fafc !important;
}

.item-image img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    transition: transform 0.35s ease !important;
}

.menu-item:hover .item-image img {
    transform: scale(1.05) !important;
}

.top-seller-badge {
    position: absolute !important;
    top: 8px !important;
    left: 8px !important;
    background: #ef6b2e !important;
    color: #ffffff !important;
    font-size: 0.68rem !important;
    font-weight: 800 !important;
    padding: 3px 8px !important;
    border-radius: 999px !important;
    box-shadow: 0 3px 8px rgba(239, 107, 46, 0.3) !important;
    z-index: 2 !important;
}

.item-content {
    padding: 12px 14px 14px 14px !important;
    flex-grow: 1 !important;
    display: flex !important;
    flex-direction: column !important;
}

.item-content h3 {
    font-family: 'Outfit', sans-serif !important;
    font-size: 0.96rem !important;
    font-weight: 800 !important;
    color: #171922 !important;
    margin: 0 0 4px !important;
    line-height: 1.25 !important;
}

.item-card-bottom {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-top: auto !important;
    padding-top: 8px !important;
}

.panda-price-pill {
    background: #fff9f2 !important;
    border: 1px solid #efddcd !important;
    border-radius: 999px !important;
    padding: 5px 14px !important;
    font-size: 0.9rem !important;
    font-weight: 800 !important;
    color: #b3261e !important;
    display: inline-flex !important;
    align-items: center !important;
    box-shadow: 0 2px 6px rgba(179, 38, 30, 0.04) !important;
}

.panda-quick-add-btn {
    width: 34px !important;
    height: 34px !important;
    border-radius: 50% !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #171922 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08) !important;
    transition: all 0.2s ease !important;
    font-size: 0.9rem !important;
}

.panda-quick-add-btn:hover {
    background: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
    transform: scale(1.1) !important;
}

.item-rating {
    margin-bottom: 15px;
    color: var(--secondary-color);
    font-size: 0.9rem;
}

.item-description {
    color: var(--text-light);
}

.item-specs {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    padding: 12px 15px;
    background-color: rgba(0,0,0,0.03);
    border-radius: 10px;
    font-size: 0.85rem;
}

.item-specs .spec {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-main);
    font-weight: 500;
}

.item-specs .spec i {
    color: var(--primary-color);
    opacity: 0.8;
}

.size-options {
    margin-bottom: 15px;
}

.size-options label,
.addon-options label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-main);
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.size-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.size-btn {
    padding: 6px 14px;
    background-color: white;
    border: 1px solid #e0e0e0;
    border-radius: 50px;
    color: var(--text-light);
    cursor: pointer;
    transition: var(--transition);
    font-size: 0.85rem;
    font-weight: 500;
}

.size-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background-color: rgba(179, 38, 30, 0.05);
}

.addon-options {
    margin-bottom: 20px;
}

.addon-item {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    color: #666;
    font-size: 0.9rem;
    padding: 5px 0;
}

.addon-item i {
    color: #4CAF50;
    font-size: 0.9rem;
}

.item-actions {
    margin-top: auto;
}

.item-actions .btn-primary {
    width: 100%;
    padding: 14px;
    font-size: 1rem;
    font-weight: 600;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    border: none;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 4px 15px rgba(179, 38, 30, 0.3);
}

.item-actions .btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(179, 38, 30, 0.5);
}

.item-actions .btn-primary:disabled {
    background: #e0e0e0;
    color: #9e9e9e;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
}

.item-unavailable .item-image img {
    filter: grayscale(100%);
}

.item-sold-out-overlay {
    position: absolute;
    display: flex;
    flex-direction: column;
}

.item-content h3 {
    color: var(--text-main);
    margin-bottom: 10px;
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1.3;
}

.item-rating {
    margin-bottom: 15px;
    color: var(--secondary-color);
    font-size: 0.9rem;
}

.item-description {
    color: var(--text-light);
    margin-bottom: 20px;
    line-height: 1.6;
    font-size: 0.9rem;
    flex-grow: 1;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.item-specs {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    padding: 12px 15px;
    background-color: rgba(0,0,0,0.03);
    border-radius: 10px;
    font-size: 0.85rem;
}

.item-specs .spec {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-main);
    font-weight: 500;
}

.item-specs .spec i {
    color: var(--primary-color);
    opacity: 0.8;
}

.size-options {
    margin-bottom: 15px;
}

.size-options label,
.addon-options label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-main);
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.size-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.size-btn {
    padding: 6px 14px;
    background-color: white;
    border: 1px solid #e0e0e0;
    border-radius: 50px;
    color: var(--text-light);
    cursor: pointer;
    transition: var(--transition);
    font-size: 0.85rem;
    font-weight: 500;
}

.size-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background-color: rgba(179, 38, 30, 0.05);
}

.addon-options {
    margin-bottom: 20px;
}

.addon-item {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    color: #666;
    font-size: 0.9rem;
    padding: 5px 0;
}

.addon-item i {
    color: #4CAF50;
    font-size: 0.9rem;
}

.item-actions {
    margin-top: auto;
}

.item-actions .btn-primary {
    width: 100%;
    padding: 14px;
    font-size: 1rem;
    font-weight: 600;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    border: none;
    transition: var(--transition);
    display: flex;
    border-radius: 10px;
}

.preview-price-section h4 {
    color: var(--text-main);
    font-size: 1.3rem;
    margin-bottom: 15px;
}

.price-amount {
    color: var(--primary-color);
    font-weight: 700;
    font-size: 1.5rem;
}

.size-weight-info {
    margin-top: 15px;
}

.size-weight-info .weight-info,
.size-weight-info .pax-info {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    color: #555;
    font-size: 0.95rem;
}

.size-weight-info i {
    color: var(--primary-color);
    width: 20px;
}

.preview-options {
    margin-bottom: 30px;
}

.size-selection,
.addons-selection,
.quantity-selection {
    margin-bottom: 25px;
}

.size-selection label,
.addons-selection label,
.quantity-selection label {
    display: block;
    margin-bottom: 12px;
    color: var(--text-main);
    font-weight: 600;
    font-size: 1rem;
}

.size-preview-btn {
    padding: 12px 20px;
    background-color: white;
    border: 1px solid #ddd;
    border-radius: 12px;
    color: var(--text-main);
    cursor: pointer;
    transition: var(--transition);
    font-weight: 500;
    text-align: left;
    margin-right: 10px;
    margin-bottom: 10px;
    display: inline-flex;
    flex-direction: column;
}

.size-preview-btn:hover,
.size-preview-btn.active {
    background-color: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
    box-shadow: 0 4px 10px rgba(179, 38, 30, 0.2);
}

.size-preview-btn .size-info {
    font-size: 0.85rem;
    opacity: 0.8;
    margin-top: 5px;
}

.addons-list {
    max-height: 150px;
    overflow-y: auto;
    padding-right: 10px;
}

.addon-checkbox {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    padding: 10px;
    background-color: #f9f9f9;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color var(--motion-fast) var(--motion-ease);
}

.addon-checkbox:hover {
    background-color: #f0f0f0;
}

.addon-checkbox input {
    width: 18px;
    height: 18px;
}

.addon-checkbox span {
    color: #555;
    font-size: 0.95rem;
}

.quantity-control {
    display: flex;
    align-items: center;
    border: 2px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
    width: 150px;
}

.quantity-control button {
    width: 45px;
    height: 45px;
    background-color: #f8f9fa;
    border: none;
    color: #333;
    font-size: 1.2rem;
    cursor: pointer;
    transition: background-color var(--motion-fast) var(--motion-ease);
}

.quantity-control button:hover {
    background-color: #e9ecef;
}

.quantity-control input {
    width: 60px;
    height: 45px;
    border: none;
    text-align: center;
    font-size: 1.1rem;
    font-weight: 600;
    color: #333;
}

.order-summary {
    background-color: #f5f5f5;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 25px;
}

.order-summary h4 {
    color: var(--text-main);
    margin-bottom: 20px;
    font-size: 1.2rem;
}

.summary-details p {
    margin-bottom: 10px;
    color: #555;
    display: flex;
    justify-content: space-between;
}

.summary-details .summary-total {
    font-weight: 700;
    color: var(--text-main);
    font-size: 1.2rem;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #ddd;
}

.add-to-cart-confirm {
    width: 100%;
    padding: 16px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    border: none;
    color: white;
    cursor: pointer;
    transition: var(--transition);
    box-shadow: 0 6px 20px rgba(179, 38, 30, 0.3);
}

.add-to-cart-confirm:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(179, 38, 30, 0.5);
}

/* Delivery Modal */
.delivery-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 2000;
}

.delivery-modal.active {
    display: block;
}

.delivery-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
    z-index: 2001;
}

.delivery-modal-content {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) scale(0.9);
    width: 90%;
    max-width: 600px;
    background-color: white;
    border-radius: 16px;
    padding: 40px;
    z-index: 2002;
    opacity: 0;
    transition: var(--transition-fade);
}

.delivery-modal.active .delivery-modal-content {
    transform: translate(-50%, -50%) scale(1);
    opacity: 1;
}

.delivery-close {
    position: absolute;
    top: 20px;
    right: 20px;
    background: none;
    border: none;
    font-size: 1.8rem;
    color: #666;
    cursor: pointer;
    transition: color var(--motion-fast) var(--motion-ease);
}

.delivery-close:hover {
    color: var(--primary-color);
}

.delivery-modal-content h3 {
    color: var(--text-main);
    font-size: 1.8rem;
    margin-bottom: 30px;
    text-align: center;
}

.delivery-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.delivery-option {
    padding: 25px;
    border: 2px solid #eee;
    border-radius: 12px;
    cursor: pointer;
    transition: var(--transition);
    text-align: center;
}

.delivery-option:hover,
.delivery-option.active {
    border-color: var(--primary-color);
    background-color: #fff5f5;
}

.delivery-option i {
    font-size: 2.5rem;
    color: var(--primary-color);
    margin-bottom: 15px;
}

.btn-near-me {
    margin-bottom: 12px;
}

.near-me-status {
    margin: 5px 0 14px;
    font-size: 0.85rem;
    color: #1f7a1f;
    min-height: 18px;
}

.near-me-status.error {
    color: var(--primary-color);
}

.delivery-option h4 {
    color: #333;
    margin-bottom: 10px;
    font-size: 1.3rem;
}

.delivery-option p {
    color: #666;
    font-size: 0.95rem;
}

.store-locations {
    margin-top: 20px;
    text-align: left;
}

.store-location {
    margin-bottom: 15px;
    padding: 15px;
    background-color: #f9f9f9;
    border-radius: 8px;
}

.store-location input {
    margin-right: 10px;
}

.store-location label {
    cursor: pointer;
    display: block;
}

.store-location strong {
    color: #333;
    display: block;
    margin-bottom: 5px;
}

.store-location p {
    color: #666;
    font-size: 0.9rem;
    margin: 5px 0;
}

.delivery-locations {
    margin-top: 20px;
    text-align: left;
}

.delivery-locations label {
    display: block;
    margin-bottom: 10px;
    color: #333;
    font-weight: 600;
}

.delivery-locations select {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
    margin-bottom: 15px;
}

.delivery-fee-info {
    padding: 15px;
    background-color: #f0f7ff;
    border-radius: 8px;
    color: #1976d2;
    font-weight: 600;
}

.delivery-summary {
    background-color: #f9f9f9;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.delivery-summary h4 {
    color: #333;
    margin-bottom: 20px;
    font-size: 1.2rem;
}

.delivery-summary .summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    color: #555;
}

.delivery-summary .summary-row.total {
    font-weight: 700;
    color: #333;
    font-size: 1.2rem;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #ddd;
}

.delivery-modal-content .btn-primary {
    width: 100%;
    padding: 16px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    border: none;
    color: white;
    cursor: pointer;
    transition: var(--transition);
}

.delivery-modal-content .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(179, 38, 30, 0.4);
}

/* Cart Sidebar */
.cart-sidebar {
    position: fixed;
    top: 0;
    right: -450px;
    width: 100%;
    max-width: 450px;
    height: 100vh;
    background-color: white;
    box-shadow: -5px 0 25px rgba(0,0,0,0.15);
    z-index: 1003;
    transition: right var(--motion-slow) var(--motion-ease);
    display: flex;
    flex-direction: column;
}

.cart-sidebar.active {
    right: 0;
}

.cart-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    z-index: 1002;
    display: none;
}

.cart-overlay.active {
    display: block;
}

.cart-header {
    padding: 25px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: #f8f9fa;
}

.cart-header h3 {
    margin: 0;
    color: var(--text-main);
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.cart-close {
    background: none;
    border: none;
    font-size: 1.8rem;
    color: #666;
    cursor: pointer;
    padding: 5px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: var(--transition-fast);
}

.cart-close:hover {
    background-color: #e9ecef;
    color: var(--primary-color);
}

.cart-body {
    flex: 1;
    overflow-y: auto;
    padding: 25px;
}

.cart-empty {
    text-align: center;
    padding: 60px 25px;
    color: #666;
}

.cart-empty i {
    font-size: 4rem;
    margin-bottom: 25px;
    color: #ddd;
}

.cart-empty p {
    margin-bottom: 25px;
    font-size: 1.1rem;
}

.cart-empty .btn-primary {
    padding: 12px 30px;
    border-radius: 8px;
}

.cart-item {
    display: flex;
    gap: 20px;
    padding: 20px 0;
    border-bottom: 1px solid #eee;
}

.cart-item:last-child {
    border-bottom: none;
}

.cart-item-image {
    width: 90px;
    height: 90px;
    background-color: #f5f5f5;
    border-radius: 8px;
    overflow: hidden;
    flex-shrink: 0;
}

.cart-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.cart-item-details {
    flex: 1;
}

.cart-item-name {
    font-weight: 600;
    color: #333;
    margin-bottom: 8px;
    font-size: 1.1rem;
}

.cart-item-size {
    color: #666;
    font-size: 0.9rem;
    margin-bottom: 5px;
}

.cart-item-price {
    color: var(--primary-color);
    font-weight: 600;
    margin-bottom: 10px;
    font-size: 1rem;
}

.cart-item-addons {
    color: #888;
    font-size: 0.85rem;
    margin-bottom: 15px;
}

.cart-item-quantity {
    display: flex;
    align-items: center;
    gap: 15px;
}

.cart-item-quantity button {
    width: 30px;
    height: 30px;
    background-color: #f8f9fa;
    border: 2px solid #ddd;
    border-radius: 6px;
    color: #333;
    cursor: pointer;
    font-weight: bold;
    transition: var(--transition-fast);
}

.cart-item-quantity button:hover {
    background-color: #e9ecef;
    border-color: var(--primary-color);
}

.cart-item-quantity span {
    font-weight: 600;
    min-width: 30px;
    text-align: center;
}

.cart-item-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: space-between;
}

.cart-item-total {
    font-weight: 700;
    color: var(--primary-color);
    font-size: 1.1rem;
}

.cart-item-remove {
    background: none;
    border: none;
    color: #dc3545;
    cursor: pointer;
    font-size: 1.2rem;
    padding: 5px;
    border-radius: 6px;
    transition: background-color var(--motion-fast) var(--motion-ease);
}

.cart-item-remove:hover {
    background-color: #ffebee;
}

.cart-footer {
    padding: 25px;
    border-top: 1px solid #eee;
    background-color: #f8f9fa;
}

.order-type-selection {
    margin-bottom: 25px;
    padding: 20px;
    background-color: white;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

.order-type-selection h4 {
    color: #333;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.order-type-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.order-type-btn {
    flex: 1;
    padding: 15px;
    background-color: white;
    border: 2px solid #ddd;
    border-radius: 8px;
    color: #666;
    cursor: pointer;
    transition: var(--transition-fast);
    font-weight: 600;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.order-type-btn:hover,
.order-type-btn.active {
    background-color: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.order-type-btn i {
    font-size: 1.3rem;
}

.order-type-details {
    padding: 15px;
    background-color: #f0f7ff;
    border-radius: 8px;
    color: #1976d2;
    font-size: 0.95rem;
}

.order-type-details p {
    margin: 5px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.order-type-details i {
    width: 20px;
}

.cart-summary {
    margin-bottom: 25px;
}

.cart-summary .summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    color: #555;
    font-size: 1rem;
}

.cart-summary .summary-row.total {
    font-weight: 700;
    color: #333;
    font-size: 1.3rem;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #ddd;
}

.cart-actions {
    display: flex;
    gap: 15px;
}

.cart-actions .btn-secondary,
.cart-actions .btn-primary {
    flex: 1;
    padding: 16px;
    text-align: center;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    transition: var(--transition-lift);
}

.cart-actions .btn-secondary {
    background-color: #6c757d;
    color: white;
    border: none;
}

.cart-actions .btn-secondary:hover {
    background-color: #5a6268;
    transform: translateY(-2px);
}

.cart-actions .btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    border: none;
}

.cart-actions .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(179, 38, 30, 0.4);
}

.cart-actions .btn-primary:disabled {
    background: #cccccc;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* Cart Toggle Button */
.cart-toggle-btn {
    position: fixed;
    bottom: <?php echo (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'customer') ? '170px' : '90px'; ?>;
    right: 30px;
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    border: none;
    border-radius: 50%;
    box-shadow: 0 8px 25px rgba(179, 38, 30, 0.5);
    cursor: pointer;
    z-index: 1001;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    transition: var(--transition);
}

.cart-toggle-btn:hover {
    transform: scale(1.1) translateY(-5px) rotate(5deg);
    box-shadow: 0 15px 35px rgba(179, 38, 30, 0.6);
}

.cart-toggle-btn::after {
    content: attr(data-tooltip);
    position: absolute;
    right: 110%;
    top: 50%;
    transform: translateY(-50%);
    background-color: rgba(0,0,0,0.8);
    color: white;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all var(--motion-base) var(--motion-ease);
    pointer-events: none;
    font-weight: normal;
}

.cart-toggle-btn:hover::after {
    opacity: 1;
    visibility: visible;
    right: 120%;
}

.cart-count-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: var(--secondary-color);
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 700;
    box-shadow: 0 3px 10px rgba(255, 152, 0, 0.4);
}

/* Responsive Design */
@media (max-width: 1200px) {
    .menu-items-grid {
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    }
    
    .preview-body {
        flex-direction: column;
        max-height: 90vh;
    }
    
    .preview-image {
        height: 250px;
    }
}

@media (max-width: 992px) {
    .category-nav {
        margin: 30px 0;
        top: calc(var(--site-header-offset, 82px) + 8px);
    }
    
    .delivery-options {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: 100px 20px 60px;
    }
    
    .page-header h1 {
        font-size: 2.2rem;
    }
    
    .menu-items-grid {
        grid-template-columns: 1fr;
    }

    .menu-filter-bar {
        flex-direction: column;
        align-items: stretch;
    }

    .menu-filter-bar select {
        min-width: 100%;
    }

    .menu-search-field {
        width: 100%;
    }
    
    .cart-sidebar {
        width: 100%;
        max-width: none;
        right: -100%;
    }
    
    .preview-modal-content {
        width: 95%;
        max-height: 95vh;
    }
    
    .delivery-modal-content {
        width: 95%;
        padding: 30px 20px;
    }
    
    .cart-toggle-btn {
        bottom: <?php echo (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'customer') ? '165px' : '90px'; ?>;
        right: 30px;
        width: 60px;
        height: 60px;
        font-size: 1.3rem;
    }
    
    .cart-count-badge {
        width: 24px;
        height: 24px;
        font-size: 0.7rem;
    }
}

@media (max-width: 576px) {
    .page-header h1 {
        font-size: 1.8rem;
    }
    
    .category-title {
        font-size: 1.6rem;
    }
    
    .item-actions {
        flex-direction: column;
    }
    
    .item-actions .btn-primary {
        width: 100%;
    }
    
    .preview-details {
        padding: 25px;
    }
    
    .cart-actions {
        flex-direction: column;
    }
    
    .cart-actions .btn-secondary,
    .cart-actions .btn-primary {
        width: 100%;
    }
}

.menu-search-result-info {
    margin: -16px 0 18px;
    color: #7a5a46;
    font-weight: 600;
    font-size: 0.9rem;
}

/* Modern Food Menu Refresh */
:root {
    --menu-red: #b3261e;
    --menu-orange: #ef6b2e;
    --menu-cream: #fff8ef;
    --menu-card: #ffffff;
    --menu-ink: #2a211d;
    --menu-muted: #7a6c63;
    --menu-border: #efddcd;
    --menu-shadow: 0 16px 34px rgba(74, 32, 20, 0.12);
}

body {
    background:
        radial-gradient(circle at 5% 0%, rgba(239, 107, 46, 0.12), transparent 32%),
        radial-gradient(circle at 95% 12%, rgba(179, 38, 30, 0.12), transparent 30%),
        var(--menu-cream);
}

.page-header {
    margin-bottom: 0;
    padding: 36px 20px 28px;
    background: #ffffff;
    border-bottom: 1px solid #eaecf0;
}

.page-header h1 {
    letter-spacing: -0.02em;
    color: #101828;
}

.page-header p {
    color: #475467;
}

.menu-section {
    background: #f8f9fa;
    padding-top: 32px;
}

.category-nav {
    top: calc(var(--site-header-offset, 92px) + 10px);
    background: rgba(255, 255, 255, 0.92);
    border: 1px solid var(--menu-border);
    border-radius: 16px;
    box-shadow: 0 12px 26px rgba(74, 32, 20, 0.09);
    backdrop-filter: blur(8px);
}

.category-link {
    border-radius: 999px;
    border: 1px solid #ebd4c1;
    color: #7e3a2b;
    background: #fff;
    font-weight: 600;
}

.category-link.active,
.category-link:hover {
    background: linear-gradient(135deg, var(--menu-red), var(--menu-orange));
    border-color: transparent;
    color: #fff;
}

.menu-filter-bar {
    background: #fff;
    border: 1px solid var(--menu-border);
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(74, 32, 20, 0.08);
    padding: 12px 16px;
}

.menu-search-field {
    border-color: #e8d5c5;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.5);
}

.menu-filter-bar label {
    color: var(--menu-ink);
    font-weight: 700;
}

.menu-filter-bar select {
    border: 1px solid #e8d5c5;
    border-radius: 10px;
}

.menu-category {
    margin-bottom: 40px;
}

.category-title {
    color: var(--menu-ink);
    letter-spacing: -0.01em;
}

.menu-item {
    border: 1px solid var(--menu-border);
    border-radius: 20px;
    overflow: hidden;
    background: var(--menu-card);
    box-shadow: var(--menu-shadow);
    transition: var(--transition-lift);
}

.menu-item:hover {
    transform: translateY(-8px);
    box-shadow: 0 22px 40px rgba(74, 32, 20, 0.16);
}

.item-image {
    background: #351a12;
}

.item-price-tag {
    background: linear-gradient(135deg, var(--menu-red), var(--menu-orange));
    box-shadow: 0 8px 20px rgba(179, 38, 30, 0.34);
}

.top-seller-badge {
    background: #ffe8d2;
    color: #932d1f;
}

.item-content h3,
.item-rating {
    color: var(--menu-ink);
}

.item-description,
.spec span,
.review-count,
.addon-item {
    color: var(--menu-muted);
}

.item-specs .spec {
    background: #fff5e8;
    border: 1px solid #f0dbc7;
    border-radius: 10px;
}

.size-btn {
    border-radius: 999px;
    border: 1px solid #e7cebb;
}

.size-btn.active,
.size-btn:hover {
    background: #fff0e1;
    border-color: #d79d78;
    color: #8f2f20;
}

.item-actions .btn-primary,
.add-to-cart-confirm,
.cart-actions .btn-primary {
    background: linear-gradient(135deg, var(--menu-red), var(--menu-orange));
    border: none;
    box-shadow: 0 12px 26px rgba(179, 38, 30, 0.26);
}

.item-actions .btn-primary:hover,
.add-to-cart-confirm:hover,
.cart-actions .btn-primary:hover {
    box-shadow: 0 16px 32px rgba(179, 38, 30, 0.33);
}

.preview-modal-content,
.delivery-modal-content,
.cart-sidebar {
    border: 1px solid var(--menu-border);
    border-radius: 20px;
    box-shadow: 0 20px 45px rgba(74, 32, 20, 0.2);
}

.swal-preview-top {
    z-index: 5000 !important;
}

.preview-details,
.cart-footer {
    background: #fffaf5;
}

.cart-header {
    background: linear-gradient(130deg, #fff4e9, #ffe7d3);
    border-bottom: 1px solid var(--menu-border);
}

.cart-body {
    background: #fff;
}

.cart-item {
    border: 1px solid #efddcd;
    border-radius: 12px;
    background: #fffaf5;
}

.cart-toggle-btn {
    background: linear-gradient(135deg, var(--menu-red), var(--menu-orange));
}

.cart-count-badge {
    background: #233f32;
}

@media (max-width: 768px) {
    .page-header {
        padding-top: 108px;
        padding-bottom: 62px;
    }

    .category-nav {
        top: calc(var(--site-header-offset, 78px) + 8px);
        border-radius: 12px;
    }
}

/* Landing Rhythm Sync */
:root {
    --rhythm-xl: 36px;
    --rhythm-lg: 28px;
    --rhythm-md: 20px;
    --rhythm-sm: 14px;
    --rhythm-card-radius: 24px;
    --rhythm-soft-radius: 18px;
}

.page-header h1 {
    font-size: clamp(2.35rem, 4.2vw, 3.6rem);
    line-height: 1.02;
    letter-spacing: -0.035em;
}

.page-header p {
    font-size: 1.02rem;
    max-width: 760px;
    line-height: 1.72;
}

.menu-section {
    padding-top: var(--rhythm-lg);
    padding-bottom: calc(var(--rhythm-lg) + 18px);
}

.category-nav {
    margin-bottom: var(--rhythm-lg);
    padding: 12px 16px;
    border-radius: var(--rhythm-soft-radius);
}

.menu-filter-bar {
    margin-bottom: var(--rhythm-md);
    gap: 12px;
}

.menu-filter-bar select {
    min-height: 44px;
    border-radius: 12px;
}

.menu-category {
    margin-bottom: var(--rhythm-lg);
}

.category-title {
    margin-bottom: var(--rhythm-md);
    font-size: clamp(1.8rem, 2.4vw, 2.5rem);
    padding-left: 14px;
    border-left-width: 4px;
}

.menu-items-grid {
    gap: 22px;
}

.menu-item {
    border-radius: var(--rhythm-card-radius);
}

.item-content {
    padding: var(--rhythm-md);
}

.item-content h3 {
    font-size: 1.2rem;
    line-height: 1.3;
}

.item-description {
    font-size: 0.93rem;
    line-height: 1.62;
}

.item-actions .btn-primary,
.add-to-cart-confirm,
.cart-actions .btn-primary,
.cart-actions .btn-secondary {
    min-height: 46px;
    border-radius: 12px;
}

.preview-modal-content,
.delivery-modal-content,
.cart-sidebar {
    border-radius: var(--rhythm-card-radius);
}

@media (max-width: 768px) {
    .menu-section {
        padding-top: 22px;
    }

    .menu-items-grid {
        gap: 16px;
    }

    .menu-item {
        border-radius: 20px;
    }
}

/* Compact Storefront Header */
.page-header.storefront-header,
.storefront-header {
    margin: 0 !important;
    padding: 18px 0 14px !important;
    background: linear-gradient(180deg, #fffaf3 0%, #fff6ea 100%) !important;
    border-bottom: 1px solid var(--menu-border) !important;
    color: var(--menu-ink) !important;
    text-align: left !important;
}

.store-breadcrumb {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
    font-size: 0.94rem;
}

.store-breadcrumb a {
    color: #5f3a2c;
    text-decoration: none;
    font-weight: 600;
}

.store-breadcrumb a:hover {
    color: var(--menu-red);
}

.store-breadcrumb .breadcrumb-sep {
    color: #a68b7d;
    font-weight: 700;
}

.store-breadcrumb .is-current {
    color: #2f2520;
    font-weight: 800;
}

.storefront-overview {
    display: grid;
    grid-template-columns: 128px minmax(0, 1fr);
    gap: 18px;
    align-items: center;
}

.store-logo-tile {
    width: 128px !important;
    height: 128px !important;
    max-width: 128px !important;
    max-height: 128px !important;
    flex-shrink: 0 !important;
    border-radius: 18px !important;
    border: 1px solid var(--menu-border);
    background: #fff;
    box-shadow: 0 12px 24px rgba(74, 32, 20, 0.09);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden !important;
}

.store-logo-tile img {
    width: 100% !important;
    height: 100% !important;
    max-width: 100% !important;
    max-height: 100% !important;
    object-fit: cover !important;
    display: block !important;
    position: relative;
    z-index: 2;
}

.store-logo.item-sold-out-overlay span {
    background-color: #ffffff;
    color: #b3261e;
    padding: 8px 18px;
    border-radius: 999px;
    font-weight: 800;
    font-size: 1.1rem;
    text-transform: uppercase;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}

/* Product Preview Modal */
.product-preview-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 2000;
}

.product-preview-modal.active {
    display: block;
}

.preview-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(23, 25, 34, 0.65);
    backdrop-filter: blur(4px);
    z-index: 2001;
}

.preview-modal-content {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) scale(0.95);
    width: 92%;
    max-width: 860px;
    max-height: 88vh;
    background-color: #ffffff;
    border-radius: 24px;
    overflow: hidden;
    z-index: 2002;
    opacity: 0;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.2);
    transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}

.product-preview-modal.active .preview-modal-content {
    transform: translate(-50%, -50%) scale(1);
    opacity: 1;
}

.preview-close {
    position: absolute;
    top: 16px;
    right: 16px;
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    font-size: 1.3rem;
    color: #171922;
    cursor: pointer;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.preview-close:hover {
    background-color: #b3261e;
    color: #ffffff;
    border-color: #b3261e;
}

.preview-body {
    display: flex;
    max-height: 88vh;
    overflow: hidden;
}

@media (max-width: 768px) {
    .preview-body {
        flex-direction: column;
        overflow-y: auto;
    }
}

.preview-image {
    flex: 1;
    min-width: 340px;
    background-color: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}

.preview-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.preview-details {
    flex: 1.2;
    padding: 28px 32px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.preview-details h3 {
    font-family: 'Outfit', sans-serif;
    color: #171922;
    font-size: 1.6rem;
    font-weight: 800;
    margin: 0 0 8px 0;
    line-height: 1.25;
}

.preview-description {
    color: #64748b;
    font-size: 0.92rem;
    margin-bottom: 20px;
    line-height: 1.5;
}

.preview-price-section {
    margin-bottom: 20px;
    padding: 16px 20px;
    background-color: #fff9f2;
    border: 1px solid #efddcd;
    border-radius: 16px;
}

.preview-price-section h4 {
    color: #171922;
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 8px 0;
}

.price-amount {
    color: #b3261e;
    font-weight: 800;
    font-size: 1.5rem;
    font-family: 'Outfit', sans-serif;
}

.size-weight-info {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.store-logo-tile img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    position: relative;
    z-index: 2;
}

.product-favorite-btn {
    position: absolute;
    top: 14px;
    right: 14px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.88);
    background: rgba(255, 255, 255, 0.92);
    color: #7b6f68;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 3;
    transition: var(--transition);
}

.product-favorite-btn:hover {
    color: var(--primary-color);
    transform: translateY(-1px);
}

.product-favorite-btn.is-active {
    color: var(--primary-color);
    background: #fff1ea;
    border-color: #f0c3ab;
}

.store-logo-initials {
    position: absolute;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: #7e3a2b;
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    background: radial-gradient(circle at 30% 20%, #fff6ea 0%, #ffeddc 100%);
}

.storefront-copy h1 {
    margin: 0 0 6px;
    font-size: clamp(2rem, 3vw, 3rem);
    line-height: 1.04;
    letter-spacing: -0.03em;
    color: var(--menu-ink);
}

.storefront-categories {
    margin: 0 0 4px !important;
    color: #ef6b2e !important;
    font-size: 0.88rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
}

.storefront-subtitle {
    margin: 4px 0 10px !important;
    color: #64748b !important;
    font-size: 0.92rem !important;
    line-height: 1.5 !important;
}

.storefront-meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 18px;
    margin-bottom: 6px;
}

.storefront-meta-row span,
.storefront-meta-row a {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #4a352b;
    font-size: 0.92rem;
    font-weight: 600;
    text-decoration: none;
}

.storefront-meta-row a:hover {
    color: var(--menu-red);
}

.storefront-meta-row-secondary a:first-child {
    color: #b85b1b;
}

.menu-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 24px;
}

@media (max-width: 1200px) {
    .menu-layout {
        grid-template-columns: minmax(0, 1fr) 300px;
        gap: 16px;
    }
}

.menu-main-column {
    min-width: 0;
}

.menu-side-column {
    min-width: 0;
    height: 100%;
}

.menu-side-stack {
    position: sticky !important;
    top: var(--menu-side-stack-top, calc(var(--site-header-offset, 72px) + 76px)) !important;
    display: flex;
    flex-direction: column;
    gap: 16px;
    z-index: 100 !important;
}

.quick-order-panel,
.store-review-panel {
    background: #fff;
    border: 1px solid var(--menu-border);
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(16, 24, 40, 0.06);
}

.quick-order-panel {
    padding: 14px;
    position: relative !important;
    z-index: 90 !important;
}

.quick-order-tabs {
    background: #f7f2ec;
    border-radius: 12px;
    padding: 5px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    position: relative !important;
    z-index: 100 !important;
    pointer-events: auto !important;
}

.quick-order-tab {
    border: none;
    background: transparent;
    color: #6d5143;
    font-weight: 700;
    border-radius: 10px;
    padding: 10px 8px;
    font-size: 0.92rem;
    cursor: pointer !important;
    transition: var(--transition-fast);
    position: relative !important;
    z-index: 101 !important;
    pointer-events: auto !important;
}

.quick-order-tab.active {
    background: #fff;
    color: var(--menu-red);
    box-shadow: 0 4px 10px rgba(74, 32, 20, 0.08);
}

.quick-order-hero {
    min-height: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 14px 8px 10px;
}

.quick-order-hero-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #f39b35;
    background: linear-gradient(135deg, #fff4e1, #ffe2bf);
    box-shadow: inset 0 0 0 1px #f4d2a8;
    font-size: 1.35rem;
}

.quick-order-hero h3 {
    margin: 8px 0 2px;
    color: #2c2521;
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.2;
}

.quick-order-hero p {
    margin: 0;
    color: #7d6b62;
    font-size: 0.85rem;
    font-weight: 600;
}

.quick-order-summary {
    border-top: 1px solid #f0dfcf;
    padding-top: 12px;
}

.quick-order-items {
    margin-bottom: 12px;
    display: grid;
    gap: 8px;
    max-height: 180px;
    overflow-y: auto;
    padding-right: 2px;
}

.quick-order-items-empty {
    margin: 0;
    font-size: 0.84rem;
    font-weight: 600;
    color: #8b7466;
}

.quick-order-item {
    border: 1px solid #efdccc;
    background: #fff8f2;
    border-radius: 10px;
    padding: 8px 9px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: start;
}

.quick-order-item-info {
    min-width: 0;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.quick-order-item-thumb {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #ecd6c4;
    background: #fff;
}

.quick-order-item-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.quick-order-item-main {
    min-width: 0;
}

.quick-order-item-main strong {
    display: block;
    color: #3a2d27;
    font-size: 0.84rem;
    line-height: 1.3;
}

.quick-order-item-main span {
    display: block;
    margin-top: 3px;
    color: #866f62;
    font-size: 0.74rem;
    line-height: 1.35;
}

.quick-order-item-meta {
    text-align: right;
    display: grid;
    gap: 1px;
}

.quick-order-item-meta span {
    color: #8a7366;
    font-size: 0.74rem;
    font-weight: 700;
}

.quick-order-item-meta strong {
    color: #2f2520;
    font-size: 0.78rem;
}

.quick-order-meta {
    margin: 0 0 8px;
    color: #8c6f60;
    font-size: 0.86rem;
    font-weight: 700;
}

.quick-order-summary .summary-row {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 10px;
}

.quick-order-summary .summary-row span {
    color: #4f3b32;
    font-weight: 700;
}

.quick-order-summary .summary-row span small {
    display: block;
    color: #8e7668;
    font-size: 0.8rem;
    font-weight: 500;
}

.quick-order-summary .summary-row strong {
    font-size: 1.5rem;
    color: #2f2520;
}

.quick-order-link {
    margin-top: 4px;
    border: none;
    background: none;
    padding: 0;
    color: #3c2f29;
    text-decoration: underline;
    text-decoration-thickness: 2px;
    text-underline-offset: 2px;
    font-weight: 800;
    cursor: pointer;
}

.quick-order-checkout {
    margin-top: 12px;
    width: 100%;
    border: none;
    border-radius: 10px;
    min-height: 48px;
    font-weight: 800;
    color: #fff;
    background: linear-gradient(135deg, var(--menu-red), var(--menu-orange));
    box-shadow: 0 8px 18px rgba(179, 38, 30, 0.28);
    transition: var(--transition-fast);
    cursor: pointer;
}

.quick-order-checkout:disabled {
    background: #d8d8d8;
    color: #9a9a9a;
    box-shadow: none;
    cursor: not-allowed;
}

.quick-order-checkout:not(:disabled):hover {
    transform: translateY(-1px);
}

.mobile-quick-order {
    display: none;
}

.mobile-quick-order-inner {
    border: 1px solid var(--menu-border);
    background: #fffdf9;
    border-radius: 14px;
    box-shadow: 0 14px 24px rgba(74, 32, 20, 0.2);
    padding: 10px;
}

.mobile-quick-order-top {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.mobile-quick-order-tabs {
    flex: 1;
    background: #f7f1ea;
    border-radius: 10px;
    padding: 4px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
}

.mobile-quick-order-tab {
    border: none;
    background: transparent;
    color: #6d5143;
    font-weight: 700;
    border-radius: 8px;
    padding: 8px 6px;
    cursor: pointer;
    transition: var(--transition-fast);
    font-size: 0.84rem;
}

.mobile-quick-order-tab.active {
    background: #fff;
    color: var(--menu-red);
    box-shadow: 0 5px 10px rgba(74, 32, 20, 0.1);
}

.mobile-quick-order-link {
    border: none;
    background: #fff4e8;
    color: #5d4336;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.82rem;
    padding: 8px 10px;
    cursor: pointer;
}

.mobile-quick-order-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.mobile-quick-order-meta {
    margin: 0 0 2px;
    color: #866e61;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.01em;
}

.mobile-quick-order-total {
    color: #2f2520;
    font-size: 1.2rem;
    line-height: 1.1;
}

.mobile-quick-order-checkout {
    border: none;
    min-height: 40px;
    border-radius: 9px;
    padding: 0 14px;
    color: #fff;
    font-weight: 800;
    font-size: 0.85rem;
    background: linear-gradient(135deg, var(--menu-red), var(--menu-orange));
    box-shadow: 0 8px 14px rgba(179, 38, 30, 0.25);
    transition: var(--transition-fast);
}

.mobile-quick-order-checkout:disabled {
    background: #d8d8d8;
    color: #999;
    box-shadow: none;
}

.store-review-panel {
    overflow: hidden;
}

.store-review-panel-header {
    padding: 14px 14px 12px;
    border-bottom: 1px solid #f0dfcf;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.store-review-panel-header h3 {
    margin: 0;
    font-size: 1rem;
    color: #2c2521;
}

.store-review-panel-header span {
    color: #8f7668;
    font-size: 0.82rem;
    font-weight: 700;
}

.store-review-list {
    max-height: 470px;
    overflow-y: auto;
    padding: 10px;
    display: grid;
    gap: 10px;
}

.store-review-item {
    border: 1px solid #f0dfcf;
    background: #fffaf5;
    border-radius: 12px;
    padding: 11px;
}

.store-review-item-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
}

.store-review-item-top strong {
    color: #342721;
    font-size: 0.9rem;
}

.store-review-item-top time {
    color: #937c6f;
    font-size: 0.77rem;
    font-weight: 600;
}

.store-review-stars {
    display: flex;
    gap: 3px;
    color: #f39b35;
    margin-bottom: 7px;
    font-size: 0.82rem;
}

.store-review-comment {
    margin: 0;
    color: #5f4b41;
    font-size: 0.87rem;
    line-height: 1.48;
}

.store-review-reply {
    margin-top: 8px;
    padding: 8px 9px;
    border-radius: 10px;
    border: 1px solid #edd7c8;
    background: #fff;
}

.store-review-reply-label {
    margin: 0 0 4px;
    color: #7a3f1f;
    font-size: 0.76rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.store-review-reply-text {
    margin: 0;
    color: #5f4b41;
    font-size: 0.83rem;
    line-height: 1.45;
}

.store-review-product {
    margin: 7px 0 0;
    color: #8a6f61;
    font-size: 0.78rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.store-review-empty {
    text-align: center;
    color: #7f6b5e;
    padding: 28px 12px;
}

.store-review-empty i {
    font-size: 1.65rem;
    margin-bottom: 10px;
    color: #c9a58f;
}

.store-review-empty p {
    margin: 0;
    font-size: 0.88rem;
    font-weight: 600;
}

@media (max-width: 992px) {
    .menu-layout {
        grid-template-columns: minmax(0, 1fr);
    }

    .menu-side-column {
        display: none !important;
    }

    .mobile-quick-order {
        display: block !important;
    }

    .store-review-list {
        max-height: none;
    }
}

@media (max-width: 768px) {
    .menu-side-column {
        display: none;
    }

    .mobile-quick-order {
        display: block;
        position: fixed;
        left: 10px;
        right: 10px;
        bottom: calc(<?php echo $mobile_quick_order_bottom; ?> + env(safe-area-inset-bottom, 0px));
        z-index: 1004;
    }

    .menu-section {
        padding-bottom: 210px;
    }

    .cart-toggle-btn {
        display: none;
    }
}

@media (max-width: 860px) {
    .storefront-overview {
        grid-template-columns: 100px minmax(0, 1fr) !important;
        gap: 14px !important;
        align-items: center !important;
    }

    .store-logo-tile {
        width: 100px !important;
        height: 100px !important;
        max-width: 100px !important;
        max-height: 100px !important;
        flex-shrink: 0 !important;
        justify-self: start !important;
        border-radius: 16px !important;
        overflow: hidden !important;
    }

    .store-logo-tile img {
        width: 100% !important;
        height: 100% !important;
        max-width: 100% !important;
        max-height: 100% !important;
        object-fit: cover !important;
    }
}

@media (max-width: 640px) {
    .storefront-header {
        padding-top: 14px;
    }

    .storefront-copy h1 {
        font-size: 1.85rem;
    }

    .storefront-meta-row {
        gap: 10px 12px;
    }
}

/* GrabFood Style Storefront Header and Menu Items Overrides */
.storefront-header {
    background-color: #fff9f2 !important;
    border-bottom: 1px solid #efddcd !important;
    padding: 32px 0 !important;
    text-align: left !important;
}
.store-breadcrumb {
    margin-bottom: 16px !important;
    font-size: 0.85rem !important;
    color: #667085 !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.store-breadcrumb a {
    color: #667085 !important;
    text-decoration: none !important;
    font-weight: 500 !important;
}
.store-breadcrumb a:hover {
    color: #b3261e !important;
}
.store-breadcrumb .breadcrumb-sep {
    color: #667085 !important;
    font-size: 0.85rem !important;
}
.storefront-overview {
    display: flex !important;
    flex-direction: row !important;
    gap: 20px !important;
    align-items: center !important;
}
.store-logo-tile {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 110px !important;
    height: 110px !important;
    min-width: 110px !important;
    min-height: 110px !important;
    border-radius: 18px !important;
    background: #ffffff !important;
    border: 1px solid #eaecf0 !important;
    box-shadow: 0 4px 16px rgba(16, 24, 40, 0.08) !important;
    overflow: hidden !important;
    flex-shrink: 0 !important;
    position: relative !important;
}
.store-logo-tile img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    display: block !important;
}
.store-logo-initials {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(135deg, #b3261e 0%, #981b15 100%) !important;
    color: #ffffff !important;
    font-family: 'Outfit', sans-serif !important;
    font-size: 2.2rem !important;
    font-weight: 800 !important;
    letter-spacing: -0.5px !important;
}
.storefront-copy {
    padding: 0 !important;
    flex: 1 !important;
    min-width: 0 !important;
}
.storefront-copy h1 {
    font-family: 'Outfit', sans-serif !important;
    font-weight: 800 !important;
    font-size: 2.2rem !important;
    color: #171922 !important;
    margin: 0 0 6px 0 !important;
    letter-spacing: -0.5px !important;
}
.storefront-categories {
    color: #667085 !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    text-transform: capitalize !important;
    margin: 0 0 6px 0 !important;
}
.storefront-subtitle {
    color: #7b6d64 !important;
    font-size: 0.92rem !important;
    margin: 0 0 12px 0 !important;
    max-width: 600px !important;
}
.storefront-meta-row {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 16px !important;
    color: #667085 !important;
    font-size: 0.85rem !important;
    margin: 8px 0 0 0 !important;
}
.storefront-meta-row span, .storefront-meta-row a {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    color: #667085 !important;
    text-decoration: none !important;
    font-weight: 500 !important;
}
.storefront-meta-row a:hover {
    color: #b3261e !important;
}
.storefront-meta-row i, .storefront-meta-row-secondary i {
    color: #ef6b2e !important;
}
.menu-items-grid {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 20px !important;
}
.menu-item {
    display: flex !important;
    flex-direction: row !important;
    align-items: flex-start !important;
    background: #ffffff !important;
    border: 1px solid #efddcd !important;
    border-radius: 12px !important;
    padding: 16px !important;
    transition: all 0.2s ease !important;
    position: relative !important;
    box-shadow: none !important;
    overflow: hidden !important;
}
.menu-item:hover {
    border-color: #ef6b2e !important;
    background: #fffdfb !important;
}
.menu-item .item-image {
    width: 110px !important;
    height: 110px !important;
    flex-shrink: 0 !important;
    border-radius: 8px !important;
    overflow: hidden !important;
    margin-right: 16px !important;
    position: relative !important;
    order: -1 !important;
}
.menu-item .item-image img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
}
.menu-item .item-content {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 0 !important;
    text-align: left !important;
}
.menu-item .item-content h3 {
    font-family: 'Outfit', sans-serif !important;
    font-weight: 800 !important;
    font-size: 1.1rem !important;
    color: #171922 !important;
    margin: 0 0 6px 0 !important;
}
.menu-item .item-description {
    font-size: 0.85rem !important;
    color: #667085 !important;
    line-height: 1.4 !important;
    margin: 0 0 10px 0 !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.menu-item .item-card-bottom {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-top: auto !important;
    width: 100% !important;
}
.menu-item .panda-price-pill {
    font-weight: 800 !important;
    color: #b3261e !important;
    font-size: 1.05rem !important;
    background: none !important;
    padding: 0 !important;
    border: none !important;
}
.menu-item .panda-quick-add-btn {
    border-radius: 8px !important;
    background: #b3261e !important;
    color: #fff !important;
    border: none !important;
    padding: 8px 12px !important;
    font-size: 0.85rem !important;
}
.menu-item .panda-quick-add-btn:hover {
    background: #ef6b2e !important;
}
@media (max-width: 768px) {
    .menu-items-grid {
        grid-template-columns: 1fr !important;
        gap: 16px !important;
    }
    .storefront-copy h1 {
        font-size: 1.8rem !important;
    }
}

.cart-item {
    display: flex !important;
    align-items: center !important;
    gap: 0 !important;
    padding: 16px 0 !important;
    border-bottom: 1px solid #efddcd !important;
}
.cart-overlay {
    background-color: rgba(0, 0, 0, 0.04) !important;
}
.cart-sidebar {
    border-left: 1px solid #efddcd !important;
    box-shadow: -8px 0 32px rgba(42, 33, 29, 0.08) !important;
}
#floatingCartBtn {
    display: none !important;
}
.swal2-container {
    z-index: 99999 !important;
}
.menu-section {
    overflow: visible !important;
}
.panda-menu-sticky-bar {
    position: sticky !important;
    top: var(--site-header-offset, 72px) !important;
    z-index: 999 !important;
}
@media (min-width: 993px) {
    #backToTopBtn {
        z-index: 1050 !important;
    }
}

/* ==========================================================================
   STOREFRONT MENU PAGE DARK MODE THEME ENGINE
   ========================================================================== */
body.dark-mode,
html.dark-mode,
body.dark-mode .storefront-header,
body.dark-mode .menu-section,
body.dark-mode .menu-container {
    background: #0f172a !important;
    background-color: #0f172a !important;
    color: #f8fafc !important;
}

body.dark-mode .store-breadcrumb a,
body.dark-mode .store-breadcrumb span {
    color: #94a3b8 !important;
}
body.dark-mode .store-breadcrumb .is-current {
    color: #f8fafc !important;
}

body.dark-mode .storefront-copy h1 {
    color: #f8fafc !important;
}
body.dark-mode .storefront-categories {
    color: #cbd5e1 !important;
}
body.dark-mode .storefront-subtitle {
    color: #94a3b8 !important;
}
body.dark-mode .storefront-meta-row span,
body.dark-mode .storefront-meta-row a {
    color: #cbd5e1 !important;
}
body.dark-mode .storefront-meta-row a:hover {
    color: #ffffff !important;
}

/* Store Deals Box */
body.dark-mode .store-deals-strip {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}
body.dark-mode .store-deals-strip h3 {
    color: #f8fafc !important;
}
body.dark-mode .store-deals-strip p {
    color: #94a3b8 !important;
}
body.dark-mode .store-deals-strip [style*="background: #f8f9fa"],
body.dark-mode .store-deals-strip [style*="background:#f8f9fa"] {
    background: #111827 !important;
    border-color: #334155 !important;
}
body.dark-mode .store-deals-strip [style*="color: #344054"],
body.dark-mode .store-deals-strip [style*="color:#344054"] {
    color: #f8fafc !important;
}
body.dark-mode .store-deals-strip button {
    background: #1e293b !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}

/* Foodpanda Sticky Category Nav Bar */
body.dark-mode .panda-menu-sticky-bar {
    background: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35) !important;
}
body.dark-mode .panda-menu-search-box {
    background: #0f172a !important;
    border-color: #334155 !important;
}
body.dark-mode .panda-menu-search-box input {
    color: #f8fafc !important;
}
body.dark-mode .panda-cat-arrow {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}
body.dark-mode .panda-cat-arrow:hover {
    background: #334155 !important;
    color: #ffffff !important;
}
body.dark-mode .panda-cat-tab {
    color: #94a3b8 !important;
}
body.dark-mode .panda-cat-tab:hover {
    color: #ffffff !important;
}
body.dark-mode .panda-cat-tab.active {
    color: #ef4444 !important;
    border-bottom-color: #ef4444 !important;
}
body.dark-mode .panda-cat-count {
    color: #64748b !important;
}
body.dark-mode .panda-cat-tab.active .panda-cat-count {
    color: #ef4444 !important;
}
body.dark-mode .panda-menu-filter-select {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

/* Menu Category Titles & Product Cards */
body.dark-mode .category-title {
    color: #f8fafc !important;
}
body.dark-mode .menu-item {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}
body.dark-mode .menu-item:hover {
    border-color: #b3261e !important;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.4) !important;
}
body.dark-mode .menu-item h3,
body.dark-mode .menu-item-title {
    color: #f8fafc !important;
}
body.dark-mode .menu-item p,
body.dark-mode .menu-item-desc {
    color: #94a3b8 !important;
}
body.dark-mode .menu-item-price {
    color: #ef4444 !important;
}

/* Empty State / Storefront Update Card */
body.dark-mode [style*="Storefront update"],
body.dark-mode div[style*="Storefront update"] {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}
body.dark-mode .empty-menu-container,
body.dark-mode .empty-cart-message {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

/* Right Cart Sidebar in Dark Mode */
body.dark-mode .cart-sidebar,
body.dark-mode .panda-cart-sidebar,
body.dark-mode .cart-container {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4) !important;
}
body.dark-mode .cart-sidebar h3,
body.dark-mode .cart-sidebar h4,
body.dark-mode .cart-sidebar strong {
    color: #f8fafc !important;
}
body.dark-mode .cart-sidebar p,
body.dark-mode .cart-sidebar span {
    color: #cbd5e1 !important;
}
body.dark-mode .delivery-pickup-toggle,
body.dark-mode .order-type-switch {
    background: #0f172a !important;
    border: 1px solid #334155 !important;
}
body.dark-mode .toggle-btn,
body.dark-mode .order-type-btn {
    color: #94a3b8 !important;
}
body.dark-mode .toggle-btn.active,
body.dark-mode .order-type-btn.active {
    background: #1e293b !important;
    color: #ef4444 !important;
    border: 1px solid #334155 !important;
}
body.dark-mode .cart-item-row {
    border-color: #334155 !important;
}
body.dark-mode .cart-summary-line {
    border-color: #334155 !important;
}

/* Modals in Dark Mode */
body.dark-mode .preview-modal-content,
body.dark-mode .product-preview-modal .preview-modal-content {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    color: #f8fafc !important;
}
body.dark-mode .preview-modal-content h2,
body.dark-mode .preview-modal-content h3,
body.dark-mode .preview-modal-content h4 {
    color: #f8fafc !important;
}
body.dark-mode .preview-modal-content p,
body.dark-mode .preview-modal-content span {
    color: #cbd5e1 !important;
}
body.dark-mode .store-review-item {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}
body.dark-mode .store-review-filter-btn {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
body.dark-mode .store-review-filter-btn.active {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}
</style>

<?php include 'includes/footer.php'; ?>

