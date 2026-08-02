<?php
session_start();
require_once 'includes/config.php';
require_once __DIR__ . '/includes/favorites_helper.php';
require_once __DIR__ . '/includes/store_availability_helper.php';

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
    return [
        [
            'id' => 1004,
            'owner_user_id' => 0,
            'name' => 'General Trias, Cavite Branch',
            'address' => "9002 Governor's Drive, Manggahan",
            'city' => 'General Trias',
            'province' => 'Cavite',
            'phone' => '0917-887-2168',
            'email' => 'gentrias@lechondelights.com',
            'hours' => '8:00 AM - 8:00 PM',
            'latitude' => null,
            'longitude' => null
        ],
    ];
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

        if (!is_cavite_scope((string)($row['address'] ?? ''), (string)($row['city'] ?? ''), (string)($row['province'] ?? ''))) {
            continue;
        }

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
        $products[] = ['name' => trim((string)$row['name']), 'store' => $stores[$key]['name'], 'category' => $category, 'price' => $price, 'rating' => $rating, 'reviews' => (int)$row['review_count'], 'sold' => (int)($sales_map[(int)$row['id']] ?? 0), 'image' => $image];
    }
    mysqli_free_result($product_result);
}

arsort($categories);
usort($products, static function ($a, $b) { return [$b['sold'], $b['reviews'], $b['rating']] <=> [$a['sold'], $a['reviews'], $a['rating']]; });
$featured_products = array_slice($products, 0, 8);
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

$stores = array_values(array_filter($stores, static function ($store) {
    return !empty($store['is_cavite']);
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
$top_rated_stores = array_slice(array_values(array_filter($top_rated_stores, static fn($store) => (float)($store['rating'] ?? 0) > 0)), 0, 6);

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
    top: 92px;
    align-self: start;
    padding: 24px;
    border-radius: 26px;
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
</style>

<div class="market-home">
    <section class="market-section" id="marketplaceStores">
        <div class="container">
            <div class="market-explorer">
                <aside class="market-sidebar">
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
                    <div class="panda-hero-banners">
                        <article class="panda-hero-card panda-card-pink">
                            <div class="panda-card-arch"></div>
                            <div class="panda-card-content">
                                <h2 class="panda-card-title">Order Fresh Lechon</h2>
                                <p class="panda-card-desc">Enjoy crispy skin and juicy meat, roasted fresh for every order.</p>
                                <a href="#marketplaceStores" class="panda-card-btn">Order Now</a>
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
                                <a href="preorder.php" class="panda-card-btn panda-card-btn-alt">Reserve Now</a>
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

                    <?php if (!empty($top_rated_stores)): ?>
                    <section class="market-mini-section">
                        <div class="market-head" style="margin-bottom:14px;">
                            <div>
                                <h2>Top-rated Cavite shops</h2>
                                <p>These are the highest-rated Cavite stores currently visible in your marketplace. Click any card to open the shop menu, or view the full Cavite top-rated directory.</p>
                            </div>
                            <a href="locations.php?sort=top_rated" class="market-btn-soft"><i class="fas fa-star"></i> View top-rated stores</a>
                        </div>
                        <div class="market-mini-grid">
                            <?php foreach ($top_rated_stores as $store): ?>
                                <a href="<?php echo htmlspecialchars($store['menu_link']); ?>" class="market-mini-card">
                                    <div class="market-mini-thumb" style="background-image:url('<?php echo htmlspecialchars($store['image']); ?>');"></div>
                                    <div class="market-mini-body">
                                        <div class="market-mini-title"><?php echo htmlspecialchars($store['name']); ?></div>
                                        <div class="market-mini-sub"><?php echo htmlspecialchars($store['location']); ?></div>
                                        <div class="market-mini-meta">
                                            <span class="market-mini-status-pill <?php echo !empty($store['is_open']) ? 'open' : 'closed'; ?>">
                                                <i class="fas fa-circle"></i> <?php echo !empty($store['is_open']) ? 'Open' : 'Closed'; ?>
                                            </span>
                                            <span><i class="fas fa-star"></i> <?php echo number_format((float)$store['rating'], 1); ?></span>
                                            <span><i class="fas fa-comment-dots"></i> <?php echo number_format((int)$store['reviews']); ?> reviews</span>
                                        </div>
                                    </div>
                                    <span class="market-mini-arrow"><i class="fas fa-arrow-right"></i></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($top_brand_stores)): ?>
                    <section class="market-mini-section">
                        <h2>Featured Cavite partner brands</h2>
                        <div class="market-mini-grid">
                            <?php foreach ($top_brand_stores as $store): ?>
                                <a href="<?php echo htmlspecialchars($store['menu_link']); ?>" class="market-mini-card">
                                    <div class="market-mini-thumb" style="background-image:url('<?php echo htmlspecialchars($store['image']); ?>');"></div>
                                    <div class="market-mini-body">
                                        <div class="market-mini-title"><?php echo htmlspecialchars($store['name']); ?></div>
                                        <div class="market-mini-sub"><?php echo htmlspecialchars($store['business_type']); ?></div>
                                        <div class="market-mini-meta">
                                            <span class="market-mini-status-pill <?php echo !empty($store['is_open']) ? 'open' : 'closed'; ?>">
                                                <i class="fas fa-circle"></i> <?php echo !empty($store['is_open']) ? 'Open' : 'Closed'; ?>
                                            </span>
                                            <span><i class="fas fa-star"></i> <?php echo $store['rating'] > 0 ? number_format((float)$store['rating'], 1) : 'New'; ?></span>
                                            <span data-role="mini-time"><?php echo !empty($store['is_open']) ? 'Open now' : 'Closed now'; ?></span>
                                        </div>
                                    </div>
                                    <span class="market-mini-arrow"><i class="fas fa-arrow-right"></i></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($top_shop_stores)): ?>
                    <section class="market-mini-section">
                        <h2>Cavite branches and pickup shops</h2>
                        <div class="market-mini-grid">
                            <?php foreach ($top_shop_stores as $store): ?>
                                <a href="<?php echo htmlspecialchars($store['menu_link']); ?>" class="market-mini-card">
                                    <div class="market-mini-thumb" style="background-image:url('<?php echo htmlspecialchars($store['image']); ?>');"></div>
                                    <div class="market-mini-body">
                                        <div class="market-mini-title"><?php echo htmlspecialchars($store['name']); ?></div>
                                        <div class="market-mini-sub"><?php echo htmlspecialchars($store['location']); ?></div>
                                        <div class="market-mini-meta">
                                            <span class="market-mini-status-pill <?php echo !empty($store['is_open']) ? 'open' : 'closed'; ?>">
                                                <i class="fas fa-circle"></i> <?php echo !empty($store['is_open']) ? 'Open' : 'Closed'; ?>
                                            </span>
                                            <span><i class="fas fa-map-location-dot"></i> Branch ready</span>
                                            <span data-role="mini-time"><?php echo htmlspecialchars($store['note']); ?></span>
                                        </div>
                                    </div>
                                    <span class="market-mini-arrow"><i class="fas fa-arrow-right"></i></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="market-mini-section">
                        <h2>All Cavite stores</h2>
                        <div class="market-toolbar" style="margin-bottom:16px;">
                            <label class="market-input" for="gridStoreSearch">
                                <i class="fas fa-magnifying-glass"></i>
                                <input type="text" id="gridStoreSearch" placeholder="Search stores, dishes, tags, or cities">
                            </label>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                <div class="market-toolbar-note"><strong id="visibleStoreCount"><?php echo number_format($visible_store_count); ?></strong> Cavite stores visible</div>
                                <div class="market-toolbar-note"><?php echo number_format($live_store_count); ?> open storefronts</div>
                                <div class="market-toolbar-note"><?php echo number_format($city_count); ?> Cavite cities</div>
                            </div>
                        </div>

                        <div class="market-store-list" id="marketStoreGrid">
                            <?php foreach ($stores as $index => $store): ?>
                                <?php
                                $price = $store['start'] !== null ? 'PHP ' . number_format((float)$store['start'], 2) : 'Coming soon';
                                $type_label = $store['type'] === 'branch' ? 'Pickup branch' : ($store['type'] === 'partner' ? 'Partner store' : ($store['type'] === 'platform' ? 'Marketplace favorite' : 'Seller'));
                                $store_key_value = (string)($store['key'] ?? '');
                                $is_store_favorite = !empty($favorite_store_keys[$store_key_value]);
                                ?>
                                <article
                                    class="market-store-row"
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
                                    <div class="market-store-row-thumb" style="background-image:url('<?php echo htmlspecialchars($store['image']); ?>');"></div>
                                    <div class="market-store-row-main">
                                        <div class="market-store-row-head">
                                            <h3><?php echo htmlspecialchars($store['name']); ?></h3>
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
                                                title="<?php echo $is_store_favorite ? 'Remove from favorites' : 'Save to favorites'; ?>">
                                                <i class="<?php echo $is_store_favorite ? 'fas' : 'far'; ?> fa-heart"></i>
                                            </button>
                                        </div>
                                        <div class="market-store-row-copy"><?php echo htmlspecialchars($store['summary']); ?></div>
                                        <div class="market-store-row-meta">
                                            <span><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($store['location']); ?></span>
                                            <span><i class="fas fa-star"></i> <?php echo $store['rating'] > 0 ? number_format((float)$store['rating'], 1) : 'New'; ?></span>
                                            <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars(implode(', ', $store['tags'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="market-store-row-side">
                                        <div class="market-score-block">
                                            <strong><?php echo htmlspecialchars($price); ?></strong>
                                            <span>Starts at | <?php echo number_format((int)$store['count']); ?> dishes</span>
                                        </div>
                                        <div class="market-list-actions">
                                            <?php if (!empty($store['live']) && !empty($store['is_open'])): ?>
                                                <a href="<?php echo htmlspecialchars($store['menu_link']); ?>" class="market-card-btn"><i class="fas fa-utensils"></i> Browse</a>
                                            <?php elseif (!empty($store['live'])): ?>
                                                <span class="market-card-btn-disabled"><i class="fas fa-door-closed"></i> Closed</span>
                                            <?php else: ?>
                                                <span class="market-card-btn-disabled"><i class="fas fa-clock"></i> Soon</span>
                                            <?php endif; ?>
                                            <?php if ($store['type'] === 'branch' || !empty($store['branch'])): ?>
                                                <a href="locations.php" class="market-card-btn-soft"><i class="fas fa-map-location-dot"></i> Branch</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <div class="market-pagination">
                            <div class="market-pagination-note" id="marketPaginationNote">Showing all stores.</div>
                            <button type="button" class="market-btn-soft" id="marketLoadMoreBtn">Show more stores</button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($featured_products)): ?>
    <section class="market-section">
        <div class="container">
            <div class="market-head">
                <div>
                    <h2>Popular picks right now</h2>
                    <p>These featured dishes are pulled from your live catalog and surfaced using product activity, reviews, and ratings.</p>
                </div>
            </div>
            <div class="market-dishes">
                <?php foreach ($featured_products as $product): ?>
                    <article class="market-dish">
                        <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy">
                        <div class="market-dish-body">
                            <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                            <div class="market-dish-meta">
                                <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($product['store']); ?></span>
                                <span><i class="fas fa-tags"></i> <?php echo htmlspecialchars($product['category']); ?></span>
                                <?php if ($product['rating'] > 0): ?><span><i class="fas fa-star"></i> <?php echo number_format((float)$product['rating'], 1); ?></span><?php endif; ?>
                            </div>
                            <div class="market-dish-price">
                                <span>PHP <?php echo number_format((float)$product['price'], 2); ?></span>
                                <span><?php echo number_format((int)$product['sold']); ?> sold</span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
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
.auth-popup-content { padding:28px 24px; overflow-y:auto; }
.auth-popup-pane { display:none; }
.auth-popup-pane.active { display:block; }
.auth-popup-form .form-group { display:grid; gap:6px; margin-bottom:16px; }
.auth-popup-form .form-group label { font-size:.88rem; font-weight:700; color:#171922; text-align: left; }
.auth-popup-form .input-wrap { position:relative; }
.auth-popup-form .input-wrap i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#667085; }
.auth-popup-form .form-control { width:100%; min-height:45px; border-radius:12px; border:1px solid #efddcd; padding:0 14px 0 42px; outline:none; font-family:inherit; font-size:.9rem; transition:all 0.22s; background:#fcf9f6; }
.auth-popup-form .form-control:focus { border-color:#b3261e; box-shadow:0 0 0 3px rgba(179,38,30,0.12); background:#fff; }
.auth-popup-form .toggle-password { position:absolute; right:12px; top:50%; transform:translateY(-50%); border:none; background:transparent; color:#667085; cursor:pointer; min-height:36px; display:inline-flex; align-items:center; }
.auth-popup-form .password-field { padding-right:45px; }
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
                        <a href="reset_password_request.php" class="forgot-link">Forgot password?</a>
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
                <div class="reg-choice-grid">
                    <a href="register.php?account_type=individual" class="reg-choice-card">
                        <div class="reg-choice-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="reg-choice-info">
                            <span class="reg-choice-title">Individual Customer</span>
                            <span class="reg-choice-desc">Order delicious lechon dishes, rate food, track deliveries, and manage your account.</span>
                        </div>
                    </a>
                    
                    <a href="register.php?account_type=organization" class="reg-choice-card">
                        <div class="reg-choice-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="reg-choice-info">
                            <span class="reg-choice-title">Business Partner</span>
                            <span class="reg-choice-desc">Register your lechon business, manage your storefront menu, and sync billing invoices.</span>
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
    
    // Intercept clicks on the sign-in button in the header
    document.querySelectorAll('.btn-signin').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            switchAuthTab('login');
            if (authOverlay) authOverlay.classList.add('active');
        });
    });

    // Intercept clicks on the register button in the header
    document.querySelectorAll('.btn-register').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            switchAuthTab('register');
            if (authOverlay) authOverlay.classList.add('active');
        });
    });
    
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


