<?php
session_start();
require_once 'includes/config.php';
require_once __DIR__ . '/includes/favorites_helper.php';
require_once __DIR__ . '/includes/store_availability_helper.php';
require_once __DIR__ . '/includes/partner_advertisement_helper.php';

$current_page = 'home';
$page_title = 'Marketplace Home';
$show_welcome = !empty($_SESSION['register_success']);
$user_email = $_SESSION['register_email'] ?? '';
unset($_SESSION['register_success'], $_SESSION['register_email']);

function asset_path($path, $fallback = 'images/store-bg.jpg') {
    $path = trim((string)$path);
    if ($path !== '' && (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0)) return $path;
    $candidates = [];
    if ($path !== '') {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $candidates[] = $path;
        if (strpos($path, '/') === false) {
            $candidates[] = 'uploads/products/' . $path;
            $candidates[] = 'images/menu/' . $path;
            $candidates[] = 'images/' . $path;
        }
    }
    $candidates[] = ltrim(str_replace('\\', '/', $fallback), '/');
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate))) return $candidate;
    }
    return $fallback;
}

function normalize_store_media_path($value) {
    if (function_exists('normalizeUserAvatarPath')) {
        return normalizeUserAvatarPath($value);
    }
    $path = str_replace('\\', '/', trim((string)$value));
    if ($path === '') return '';
    if (!preg_match('#^uploads/(profile_pictures|business_logos)/[A-Za-z0-9._-]+$#', $path)) return '';
    return $path;
}

function user_storefront_image(array $user_row, $fallback = '') {
    $business_logo = normalize_store_media_path($user_row['business_logo'] ?? '');
    if ($business_logo !== '') {
        return asset_path($business_logo, $fallback !== '' ? $fallback : 'images/store-bg.jpg');
    }

    $profile_image = normalize_store_media_path($user_row['profile_image'] ?? '');
    if ($profile_image !== '') {
        return asset_path($profile_image, $fallback !== '' ? $fallback : 'images/store-bg.jpg');
    }

    return $fallback !== '' ? asset_path('', $fallback) : '';
}

function norm_key($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\b(branch|store|lechon|delights)\b/', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function add_unique_limited(array &$items, $value, $limit = 3) {
    $value = trim((string)$value);
    if ($value === '' || in_array($value, $items, true) || count($items) >= $limit) return;
    $items[] = $value;
}

function location_label($address, $city = '', $province = '') {
    $parts = [];
    $address = trim((string)$address);
    $city = trim((string)$city);
    $province = trim((string)$province);
    if ($address !== '') {
        $chunks = array_values(array_filter(array_map('trim', explode(',', $address))));
        if (!empty($chunks)) $parts[] = $chunks[0];
    }
    if ($city !== '') $parts[] = $city;
    if ($province !== '' && strcasecmp($province, $city) !== 0) $parts[] = $province;
    return !empty($parts) ? implode(', ', array_unique($parts)) : 'Cavite';
}

function business_type_label($type) {
    $type = trim((string)$type);
    return $type === '' ? 'Partner store' : ucwords(str_replace('_', ' ', $type));
}

function is_cavite_scope($address = '', $city = '', $province = '') {
    $normalize = static function ($value) {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = strtolower($converted);
            }
        }
        $value = str_replace(['Ã±', 'ñ'], 'n', $value);
        $value = preg_replace('/[^a-z0-9\.\s]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    };

    $haystack = trim(implode(' ', array_filter([
        $normalize($address),
        $normalize($city),
        $normalize($province)
    ])));
    if ($haystack === '') return false;

    return strpos($haystack, 'cavite') !== false
        || strpos($haystack, 'general trias') !== false
        || strpos($haystack, 'gentri') !== false
        || strpos($haystack, 'imus') !== false
        || strpos($haystack, 'bacoor') !== false
        || strpos($haystack, 'dasmarinas') !== false
        || strpos($haystack, 'tagaytay') !== false
        || strpos($haystack, 'trece martires') !== false
        || strpos($haystack, 'kawit') !== false
        || strpos($haystack, 'noveleta') !== false
        || strpos($haystack, 'rosario') !== false
        || strpos($haystack, 'tanza') !== false
        || strpos($haystack, 'naic') !== false
        || strpos($haystack, 'ternate') !== false
        || strpos($haystack, 'maragondon') !== false
        || strpos($haystack, 'indang') !== false
        || strpos($haystack, 'alfonso') !== false
        || strpos($haystack, 'mendez') !== false
        || strpos($haystack, 'silang') !== false
        || strpos($haystack, 'carmona') !== false
        || strpos($haystack, 'gen. trias') !== false;
}

function store_menu_link(array $store) {
    $params = [];
    $seller_id = isset($store['seller_id']) ? (int)$store['seller_id'] : 0;
    $branch_id = isset($store['branch_id']) ? (int)$store['branch_id'] : 0;

    if ($seller_id > 0) $params['seller_id'] = $seller_id;
    if ($branch_id > 0) $params['branch_id'] = $branch_id;

    return empty($params) ? 'menu.php' : 'menu.php?' . http_build_query($params);
}

function branch_fallbacks() {
    return [];
}

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

$sales_map = [];
$sales_sql = "SELECT p.id, COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END),0) total_sold
              FROM products p
              LEFT JOIN order_items oi ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
              LEFT JOIN orders o ON oi.order_id = o.id AND o.is_archived = 0 AND o.status <> 'cancelled'
              WHERE p.is_archived = 0
              GROUP BY p.id";
$sales_result = mysqli_query($conn, $sales_sql);
if ($sales_result) {
    while ($row = mysqli_fetch_assoc($sales_result)) $sales_map[(int)$row['id']] = (int)$row['total_sold'];
    mysqli_free_result($sales_result);
}

$branches = [];
$branch_by_email = [];
$branch_by_name = [];
$branch_by_owner = [];
$branch_any_by_email = [];
$branch_any_by_name = [];
$branch_any_by_owner = [];
$branch_owner_ids = [];
$branch_owner_images = [];
$has_business_logo_column = function_exists('userAccountControlColumnExists') ? userAccountControlColumnExists($conn, 'business_logo') : true;
$has_profile_image_column = function_exists('userAccountControlColumnExists') ? userAccountControlColumnExists($conn, 'profile_image') : true;
sahEnsureStoreLocationAvailabilitySchema($conn);
$branch_result = mysqli_query($conn, "SELECT store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, is_active, latitude, longitude FROM store_locations WHERE is_active = 1 ORDER BY store_name");
if ($branch_result) {
    while ($row = mysqli_fetch_assoc($branch_result)) {
        $availability = sahResolveStoreAvailability($row);
        $branch = [
            'id' => (int)$row['store_id'],
            'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
            'name' => trim((string)$row['store_name']),
            'address' => trim((string)$row['address']),
            'city' => trim((string)$row['city']),
            'province' => trim((string)$row['province']),
            'phone' => trim((string)$row['phone']),
            'email' => strtolower(trim((string)$row['email'])),
            'hours' => trim((string)($availability['schedule'] ?? $row['opening_hours'])),
            'availability' => $availability,
            'is_open' => !empty($availability['is_open']),
            'status_label' => trim((string)($availability['label'] ?? 'Closed')),
            'status_note' => trim((string)($availability['note'] ?? 'Store is currently unavailable.')),
            'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : null
        ];

        if ((int)$branch['owner_user_id'] > 0) {
            $branch_any_by_owner[(int)$branch['owner_user_id']] = $branch;
        }
        if ($branch['email'] !== '') {
            $branch_any_by_email[$branch['email']] = $branch;
        }
        $branch_name_key = norm_key($branch['name']);
        if ($branch_name_key !== '') {
            $branch_any_by_name[$branch_name_key] = $branch;
        }

        // Include all active database store locations
        $branches[] = $branch;
        if ((int)$branch['owner_user_id'] > 0) {
            $branch_owner_ids[(int)$branch['owner_user_id']] = true;
        }
        if ((int)$branch['owner_user_id'] > 0) {
            $branch_by_owner[(int)$branch['owner_user_id']] = $branch;
        }
        if ($branch['email'] !== '') $branch_by_email[$branch['email']] = $branch;
        if ($branch_name_key !== '') {
            $branch_by_name[$branch_name_key] = $branch;
        }
    }
    mysqli_free_result($branch_result);
}
foreach (branch_fallbacks() as $fallback_branch) {
    $fallback_email = strtolower(trim((string)($fallback_branch['email'] ?? '')));
    $fallback_name_key = norm_key((string)($fallback_branch['name'] ?? ''));
    $already_exists = false;

    if ($fallback_email !== '' && isset($branch_by_email[$fallback_email])) {
        $already_exists = true;
    }
    if (!$already_exists && $fallback_name_key !== '' && isset($branch_by_name[$fallback_name_key])) {
        $already_exists = true;
    }
    if ($already_exists) {
        continue;
    }

    $branches[] = $fallback_branch;
    if ((int)($fallback_branch['owner_user_id'] ?? 0) > 0) {
        $branch_any_by_owner[(int)$fallback_branch['owner_user_id']] = $fallback_branch;
    }
    if ($fallback_email !== '') {
        $branch_any_by_email[$fallback_email] = $fallback_branch;
        $branch_by_email[$fallback_email] = $fallback_branch;
    }
    if ($fallback_name_key !== '') {
        $branch_any_by_name[$fallback_name_key] = $fallback_branch;
        $branch_by_name[$fallback_name_key] = $fallback_branch;
    }
}

if (!empty($branch_owner_ids)) {
    $owner_ids_sql = implode(',', array_map('intval', array_keys($branch_owner_ids)));
    if ($owner_ids_sql !== '') {
        $owner_media_fields = "id";
        $owner_media_fields .= $has_business_logo_column ? ", business_logo" : ", NULL AS business_logo";
        $owner_media_fields .= $has_profile_image_column ? ", profile_image" : ", NULL AS profile_image";
        $owner_media_result = mysqli_query($conn, "SELECT {$owner_media_fields} FROM users WHERE id IN ({$owner_ids_sql})");
        if ($owner_media_result) {
            while ($owner_row = mysqli_fetch_assoc($owner_media_result)) {
                $owner_id = (int)($owner_row['id'] ?? 0);
                if ($owner_id <= 0) continue;
                $owner_image = user_storefront_image($owner_row, '');
                if ($owner_image !== '') {
                    $branch_owner_images[$owner_id] = $owner_image;
                }
            }
            mysqli_free_result($owner_media_result);
        }
    }
}

$stores = [];
$latest_approved_partner_sql = "SELECT fa1.* FROM franchise_applications fa1 INNER JOIN (SELECT user_id, MAX(id) AS latest_id FROM franchise_applications WHERE status = 'approved' GROUP BY user_id) latest ON latest.latest_id = fa1.id";
$seller_media_fields = '';
$seller_media_fields .= $has_business_logo_column ? ", u.business_logo" : ", NULL AS business_logo";
$seller_media_fields .= $has_profile_image_column ? ", u.profile_image" : ", NULL AS profile_image";
$seller_result = mysqli_query($conn, "SELECT u.id, u.full_name, u.email, u.phone, u.address, u.business_name, u.business_type, u.created_at{$seller_media_fields}, fa.business_address, fa.city_name, fa.province_name, fa.barangay_name
                                      FROM users u
                                      INNER JOIN ({$latest_approved_partner_sql}) fa ON fa.user_id = u.id
                                      WHERE u.account_type = 'organization'
                                        AND u.is_active = 1
                                      ORDER BY COALESCE(NULLIF(TRIM(u.business_name), ''), u.full_name)");
if ($seller_result) {
    while ($row = mysqli_fetch_assoc($seller_result)) {
        $name = trim((string)$row['business_name']) !== '' ? trim((string)$row['business_name']) : trim((string)$row['full_name']) . ' Store';
        $email = strtolower(trim((string)$row['email']));
        $seller_id = (int)$row['id'];
        $match = $branch_by_owner[$seller_id] ?? ($branch_by_email[$email] ?? ($branch_by_name[norm_key($name)] ?? null));
        $availability_match = $match ?: ($branch_any_by_owner[$seller_id] ?? ($branch_any_by_email[$email] ?? ($branch_any_by_name[norm_key($name)] ?? null)));
        $application_address = trim((string)($row['business_address'] ?? ''));
        $application_city = trim((string)($row['city_name'] ?? ''));
        $application_province = trim((string)($row['province_name'] ?? ''));
        $user_address = trim((string)($row['address'] ?? ''));

        $application_has_cavite_scope = is_cavite_scope($application_address, $application_city, $application_province);
        $user_has_cavite_scope = is_cavite_scope($user_address, '', '');

        $fallback_address = $application_address !== '' ? $application_address : $user_address;
        $fallback_city = $application_city;
        $fallback_province = $application_province;

        if (!$application_has_cavite_scope && $user_has_cavite_scope && $user_address !== '') {
            $fallback_address = $user_address;
            if ($fallback_city === '') {
                $fallback_city = trim((string)($row['city_name'] ?? ''));
            }
            if ($fallback_province === '') {
                $fallback_province = trim((string)($row['province_name'] ?? ''));
            }
        }

        if (!$match && !$application_has_cavite_scope && !$user_has_cavite_scope) {
            continue;
        }
        $store_availability = [];
        if ($availability_match && isset($availability_match['availability']) && is_array($availability_match['availability'])) {
            $store_availability = $availability_match['availability'];
        } elseif ($availability_match) {
            $store_availability = sahResolveStoreAvailability($availability_match);
        }
        $seller_store_image = user_storefront_image($row, '');
        if ($seller_store_image !== '') {
            $branch_owner_images[$seller_id] = $seller_store_image;
        }

        $stores['seller-' . $seller_id] = [
            'key' => 'seller-' . $seller_id,
            'name' => $name,
            'type' => 'partner',
            'seller_id' => $seller_id,
            'branch_id' => $match ? (int)($match['id'] ?? 0) : 0,
            'business_type' => business_type_label($row['business_type']),
            'phone' => trim((string)$row['phone']),
            'location' => $match ? location_label($match['address'], $match['city'], $match['province']) : location_label($fallback_address, $fallback_city, $fallback_province),
            'branch' => $match,
            'latitude' => $match['latitude'] ?? null,
            'longitude' => $match['longitude'] ?? null,
            'city' => $match['city'] ?? trim($fallback_city),
            'province' => $match['province'] ?? trim($fallback_province),
            'raw_address' => $match ? trim((string)($match['address'] ?? '')) : trim((string)$fallback_address),
            'raw_city' => $match ? trim((string)($match['city'] ?? '')) : trim((string)$fallback_city),
            'raw_province' => $match ? trim((string)($match['province'] ?? '')) : trim((string)$fallback_province),
            'image' => $seller_store_image,
            'count' => 0,
            'order_volume' => 0,
            'start' => null,
            'rating_sum' => 0,
            'rating_count' => 0,
            'rating' => 0,
            'reviews' => 0,
            'tags' => [],
            'items' => [],
            'search_terms' => [],
            'live' => false,
            'availability' => $store_availability,
            'is_open' => !empty($store_availability['is_open']),
            'status_label' => trim((string)($store_availability['label'] ?? 'Closed')),
            'status_note' => trim((string)($store_availability['note'] ?? 'Store is currently unavailable.')),
            'note' => trim((string)($store_availability['note'] ?? 'Waiting for first menu upload')),
            'joined' => date('M Y', strtotime((string)$row['created_at']))
        ];
    }
    mysqli_free_result($seller_result);
}

$products = [];
$categories = [];
$global_min = null;
$global_rating_sum = 0;
$global_rating_count = 0;
$global_reviews = 0;
$product_sql = "SELECT p.id, p.seller_id, p.name, p.category, p.price, p.image, p.avg_rating, p.review_count, COALESCE(NULLIF(TRIM(u.business_name), ''), 'Lechon Delights Kitchen') seller_name
                FROM products p LEFT JOIN users u ON p.seller_id = u.id
                WHERE p.is_archived = 0 AND p.is_active = 1
                ORDER BY p.created_at DESC, p.id DESC";
$product_result = mysqli_query($conn, $product_sql);
if ($product_result) {
    while ($row = mysqli_fetch_assoc($product_result)) {
        $seller_id = isset($row['seller_id']) ? (int)$row['seller_id'] : 0;
        $key = $seller_id > 0 ? 'seller-' . $seller_id : 'platform-main';
        if (!isset($stores[$key])) {
            continue;
        }
        $price = (float)$row['price'];
        $rating = (float)$row['avg_rating'];
        $category = trim((string)$row['category']) !== '' ? trim((string)$row['category']) : 'Filipino favorites';
        $image = asset_path($row['image'] ?? '', 'images/menu/whole-lechon.jpg');
        $stores[$key]['live'] = true;
        $stores[$key]['count']++;
        $stores[$key]['order_volume'] += (int)($sales_map[(int)$row['id']] ?? 0);
        $stores[$key]['start'] = $stores[$key]['start'] === null ? $price : min($stores[$key]['start'], $price);
        $stores[$key]['reviews'] += (int)$row['review_count'];
        if ($rating > 0) { $stores[$key]['rating_sum'] += $rating; $stores[$key]['rating_count']++; $global_rating_sum += $rating; $global_rating_count++; }
        if ($stores[$key]['image'] === '') $stores[$key]['image'] = $image;
        add_unique_limited($stores[$key]['tags'], $category, 3);
        add_unique_limited($stores[$key]['items'], $row['name'], 3);
        $stores[$key]['search_terms'][] = strtolower(trim((string)$row['name']));
        $stores[$key]['search_terms'][] = strtolower(trim((string)$category));
        $stores[$key]['search_terms'][] = strtolower(trim((string)$row['seller_name']));
        $categories[$category] = ($categories[$category] ?? 0) + 1;
        $global_min = $global_min === null ? $price : min($global_min, $price);
        $global_reviews += (int)$row['review_count'];
        $menu_target = $seller_id > 0 ? 'menu.php?seller_id=' . $seller_id : (!empty($stores[$key]['branch_id']) ? 'menu.php?branch_id=' . $stores[$key]['branch_id'] : 'menu.php');
        $products[] = [
            'id' => (int)$row['id'],
            'seller_id' => $seller_id,
            'menu_link' => $menu_target,
            'name' => trim((string)$row['name']),
            'store' => $stores[$key]['name'],
            'category' => $category,
            'price' => $price,
            'rating' => $rating,
            'reviews' => (int)$row['review_count'],
            'sold' => (int)($sales_map[(int)$row['id']] ?? 0),
            'image' => $image
        ];
    }
    mysqli_free_result($product_result);
}

arsort($categories);
usort($products, static function ($a, $b) { return [$b['sold'], $b['reviews'], $b['rating']] <=> [$a['sold'], $a['reviews'], $a['rating']]; });
$featured_products = array_slice($products, 0, 12);
$global_rating = $global_rating_count > 0 ? $global_rating_sum / $global_rating_count : 0;
$branch_tags = array_slice(array_keys($categories), 0, 3);
$branch_items = array_slice(array_column($featured_products, 'name'), 0, 3);
$global_product_search_terms = array_values(array_unique(array_filter(array_map(static function ($product) {
    $name = strtolower(trim((string)($product['name'] ?? '')));
    $category = strtolower(trim((string)($product['category'] ?? '')));
    return trim($name . ' ' . $category);
}, $products))));

foreach ($branches as $branch) {
    $exists = false;
    foreach ($stores as $store) { if (!empty($store['branch']) && (int)$store['branch']['id'] === (int)$branch['id']) { $exists = true; break; } }
    if ($exists) continue;
    $branch_availability = isset($branch['availability']) && is_array($branch['availability'])
        ? $branch['availability']
        : sahResolveStoreAvailability($branch);
    $stores['branch-' . $branch['id']] = [
        'key' => 'branch-' . $branch['id'],
        'name' => $branch['name'],
        'type' => 'branch',
        'seller_id' => 0,
        'branch_id' => (int)$branch['id'],
        'business_type' => 'Pickup branch',
        'phone' => $branch['phone'],
        'location' => location_label($branch['address'], $branch['city'], $branch['province']),
        'branch' => $branch,
        'latitude' => $branch['latitude'] ?? null,
        'longitude' => $branch['longitude'] ?? null,
        'city' => $branch['city'],
        'province' => $branch['province'],
        'raw_address' => trim((string)($branch['address'] ?? '')),
        'raw_city' => trim((string)($branch['city'] ?? '')),
        'raw_province' => trim((string)($branch['province'] ?? '')),
        'image' => !empty($branch_owner_images[(int)($branch['owner_user_id'] ?? 0)])
            ? $branch_owner_images[(int)($branch['owner_user_id'] ?? 0)]
            : asset_path('images/store-bg.jpg', 'images/hero-bg.jpg'),
        'count' => count($products),
        'order_volume' => 0,
        'start' => $global_min,
        'rating_sum' => 0,
        'rating_count' => 0,
        'rating' => $global_rating,
        'reviews' => $global_reviews,
        'tags' => !empty($branch_tags) ? $branch_tags : ['Lechon favorites'],
        'items' => !empty($branch_items) ? $branch_items : ['Whole Lechon', 'Lechon Belly'],
        'search_terms' => [
            strtolower(trim((string)$branch['name'])),
            strtolower(trim((string)$branch['address'])),
            strtolower(trim((string)$branch['city'])),
            strtolower(trim((string)$branch['province']))
        ],
        'live' => !empty($products),
        'availability' => $branch_availability,
        'is_open' => !empty($branch_availability['is_open']),
        'status_label' => trim((string)($branch_availability['label'] ?? 'Closed')),
        'status_note' => trim((string)($branch_availability['note'] ?? 'Store is currently unavailable.')),
        'note' => trim((string)($branch_availability['note'] ?? ($branch['hours'] !== '' ? 'Open today: ' . $branch['hours'] : 'Available for pickup'))),
        'joined' => 'Branch network'
    ];
    if (!empty($global_product_search_terms)) {
        $stores['branch-' . $branch['id']]['search_terms'] = array_merge(
            $stores['branch-' . $branch['id']]['search_terms'],
            $global_product_search_terms
        );
    }
}

foreach ($stores as &$store) {
    if (!isset($store['seller_id'])) $store['seller_id'] = 0;
    if (!isset($store['branch_id'])) {
        $store['branch_id'] = !empty($store['branch']) && !empty($store['branch']['id']) ? (int)$store['branch']['id'] : 0;
    }
    $availability = isset($store['availability']) && is_array($store['availability']) ? $store['availability'] : null;
    if ($availability) {
        $store['is_open'] = !empty($availability['is_open']);
        $store['status_label'] = trim((string)($availability['label'] ?? ($store['is_open'] ? 'Open' : 'Closed')));
        $store['status_note'] = trim((string)($availability['note'] ?? ''));
        $schedule_text = trim((string)($availability['schedule'] ?? ''));
        if ($schedule_text !== '') {
            $store['hours'] = $schedule_text;
        }
    } else {
        $store['is_open'] = !empty($store['is_open']);
        $store['status_label'] = trim((string)($store['status_label'] ?? ($store['is_open'] ? 'Open' : 'Closed')));
        $store['status_note'] = trim((string)($store['status_note'] ?? ''));
    }
    if ($store['rating_count'] > 0) $store['rating'] = $store['rating_sum'] / $store['rating_count'];
    if (!isset($store['order_volume'])) $store['order_volume'] = 0;
    if ($store['image'] === '') $store['image'] = asset_path('images/hero-bg.jpg', 'images/store-bg.jpg');
    if (empty($store['tags'])) $store['tags'] = [$store['business_type']];
    if (trim((string)($store['status_note'] ?? '')) !== '') {
        $store['note'] = trim((string)$store['status_note']);
    }
    $store['summary'] = !empty($store['items']) ? implode(' | ', $store['items']) : 'Storefront profile is ready on the platform.';
    $search_terms = array_filter(array_map(static function ($term) {
        return strtolower(trim((string)$term));
    }, $store['search_terms'] ?? []));
    $search_terms = array_values(array_unique($search_terms));
    $search_blob = implode(' ', array_filter([
        strtolower(trim((string)$store['name'])),
        strtolower(trim((string)$store['location'])),
        strtolower(trim((string)$store['business_type'])),
        strtolower(trim((string)($store['city'] ?? ''))),
        strtolower(trim((string)($store['province'] ?? ''))),
        strtolower(trim((string)($store['note'] ?? ''))),
        strtolower(trim((string)implode(' ', $store['tags']))),
        strtolower(trim((string)implode(' ', $store['items']))),
        implode(' ', $search_terms)
    ]));
    $store['search'] = trim(preg_replace('/\s+/', ' ', $search_blob));
    unset($store['search_terms']);
    $store['is_partner'] = in_array($store['type'], ['partner', 'seller', 'platform'], true);
    $store['is_branch'] = $store['type'] === 'branch' || !empty($store['branch']);
    $store['is_cavite'] = is_cavite_scope(
        (string)($store['raw_address'] ?? ($store['location'] ?? '')),
        (string)($store['raw_city'] ?? ($store['city'] ?? '')),
        (string)($store['raw_province'] ?? ($store['province'] ?? ''))
    );
    $store['menu_link'] = store_menu_link($store);
}
unset($store);

$has_any_cavite_stores = false;
foreach ($stores as $s) {
    if (!empty($s['is_cavite'])) {
        $has_any_cavite_stores = true;
        break;
    }
}

$stores = array_values(array_filter($stores, static function ($store) use ($has_any_cavite_stores) {
    return $has_any_cavite_stores ? !empty($store['is_cavite']) : true;
}));
usort($stores, static function ($a, $b) {
    return [$b['is_open'] ? 1 : 0, $b['live'] ? 1 : 0, $b['count'], $b['type'] === 'platform' ? 1 : 0, $a['name']]
        <=> [$a['is_open'] ? 1 : 0, $a['live'] ? 1 : 0, $a['count'], $a['type'] === 'platform' ? 1 : 0, $b['name']];
});

$spotlight_candidates = array_values(array_filter($stores, static function ($store) {
    $rating = (float)($store['rating'] ?? 0);
    $orders = (int)($store['order_volume'] ?? 0);
    return ($rating > 0 || $orders > 0) && (!empty($store['live']) || !empty($store['is_open']));
}));
usort($spotlight_candidates, static function ($a, $b) {
    return [
        (float)($b['rating'] ?? 0),
        (int)($b['order_volume'] ?? 0),
        (int)($b['reviews'] ?? 0),
        !empty($b['is_open']) ? 1 : 0,
        strtolower((string)($a['name'] ?? ''))
    ] <=> [
        (float)($a['rating'] ?? 0),
        (int)($a['order_volume'] ?? 0),
        (int)($a['reviews'] ?? 0),
        !empty($a['is_open']) ? 1 : 0,
        strtolower((string)($b['name'] ?? ''))
    ];
});
if (empty($spotlight_candidates)) {
    $spotlight_candidates = $stores;
}
$spotlights = array_slice($spotlight_candidates, 0, min(3, count($spotlight_candidates)));
$visible_store_count = count($stores);
$registered_store_count = count(array_filter($stores, static fn($store) => $store['type'] === 'partner'));
$pickup_branch_count = count($branches);
$live_store_count = count(array_filter($stores, static fn($store) => !empty($store['is_open'])));
$city_count = count(array_unique(array_filter(array_map(static fn($branch) => $branch['city'], $branches))));
$cavite_branch_count = count(array_filter($branches, static fn($branch) => is_cavite_scope(
    (string)($branch['address'] ?? ''),
    (string)($branch['city'] ?? ''),
    (string)($branch['province'] ?? '')
)));
$top_brand_stores = array_slice(array_values(array_filter($stores, static fn($store) => !empty($store['is_partner']))), 0, 6);
$top_shop_stores = array_slice(array_values(array_filter($stores, static fn($store) => !empty($store['is_branch']))), 0, 6);
$top_rated_stores = $stores;
usort($top_rated_stores, static function ($a, $b) {
    return [(float)($b['rating'] ?? 0), (int)($b['reviews'] ?? 0), !empty($b['is_open']) ? 1 : 0, strtolower((string)($a['name'] ?? ''))]
        <=> [(float)($a['rating'] ?? 0), (int)($a['reviews'] ?? 0), !empty($a['is_open']) ? 1 : 0, strtolower((string)($b['name'] ?? ''))];
});
$top_rated_stores = array_values(array_filter($top_rated_stores, static fn($store) => (float)($store['rating'] ?? 0) > 0));
if (empty($top_rated_stores)) {
    $top_rated_stores = $stores;
}
$top_rated_stores = array_slice($top_rated_stores, 0, 8);

include 'includes/header.php';
?>
<style>
.market-home {
    --menu-red: #b3261e;
    --menu-orange: #ef6b2e;
    --menu-cream: #fff8ef;
    --rose: #b3261e;
    --rose-soft: #ffe7d3;
    --rose-wash: #fff2e4;
    --ink: #2a211d;
    --muted: #7a6c63;
    --line: #efddcd;
    --card: #ffffff;
    --shadow-soft: 0 20px 42px rgba(74, 32, 20, 0.14);
    --shadow-card: 0 12px 26px rgba(74, 32, 20, 0.1);
    --motion-ease: cubic-bezier(.22, 1, .36, 1);
    --motion-fast: .22s;
    --motion-base: .28s;
    --transition-fast: all var(--motion-fast) var(--motion-ease);
    --transition-lift: transform var(--motion-fast) var(--motion-ease), box-shadow var(--motion-fast) var(--motion-ease), border-color var(--motion-fast) var(--motion-ease), background-color var(--motion-fast) var(--motion-ease), color var(--motion-fast) var(--motion-ease);
    position: relative;
    overflow: visible;
    background:
        radial-gradient(circle at 8% -5%, rgba(239, 107, 46, 0.14), transparent 36%),
        radial-gradient(circle at 90% 12%, rgba(179, 38, 30, 0.12), transparent 32%),
        linear-gradient(180deg, #fff8ef 0%, #fff9f2 34%, #ffffff 100%);
    color: var(--ink);
    padding: 20px 0 56px;
}

.partner-ad-card {
    border-radius: 20px;
    padding: 22px 24px;
    color: #ffffff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 28px rgba(23, 25, 34, 0.08);
    transition: transform 0.25s cubic-bezier(.22,1,.36,1), box-shadow 0.25s ease;
}
.partner-ad-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 36px rgba(179, 38, 30, 0.18);
}
.partner-ad-card.gradient-red { background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%); }
.partner-ad-card.gradient-orange { background: linear-gradient(135deg, #ef6b2e 0%, #ff9e43 100%); }
.partner-ad-card.gradient-dark { background: linear-gradient(135deg, #171922 0%, #343a40 100%); }

.partner-ad-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.partner-ad-store { font-size: 0.78rem; font-weight: 700; opacity: 0.95; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px; }
.partner-ad-badge { background: rgba(255, 255, 255, 0.25); backdrop-filter: blur(4px); color: #ffffff; font-weight: 800; font-size: 0.72rem; padding: 4px 10px; border-radius: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
.partner-ad-title { font-size: 1.25rem; font-weight: 800; margin: 0 0 6px 0; color: #ffffff; line-height: 1.25; }
.partner-ad-desc { font-size: 0.88rem; opacity: 0.92; margin: 0 0 16px 0; line-height: 1.4; color: #ffffff; }
.partner-ad-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.2); }
.partner-ad-code { background: #ffffff; color: #171922; font-weight: 800; font-size: 0.82rem; padding: 6px 12px; border-radius: 8px; font-family: monospace; display: flex; align-items: center; gap: 6px; }
.partner-ad-code i { color: #b3261e; }
.partner-ad-btn { background: #ffffff; color: #b3261e; text-decoration: none; font-weight: 800; font-size: 0.82rem; padding: 8px 16px; border-radius: 10px; transition: background 0.2s ease, transform 0.2s ease; display: inline-flex; align-items: center; gap: 6px; }
.partner-ad-btn:hover { background: #171922; color: #ffffff; transform: translateX(2px); }

.market-home::before,
.market-home::after {
    content: "";
    position: absolute;
    border-radius: 999px;
    pointer-events: none;
    z-index: 0;
}

.market-home::before {
    width: 260px;
    height: 260px;
    right: -80px;
    top: 160px;
    background: radial-gradient(circle, rgba(239, 107, 46, 0.2), rgba(239, 107, 46, 0));
}

.market-home::after {
    width: 220px;
    height: 220px;
    left: -70px;
    top: 520px;
    background: radial-gradient(circle, rgba(179, 38, 30, 0.2), rgba(179, 38, 30, 0));
}

.market-home .container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 24px;
    position: relative;
    z-index: 1;
    box-sizing: border-box;
}

@media (max-width: 768px) {
    .market-home .container {
        padding: 0 16px;
    }
}

@media (max-width: 480px) {
    .market-home .container {
        padding: 0 12px;
    }
}

.market-hero-grid,
.market-head,
.market-toolbar,
.market-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 22px;
    flex-wrap: wrap;
}

.panda-hero-banners {
    display: grid;
    gap: 16px;
    margin-bottom: 24px;
}

.panda-hero-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 24px 32px;
    border-radius: 24px;
    position: relative;
    overflow: hidden;
    min-height: 150px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
}

.panda-card-pink {
    background: linear-gradient(135deg, #fff0f3 0%, #ffdfe5 100%);
    border: 1px solid #ffd0d8;
}

.panda-card-soft {
    background: linear-gradient(135deg, #fff8f0 0%, #ffe8d4 100%);
    border: 1px solid #fedbc1;
}

.panda-card-arch {
    position: absolute;
    right: -20px;
    top: -30px;
    bottom: -30px;
    width: 260px;
    background: rgba(255, 255, 255, 0.55);
    border-radius: 50% 0 0 50%;
    pointer-events: none;
    z-index: 1;
    transition: transform 0.3s ease;
}

.panda-hero-card:hover .panda-card-arch {
    transform: scale(1.05);
}

.panda-card-content {
    flex: 1 1 340px;
    max-width: 480px;
    margin-left: 0 !important;
    text-align: left !important;
    display: grid;
    gap: 8px;
    z-index: 2;
}

.panda-card-title {
    margin: 0;
    font-family: "Outfit", "Plus Jakarta Sans", sans-serif;
    font-size: clamp(1.4rem, 2.2vw, 1.85rem);
    font-weight: 800;
    color: #171922;
    line-height: 1.15;
    letter-spacing: -0.02em;
    text-align: left !important;
}

.panda-card-desc {
    margin: 0;
    font-size: 0.92rem;
    color: #4b5563;
    line-height: 1.45;
    font-weight: 500;
    text-align: left !important;
}

.panda-card-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: fit-content;
    margin-top: 6px;
    min-height: 38px;
    padding: 0 20px;
    border-radius: 999px;
    background: #b3261e;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.86rem;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(.22,1,.36,1);
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.22);
}

.panda-card-btn:hover {
    background: #901e17;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(179, 38, 30, 0.3);
    color: #fff;
}

.panda-card-btn-alt {
    background: #171922;
    box-shadow: 0 4px 12px rgba(23, 25, 34, 0.18);
}

.panda-card-btn-alt:hover {
    background: #2b3144;
    box-shadow: 0 6px 16px rgba(23, 25, 34, 0.25);
    color: #fff;
}

.panda-card-graphic {
    position: relative;
    flex: 0 0 180px;
    height: 150px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    z-index: 2;
    margin-left: auto;
}

.panda-card-graphic img.panda-mascot-img {
    height: 165px;
    width: auto;
    object-fit: contain;
    margin-bottom: -10px;
    filter: drop-shadow(0 10px 18px rgba(179, 38, 30, 0.16));
    transition: transform 0.3s cubic-bezier(.22,1,.36,1);
    border-radius: 0;
    box-shadow: none;
    background: transparent;
}

.panda-hero-card:hover .panda-mascot-img {
    transform: scale(1.08) translateY(-4px);
}

.panda-card-graphic-cluster {
    position: relative;
    flex: 0 0 170px;
    width: 170px;
    height: 150px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
    margin-left: auto;
}

.panda-card-heart-bg {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 260px;
    pointer-events: none;
    z-index: 1;
    overflow: hidden;
}

.panda-heart-shape {
    position: absolute;
    background: rgba(239, 107, 46, 0.12);
    transition: transform 0.3s ease;
}

.panda-heart-shape.heart-lg {
    width: 200px;
    height: 200px;
    right: -40px;
    bottom: -60px;
    transform: rotate(-15deg);
    background: radial-gradient(circle, rgba(255, 220, 200, 0.85) 0%, rgba(255, 235, 220, 0.45) 100%);
    border-radius: 50% 50% 50% 0;
}

.panda-heart-shape.heart-sm {
    width: 130px;
    height: 130px;
    right: 60px;
    top: -20px;
    transform: rotate(25deg);
    background: radial-gradient(circle, rgba(255, 230, 210, 0.95) 0%, rgba(255, 240, 230, 0.45) 100%);
    border-radius: 50% 50% 50% 0;
}

.panda-hero-card:hover .panda-heart-shape.heart-lg {
    transform: rotate(-10deg) scale(1.06);
}

.panda-hero-card:hover .panda-heart-shape.heart-sm {
    transform: rotate(30deg) scale(1.08);
}

.panda-float-badge-wrap {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.panda-badge-item {
    position: absolute;
    background: #ffffff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.12);
    z-index: 3;
}

.panda-badge-calendar {
    top: 18px;
    right: 50px;
    width: 78px;
    height: 78px;
    background: #ffffff;
    color: #ef6b2e;
    font-size: 2.15rem;
    box-shadow: 0 12px 28px rgba(239, 107, 46, 0.22);
    animation: pandaFloatA 3.2s ease-in-out infinite alternate;
}

.panda-badge-gift {
    bottom: 12px;
    right: 6px;
    width: 52px;
    height: 52px;
    background: #fff3eb;
    color: #ef6b2e;
    font-size: 1.35rem;
    box-shadow: 0 8px 20px rgba(239, 107, 46, 0.16);
    animation: pandaFloatB 2.6s ease-in-out infinite alternate;
}

.panda-badge-party {
    top: 10px;
    right: 126px;
    width: 44px;
    height: 44px;
    background: #ffffff;
    color: #b3261e;
    font-size: 1.12rem;
    box-shadow: 0 6px 16px rgba(179, 38, 30, 0.15);
    animation: pandaFloatC 2.9s ease-in-out infinite alternate;
}

@keyframes pandaFloatA {
    0% { transform: translateY(0) rotate(0deg); }
    100% { transform: translateY(-8px) rotate(4deg); }
}

@keyframes pandaFloatB {
    0% { transform: translateY(0) rotate(0deg); }
    100% { transform: translateY(-6px) rotate(-5deg); }
}

@keyframes pandaFloatC {
    0% { transform: translateY(0) scale(1); }
    100% { transform: translateY(-10px) scale(1.1); }
}

@media (max-width: 640px) {
    .panda-hero-card {
        padding: 20px;
    }
    .panda-card-graphic {
        flex: 0 0 120px;
        height: 110px;
    }
    .panda-card-graphic img.panda-mascot-img {
        height: 110px;
    }
}

.hero-chip-list .market-chip {
    text-decoration: none;
    font-size: 0.8rem;
    padding: 5px 12px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #e2ba9c;
    color: var(--rose);
    font-weight: 600;
    transition: var(--transition-fast);
}

.hero-chip-list .market-chip:hover {
    background: var(--rose);
    color: #fff;
    border-color: var(--rose);
}

.market-btn,
.market-btn-soft,
.market-btn-ghost,
.market-card-btn,
.market-card-btn-soft,
.market-card-btn-disabled {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 38px;
    padding: 0 14px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.84rem;
    text-decoration: none;
    transition: var(--transition-lift);
}

.market-btn,
.market-card-btn {
    background: linear-gradient(135deg, var(--menu-red), var(--menu-orange));
    color: #fff;
    border: none;
    box-shadow: 0 8px 18px rgba(179, 38, 30, 0.2);
}

.market-btn-soft {
    background: #fff;
    border: 1px solid var(--line);
    color: var(--ink);
}

.market-btn-ghost,
.market-card-btn-soft {
    background: #fff;
    border: 1px solid var(--line);
    color: var(--ink);
}

.market-card-btn-disabled {
    background: #fff8f0;
    border: 1px dashed #e6cdb8;
    color: #9a7e6f;
}

.market-btn:hover,
.market-btn-soft:hover,
.market-btn-ghost:hover,
.market-card-btn:hover,
.market-card-btn-soft:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 20px rgba(74, 32, 20, 0.12);
}

.market-hero {
    padding: 16px 0 20px;
}

.market-kicker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #ffe8d2;
    color: var(--rose);
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.market-copy h1 {
    margin: 0 0 10px;
    max-width: 680px;
    font-family: "Outfit", "Plus Jakarta Sans", sans-serif;
    font-size: clamp(1.6rem, 2.2vw, 2.3rem);
    line-height: 1.15;
    letter-spacing: -0.03em;
}

.market-copy p,
.market-head p,
.market-card-copy,
.market-dish-meta,
.market-branch-meta {
    color: var(--muted);
    line-height: 1.55;
}

.market-copy p {
    max-width: 640px;
    font-size: 0.92rem;
}

.market-search {
    max-width: 640px;
    margin-top: 12px;
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(10px);
    box-shadow: 0 10px 24px rgba(74, 32, 20, 0.08);
}

.market-address {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 0.85rem;
    font-weight: 700;
}

.market-address span:last-child {
    color: var(--muted);
    font-weight: 600;
}

.market-search-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 10px;
}

.market-input {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    background: #fff8f0;
    border: 1px solid transparent;
    border-radius: 12px;
}

.market-input:focus-within {
    border-color: #e2ba9c;
    background: #fff;
}

.market-input input {
    width: 100%;
    min-height: 40px;
    border: none;
    outline: none;
    background: transparent;
    color: var(--ink);
    font-size: 0.88rem;
}

.market-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-top: 16px;
}

.market-stat,
.market-toolbar-note {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 14px;
    box-shadow: var(--shadow-card);
}

.market-stat {
    padding: 12px 14px;
}

.market-stat strong {
    display: block;
    margin-bottom: 2px;
    font-size: 1.15rem;
    font-family: "Outfit", "Plus Jakarta Sans", sans-serif;
}

.market-stat span {
    font-size: 0.78rem;
}

.market-hero-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.75fr) minmax(280px, 1fr);
    gap: 20px;
}

.market-side {
    display: grid;
    gap: 20px;
    align-self: stretch;
}

.market-hero-showcase {
    display: grid;
    gap: 18px;
}

.market-store-card,
.market-product-card {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 28px;
    box-shadow: 0 18px 40px rgba(74, 32, 20, 0.1);
    overflow: hidden;
}

.market-store-card {
    display: grid;
    grid-template-columns: 1fr;
}

.market-store-card-image {
    width: 100%;
    min-height: 240px;
    background-size: cover;
    background-position: center;
}

.market-store-card-body {
    padding: 24px;
    display: grid;
    gap: 16px;
}

.market-store-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.market-store-card-head h3 {
    margin: 0;
    font-size: 1.55rem;
    line-height: 1.1;
}

.market-store-card-meta {
    display: grid;
    gap: 10px;
    color: var(--muted);
    font-size: 0.95rem;
}

.market-store-card-meta span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.market-store-card-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.market-store-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 999px;
    background: #fff4e8;
    color: var(--rose);
    font-weight: 700;
}

.market-product-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.market-product-card {
    transition: var(--transition-lift);
}

.market-product-card:hover {
    transform: translateY(-2px);
}

.market-product-thumb {
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    display: block;
}

.market-product-body {
    padding: 16px;
    display: grid;
    gap: 10px;
}

.market-product-body h4 {
    margin: 0;
    font-size: 1rem;
    line-height: 1.3;
}

.market-product-meta {
    display: grid;
    gap: 8px;
    font-size: 0.86rem;
    color: var(--muted);
}

.market-product-meta span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.market-product-price {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-weight: 700;
}

.market-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}

.market-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    border: 1px solid rgba(179, 38, 30, 0.14);
    background: rgba(255, 243, 236, 0.8);
    color: var(--rose);
    font-size: 0.9rem;
    font-weight: 700;
}

.market-spot-card,
.market-mini-card,
.market-status-card,
.market-dish,
.market-branch,
.market-sidebar,
.market-store-row {
    background: var(--card);
    border: 1px solid var(--line);
    box-shadow: 0 14px 30px rgba(74, 32, 20, 0.11);
}

.market-spot-card {
    display: grid;
    grid-template-columns: 78px 1fr;
    gap: 12px;
    padding: 12px;
    border-radius: 20px;
    color: inherit;
    text-decoration: none;
    transition: var(--transition-lift);
}

.market-spot-thumb {
    width: 78px;
    height: 78px;
    border-radius: 16px;
    background-size: cover;
    background-position: center;
}

.market-spot-card h3,
.market-dish h3,
.market-branch h3,
.market-store-row-head h3 {
    margin: 0 0 6px;
    color: var(--ink);
}

.market-spot-card p {
    margin: 0 0 8px;
    color: var(--muted);
    font-size: 0.88rem;
    line-height: 1.55;
}

.market-spot-meta,
.market-card-sub,
.market-dish-meta,
.market-branch-meta,
.market-mini-meta,
.market-store-row-meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 0.84rem;
}

.market-section {
    padding: 26px 0;
}

.market-head {
    margin-bottom: 18px;
    align-items: flex-end;
}

.market-head h2,
.market-mini-section h2 {
    margin: 0 0 8px;
    font-family: "Outfit", "Plus Jakarta Sans", sans-serif;
    font-size: clamp(1.9rem, 2.3vw, 2.8rem);
    letter-spacing: -0.04em;
}

.market-toolbar {
    margin-bottom: 18px;
}

.market-toolbar-note {
    padding: 12px 16px;
    color: var(--muted);
    font-size: 0.9rem;
    font-weight: 700;
}

.market-pagination {
    margin-top: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}

.market-pagination-note {
    color: var(--muted);
    font-size: 0.9rem;
    font-weight: 700;
}

.market-banner {
    margin-top: 8px;
    padding: 28px;
    border: 1px solid #efddcd;
    border-radius: 32px;
    background:
        radial-gradient(circle at top right, rgba(239, 107, 46, 0.14), transparent 28%),
        linear-gradient(180deg, #fffaf3 0%, #fff1e2 100%);
    box-shadow: var(--shadow-soft);
}

.market-banner h2 {
    margin: 0 0 8px;
    font-size: clamp(1.7rem, 2vw, 2.4rem);
    line-height: 1.08;
}

.market-banner p {
    margin: 0;
    max-width: 760px;
    color: var(--muted);
}

.market-explorer {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 28px;
    align-items: start;
}

.market-sidebar {
    position: sticky;
    top: 112px;
    max-height: calc(100vh - 130px);
    overflow-y: auto;
    align-self: start;
    z-index: 100;
    padding: 20px 14px 20px 20px;
    border-radius: 26px;
    scrollbar-width: thin;
    scrollbar-color: #94a3b8 #f1f5f9;
}

.market-sidebar::-webkit-scrollbar {
    width: 6px;
}

.market-sidebar::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 999px;
}

.market-sidebar::-webkit-scrollbar-thumb {
    background: #94a3b8;
    border-radius: 999px;
}

.market-sidebar::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

.market-sidebar h3,
.market-sidebar h4 {
    margin: 0 0 14px;
    color: var(--ink);
}

.market-sidebar h3 {
    font-size: 1.25rem;
}

.market-sidebar h4 {
    font-size: 1rem;
}

.market-sidebar-section + .market-sidebar-section {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--line);
}

.market-radio-list,
.market-check-list {
    display: grid;
    gap: 14px;
}

.market-radio,
.market-check {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    color: var(--ink);
    cursor: pointer;
}

.market-radio input,
.market-check input {
    width: 20px;
    height: 20px;
    accent-color: var(--rose);
}

.market-helper {
    font-size: 0.88rem;
    color: var(--muted);
    line-height: 1.65;
}

.market-detect-btn {
    width: 100%;
    justify-content: center;
}

.market-status-card {
    margin-bottom: 20px;
    padding: 18px;
    border-radius: 24px;
}

.market-status-card strong {
    display: block;
    margin-bottom: 6px;
}

.market-status-card p {
    margin: 0;
    color: var(--muted);
}

.market-mini-section + .market-mini-section {
    margin-top: 24px;
}

.market-mini-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 18px;
}

.market-mini-card {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
        "body body"
        "thumb arrow";
    column-gap: 14px;
    row-gap: 14px;
    align-items: end;
    padding: 18px;
    border-radius: 24px;
    color: inherit;
    text-decoration: none;
    transition: var(--transition-lift);
}

.market-mini-card:hover,
.market-spot-card:hover,
.market-store-row:hover,
.market-dish:hover,
.market-branch:hover {
    transform: translateY(-4px);
    box-shadow: 0 22px 40px rgba(74, 32, 20, 0.18);
}

.market-mini-thumb {
    grid-area: thumb;
    width: 96px;
    height: 96px;
    flex: 0 0 96px;
    border-radius: 20px;
    background-color: #fff4e8;
    background-position: center;
    background-size: cover;
}

.market-mini-body {
    grid-area: body;
    display: grid;
    gap: 6px;
    min-width: 0;
}

.market-mini-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--ink);
    line-height: 1.15;
}

.market-mini-sub,
.market-mini-meta {
    font-size: 0.92rem;
    color: var(--muted);
    line-height: 1.45;
}

.market-mini-sub {
    overflow-wrap: anywhere;
}

.market-mini-meta {
    display: grid;
    gap: 4px;
}

.market-mini-meta span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.market-mini-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
    padding: 6px 11px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.01em;
}

.market-mini-status-pill.open {
    background: #dcfce7;
    color: #166534;
}

.market-mini-status-pill.closed {
    background: #fee2e2;
    color: #991b1b;
}

.market-mini-arrow {
    grid-area: arrow;
    justify-self: end;
    align-self: center;
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--line);
    border-radius: 50%;
    background: #fff9f2;
    transition: var(--transition-lift);
}

.market-mini-card:hover .market-mini-arrow {
    transform: translateX(2px);
    background: #fff;
}

.market-store-list {
    display: grid;
    gap: 16px;
}

.market-store-row {
    display: grid;
    grid-template-columns: 92px minmax(0, 1fr) auto;
    gap: 16px;
    align-items: center;
    padding: 16px 18px;
    border-radius: 26px;
    transition: var(--transition-lift);
}

.market-store-row.is-hidden {
    display: none;
}

.market-store-row-thumb {
    width: 92px;
    height: 92px;
    border-radius: 20px;
    background-color: #fff4e8;
    background-position: center;
    background-size: cover;
}

.market-store-row-main {
    display: grid;
    gap: 8px;
    min-width: 0;
}

.market-store-row-head {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.market-type-pill,
.market-time-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border-radius: 999px;
    font-size: 0.79rem;
    font-weight: 800;
}

.market-type-pill {
    background: #ffe8d2;
    color: #932d1f;
}

.market-time-pill {
    background: #fff3e5;
    color: #6b3a2a;
}

.market-store-favorite-btn {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    border: 1px solid #efd8c3;
    background: #fff;
    color: #8f7a6d;
    cursor: pointer;
    transition: var(--transition-lift);
}

.market-store-favorite-btn:hover {
    color: #b3261e;
    border-color: #e2b59f;
}

.market-store-favorite-btn.is-active {
    color: #b3261e;
    border-color: #e2b59f;
    background: #fff1ea;
}

.market-store-row-copy {
    color: var(--muted);
    font-size: 0.92rem;
    line-height: 1.6;
}

.market-store-row-meta {
    color: #6b7387;
}

.market-store-row-side {
    display: grid;
    gap: 12px;
    justify-items: end;
}

.market-score-block {
    display: grid;
    gap: 8px;
    justify-items: end;
}

.market-score-block strong {
    font-size: 1.05rem;
}

.market-score-block span {
    font-size: 0.84rem;
    color: #8b7568;
}

.market-list-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.market-dishes,
.market-branches {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
}

.market-dish,
.market-branch {
    border-radius: 28px;
    overflow: hidden;
    transition: var(--transition-lift);
    background: #fffdfb;
}

.market-dish img {
    display: block;
    width: 100%;
    height: 170px;
    object-fit: cover;
}

.market-dish-body,
.market-branch-body {
    padding: 18px;
    background: linear-gradient(180deg, #fffefc 0%, #fff8f0 100%);
}

.market-dish-price {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    font-weight: 800;
}

@keyframes marketFadeUp {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.market-hero .market-copy,
.market-hero .market-side {
    animation: marketFadeUp 0.5s ease both;
}

.market-mini-section,
.market-store-list .market-store-row,
.market-dishes .market-dish,
.market-branches .market-branch {
    animation: marketFadeUp 0.45s ease both;
}

.market-store-list .market-store-row:nth-child(2n) { animation-delay: 0.04s; }
.market-store-list .market-store-row:nth-child(3n) { animation-delay: 0.08s; }
.market-dishes .market-dish:nth-child(2n),
.market-branches .market-branch:nth-child(2n) { animation-delay: 0.06s; }

@media (prefers-reduced-motion: reduce) {
    .market-hero .market-copy,
    .market-hero .market-side,
    .market-mini-section,
    .market-store-list .market-store-row,
    .market-dishes .market-dish,
    .market-branches .market-branch {
        animation: none;
    }
}

@media (max-width: 1100px) {
    .market-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 980px) {
    .market-explorer {
        grid-template-columns: 1fr;
    }

    .market-sidebar {
        position: static;
    }

    .market-store-row {
        grid-template-columns: 76px minmax(0, 1fr);
    }

    .market-list-actions {
        justify-content: flex-start;
    }

    .market-hero-grid {
        grid-template-columns: 1fr;
    }

    .market-product-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 860px) {
    .market-hero-grid,
    .market-head,
    .market-toolbar,
    .market-banner {
        display: grid;
    }

    .market-search-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .market-home {
        padding-top: 12px;
    }

    .market-copy h1 {
        font-size: 2.25rem;
    }

    .market-stats {
        grid-template-columns: 1fr;
    }

    .market-side,
    .market-search,
    .market-dish,
    .market-branch,
    .market-store-row {
        border-radius: 24px;
    }
}

/* Foodpanda Brands Carousel UI */
.panda-brand-slider {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    padding: 6px 2px 14px;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.panda-brand-slider::-webkit-scrollbar {
    display: none;
}

.panda-brand-item {
    flex: 0 0 115px;
    background: #ffffff;
    border: 1px solid #f0e2d5;
    border-radius: 16px;
    padding: 10px 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    text-decoration: none;
    color: #2a211d;
    box-shadow: 0 4px 10px rgba(74, 32, 20, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.panda-brand-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 18px rgba(74, 32, 20, 0.09);
    border-color: #ef6b2e;
}

.panda-brand-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background-size: cover;
    background-position: center;
    margin-bottom: 8px;
    box-shadow: 0 3px 8px rgba(0,0,0,0.08);
}

.panda-brand-name {
    font-family: 'Outfit', sans-serif;
    font-size: 0.82rem;
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 3px;
    color: #2a211d;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.panda-brand-meta {
    font-size: 0.71rem;
    color: #7d6f65;
    font-weight: 600;
}

/* Scoped Foodpanda Redesign of All Restaurants Grid - FOR ALL VISITORS */
.panda-card-link {
    text-decoration: none !important;
    color: inherit !important;
    cursor: pointer !important;
}

.market-store-list,
.store-list-grid,
.store-list-rows {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)) !important;
    gap: 18px !important;
}

.market-product-card,
.market-store-row {
    display: flex !important;
    flex-direction: column !important;
    background: #ffffff !important;
    border: 1px solid #f0e2d5 !important;
    border-radius: 16px !important;
    padding: 0 !important;
    overflow: hidden !important;
    transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease !important;
    box-shadow: 0 4px 12px rgba(74, 32, 20, 0.04) !important;
    grid-template-columns: none !important;
}

.market-product-card:hover,
.market-store-row:hover {
    transform: translateY(-3px) !important;
    box-shadow: 0 10px 24px rgba(74, 32, 20, 0.1) !important;
    border-color: #ebd7c5 !important;
}

.store-card-details {
    padding: 18px 20px 20px 20px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
    flex: 1 !important;
    gap: 6px !important;
}

.store-card-row-head {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 8px !important;
    margin-bottom: 6px !important;
}

.store-card-row-head h3 {
    margin: 0 !important;
    font-family: 'Outfit', sans-serif !important;
    font-size: 1.05rem !important;
    font-weight: 800 !important;
    color: #171922 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    flex: 1 !important;
}

.store-card-summary {
    font-size: 0.84rem !important;
    color: #64748b !important;
    line-height: 1.45 !important;
    height: 38px !important;
    overflow: hidden !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    margin-bottom: 8px !important;
}

.panda-card-footer-line {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-top: 8px !important;
    padding-top: 8px !important;
    border-top: 1px solid #f5eae0 !important;
    gap: 8px !important;
}

.panda-card-city {
    font-size: 0.76rem !important;
    color: #64748b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.panda-card-price-text {
    font-family: 'Outfit', sans-serif !important;
    font-size: 0.88rem !important;
    font-weight: 800 !important;
    color: #b3261e !important;
    white-space: nowrap !important;
}

.store-card-image-wrap {
    position: relative !important;
    width: 100% !important;
    height: 135px !important;
    overflow: hidden !important;
}

.market-store-row-thumb {
    width: 100% !important;
    height: 100% !important;
    border-radius: 0 !important;
    background-size: cover !important;
    background-position: center !important;
    transition: transform 0.3s ease !important;
}

.market-store-row:hover .market-store-row-thumb {
    transform: scale(1.04) !important;
}

/* Floating favorite button in card */
.market-store-favorite-btn {
    position: absolute !important;
    top: 10px !important;
    right: 10px !important;
    z-index: 10 !important;
    width: 32px !important;
    height: 32px !important;
    background: #ffffff !important;
    border: none !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: 0 3px 8px rgba(0,0,0,0.12) !important;
    cursor: pointer !important;
    color: #e11d48 !important;
    font-size: 0.85rem !important;
    transition: transform 0.2s ease !important;
}

.market-store-favorite-btn:hover {
    transform: scale(1.08) !important;
}

/* Overlay pills on image */
.market-type-pill {
    position: absolute !important;
    left: 10px !important;
    top: 10px !important;
    z-index: 5 !important;
    background: rgba(42, 33, 29, 0.85) !important;
    color: #ffffff !important;
    font-size: 0.68rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    border: none !important;
    font-weight: 700 !important;
}

.market-time-pill {
    position: absolute !important;
    left: 10px !important;
    bottom: 10px !important;
    z-index: 5 !important;
    background: rgba(255, 255, 255, 0.92) !important;
    color: #b3261e !important;
    font-size: 0.68rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    font-weight: 800 !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1) !important;
}

/* Foodpanda Store Section Header Bar */
.panda-store-header-bar {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-bottom: 22px;
}

@media (min-width: 768px) {
    .panda-store-header-bar {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
    }
}

.panda-store-header-title h2.panda-main-heading {
    font-family: 'Outfit', sans-serif;
    font-size: 1.55rem;
    font-weight: 800;
    color: #2a211d;
    margin: 0 0 6px 0;
    letter-spacing: -0.02em;
}

.panda-store-stats-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.panda-stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.78rem;
    color: #55483f;
    background: #f8f1eb;
    border: 1px solid #ebd7c5;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
}

.panda-stat-pill.panda-stat-live {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #166534;
}

.panda-stat-pill.panda-stat-live i {
    font-size: 0.5rem;
    color: #22c55e;
}

.panda-search-bar-wrap {
    flex: 1;
    max-width: 440px;
    width: 100%;
}

.panda-search-bar {
    display: flex;
    align-items: center;
    background: #fcf8f5;
    border: 1.5px solid #ebd7c5;
    border-radius: 30px;
    padding: 9px 18px;
    gap: 10px;
    box-shadow: 0 2px 8px rgba(74, 32, 20, 0.03);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    cursor: text;
}

.panda-search-bar:focus-within {
    background: #ffffff;
    border-color: #ef6b2e;
    box-shadow: 0 4px 14px rgba(239, 107, 46, 0.14);
}

.panda-search-bar .search-icon {
    color: #ef6b2e;
    font-size: 0.95rem;
}

.panda-search-bar input {
    border: none;
    background: transparent;
    outline: none;
    width: 100%;
    font-size: 0.88rem;
    color: #2a211d;
    font-family: inherit;
    font-weight: 500;
}

.panda-search-bar input::placeholder {
    color: #a39589;
}
    gap: 8px !important;
    flex: 1 !important;
}

.store-list-grid .store-card-row-head {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 8px !important;
}

.store-list-grid .store-card-row-head h3 {
    margin: 0 !important;
    font-family: 'Outfit', sans-serif !important;
    font-size: 1.12rem !important;
    font-weight: 800 !important;
    color: #2a211d !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    flex: 1 !important;
}

.store-list-grid .store-card-rating {
    font-size: 0.88rem !important;
    font-weight: 700 !important;
    color: #2a211d !important;
    display: flex !important;
    align-items: center !important;
    white-space: nowrap !important;
}

.store-list-grid .store-card-summary {
    font-size: 0.84rem !important;
    color: #7a6c63 !important;
    line-height: 1.4 !important;
    height: 38px !important;
    overflow: hidden !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
}

.store-list-grid .store-card-meta {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    font-size: 0.8rem !important;
    color: #7a6c63 !important;
    border-bottom: 1px solid #efddcd !important;
    padding-bottom: 10px !important;
    margin-bottom: 4px !important;
}

.store-list-grid .store-card-meta-item {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}

.store-list-grid .store-card-meta-item i {
    color: #b3261e !important;
    width: 14px !important;
}

.store-list-grid .store-card-footer {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    margin-top: auto !important;
    padding-top: 8px !important;
}

.store-list-grid .store-card-price {
    display: flex !important;
    flex-direction: column !important;
}

.store-list-grid .store-card-price strong {
    font-size: 0.95rem !important;
    color: #b3261e !important;
    font-weight: 800 !important;
}

.store-list-grid .store-card-price span {
    font-size: 0.72rem !important;
    color: #7a6c63 !important;
}

.store-list-grid .store-card-actions {
    display: flex !important;
    gap: 6px !important;
}

<?php if (!empty($_SESSION['user_id'])): ?>
/* Logged-in Customer Dashboard Layout Theme */
body {
    background: #ffffff !important;
    --bg: #ffffff !important;
}

.market-explorer {
    gap: 36px !important;
    align-items: flex-start !important;
}

.market-sidebar {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    padding: 20px !important;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.02) !important;
    max-width: 280px !important;
    width: 100% !important;
}

.market-sidebar-section {
    border-bottom: 1px solid #f1f5f9 !important;
    padding-bottom: 16px !important;
    margin-bottom: 16px !important;
}

.market-sidebar-section:last-child {
    border-bottom: none !important;
    padding-bottom: 0 !important;
    margin-bottom: 0 !important;
}

.market-sidebar h3 {
    margin: 0 0 12px 0 !important;
    font-size: 1.15rem !important;
    font-weight: 800 !important;
    color: #1e293b !important;
}

.market-sidebar h4 {
    margin: 0 0 10px 0 !important;
    font-size: 0.88rem !important;
    font-weight: 700 !important;
    color: #475569 !important;
}

/* Header bottom links for customer portal */
.market-home-link.active {
    background: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
}

.market-home-link:hover:not(.active) {
    background: #f1f5f9 !important;
    color: #171922 !important;
}
.bestseller-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(179, 38, 30, 0.12) !important;
    border-color: #b3261e !important;
}
.bestseller-card:hover .market-store-row-thumb {
    transform: scale(1.06);
}
<?php endif; ?>
</style>

<div class="market-home">
    <section class="market-section" id="marketplaceStores">
        <div class="container">
            <div class="market-explorer">
                <aside class="market-sidebar">
                    <?php if (!empty($_SESSION['user_id'])): ?>
                        <!-- App Promo Card -->
                        <div class="sidebar-app-promo" style="background:#2a211d; color:#ffffff; padding:20px; border-radius:20px; position:relative; margin-bottom:24px; box-shadow:0 8px 24px rgba(0,0,0,0.12); display:flex; flex-direction:column; align-items:center; text-align:center;">
                            <button type="button" onclick="this.parentElement.style.display='none';" style="position:absolute; top:12px; right:12px; background:transparent; border:none; color:#a1a1a1; cursor:pointer; font-size:1rem; padding:0; line-height:1;"><i class="fas fa-xmark"></i></button>
                            <div style="background:#ffffff; padding:12px; border-radius:16px; margin-bottom:12px; display:inline-block; border:1px solid #efddcd; line-height:0;">
                                <svg width="90" height="90" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="100" height="100" fill="#fff"/>
                                    <rect x="10" y="10" width="25" height="25" fill="#2a211d"/>
                                    <rect x="15" y="15" width="15" height="15" fill="#fff"/>
                                    <rect x="18" y="18" width="9" height="9" fill="#2a211d"/>
                                    <rect x="65" y="10" width="25" height="25" fill="#2a211d"/>
                                    <rect x="70" y="15" width="15" height="15" fill="#fff"/>
                                    <rect x="73" y="18" width="9" height="9" fill="#2a211d"/>
                                    <rect x="10" y="65" width="25" height="25" fill="#2a211d"/>
                                    <rect x="15" y="70" width="15" height="15" fill="#fff"/>
                                    <rect x="18" y="73" width="9" height="9" fill="#2a211d"/>
                                    <rect x="45" y="15" width="8" height="8" fill="#b3261e"/>
                                    <rect x="53" y="30" width="8" height="8" fill="#2a211d"/>
                                    <rect x="40" y="45" width="20" height="20" fill="#b3261e" rx="4"/>
                                    <circle cx="50" cy="55" r="6" fill="#fff"/>
                                    <path d="M48 55 C48 53, 52 53, 52 55 C52 57, 48 57, 48 55" fill="#b3261e"/>
                                    <rect x="70" y="45" width="12" height="12" fill="#2a211d"/>
                                    <rect x="75" y="70" width="15" height="15" fill="#2a211d"/>
                                </svg>
                            </div>
                            <h4 style="margin:0 0 6px 0; font-family:'Outfit', sans-serif; font-size:0.92rem; font-weight:800; line-height:1.3; color:#ffffff;">Unlock more app-only deals. Download now.</h4>
                            <div style="display:flex; gap:8px; width:100%; justify-content:center; margin-top:8px;">
                                <a href="#" style="background:#111; color:#fff; border:1px solid #444; border-radius:8px; padding:6px 10px; font-size:0.68rem; display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-weight:700;"><i class="fab fa-apple" style="font-size:0.9rem;"></i> App Store</a>
                                <a href="#" style="background:#111; color:#fff; border:1px solid #444; border-radius:8px; padding:6px 10px; font-size:0.68rem; display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-weight:700;"><i class="fab fa-google-play" style="font-size:0.8rem;"></i> Play Store</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="market-sidebar-section">
                        <h3>Filters</h3>
                    </div>
                    <div class="market-sidebar-section">
                        <h4>Sort by</h4>
                        <div class="market-radio-list">
                            <label class="market-radio"><input type="radio" name="storeSort" value="relevance" checked> <span>Relevance</span></label>
                            <label class="market-radio"><input type="radio" name="storeSort" value="fastest"> <span>Fastest delivery</span></label>
                            <label class="market-radio"><input type="radio" name="storeSort" value="distance"> <span>Distance</span></label>
                            <label class="market-radio"><input type="radio" name="storeSort" value="top_rated"> <span>Top rated</span></label>
                        </div>
                    </div>
                    <div class="market-sidebar-section">
                        <h4>Quick filters</h4>
                        <div class="market-check-list">
                            <label class="market-check"><input type="checkbox" id="filterRatings4"> <span>Ratings 4+</span></label>
                            <label class="market-check"><input type="checkbox" id="filterLiveOnly"> <span>Open now only</span></label>
                            <label class="market-check"><input type="checkbox" id="filterPartnerOnly"> <span>Partner stores</span></label>
                            <label class="market-check"><input type="checkbox" id="filterBranchOnly"> <span>Pickup branches</span></label>
                            <label class="market-check"><input type="checkbox" id="filterNearbyOnly"> <span>Nearby only</span></label>
                        </div>
                    </div>
                    <div class="market-sidebar-section">
                        <h4>Nearest Cavite lechon shops</h4>
                        <p class="market-helper">Allow location access and the page will rank the closest saved Cavite branches first using your browser position and store coordinates.</p>
                        <button type="button" class="market-btn market-detect-btn" id="detectNearestStores"><i class="fas fa-location-crosshairs"></i> Detect nearest shops</button>
                        <p class="market-helper" id="nearestDetectStatus" style="margin-top:12px;">Waiting for location permission.</p>
                    </div>
                </aside>

                <div>
                    <?php if (!empty($_SESSION['user_id'])): 
                        $first_name = explode(' ', $_SESSION['full_name'] ?? 'Guest')[0];
                    ?>
                        <div class="panda-welcome-banner" style="background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%); color: #fff; padding: 28px 24px; border-radius: 24px; margin-bottom: 24px; box-shadow: 0 12px 28px rgba(179, 38, 30, 0.15); display: flex; justify-content: space-between; align-items: center; overflow: hidden; position: relative;">
                            <div style="position: absolute; right: -20px; bottom: -40px; opacity: 0.12; font-size: 10rem; color: #fff; transform: rotate(-15deg); pointer-events: none;"><i class="fas fa-utensils"></i></div>
                            <div style="z-index: 1;">
                                <h1 style="margin: 0 0 6px 0; font-family: 'Outfit', sans-serif; font-size: clamp(1.5rem, 2.5vw, 2.2rem); font-weight: 800; color: #ffffff;">Mabuhay, <?php echo htmlspecialchars($first_name); ?>! 👋</h1>
                                <p style="margin: 0; font-size: 0.95rem; opacity: 0.9; max-width: 580px; color: #ffffff;">Ready for some crispy, mouth-watering lechon? Check out the available Cavite partners and branches open right now near you.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($_SESSION['user_id'])): ?>
                        <div class="panda-hero-banners">
                            <article class="panda-hero-card panda-card-pink">
                                <div class="panda-card-arch"></div>
                                <div class="panda-card-content">
                                    <h2 class="panda-card-title">Order Fresh Lechon</h2>
                                    <p class="panda-card-desc">Enjoy crispy skin and juicy meat, roasted fresh for every order.</p>
                                    <a href="register.php" class="panda-card-btn guest-cta-btn">Order Now</a>
                                </div>
                                <div class="panda-card-graphic">
                                    <img src="assets/images/lechon_mascot_user.png" alt="Lechon Delights Mascot" class="panda-mascot-img" loading="lazy">
                                </div>
                            </article>

                            <article class="panda-hero-card panda-card-soft">
                                <div class="panda-card-heart-bg">
                                    <div class="panda-heart-shape heart-lg"></div>
                                    <div class="panda-heart-shape heart-sm"></div>
                                </div>
                                <div class="panda-card-content">
                                    <h2 class="panda-card-title">Pre-order for Celebrations</h2>
                                    <p class="panda-card-desc">Avoid the rush by booking your whole or half lechon ahead of time.</p>
                                    <a href="register.php" class="panda-card-btn panda-card-btn-alt guest-cta-btn">Reserve Now</a>
                                </div>
                                <div class="panda-card-graphic-cluster">
                                    <div class="panda-float-badge-wrap">
                                        <div class="panda-badge-item panda-badge-calendar" title="Pre-order Ahead"><i class="fas fa-calendar-check"></i></div>
                                        <div class="panda-badge-item panda-badge-gift" title="Celebration Offer"><i class="fas fa-gift"></i></div>
                                        <div class="panda-badge-item panda-badge-party" title="Lechon Feast"><i class="fas fa-utensils"></i></div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    <?php else: ?>
                        <!-- Logged-in Premium Minimal Promo Cards -->
                        <div class="user-promo-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 28px;">
                            <!-- Order Now Card -->
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 12px rgba(15,23,42,0.02); transition: transform 0.2s ease;">
                                <div style="width: 52px; height: 52px; border-radius: 12px; background: #fff1f2; display: flex; align-items: center; justify-content: center; color: #b3261e; font-size: 1.4rem; flex-shrink: 0;">
                                    <i class="fas fa-motorcycle"></i>
                                </div>
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 4px 0; font-family: 'Outfit', sans-serif; font-size: 0.98rem; font-weight: 800; color: #1e293b;">Order Fresh Lechon</h4>
                                    <p style="margin: 0 0 10px 0; font-size: 0.8rem; color: #64748b; line-height: 1.4;">Crispy skin and juicy meat roasted fresh for your feast.</p>
                                    <a href="#marketplaceStores" class="market-btn" style="min-height: 32px; padding: 0 14px; font-size: 0.78rem; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">Order Now</a>
                                </div>
                            </div>
                            
                            <!-- Reserve Now Card -->
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 12px rgba(15,23,42,0.02); transition: transform 0.2s ease;">
                                <div style="width: 52px; height: 52px; border-radius: 12px; background: #fef4ea; display: flex; align-items: center; justify-content: center; color: #ef6b2e; font-size: 1.4rem; flex-shrink: 0;">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 4px 0; font-family: 'Outfit', sans-serif; font-size: 0.98rem; font-weight: 800; color: #1e293b;">Pre-order Celebrations</h4>
                                    <p style="margin: 0 0 10px 0; font-size: 0.8rem; color: #64748b; line-height: 1.4;">Avoid the rush and reserve your whole lechon ahead of time.</p>
                                    <a href="preorder.php" class="market-btn-soft" style="min-height: 32px; padding: 0 14px; font-size: 0.78rem; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">Reserve Now</a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php 
                    $featured_ads = paGetActiveAdvertisements($conn, 6);
                    if (!empty($featured_ads)): 
                    ?>
                    <!-- Partner Promos & Advertisements Section -->
                    <section class="partner-ads-section" style="margin-bottom: 32px;">
                        <div class="market-head" style="margin-bottom: 14px;">
                            <div>
                                <h2 style="font-family:'Outfit',sans-serif; font-size:1.45rem; font-weight:800; color:#171922; display:flex; align-items:center; gap:8px;">
                                    <i class="fas fa-bullhorn" style="color:#ef6b2e;"></i> Featured Promos & Partner Deals
                                </h2>
                                <p style="font-size:0.88rem; color:#7b6d64; margin:0;">Exclusive discounts, voucher codes, and special offers from store owners and partner sellers.</p>
                            </div>
                        </div>

                        <div class="partner-ads-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 18px;">
                            <?php foreach ($featured_ads as $ad): 
                                $theme_class = htmlspecialchars($ad['bg_theme'] ?: 'gradient-red');
                                $seller_name = htmlspecialchars($ad['business_name'] ?: 'Partner Store');
                                $target_url = htmlspecialchars($ad['target_url'] ?: 'menu.php?seller_id=' . $ad['seller_id']);
                            ?>
                                <article class="partner-ad-card <?php echo $theme_class; ?>">
                                    <div class="partner-ad-header">
                                        <div class="partner-ad-store">
                                            <i class="fas fa-store"></i> <?php echo $seller_name; ?>
                                        </div>
                                        <?php if (!empty($ad['discount_tag'])): ?>
                                            <span class="partner-ad-badge"><?php echo htmlspecialchars($ad['discount_tag']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="partner-ad-title"><?php echo htmlspecialchars($ad['title']); ?></h3>
                                    <p class="partner-ad-desc"><?php echo htmlspecialchars($ad['subtitle'] ?: 'Special promotional offer for customers.'); ?></p>
                                    
                                    <div class="partner-ad-footer">
                                        <?php if (!empty($ad['promo_code'])): ?>
                                            <div class="partner-ad-code" title="Use voucher code at checkout">
                                                <i class="fas fa-ticket-alt"></i> <span><?php echo htmlspecialchars($ad['promo_code']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <a href="<?php echo $target_url; ?>" class="partner-ad-btn">
                                            Claim Deal <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($top_rated_stores)): ?>
                    <!-- Foodpanda Brands Carousel: Top Lechon Houses -->
                    <section class="market-mini-section">
                        <div class="market-head" style="margin-bottom:12px;">
                            <div>
                                <h2 style="font-family:'Outfit',sans-serif; font-size:1.4rem; font-weight:800; color:#2a211d;">Top lechon brands & hubs</h2>
                                <p style="font-size:0.88rem; color:#7d6f65; margin:0;">Popular Cavite lechon pitmasters, whole lechon suppliers, and quick pickup branches.</p>
                            </div>
                        </div>
                        <div class="panda-brand-slider">
                            <?php foreach ($top_rated_stores as $top_store): ?>
                                <a href="<?php echo htmlspecialchars($top_store['menu_link']); ?>" class="panda-brand-item">
                                    <div class="panda-brand-avatar" style="background-image:url('<?php echo htmlspecialchars($top_store['image']); ?>');"></div>
                                    <div class="panda-brand-name"><?php echo htmlspecialchars($top_store['name']); ?></div>
                                    <div class="panda-brand-meta">
                                        <i class="fas fa-star" style="color:#ef6b2e;"></i> <?php echo $top_store['rating'] > 0 ? number_format((float)$top_store['rating'], 1) : '4.9'; ?>
                                        • 25-35m
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($featured_products)): ?>
                    <!-- Prominent Best Sellers & Top Rated Dishes Section -->
                    <section class="bestsellers-section" style="margin-bottom: 32px; padding: 24px; background: linear-gradient(135deg, #fff9f2 0%, #ffffff 100%); border: 1px solid #efddcd; border-radius: 20px; box-shadow: 0 8px 24px rgba(42,33,29,0.04);">
                        <div class="market-head" style="margin-bottom: 18px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px;">
                            <div>
                                <div style="display:inline-flex; align-items:center; gap:6px; padding:4px 12px; background:#fff1f2; border:1px solid #ffe4e6; border-radius:999px; color:#b3261e; font-size:0.78rem; font-weight:800; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:6px;">
                                    <i class="fas fa-fire" style="color:#ef6b2e;"></i> Top Customer Choices
                                </div>
                                <h2 style="font-family:'Outfit',sans-serif; font-size:1.55rem; font-weight:800; color:#171922; margin:0 0 4px 0;">Best Sellers & Top Rated Dishes</h2>
                                <p style="font-size:0.88rem; color:#7b6d64; margin:0;">Hand-picked customer favorites with high sales volume and top customer ratings across Cavite.</p>
                            </div>
                            <a href="menu.php" class="btn-outline btn-sm" style="border-radius:999px; font-weight:700; text-decoration:none; padding:8px 18px; font-size:0.85rem;">
                                Explore Full Menu <i class="fas fa-arrow-right" style="margin-left:4px;"></i>
                            </a>
                        </div>

                        <div class="bestsellers-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px;">
                            <?php foreach ($featured_products as $idx => $product): ?>
                                <?php
                                $prod_id_val = (string)($product['id'] ?? '');
                                $is_prod_fav = !empty($favorite_store_keys['product_' . $prod_id_val]);
                                $rank_num = $idx + 1;
                                ?>
                                <a href="<?php echo htmlspecialchars($product['menu_link'] ?? 'menu.php'); ?>" class="bestseller-card panda-card-link" style="background:#ffffff; border:1px solid #efddcd; border-radius:16px; overflow:hidden; display:flex; flex-direction:column; transition:transform 0.25s ease, box-shadow 0.25s ease; text-decoration:none; position:relative;">
                                    <div class="store-card-image-wrap" style="height: 145px; position:relative; overflow:hidden;">
                                        <div class="market-store-row-thumb" style="background-image: url('<?php echo htmlspecialchars($product['image']); ?>'); width:100%; height:100%; background-size:cover; background-position:center; transition:transform 0.4s ease;"></div>
                                        <span class="bestseller-rank-badge" style="position:absolute; top:10px; left:10px; background:linear-gradient(135deg, #b3261e, #ef6b2e); color:#ffffff; font-weight:800; font-size:0.75rem; padding:4px 10px; border-radius:999px; box-shadow:0 4px 10px rgba(179,38,30,0.3); display:inline-flex; align-items:center; gap:4px; z-index:2;">
                                            <i class="fas fa-fire"></i> #<?php echo $rank_num; ?> Best Seller
                                        </span>
                                        <button
                                            type="button"
                                            class="market-store-favorite-btn<?php echo $is_prod_fav ? ' is-active' : ''; ?>"
                                            data-favorite-toggle="1"
                                            data-favorite-type="product"
                                            data-favorite-product-id="<?php echo htmlspecialchars($prod_id_val); ?>"
                                            data-favorite-active="<?php echo $is_prod_fav ? '1' : '0'; ?>"
                                            aria-pressed="<?php echo $is_prod_fav ? 'true' : 'false'; ?>"
                                            title="<?php echo $is_prod_fav ? 'Remove from favorites' : 'Save to favorites'; ?>"
                                            onclick="event.preventDefault(); event.stopPropagation();"
                                            style="position:absolute; top:10px; right:10px; z-index:2;">
                                            <i class="<?php echo $is_prod_fav ? 'fas' : 'far'; ?> fa-heart"></i>
                                        </button>
                                    </div>

                                    <div class="store-card-details" style="padding: 14px; display:flex; flex-direction:column; flex:1;">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:4px;">
                                            <h3 style="font-family:'Outfit',sans-serif; font-size:1rem; font-weight:800; color:#171922; margin:0; line-height:1.35; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; flex:1;"><?php echo htmlspecialchars($product['name']); ?></h3>
                                            <?php if ($product['rating'] > 0): ?>
                                            <span style="font-size:0.82rem; font-weight:800; color:#171922; display:inline-flex; align-items:center; gap:3px; white-space:nowrap;">
                                                <i class="fas fa-star" style="color:#ef6b2e;"></i><?php echo number_format((float)$product['rating'], 1); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div style="font-size:0.8rem; color:#7b6d64; margin-bottom:12px; display:flex; align-items:center; gap:5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <i class="fas fa-store" style="color:#ef6b2e;"></i> <span><?php echo htmlspecialchars($product['store']); ?></span>
                                        </div>

                                        <div style="margin-top:auto; padding-top:10px; border-top:1px dashed #efddcd; display:flex; justify-content:space-between; align-items:center;">
                                            <div>
                                                <strong style="font-size:1.05rem; color:#b3261e; font-weight:800; display:block;">PHP <?php echo number_format((float)$product['price'], 2); ?></strong>
                                                <span style="font-size:0.75rem; color:#7b6d64; font-weight:600;"><?php echo number_format((int)$product['sold']); ?> sold</span>
                                            </div>
                                            <span class="btn-primary btn-sm" style="padding:6px 12px; font-size:0.78rem; border-radius:8px; pointer-events:none; background:linear-gradient(135deg, #b3261e, #ef6b2e); color:#fff; font-weight:700;">
                                                Order Now <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="market-mini-section">
                        <div class="panda-store-header-bar">
                            <div class="panda-store-header-title">
                                <h2 class="panda-main-heading">All Cavite stores</h2>
                            </div>
                            <div class="panda-search-bar-wrap">
                                <label class="panda-search-bar" for="gridStoreSearch">
                                    <i class="fas fa-magnifying-glass search-icon"></i>
                                    <input type="text" id="gridStoreSearch" placeholder="Search stores, whole lechon, belly, cities...">
                                </label>
                            </div>
                        </div>

                        <!-- Unified Foodpanda 3-Column Card Grid Layout -->
                        <div class="market-store-list store-list-grid" id="marketStoreGrid">
                            <?php foreach ($stores as $index => $store): ?>
                                <?php
                                $price = $store['start'] !== null ? 'PHP ' . number_format((float)$store['start'], 2) : 'Starts at PHP 450.00';
                                $type_label = $store['type'] === 'branch' ? 'Pickup branch' : ($store['type'] === 'partner' ? 'Partner store' : ($store['type'] === 'platform' ? 'Marketplace favorite' : 'Seller'));
                                $store_key_value = (string)($store['key'] ?? '');
                                $is_store_favorite = !empty($favorite_store_keys[$store_key_value]);
                                $city_label = !empty($store['city']) ? $store['city'] . ', Cavite' : $store['location'];
                                ?>
                                <a href="<?php echo htmlspecialchars($store['menu_link']); ?>"
                                   class="market-store-row panda-card-link"
                                   data-store-key="<?php echo htmlspecialchars($store_key_value); ?>"
                                   data-index="<?php echo (int)$index; ?>"
                                   data-search="<?php echo htmlspecialchars($store['search']); ?>"
                                   data-tags="<?php echo htmlspecialchars(strtolower(implode(' ', $store['tags']))); ?>"
                                   data-type="<?php echo htmlspecialchars($store['type']); ?>"
                                   data-live="<?php echo !empty($store['live']) ? '1' : '0'; ?>"
                                   data-open="<?php echo !empty($store['is_open']) ? '1' : '0'; ?>"
                                   data-rating="<?php echo number_format((float)$store['rating'], 2, '.', ''); ?>"
                                   data-reviews="<?php echo (int)$store['reviews']; ?>"
                                   data-count="<?php echo (int)$store['count']; ?>"
                                   data-lat="<?php echo $store['latitude'] !== null ? htmlspecialchars((string)$store['latitude']) : ''; ?>"
                                   data-lng="<?php echo $store['longitude'] !== null ? htmlspecialchars((string)$store['longitude']) : ''; ?>">
                                    
                                    <!-- Image Wrapper -->
                                    <div class="store-card-image-wrap">
                                        <div class="market-store-row-thumb" style="background-image:url('<?php echo htmlspecialchars($store['image']); ?>');"></div>
                                        <span class="market-type-pill"><?php echo htmlspecialchars($type_label); ?></span>
                                        <span class="market-time-pill" data-role="time-label"><?php echo !empty($store['is_open']) ? 'Open now' : 'Closed now'; ?></span>
                                        <button
                                            type="button"
                                            class="market-store-favorite-btn<?php echo $is_store_favorite ? ' is-active' : ''; ?>"
                                            data-favorite-toggle="1"
                                            data-favorite-type="store"
                                            data-favorite-store-key="<?php echo htmlspecialchars($store_key_value); ?>"
                                            data-favorite-active="<?php echo $is_store_favorite ? '1' : '0'; ?>"
                                            aria-pressed="<?php echo $is_store_favorite ? 'true' : 'false'; ?>"
                                            title="<?php echo $is_store_favorite ? 'Remove from favorites' : 'Save to favorites'; ?>"
                                            onclick="event.preventDefault(); event.stopPropagation();">
                                            <i class="<?php echo $is_store_favorite ? 'fas' : 'far'; ?> fa-heart"></i>
                                        </button>
                                    </div>

                                    <!-- Details Container -->
                                    <div class="store-card-details">
                                        <div class="store-card-row-head">
                                            <h3><?php echo htmlspecialchars($store['name']); ?></h3>
                                            <span class="store-card-rating">
                                                <i class="fas fa-star" style="color:#ef6b2e; margin-right:3px;"></i><?php echo $store['rating'] > 0 ? number_format((float)$store['rating'], 1) : 'New'; ?>
                                                <?php if ($store['reviews'] > 0): ?><span class="store-card-reviews" style="font-size:0.75rem; color:#64748b; font-weight:normal;">(<?php echo (int)$store['reviews']; ?>)</span><?php endif; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="store-card-summary">
                                            <?php echo htmlspecialchars($store['summary']); ?>
                                        </div>
                                        
                                        <div class="panda-card-footer-line">
                                            <span class="panda-card-city"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($city_label); ?></span>
                                            <strong class="panda-card-price-text"><?php echo htmlspecialchars($price); ?></strong>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div class="market-pagination">
                            <div class="market-pagination-note" id="marketPaginationNote">Showing all stores.</div>
                            <button type="button" class="market-btn-soft" id="marketLoadMoreBtn">Show more stores</button>
                        </div>
                    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Intercept guest order/reserve button clicks
    const guestCtaBtns = document.querySelectorAll('.guest-cta-btn');
    guestCtaBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'login.php';
        });
    });

    const heroSearch = document.getElementById('heroStoreSearch');
    const gridSearch = document.getElementById('gridStoreSearch');
    const headerSearch = document.getElementById('marketHeaderSearch');
    const storeGrid = document.getElementById('marketStoreGrid');
    const rows = Array.from(document.querySelectorAll('.market-store-row'));
    const visibleCount = document.getElementById('visibleStoreCount');
    const paginationNote = document.getElementById('marketPaginationNote');
    const loadMoreButton = document.getElementById('marketLoadMoreBtn');
    const distanceSummary = document.getElementById('marketDistanceSummary');
    const detectNearestButton = document.getElementById('detectNearestStores');
    const detectNearestStatus = document.getElementById('nearestDetectStatus');
    const sortInputs = Array.from(document.querySelectorAll('input[name="storeSort"]'));
    const filterRatings4 = document.getElementById('filterRatings4');
    const filterLiveOnly = document.getElementById('filterLiveOnly');
    const filterPartnerOnly = document.getElementById('filterPartnerOnly');
    const filterBranchOnly = document.getElementById('filterBranchOnly');
    const filterNearbyOnly = document.getElementById('filterNearbyOnly');
    const MARKET_SEARCH_ENDPOINT = 'api/search_marketplace.php';
    const CLIENT_PAGE_SIZE = 24;
    const SERVER_PAGE_SIZE = 120;
    let currentSort = 'relevance';
    let locationReady = false;
    let displayLimit = CLIENT_PAGE_SIZE;
    let paginationSignature = '';
    let serverMatchedKeys = null;
    let serverCriteriaSignature = '';
    let pendingServerCriteriaSignature = '';
    let serverTotalMatches = 0;
    let serverNextOffset = 0;
    let serverHasMore = false;
    let serverIsLoading = false;
    let serverSearchTimer = null;
    let serverSearchController = null;
    let canRevealMoreRows = false;
    let canFetchMoreServerRows = false;
    let lastFilterSource = null;
    let lastAppliedState = null;

    function normalizeSearch(value) {
        let text = (value || '').toString().toLowerCase().trim();
        try {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (error) {
            // Keep original text when normalize is unavailable.
        }
        return text.replace(/\s+/g, ' ');
    }

    function syncInputs(source, value) {
        if (heroSearch && source !== heroSearch) heroSearch.value = value;
        if (gridSearch && source !== gridSearch) gridSearch.value = value;
        if (headerSearch && source !== headerSearch) headerSearch.value = value;
    }

    function shouldSearchServer(state) {
        return state.query !== '' || state.rating4 || state.live || state.partner || state.branch;
    }

    function getCurrentSearchState(source) {
        const rawQuery = ((source && source.value) || (headerSearch && headerSearch.value) || (heroSearch && heroSearch.value) || '').trim();
        return {
            rawQuery,
            query: normalizeSearch(rawQuery),
            rating4: !!(filterRatings4 && filterRatings4.checked),
            live: !!(filterLiveOnly && filterLiveOnly.checked),
            partner: !!(filterPartnerOnly && filterPartnerOnly.checked),
            branch: !!(filterBranchOnly && filterBranchOnly.checked)
        };
    }

    function buildServerCriteriaSignature(state) {
        return JSON.stringify({
            q: state.query || '',
            r4: state.rating4 ? 1 : 0,
            l: state.live ? 1 : 0,
            p: state.partner ? 1 : 0,
            b: state.branch ? 1 : 0
        });
    }

    function buildPaginationSignature(state) {
        return JSON.stringify({
            criteria: buildServerCriteriaSignature(state),
            nearby: !!(filterNearbyOnly && filterNearbyOnly.checked),
            nearby_ready: !!(filterNearbyOnly && filterNearbyOnly.checked && locationReady)
        });
    }

    function formatCount(value) {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed.toLocaleString() : '0';
    }

    function abortPendingServerSearch() {
        if (serverSearchTimer) {
            clearTimeout(serverSearchTimer);
            serverSearchTimer = null;
        }
        if (serverSearchController) {
            serverSearchController.abort();
            serverSearchController = null;
        }
        serverIsLoading = false;
    }

    function resetServerMatches() {
        serverMatchedKeys = null;
        serverCriteriaSignature = '';
        pendingServerCriteriaSignature = '';
        serverTotalMatches = 0;
        serverNextOffset = 0;
        serverHasMore = false;
        abortPendingServerSearch();
    }

    async function fetchServerResults(state, source, criteriaSignature, append) {
        if (serverIsLoading) return;
        if (append && !serverHasMore) return;

        serverIsLoading = true;
        if (!append) {
            serverMatchedKeys = null;
            serverCriteriaSignature = '';
            serverTotalMatches = 0;
            serverNextOffset = 0;
            serverHasMore = false;
        }

        const params = new URLSearchParams();
        if (state.query !== '') params.set('search', state.rawQuery);
        if (state.rating4) params.set('rating4', '1');
        if (state.live) params.set('live', '1');
        if (state.partner) params.set('partner', '1');
        if (state.branch) params.set('branch', '1');
        params.set('offset', append ? String(serverNextOffset) : '0');
        params.set('limit', String(SERVER_PAGE_SIZE));

        const controller = new AbortController();
        serverSearchController = controller;

        try {
            const response = await fetch(MARKET_SEARCH_ENDPOINT + '?' + params.toString(), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal
            });

            if (!response.ok) {
                throw new Error('Search endpoint request failed');
            }

            const payload = await response.json();
            if (!append && pendingServerCriteriaSignature !== criteriaSignature) {
                return;
            }
            if (append && serverCriteriaSignature !== criteriaSignature) {
                return;
            }

            const keys = Array.isArray(payload.store_keys) ? payload.store_keys : [];
            if (!append || !(serverMatchedKeys instanceof Set)) {
                serverMatchedKeys = new Set();
            }

            keys.forEach(function (value) {
                const key = String(value || '');
                if (key !== '') serverMatchedKeys.add(key);
            });

            const payloadTotal = parseInt(payload.total_matches, 10);
            serverTotalMatches = Number.isFinite(payloadTotal) ? Math.max(0, payloadTotal) : serverMatchedKeys.size;

            const payloadNextOffset = payload.next_offset;
            if (payloadNextOffset === null || payloadNextOffset === undefined || payloadNextOffset === '') {
                serverNextOffset = serverMatchedKeys.size;
            } else {
                const nextValue = parseInt(payloadNextOffset, 10);
                serverNextOffset = Number.isFinite(nextValue) ? Math.max(0, nextValue) : serverMatchedKeys.size;
            }
            serverHasMore = !!payload.has_more;
            serverCriteriaSignature = criteriaSignature;
            pendingServerCriteriaSignature = criteriaSignature;
        } catch (error) {
            if (!error || error.name !== 'AbortError') {
                if (!append) {
                    serverMatchedKeys = null;
                    serverCriteriaSignature = '';
                    serverTotalMatches = 0;
                    serverNextOffset = 0;
                    serverHasMore = false;
                }
            }
        } finally {
            if (serverSearchController === controller) {
                serverSearchController = null;
            }
            serverIsLoading = false;
            applyFilters(source || lastFilterSource || heroSearch || gridSearch || headerSearch, {
                skipServerRequest: true,
                preservePagination: true
            });
        }
    }

    function queueServerSearch(state, source) {
        if (!shouldSearchServer(state)) {
            resetServerMatches();
            return;
        }

        const criteriaSignature = buildServerCriteriaSignature(state);
        if (criteriaSignature === serverCriteriaSignature && serverMatchedKeys instanceof Set) {
            return;
        }
        if (criteriaSignature === pendingServerCriteriaSignature && (serverSearchTimer !== null || serverIsLoading)) {
            return;
        }
        pendingServerCriteriaSignature = criteriaSignature;

        if (criteriaSignature !== serverCriteriaSignature) {
            serverMatchedKeys = null;
            serverCriteriaSignature = '';
            serverTotalMatches = 0;
            serverNextOffset = 0;
            serverHasMore = false;
        }
        abortPendingServerSearch();

        serverSearchTimer = window.setTimeout(async function () {
            serverSearchTimer = null;
            await fetchServerResults(state, source, criteriaSignature, false);
        }, 220);
    }

    function toRadians(value) {
        return value * (Math.PI / 180);
    }

    function haversineKm(lat1, lng1, lat2, lng2) {
        const earthRadiusKm = 6371;
        const dLat = toRadians(lat2 - lat1);
        const dLng = toRadians(lng2 - lng1);
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return earthRadiusKm * c;
    }

    function getSortValue(row, key) {
        if (key === 'distance' || key === 'fastest') {
            const distance = parseFloat(row.dataset.distance || '');
            return Number.isFinite(distance) ? distance : Number.POSITIVE_INFINITY;
        }
        if (key === 'top_rated') {
            return -((parseFloat(row.dataset.rating || '0') * 100) + parseInt(row.dataset.reviews || '0', 10));
        }
        return parseInt(row.dataset.index || '0', 10);
    }

    function sortRows() {
        if (!storeGrid) return;
        if ((currentSort === 'distance' || currentSort === 'fastest') && !locationReady) {
            if (detectNearestStatus) detectNearestStatus.textContent = 'Enable location first to sort by distance or fastest delivery.';
        }
        const sorted = [...rows].sort(function (left, right) {
            if (currentSort === 'top_rated') {
                return getSortValue(left, 'top_rated') - getSortValue(right, 'top_rated');
            }
            if (currentSort === 'fastest') {
                const leftMinutes = parseFloat(left.dataset.minutes || '');
                const rightMinutes = parseFloat(right.dataset.minutes || '');
                const leftValue = Number.isFinite(leftMinutes) ? leftMinutes : getSortValue(left, 'distance');
                const rightValue = Number.isFinite(rightMinutes) ? rightMinutes : getSortValue(right, 'distance');
                return leftValue - rightValue;
            }
            if (currentSort === 'distance') {
                return getSortValue(left, 'distance') - getSortValue(right, 'distance');
            }
            return getSortValue(left, 'relevance') - getSortValue(right, 'relevance');
        });
        sorted.forEach(function (row) { storeGrid.appendChild(row); });
    }

    function updatePaginationUi(shownCount, matchedCount, shouldUseServerResults, useServerMatches) {
        const pendingServerLookup = shouldUseServerResults && !useServerMatches && (serverSearchTimer !== null || serverIsLoading);

        if (paginationNote) {
            if (pendingServerLookup) {
                paginationNote.textContent = 'Finding matching stores...';
            } else if (matchedCount <= 0) {
                paginationNote.textContent = 'No stores matched your current filters.';
            } else if (shownCount < matchedCount) {
                paginationNote.textContent = 'Showing ' + formatCount(shownCount) + ' of ' + formatCount(matchedCount) + ' matching stores.';
            } else {
                paginationNote.textContent = 'Showing all ' + formatCount(matchedCount) + ' matching stores.';
            }
        }

        if (!loadMoreButton) return;
        const hasMoreActions = canRevealMoreRows || canFetchMoreServerRows;
        loadMoreButton.hidden = !hasMoreActions && !serverIsLoading;
        loadMoreButton.disabled = serverIsLoading || (!canRevealMoreRows && !canFetchMoreServerRows);
        if (serverIsLoading) {
            loadMoreButton.textContent = 'Loading stores...';
        } else if (canFetchMoreServerRows) {
            loadMoreButton.textContent = 'Load more results';
        } else {
            loadMoreButton.textContent = 'Show more stores';
        }
    }

    function applyFilters(source, options) {
        const config = options || {};
        const state = getCurrentSearchState(source);
        lastFilterSource = source || heroSearch || gridSearch || headerSearch;
        lastAppliedState = state;
        const rawQuery = state.rawQuery;
        const query = state.query;
        const criteriaSignature = buildServerCriteriaSignature(state);
        const shouldUseServerResults = shouldSearchServer(state);
        const nextPaginationSignature = buildPaginationSignature(state);

        if (paginationSignature === '') {
            paginationSignature = nextPaginationSignature;
        } else if (!config.preservePagination && paginationSignature !== nextPaginationSignature) {
            displayLimit = CLIENT_PAGE_SIZE;
            paginationSignature = nextPaginationSignature;
        }

        if (!config.skipServerRequest) {
            queueServerSearch(state, lastFilterSource);
        }

        syncInputs(source, rawQuery);
        const useServerMatches = shouldUseServerResults && serverMatchedKeys instanceof Set && serverCriteriaSignature === criteriaSignature;
        const orderedRows = storeGrid ? Array.from(storeGrid.querySelectorAll('.market-store-row')) : rows;
        const matchedRows = [];

        orderedRows.forEach(function (row) {
            const search = normalizeSearch(row.dataset.search || '');
            const rating = parseFloat(row.dataset.rating || '0');
            const isOpen = row.dataset.open === '1';
            const isBranch = row.dataset.type === 'branch';
            const isPartner = row.dataset.type !== 'branch';
            const distance = parseFloat(row.dataset.distance || '');
            const rowKey = String(row.dataset.storeKey || '');
            const matchQuery = useServerMatches ? serverMatchedKeys.has(rowKey) : (query === '' || search.indexOf(query) !== -1);
            const matchRatings = useServerMatches ? true : (!filterRatings4 || !filterRatings4.checked || rating >= 4);
            const matchLive = useServerMatches ? true : (!filterLiveOnly || !filterLiveOnly.checked || isOpen);
            const matchPartner = useServerMatches ? true : (!filterPartnerOnly || !filterPartnerOnly.checked || isPartner);
            const matchBranch = useServerMatches ? true : (!filterBranchOnly || !filterBranchOnly.checked || isBranch);
            const matchNearby = !filterNearbyOnly || !filterNearbyOnly.checked || (locationReady && Number.isFinite(distance) && distance <= 35);
            if (matchQuery && matchRatings && matchLive && matchPartner && matchBranch && matchNearby) {
                matchedRows.push(row);
            }
        });

        rows.forEach(function (row) { row.classList.add('is-hidden'); });
        const shownRows = matchedRows.slice(0, displayLimit);
        shownRows.forEach(function (row) { row.classList.remove('is-hidden'); });

        const shownCount = shownRows.length;
        const matchedCount = useServerMatches && serverTotalMatches > 0 ? serverTotalMatches : matchedRows.length;
        canRevealMoreRows = matchedRows.length > shownCount;
        canFetchMoreServerRows = useServerMatches && !canRevealMoreRows && serverHasMore;

        if (visibleCount) visibleCount.textContent = formatCount(shownCount);
        updatePaginationUi(shownCount, matchedCount, shouldUseServerResults, useServerMatches);
    }

    function updateNearest(lat, lng) {
        let nearestName = '';
        let nearestDistance = Number.POSITIVE_INFINITY;

        rows.forEach(function (row) {
            const storeLat = parseFloat(row.dataset.lat || '');
            const storeLng = parseFloat(row.dataset.lng || '');
            const timeLabel = row.querySelector('[data-role="time-label"]');

            if (!Number.isFinite(storeLat) || !Number.isFinite(storeLng)) {
                row.dataset.distance = '';
                row.dataset.minutes = '';
                if (timeLabel) timeLabel.textContent = row.dataset.open === '1' ? 'Open now' : 'Closed now';
                return;
            }

            const distanceKm = haversineKm(lat, lng, storeLat, storeLng);
            const minutes = Math.max(5, Math.round((distanceKm * 4.2) + 6));
            row.dataset.distance = distanceKm.toFixed(2);
            row.dataset.minutes = minutes.toString();

            if (timeLabel) {
                timeLabel.textContent = distanceKm.toFixed(1) + ' km | ' + minutes + ' min';
            }

            if (distanceKm < nearestDistance) {
                nearestDistance = distanceKm;
                nearestName = row.querySelector('h3') ? row.querySelector('h3').textContent.trim() : '';
            }
        });

        locationReady = true;
        if (detectNearestStatus) detectNearestStatus.textContent = 'Location detected. Stores can now be sorted by fastest delivery or distance.';
        if (distanceSummary) {
            distanceSummary.textContent = nearestName !== ''
                ? 'Nearest detected shop: ' + nearestName + ' at about ' + nearestDistance.toFixed(1) + ' km from your current location.'
                : 'Location detected, but no stores with saved coordinates were found.';
        }

        currentSort = 'distance';
        sortInputs.forEach(function (input) {
            input.checked = input.value === 'distance';
        });
        sortRows();
        applyFilters(heroSearch || gridSearch, { preservePagination: true });
    }

    if (heroSearch) heroSearch.addEventListener('input', function () { applyFilters(heroSearch); });
    if (gridSearch) gridSearch.addEventListener('input', function () { applyFilters(gridSearch); });
    if (headerSearch) headerSearch.addEventListener('input', function () { applyFilters(headerSearch); });
    sortInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            currentSort = input.value;
            sortRows();
            applyFilters(heroSearch || gridSearch, { preservePagination: true });
        });
    });
    [filterRatings4, filterLiveOnly, filterPartnerOnly, filterBranchOnly, filterNearbyOnly].forEach(function (control) {
        if (!control) return;
        control.addEventListener('change', function () {
            if (control === filterNearbyOnly && filterNearbyOnly.checked && !locationReady) {
                if (detectNearestStatus) detectNearestStatus.textContent = 'Nearby filter will start working after you allow location access.';
                if (distanceSummary) distanceSummary.textContent = 'Use "Detect nearest shops" to filter the store list by real distance.';
            }
            applyFilters(heroSearch || gridSearch);
        });
    });
    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', function () {
            if (serverIsLoading) return;

            if (canRevealMoreRows) {
                displayLimit += CLIENT_PAGE_SIZE;
                applyFilters(lastFilterSource || heroSearch || gridSearch || headerSearch, {
                    skipServerRequest: true,
                    preservePagination: true
                });
                return;
            }

            if (canFetchMoreServerRows && lastAppliedState) {
                displayLimit += CLIENT_PAGE_SIZE;
                fetchServerResults(
                    lastAppliedState,
                    lastFilterSource || heroSearch || gridSearch || headerSearch,
                    buildServerCriteriaSignature(lastAppliedState),
                    true
                );
            }
        });
    }
    if (detectNearestButton) {
        detectNearestButton.addEventListener('click', function () {
            if (!navigator.geolocation) {
                if (detectNearestStatus) detectNearestStatus.textContent = 'This browser does not support geolocation.';
                if (distanceSummary) distanceSummary.textContent = 'Geolocation is unavailable, so nearest-shop detection could not run.';
                return;
            }

            if (detectNearestStatus) detectNearestStatus.textContent = 'Detecting your current location...';
            navigator.geolocation.getCurrentPosition(
                function (position) {
                    updateNearest(position.coords.latitude, position.coords.longitude);
                },
                function () {
                    if (detectNearestStatus) detectNearestStatus.textContent = 'Location permission was denied. You can still browse and sort by rating.';
                    if (distanceSummary) distanceSummary.textContent = 'Nearest Cavite-style sorting needs browser location access. Please allow location if you want true nearest results.';
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 }
            );
        });
    }

    const params = new URLSearchParams(window.location.search);
    const initialSearch = params.get('search');
    const initialSort = (params.get('sort') || '').trim().toLowerCase();
    const initialRating4 = params.get('rating4') === '1';
    const initialLive = params.get('live') === '1';
    const initialPartner = params.get('partner') === '1';
    const initialBranch = params.get('branch') === '1';
    const initialNearby = params.get('nearby') === '1';

    if (['relevance', 'fastest', 'distance', 'top_rated'].includes(initialSort)) {
        currentSort = initialSort;
        sortInputs.forEach(function (input) {
            input.checked = input.value === initialSort;
        });
    }
    if (filterRatings4) filterRatings4.checked = initialRating4;
    if (filterLiveOnly) filterLiveOnly.checked = initialLive;
    if (filterPartnerOnly) filterPartnerOnly.checked = initialPartner;
    if (filterBranchOnly) filterBranchOnly.checked = initialBranch;
    if (filterNearbyOnly) filterNearbyOnly.checked = initialNearby;

    if (initialSearch) {
        syncInputs(null, initialSearch);
    }
    if (initialNearby && !locationReady) {
        if (detectNearestStatus) detectNearestStatus.textContent = 'Nearby filter is active. Allow location access to show nearby stores.';
        if (distanceSummary) distanceSummary.textContent = 'Nearby filtering needs browser location access. Click "Detect nearest shops".';
    }

    sortRows();
    applyFilters(heroSearch || gridSearch || headerSearch);

    <?php if ($show_welcome): ?>
    Swal.fire({
        icon: 'success',
        title: 'Welcome!',
        html: '<p style="font-size:1.05rem;margin-bottom:10px;color:#2b2f3a;">Registration successful.</p><p style="font-size:.95rem;color:#667085;">You are now signed in as <strong><?php echo htmlspecialchars($user_email, ENT_QUOTES); ?></strong>.</p><p style="font-size:.9rem;color:#7a8395;margin-top:14px;">Explore the marketplace and start browsing stores.</p>',
        confirmButtonColor: '#d81b60',
        confirmButtonText: 'Explore now',
        allowOutsideClick: false,
        allowEscapeKey: false
    });
    <?php endif; ?>
});
</script>

<!-- Landing Page Login & Signup Popup Modal Styles -->
<style>
.auth-popup-overlay { position:fixed; inset:0; background:rgba(15, 23, 42, 0.45); backdrop-filter:blur(10px); z-index:2000; display:flex; align-items:center; justify-content:center; padding:16px; opacity:0; visibility:hidden; transition: opacity 0.22s cubic-bezier(.22,1,.36,1), visibility 0.22s cubic-bezier(.22,1,.36,1); }
.auth-popup-overlay.active { opacity:1; visibility:visible; }
.auth-popup-card { background:#fff; border-radius:24px; box-shadow:0 30px 60px rgba(15,23,42,.18); width:min(460px,100%); max-height:92vh; display:flex; flex-direction:column; overflow:hidden; border:1px solid #efddcd; transform:translateY(20px) scale(0.96); transition:transform 0.28s cubic-bezier(.22,1,.36,1); position:relative; }
.auth-popup-overlay.active .auth-popup-card { transform:translateY(0) scale(1); }
.auth-popup-close { position:absolute; top:18px; right:20px; border:none; background:transparent; width:34px; height:34px; border-radius:10px; color:#667085; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:all 0.22s; z-index:10; }
.auth-popup-close:hover { background:#fff9f2; color:#171922; }
.auth-popup-tabs { display:flex; border-bottom:1px solid #efddcd; padding:0 24px; background:#faf7f4; padding-top:10px; }
.auth-popup-tab { appearance:none; border:none; background:transparent; font-weight:800; font-size:.95rem; color:#667085; padding:18px 12px; border-bottom:3px solid transparent; cursor:pointer; transition:all 0.22s; }
.auth-popup-tab:hover { color:#171922; }
.auth-popup-tab.active { color:#b3261e; border-bottom-color:#b3261e; }
.auth-popup-content { padding:28px 24px; overflow-x:hidden; overflow-y:auto; position:relative; }
.auth-popup-pane { display:none; opacity:0; transform:translateX(20px); }
.auth-popup-pane.active { display:block; animation: authPaneSlideFast 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

@keyframes authPaneSlideFast {
    0% { opacity: 0; transform: translateX(22px); }
    100% { opacity: 1; transform: translateX(0); }
}

.auth-popup-form .form-group { display:grid; gap:6px; margin-bottom:16px; }
.auth-popup-form .form-group label { font-size:.88rem; font-weight:700; color:#171922; text-align: left; }
.auth-popup-form .input-wrap { position:relative !important; width:100% !important; display:flex !important; align-items:center !important; }
.auth-popup-form .input-wrap > i { position:absolute !important; left:14px !important; top:50% !important; transform:translateY(-50%) !important; color:#667085 !important; font-size:1rem !important; pointer-events:none !important; z-index:2 !important; }
.auth-popup-form .form-control { width:100% !important; min-height:46px !important; border-radius:12px !important; border:1px solid #efddcd !important; padding:0 46px 0 42px !important; outline:none !important; font-family:inherit !important; font-size:.9rem !important; transition:all 0.22s !important; background:#fcf9f6 !important; }
.auth-popup-form .form-control:focus { border-color:#b3261e !important; box-shadow:0 0 0 3px rgba(179,38,30,0.12) !important; background:#fff !important; }
.auth-popup-form .toggle-password { position:absolute !important; right:12px !important; top:50% !important; transform:translateY(-50%) !important; border:none !important; background:transparent !important; color:#667085 !important; cursor:pointer !important; padding:6px 8px !important; font-size:1rem !important; z-index:5 !important; display:inline-flex !important; align-items:center !important; justify-content:center !important; }
.auth-popup-form .toggle-password:hover { color:#b3261e !important; }
.auth-popup-form .password-field { padding-right:46px !important; }
.auth-popup-form .remember-forgot { display:flex; justify-content:space-between; align-items:center; font-size:.84rem; margin-bottom:20px; }
.auth-popup-form .remember-label { display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:600; color:#667085; }
.auth-popup-form .forgot-link { color:#b3261e; text-decoration:none; font-weight:700; }
.auth-popup-form .forgot-link:hover { text-decoration:underline; }
.auth-popup-form .btn-submit { width:100%; min-height:48px; border-radius:12px; border:none; background:#b3261e; color:#fff; font-weight:800; font-size:.94rem; display:inline-flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; transition:all 0.22s; box-shadow:0 10px 24px rgba(179,38,30,.2); }
.auth-popup-form .btn-submit:hover { background:#8f261a; transform:translateY(-1px); }
.auth-popup-form .auth-link { margin-top:20px; font-size:.86rem; color:#667085; text-align: center; }
.auth-popup-form .auth-link a { color:#b3261e; text-decoration:none; font-weight:700; }
.auth-popup-form .auth-link a:hover { text-decoration:underline; }

.reg-choice-grid { display:grid; gap:16px; margin-top:4px; }
.reg-choice-card { display:flex; gap:16px; align-items:center; border:1px solid #efddcd; border-radius:16px; padding:18px; text-decoration:none; color:inherit; transition:all 0.22s; background:#fcf9f6; text-align: left; }
.reg-choice-card:hover { border-color:#b3261e; background:#fff5e9; transform:translateY(-2px); box-shadow:0 12px 30px rgba(15,23,42,.1); }
.reg-choice-icon { width:48px; height:48px; border-radius:12px; background:rgba(179,38,30,0.08); color:#b3261e; display:inline-flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.reg-choice-info { display:grid; gap:2px; }
.reg-choice-title { font-weight:800; font-size:1.02rem; color:#171922; }
.reg-choice-desc { font-size:.82rem; color:#667085; line-height:1.3; }
</style>

<!-- Landing Page Login & Signup Popup Modal Markup -->
<div class="auth-popup-overlay" id="authPopupOverlay">
    <div class="auth-popup-card">
        <button type="button" class="auth-popup-close" id="authPopupClose" aria-label="Close modal">
            <i class="fas fa-times"></i>
        </button>
        
        <div class="auth-popup-tabs">
            <button type="button" class="auth-popup-tab active" data-auth-tab="login">Sign In</button>
            <button type="button" class="auth-popup-tab" data-auth-tab="register">Create Account</button>
        </div>
        
        <div class="auth-popup-content">
            <!-- Login Pane -->
            <div class="auth-popup-pane active" id="authPaneLogin">
                <form class="auth-popup-form" id="popupLoginForm" novalidate>
                    <div class="form-group">
                        <label for="popupEmail">Email Address</label>
                        <div class="input-wrap">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="popupEmail" name="email" class="form-control" placeholder="Enter your email address" required autocomplete="email">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="popupPassword">Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="popupPassword" name="password" class="form-control password-field" placeholder="Enter your password" required autocomplete="current-password">
                            <button type="button" class="toggle-password" id="popupTogglePassword" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="remember-forgot">
                        <label class="remember-label">
                            <input type="checkbox" name="remember" id="popupRememberMe" value="1">
                            <span>Remember me</span>
                        </label>
                        <a href="javascript:void(0);" class="forgot-link" id="popupSwitchToForgot">Forgot password?</a>
                    </div>
                    
                    <button type="submit" class="btn-submit" id="popupLoginBtn">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>Sign In</span>
                    </button>
                    
                    <div class="auth-link">
                        Don't have an account? 
                        <a href="javascript:void(0);" id="popupSwitchToRegister">Create an account</a>
                    </div>
                </form>
            </div>

            <!-- Register Pane -->
            <div class="auth-popup-pane" id="authPaneRegister">
                <div class="reg-choice-grid" style="grid-template-columns: 1fr;">
                    <a href="register.php?account_type=individual" class="reg-choice-card">
                        <div class="reg-choice-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="reg-choice-info">
                            <span class="reg-choice-title">Individual Customer</span>
                            <span class="reg-choice-desc">Order delicious lechon dishes, rate food, track deliveries, and manage your account.</span>
                        </div>
                    </a>
                </div>
                <div class="auth-popup-form" style="margin-top: 10px;">
                    <div class="auth-link">
                        Already have an account? 
                        <a href="javascript:void(0);" id="popupSwitchToLogin">Sign in</a>
                    </div>
                </div>
            </div>

            <!-- Forgot Password Pane -->
            <div class="auth-popup-pane" id="authPaneForgot">
                <form class="auth-popup-form" id="popupForgotForm" novalidate style="margin-top: 10px;">
                    <input type="hidden" name="ajax" value="true">
                    <div id="popupForgotAlert" class="auth-popup-alert" style="display:none; margin-bottom:15px; padding:12px 16px; border-radius:8px; font-size:.88rem; font-weight:600; line-height:1.4;"></div>

                    <div class="form-group">
                        <label for="popupForgotEmail">Email Address</label>
                        <div class="input-wrap">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="popupForgotEmail" name="email" class="form-control" placeholder="Enter your email address" required autocomplete="email">
                        </div>
                    </div>

                    <button type="submit" class="btn-submit" id="popupForgotBtn" style="margin-top: 10px;">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send Reset Link</span>
                    </button>

                    <div class="auth-link">
                        <a href="javascript:void(0);" id="popupForgotBackToLogin"><i class="fas fa-arrow-left"></i> Back to Sign In</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const authOverlay = document.getElementById('authPopupOverlay');
    const authClose = document.getElementById('authPopupClose');
    const togglePasswordBtn = document.getElementById('popupTogglePassword');
    const passwordInput = document.getElementById('popupPassword');
    const popupLoginForm = document.getElementById('popupLoginForm');
    const authTabs = document.querySelectorAll('[data-auth-tab]');
    const authPanes = document.querySelectorAll('.auth-popup-pane');
    
    function switchAuthTab(tabName) {
        authTabs.forEach(tab => {
            if (tab.dataset.authTab === tabName) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });
        
        authPanes.forEach(pane => {
            if (pane.id === 'authPane' + tabName.charAt(0).toUpperCase() + tabName.slice(1)) {
                pane.classList.add('active');
            } else {
                pane.classList.remove('active');
            }
        });
    }

    authTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            switchAuthTab(this.dataset.authTab);
        });
    });

    const switchToRegisterLink = document.getElementById('popupSwitchToRegister');
    if (switchToRegisterLink) {
        switchToRegisterLink.addEventListener('click', function(e) {
            e.preventDefault();
            switchAuthTab('register');
        });
    }

    const switchToLoginLink = document.getElementById('popupSwitchToLogin');
    if (switchToLoginLink) {
        switchToLoginLink.addEventListener('click', function(e) {
            e.preventDefault();
            switchAuthTab('login');
        });
    }

    const switchToForgotLink = document.getElementById('popupSwitchToForgot');
    const popupForgotEmailInput = document.getElementById('popupForgotEmail');
    const popupEmailInput = document.getElementById('popupEmail');

    if (switchToForgotLink) {
        switchToForgotLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (popupEmailInput && popupEmailInput.value && popupForgotEmailInput) {
                popupForgotEmailInput.value = popupEmailInput.value.trim();
            }
            switchAuthTab('forgot');
        });
    }

    const popupForgotBackToLogin = document.getElementById('popupForgotBackToLogin');
    if (popupForgotBackToLogin) {
        popupForgotBackToLogin.addEventListener('click', function(e) {
            e.preventDefault();
            switchAuthTab('login');
        });
    }

    const popupForgotForm = document.getElementById('popupForgotForm');
    const popupForgotBtn = document.getElementById('popupForgotBtn');
    const popupForgotAlert = document.getElementById('popupForgotAlert');

    if (popupForgotForm) {
        popupForgotForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const email = popupForgotEmailInput ? popupForgotEmailInput.value.trim() : '';
            if (!email) return;

            if (popupForgotBtn) {
                popupForgotBtn.disabled = true;
                popupForgotBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Sending Link...</span>';
            }
            if (popupForgotAlert) popupForgotAlert.style.display = 'none';

            try {
                const formData = new FormData(popupForgotForm);
                const res = await fetch('reset_password_request.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    if (popupForgotAlert) {
                        popupForgotAlert.style.background = '#E8F5E9';
                        popupForgotAlert.style.borderLeft = '4px solid #4CAF50';
                        popupForgotAlert.style.color = '#2E7D32';
                        popupForgotAlert.innerHTML = '<i class="fas fa-check-circle"></i> ' + (data.message || 'Password reset link sent! Check your inbox.');
                        popupForgotAlert.style.display = 'block';
                    }
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Reset Link Sent',
                            text: data.message || 'Check your email inbox for password reset instructions.',
                            confirmButtonColor: '#b3261e'
                        });
                    }
                } else {
                    if (popupForgotAlert) {
                        popupForgotAlert.style.background = '#FFEBEE';
                        popupForgotAlert.style.borderLeft = '4px solid #F44336';
                        popupForgotAlert.style.color = '#b3261e';
                        popupForgotAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Error processing request.');
                        popupForgotAlert.style.display = 'block';
                    }
                }
            } catch (err) {
                if (popupForgotAlert) {
                    popupForgotAlert.style.background = '#FFEBEE';
                    popupForgotAlert.style.borderLeft = '4px solid #F44336';
                    popupForgotAlert.style.color = '#b3261e';
                    popupForgotAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i> An unexpected error occurred. Please try again.';
                    popupForgotAlert.style.display = 'block';
                }
            } finally {
                if (popupForgotBtn) {
                    popupForgotBtn.disabled = false;
                    popupForgotBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>Send Reset Link</span>';
                }
            }
        });
    }
    
    
    
    if (authClose) {
        authClose.addEventListener('click', function() {
            if (authOverlay) authOverlay.classList.remove('active');
        });
    }
    
    if (authOverlay) {
        authOverlay.addEventListener('click', function(e) {
            if (e.target === authOverlay) {
                authOverlay.classList.remove('active');
            }
        });
    }
    
    if (togglePasswordBtn && passwordInput) {
        togglePasswordBtn.addEventListener('click', function() {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            const icon = this.querySelector('i');
            if (icon) {
                icon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
            }
        });
    }
    
    if (popupLoginForm) {
        popupLoginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const emailInput = document.getElementById('popupEmail');
            const submitBtn = document.getElementById('popupLoginBtn');
            
            const email = emailInput.value.trim();
            const password = passwordInput.value;
            const remember = document.getElementById('popupRememberMe').checked;
            
            if (!email || !password) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please enter both email and password.',
                    confirmButtonColor: '#b3261e'
                });
                return;
            }
            
            if (submitBtn) {
                submitBtn.disabled = true;
                const span = submitBtn.querySelector('span');
                if (span) span.textContent = 'Signing in...';
            }
            
            const formData = new FormData();
            formData.append('login', 'true');
            formData.append('ajax', 'true');
            formData.append('email', email);
            formData.append('password', password);
            if (remember) formData.append('remember', '1');
            
            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(data => {
                try {
                    const json = JSON.parse(data);
                    if (json.success) {
                        window.location.reload();
                        return;
                    }
                } catch(e) {}
                
                if (data.includes('alert-error') || data.includes('Invalid email or password')) {
                    const errorMatch = data.match(/alert-error.*?<div>(.*?)<\/div>/s);
                    const errMsg = errorMatch ? errorMatch[1].trim() : 'Invalid email or password';
                    Swal.fire({
                        icon: 'error',
                        title: 'Sign In Failed',
                        text: errMsg,
                        confirmButtonColor: '#b3261e'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An unexpected error occurred. Please try again.',
                        confirmButtonColor: '#b3261e'
                    });
                }
                
                if (submitBtn) {
                    submitBtn.disabled = false;
                    const span = submitBtn.querySelector('span');
                    if (span) span.textContent = 'Sign In';
                }
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not connect to authentication server.',
                    confirmButtonColor: '#b3261e'
                });
                if (submitBtn) {
                    submitBtn.disabled = false;
                    const span = submitBtn.querySelector('span');
                    if (span) span.textContent = 'Sign In';
                }
            });
        });
    }
});
</script>
<?php include 'includes/footer.php'; ?>


