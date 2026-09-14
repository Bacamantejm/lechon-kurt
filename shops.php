<?php
session_start();
require_once 'includes/config.php';
require_once __DIR__ . '/includes/favorites_helper.php';
require_once __DIR__ . '/includes/store_availability_helper.php';

$current_page = 'shops';
$page_title = 'Lechon & Meat Supply Shops';

$current_user_address = '';
if (!empty($_SESSION['user_id'])) {
    $stmt = mysqli_prepare($conn, "SELECT address FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $current_user_address = trim((string)($row['address'] ?? ''));
        if ($result) mysqli_free_result($result);
        mysqli_stmt_close($stmt);
    }
}

$favorite_store_keys = [];
if (favoritesIsCustomerUserSession()) {
    $favorite_store_keys = favoritesFetchUserFavoriteStoreKeyMap($conn, (int)$_SESSION['user_id']);
}

function shopsGetCityDefaultCoords(string $city_or_address): array {
    $haystack = strtolower(trim($city_or_address));
    if (strpos($haystack, 'dasma') !== false || strpos($haystack, 'salawag') !== false || strpos($haystack, 'paliparan') !== false) {
        return ['lat' => 14.3294, 'lng' => 120.9367];
    }
    if (strpos($haystack, 'bacoor') !== false || strpos($haystack, 'habay') !== false || strpos($haystack, 'molino') !== false) {
        return ['lat' => 14.4445, 'lng' => 120.9439];
    }
    if (strpos($haystack, 'imus') !== false || strpos($haystack, 'anabu') !== false || strpos($haystack, 'poblacion') !== false) {
        return ['lat' => 14.4296, 'lng' => 120.9367];
    }
    if (strpos($haystack, 'tagaytay') !== false) {
        return ['lat' => 14.1153, 'lng' => 120.9621];
    }
    if (strpos($haystack, 'trias') !== false || strpos($haystack, 'manggahan') !== false || strpos($haystack, 'gentri') !== false) {
        return ['lat' => 14.2818, 'lng' => 120.8800];
    }
    if (strpos($haystack, 'silang') !== false) {
        return ['lat' => 14.2307, 'lng' => 120.9749];
    }
    if (strpos($haystack, 'trece') !== false) {
        return ['lat' => 14.2820, 'lng' => 120.8670];
    }
    if (strpos($haystack, 'kawit') !== false) {
        return ['lat' => 14.4450, 'lng' => 120.9020];
    }
    if (strpos($haystack, 'rosario') !== false) {
        return ['lat' => 14.4167, 'lng' => 120.8500];
    }
    if (strpos($haystack, 'tanza') !== false) {
        return ['lat' => 14.3940, 'lng' => 120.8540];
    }
    if (strpos($haystack, 'naic') !== false) {
        return ['lat' => 14.3167, 'lng' => 120.7667];
    }
    if (strpos($haystack, 'carmona') !== false) {
        return ['lat' => 14.3167, 'lng' => 121.0500];
    }
    return ['lat' => 14.3294, 'lng' => 120.9367];
}

// Fetch real store branches from store_locations table
$shops = [];

$branch_sql = "SELECT store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, latitude, longitude, is_active FROM store_locations WHERE is_active = 1 ORDER BY store_name ASC";
$branch_res = @mysqli_query($conn, $branch_sql);
if ($branch_res) {
    while ($row = mysqli_fetch_assoc($branch_res)) {
        $store_id = (int)$row['store_id'];
        
        $min_price_sql = "SELECT MIN(price) AS min_price FROM products WHERE is_archived = 0 AND is_active = 1" . ($row['owner_user_id'] > 0 ? " AND seller_id = " . (int)$row['owner_user_id'] : "");
        $min_res = @mysqli_query($conn, $min_price_sql);
        $min_row = $min_res ? mysqli_fetch_assoc($min_res) : null;
        $start_price = (!empty($min_row['min_price']) && (float)$min_row['min_price'] > 0) ? (float)$min_row['min_price'] : 35.00;

        $city_label = !empty($row['city']) ? trim($row['city']) : 'Branch Store';
        $location_display = !empty($row['address']) ? trim($row['address']) : $city_label;

        $lat = isset($row['latitude']) && $row['latitude'] !== null && (float)$row['latitude'] != 0 ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) && $row['longitude'] !== null && (float)$row['longitude'] != 0 ? (float)$row['longitude'] : null;
        if ($lat === null || $lng === null) {
            $fallback = shopsGetCityDefaultCoords($location_display . ' ' . $city_label);
            $lat = $fallback['lat'];
            $lng = $fallback['lng'];
        }

        $shops[] = [
            'key' => 'branch-' . $store_id,
            'name' => trim((string)$row['store_name']),
            'type' => 'Pickup Branch',
            'cat_key' => 'branch',
            'summary' => 'Official branch location offering pickup & delivery: ' . $location_display,
            'location' => $city_label,
            'city' => $city_label,
            'start_price' => $start_price,
            'rating' => 4.9,
            'reviews' => 12,
            'is_open' => true,
            'has_vouchers' => true,
            'latitude' => $lat,
            'longitude' => $lng,
            'image' => 'images/store-bg.jpg',
            'menu_link' => 'menu.php?branch_id=' . $store_id,
            'tags' => ['branch', strtolower($row['store_name']), strtolower($city_label)]
        ];
    }
    @mysqli_free_result($branch_res);
}

// Fetch approved organization partner sellers from users & franchise_applications
$latest_approved_partner_sql = "SELECT fa1.* FROM franchise_applications fa1 INNER JOIN (SELECT user_id, MAX(id) AS latest_id FROM franchise_applications WHERE status = 'approved' GROUP BY user_id) latest ON latest.latest_id = fa1.id";
$seller_sql = "SELECT u.id, u.full_name, u.email, u.phone, u.address, u.business_name, u.business_type, u.business_logo, u.profile_image, fa.business_address, fa.city_name, sl.latitude, sl.longitude
              FROM users u
              INNER JOIN ({$latest_approved_partner_sql}) fa ON fa.user_id = u.id
              LEFT JOIN store_locations sl ON sl.owner_user_id = u.id AND sl.is_active = 1
              WHERE u.account_type = 'organization' AND u.is_active = 1
              ORDER BY COALESCE(NULLIF(TRIM(u.business_name), ''), u.full_name) ASC";
$seller_res = @mysqli_query($conn, $seller_sql);
if ($seller_res) {
    while ($row = mysqli_fetch_assoc($seller_res)) {
        $seller_id = (int)$row['id'];
        $name = trim((string)$row['business_name']) !== '' ? trim((string)$row['business_name']) : trim((string)$row['full_name']) . ' Store';
        $city = !empty($row['city_name']) ? trim($row['city_name']) : (!empty($row['address']) ? trim($row['address']) : 'Partner Store');

        $min_price_sql = "SELECT MIN(price) AS min_price FROM products WHERE seller_id = " . $seller_id . " AND is_archived = 0 AND is_active = 1";
        $min_res = @mysqli_query($conn, $min_price_sql);
        $min_row = $min_res ? mysqli_fetch_assoc($min_res) : null;
        $start_price = (!empty($min_row['min_price']) && (float)$min_row['min_price'] > 0) ? (float)$min_row['min_price'] : 50.00;

        $lat = isset($row['latitude']) && $row['latitude'] !== null && (float)$row['latitude'] != 0 ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) && $row['longitude'] !== null && (float)$row['longitude'] != 0 ? (float)$row['longitude'] : null;
        if ($lat === null || $lng === null) {
            $fallback = shopsGetCityDefaultCoords($city . ' ' . ($row['business_address'] ?? '') . ' ' . ($row['address'] ?? ''));
            $lat = $fallback['lat'];
            $lng = $fallback['lng'];
        }

        $shops[] = [
            'key' => 'seller-' . $seller_id,
            'name' => $name,
            'type' => !empty($row['business_type']) ? ucwords(str_replace('_', ' ', $row['business_type'])) : 'Partner Store',
            'cat_key' => 'partner',
            'summary' => 'Verified partner vendor store offering fresh lechon and specialty menu orders.',
            'location' => $city,
            'city' => $city,
            'start_price' => $start_price,
            'rating' => 4.8,
            'reviews' => 15,
            'is_open' => true,
            'has_vouchers' => true,
            'latitude' => $lat,
            'longitude' => $lng,
            'image' => !empty($row['business_logo']) ? $row['business_logo'] : (!empty($row['profile_image']) ? $row['profile_image'] : 'images/store-bg.jpg'),
            'menu_link' => 'menu.php?seller_id=' . $seller_id,
            'tags' => ['partner', strtolower($name), strtolower($city)]
        ];
    }
    @mysqli_free_result($seller_res);
}

// Fetch real products for horizontal spotlight swimlane
$spotlight_products = [];
$prod_sql = "SELECT id, product_id, seller_id, name, description, price, category, image, avg_rating, review_count FROM products WHERE is_archived = 0 AND is_active = 1 ORDER BY id ASC";
$prod_res = @mysqli_query($conn, $prod_sql);
if ($prod_res) {
    while ($p = mysqli_fetch_assoc($prod_res)) {
        $price = (float)$p['price'];
        $orig_price = round($price * 1.08, 2);
        $img = !empty($p['image']) ? $p['image'] : 'images/store-bg.jpg';
        $cat_key = strtolower(preg_replace('/[^a-z0-9]+/', '_', trim((string)$p['category'])));
        
        $seller_id = (int)$p['seller_id'];
        $menu_target = 'menu.php?' . ($seller_id > 0 ? 'seller_id=' . $seller_id : 'branch_id=1') . '&product_id=' . (int)$p['id'];

        $spotlight_products[] = [
            'id' => (int)$p['id'],
            'seller_id' => $seller_id,
            'menu_link' => $menu_target,
            'name' => trim((string)$p['name']),
            'price' => $price,
            'orig_price' => $orig_price,
            'discount' => '7% off',
            'image' => $img,
            'cat_key' => $cat_key,
            'category' => trim((string)$p['category'])
        ];
    }
    @mysqli_free_result($prod_res);
}

// Fetch distinct real categories from products table
$real_categories = [];
$cat_sql = "SELECT DISTINCT category FROM products WHERE is_archived = 0 AND is_active = 1 AND category IS NOT NULL AND TRIM(category) <> '' ORDER BY category ASC";
$cat_res = @mysqli_query($conn, $cat_sql);
if ($cat_res) {
    while ($crow = mysqli_fetch_assoc($cat_res)) {
        $cat_name = trim((string)$crow['category']);
        $cat_key = strtolower(preg_replace('/[^a-z0-9]+/', '_', $cat_name));
        $real_categories[] = [
            'key' => $cat_key,
            'name' => $cat_name
        ];
    }
    @mysqli_free_result($cat_res);
}

// Featured spotlight store (First available real store or fallback to first branch)
$featured_shop = !empty($shops) ? $shops[0] : [
    'name' => 'Main Branch Store',
    'location' => 'Branch Location',
    'menu_link' => 'menu.php'
];

require_once 'includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
/* CSS Styles for Foodpanda Shops Explorer & Cards */
.market-home {
    background: #fff9f2;
    padding-top: 24px;
    padding-bottom: 60px;
}

.market-explorer {
    display: grid !important;
    grid-template-columns: 280px minmax(0, 1fr) !important;
    gap: 28px !important;
    align-items: start !important;
}

.market-sidebar {
    position: sticky !important;
    top: 112px !important;
    max-height: calc(100vh - 130px) !important;
    overflow-y: auto !important;
    align-self: start !important;
    z-index: 100 !important;
    padding: 20px 14px 20px 20px !important;
    border-radius: 24px !important;
    background: #ffffff !important;
    border: 1px solid #efddcd !important;
    box-shadow: 0 10px 28px rgba(74, 32, 20, 0.05) !important;
    scrollbar-width: thin !important;
    scrollbar-color: #94a3b8 #f1f5f9 !important;
}

.market-sidebar::-webkit-scrollbar {
    width: 6px !important;
}

.market-sidebar::-webkit-scrollbar-track {
    background: #f1f5f9 !important;
    border-radius: 999px !important;
}

.market-sidebar::-webkit-scrollbar-thumb {
    background: #94a3b8 !important;
    border-radius: 999px !important;
}

.market-sidebar::-webkit-scrollbar-thumb:hover {
    background: #64748b !important;
}

.market-sidebar h3 {
    font-family: 'Outfit', sans-serif !important;
    font-size: 1.25rem !important;
    font-weight: 800 !important;
    color: #171922 !important;
    margin: 0 0 16px 0 !important;
}

.market-sidebar h4 {
    font-family: 'Outfit', sans-serif !important;
    font-size: 0.98rem !important;
    font-weight: 700 !important;
    color: #171922 !important;
    margin: 0 0 12px 0 !important;
}

.market-sidebar-section + .market-sidebar-section {
    margin-top: 20px !important;
    padding-top: 20px !important;
    border-top: 1px solid #efddcd !important;
}

.market-radio-list,
.market-check-list {
    display: grid !important;
    gap: 12px !important;
}

.market-radio,
.market-check {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    font-weight: 600 !important;
    color: #171922 !important;
    font-size: 0.88rem !important;
    cursor: pointer !important;
}

.market-radio input,
.market-check input {
    width: 18px !important;
    height: 18px !important;
    accent-color: #b3261e !important;
    cursor: pointer !important;
}

/* Card Links and Grid */
.panda-card-link {
    text-decoration: none !important;
    color: inherit !important;
    cursor: pointer !important;
}

.market-store-list,
.store-list-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)) !important;
    gap: 28px 22px !important;
}

.market-store-row {
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
    border: none !important;
    border-radius: 16px !important;
    padding: 0 !important;
    overflow: visible !important;
    box-shadow: none !important;
    transition: transform 0.22s ease !important;
    text-decoration: none !important;
    color: inherit !important;
}

.market-store-row:hover {
    transform: translateY(-3px) !important;
    box-shadow: none !important;
}

.store-card-image-wrap {
    position: relative !important;
    width: 100% !important;
    height: 165px !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    background: #e2e8f0 !important;
    margin-bottom: 8px !important;
}

.market-store-row-thumb {
    width: 100% !important;
    height: 100% !important;
    background-size: cover !important;
    background-position: center !important;
    border-radius: 16px !important;
    transition: transform 0.35s cubic-bezier(0.2, 0, 0, 1) !important;
}

.market-store-row:hover .market-store-row-thumb {
    transform: scale(1.04) !important;
}

.market-type-pill {
    position: absolute !important;
    left: 10px !important;
    top: 10px !important;
    z-index: 5 !important;
    background: rgba(17, 24, 39, 0.75) !important;
    backdrop-filter: blur(4px) !important;
    color: #ffffff !important;
    font-size: 0.68rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    font-weight: 700 !important;
    letter-spacing: 0.2px !important;
}

.market-ad-pill {
    position: absolute !important;
    right: 10px !important;
    bottom: 10px !important;
    z-index: 5 !important;
    background: rgba(17, 24, 39, 0.65) !important;
    backdrop-filter: blur(4px) !important;
    color: #ffffff !important;
    font-size: 0.65rem !important;
    padding: 2px 6px !important;
    border-radius: 4px !important;
    font-weight: 600 !important;
}

.market-store-favorite-btn {
    position: absolute !important;
    top: 10px !important;
    right: 10px !important;
    z-index: 10 !important;
    width: 32px !important;
    height: 32px !important;
    background: rgba(255, 255, 255, 0.9) !important;
    backdrop-filter: blur(4px) !important;
    border: none !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
    cursor: pointer !important;
    color: #e11d48 !important;
    font-size: 0.85rem !important;
    transition: transform 0.2s ease, background 0.2s ease !important;
}

.market-store-favorite-btn:hover {
    transform: scale(1.12) !important;
    background: #ffffff !important;
}

.store-card-details {
    padding: 2px 2px 4px 2px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 3px !important;
}

.store-card-row-head {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    gap: 8px !important;
    margin-bottom: 2px !important;
}

.store-card-row-head h3 {
    margin: 0 !important;
    font-family: 'Outfit', sans-serif !important;
    font-size: 0.98rem !important;
    font-weight: 800 !important;
    color: #171922 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    flex: 1 !important;
    line-height: 1.3 !important;
}

.store-card-rating {
    font-size: 0.82rem !important;
    font-weight: 800 !important;
    color: #171922 !important;
    display: flex !important;
    align-items: center !important;
    gap: 3px !important;
    white-space: nowrap !important;
}

.store-card-meta-line {
    font-size: 0.8rem !important;
    color: #64748b !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.store-card-delivery-line {
    font-size: 0.78rem !important;
    color: #64748b !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}

.store-card-promo-line {
    font-size: 0.76rem !important;
    color: #e11d48 !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    gap: 5px !important;
    margin-top: 1px !important;
}

@media (max-width: 991px) {
    .market-explorer {
        grid-template-columns: 1fr !important;
    }
    .market-sidebar {
        position: static !important;
    }
}

/* ==========================================================================
   SHOPS EXPLORER DARK MODE THEME ENGINE
   ========================================================================== */
body.dark-mode .market-home,
html.dark-mode .market-home {
    background: #0f172a !important;
    background-color: #0f172a !important;
    color: #f8fafc !important;
}

/* Sidebar & Filters in Dark Mode */
body.dark-mode .market-sidebar {
    background: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4) !important;
    color: #f8fafc !important;
}

body.dark-mode .market-sidebar-section {
    border-color: #334155 !important;
}

body.dark-mode .market-sidebar h3,
body.dark-mode .market-sidebar h4 {
    color: #f8fafc !important;
}

body.dark-mode .market-radio,
body.dark-mode .market-check,
body.dark-mode .market-radio span,
body.dark-mode .market-check span {
    color: #cbd5e1 !important;
}

body.dark-mode .market-radio:hover span,
body.dark-mode .market-check:hover span {
    color: #ffffff !important;
}

/* Featured Shop Spotlight Box */
body.dark-mode #shopsMainSection [style*="background:#fff4f6"],
body.dark-mode #shopsMainSection [style*="background: #fff4f6"],
body.dark-mode .spotlight-card,
body.dark-mode .featured-shop-box {
    background: linear-gradient(135deg, #1e293b 0%, #283347 100%) !important;
    border-color: #334155 !important;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3) !important;
}

body.dark-mode .spotlight-card h2,
body.dark-mode [style*="background:#fff4f6"] h2,
body.dark-mode [style*="background: #fff4f6"] h2 {
    color: #f8fafc !important;
}

body.dark-mode .spotlight-card p,
body.dark-mode [style*="background:#fff4f6"] p,
body.dark-mode [style*="background: #fff4f6"] p {
    color: #94a3b8 !important;
}

body.dark-mode .swimlane-item-card {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25) !important;
}

body.dark-mode .swimlane-item-card:hover {
    border-color: #b3261e !important;
    transform: translateY(-2px);
}

body.dark-mode .swimlane-item-card h4 {
    color: #f8fafc !important;
}

body.dark-mode .swimlane-nav-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .swimlane-nav-btn:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .swimlane-add-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode [style*="background:#fff4f6"] a[style*="border:1px solid #171922"],
body.dark-mode [style*="background: #fff4f6"] a[style*="border:1px solid #171922"] {
    background: #1e293b !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}

/* Store Grid & Store Cards */
body.dark-mode .market-store-row,
body.dark-mode .shop-card-item {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    color: #f8fafc !important;
}

body.dark-mode .store-card-image-wrap {
    background: #1e293b !important;
}

body.dark-mode .store-card-row-head h3 {
    color: #f8fafc !important;
}

body.dark-mode .store-card-rating {
    color: #f8fafc !important;
}

body.dark-mode .store-card-meta-line,
body.dark-mode .store-card-delivery-line {
    color: #94a3b8 !important;
}

body.dark-mode .store-card-promo-line {
    color: #fb7185 !important;
}

body.dark-mode #shopsMainSection h2,
body.dark-mode #nearbySectionTitle,
body.dark-mode #otherSectionTitle {
    color: #f8fafc !important;
}

body.dark-mode #shopsCountLabel,
body.dark-mode #nearbyCountLabel,
body.dark-mode #otherCountLabel {
    color: #94a3b8 !important;
}

body.dark-mode #nearbyEmptyNotice {
    background: #1e293b !important;
    border-color: #334155 !important;
}

body.dark-mode [style*="border-top:1px solid #eaecf0"],
body.dark-mode [style*="border-top: 1px solid #eaecf0"] {
    border-top-color: #334155 !important;
}

body.dark-mode .market-time-pill {
    background: rgba(15, 23, 42, 0.9) !important;
    color: #ef4444 !important;
    border: 1px solid #334155 !important;
}

body.dark-mode .market-store-favorite-btn {
    background: #1e293b !important;
    color: #e11d48 !important;
    border: 1px solid #334155 !important;
}

/* Shops Map Banner & Popups Dark Mode */
body.dark-mode .shops-map-banner {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .shops-map-banner h2,
body.dark-mode .shops-map-banner h3 {
    color: #f8fafc !important;
}

body.dark-mode #userLocationBadge {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .leaflet-popup-content-wrapper,
body.dark-mode .leaflet-popup-tip {
    background: #1e293b !important;
    color: #f8fafc !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5) !important;
}

body.dark-mode .leaflet-popup-content strong {
    color: #f8fafc !important;
}

body.dark-mode .leaflet-popup-content div,
body.dark-mode .leaflet-popup-content span {
    color: #cbd5e1 !important;
}
</style>

<div class="market-home">
    <section class="market-section" id="shopsMainSection">
        <div class="container" style="max-width:1320px; margin:0 auto; padding:0 22px;">
            <div class="market-explorer">
                
                <!-- Left Sidebar Filters (Matching Delivery Page Layout) -->
                <aside class="market-sidebar">
                    <div class="market-sidebar-section">
                        <h3>Filters</h3>
                    </div>

                    <!-- Nearby Distance & City Filter -->
                    <div class="market-sidebar-section">
                        <h4>Location & City Scope</h4>
                        <div class="market-check-list">
                            <label class="market-check">
                                <input type="checkbox" id="filterCityOnly" checked>
                                <span id="filterCityLabel">Stores in my city only</span>
                            </label>
                            <label class="market-check">
                                <input type="checkbox" id="filterNearbyOnly">
                                <span>Nearby radius (&le; 15 km)</span>
                            </label>
                        </div>
                        <button type="button" class="market-btn" id="sidebarDetectLocationBtn" style="margin-top:10px; width:100%; min-height:36px; padding:0 12px; font-size:0.8rem; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; gap:6px; background:#ffffff; color:#b3261e; border:1px solid #b3261e; cursor:pointer; font-weight:700; transition:all 0.2s ease;">
                            <i class="fas fa-location-crosshairs"></i> Detect my location
                        </button>
                    </div>

                    <!-- Offers -->
                    <div class="market-sidebar-section">
                        <h4>Offers</h4>
                        <div class="market-check-list">
                            <label class="market-check">
                                <input type="checkbox" id="filterVouchers">
                                <span>Accepts vouchers</span>
                            </label>
                        </div>
                    </div>

                    <!-- Dynamic Shop Categories from DB -->
                    <?php if (!empty($real_categories)): ?>
                    <div class="market-sidebar-section">
                        <h4>Product categories</h4>
                        <div class="market-check-list">
                            <?php foreach ($real_categories as $rcat): ?>
                                <label class="market-check">
                                    <input type="checkbox" class="shop-type-check" value="<?php echo htmlspecialchars($rcat['key']); ?>">
                                    <span><?php echo htmlspecialchars($rcat['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Sort By -->
                    <div class="market-sidebar-section">
                        <h4>Sort by</h4>
                        <div class="market-radio-list">
                            <label class="market-radio"><input type="radio" name="shopSort" value="relevance" checked> <span>Relevance</span></label>
                            <label class="market-radio"><input type="radio" name="shopSort" value="fastest"> <span>Fastest delivery</span></label>
                            <label class="market-radio"><input type="radio" name="shopSort" value="distance"> <span>Distance</span></label>
                            <label class="market-radio"><input type="radio" name="shopSort" value="top_rated"> <span>Top rated</span></label>
                        </div>
                    </div>

                    <!-- Price Range -->
                    <div class="market-sidebar-section">
                        <h4>Price range</h4>
                        <div class="market-radio-list">
                            <label class="market-radio"><input type="radio" name="priceRange" value="all" checked> <span>All prices</span></label>
                            <label class="market-radio"><input type="radio" name="priceRange" value="under_300"> <span>Under PHP 300</span></label>
                            <label class="market-radio"><input type="radio" name="priceRange" value="300_1000"> <span>PHP 300 - PHP 1,000</span></label>
                            <label class="market-radio"><input type="radio" name="priceRange" value="above_1000"> <span>Above PHP 1,000</span></label>
                        </div>
                    </div>
                </aside>

                <!-- Right Content Area -->
                <div style="flex:1; min-width:0;">
                    
                    <!-- Interactive Live Store Finder & Nearby Map Banner (At the very top) -->
                    <div class="shops-map-banner" id="shopsMapBanner" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:20px; padding:22px; margin-bottom:28px; box-shadow:0 4px 16px rgba(15,23,42,0.04);">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:16px;">
                            <div>
                                <div style="display:inline-flex; align-items:center; gap:6px; background:#fff1f0; color:#b3261e; padding:4px 12px; border-radius:999px; font-size:0.78rem; font-weight:800; margin-bottom:6px; border:1px solid #fee4e2;">
                                    <i class="fas fa-map-location-dot"></i> Live Store Finder
                                </div>
                                <h2 style="margin:0; font-size:1.35rem; font-weight:800; color:#171922; font-family:'Outfit',sans-serif;">
                                    Shops Near You
                                </h2>
                            </div>
                            
                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                <div id="userLocationBadge" style="display:inline-flex; align-items:center; gap:8px; background:#f1f5f9; color:#475569; padding:6px 14px; border-radius:10px; font-size:0.8rem; font-weight:700; border:1px solid #e2e8f0;">
                                    <span class="user-pulse-dot" style="width:8px; height:8px; border-radius:50%; background:#2563eb; display:inline-block; box-shadow:0 0 0 3px rgba(37,99,235,0.25);"></span>
                                    <span id="userLocationText">Detecting your location...</span>
                                </div>
                                <button type="button" id="mapDetectLocationBtn" style="background:#b3261e; color:#ffffff; border:none; padding:7px 14px; border-radius:10px; font-size:0.8rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:background 0.2s ease;">
                                    <i class="fas fa-location-crosshairs"></i> Refresh GPS
                                </button>
                            </div>
                        </div>
                        
                        <div id="shopsNearbyMap" style="width:100%; height:360px; border-radius:16px; border:1px solid #cbd5e1; box-shadow:0 6px 20px rgba(15,23,42,0.06); z-index:1;"></div>
                    </div>

                    <!-- Section 1: Stores Near You (Only shows stores nearby the user's location) -->
                    <div class="shops-section-block" id="nearbyShopsBlock" style="margin-bottom:34px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:10px;">
                            <div>
                                <div style="display:inline-flex; align-items:center; gap:6px; background:#fff1f0; color:#b3261e; padding:4px 12px; border-radius:999px; font-size:0.78rem; font-weight:800; margin-bottom:6px; border:1px solid #fee4e2;">
                                    <i class="fas fa-location-arrow"></i> Nearby Delivery
                                </div>
                                <h2 style="font-size:1.45rem; font-weight:800; color:#171922; margin:0;" id="nearbySectionTitle">Stores Near You</h2>
                            </div>
                            <span style="font-size:0.88rem; color:#667085;" id="nearbyCountLabel">Showing nearby stores</span>
                        </div>

                        <!-- 3-Column Nearby Store Grid -->
                        <div class="market-store-list store-list-grid" id="nearbyShopsGrid"></div>

                        <!-- Nearby Empty Notice -->
                        <div id="nearbyEmptyNotice" style="display:none; padding:28px 20px; text-align:center; background:#ffffff; border:1px dashed #cbd5e1; border-radius:16px; margin-top:10px;">
                            <i class="fas fa-location-crosshairs" style="font-size:1.8rem; color:#b3261e; margin-bottom:8px; opacity:0.8;"></i>
                            <p style="color:#64748b; font-size:0.92rem; margin:0 0 6px; font-weight:600;">No stores found within immediate 15 km of your location.</p>
                            <span style="color:#b3261e; font-weight:700; font-size:0.85rem;">Check other Cavite store branches &amp; partner shops below!</span>
                        </div>
                    </div>

                    <!-- Section 2: Other Store Branches & Partner Vendors (Different Categories) -->
                    <div class="shops-section-block" id="otherShopsBlock" style="margin-top:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:10px; border-top:1px solid #eaecf0; padding-top:28px;">
                            <div>
                                <div style="display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; color:#475569; padding:4px 12px; border-radius:999px; font-size:0.78rem; font-weight:800; margin-bottom:6px; border:1px solid #e2e8f0;">
                                    <i class="fas fa-layer-group"></i> Store Categories &amp; Other Branches
                                </div>
                                <h2 style="font-size:1.35rem; font-weight:800; color:#171922; margin:0;" id="otherSectionTitle">Other Cavite Stores &amp; Partner Shops</h2>
                            </div>
                            <span style="font-size:0.88rem; color:#667085;" id="otherCountLabel">More stores in Cavite</span>
                        </div>

                        <!-- 3-Column Other Store Grid -->
                        <div class="market-store-list store-list-grid" id="otherShopsGrid">
                            <?php foreach ($shops as $index => $shop): ?>
                                <?php
                                $price_text = 'Starts at PHP ' . number_format((float)$shop['start_price'], 2);
                                $shop_key_value = (string)($shop['key'] ?? '');
                                $is_shop_favorite = !empty($favorite_store_keys[$shop_key_value]);
                                $city_label = !empty($shop['city']) ? $shop['city'] : $shop['location'];
                                ?>
                                <a href="<?php echo htmlspecialchars($shop['menu_link']); ?>"
                                   class="market-store-row panda-card-link shop-card-item"
                                   data-shop-key="<?php echo htmlspecialchars($shop_key_value); ?>"
                                   data-city="<?php echo htmlspecialchars(strtolower($city_label)); ?>"
                                   data-location="<?php echo htmlspecialchars(strtolower($shop['location'] ?? '')); ?>"
                                   data-cat="<?php echo htmlspecialchars($shop['cat_key']); ?>"
                                   data-vouchers="<?php echo !empty($shop['has_vouchers']) ? '1' : '0'; ?>"
                                   data-price="<?php echo (float)$shop['start_price']; ?>"
                                   data-rating="<?php echo (float)$shop['rating']; ?>"
                                   data-open="<?php echo !empty($shop['is_open']) ? '1' : '0'; ?>"
                                   data-lat="<?php echo isset($shop['latitude']) && $shop['latitude'] !== null ? htmlspecialchars((string)$shop['latitude']) : ''; ?>"
                                   data-lng="<?php echo isset($shop['longitude']) && $shop['longitude'] !== null ? htmlspecialchars((string)$shop['longitude']) : ''; ?>"
                                   data-search="<?php echo htmlspecialchars(strtolower($shop['name'] . ' ' . $shop['summary'] . ' ' . $city_label . ' ' . implode(' ', $shop['tags']))); ?>">
                                    
                                    <!-- Image Wrapper -->
                                    <div class="store-card-image-wrap">
                                        <div class="market-store-row-thumb" style="background-image:url('<?php echo htmlspecialchars($shop['image']); ?>');"></div>
                                        <span class="market-type-pill"><?php echo htmlspecialchars($shop['type']); ?></span>
                                        <span class="market-ad-pill"><?php echo htmlspecialchars($city_label); ?></span>
                                        <button
                                            type="button"
                                            class="market-store-favorite-btn<?php echo $is_shop_favorite ? ' is-active' : ''; ?>"
                                            data-favorite-toggle="1"
                                            data-favorite-type="store"
                                            data-favorite-store-key="<?php echo htmlspecialchars($shop_key_value); ?>"
                                            data-favorite-active="<?php echo $is_shop_favorite ? '1' : '0'; ?>"
                                            aria-pressed="<?php echo $is_shop_favorite ? 'true' : 'false'; ?>"
                                            title="<?php echo $is_shop_favorite ? 'Remove from favorites' : 'Save to favorites'; ?>">
                                            <i class="<?php echo $is_shop_favorite ? 'fas' : 'far'; ?> fa-heart"></i>
                                        </button>
                                    </div>

                                    <!-- Details Container (Minimalist Foodpanda Style) -->
                                    <div class="store-card-details">
                                        <!-- Line 1: Store Name & Rating -->
                                        <div class="store-card-row-head">
                                            <h3><?php echo htmlspecialchars($shop['name']); ?> <span style="font-weight:600; color:#64748b; font-size:0.86rem;">– <?php echo htmlspecialchars($city_label); ?></span></h3>
                                            <span class="store-card-rating">
                                                <i class="fas fa-star" style="color:#ef6b2e; font-size:0.75rem;"></i> <?php echo number_format((float)$shop['rating'], 1); ?>
                                                <span style="font-size:0.72rem; color:#94a3b8; font-weight:500;">(<?php echo (int)$shop['reviews']; ?>+)</span>
                                            </span>
                                        </div>
                                        
                                        <!-- Line 2: ETA • Distance • Cuisine -->
                                        <div class="store-card-meta-line">
                                            <span data-role="eta-text">From 15 min</span>
                                            <span>•</span>
                                            <span data-role="distance-text">Near Cavite</span>
                                            <span>•</span>
                                            <span>Lechon &amp; Specialty</span>
                                        </div>

                                        <!-- Line 3: Delivery Deal Line -->
                                        <div class="store-card-delivery-line">
                                            <i class="fas fa-motorcycle" style="color:#94a3b8; font-size:0.75rem;"></i>
                                            <span style="text-decoration:line-through; color:#94a3b8;">₱49</span>
                                            <span style="color:#b3261e; font-weight:700;">Free for first order</span>
                                        </div>

                                        <!-- Line 4: Pricing / Discount Promo Line -->
                                        <div class="store-card-promo-line">
                                            <i class="fas fa-tag" style="font-size:0.7rem;"></i>
                                            <span><?php echo htmlspecialchars($price_text); ?></span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Zero State Container -->
                    <div id="shopsZeroState" style="display:none; text-align:center; padding:60px 20px; background:#ffffff; border:1px solid #efddcd; border-radius:20px; margin-top:20px;">
                        <i class="fas fa-store-slash" style="font-size:3rem; color:#b3261e; margin-bottom:12px; opacity:0.6;"></i>
                        <h3 style="font-size:1.25rem; font-weight:800; color:#171922; margin:0 0 6px;">No shops found</h3>
                        <p style="color:#667085; margin:0;">Try clearing some filters or searching for another keyword.</p>
                    </div>

                </div>
            </div>
        </div>
    </section>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
window.PHP_USER_ADDRESS = <?php echo json_encode($current_user_address); ?>;
document.addEventListener('DOMContentLoaded', function () {
    const filterCityOnly = document.getElementById('filterCityOnly');
    const filterCityLabel = document.getElementById('filterCityLabel');
    const filterNearbyOnly = document.getElementById('filterNearbyOnly');
    const filterVouchers = document.getElementById('filterVouchers');
    const shopTypeChecks = document.querySelectorAll('.shop-type-check');
    const sortRadios = document.querySelectorAll('input[name="shopSort"]');
    const priceRadios = document.querySelectorAll('input[name="priceRange"]');
    const headerSearch = document.getElementById('marketHeaderSearch');
    const shopCards = document.querySelectorAll('.shop-card-item');
    const swimlaneCards = document.querySelectorAll('.swimlane-item-card');
    const zeroState = document.getElementById('shopsZeroState');
    const countLabel = document.getElementById('shopsCountLabel');
    const shopsGrid = document.getElementById('shopsGrid');
    const userLocationText = document.getElementById('userLocationText');
    const mapDetectLocationBtn = document.getElementById('mapDetectLocationBtn');
    const sidebarDetectLocationBtn = document.getElementById('sidebarDetectLocationBtn');

    // Default coordinates: Dasmariñas / Cavite Center
    let currentUserLat = 14.3294;
    let currentUserLng = 120.9367;
    let userLocationLabel = 'Dasmariñas, Cavite';
    let detectedCity = { key: 'dasmarinas', name: 'Dasmariñas' };
    let isUserLocationAccurate = false;
    let leafletMap = null;
    let userMarker = null;
    const storeMarkers = [];

    function toRadians(deg) { return deg * (Math.PI / 180); }
    function haversineKm(lat1, lng1, lat2, lng2) {
        const R = 6371;
        const dLat = toRadians(lat2 - lat1);
        const dLng = toRadians(lng2 - lng1);
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
    }

    function normalizeCityString(str) {
        if (!str) return '';
        return str.toLowerCase()
            .replace(/ñ/g, 'n')
            .replace(/city of /g, '')
            .replace(/[^a-z0-9]/g, '');
    }

    function detectCityFromTextOrCoords(text, lat, lng) {
        const norm = normalizeCityString(text);
        if (norm.includes('dasma') || norm.includes('salawag') || norm.includes('burol') || norm.includes('langkaan') || norm.includes('paliparan') || norm.includes('sampaloc')) {
            return { key: 'dasmarinas', name: 'Dasmariñas' };
        }
        if (norm.includes('bacoor') || norm.includes('habay') || norm.includes('molino') || norm.includes('zapote')) {
            return { key: 'bacoor', name: 'Bacoor' };
        }
        if (norm.includes('imus') || norm.includes('poblacion') || norm.includes('anabu') || norm.includes('buhay na tubig')) {
            return { key: 'imus', name: 'Imus' };
        }
        if (norm.includes('tagaytay') || norm.includes('maharlika') || norm.includes('kaybagal')) {
            return { key: 'tagaytay', name: 'Tagaytay' };
        }
        if (norm.includes('silang') || norm.includes('biga') || norm.includes('bulihan')) {
            return { key: 'silang', name: 'Silang' };
        }
        if (norm.includes('general trias') || norm.includes('gen. trias') || norm.includes('manggahan') || norm.includes('gentri') || norm.includes('trias')) {
            return { key: 'general trias', name: 'General Trias' };
        }
        if (norm.includes('trece') || norm.includes('martires')) {
            return { key: 'trece', name: 'Trece Martires' };
        }
        if (norm.includes('kawit')) {
            return { key: 'kawit', name: 'Kawit' };
        }
        if (norm.includes('rosario')) {
            return { key: 'rosario', name: 'Rosario' };
        }
        if (norm.includes('tanza')) {
            return { key: 'tanza', name: 'Tanza' };
        }
        if (norm.includes('naic')) {
            return { key: 'naic', name: 'Naic' };
        }
        if (norm.includes('carmona')) {
            return { key: 'carmona', name: 'Carmona' };
        }
        if (norm.includes('noveleta')) {
            return { key: 'noveleta', name: 'Noveleta' };
        }
        if (norm.includes('cavite city')) {
            return { key: 'cavite city', name: 'Cavite City' };
        }
        if (norm.includes('alvarez') || norm.includes('gma')) {
            return { key: 'gma', name: 'GMA' };
        }

        // Check GPS coordinates bounding boxes if coordinates are provided
        if (Number.isFinite(lat) && Number.isFinite(lng) && lat !== 0 && lng !== 0) {
            // Dasmariñas bounds
            if (lat >= 14.26 && lat <= 14.38 && lng >= 120.90 && lng <= 120.99) {
                return { key: 'dasmarinas', name: 'Dasmariñas' };
            }
            // Bacoor bounds
            if (lat >= 14.39 && lat <= 14.48 && lng >= 120.91 && lng <= 120.99) {
                return { key: 'bacoor', name: 'Bacoor' };
            }
            // Imus bounds
            if (lat >= 14.38 && lat <= 14.45 && lng >= 120.88 && lng <= 120.96) {
                return { key: 'imus', name: 'Imus' };
            }
            // Silang bounds
            if (lat >= 14.18 && lat <= 14.26 && lng >= 120.93 && lng <= 121.01) {
                return { key: 'silang', name: 'Silang' };
            }
            // Gen Trias bounds
            if (lat >= 14.25 && lat <= 14.34 && lng >= 120.85 && lng <= 120.92) {
                return { key: 'general trias', name: 'General Trias' };
            }
            // Tagaytay bounds
            if (lat >= 14.07 && lat <= 14.17 && lng >= 120.88 && lng <= 121.02) {
                return { key: 'tagaytay', name: 'Tagaytay' };
            }
        }
        return null;
    }

    // Read stored address payload from navbar location picker or user profile
    function readStoredNavbarLocation() {
        try {
            const raw = localStorage.getItem('market_address_payload');
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed) {
                    const plat = parseFloat(parsed.latitude);
                    const plng = parseFloat(parsed.longitude);
                    const addrText = parsed.display_address || parsed.city || parsed.address || '';
                    if (Number.isFinite(plat) && Number.isFinite(plng) && plat !== 0 && plng !== 0) {
                        currentUserLat = plat;
                        currentUserLng = plng;
                    }
                    if (addrText) {
                        userLocationLabel = addrText;
                    }
                    isUserLocationAccurate = true;

                    const detected = detectCityFromTextOrCoords(addrText, currentUserLat, currentUserLng);
                    if (detected) {
                        detectedCity = detected;
                    }
                    return true;
                }
            }
        } catch (e) {}

        // Fallback to PHP user address if available
        if (window.PHP_USER_ADDRESS && window.PHP_USER_ADDRESS.trim() !== '') {
            userLocationLabel = window.PHP_USER_ADDRESS.trim();
            const detected = detectCityFromTextOrCoords(userLocationLabel, currentUserLat, currentUserLng);
            if (detected) {
                detectedCity = detected;
            }
            return true;
        }

        return false;
    }

    // Initialize Leaflet Map
    function initShopsMap() {
        const mapContainer = document.getElementById('shopsNearbyMap');
        if (!mapContainer || typeof L === 'undefined') return;

        leafletMap = L.map('shopsNearbyMap', {
            zoomControl: true,
            scrollWheelZoom: false
        }).setView([currentUserLat, currentUserLng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(leafletMap);

        updateUserMapMarker();
        renderStoreMapMarkers();
    }

    function updateUserMapMarker() {
        if (!leafletMap || typeof L === 'undefined') return;

        if (userMarker) {
            leafletMap.removeLayer(userMarker);
        }

        const userIcon = L.divIcon({
            className: 'user-radar-pin',
            html: `
                <div style="position:relative; width:28px; height:28px; display:flex; align-items:center; justify-content:center;">
                    <span style="position:absolute; width:100%; height:100%; border-radius:50%; background:rgba(37,99,235,0.4); animation:ping 1.6s cubic-bezier(0,0,0.2,1) infinite;"></span>
                    <div style="width:16px; height:16px; border-radius:50%; background:#2563eb; border:3px solid #ffffff; box-shadow:0 3px 10px rgba(0,0,0,0.35); z-index:2;"></div>
                </div>
            `,
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            popupAnchor: [0, -14]
        });

        userMarker = L.marker([currentUserLat, currentUserLng], { icon: userIcon, zIndexOffset: 1000 }).addTo(leafletMap);
        userMarker.bindPopup(`
            <div style="font-family:'Outfit',sans-serif; padding:4px;">
                <strong style="color:#2563eb; font-size:13px;"><i class="fas fa-location-crosshairs"></i> Your Location</strong>
                <div style="font-size:12px; color:#475569; margin-top:2px;">${userLocationLabel}</div>
                ${detectedCity ? `<div style="font-size:11px; font-weight:700; color:#b3261e; margin-top:4px;">Showing stores in ${detectedCity.name}</div>` : ''}
            </div>
        `);

        if (userLocationText) {
            const cityName = detectedCity ? detectedCity.name : 'Dasmariñas';
            userLocationText.textContent = isUserLocationAccurate ? (`${userLocationLabel} (${cityName})`) : `Location: ${cityName}`;
        }

        if (filterCityLabel && detectedCity) {
            filterCityLabel.textContent = `Stores in ${detectedCity.name} only`;
        }
    }

    function isStoreInCityScope(card, cityKey) {
        if (!cityKey) return true;
        const normKey = normalizeCityString(cityKey);
        const cardCity = normalizeCityString(card.dataset.city || '');
        const cardLoc = normalizeCityString(card.dataset.location || '');

        if (normKey.includes('dasma') || normKey.includes('salawag')) {
            return cardCity.includes('dasma') || cardCity.includes('salawag') ||
                   cardLoc.includes('dasma') || cardLoc.includes('salawag');
        }
        if (normKey.includes('bacoor')) {
            return cardCity.includes('bacoor') || cardLoc.includes('bacoor');
        }
        if (normKey.includes('imus')) {
            return cardCity.includes('imus') || cardLoc.includes('imus');
        }
        if (normKey.includes('tagaytay')) {
            return cardCity.includes('tagaytay') || cardLoc.includes('tagaytay');
        }
        if (normKey.includes('trias') || normKey.includes('gentri')) {
            return cardCity.includes('trias') || cardLoc.includes('trias') || cardCity.includes('gentri') || cardLoc.includes('gentri');
        }
        if (normKey.includes('silang')) {
            return cardCity.includes('silang') || cardLoc.includes('silang');
        }
        if (normKey.includes('trece')) {
            return cardCity.includes('trece') || cardLoc.includes('trece');
        }
        if (normKey.includes('kawit')) {
            return cardCity.includes('kawit') || cardLoc.includes('kawit');
        }
        if (normKey.includes('rosario')) {
            return cardCity.includes('rosario') || cardLoc.includes('rosario');
        }
        if (normKey.includes('tanza')) {
            return cardCity.includes('tanza') || cardLoc.includes('tanza');
        }
        if (normKey.includes('naic')) {
            return cardCity.includes('naic') || cardLoc.includes('naic');
        }
        if (normKey.includes('carmona')) {
            return cardCity.includes('carmona') || cardLoc.includes('carmona');
        }
        return cardCity.includes(normKey) || cardLoc.includes(normKey);
    }

    function renderStoreMapMarkers() {
        if (!leafletMap || typeof L === 'undefined') return;

        // Clear existing store markers
        storeMarkers.forEach(m => leafletMap.removeLayer(m));
        storeMarkers.length = 0;

        const bounds = [[currentUserLat, currentUserLng]];
        const isNearbyOnly = filterNearbyOnly ? filterNearbyOnly.checked : false;
        const isCityOnly = filterCityOnly ? filterCityOnly.checked : false;
        const targetCityKey = detectedCity ? detectedCity.key : 'dasmarinas';

        shopCards.forEach(function (card) {
            const storeLat = parseFloat(card.dataset.lat || '');
            const storeLng = parseFloat(card.dataset.lng || '');
            const distance = parseFloat(card.dataset.distance || '999');
            const storeTitle = card.querySelector('h3')?.textContent.trim() || 'Lechon Store';
            const storeType = card.querySelector('.market-type-pill')?.textContent.trim() || 'Store';
            const storeRating = card.dataset.rating || '4.9';
            const menuLink = card.getAttribute('href') || 'menu.php';

            if (!Number.isFinite(storeLat) || !Number.isFinite(storeLng) || storeLat === 0 || storeLng === 0) {
                return;
            }

            if (card.style.display === 'none') {
                return;
            }

            bounds.push([storeLat, storeLng]);

            const storeIcon = L.divIcon({
                className: 'store-leaflet-marker',
                html: `
                    <div style="background:#b3261e; color:#ffffff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #ffffff; box-shadow:0 4px 14px rgba(0,0,0,0.3); font-size:14px; cursor:pointer;">
                        <i class="fas fa-store"></i>
                    </div>
                `,
                iconSize: [34, 34],
                iconAnchor: [17, 34],
                popupAnchor: [0, -34]
            });

            const marker = L.marker([storeLat, storeLng], { icon: storeIcon }).addTo(leafletMap);
            marker.bindPopup(`
                <div style="font-family:'Outfit',sans-serif; padding:4px; min-width:170px;">
                    <span style="display:inline-block; font-size:10px; font-weight:800; color:#b3261e; background:#fff1f0; padding:2px 6px; border-radius:4px; margin-bottom:4px;">${storeType}</span>
                    <strong style="font-size:14px; color:#171922; display:block; line-height:1.3;">${storeTitle}</strong>
                    <div style="font-size:12px; color:#64748b; margin:4px 0 8px 0;">
                        <i class="fas fa-location-arrow" style="color:#b3261e;"></i> ${distance < 900 ? (distance.toFixed(1) + ' km away') : (card.dataset.city || 'Cavite')} &bull; ⭐ ${storeRating}
                    </div>
                    <a href="${menuLink}" style="background:#b3261e; color:#ffffff; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; display:inline-block;">View Store &amp; Order</a>
                </div>
            `);

            marker.on('click', function() {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.style.outline = '3px solid #b3261e';
                card.style.boxShadow = '0 0 20px rgba(179,38,30,0.35)';
                setTimeout(() => {
                    card.style.outline = '';
                    card.style.boxShadow = '';
                }, 2000);
            });

            storeMarkers.push(marker);
        });

        if (bounds.length > 1) {
            leafletMap.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
        } else {
            leafletMap.setView([currentUserLat, currentUserLng], 13);
        }
        leafletMap.invalidateSize();
    }

    function calculateShopDistances(lat, lng) {
        currentUserLat = lat;
        currentUserLng = lng;

        shopCards.forEach(function (card) {
            const storeLat = parseFloat(card.dataset.lat || '');
            const storeLng = parseFloat(card.dataset.lng || '');
            const timeLabel = card.querySelector('[data-role="time-label"]');
            const etaText = card.querySelector('[data-role="eta-text"]');
            const distanceText = card.querySelector('[data-role="distance-text"]');

            if (!Number.isFinite(storeLat) || !Number.isFinite(storeLng) || storeLat === 0 || storeLng === 0) {
                card.dataset.distance = '999';
                card.dataset.minutes = '999';
                if (etaText) etaText.textContent = '20-30 min';
                if (distanceText) distanceText.textContent = 'Near Cavite';
                return;
            }

            const distanceKm = haversineKm(lat, lng, storeLat, storeLng);
            const minTime = Math.max(15, Math.round((distanceKm * 3.5) + 15));
            const maxTime = minTime + 10;
            const etaString = minTime + '-' + maxTime + ' min';
            const distLabel = distanceKm < 1 ? '< 1 km away' : distanceKm.toFixed(1) + ' km away';

            card.dataset.distance = distanceKm.toFixed(2);
            card.dataset.minutes = minTime.toString();

            const isOpen = card.dataset.open === '1';
            if (timeLabel) {
                timeLabel.innerHTML = isOpen 
                    ? '<i class="fas fa-truck-fast"></i> ' + etaString + ' &bull; ' + (distanceKm < 1 ? '< 1 km' : distanceKm.toFixed(1) + ' km') 
                    : '<i class="fas fa-store-slash"></i> Closed &bull; ' + (distanceKm < 1 ? '< 1 km' : distanceKm.toFixed(1) + ' km');
            }
            if (etaText) etaText.textContent = etaString;
            if (distanceText) distanceText.textContent = distLabel;
        });

        updateUserMapMarker();
        renderStoreMapMarkers();
    }

    const applyShopFilters = function () {
        const query = (headerSearch ? headerSearch.value : '').toLowerCase().trim();
        const vouchersOnly = filterVouchers ? filterVouchers.checked : false;
        const nearbyOnly = filterNearbyOnly ? filterNearbyOnly.checked : false;
        const cityOnly = filterCityOnly ? filterCityOnly.checked : false;
        const targetCityKey = detectedCity ? detectedCity.key : 'dasmarinas';

        const selectedTypes = [];
        shopTypeChecks.forEach(function (chk) {
            if (chk.checked) selectedTypes.push(chk.value);
        });

        let selectedSort = 'relevance';
        sortRadios.forEach(function (r) {
            if (r.checked) selectedSort = r.value;
        });

        let selectedPrice = 'all';
        priceRadios.forEach(function (r) {
            if (r.checked) selectedPrice = r.value;
        });

        const nearbyCardsArray = [];
        const otherCardsArray = [];

        shopCards.forEach(function (card) {
            const cardCat = card.dataset.cat || '';
            const cardVouchers = card.dataset.vouchers === '1';
            const cardPrice = parseFloat(card.dataset.price || '0');
            const cardSearch = card.dataset.search || '';
            const cardDistance = parseFloat(card.dataset.distance || '999');

            // Check filters
            const matchesVouchers = !vouchersOnly || cardVouchers;
            const matchesTypes = selectedTypes.length === 0 || selectedTypes.includes(cardCat);
            const matchesQuery = !query || cardSearch.includes(query);

            let matchesPrice = true;
            if (selectedPrice === 'under_300') matchesPrice = cardPrice < 300;
            else if (selectedPrice === '300_1000') matchesPrice = cardPrice >= 300 && cardPrice <= 1000;
            else if (selectedPrice === 'above_1000') matchesPrice = cardPrice > 1000;

            if (!matchesVouchers || !matchesTypes || !matchesQuery || !matchesPrice) {
                card.style.display = 'none';
                return;
            }

            card.style.display = '';

            // Strict Nearby Determination based on user location & proximity
            const inCity = isStoreInCityScope(card, targetCityKey);
            const isNearbyRadius = Number.isFinite(cardDistance) && cardDistance <= 10;
            const isSameCityNearby = inCity && Number.isFinite(cardDistance) && cardDistance <= 12;
            const isNearby = (isNearbyRadius || isSameCityNearby) && cardDistance < 900;

            if (cityOnly) {
                if (inCity && cardDistance < 900) {
                    nearbyCardsArray.push(card);
                } else {
                    card.style.display = 'none';
                }
            } else if (nearbyOnly) {
                if (isNearby) {
                    nearbyCardsArray.push(card);
                } else {
                    card.style.display = 'none';
                }
            } else {
                if (isNearby) {
                    nearbyCardsArray.push(card);
                } else {
                    otherCardsArray.push(card);
                }
            }
        });

        // Handle Swimlane Filter
        swimlaneCards.forEach(function (scard) {
            const scat = scard.dataset.cat || '';
            const matches = selectedTypes.length === 0 || selectedTypes.includes(scat);
            scard.style.display = matches ? 'flex' : 'none';
        });

        // Sorting Logic
        const sortCardsList = function (arr, mode) {
            if (mode === 'top_rated') {
                arr.sort((a, b) => parseFloat(b.dataset.rating || '0') - parseFloat(a.dataset.rating || '0'));
            } else if (mode === 'fastest') {
                arr.sort((a, b) => parseFloat(a.dataset.minutes || '999') - parseFloat(b.dataset.minutes || '999'));
            } else {
                arr.sort((a, b) => parseFloat(a.dataset.distance || '999') - parseFloat(b.dataset.distance || '999'));
            }
        };

        const effectiveSort = (selectedSort === 'relevance') ? 'distance' : selectedSort;
        sortCardsList(nearbyCardsArray, effectiveSort);
        sortCardsList(otherCardsArray, effectiveSort);

        const nearbyGrid = document.getElementById('nearbyShopsGrid');
        const otherGrid = document.getElementById('otherShopsGrid');
        const nearbyBlock = document.getElementById('nearbyShopsBlock');
        const otherBlock = document.getElementById('otherShopsBlock');
        const nearbyEmptyNotice = document.getElementById('nearbyEmptyNotice');
        const nearbyTitle = document.getElementById('nearbySectionTitle');
        const nearbyCountLabel = document.getElementById('nearbyCountLabel');
        const otherCountLabel = document.getElementById('otherCountLabel');

        if (nearbyGrid) {
            nearbyCardsArray.forEach(card => nearbyGrid.appendChild(card));
        }
        if (otherGrid) {
            otherCardsArray.forEach(card => otherGrid.appendChild(card));
        }

        const cityName = detectedCity ? detectedCity.name : 'Dasmariñas';
        if (nearbyTitle) {
            nearbyTitle.textContent = `Stores Near You in ${cityName}`;
        }
        if (nearbyCountLabel) {
            nearbyCountLabel.textContent = `Showing ${nearbyCardsArray.length} nearby store${nearbyCardsArray.length === 1 ? '' : 's'}`;
        }
        if (otherCountLabel) {
            otherCountLabel.textContent = `Showing ${otherCardsArray.length} other store${otherCardsArray.length === 1 ? '' : 's'}`;
        }

        if (nearbyEmptyNotice) {
            nearbyEmptyNotice.style.display = nearbyCardsArray.length === 0 ? 'block' : 'none';
        }
        if (otherBlock) {
            otherBlock.style.display = otherCardsArray.length === 0 ? 'none' : 'block';
        }

        const totalVisible = nearbyCardsArray.length + otherCardsArray.length;
        if (zeroState) {
            zeroState.style.display = totalVisible === 0 ? 'block' : 'none';
        }

        renderStoreMapMarkers();
    };

    function triggerGeolocationDetection() {
        if (userLocationText) userLocationText.textContent = 'Acquiring GPS position...';
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                isUserLocationAccurate = true;
                userLocationLabel = 'Current GPS Position';
                const detected = detectCityFromTextOrCoords('', pos.coords.latitude, pos.coords.longitude);
                if (detected) {
                    detectedCity = detected;
                }
                calculateShopDistances(pos.coords.latitude, pos.coords.longitude);
                applyShopFilters();
            }, function () {
                if (userLocationText) userLocationText.textContent = `Location blocked. Using ${detectedCity ? detectedCity.name : 'Dasmariñas'}.`;
            }, { timeout: 6000, enableHighAccuracy: true });
        }
    }

    // 1. Check URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('nearby') === '1') {
        if (filterNearbyOnly) filterNearbyOnly.checked = true;
        if (filterCityOnly) filterCityOnly.checked = false;
    }
    if (urlParams.get('sort')) {
        const querySort = urlParams.get('sort');
        sortRadios.forEach(r => r.checked = r.value === querySort);
    }

    // 2. Check if user already set location in navbar / session
    readStoredNavbarLocation();

    // 3. Compute distances and initialize map
    calculateShopDistances(currentUserLat, currentUserLng);
    setTimeout(initShopsMap, 150);
    applyShopFilters();

    // 4. Try background browser geolocation if not set from navbar
    if (!isUserLocationAccurate && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (pos) {
            isUserLocationAccurate = true;
            userLocationLabel = 'Current GPS Position';
            const detected = detectCityFromTextOrCoords('', pos.coords.latitude, pos.coords.longitude);
            if (detected) {
                detectedCity = detected;
            }
            calculateShopDistances(pos.coords.latitude, pos.coords.longitude);
            applyShopFilters();
        }, function () {}, { timeout: 4000, maximumAge: 300000 });
    }

    // Event Listeners
    if (filterCityOnly) {
        filterCityOnly.addEventListener('change', function () {
            if (filterCityOnly.checked && filterNearbyOnly) {
                filterNearbyOnly.checked = false;
            }
            applyShopFilters();
        });
    }
    if (filterNearbyOnly) {
        filterNearbyOnly.addEventListener('change', function () {
            if (filterNearbyOnly.checked && filterCityOnly) {
                filterCityOnly.checked = false;
            }
            applyShopFilters();
        });
    }
    if (filterVouchers) filterVouchers.addEventListener('change', applyShopFilters);
    shopTypeChecks.forEach(chk => chk.addEventListener('change', applyShopFilters));
    sortRadios.forEach(r => r.addEventListener('change', applyShopFilters));
    priceRadios.forEach(r => r.addEventListener('change', applyShopFilters));
    if (headerSearch) headerSearch.addEventListener('input', applyShopFilters);

    if (mapDetectLocationBtn) mapDetectLocationBtn.addEventListener('click', triggerGeolocationDetection);
    if (sidebarDetectLocationBtn) sidebarDetectLocationBtn.addEventListener('click', triggerGeolocationDetection);

    // Listen for storage and custom events from header location modal
    const syncLocationUpdate = function () {
        if (readStoredNavbarLocation()) {
            calculateShopDistances(currentUserLat, currentUserLng);
            applyShopFilters();
        }
    };
    window.addEventListener('storage', function(e) {
        if (e.key === 'market_address_payload' || e.key === 'market_address') {
            syncLocationUpdate();
        }
    });
    window.addEventListener('marketAddressChanged', syncLocationUpdate);
    window.addEventListener('marketAddressUpdated', syncLocationUpdate);
});
</script>

<?php require_once 'includes/footer.php'; ?>
