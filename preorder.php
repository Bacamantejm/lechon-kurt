<?php
session_start();
$current_page = 'preorder';
$page_title = "Pre-Order | Lechon Delights";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $redirect_target = 'preorder.php';
    $query_string = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
    if ($query_string !== '') {
        $redirect_target .= '?' . $query_string;
    }
    header("Location: login.php?redirect=" . urlencode($redirect_target));
    exit();
}

require_once 'includes/config.php';
require_once 'includes/preorder_schedule_helper.php';
$google_maps_api_key = function_exists('getGoogleMapsApiKey')
    ? getGoogleMapsApiKey()
    : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));
$google_geocoding_enabled = function_exists('shouldUseGoogleGeocoding') ? shouldUseGoogleGeocoding() : true;

function preorderNormalizeCityLabel($city) {
    $city = trim((string)$city);
    if ($city === '') {
        return '';
    }
    $aliases = [
        'Dasmariñas' => 'Dasmarinas',
        'DasmariÃ±as' => 'Dasmarinas',
        'General Trias City' => 'General Trias'
    ];
    return $aliases[$city] ?? $city;
}

function preorderExtractAddressParts($rawAddress) {
    $parts = array_values(array_filter(array_map('trim', explode(',', (string)$rawAddress))));
    $street = '';
    $barangay = '';
    $city = '';
    $province = '';

    $count = count($parts);
    if ($count >= 4) {
        $street = implode(', ', array_slice($parts, 0, $count - 3));
        $barangay = $parts[$count - 3];
        $city = preorderNormalizeCityLabel($parts[$count - 2]);
        $province = $parts[$count - 1];
    } elseif ($count === 3) {
        $street = $parts[0];
        $city = preorderNormalizeCityLabel($parts[1]);
        $province = $parts[2];
    } elseif ($count === 2) {
        $street = $parts[0];
        $city = preorderNormalizeCityLabel($parts[1]);
    } elseif ($count === 1) {
        $street = $parts[0];
    }

    return [
        'street' => $street,
        'barangay' => $barangay,
        'city' => $city,
        'province' => $province
    ];
}

$requested_seller_id = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
$requested_product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

if ($requested_product_id > 0 && $requested_seller_id <= 0) {
    $p_stmt = mysqli_prepare($conn, "SELECT seller_id FROM products WHERE id = ? LIMIT 1");
    if ($p_stmt) {
        mysqli_stmt_bind_param($p_stmt, "i", $requested_product_id);
        mysqli_stmt_execute($p_stmt);
        $p_res = mysqli_stmt_get_result($p_stmt);
        if ($p_row = mysqli_fetch_assoc($p_res)) {
            $resolved_p_seller = (int)($p_row['seller_id'] ?? 0);
            if ($resolved_p_seller > 0) {
                $requested_seller_id = $resolved_p_seller;
            }
        }
        mysqli_stmt_close($p_stmt);
    }
}

if ($requested_seller_id > 0) {
    $_SESSION['storefront_seller_id'] = $requested_seller_id;
}
$active_seller_id = $requested_seller_id > 0
    ? $requested_seller_id
    : (int)($_SESSION['storefront_seller_id'] ?? 0);

$storefront_name = '';
if ($active_seller_id > 0) {
    $store_stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(NULLIF(TRIM(business_name), ''), full_name) AS store_name FROM users WHERE id = ? LIMIT 1"
    );
    if ($store_stmt) {
        mysqli_stmt_bind_param($store_stmt, "i", $active_seller_id);
        mysqli_stmt_execute($store_stmt);
        $store_result = mysqli_stmt_get_result($store_stmt);
        $store_row = $store_result ? mysqli_fetch_assoc($store_result) : null;
        $storefront_name = trim((string)($store_row['store_name'] ?? ''));
        if ($store_result) {
            mysqli_free_result($store_result);
        }
        mysqli_stmt_close($store_stmt);
    }
}

$preorder_schedule = posGetSellerSchedule($conn, (int)$active_seller_id);
$initial_calendar_data = posGetCalendarAvailability($conn, (int)$active_seller_id);

$user_profile = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => ''
];
$user_stmt = mysqli_prepare($conn, "SELECT full_name, email, phone, address FROM users WHERE id = ? LIMIT 1");
if ($user_stmt) {
    $session_user_id = (int)$_SESSION['user_id'];
    mysqli_stmt_bind_param($user_stmt, "i", $session_user_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user_row = $user_result ? mysqli_fetch_assoc($user_result) : null;
    if (is_array($user_row)) {
        $user_profile['full_name'] = trim((string)($user_row['full_name'] ?? ''));
        $user_profile['email'] = trim((string)($user_row['email'] ?? ''));
        $user_profile['phone'] = trim((string)($user_row['phone'] ?? ''));
        $user_profile['address'] = trim((string)($user_row['address'] ?? ''));
    }
    if ($user_result) {
        mysqli_free_result($user_result);
    }
    mysqli_stmt_close($user_stmt);
}

if ($user_profile['full_name'] === '' && isset($_SESSION['full_name'])) {
    $user_profile['full_name'] = trim((string)$_SESSION['full_name']);
}
if ($user_profile['email'] === '' && isset($_SESSION['email'])) {
    $user_profile['email'] = trim((string)$_SESSION['email']);
}
if ($user_profile['phone'] === '' && isset($_SESSION['phone'])) {
    $user_profile['phone'] = trim((string)$_SESSION['phone']);
}
if ($user_profile['address'] === '' && isset($_SESSION['address'])) {
    $user_profile['address'] = trim((string)$_SESSION['address']);
}

$parsed_address = preorderExtractAddressParts($user_profile['address']);
$prefill_street = (string)($parsed_address['street'] ?? '');
$prefill_barangay = (string)($parsed_address['barangay'] ?? '');
$prefill_city = (string)($parsed_address['city'] ?? '');
$prefill_province = trim((string)($parsed_address['province'] ?? ''));
if ($prefill_province === '') {
    $prefill_province = 'Cavite';
}

// Fetch products for pre-ordering (load full catalog with seller association and live inventory)
$products_sql = "SELECT p.id, p.product_id, p.seller_id, p.name, p.description, p.price, p.image, p.category,
                        COALESCE(i.current_stock, p.stock) AS stock
                 FROM products p
                 LEFT JOIN inventory i ON p.id = i.product_id AND i.inventory_date = CURDATE() AND i.is_archived = 0
                 WHERE p.is_active = 1
                   AND (p.is_archived = 0 OR p.is_archived IS NULL)
                 ORDER BY p.category, p.name ASC";
$products_result = mysqli_query($conn, $products_sql);
$all_products = [];
if ($products_result) {
    while ($row = mysqli_fetch_assoc($products_result)) {
        $all_products[] = [
            'id' => (int)$row['id'],
            'product_id' => (string)($row['product_id'] ?? ''),
            'seller_id' => (int)($row['seller_id'] ?? 1),
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'price' => (float)($row['price'] ?? 0),
            'stock' => (int)($row['stock'] ?? 0),
            'image' => (string)($row['image'] ?? 'default.jpg'),
            'category' => (string)($row['category'] ?? 'lechon')
        ];
    }
    mysqli_free_result($products_result);
}

// Get distinct categories for filter
$categories = [];
foreach ($all_products as $p) {
    $categories[$p['category']] = true;
}
$categories = array_keys($categories);
sort($categories);

$store_scope_query = $active_seller_id > 0 ? '?seller_id=' . $active_seller_id : '';

function preorderGetStoreImage($store_id, $store_name, $custom_image = '') {
    $custom = trim((string)$custom_image);
    if ($custom !== '' && $custom !== 'default.jpg') {
        if (str_starts_with($custom, 'http') || str_starts_with($custom, 'images/') || str_starts_with($custom, 'uploads/')) {
            return $custom;
        }
        if (file_exists('uploads/business_logos/' . $custom)) {
            return 'uploads/business_logos/' . $custom;
        }
        if (file_exists('images/' . $custom)) {
            return 'images/' . $custom;
        }
    }
    
    $image_map = [
        1 => 'images/store-bg.jpg',               // Dasmariñas Central Branch
        2 => 'images/panda_preorder_lechon.jpg',  // Bacoor Express Branch
        3 => 'images/about-us-bg.jpg',             // Imus Heritage Branch
        4 => 'images/hero-bg.jpg',                 // Tagaytay Ridge Branch
        5 => 'images/panda_fresh_lechon.jpg',     // Janna Restaurant
        6 => 'images/store-bg.jpg',               // Justine Business
        7 => 'images/panda_preorder_lechon.jpg',  // JM Lechon
    ];
    if (isset($image_map[$store_id])) {
        return $image_map[$store_id];
    }
    
    $name_lower = strtolower((string)$store_name);
    if (str_contains($name_lower, 'lydia')) return 'images/panda_fresh_lechon.jpg';
    if (str_contains($name_lower, 'linda')) return 'images/about-us-bg.jpg';
    if (str_contains($name_lower, 'tagaytay')) return 'images/hero-bg.jpg';
    if (str_contains($name_lower, 'bacoor')) return 'images/panda_preorder_lechon.jpg';
    if (str_contains($name_lower, 'imus')) return 'images/about-us-bg.jpg';
    return 'images/store-bg.jpg';
}

$official_owner_ids = [0, 1, 42, 43, 44, 45];

// Fetch active store locations & partner vendor stores for pick-up / pre-order
$stores = [];
$branch_sql = "SELECT sl.store_id AS id, sl.store_id, sl.owner_user_id, sl.store_name, sl.address, sl.city, sl.province, sl.phone, sl.opening_hours, sl.opening_time, sl.closing_time, sl.latitude, sl.longitude,
                      CASE WHEN sps.id IS NOT NULL AND sps.is_active = 1 THEN 1 ELSE 0 END AS has_reservation_schedule,
                      sps.lead_time_days, sps.cutoff_time, sps.max_advance_days
               FROM store_locations sl
               LEFT JOIN shop_preorder_schedules sps ON (sps.seller_id = sl.owner_user_id OR sps.seller_id = sl.store_id) AND sps.is_active = 1
               WHERE sl.is_active = 1
               ORDER BY has_reservation_schedule DESC, sl.store_name ASC";
$branch_res = mysqli_query($conn, $branch_sql);
if ($branch_res) {
    while ($r = mysqli_fetch_assoc($branch_res)) {
        $b_owner = (int)($r['owner_user_id'] ?? 1);
        $is_partner = ($b_owner > 0 && !in_array($b_owner, $official_owner_ids, true));
        $r['seller_id'] = $b_owner;
        $r['store_category'] = $is_partner ? 'partner' : 'branch';
        $r['store_type_label'] = $is_partner ? 'Partner Store' : 'Pickup Branch';
        $r['image'] = preorderGetStoreImage((int)$r['store_id'], $r['store_name']);
        $stores[] = $r;
    }
    mysqli_free_result($branch_res);
}

// Add approved partner organization stores that are not already listed as physical branches
$seller_sql = "SELECT u.id AS seller_id, u.full_name, u.business_name, u.business_type, u.business_logo, u.profile_image, u.address, u.phone,
                      sl.store_id, sl.latitude, sl.longitude, sl.city, sl.province,
                      CASE WHEN sps.id IS NOT NULL AND sps.is_active = 1 THEN 1 ELSE 0 END AS has_reservation_schedule,
                      sps.lead_time_days, sps.cutoff_time, sps.max_advance_days
               FROM users u
               LEFT JOIN store_locations sl ON sl.owner_user_id = u.id AND sl.is_active = 1
               LEFT JOIN shop_preorder_schedules sps ON sps.seller_id = u.id AND sps.is_active = 1
               WHERE (u.account_type = 'organization' OR u.user_type = 'seller') AND u.is_active = 1";
$seller_res = mysqli_query($conn, $seller_sql);
if ($seller_res) {
    while ($r = mysqli_fetch_assoc($seller_res)) {
        $sid = (int)$r['seller_id'];
        $already_in = false;
        foreach ($stores as $st) {
            if ((int)($st['owner_user_id'] ?? 0) === $sid) {
                $already_in = true;
                break;
            }
        }
        if (!$already_in) {
            $name = trim((string)$r['business_name']) ?: (trim((string)$r['full_name']) . ' Store');
            $city = !empty($r['city']) ? $r['city'] : 'Cavite';
            $prov = !empty($r['province']) ? $r['province'] : 'Cavite';
            $partner_img = !empty($r['business_logo']) ? $r['business_logo'] : (!empty($r['profile_image']) ? $r['profile_image'] : '');
            $stores[] = [
                'id' => 9000 + $sid,
                'store_id' => !empty($r['store_id']) ? (int)$r['store_id'] : 1,
                'owner_user_id' => $sid,
                'seller_id' => $sid,
                'store_name' => $name,
                'address' => $r['address'] ?: ($city . ', Cavite'),
                'city' => $city,
                'province' => $prov,
                'phone' => $r['phone'] ?? '',
                'opening_hours' => '8:00 AM - 8:00 PM',
                'opening_time' => '08:00:00',
                'closing_time' => '20:00:00',
                'latitude' => !empty($r['latitude']) ? $r['latitude'] : '14.3294',
                'longitude' => !empty($r['longitude']) ? $r['longitude'] : '120.9367',
                'store_category' => 'partner',
                'store_type_label' => 'Partner Store',
                'image' => preorderGetStoreImage(9000 + $sid, $name, $partner_img),
                'has_reservation_schedule' => (int)$r['has_reservation_schedule'],
                'lead_time_days' => $r['lead_time_days'] ?? 1,
                'cutoff_time' => $r['cutoff_time'] ?? '18:00:00',
                'max_advance_days' => $r['max_advance_days'] ?? 30
            ];
        }
    }
    mysqli_free_result($seller_res);
}

// Split into Official Branches and Partner Stores for hierarchical presentation
$official_branches = array_filter($stores, function($s) {
    return ($s['store_category'] ?? 'branch') === 'branch';
});
$partner_stores = array_filter($stores, function($s) {
    return ($s['store_category'] ?? '') === 'partner';
});

// Extract unique cities for quick filter pills
$store_cities = [];
foreach ($stores as $s) {
    $c = trim((string)($s['city'] ?? ''));
    if ($c !== '' && !in_array($c, $store_cities, true)) {
        $store_cities[] = $c;
    }
}
sort($store_cities);

$time_slots = ['8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM'];

include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
    --primary-color: #b3261e;
    --primary-dark: #981b15;
    --pre-red: #b3261e;
    --pre-red-hover: #981b15;
    --pre-ink: #101828;
    --pre-muted: #475467;
    --pre-border: #eaecf0;
    --pre-border-input: #d0d5dd;
    --pre-bg: #f8f9fa;
    --pre-card: #ffffff;
    --text-main: #101828;
    --text-light: #475467;
    --bg-light: #f8f9fa;
    --card-radius: 16px;
    --transition: all 0.2s cubic-bezier(0.25, 0.8, 0.25, 1);
    --shadow-sm: 0 1px 3px rgba(16, 24, 40, 0.04);
    --shadow-md: 0 1px 3px rgba(16, 24, 40, 0.04);
}

body {
    background: var(--pre-bg) !important;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--pre-ink);
}

/* Page Header - Clean, Flat, High-Contrast */
.page-header {
    background: #ffffff;
    border-bottom: 1px solid var(--pre-border);
    padding: 32px 20px 24px;
    text-align: center;
    margin-bottom: 0;
    position: static;
}

.page-header h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 2rem;
    font-weight: 800;
    color: var(--pre-ink);
    margin: 0 0 6px;
    letter-spacing: -0.02em;
    text-shadow: none;
}

.page-header p {
    font-size: 0.95rem;
    color: var(--pre-muted);
    max-width: 600px;
    margin: 0 auto;
    font-weight: 400;
    opacity: 1;
}

.preorder-section {
    padding: 24px 0 80px;
    background-color: var(--pre-bg);
    min-height: 80vh;
}

.preorder-container {
    background: #ffffff;
    border: 1px solid var(--pre-border);
    border-radius: var(--card-radius);
    box-shadow: var(--shadow-sm);
    padding: 28px;
    max-width: 900px;
    margin: 0 auto;
    position: relative;
}

.preorder-container.expanded {
    max-width: 1200px;
}

/* Checkout Type Switch */
.checkout-type-switch {
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    padding: 14px 16px;
    background: #f8f9fa;
    margin-bottom: 20px;
}

.checkout-type-title {
    margin: 0 0 8px;
    color: var(--pre-ink);
    font-weight: 700;
    font-size: 0.9rem;
}

.checkout-type-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.checkout-type-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid var(--pre-border-input);
    background: #ffffff;
    color: #344054;
    font-weight: 700;
    font-size: 0.85rem;
    transition: all 0.15s ease;
}

.checkout-type-chip:hover {
    background: #f8f9fa;
    color: var(--pre-red);
    border-color: #d0d5dd;
}

.checkout-type-chip.is-active {
    background: var(--pre-red);
    border-color: var(--pre-red);
    color: #ffffff;
    cursor: default;
    pointer-events: none;
}

.checkout-type-note {
    margin: 8px 0 0;
    color: var(--pre-muted);
    font-size: 0.8rem;
}

/* Progress Stepper */
.progress-bar-container {
    margin-bottom: 24px;
    padding: 16px 20px 12px;
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    background: #ffffff;
}

.progress-steps {
    display: flex;
    justify-content: space-between;
    position: relative;
    max-width: 600px;
    margin: 0 auto;
}

.progress-steps::before {
    content: '';
    position: absolute;
    top: 18px;
    left: 40px;
    right: 40px;
    height: 4px;
    background: #eaecf0;
    z-index: 0;
    border-radius: 4px;
}

.progress-step {
    position: relative;
    z-index: 1;
    text-align: center;
    width: 90px;
}

.progress-step-circle {
    width: 36px;
    height: 36px;
    background: #ffffff;
    border: 2px solid #d0d5dd;
    color: #98a2b3;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.9rem;
    margin: 0 auto 8px;
    transition: var(--transition);
}

.progress-step.active .progress-step-circle {
    border-color: var(--pre-red);
    background: var(--pre-red);
    color: #ffffff;
    transform: scale(1.08);
    box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.15);
}

.progress-step.completed .progress-step-circle {
    border-color: #027a48;
    background: #027a48;
    color: #ffffff;
}

.progress-step-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #667085;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.progress-step.active .progress-step-label {
    color: var(--pre-red);
}

.progress-step.completed .progress-step-label {
    color: #027a48;
}

/* Steps Content */
.step-content {
    display: none;
    animation: fadeIn 0.25s ease;
    border: 1px solid var(--pre-border);
    border-radius: 16px;
    background: #ffffff;
    padding: 24px;
}

.step-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.step-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--pre-ink);
    margin-bottom: 20px;
    text-align: left;
    position: relative;
    padding-bottom: 10px;
    border-bottom: 1px solid #f2f4f7;
}

.tenant-scope-note {
    margin: -6px 0 16px;
    padding: 10px 14px;
    border: 1px solid #b2ddff;
    border-radius: 10px;
    background: #eff8ff;
    color: #175cd3;
    font-size: 0.88rem;
}

.autofill-note {
    margin: -4px 0 16px;
    color: #475467;
    font-size: 0.84rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.autofill-note i {
    color: #027a48;
}

/* Category Filter Nav */
.category-nav {
    background-color: #ffffff;
    padding: 8px 12px;
    border-radius: 12px;
    border: 1px solid var(--pre-border);
    box-shadow: none;
    margin: 0 0 20px;
    position: static;
}

.category-list {
    display: flex;
    justify-content: flex-start;
    overflow-x: auto;
    gap: 8px;
    padding: 4px 0;
    scrollbar-width: none;
}

.category-list::-webkit-scrollbar {
    display: none;
}

.category-link {
    white-space: nowrap;
    padding: 7px 16px;
    background-color: #ffffff;
    color: #344054;
    border: 1px solid var(--pre-border-input);
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.84rem;
    cursor: pointer;
    transition: all 0.15s ease;
}

.category-link:hover {
    background: #f8f9fa;
    color: var(--pre-red);
    border-color: #d0d5dd;
}

.category-link.active {
    background: var(--pre-red);
    color: #ffffff;
    border-color: var(--pre-red);
    box-shadow: none;
}

/* Product Cards */
#productList {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.product-card {
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    background: #ffffff;
    padding: 0;
    overflow: hidden;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.product-card:hover {
    transform: translateY(-2px);
    border-color: #d0d5dd;
    box-shadow: 0 4px 12px rgba(16, 24, 40, 0.08);
}

.product-image {
    height: 150px;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-image i {
    font-size: 2.5rem;
    color: #cbd5e1;
}

.product-info {
    padding: 14px;
    text-align: left;
}

.product-info h4 {
    margin: 0 0 4px;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--pre-ink);
}

.product-price {
    color: var(--pre-red);
    font-weight: 800;
    font-size: 1.05rem;
    margin-bottom: 8px;
}

.product-card.selected {
    border-color: var(--pre-red);
    box-shadow: 0 0 0 2px rgba(179, 38, 30, 0.2);
}

.check-icon {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 24px;
    height: 24px;
    background: var(--pre-red);
    color: #ffffff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    opacity: 0;
    transform: scale(0);
    transition: var(--transition);
}

.product-card.selected .check-icon {
    opacity: 1;
    transform: scale(1);
}

/* Step 1: Hierarchical Store Selection & Storefront Image Cards */
.store-selection-container {
    background: #ffffff;
    border: 1px solid var(--pre-border);
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.store-selection-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

.store-selection-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.store-selection-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #fff1f0;
    color: #b3261e;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.store-selection-title h3 {
    margin: 0;
    font-family: 'Outfit', sans-serif;
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--pre-ink);
}

.store-selection-title p {
    margin: 2px 0 0 0;
    font-size: 0.82rem;
    color: var(--pre-muted);
}

/* Hierarchy Category Tabs */
.store-hierarchy-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.store-hierarchy-tab {
    background: #ffffff;
    border: 1.5px solid #eaecf0;
    color: #344054;
    font-size: 0.82rem;
    font-weight: 700;
    padding: 8px 16px;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.store-hierarchy-tab:hover {
    border-color: #d0d5dd;
    background: #f8f9fa;
    color: #101828;
}

.store-hierarchy-tab.active {
    background: #b3261e;
    border-color: #b3261e;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(179, 38, 30, 0.2);
}

.store-city-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid #f2f4f7;
}

.store-city-pill {
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    color: #475467;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 999px;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.store-city-pill:hover {
    border-color: #d0d5dd;
    color: #101828;
    background: #ffffff;
}

.store-city-pill.active {
    background: #101828;
    border-color: #101828;
    color: #ffffff;
}

/* Store Hierarchy Sections */
.store-hierarchy-section {
    margin-bottom: 22px;
}

.store-hierarchy-section:last-child {
    margin-bottom: 0;
}

.store-hierarchy-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid #eaecf0;
}

.store-hierarchy-heading h4 {
    margin: 0;
    font-family: 'Outfit', sans-serif;
    font-size: 1.02rem;
    font-weight: 800;
    color: var(--pre-ink);
    display: flex;
    align-items: center;
    gap: 8px;
}

.store-hierarchy-heading span {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--pre-muted);
}

.store-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

/* Store Card Item with Image Banner */
.store-card-item {
    background: #ffffff;
    border: 1.5px solid #eaecf0;
    border-radius: 14px;
    cursor: pointer;
    position: relative;
    transition: all 0.2s cubic-bezier(0.25, 0.8, 0.25, 1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.store-card-item:hover {
    border-color: #d0d5dd;
    box-shadow: 0 6px 18px rgba(16, 24, 40, 0.08);
    transform: translateY(-2px);
}

.store-card-item.selected {
    border-color: #b3261e;
    box-shadow: 0 6px 20px rgba(179, 38, 30, 0.16);
}

.store-card-img-banner {
    height: 125px;
    background-size: cover;
    background-position: center;
    position: relative;
    background-color: #f2f4f7;
    border-bottom: 1px solid #eaecf0;
}

.store-card-img-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.45) 0%, rgba(0,0,0,0.1) 40%, rgba(0,0,0,0.6) 100%);
}

.store-card-badge-top-left {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 2;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.store-card-badge-top-right {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 2;
}

.store-badge-branch {
    background: #175cd3;
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}

.store-badge-partner {
    background: #b54708;
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}

.store-badge-res-tag {
    background: #027a48;
    color: #ffffff;
    font-size: 0.66rem;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}

.store-badge-dist {
    background: rgba(16, 24, 40, 0.85);
    backdrop-filter: blur(4px);
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid rgba(255,255,255,0.2);
}

.store-card-body {
    padding: 14px;
    display: flex;
    flex-direction: column;
    flex: 1;
    justify-content: space-between;
}

.store-card-name-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
}

.store-card-title {
    font-family: 'Outfit', sans-serif;
    font-size: 0.98rem;
    font-weight: 800;
    color: var(--pre-ink);
    margin: 0;
    line-height: 1.3;
}

.store-card-check-pill {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid #d0d5dd;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    color: #ffffff;
    background: transparent;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.store-card-item.selected .store-card-check-pill {
    background: #b3261e;
    border-color: #b3261e;
}

.store-card-address-row {
    font-size: 0.78rem;
    color: #475467;
    margin-bottom: 8px;
    line-height: 1.35;
    display: flex;
    align-items: flex-start;
    gap: 5px;
}

.store-card-address-row i {
    color: #b3261e;
    margin-top: 2px;
    flex-shrink: 0;
}

.store-card-info-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.75rem;
    color: #667085;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #f2f4f7;
}

.store-card-select-btn {
    width: 100%;
    margin-top: 10px;
    padding: 7px 12px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 700;
    text-align: center;
    border: 1px solid #eaecf0;
    background: #f8f9fa;
    color: #344054;
    transition: all 0.15s ease;
}

.store-card-item:hover .store-card-select-btn {
    border-color: #d0d5dd;
    background: #ffffff;
    color: #101828;
}

.store-card-item.selected .store-card-select-btn {
    border-color: #b3261e;
    background: #b3261e;
    color: #ffffff;
}

/* Progressive Disclosure for Store Selection -> Menu Display */
.store-select-prompt-box {
    background: #ffffff;
    border: 2px dashed #d0d5dd;
    border-radius: 16px;
    padding: 42px 24px;
    text-align: center;
    margin-top: 10px;
    transition: all 0.3s ease;
}

.store-select-prompt-icon {
    width: 62px;
    height: 62px;
    border-radius: 50%;
    background: #fff1f0;
    color: #b3261e;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.65rem;
    margin-bottom: 14px;
}

.store-select-prompt-box h4 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--pre-ink);
    margin: 0 0 6px;
}

.store-select-prompt-box p {
    font-size: 0.88rem;
    color: var(--pre-muted);
    max-width: 480px;
    margin: 0 auto;
    line-height: 1.45;
}

.dishes-menu-wrapper {
    display: none;
    margin-top: 20px;
    animation: preorderMenuFadeIn 0.35s cubic-bezier(0.25, 0.8, 0.25, 1) forwards;
}

.dishes-menu-wrapper.is-visible {
    display: block;
}

@keyframes preorderMenuFadeIn {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Step 1: 2-Column Product Catalog & Sticky Pre-Order Cart Layout */
.preorder-step1-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    align-items: start;
}

.preorder-catalog-main {
    min-width: 0;
}

.preorder-cart-sidebar {
    position: sticky;
    top: 90px;
}

.preorder-cart-card {
    background: #ffffff;
    border: 1px solid var(--pre-border);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.preorder-cart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 4px;
}

.preorder-cart-header h4 {
    margin: 0;
    font-family: 'Outfit', sans-serif;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--pre-ink);
    display: flex;
    align-items: center;
    gap: 8px;
}

.preorder-cart-badge {
    background: #fff1f0;
    color: var(--pre-red);
    border: 1px solid #fee4e2;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 800;
}

.btn-clear-cart {
    background: transparent;
    border: 1px solid var(--pre-border-input);
    color: #667085;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.76rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-clear-cart:hover {
    background: #fff1f0;
    border-color: #fee4e2;
    color: var(--pre-red);
}

.preorder-cart-sub {
    margin: 0 0 14px;
    font-size: 0.8rem;
    color: var(--pre-muted);
}

.cart-items-container {
    max-height: 320px;
    overflow-y: auto;
    margin-bottom: 16px;
    padding-right: 4px;
}

.empty-cart-msg {
    text-align: center;
    padding: 28px 16px;
    color: #667085;
    font-size: 0.86rem;
    line-height: 1.4;
    background: #f8f9fa;
    border: 1px dashed var(--pre-border-input);
    border-radius: 12px;
    margin: 0;
}

.preorder-cart-totals {
    border-top: 1px solid #eaecf0;
    padding-top: 12px;
    margin-bottom: 16px;
}

.preorder-total-line {
    display: flex;
    justify-content: space-between;
    font-size: 0.84rem;
    color: var(--pre-muted);
    margin-bottom: 6px;
}

.preorder-total-line strong {
    color: var(--pre-ink);
}

.preorder-total-line.grand-total {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--pre-ink);
    border-top: 1px solid #eaecf0;
    padding-top: 8px;
    margin-top: 8px;
}

.preorder-total-line.grand-total strong {
    color: var(--pre-red);
}

.preorder-cart-actions .btn-block {
    width: 100%;
    padding: 12px 18px;
}

/* Cart item row & Summary Box */
.summary-box {
    background: #f8f9fa;
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    padding: 18px 20px;
    margin-top: 20px;
}

.cart-item-row {
    background: #ffffff;
    border: 1px solid var(--pre-border);
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
}

.cart-item-image-col {
    width: 44px;
    height: 44px;
    margin-right: 12px;
    flex-shrink: 0;
}

.cart-item-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 6px;
}

.cart-item-thumb-placeholder {
    width: 100%;
    height: 100%;
    background: #f1f5f9;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
}

.cart-item-details-col {
    flex-grow: 1;
}

.cart-item-name {
    font-weight: 700;
    font-size: 0.9rem;
    color: var(--pre-ink);
    margin-bottom: 2px;
}

.cart-item-price-single {
    font-size: 0.78rem;
    color: var(--pre-muted);
}

.cart-item-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}

.tiny-btn {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 1px solid var(--pre-border-input);
    background: #ffffff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--pre-ink);
    font-size: 0.72rem;
    transition: all 0.15s ease;
}

.tiny-btn:hover {
    background: #f8f9fa;
    color: var(--pre-red);
    border-color: #d0d5dd;
}

.qty-display {
    font-weight: 700;
    font-size: 0.88rem;
    min-width: 20px;
    text-align: center;
    color: var(--pre-ink);
}

.cart-item-total-col {
    text-align: right;
    margin-left: 12px;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: space-between;
    height: 44px;
}

.cart-item-price {
    font-weight: 800;
    color: var(--pre-red);
    font-size: 0.95rem;
}

.remove-item-btn {
    background: none;
    border: none;
    color: #b3261e;
    cursor: pointer;
    font-size: 0.88rem;
    padding: 2px;
    opacity: 0.8;
    transition: opacity 0.15s ease;
}

.remove-item-btn:hover {
    opacity: 1;
}

.cart-total-row {
    display: flex;
    justify-content: space-between;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--pre-ink);
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--pre-border);
}

.cart-total-row span:last-child {
    color: var(--pre-red);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.88rem;
    color: var(--pre-muted);
    margin-bottom: 8px;
}

.summary-row.total {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--pre-border);
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--pre-ink);
}

.summary-row.total span:last-child,
#summaryTotal, #payTotal {
    color: var(--pre-red);
}

/* Form Controls */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-row.full {
    grid-template-columns: 1fr;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-label {
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--pre-ink);
}

.form-control, .form-select {
    padding: 10px 14px;
    border: 1px solid var(--pre-border-input);
    border-radius: 10px;
    font-size: 0.92rem;
    color: var(--pre-ink);
    background: #ffffff;
    outline: none;
    transition: all 0.15s ease;
}

.form-control:focus, .form-select:focus {
    border-color: var(--pre-red);
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
}

.payment-options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.payment-option-card {
    border: 1px solid var(--pre-border-input);
    border-radius: 12px;
    padding: 16px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.15s ease;
    display: flex;
    align-items: center;
    gap: 12px;
}

.payment-option-card:hover {
    border-color: var(--pre-red);
    background: #fff8f0;
}

.payment-option-card.selected {
    border-color: var(--pre-red);
    background: #fff1f0;
    box-shadow: 0 0 0 2px rgba(179, 38, 30, 0.2);
}

.store-details-card {
    background: #f8f9fa;
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.store-details-info h4 {
    margin: 0 0 6px;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--pre-ink);
}

.store-details-info p {
    margin: 0;
    font-size: 0.86rem;
    color: var(--pre-muted);
}

.button-group {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 24px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 22px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.92rem;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s ease;
}

.btn-primary {
    background: var(--pre-red);
    color: #ffffff;
    border-color: var(--pre-red);
}

.btn-primary:hover {
    background: var(--pre-red-hover);
    border-color: var(--pre-red-hover);
}

.btn-secondary {
    background: #ffffff;
    border-color: var(--pre-border-input);
    color: #344054;
}

.btn-secondary:hover {
    background: #f8f9fa;
    border-color: #d0d5dd;
    color: #101828;
}

.slot-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.72rem;
    font-weight: 700;
    margin-top: 2px;
}
.slot-badge.badge-open {
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
}
.slot-badge.badge-full {
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
}

@media (max-width: 768px) {
    .page-header h1 { font-size: 1.6rem; }
    .preorder-container { padding: 16px; }
    .preorder-step1-layout { grid-template-columns: 1fr; }
    .preorder-cart-sidebar { position: static; }
    .form-row { grid-template-columns: 1fr; gap: 12px; }
    .button-group { flex-direction: column-reverse; }
    .btn { width: 100%; }
    .payment-options-grid { grid-template-columns: 1fr; }
    .checkout-type-actions { flex-direction: column; }
    .progress-step { width: 70px; }
    .progress-step-circle { width: 32px; height: 32px; font-size: 0.8rem; }
    .store-details-card { flex-direction: column; align-items: flex-start; }
    .btn-directions { width: 100%; }
}

/* ==========================================================================
   PREORDER DARK MODE THEME ENGINE
   ========================================================================== */
html.dark-mode body,
body.dark-mode {
    background: #0f172a !important;
    background-color: #0f172a !important;
    color: #f8fafc !important;
}

body.dark-mode .page-header {
    background: #1e293b !important;
    border-bottom: 1px solid #334155 !important;
}

body.dark-mode .page-header h1 {
    color: #f8fafc !important;
}

body.dark-mode .page-header p {
    color: #94a3b8 !important;
}

body.dark-mode .preorder-section {
    background: #0f172a !important;
}

body.dark-mode .preorder-container {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
    color: #f8fafc !important;
}

body.dark-mode .checkout-type-switch {
    background: #111827 !important;
    border: 1px solid #334155 !important;
}

body.dark-mode .checkout-type-title {
    color: #f8fafc !important;
}

body.dark-mode .checkout-type-note {
    color: #94a3b8 !important;
}

body.dark-mode .checkout-type-chip {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .checkout-type-chip:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .checkout-type-chip.is-active {
    background: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
}

body.dark-mode .progress-bar-container {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .progress-steps::before {
    background: #334155 !important;
}

body.dark-mode .progress-step-circle {
    background: #1e293b !important;
    border-color: #475569 !important;
    color: #94a3b8 !important;
}

body.dark-mode .progress-step.active .progress-step-circle {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .progress-step.completed .progress-step-circle {
    background: #027a48 !important;
    border-color: #027a48 !important;
    color: #ffffff !important;
}

body.dark-mode .progress-step-label {
    color: #94a3b8 !important;
}

body.dark-mode .progress-step.active .progress-step-label {
    color: #ef4444 !important;
}

body.dark-mode .progress-step.completed .progress-step-label {
    color: #027a48 !important;
}

body.dark-mode .step-content {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .step-title {
    color: #f8fafc !important;
    border-bottom-color: #334155 !important;
}

body.dark-mode .tenant-scope-note {
    background: rgba(23, 92, 211, 0.15) !important;
    border-color: #175cd3 !important;
    color: #93c5fd !important;
}

body.dark-mode .autofill-note {
    color: #94a3b8 !important;
}

body.dark-mode .category-nav {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .category-link {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .category-link:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .category-link.active {
    background: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
}

body.dark-mode .product-card {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}

body.dark-mode .product-card:hover {
    border-color: #475569 !important;
}

body.dark-mode .product-image {
    background: #0f172a !important;
}

body.dark-mode .product-info h4 {
    color: #f8fafc !important;
}

body.dark-mode .product-price {
    color: #ef4444 !important;
}

body.dark-mode .product-desc {
    color: #94a3b8 !important;
}

body.dark-mode .preorder-cart-card {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}

body.dark-mode .preorder-cart-header h4 {
    color: #f8fafc !important;
}

body.dark-mode .preorder-cart-sub {
    color: #94a3b8 !important;
}

body.dark-mode .empty-cart-msg {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}

body.dark-mode .cart-item-row {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .cart-item-name {
    color: #f8fafc !important;
}

body.dark-mode .cart-item-price-single {
    color: #94a3b8 !important;
}

body.dark-mode .tiny-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .tiny-btn:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .qty-display {
    color: #f8fafc !important;
}

body.dark-mode .cart-item-price {
    color: #ef4444 !important;
}

body.dark-mode .preorder-cart-totals {
    border-top-color: #334155 !important;
}

body.dark-mode .preorder-total-line {
    color: #94a3b8 !important;
}

body.dark-mode .preorder-total-line strong {
    color: #f8fafc !important;
}

body.dark-mode .preorder-total-line.grand-total {
    color: #f8fafc !important;
    border-top-color: #334155 !important;
}

body.dark-mode .preorder-total-line.grand-total strong {
    color: #ef4444 !important;
}

body.dark-mode .btn-clear-cart {
    border-color: #334155 !important;
    color: #94a3b8 !important;
}

body.dark-mode .btn-clear-cart:hover {
    background: rgba(179, 38, 30, 0.2) !important;
    border-color: rgba(239, 68, 68, 0.3) !important;
    color: #ef4444 !important;
}

body.dark-mode .summary-box {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .summary-row {
    color: #94a3b8 !important;
}

body.dark-mode .summary-row.total {
    border-top-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .summary-row.total span:last-child {
    color: #ef4444 !important;
}

body.dark-mode .form-label,
body.dark-mode label {
    color: #f8fafc !important;
}

body.dark-mode .form-control,
body.dark-mode .form-select {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .form-control:focus,
body.dark-mode .form-select:focus {
    background: #0f172a !important;
    border-color: #b3261e !important;
    color: #f8fafc !important;
}

body.dark-mode .payment-option-card {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .payment-option-card:hover {
    border-color: #475569 !important;
}

body.dark-mode .payment-option-card.selected {
    border-color: #b3261e !important;
    background: rgba(179, 38, 30, 0.1) !important;
}

body.dark-mode .store-details-card {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .store-details-info h4 {
    color: #f8fafc !important;
}

body.dark-mode .store-details-info p {
    color: #94a3b8 !important;
}

body.dark-mode .btn-secondary {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}

body.dark-mode .btn-secondary:hover {
    background: #475569 !important;
    color: #ffffff !important;
}

/* Step 1 Store Hierarchy & Image Cards Dark Theme */
body.dark-mode .store-selection-container {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}

body.dark-mode .store-selection-icon {
    background: rgba(179, 38, 30, 0.2) !important;
    color: #ef4444 !important;
}

body.dark-mode .store-selection-title h3 {
    color: #f8fafc !important;
}

body.dark-mode .store-selection-title p {
    color: #94a3b8 !important;
}

body.dark-mode .store-hierarchy-tabs {
    border-bottom-color: #334155 !important;
}

body.dark-mode .store-hierarchy-tab {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .store-hierarchy-tab:hover {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #ffffff !important;
}

body.dark-mode .store-hierarchy-tab.active {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(179, 38, 30, 0.35) !important;
}

body.dark-mode .store-city-filters {
    border-bottom-color: #334155 !important;
}

body.dark-mode .store-city-pill {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}

body.dark-mode .store-city-pill:hover {
    background: #1e293b !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}

body.dark-mode .store-city-pill.active {
    background: #f8fafc !important;
    border-color: #f8fafc !important;
    color: #0f172a !important;
}

body.dark-mode .store-hierarchy-heading h4 {
    color: #f8fafc !important;
}

body.dark-mode .store-hierarchy-heading span {
    color: #94a3b8 !important;
}

body.dark-mode .store-card-item {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}

body.dark-mode .store-card-item:hover {
    border-color: #475569 !important;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .store-card-item.selected {
    border-color: #b3261e !important;
    background: #18192a !important;
    box-shadow: 0 6px 22px rgba(179, 38, 30, 0.35) !important;
}

body.dark-mode .store-card-img-banner {
    border-bottom-color: #334155 !important;
    background-color: #0f172a !important;
}

body.dark-mode .store-card-title {
    color: #f8fafc !important;
}

body.dark-mode .store-card-check-pill {
    border-color: #475569 !important;
    background: transparent !important;
}

body.dark-mode .store-card-item.selected .store-card-check-pill {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .store-card-address-row {
    color: #94a3b8 !important;
}

body.dark-mode .store-card-address-row i {
    color: #ef4444 !important;
}

body.dark-mode .store-card-info-footer {
    border-top-color: #334155 !important;
    color: #94a3b8 !important;
}

body.dark-mode .store-card-select-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .store-card-item:hover .store-card-select-btn {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #ffffff !important;
}

body.dark-mode .store-card-item.selected .store-card-select-btn {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .store-select-prompt-box {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .store-select-prompt-icon {
    background: rgba(179, 38, 30, 0.2) !important;
    color: #ef4444 !important;
}

body.dark-mode .store-select-prompt-box h4 {
    color: #f8fafc !important;
}

body.dark-mode .store-select-prompt-box p {
    color: #94a3b8 !important;
}

body.dark-mode #step1SelectedStoreBadge {
    background: rgba(179, 38, 30, 0.2) !important;
    border-color: rgba(239, 68, 68, 0.35) !important;
    color: #ef4444 !important;
}

body.dark-mode #step1ActiveStoreName {
    color: #f8fafc !important;
}

body.dark-mode #storePickupMap {
    border-color: #334155 !important;
}

body.dark-mode .leaflet-popup-content-wrapper {
    background: #1e293b !important;
    color: #f8fafc !important;
    border: 1px solid #334155 !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .leaflet-popup-tip {
    background: #1e293b !important;
}

/* ==========================================================================
   PRE-ORDER INTERACTIVE CALENDAR & TIME SLOTS STYLES
   ========================================================================== */
.preorder-cal-widget {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.cal-widget-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #eaecf0;
}

.cal-widget-title {
    font-family: 'Outfit', sans-serif;
    font-weight: 800;
    font-size: 1.15rem;
    color: #101828;
    letter-spacing: -0.01em;
}

.cal-nav-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid #d0d5dd;
    background: #ffffff;
    color: #344054;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s ease;
}

.cal-nav-btn:hover:not(:disabled) {
    background: #f8f9fa;
    border-color: #98a2b3;
    color: #101828;
}

.cal-nav-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.cal-schedule-policy-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 10px 14px;
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    border-radius: 10px;
    font-size: 0.82rem;
    color: #475467;
    margin-bottom: 16px;
}

.cal-schedule-policy-bar span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.cal-schedule-policy-bar strong {
    color: #101828;
}

.cal-grid-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
    margin-bottom: 8px;
    text-align: center;
}

.cal-grid-weekdays div {
    font-size: 0.78rem;
    font-weight: 700;
    color: #667085;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 6px 0;
}

.cal-grid-days {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
}

.cal-day-cell {
    aspect-ratio: 1;
    min-height: 52px;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4px;
    border: 1px solid #eaecf0;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.18s ease;
    text-decoration: none;
    position: relative;
}

.cal-day-cell.is-blank {
    background: transparent;
    border-color: transparent;
    cursor: default;
}

.cal-day-num {
    font-weight: 800;
    font-size: 0.95rem;
    color: #101828;
    line-height: 1.1;
}

.cal-day-sub {
    font-size: 0.68rem;
    font-weight: 700;
    color: #027a48;
    margin-top: 2px;
    line-height: 1;
}

.cal-day-sub.muted {
    color: #98a2b3;
}

.cal-day-sub.full {
    color: #b3261e;
}

.cal-day-cell.is-available {
    background: #ffffff;
    border-color: #abefc6;
}

.cal-day-cell.is-available:hover {
    border-color: #b3261e;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.12);
}

.cal-day-cell.is-disabled {
    background: #f8f9fa;
    border-color: #eaecf0;
    opacity: 0.55;
    cursor: not-allowed;
}

.cal-day-cell.is-disabled .cal-day-num {
    color: #98a2b3;
}

.cal-day-cell.is-selected {
    background: #b3261e !important;
    border-color: #b3261e !important;
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.35) !important;
}

.cal-day-cell.is-selected .cal-day-num {
    color: #ffffff !important;
}

.cal-day-cell.is-selected .cal-day-sub {
    color: #fedf89 !important;
}

.cal-legend-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #eaecf0;
    justify-content: center;
}

.cal-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: #475467;
    font-weight: 600;
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

.legend-dot.available {
    background: #12b76a;
    border: 1px solid #027a48;
}

.legend-dot.selected {
    background: #b3261e;
}

.legend-dot.disabled {
    background: #d0d5dd;
}

.legend-dot.full {
    background: #f04438;
}

/* Time Slots Grid */
.time-slots-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 10px;
}

.time-slot-card {
    background: #ffffff;
    border: 1.5px solid #eaecf0;
    border-radius: 12px;
    padding: 12px 10px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}

.time-slot-card:hover:not(.is-disabled) {
    border-color: #b3261e;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.12);
}

.time-slot-card.is-selected {
    background: #fff1f0;
    border-color: #b3261e;
    box-shadow: 0 0 0 2px rgba(179, 38, 30, 0.2);
}

.time-slot-card.is-disabled {
    opacity: 0.45;
    cursor: not-allowed;
    background: #f8f9fa;
}

.slot-time {
    font-weight: 800;
    font-size: 0.92rem;
    color: #101828;
    display: flex;
    align-items: center;
    gap: 6px;
}

.slot-time i {
    font-size: 0.8rem;
    color: #b3261e;
}

.slot-label {
    font-size: 0.75rem;
    color: #667085;
}

.slot-badge {
    font-size: 0.68rem;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 999px;
    margin-top: 2px;
}

.slot-badge.badge-open {
    background: #ecfdf3;
    color: #027a48;
}

.slot-badge.badge-full {
    background: #fef3f2;
    color: #b42318;
}

/* Dark Mode Overrides for Calendar Widget & Time Slots */
body.dark-mode .preorder-cal-widget {
    background: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}

body.dark-mode .cal-widget-header {
    border-bottom-color: #334155 !important;
}

body.dark-mode .cal-widget-title {
    color: #f8fafc !important;
}

body.dark-mode .cal-nav-btn {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .cal-nav-btn:hover:not(:disabled) {
    background: #334155 !important;
    border-color: #475569 !important;
}

body.dark-mode .cal-schedule-policy-bar {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .cal-schedule-policy-bar strong {
    color: #f8fafc !important;
}

body.dark-mode .cal-grid-weekdays div {
    color: #94a3b8 !important;
}

body.dark-mode .cal-day-cell {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .cal-day-cell.is-available {
    background: #111827 !important;
    border-color: #166534 !important;
}

body.dark-mode .cal-day-cell.is-available:hover {
    border-color: #ef4444 !important;
    background: #1e293b !important;
}

body.dark-mode .cal-day-num {
    color: #f8fafc !important;
}

body.dark-mode .cal-day-sub {
    color: #4ade80 !important;
}

body.dark-mode .cal-day-sub.muted {
    color: #64748b !important;
}

body.dark-mode .cal-day-sub.full {
    color: #f87171 !important;
}

body.dark-mode .cal-day-cell.is-disabled {
    background: rgba(15, 23, 42, 0.6) !important;
    border-color: #1e293b !important;
    opacity: 0.4;
}

body.dark-mode .cal-day-cell.is-disabled .cal-day-num {
    color: #64748b !important;
}

body.dark-mode .cal-day-cell.is-selected {
    background: #b3261e !important;
    border-color: #b3261e !important;
}

body.dark-mode .cal-legend-bar {
    border-top-color: #334155 !important;
}

body.dark-mode .cal-legend-item {
    color: #cbd5e1 !important;
}

body.dark-mode .time-slot-card {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .time-slot-card:hover:not(.is-disabled) {
    border-color: #ef4444 !important;
}

body.dark-mode .time-slot-card.is-selected {
    background: rgba(179, 38, 30, 0.2) !important;
    border-color: #ef4444 !important;
}

body.dark-mode .time-slot-card.is-disabled {
    background: rgba(15, 23, 42, 0.6) !important;
    border-color: #1e293b !important;
}

body.dark-mode .slot-time {
    color: #f8fafc !important;
}

body.dark-mode .slot-label {
    color: #94a3b8 !important;
}

body.dark-mode .preorder-schedule-selected-badge {
    background: rgba(2, 122, 72, 0.15) !important;
    border-color: rgba(74, 222, 128, 0.4) !important;
    color: #4ade80 !important;
}
</style>

<section class="page-header">
    <div class="container">
        <h1>Pre-Order Your Lechon</h1>
        <p>Plan ahead and secure your feast for your special celebration</p>
    </div>
</section>

<section class="preorder-section">
    <div class="container">
        <div class="preorder-container expanded">
    <div class="checkout-type-switch" aria-label="Checkout Type">
        <p class="checkout-type-title">Checkout Type</p>
        <div class="checkout-type-actions">
            <a href="checkout.php<?php echo htmlspecialchars($store_scope_query); ?>" class="checkout-type-chip">
                <i class="fas fa-bolt"></i> Order Now
            </a>
            <a href="preorder.php<?php echo htmlspecialchars($store_scope_query); ?>" class="checkout-type-chip is-active" aria-current="page">
                <i class="fas fa-calendar-alt"></i> Pre-Order
            </a>
        </div>
        <p class="checkout-type-note">Need ASAP checkout instead? Switch to order now anytime.</p>
    </div>
    <!-- Progress Bar -->
    <div class="progress-bar-container">
        <div class="progress-steps">
            <div class="progress-step active" data-step="1">
                <div class="progress-step-circle">1</div>
                <div class="progress-step-label">Product</div>
            </div>
            <div class="progress-step" data-step="2">
                <div class="progress-step-circle">2</div>
                <div class="progress-step-label">Pick-up</div>
            </div>
            <div class="progress-step" data-step="3">
                <div class="progress-step-circle">3</div>
                <div class="progress-step-label">Payment</div>
            </div>
            <div class="progress-step" data-step="4">
                <div class="progress-step-circle">4</div>
                <div class="progress-step-label">Confirm</div>
            </div>
        </div>
    </div>

    <form id="preorderForm" method="POST">
        <!-- Step 1: Store & Product Selection with Dedicated Pre-Order Cart -->
        <div class="step-content active" data-step="1">
            <div class="preorder-step1-layout">
                <!-- Left Main: Store Selection & Product Catalog -->
                <div class="preorder-catalog-main">
                    <!-- Step 1 Store Choice Hierarchy Grid & Selector Container -->
                    <div class="store-selection-container">
                        <div class="store-selection-header">
                            <div class="store-selection-title">
                                <div class="store-selection-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div>
                                    <h3>Choose Roasting Branch or Partner Store</h3>
                                    <p>Select your preferred Cavite store to view dishes and live roasting availability.</p>
                                </div>
                            </div>
                            <!-- Hidden select for form submission & legacy sync -->
                            <select id="step1StoreSelect" class="form-control" style="display:none;" onchange="onStep1StoreChange(this.value)">
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo (int)$store['id']; ?>"
                                        data-seller-id="<?php echo (int)($store['seller_id'] ?? $store['owner_user_id'] ?? 1); ?>"
                                        data-name="<?php echo htmlspecialchars($store['store_name']); ?>"
                                        data-address="<?php echo htmlspecialchars($store['address'] . ', ' . $store['city'] . ', ' . $store['province']); ?>"
                                        data-phone="<?php echo htmlspecialchars($store['phone'] ?? ''); ?>"
                                        data-hours="<?php echo htmlspecialchars($store['opening_hours'] ?? '8:00 AM - 8:00 PM'); ?>"
                                        data-lat="<?php echo htmlspecialchars($store['latitude'] ?? '14.3294'); ?>"
                                        data-lng="<?php echo htmlspecialchars($store['longitude'] ?? '120.9367'); ?>"
                                        data-city="<?php echo htmlspecialchars($store['city'] ?? ''); ?>"
                                        data-province="<?php echo htmlspecialchars($store['province'] ?? ''); ?>"
                                        data-type="<?php echo htmlspecialchars($store['store_type_label'] ?? 'Pickup Branch'); ?>"
                                        data-category="<?php echo htmlspecialchars($store['store_category'] ?? 'branch'); ?>"
                                        data-reservation="<?php echo !empty($store['has_reservation_schedule']) ? '1' : '0'; ?>">
                                        <?php echo htmlspecialchars($store['store_name']); ?> — <?php echo htmlspecialchars($store['address'] . ', ' . $store['city']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Store Type Hierarchy Tabs -->
                        <div class="store-hierarchy-tabs">
                            <button type="button" class="store-hierarchy-tab active" data-type="all" onclick="filterStoreCardsByType('all', this)">
                                <i class="fas fa-layer-group"></i> All Stores (<?php echo count($stores); ?>)
                            </button>
                            <button type="button" class="store-hierarchy-tab" data-type="branch" onclick="filterStoreCardsByType('branch', this)">
                                <i class="fas fa-building-flag"></i> Official Roasting Hubs (<?php echo count($official_branches); ?>)
                            </button>
                            <button type="button" class="store-hierarchy-tab" data-type="partner" onclick="filterStoreCardsByType('partner', this)">
                                <i class="fas fa-handshake"></i> Partner Stores (<?php echo count($partner_stores); ?>)
                            </button>
                        </div>

                        <!-- City Quick Filters -->
                        <div class="store-city-filters">
                            <button type="button" class="store-city-pill active" data-city="all" onclick="filterStoreCardsByCity('all', this)">
                                <i class="fas fa-globe"></i> All Cities
                            </button>
                            <?php foreach ($store_cities as $scity): ?>
                                <button type="button" class="store-city-pill" data-city="<?php echo htmlspecialchars(strtolower($scity)); ?>" onclick="filterStoreCardsByCity('<?php echo htmlspecialchars(addslashes($scity)); ?>', this)">
                                    <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($scity); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Store Choice Cards Grid (Hierarchical with Image Banners) -->
                        <div class="store-cards-grid" id="storeCardsGrid">
                            <?php foreach ($stores as $idx => $store): 
                                $sId = (int)$store['id'];
                                $sellerId = (int)($store['seller_id'] ?? $store['owner_user_id'] ?? 1);
                                $hasRes = !empty($store['has_reservation_schedule']);
                                $cat = $store['store_category'] ?? 'branch';
                                $isBranch = ($cat === 'branch');
                                $typeLabel = $store['store_type_label'] ?? ($isBranch ? 'Pickup Branch' : 'Partner Store');
                                $storeImg = $store['image'] ?? 'images/store-bg.jpg';
                                $isPreselected = ($requested_seller_id > 0 && $sellerId === $requested_seller_id);
                            ?>
                                <div class="store-card-item <?php echo $isPreselected ? 'selected' : ''; ?>"
                                     id="store-card-<?php echo $sId; ?>"
                                     data-store-id="<?php echo $sId; ?>"
                                     data-seller-id="<?php echo $sellerId; ?>"
                                     data-category="<?php echo htmlspecialchars($cat); ?>"
                                     data-city="<?php echo htmlspecialchars(strtolower($store['city'] ?? '')); ?>"
                                     data-lat="<?php echo htmlspecialchars($store['latitude'] ?? '14.3294'); ?>"
                                     data-lng="<?php echo htmlspecialchars($store['longitude'] ?? '120.9367'); ?>"
                                     data-name="<?php echo htmlspecialchars($store['store_name']); ?>"
                                     data-address="<?php echo htmlspecialchars(($store['address'] ?? '') . ', ' . ($store['city'] ?? '')); ?>"
                                     data-hours="<?php echo htmlspecialchars($store['opening_hours'] ?? '8:00 AM - 8:00 PM'); ?>"
                                     data-phone="<?php echo htmlspecialchars($store['phone'] ?? ''); ?>"
                                     data-reservation="<?php echo $hasRes ? '1' : '0'; ?>"
                                     onclick="selectPreorderStore('<?php echo $sId; ?>', '<?php echo $sellerId; ?>', false, true)">
                                    
                                    <!-- Store Image Banner with Overlaid Badges -->
                                    <div class="store-card-img-banner" style="background-image:url('<?php echo htmlspecialchars($storeImg); ?>');">
                                        <div class="store-card-badge-top-left">
                                            <span class="<?php echo $isBranch ? 'store-badge-branch' : 'store-badge-partner'; ?>">
                                                <i class="<?php echo $isBranch ? 'fas fa-building-flag' : 'fas fa-handshake'; ?>"></i>
                                                <?php echo htmlspecialchars($typeLabel); ?>
                                            </span>
                                            <?php if ($hasRes): ?>
                                                <span class="store-badge-res-tag"><i class="fas fa-calendar-check"></i> Reservation Ready</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="store-card-badge-top-right">
                                            <span class="store-badge-dist" id="store-dist-<?php echo $sId; ?>">
                                                <i class="fas fa-location-arrow"></i> <span class="dist-val">Near Cavite</span>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Store Details Body -->
                                    <div class="store-card-body">
                                        <div>
                                            <div class="store-card-name-row">
                                                <h4 class="store-card-title"><?php echo htmlspecialchars($store['store_name']); ?></h4>
                                                <div class="store-card-check-pill"><i class="fas fa-check"></i></div>
                                            </div>
                                            <div class="store-card-address-row">
                                                <i class="fas fa-location-dot"></i>
                                                <span><?php echo htmlspecialchars(($store['address'] ?? '') . ', ' . ($store['city'] ?? '')); ?></span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="store-card-info-footer">
                                                <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars($store['opening_hours'] ?? '8:00 AM - 8:00 PM'); ?></span>
                                                <?php if (!empty($store['phone'])): ?>
                                                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($store['phone']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="store-card-select-btn">
                                                <i class="fas fa-store"></i> Select Store
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Call-to-action prompt when user hasn't selected a store yet -->
                    <div id="storeSelectPromptBox" class="store-select-prompt-box">
                        <div class="store-select-prompt-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <h4>Select a Roasting Store Above</h4>
                        <p>Click on any of the available Cavite roasting branches or partner stores above to unlock their fresh specialty dishes, roasting schedules, and advance reservation menu.</p>
                    </div>

                    <!-- Progressive Dishes Menu Container (Revealed only after picking a store) -->
                    <div id="dishesMenuWrapper" class="dishes-menu-wrapper">
                        <div class="step-title" style="margin-top: 0; display:flex; align-items:center; flex-wrap:wrap; justify-content:space-between; gap:10px;">
                            <span>Select Your Pre-Order Dishes</span>
                            <span id="step1SelectedStoreBadge" style="font-size:0.82rem; font-weight:700; color:#b3261e; background:#fff1f0; border:1px solid #fee4e2; padding:3px 12px; border-radius:999px;">
                                <i class="fas fa-store"></i> <span id="step1ActiveStoreName"><?php echo htmlspecialchars($stores[0]['store_name'] ?? 'Main Branch'); ?></span>
                            </span>
                        </div>
                        
                        <!-- Category Filter -->
                        <div class="category-nav">
                            <div class="category-list">
                                <button type="button" class="category-link active" data-category="all">All</button>
                                <?php foreach ($categories as $cat): ?>
                                    <button type="button" class="category-link" data-category="<?php echo htmlspecialchars($cat); ?>">
                                        <?php echo htmlspecialchars($cat); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <div id="productList" class="product-grid">
                                <?php if (empty($all_products)): ?>
                                    <p class="empty-product-note"><?php echo $active_seller_id > 0 ? 'No active products are currently posted for this partner.' : 'No active products are currently available.'; ?></p>
                                <?php else: ?>
                                    <?php foreach ($all_products as $p): 
                                        $imgSrc = (string)($p['image'] ?? 'default.jpg');
                                        if ($imgSrc !== '' && $imgSrc !== 'default.jpg') {
                                            if (!str_starts_with($imgSrc, 'http') && !str_contains($imgSrc, '/')) {
                                                $imgSrc = 'images/menu/' . $imgSrc;
                                            }
                                        }
                                    ?>
                                    <div class="product-card" data-product-id="<?php echo (int)$p['id']; ?>" onclick="addToCart(<?php echo (int)$p['id']; ?>)">
                                        <div class="check-icon"><i class="fas fa-check"></i></div>
                                        <div class="product-image">
                                            <?php if ($imgSrc !== '' && $imgSrc !== 'default.jpg'): ?>
                                                <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
                                            <?php else: ?>
                                                <i class="fas fa-drumstick-bite"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-info">
                                            <h4><?php echo htmlspecialchars($p['name']); ?></h4>
                                            <div class="product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                                            <button type="button" class="btn btn-outline btn-sm btn-block btn-add-preorder" data-product-id="<?php echo (int)$p['id']; ?>" onclick="event.stopPropagation(); addToCart(<?php echo (int)$p['id']; ?>)">
                                                <i class="fas fa-plus"></i> Add to Order
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar: Dedicated Sticky Pre-Order Cart Panel -->
                <aside class="preorder-cart-sidebar">
                    <div class="preorder-cart-card">
                        <div class="preorder-cart-header">
                            <h4>
                                <i class="fas fa-calendar-check" style="color:var(--pre-red);"></i>
                                Pre-Order Cart
                                <span id="cartCountBadge" class="preorder-cart-badge" style="display:none;">0</span>
                            </h4>
                            <button type="button" id="clearCartBtn" class="btn-clear-cart" style="display:none;" onclick="clearCart()">
                                <i class="fas fa-trash-alt"></i> Clear
                            </button>
                        </div>
                        <p class="preorder-cart-sub">Items selected exclusively for your advance reservation.</p>

                        <div id="preorderCartItems" class="cart-items-container">
                            <p class="empty-cart-msg"><i class="fas fa-basket-shopping"></i> No items added yet. Click on any dish to add it to your pre-order.</p>
                        </div>

                        <div class="preorder-cart-totals">
                            <div class="preorder-total-line">
                                <span>Estimated Subtotal</span>
                                <strong id="cartSubtotalDisplay">₱0.00</strong>
                            </div>
                            <div class="preorder-total-line">
                                <span>VAT (12%)</span>
                                <strong id="cartVatDisplay">₱0.00</strong>
                            </div>
                            <div class="preorder-total-line grand-total">
                                <span>Estimated Total</span>
                                <strong id="cartTotalDisplay">₱0.00</strong>
                            </div>
                        </div>

                        <div class="preorder-cart-actions">
                            <button type="button" class="btn btn-primary next-btn btn-block">
                                Continue to Pick-up Details <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </aside>
            </div>
        </div>

        <!-- Step 2: Store Pick-up & Schedule Information -->
        <div class="step-content" data-step="2">
            <div class="step-title"><i class="fas fa-store" style="color:var(--pre-red); margin-right:6px;"></i> Store Pick-up Details</div>

            <div class="preorder-pickup-banner">
                <div class="preorder-pickup-badge"><i class="fas fa-bag-shopping"></i> Self Pick-up Order</div>
                <p>This pre-order is for store pick-up. Your lechon feast will be freshly roasted and packaged ready for pick-up at your selected branch.</p>
            </div>

            <!-- Contact Person for Claiming -->
            <div class="preorder-section-block">
                <h4 class="preorder-block-title"><i class="fas fa-user-check"></i> Claimant Contact Information</h4>
                <div class="form-row full">
                    <div class="form-group">
                        <label for="fullName">Full Name (Order Claimant) *</label>
                        <input type="text" id="fullName" name="fullName" placeholder="Enter your full name" value="<?php echo htmlspecialchars($user_profile['full_name']); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Mobile Phone Number *</label>
                        <input type="tel" id="phone" name="phone" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($user_profile['phone']); ?>" required>
                        <small style="color:#667085; font-size:0.78rem;">We will send SMS updates when your roast is hot & ready for pick-up.</small>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" placeholder="your@email.com" value="<?php echo htmlspecialchars($user_profile['email']); ?>" required>
                        <small style="color:#667085; font-size:0.78rem;">Order receipt and Claim QR Code will be sent here.</small>
                    </div>
                </div>
            </div>

            <!-- Pick-up Store Location & Interactive Map -->
            <div class="preorder-section-block">
                <h4 class="preorder-block-title"><i class="fas fa-location-dot"></i> Pick-up Store Branch & Map</h4>
                
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="storeSelect">Select Fulfillment Branch *</label>
                    <select id="storeSelect" name="store_id" class="form-control" onchange="onStoreChange(this.value)">
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo (int)$store['id']; ?>" 
                                data-name="<?php echo htmlspecialchars($store['store_name']); ?>"
                                data-address="<?php echo htmlspecialchars($store['address'] . ', ' . $store['city'] . ', ' . $store['province']); ?>"
                                data-phone="<?php echo htmlspecialchars($store['phone'] ?? ''); ?>"
                                data-hours="<?php echo htmlspecialchars($store['opening_hours'] ?? '8:00 AM - 8:00 PM'); ?>"
                                data-lat="<?php echo htmlspecialchars($store['latitude'] ?? '14.3294'); ?>"
                                data-lng="<?php echo htmlspecialchars($store['longitude'] ?? '120.9367'); ?>"
                                data-city="<?php echo htmlspecialchars($store['city'] ?? ''); ?>"
                                data-province="<?php echo htmlspecialchars($store['province'] ?? ''); ?>">
                                <?php echo htmlspecialchars($store['store_name']); ?> — <?php echo htmlspecialchars($store['address'] . ', ' . $store['city']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Selected Store Details Card -->
                <div class="store-details-card" id="storeInfoCard">
                    <div class="store-details-main">
                        <div class="store-icon"><i class="fas fa-store"></i></div>
                        <div class="store-info-text">
                            <h5 id="storeNameDisplay"><?php echo htmlspecialchars($stores[0]['store_name'] ?? 'Main Branch'); ?></h5>
                            <p class="store-address-p" id="storeAddressDisplay"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars(($stores[0]['address'] ?? '') . ', ' . ($stores[0]['city'] ?? '') . ', ' . ($stores[0]['province'] ?? '')); ?></p>
                            <div class="store-meta-tags">
                                <span class="store-tag" id="storeHoursDisplay"><i class="fas fa-clock"></i> Hours: <?php echo htmlspecialchars($stores[0]['opening_hours'] ?? '8:00 AM - 8:00 PM'); ?></span>
                                <?php if (!empty($stores[0]['phone'])): ?>
                                    <span class="store-tag" id="storePhoneDisplay"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($stores[0]['phone']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo htmlspecialchars($stores[0]['latitude'] ?? '14.3294'); ?>,<?php echo htmlspecialchars($stores[0]['longitude'] ?? '120.9367'); ?>" id="storeDirectionsLink" target="_blank" class="btn-directions">
                        <i class="fas fa-directions"></i> Get Directions
                    </a>
                </div>

                <!-- Store Interactive Leaflet Map -->
                <div class="store-map-wrapper">
                    <div id="storePickupMap" style="height: 280px; width: 100%; border-radius: 12px; border: 1px solid #eaecf0; margin-top: 12px; z-index: 1;"></div>
                    <small style="display:block; margin-top:6px; color:#667085;"><i class="fas fa-circle-info"></i> Map displays the exact store location where you will pick up your feast.</small>
                </div>
            </div>

            <!-- Pick-up Date & Time Schedule -->
            <div class="preorder-section-block">
                <h4 class="preorder-block-title"><i class="fas fa-calendar-check"></i> Select Roasting &amp; Pick-up Schedule</h4>
                <p style="color: #667085; font-size: 0.85rem; margin-top: -6px; margin-bottom: 16px;">
                    Choose an available pick-up date from our store calendar. Available dates and daily roasting batch capacities automatically update in real-time.
                </p>

                <!-- Hidden inputs for seamless form submission & validation -->
                <input type="hidden" id="pickupDate" name="pickupDate" value="" required>
                <input type="hidden" id="pickupTime" name="pickupTime" value="" required>

                <!-- Interactive Calendar Widget -->
                <div class="preorder-cal-widget" id="preorderCalendarWidget">
                    <div class="cal-widget-header">
                        <button type="button" class="cal-nav-btn" id="calPrevMonthBtn" title="Previous Month"><i class="fas fa-chevron-left"></i></button>
                        <div class="cal-widget-title" id="calMonthTitle"><?php echo htmlspecialchars($initial_calendar_data['month_title']); ?></div>
                        <button type="button" class="cal-nav-btn" id="calNextMonthBtn" title="Next Month"><i class="fas fa-chevron-right"></i></button>
                    </div>

                    <div class="cal-schedule-policy-bar">
                        <span><i class="fas fa-clock"></i> <strong>Lead Time:</strong> <?php echo (int)$preorder_schedule['lead_time_days']; ?> day(s) notice</span>
                        <span><i class="fas fa-hourglass-half"></i> <strong>Daily Cutoff:</strong> <?php echo date('g:i A', strtotime($preorder_schedule['cutoff_time'])); ?></span>
                        <span><i class="fas fa-calendar-alt"></i> <strong>Window:</strong> Up to <?php echo (int)$preorder_schedule['max_advance_days']; ?> days ahead</span>
                    </div>

                    <div class="cal-grid-weekdays">
                        <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
                    </div>

                    <div class="cal-grid-days" id="calDaysGrid">
                        <!-- Populated by JavaScript and server initial render -->
                    </div>

                    <div class="cal-legend-bar">
                        <div class="cal-legend-item"><span class="legend-dot available"></span> Available Date</div>
                        <div class="cal-legend-item"><span class="legend-dot selected"></span> Selected</div>
                        <div class="cal-legend-item"><span class="legend-dot disabled"></span> Closed / Cutoff</div>
                        <div class="cal-legend-item"><span class="legend-dot full"></span> Fully Booked</div>
                    </div>
                </div>

                <!-- Available Time Slots for Selected Date -->
                <div class="preorder-slots-section" id="preorderSlotsSection" style="margin-top: 24px; display: none;">
                    <h5 style="font-family:'Outfit',sans-serif; font-weight:700; font-size:1rem; color:#101828; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-clock text-danger"></i> Available Pick-up Time Slots for <span id="slotsSelectedDateText" style="color:#b3261e;"></span>
                    </h5>
                    <div class="time-slots-grid" id="timeSlotsGrid">
                        <!-- Time slot cards dynamically injected here -->
                    </div>
                </div>

                <!-- Selected Date & Time Confirmation Banner -->
                <div class="preorder-schedule-selected-badge" id="scheduleSelectedBadge" style="display: none; margin-top: 18px; background: #ecfdf3; border: 1px solid #abefc6; border-radius: 12px; padding: 14px 18px; color: #027a48; align-items: center; gap: 12px;">
                    <i class="fas fa-circle-check" style="font-size: 1.3rem;"></i>
                    <div>
                        <div style="font-weight: 800; font-size: 0.95rem;">Pick-up Schedule Confirmed</div>
                        <div style="font-size: 0.86rem;" id="scheduleSelectedSummaryText"></div>
                    </div>
                </div>
            </div>

            <!-- Hidden Fields for Backend Compatibility -->
            <input type="hidden" id="streetAddress" name="streetAddress" value="">
            <input type="hidden" id="province" name="province" value="">
            <input type="hidden" id="city" name="city" value="">
            <input type="hidden" id="barangay" name="barangay" value="Store Pick-up">
            <input type="hidden" id="preorder_region_name" name="preorder_region_name" value="">
            <input type="hidden" id="preorder_region_code" name="preorder_region_code" value="">
            <input type="hidden" id="preorder_province_name" name="preorder_province_name" value="">
            <input type="hidden" id="preorder_province_code" name="preorder_province_code" value="">
            <input type="hidden" id="preorder_city_name" name="preorder_city_name" value="">
            <input type="hidden" id="preorder_city_code" name="preorder_city_code" value="">
            <input type="hidden" id="preorder_barangay_name" name="preorder_barangay_name" value="Store Pick-up">
            <input type="hidden" id="preorder_barangay_code" name="preorder_barangay_code" value="">
            <input type="hidden" id="latitude" name="latitude" value="">
            <input type="hidden" id="longitude" name="longitude" value="">

            <div class="button-group">
                <button type="button" class="btn btn-secondary prev-btn">Back</button>
                <button type="button" class="btn btn-primary next-btn">Proceed to Payment <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        <!-- Step 3: Payment -->
        <div class="step-content" data-step="3">
            <div class="step-title">Payment Method</div>

            <div class="payment-options-grid">
                <label class="payment-option-card selected">
                    <input type="radio" name="payment_type" value="full" checked onchange="selectPaymentOption(this)">
                    <div class="option-content">
                        <i class="fas fa-money-bill-wave"></i>
                        <h4>Full Payment</h4>
                        <p>Pay the full amount now via PayMongo.</p>
                    </div>
                </label>
                <label class="payment-option-card">
                    <input type="radio" name="payment_type" value="downpayment" onchange="selectPaymentOption(this)">
                    <div class="option-content">
                        <i class="fas fa-percentage"></i>
                        <h4>30% Downpayment</h4>
                        <p>Pay 30% now, balance upon pickup/delivery.</p>
                    </div>
                </label>
            </div>

            <div class="summary-box">
                <div class="summary-row">
                    <span>Items:</span>
                    <span id="payItemCount">0</span>
                </div>
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="paySubtotal">PHP 0.00</span>
                </div>
                <div class="summary-row">
                    <span>VAT (12%):</span>
                    <span id="payVat">PHP 0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span id="payTotal">₱0.00</span>
                </div>
            </div>

            <div class="button-group">
                <button type="button" class="btn btn-secondary prev-btn">Back</button>
                <button type="button" class="btn btn-primary next-btn">Next</button>
            </div>
        </div>

        <!-- Step 4: Confirmation -->
        <div class="step-content" data-step="4">
            <div class="step-title">Confirm Your Order</div>

            <div class="summary-box" id="confirmSummary">
                <p>Order Summary will appear here</p>
            </div>

            <div class="button-group">
                <button type="button" class="btn btn-secondary prev-btn">Back</button>
                <button type="submit" class="btn btn-primary">Submit Order</button>
            </div>
        </div>
    </form>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const products = <?php echo json_encode($all_products); ?>;
const activeSellerId = <?php echo (int)$active_seller_id; ?>;
const stores = <?php echo json_encode($stores); ?>;
let cart = []; // Array to store selected items: { id, name, price, quantity, image }
const VAT_RATE = 0.12;
let storeMap = null;
let storeMarker = null;
let currentStep = 1;

function getPreorderTotals() {
    const subtotal = cart.reduce((sum, item) => sum + ((parseFloat(item.price) || 0) * (parseInt(item.quantity) || 0)), 0);
    const vatAmount = Math.round(subtotal * VAT_RATE * 100) / 100;
    const total = subtotal + vatAmount;
    return { subtotal, vatAmount, total };
}

function initStoreMap() {
    const mapEl = document.getElementById('storePickupMap');
    if (!mapEl || typeof L === 'undefined') return;

    const storeSelect = document.getElementById('storeSelect');
    if (!storeSelect) return;
    const selectedOpt = storeSelect.options[storeSelect.selectedIndex];
    if (!selectedOpt) return;

    const lat = parseFloat(selectedOpt.dataset.lat) || 14.3294;
    const lng = parseFloat(selectedOpt.dataset.lng) || 120.9367;
    const name = selectedOpt.dataset.name || 'Store Branch';
    const address = selectedOpt.dataset.address || '';
    const hours = selectedOpt.dataset.hours || '8:00 AM - 8:00 PM';
    const phone = selectedOpt.dataset.phone || '';

    // Update Card & Directions Link
    const storeNameEl = document.getElementById('storeNameDisplay');
    const storeAddrEl = document.getElementById('storeAddressDisplay');
    const storeHoursEl = document.getElementById('storeHoursDisplay');
    const storePhoneEl = document.getElementById('storePhoneDisplay');
    const dirLink = document.getElementById('storeDirectionsLink');

    if (storeNameEl) storeNameEl.textContent = name;
    if (storeAddrEl) storeAddrEl.innerHTML = '<i class="fas fa-location-dot"></i> ' + address;
    if (storeHoursEl) storeHoursEl.innerHTML = '<i class="fas fa-clock"></i> Hours: ' + hours;
    if (storePhoneEl) {
        if (phone) {
            storePhoneEl.innerHTML = '<i class="fas fa-phone"></i> ' + phone;
            storePhoneEl.style.display = 'inline-flex';
        } else {
            storePhoneEl.style.display = 'none';
        }
    }
    if (dirLink) dirLink.href = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;

    if (!storeMap) {
        storeMap = L.map('storePickupMap', {
            scrollWheelZoom: false
        }).setView([lat, lng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(storeMap);

        const storeIcon = L.divIcon({
            className: 'store-custom-marker',
            html: '<div style="background:#b3261e; color:#ffffff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 8px rgba(179,38,30,0.35); border:2.5px solid #ffffff;"><i class="fas fa-store" style="font-size:15px;"></i></div>',
            iconSize: [34, 34],
            iconAnchor: [17, 17],
            popupAnchor: [0, -18]
        });

        storeMarker = L.marker([lat, lng], { icon: storeIcon }).addTo(storeMap);
        storeMarker.bindPopup(`<strong>${name}</strong><br>${address}<br><small style="color:#b3261e; font-weight:700;">Pick-up Branch</small>`).openPopup();
    } else {
        storeMap.setView([lat, lng], 15);
        if (storeMarker) {
            storeMarker.setLatLng([lat, lng]);
            storeMarker.setPopupContent(`<strong>${name}</strong><br>${address}<br><small style="color:#b3261e; font-weight:700;">Pick-up Branch</small>`).openPopup();
        }
        setTimeout(() => { storeMap.invalidateSize(); }, 150);
    }
}

function haversineKm(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

let currentStoreCategoryFilter = 'all';
let currentStoreCityFilter = 'all';

function applyStoreCardFilters() {
    const cards = document.querySelectorAll('.store-card-item');
    cards.forEach(card => {
        const cardCat = (card.dataset.category || 'branch').toLowerCase().trim();
        const cardCity = (card.dataset.city || '').toLowerCase().trim();

        const matchCat = (currentStoreCategoryFilter === 'all') || (cardCat === currentStoreCategoryFilter);
        const matchCity = (currentStoreCityFilter === 'all') || cardCity.includes(currentStoreCityFilter) || currentStoreCityFilter.includes(cardCity);

        if (matchCat && matchCity) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

function filterStoreCardsByType(type, btnEl) {
    currentStoreCategoryFilter = (type || 'all').toLowerCase().trim();
    const tabs = document.querySelectorAll('.store-hierarchy-tab');
    tabs.forEach(t => t.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    applyStoreCardFilters();
}

function filterStoreCardsByCity(city, btnEl) {
    currentStoreCityFilter = (city || 'all').toLowerCase().trim();
    const pills = document.querySelectorAll('.store-city-pill');
    pills.forEach(p => p.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    applyStoreCardFilters();
}

function selectPreorderStore(storeId, sellerId, skipScheduleReload, isUserInitiated) {
    const sIdStr = String(storeId);
    const sellerIdNum = parseInt(sellerId) || 1;

    // 1. Update Card Selected States
    const allCards = document.querySelectorAll('.store-card-item');
    allCards.forEach(c => {
        const btn = c.querySelector('.store-card-select-btn');
        if (String(c.dataset.storeId) === sIdStr) {
            c.classList.add('selected');
            if (btn) btn.innerHTML = '<i class="fas fa-check"></i> Selected Store';
        } else {
            c.classList.remove('selected');
            if (btn) btn.innerHTML = '<i class="fas fa-store"></i> Select Store';
        }
    });

    // 2. Reveal Menu & Hide Placeholder Prompt
    const menuWrap = document.getElementById('dishesMenuWrapper');
    const promptBox = document.getElementById('storeSelectPromptBox');
    if (menuWrap) {
        menuWrap.classList.add('is-visible');
    }
    if (promptBox) {
        promptBox.style.display = 'none';
    }

    // 3. Sync hidden / Step 2 selects
    const step1Select = document.getElementById('step1StoreSelect');
    const step2Select = document.getElementById('storeSelect');
    if (step1Select && step1Select.value !== sIdStr) {
        step1Select.value = sIdStr;
    }
    if (step2Select && step2Select.value !== sIdStr) {
        step2Select.value = sIdStr;
    }

    // 4. Update active store text & badges
    const targetCard = document.getElementById('store-card-' + sIdStr) || document.querySelector(`.store-card-item[data-store-id="${sIdStr}"]`);
    if (targetCard) {
        const storeName = targetCard.dataset.name || 'Main Branch';
        const badgeNameEl = document.getElementById('step1ActiveStoreName');
        const nameEl = document.getElementById('step1StoreNameDisplay');
        if (badgeNameEl) badgeNameEl.textContent = storeName;
        if (nameEl) nameEl.textContent = storeName;
    }

    // 5. Update global activeSellerId and activeStoreId
    window.activeSellerId = sellerIdNum;
    window.activeStoreId = sIdStr;

    // 6. Refresh products immediately for this specific shop
    const activeCatBtn = document.querySelector('.category-link.active');
    renderProducts(activeCatBtn ? activeCatBtn.dataset.category : 'all');

    // 7. Sync addresses & Leaflet Map
    syncPreorderStoreAddress();
    initStoreMap();

    // 8. Reload Roasting Schedule for selected store if schedule widget is present
    if (!skipScheduleReload && typeof loadCalendarMonth === 'function' && typeof currentCalMonth !== 'undefined') {
        loadCalendarMonth(currentCalMonth);
    }

    // 9. Smoothly scroll to the dishes menu if user clicked
    if (isUserInitiated && menuWrap) {
        setTimeout(() => {
            menuWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 80);
    }
}

function onStep1StoreChange(val) {
    const step1Select = document.getElementById('step1StoreSelect');
    if (!step1Select) return;
    const selectedOpt = step1Select.options[step1Select.selectedIndex];
    const sellerId = selectedOpt ? (selectedOpt.dataset.sellerId || 1) : 1;
    selectPreorderStore(val, sellerId, false, true);
}

function onStoreChange(val) {
    const step2Select = document.getElementById('storeSelect');
    if (!step2Select) return;
    const selectedOpt = step2Select.options[step2Select.selectedIndex];
    const sellerId = selectedOpt ? (selectedOpt.dataset.sellerId || 1) : 1;
    selectPreorderStore(val, sellerId, false, true);
}

function prioritizeAndSelectNearbyReservationStore() {
    const step1Select = document.getElementById('step1StoreSelect');
    const step2Select = document.getElementById('storeSelect');
    const cardsGrid = document.getElementById('storeCardsGrid');

    let userLat = 14.3294;
    let userLng = 120.9367;
    let hasUserGps = false;

    try {
        const raw = localStorage.getItem('market_address_payload');
        if (raw) {
            const parsed = JSON.parse(raw);
            if (parsed && Number.isFinite(parseFloat(parsed.latitude)) && Number.isFinite(parseFloat(parsed.longitude))) {
                const pLat = parseFloat(parsed.latitude);
                const pLng = parseFloat(parsed.longitude);
                if (pLat !== 0 && pLng !== 0) {
                    userLat = pLat;
                    userLng = pLng;
                    hasUserGps = true;
                }
            }
        }
    } catch (e) {}

    const urlParams = new URLSearchParams(window.location.search);
    const requestedStoreId = urlParams.get('store_id') || urlParams.get('branch_id');
    const requestedSellerId = urlParams.get('seller_id');

    // 1. Calculate distances for Select Options
    const selects = [step1Select, step2Select].filter(Boolean);
    selects.forEach(sel => {
        const options = Array.from(sel.options);
        options.forEach(opt => {
            const lat = parseFloat(opt.dataset.lat) || 14.3294;
            const lng = parseFloat(opt.dataset.lng) || 120.9367;
            const distKm = haversineKm(userLat, userLng, lat, lng);
            opt.dataset.distance = distKm.toFixed(2);
            const hasRes = opt.dataset.reservation === '1';
            const baseName = opt.dataset.name || 'Branch';
            const city = opt.dataset.city || '';

            let label = baseName + (city ? ' — ' + city : '');
            if (hasUserGps) {
                label += ` (~${distKm.toFixed(1)} km away)`;
            }
            if (hasRes) {
                label += ' ★ Reservation Ready';
            }
            opt.textContent = label;
        });

        options.sort((a, b) => {
            const resA = a.dataset.reservation === '1' ? 1 : 0;
            const resB = b.dataset.reservation === '1' ? 1 : 0;
            if (resA !== resB) return resB - resA;
            const distA = parseFloat(a.dataset.distance || '999');
            const distB = parseFloat(b.dataset.distance || '999');
            return distA - distB;
        });

        sel.innerHTML = '';
        options.forEach(opt => sel.appendChild(opt));
    });

    // 2. Calculate distances and sort Store Cards in Grid
    if (cardsGrid) {
        const cardElements = Array.from(cardsGrid.querySelectorAll('.store-card-item'));
        cardElements.forEach(card => {
            const lat = parseFloat(card.dataset.lat) || 14.3294;
            const lng = parseFloat(card.dataset.lng) || 120.9367;
            const distKm = haversineKm(userLat, userLng, lat, lng);
            card.dataset.distance = distKm.toFixed(2);

            const distValEl = card.querySelector('.dist-val');
            if (distValEl) {
                distValEl.textContent = hasUserGps ? `~${distKm.toFixed(1)} km away` : 'Near Cavite';
            }
        });

        cardElements.sort((a, b) => {
            const resA = a.dataset.reservation === '1' ? 1 : 0;
            const resB = b.dataset.reservation === '1' ? 1 : 0;
            if (resA !== resB) return resB - resA;
            const distA = parseFloat(a.dataset.distance || '999');
            const distB = parseFloat(b.dataset.distance || '999');
            return distA - distB;
        });

        cardsGrid.innerHTML = '';
        cardElements.forEach(c => cardsGrid.appendChild(c));
    }

    // 3. Determine initial store to select only if explicitly passed in URL
    let initialStoreId = null;
    let initialSellerId = null;

    if (requestedStoreId) {
        const found = document.querySelector(`.store-card-item[data-store-id="${requestedStoreId}"]`);
        if (found) {
            initialStoreId = requestedStoreId;
            initialSellerId = found.dataset.sellerId;
        }
    } else if (requestedSellerId) {
        const found = document.querySelector(`.store-card-item[data-seller-id="${requestedSellerId}"]`);
        if (found) {
            initialStoreId = found.dataset.storeId;
            initialSellerId = requestedSellerId;
        }
    }

    if (initialStoreId) {
        selectPreorderStore(initialStoreId, initialSellerId, true, false);
    } else {
        // No pre-selected store in URL: show prompt and keep dishes hidden until user picks a store
        const promptBox = document.getElementById('storeSelectPromptBox');
        const menuWrap = document.getElementById('dishesMenuWrapper');
        if (promptBox) promptBox.style.display = 'block';
        if (menuWrap) menuWrap.classList.remove('is-visible');
        syncPreorderStoreAddress();
        initStoreMap();
    }
}

function syncPreorderStoreAddress() {
    const storeSelect = document.getElementById('storeSelect');
    if (!storeSelect) return;
    const selectedOpt = storeSelect.options[storeSelect.selectedIndex];
    if (!selectedOpt) return;

    const storeName = selectedOpt.dataset.name || selectedOpt.text;
    const storeAddress = selectedOpt.dataset.address || '';
    const storeCity = selectedOpt.dataset.city || 'Cavite';
    const storeProvince = selectedOpt.dataset.province || 'Cavite';
    const storeLat = selectedOpt.dataset.lat || '14.3294';
    const storeLng = selectedOpt.dataset.lng || '120.9367';

    const streetAddressInput = document.getElementById('streetAddress');
    if (streetAddressInput) streetAddressInput.value = 'Store Pick-up: ' + storeName + ' (' + storeAddress + ')';
    const provinceInput = document.getElementById('province');
    if (provinceInput) provinceInput.value = storeProvince;
    const cityInput = document.getElementById('city');
    if (cityInput) cityInput.value = storeCity;
    const brgyInput = document.getElementById('barangay');
    if (brgyInput) brgyInput.value = 'Store Pick-up';
    const cityNameInput = document.getElementById('preorder_city_name');
    if (cityNameInput) cityNameInput.value = storeCity;
    const provNameInput = document.getElementById('preorder_province_name');
    if (provNameInput) provNameInput.value = storeProvince;
    const brgyNameInput = document.getElementById('preorder_barangay_name');
    if (brgyNameInput) brgyNameInput.value = 'Store Pick-up';
    const latInput = document.getElementById('latitude');
    if (latInput) latInput.value = storeLat;
    const lngInput = document.getElementById('longitude');
    if (lngInput) lngInput.value = storeLng;
}

function showPreorderToast(msg) {
    if (window.showToast) {
        window.showToast(msg, 'success', 2500);
        return;
    }
    if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: msg,
            showConfirmButton: false,
            timer: 1500
        });
        return;
    }
    let toast = document.getElementById('preorderToastNotice');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'preorderToastNotice';
        toast.style.cssText = 'position:fixed; top:24px; right:24px; z-index:99999; background:#101828; color:#ffffff; padding:12px 20px; border-radius:10px; font-weight:700; font-size:0.9rem; box-shadow:0 8px 24px rgba(0,0,0,0.2); transition:all 0.3s ease; display:flex; align-items:center; gap:8px;';
        document.body.appendChild(toast);
    }
    toast.innerHTML = '<i class="fas fa-check-circle" style="color:#12b76a;"></i> ' + msg;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';
    setTimeout(() => {
        if (toast) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
        }
    }, 1800);
}

// Pre-Order Roasting Calendar & Time Slot Controller
let currentCalMonth = <?php echo json_encode($initial_calendar_data['current_month']); ?>;
let selectedPickupDate = '';
let selectedPickupTime = '';

async function loadCalendarMonth(monthStr) {
    const daysGrid = document.getElementById('calDaysGrid');
    const monthTitle = document.getElementById('calMonthTitle');
    const prevBtn = document.getElementById('calPrevMonthBtn');
    const nextBtn = document.getElementById('calNextMonthBtn');
    
    if (!daysGrid) return;
    daysGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #667085;"><i class="fas fa-spinner fa-spin"></i> Loading schedule...</div>';

    try {
        const res = await fetch(`api/preorder_schedule.php?action=get_calendar&seller_id=${activeSellerId}&month=${encodeURIComponent(monthStr || '')}`);
        const json = await res.json();
        if (json.success && json.data) {
            const data = json.data;
            currentCalMonth = data.current_month;
            if (monthTitle) monthTitle.textContent = data.month_title;
            
            if (prevBtn) {
                prevBtn.disabled = !data.prev_month;
                prevBtn.onclick = () => data.prev_month && loadCalendarMonth(data.prev_month);
            }
            if (nextBtn) {
                nextBtn.disabled = !data.next_month;
                nextBtn.onclick = () => data.next_month && loadCalendarMonth(data.next_month);
            }

            renderCalendarDays(data);
        }
    } catch (e) {
        console.error('Error loading calendar:', e);
        daysGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #b3261e;">Failed to load schedule. Please try again.</div>';
    }
}

function renderCalendarDays(calData) {
    const daysGrid = document.getElementById('calDaysGrid');
    if (!daysGrid) return;
    daysGrid.innerHTML = '';

    // Leading blanks
    for (let i = 1; i < calData.first_day_weekday; i++) {
        const blank = document.createElement('div');
        blank.className = 'cal-day-cell is-blank';
        daysGrid.appendChild(blank);
    }

    calData.days.forEach(day => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `cal-day-cell is-day ${day.available ? 'is-available' : 'is-disabled'}`;
        if (day.date === selectedPickupDate) {
            btn.classList.add('is-selected');
        }
        btn.title = day.status_reason;

        let statusSub = '';
        if (day.available) {
            statusSub = `<span class="cal-day-sub">${day.remaining_capacity} left</span>`;
        } else if (day.status === 'lead_time_cutoff') {
            statusSub = `<span class="cal-day-sub muted">Cutoff</span>`;
        } else if (day.status === 'closed_weekday') {
            statusSub = `<span class="cal-day-sub muted">Closed</span>`;
        } else if (day.status === 'fully_booked') {
            statusSub = `<span class="cal-day-sub full">Full</span>`;
        } else if (day.status === 'blackout') {
            statusSub = `<span class="cal-day-sub full">Holiday</span>`;
        }

        btn.innerHTML = `<span class="cal-day-num">${day.day}</span>${statusSub}`;

        if (day.available) {
            btn.addEventListener('click', () => {
                selectCalendarDate(day.date, day);
            });
        }

        daysGrid.appendChild(btn);
    });
}

async function selectCalendarDate(dateStr, dayData) {
    selectedPickupDate = dateStr;
    const pickupDateInput = document.getElementById('pickupDate');
    if (pickupDateInput) pickupDateInput.value = dateStr;

    // Reset selected time
    selectedPickupTime = '';
    const pickupTimeInput = document.getElementById('pickupTime');
    if (pickupTimeInput) pickupTimeInput.value = '';

    const badge = document.getElementById('scheduleSelectedBadge');
    if (badge) badge.style.display = 'none';

    document.querySelectorAll('.cal-day-cell.is-day').forEach(cell => cell.classList.remove('is-selected'));
    const allCells = document.querySelectorAll('.cal-day-cell.is-day');
    allCells.forEach(cell => {
        if (cell.title && cell.title.includes(dateStr)) cell.classList.add('is-selected');
    });

    await loadTimeSlotsForDate(dateStr);
}

async function loadTimeSlotsForDate(dateStr) {
    const slotsSection = document.getElementById('preorderSlotsSection');
    const slotsGrid = document.getElementById('timeSlotsGrid');
    const dateText = document.getElementById('slotsSelectedDateText');

    if (!slotsSection || !slotsGrid) return;

    slotsSection.style.display = 'block';
    if (dateText) {
        const dObj = new Date(dateStr + 'T00:00:00');
        dateText.textContent = dObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }

    slotsGrid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:15px; color:#667085;"><i class="fas fa-spinner fa-spin"></i> Loading available slots...</div>';

    try {
        const res = await fetch(`api/preorder_schedule.php?action=get_slots&seller_id=${activeSellerId}&date=${encodeURIComponent(dateStr)}`);
        const json = await res.json();
        if (json.success && json.slots) {
            renderTimeSlots(json.slots);
        }
    } catch (e) {
        console.error('Error loading time slots:', e);
        slotsGrid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:15px; color:#b3261e;">Failed to load time slots.</div>';
    }
}

function renderTimeSlots(slots) {
    const slotsGrid = document.getElementById('timeSlotsGrid');
    if (!slotsGrid) return;
    slotsGrid.innerHTML = '';

    if (slots.length === 0) {
        slotsGrid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:15px; color:#667085;">No pickup time slots configured for this date.</div>';
        return;
    }

    slots.forEach(slot => {
        const card = document.createElement('button');
        card.type = 'button';
        card.className = `time-slot-card ${slot.is_available ? 'is-available' : 'is-disabled'}`;
        if (slot.time_value === selectedPickupTime) {
            card.classList.add('is-selected');
        }

        card.innerHTML = `
            <div class="slot-time"><i class="fas fa-clock"></i> ${slot.time_value}</div>
            <div class="slot-label">${slot.display_label}</div>
            <span class="slot-badge ${slot.is_available ? 'badge-open' : 'badge-full'}">${slot.badge_text}</span>
        `;

        if (slot.is_available) {
            card.addEventListener('click', (e) => {
                selectTimeSlot(slot.time_value, slot.display_label, card);
            });
        }

        slotsGrid.appendChild(card);
    });
}

function selectTimeSlot(timeVal, label, clickedEl) {
    selectedPickupTime = timeVal;
    const pickupTimeInput = document.getElementById('pickupTime');
    if (pickupTimeInput) pickupTimeInput.value = timeVal;

    document.querySelectorAll('.time-slot-card').forEach(c => c.classList.remove('is-selected'));
    if (clickedEl) clickedEl.classList.add('is-selected');

    const badge = document.getElementById('scheduleSelectedBadge');
    const summaryText = document.getElementById('scheduleSelectedSummaryText');
    if (badge && summaryText && selectedPickupDate) {
        const dObj = new Date(selectedPickupDate + 'T00:00:00');
        const formattedDate = dObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        summaryText.innerHTML = `<strong>${formattedDate}</strong> at <strong>${timeVal}</strong> (${label})`;
        badge.style.display = 'flex';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    renderProducts('all');
    setupButtons();
    setupFilters();
    setupProgressNavigation();
    prioritizeAndSelectNearbyReservationStore();
    loadCalendarMonth(currentCalMonth);

    window.addEventListener('storage', function (e) {
        if (e.key === 'market_address_payload' || e.key === 'market_address') {
            prioritizeAndSelectNearbyReservationStore();
        }
    });
    window.addEventListener('marketAddressChanged', prioritizeAndSelectNearbyReservationStore);
    window.addEventListener('marketAddressUpdated', prioritizeAndSelectNearbyReservationStore);
    
    // Auto-add product if routed with product_id (e.g. from Menu 'Reserve Event Date')
    const preselectedProductId = <?php echo (int)$requested_product_id; ?>;
    if (preselectedProductId > 0) {
        addToCart(preselectedProductId);
    }
});

function setupFilters() {
    const buttons = document.querySelectorAll('.category-link');
    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            buttons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderProducts(btn.dataset.category);
        });
    });
}

function renderProducts(category) {
    const productList = document.getElementById('productList');
    if (!productList) return;
    
    const cat = category || 'all';
    const officialOwnerIds = [0, 1, 42, 43, 44, 45];

    // Identify current selected store
    const sIdStr = String(window.activeStoreId || '');
    let currentStore = null;
    if (typeof stores !== 'undefined' && Array.isArray(stores)) {
        currentStore = stores.find(s => String(s.id) === sIdStr || String(s.store_id) === sIdStr);
    }
    const storeOwnerId = currentStore ? parseInt(currentStore.seller_id || currentStore.owner_user_id || 1) : parseInt(window.activeSellerId || 1);
    const isPartnerStore = currentStore && (currentStore.store_category === 'partner' || currentStore.store_type_label === 'Partner Store' || !officialOwnerIds.includes(storeOwnerId));

    // Isolate products belonging strictly to the selected business shop
    let storeProducts = [];
    if (isPartnerStore) {
        // Partner Store: strictly only items where seller_id matches this partner
        storeProducts = products.filter(p => parseInt(p.seller_id) === storeOwnerId);
    } else {
        // Official Branch: foods with seller_id = 1, seller_id is null, or matching branch owner
        storeProducts = products.filter(p => {
            const sId = p.seller_id ? parseInt(p.seller_id) : 1;
            return sId === 1 || sId === storeOwnerId || !p.seller_id;
        });
    }

    const filtered = cat === 'all' ? storeProducts : storeProducts.filter(p => p.category === cat);

    if (filtered.length === 0) {
        const storeName = currentStore ? (currentStore.store_name || 'this shop') : 'this shop';
        const emptyMessage = storeProducts.length === 0
            ? `No menu items are currently available for ${storeName}. Please choose another branch or shop.`
            : `No items found in this category for ${storeName}.`;
        productList.innerHTML = `
            <div style="grid-column: 1 / -1; text-align: center; padding: 40px 20px; background: #ffffff; border: 1px dashed #d0d5dd; border-radius: 14px; color: #475467;">
                <i class="fas fa-utensils" style="font-size: 2rem; color: #98a2b3; margin-bottom: 10px; display: block;"></i>
                <h4 style="margin: 0 0 6px; font-family: 'Outfit', sans-serif; font-size: 1.05rem; color: #101828; font-weight: 700;">No Menu Items Available</h4>
                <p style="margin: 0; font-size: 0.85rem; color: #667085;">${emptyMessage}</p>
            </div>
        `;
        return;
    }
    
    productList.innerHTML = filtered.map(p => {
        let imageHtml = '';
        if (p.image && p.image !== 'default.jpg' && p.image !== '') {
            let imgSrc = p.image;
            if (!imgSrc.startsWith('http') && !imgSrc.includes('/')) {
                imgSrc = 'images/menu/' + imgSrc;
            }
            imageHtml = `<img src="${imgSrc}" alt="${p.name}">`;
        } else {
            imageHtml = `<i class="fas fa-drumstick-bite"></i>`;
        }
        
        const inCart = cart.find(i => String(i.id) === String(p.id) || String(i.product_id) === String(p.product_id));
        const qty = inCart ? (parseInt(inCart.quantity) || 0) : 0;
        const isSelected = qty > 0;
        const priceNum = parseFloat(p.price) || 0;
        const pStock = typeof p.stock !== 'undefined' ? parseInt(p.stock) : 10;
        const isSoldOut = pStock <= 0;
        
        return `
            <div class="product-card ${isSelected ? 'selected' : ''} ${isSoldOut ? 'product-sold-out' : ''}" data-product-id="${p.id}" onclick="${isSoldOut ? '' : `addToCart(${p.id})`}">
                <div class="check-icon"><i class="fas fa-check"></i></div>
                <div class="product-image">
                    ${imageHtml}
                    ${isSoldOut ? '<div style="position:absolute;inset:0;background:rgba(16,24,40,0.65);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.8rem;border-radius:10px;">SOLD OUT</div>' : ''}
                </div>
                <div class="product-info">
                    <h4>${p.name}</h4>
                    <div class="product-price">₱${priceNum.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                    <div style="margin: 4px 0 8px; display: flex; align-items: center; gap: 4px;">
                        ${!isSoldOut 
                            ? `<span style="font-size:0.72rem; font-weight:700; color:#027a48; background:#ecfdf3; border:1px solid #abefc6; padding:2px 6px; border-radius:4px;"><i class="fas fa-boxes-stacked"></i> ${pStock} in stock</span>`
                            : `<span style="font-size:0.72rem; font-weight:700; color:#b3261e; background:#fff1f0; border:1px solid #fee4e2; padding:2px 6px; border-radius:4px;"><i class="fas fa-ban"></i> Out of stock</span>`
                        }
                    </div>
                    <button type="button" class="btn ${isSelected ? 'btn-primary' : 'btn-outline'} btn-sm btn-block btn-add-preorder" data-product-id="${p.id}" ${isSoldOut ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : `onclick="event.stopPropagation(); addToCart(${p.id})"`}>
                        ${isSoldOut ? '<i class="fas fa-ban"></i> Sold Out' : (isSelected ? `<i class="fas fa-check"></i> Added (${qty})` : '<i class="fas fa-plus"></i> Add to Order')}
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function addToCart(productId) {
    if (!productId) return;
    const pIdStr = String(productId);
    const product = products.find(p => String(p.id) === pIdStr || (p.product_id && String(p.product_id) === pIdStr));
    if (!product) {
        console.warn('Product not found for ID:', productId);
        return;
    }

    const pStock = typeof product.stock !== 'undefined' ? parseInt(product.stock) : 10;
    if (pStock <= 0) {
        showPreorderToast('Sorry, ' + product.name + ' is currently out of stock.');
        return;
    }
    
    const existing = cart.find(i => String(i.id) === String(product.id) || (i.product_id && String(i.product_id) === String(product.product_id)));
    if (existing) {
        if (existing.quantity >= pStock) {
            showPreorderToast('Cannot add more than available stock (' + pStock + ' max).');
            return;
        }
        existing.quantity = (parseInt(existing.quantity) || 1) + 1;
    } else {
        cart.push({
            id: product.id,
            product_id: product.product_id || '',
            name: product.name,
            price: parseFloat(product.price) || 0,
            image: product.image || 'default.jpg',
            quantity: 1
        });
    }
    
    showPreorderToast('Added ' + product.name + ' to Pre-Order Cart');
    
    updateCartUI();
    const activeBtn = document.querySelector('.category-link.active');
    renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
}

function updateCartItemQty(id, change) {
    const idx = cart.findIndex(i => String(i.id) === String(id) || String(i.product_id) === String(id));
    if (idx === -1) return;
    
    cart[idx].quantity = (parseInt(cart[idx].quantity) || 1) + change;
    if (cart[idx].quantity <= 0) {
        cart.splice(idx, 1);
    }
    
    updateCartUI();
    const activeBtn = document.querySelector('.category-link.active');
    renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
}

function removeFromCart(id) {
    const idx = cart.findIndex(i => String(i.id) === String(id) || String(i.product_id) === String(id));
    if (idx !== -1) {
        cart.splice(idx, 1);
        updateCartUI();
        const activeBtn = document.querySelector('.category-link.active');
        renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
    }
}

function clearCart() {
    if (cart.length === 0) return;
    cart = [];
    updateCartUI();
    const activeBtn = document.querySelector('.category-link.active');
    renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
}

function updateCartUI() {
    const container = document.getElementById('preorderCartItems');
    const totalDisplay = document.getElementById('cartTotalDisplay');
    const subtotalDisplay = document.getElementById('cartSubtotalDisplay');
    const vatDisplay = document.getElementById('cartVatDisplay');
    const countBadge = document.getElementById('cartCountBadge');
    const clearBtn = document.getElementById('clearCartBtn');

    if (!container) return;
    
    const totals = getPreorderTotals();
    const itemCount = cart.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
    
    if (cart.length === 0) {
        container.innerHTML = '<p class="empty-cart-msg"><i class="fas fa-basket-shopping" style="font-size:1.4rem; color:#98a2b3; display:block; margin-bottom:6px;"></i> No items selected yet.<br><span style="font-size:0.78rem; color:#98a2b3;">Select dishes to add to pre-order</span></p>';
        if (countBadge) countBadge.style.display = 'none';
        if (clearBtn) clearBtn.style.display = 'none';
    } else {
        if (countBadge) {
            countBadge.textContent = itemCount;
            countBadge.style.display = 'inline-flex';
        }
        if (clearBtn) clearBtn.style.display = 'inline-flex';

        const itemsHtml = cart.map(item => {
            let imageHtml = '<div class="cart-item-thumb-placeholder"><i class="fas fa-drumstick-bite"></i></div>';
            if (item.image && item.image !== 'default.jpg' && item.image !== '') {
                let imgSrc = item.image;
                if (!imgSrc.startsWith('http') && !imgSrc.includes('/')) {
                    imgSrc = 'images/menu/' + imgSrc;
                }
                imageHtml = `<img src="${imgSrc}" alt="${item.name}" class="cart-item-thumb">`;
            }

            const itemPrice = parseFloat(item.price) || 0;
            const itemQty = parseInt(item.quantity) || 1;
            const itemTotal = itemPrice * itemQty;

            return `
            <div class="cart-item-row">
                <div class="cart-item-image-col">${imageHtml}</div>
                <div class="cart-item-details-col">
                    <div class="cart-item-name">${item.name}</div>
                    <div class="cart-item-price-single">₱${itemPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} each</div>
                    <div class="cart-item-controls">
                        <button type="button" class="tiny-btn" onclick="updateCartItemQty(${item.id}, -1)"><i class="fas fa-minus"></i></button>
                        <span class="qty-display">${itemQty}</span>
                        <button type="button" class="tiny-btn" onclick="updateCartItemQty(${item.id}, 1)"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <div class="cart-item-total-col">
                    <div class="cart-item-price">₱${itemTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                    <button type="button" class="remove-item-btn" onclick="removeFromCart(${item.id})" title="Remove item"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            `;
        }).join('');
        
        container.innerHTML = itemsHtml;
    }
    
    if (subtotalDisplay) subtotalDisplay.textContent = '₱' + totals.subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (vatDisplay) vatDisplay.textContent = '₱' + totals.vatAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (totalDisplay) totalDisplay.textContent = '₱' + totals.total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    updateSummary();
}

window.addToCart = addToCart;
window.updateCartItemQty = updateCartItemQty;
window.removeFromCart = removeFromCart;
window.clearCart = clearCart;
window.renderProducts = renderProducts;

function setupProgressNavigation() {
    document.querySelectorAll('.progress-step').forEach(stepEl => {
        stepEl.addEventListener('click', function() {
            const targetStep = parseInt(this.dataset.step, 10);
            if (this.classList.contains('completed') || this.classList.contains('active')) {
                goToStep(targetStep);
            } else {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: 'Please use the "Next" button to proceed.',
                    showConfirmButton: false,
                    timer: 2000
                });
            }
        });
    });
}

function updateSummary() {
    const totals = getPreorderTotals();
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    
    const countEl = document.getElementById('payItemCount');
    if (countEl) countEl.textContent = itemCount;
    const subtotalElement = document.getElementById('paySubtotal');
    const vatElement = document.getElementById('payVat');
    if (subtotalElement) subtotalElement.textContent = '₱' + totals.subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (vatElement) vatElement.textContent = '₱' + totals.vatAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const totalEl = document.getElementById('payTotal');
    if (totalEl) totalEl.textContent = '₱' + totals.total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function setupButtons() {
    document.querySelectorAll('.next-btn').forEach(btn => {
        btn.addEventListener('click', nextStep);
    });
    document.querySelectorAll('.prev-btn').forEach(btn => {
        btn.addEventListener('click', prevStep);
    });
}

function nextStep() {
    if (validateStep(currentStep)) {
        goToStep(currentStep + 1);
    }
}

function prevStep() {
    if (currentStep > 1) {
        goToStep(currentStep - 1);
    }
}

function goToStep(step) {
    const allSteps = document.querySelectorAll('.step-content');
    allSteps.forEach(el => el.classList.remove('active'));
    
    const targetStep = document.querySelector(`.step-content[data-step="${step}"]`);
    if (targetStep) {
        targetStep.classList.add('active');
    }
    
    document.querySelectorAll('.progress-step').forEach((el, idx) => {
        el.classList.remove('active', 'completed');
        if (idx + 1 === step) el.classList.add('active');
        if (idx + 1 < step) el.classList.add('completed');
    });
    
    currentStep = step;
    window.scrollTo(0, 0);

    if (step === 2) {
        setTimeout(initStoreMap, 150);
    }
}

function validateStep(step) {
    if (step === 1) {
        if (cart.length === 0) {
            if (window.showToast) {
                window.showToast('Please select at least one product for your pre-order.', 'alert');
            } else if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Selection Required',
                    text: 'Please select at least one product for your pre-order.',
                    icon: 'warning',
                    confirmButtonText: 'Got it'
                });
            }
            return false;
        }
    } else if (step === 2) {
        const fullName = document.getElementById('fullName').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const pickupDate = document.getElementById('pickupDate').value.trim();
        const pickupTime = document.getElementById('pickupTime').value.trim();

        if (!fullName) {
            if (window.showToast) window.showToast('Please enter the full name of the person picking up the order.', 'alert');
            else Swal.fire({ title: 'Claimant Name Required', text: 'Please enter full name.', icon: 'warning' });
            return false;
        }
        if (!email) {
            if (window.showToast) window.showToast('Please enter your email address for order confirmation.', 'alert');
            else Swal.fire({ title: 'Email Required', text: 'Please enter email address.', icon: 'warning' });
            return false;
        }
        if (!phone) {
            if (window.showToast) window.showToast('Please enter your mobile phone number.', 'alert');
            else Swal.fire({ title: 'Phone Required', text: 'Please enter mobile phone number.', icon: 'warning' });
            return false;
        }
        if (!pickupDate) {
            if (window.showToast) window.showToast('Please select your preferred pick-up date.', 'alert');
            else Swal.fire({ title: 'Pick-up Date Required', text: 'Please select pick-up date.', icon: 'warning' });
            return false;
        }
        if (!pickupTime) {
            if (window.showToast) window.showToast('Please select your preferred pick-up time slot.', 'alert');
            else Swal.fire({ title: 'Time Slot Required', text: 'Please select time slot.', icon: 'warning' });
            return false;
        }
        syncPreorderStoreAddress();
    } else if (step === 3) {
        populateConfirmation();
    }
    return true;
}

function selectPaymentOption(radio) {
    document.querySelectorAll('.payment-option-card').forEach(el => el.classList.remove('selected'));
    radio.closest('.payment-option-card').classList.add('selected');
    updateSummary();
}

function populateConfirmation() {
    const totals = getPreorderTotals();
    const subtotal = totals.subtotal;
    const vatAmount = totals.vatAmount;
    const total = totals.total;
    const paymentType = document.querySelector('input[name="payment_type"]:checked').value;
    const paymentAmount = paymentType === 'downpayment' ? total * 0.30 : total;
    const remaining = total - paymentAmount;

    const fullName = document.getElementById('fullName').value;
    const email = document.getElementById('email').value;
    const phone = document.getElementById('phone').value;
    const pickupDate = document.getElementById('pickupDate').value;
    const pickupTime = document.getElementById('pickupTime').value;

    const storeSelect = document.getElementById('storeSelect');
    const selectedStoreOpt = storeSelect ? storeSelect.options[storeSelect.selectedIndex] : null;
    const storeName = selectedStoreOpt ? (selectedStoreOpt.dataset.name || selectedStoreOpt.text) : 'Main Branch';
    const storeAddress = selectedStoreOpt ? (selectedStoreOpt.dataset.address || '') : '';

    let itemsTable = `
        <table class="confirmation-table" style="width:100%; border-collapse: collapse; margin-bottom: 15px;">
            <thead>
                <tr style="border-bottom: 1px solid #eaecf0; background-color: #f8f9fa;">
                    <th style="text-align: left; padding: 10px; font-size: 0.85rem; color:#475467;">Dish / Item</th>
                    <th style="text-align: center; padding: 10px; font-size: 0.85rem; color:#475467;">Qty</th>
                    <th style="text-align: right; padding: 10px; font-size: 0.85rem; color:#475467;">Total</th>
                </tr>
            </thead>
            <tbody>
                ${cart.map(item => `
                    <tr style="border-bottom: 1px solid #f2f4f7;">
                        <td style="padding: 10px; font-size: 0.92rem; font-weight:600; color:#101828;">${item.name}</td>
                        <td style="text-align: center; padding: 10px; font-size: 0.92rem; color:#475467;">${item.quantity}</td>
                        <td style="text-align: right; padding: 10px; font-size: 0.92rem; font-weight:700; color:#101828;">₱${(item.price * item.quantity).toLocaleString()}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;

    let summaryHTML = `
        <h4 style="color: #b3261e; margin-bottom: 16px; font-family:'Outfit', sans-serif; font-size:1.15rem;"><i class="fas fa-clipboard-check"></i> Pre-Order Summary</h4>
        ${itemsTable}
        <div class="summary-row">
            <span>Subtotal:</span>
            <strong>₱${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
        </div>
        <div class="summary-row">
            <span>VAT (12%):</span>
            <strong>₱${vatAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
        </div>
        <div class="summary-row total">
            <span>Grand Total (Incl. VAT):</span>
            <strong>₱${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
        </div>
        <hr style="margin: 16px 0; border: none; border-top: 1px solid #eaecf0;">
        <h4 style="color: #b3261e; margin: 16px 0; font-family:'Outfit', sans-serif; font-size:1.15rem;"><i class="fas fa-store"></i> Store Pick-up Details</h4>
        <div class="summary-row">
            <span><strong>Fulfillment Branch:</strong></span>
            <span style="color:#101828; font-weight:700;">${storeName}</span>
        </div>
        <div class="summary-row">
            <span><strong>Store Address:</strong></span>
            <span>${storeAddress}</span>
        </div>
        <div class="summary-row">
            <span><strong>Scheduled Date:</strong></span>
            <span style="color:#027a48; font-weight:700;">${new Date(pickupDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
        </div>
        <div class="summary-row">
            <span><strong>Pick-up Time Slot:</strong></span>
            <span style="color:#027a48; font-weight:700;">${pickupTime}</span>
        </div>
        <hr style="margin: 16px 0; border: none; border-top: 1px solid #eaecf0;">
        <h4 style="color: #b3261e; margin: 16px 0; font-family:'Outfit', sans-serif; font-size:1.15rem;"><i class="fas fa-user-check"></i> Claimant Information</h4>
        <div class="summary-row">
            <span><strong>Full Name:</strong></span>
            <span>${fullName}</span>
        </div>
        <div class="summary-row">
            <span><strong>Mobile Phone:</strong></span>
            <span>${phone}</span>
        </div>
        <div class="summary-row">
            <span><strong>Email:</strong></span>
            <span>${email}</span>
        </div>
        <hr style="margin: 16px 0; border: none; border-top: 1px solid #eaecf0;">
        <h4 style="color: #b3261e; margin: 16px 0; font-family:'Outfit', sans-serif; font-size:1.15rem;"><i class="fas fa-credit-card"></i> Payment Breakdown</h4>
        <div class="summary-row">
            <span><strong>Payment Option:</strong></span>
            <span>${paymentType === 'downpayment' ? '30% Downpayment' : 'Full Payment'}</span>
        </div>
        <div class="summary-row">
            <span><strong>Amount to Pay Now:</strong></span>
            <span style="color: #b3261e; font-size:1.1rem; font-weight: 800;">₱${paymentAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
        </div>
    `;

    if (paymentType === 'downpayment') {
        summaryHTML += `
        <div class="summary-row">
            <span><strong>Remaining Balance (Upon Pick-up):</strong></span>
            <span style="color:#475467; font-weight:700;">₱${remaining.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
        </div>
        `;
    }

    document.getElementById('confirmSummary').innerHTML = summaryHTML;
}

document.getElementById('preorderForm').addEventListener('submit', function(e) {
    e.preventDefault();
    syncPreorderStoreAddress();
    
    if (cart.length === 0) {
        Swal.fire('Error', 'Your cart is empty', 'error');
        return;
    }

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing Payment...';

    const formData = {
        items: cart,
        full_name: document.getElementById('fullName').value,
        email: document.getElementById('email').value,
        phone: document.getElementById('phone').value,
        street_address: document.getElementById('streetAddress').value,
        province: document.getElementById('province').value,
        city: document.getElementById('city').value,
        barangay: document.getElementById('barangay').value,
        region_name: document.getElementById('preorder_region_name').value,
        region_code: document.getElementById('preorder_region_code').value,
        province_name: document.getElementById('preorder_province_name').value,
        province_code: document.getElementById('preorder_province_code').value,
        city_name: document.getElementById('preorder_city_name').value,
        city_code: document.getElementById('preorder_city_code').value,
        barangay_name: document.getElementById('preorder_barangay_name').value,
        barangay_code: document.getElementById('preorder_barangay_code').value,
        latitude: document.getElementById('latitude').value,
        longitude: document.getElementById('longitude').value,
        pickup_date: document.getElementById('pickupDate').value,
        pickup_time: document.getElementById('pickupTime').value,
        payment_type: document.querySelector('input[name="payment_type"]:checked').value,
        seller_id: activeSellerId
    };

    fetch('process_preorder_payment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(formData)
    })
    .then(response => {
        return response.text().then(text => {
            try {
                const parsed = JSON.parse(text);
                return parsed;
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('Server returned invalid response: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        if (data.success) {
            window.location.href = data.checkout_url;
        } else {
            const errorMsg = data.error || 'Payment processing failed. Please try again.';
            console.error('Payment error:', errorMsg);
            Swal.fire('Error', errorMsg, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    })
    .catch(error => {
        console.error('Request failed:', error.message);
        let errorMessage = error.message;
        if (error.message.includes('JSON')) {
            errorMessage = 'Server response error. Please check the browser console for details.';
        }
        Swal.fire('Error', errorMessage, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
});
</script>


<?php include 'includes/footer.php'; ?>
