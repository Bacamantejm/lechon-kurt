<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/store_availability_helper.php';

function normalizeText($value) {
    $text = strtolower(trim((string)$value));
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string)$text);
}

function normalizeKey($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\b(branch|store|lechon|delights)\b/', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function parseBoolParam($value) {
    $raw = strtolower(trim((string)$value));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function buildSearchBlob(array $parts) {
    $normalized = array_map(static function ($part) {
        return normalizeText($part);
    }, $parts);
    $filtered = array_filter($normalized, static function ($part) {
        return $part !== '';
    });
    return trim((string)preg_replace('/\s+/', ' ', implode(' ', $filtered)));
}

$search = normalizeText($_GET['search'] ?? '');
$filter_rating4 = parseBoolParam($_GET['rating4'] ?? '');
$filter_live = parseBoolParam($_GET['live'] ?? '');
$filter_partner = parseBoolParam($_GET['partner'] ?? '');
$filter_branch = parseBoolParam($_GET['branch'] ?? '');
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$offset = max(0, $offset);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 60;
$limit = max(1, min($limit, 500));

$stores = [];
$branches = [];
$branch_by_owner = [];
$branch_by_email = [];
$branch_by_name = [];
$matched_branch_ids = [];

$branch_sql = "SELECT store_id, owner_user_id, store_name, address, city, province, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, is_active, latitude, longitude
               FROM store_locations
               WHERE is_active = 1";
sahEnsureStoreLocationAvailabilitySchema($conn);
$branch_result = mysqli_query($conn, $branch_sql);
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
            'email' => strtolower(trim((string)$row['email'])),
            'availability' => $availability,
            'is_open' => !empty($availability['is_open']),
            'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : null
        ];
        $branches[] = $branch;
        if ((int)$branch['owner_user_id'] > 0) {
            $branch_by_owner[(int)$branch['owner_user_id']] = $branch;
        }
        if ($branch['email'] !== '') {
            $branch_by_email[$branch['email']] = $branch;
        }
        $branch_by_name[normalizeKey($branch['name'])] = $branch;
    }
    mysqli_free_result($branch_result);
}

$partner_sql = "SELECT id, full_name, email, address, business_name, business_type
                FROM users
                WHERE account_type = 'organization' AND is_active = 1";
$partner_result = mysqli_query($conn, $partner_sql);
if ($partner_result) {
    while ($row = mysqli_fetch_assoc($partner_result)) {
        $seller_id = (int)$row['id'];
        $name = trim((string)$row['business_name']) !== ''
            ? trim((string)$row['business_name'])
            : (trim((string)$row['full_name']) . ' Store');
        $email = strtolower(trim((string)$row['email']));
        $match = $branch_by_owner[$seller_id] ?? ($branch_by_email[$email] ?? ($branch_by_name[normalizeKey($name)] ?? null));

        if ($match && !empty($match['id'])) {
            $matched_branch_ids[(int)$match['id']] = true;
        }

        $location = '';
        if ($match) {
            $location = trim(implode(', ', array_filter([
                $match['address'] ?? '',
                $match['city'] ?? '',
                $match['province'] ?? ''
            ])));
        }
        if ($location === '') {
            $location = trim((string)($row['address'] ?? ''));
        }

        $stores['seller-' . $seller_id] = [
            'key' => 'seller-' . $seller_id,
            'type' => 'partner',
            'name' => $name,
            'location' => $location,
            'business_type' => trim((string)$row['business_type']),
            'city' => $match['city'] ?? '',
            'province' => $match['province'] ?? '',
            'live' => false,
            'is_open' => !empty($match['is_open']),
            'rating' => 0.0,
            'search_terms' => array_filter([
                $name,
                $location,
                $row['business_type'] ?? '',
                $row['full_name'] ?? '',
                $row['email'] ?? ''
            ])
        ];
    }
    mysqli_free_result($partner_result);
}

mysqli_query($conn, "SET SESSION group_concat_max_len = 8192");

$global_has_products = false;
$global_search_terms = [];

$product_agg_sql = "SELECT
                        COALESCE(p.seller_id, 0) AS seller_id,
                        COUNT(*) AS item_count,
                        AVG(CASE WHEN p.avg_rating > 0 THEN p.avg_rating END) AS avg_rating,
                        GROUP_CONCAT(DISTINCT LOWER(TRIM(p.name)) ORDER BY p.name SEPARATOR ' ') AS product_names,
                        GROUP_CONCAT(DISTINCT LOWER(TRIM(p.category)) ORDER BY p.category SEPARATOR ' ') AS category_names
                    FROM products p
                    WHERE p.is_archived = 0 AND p.is_active = 1
                    GROUP BY COALESCE(p.seller_id, 0)";
$product_agg_result = mysqli_query($conn, $product_agg_sql);
if ($product_agg_result) {
    while ($row = mysqli_fetch_assoc($product_agg_result)) {
        $global_has_products = true;
        $seller_id = (int)($row['seller_id'] ?? 0);
        $key = $seller_id > 0 ? ('seller-' . $seller_id) : 'platform-main';
        if (!isset($stores[$key])) {
            $stores[$key] = [
                'key' => $key,
                'type' => $seller_id > 0 ? 'seller' : 'platform',
                'name' => $seller_id > 0 ? ('Seller #' . $seller_id) : 'Lechon Delights Marketplace',
                'location' => 'Marketplace storefront',
                'business_type' => $seller_id > 0 ? 'Marketplace seller' : 'Flagship kitchen',
                'city' => '',
                'province' => '',
                'live' => false,
                'is_open' => false,
                'rating' => 0.0,
                'search_terms' => []
            ];
        }

        $stores[$key]['live'] = ((int)($row['item_count'] ?? 0) > 0);
        $stores[$key]['rating'] = isset($row['avg_rating']) && $row['avg_rating'] !== null ? (float)$row['avg_rating'] : (float)$stores[$key]['rating'];

        $product_names = trim((string)($row['product_names'] ?? ''));
        $category_names = trim((string)($row['category_names'] ?? ''));
        if ($product_names !== '') {
            $stores[$key]['search_terms'][] = $product_names;
            $global_search_terms[] = $product_names;
        }
        if ($category_names !== '') {
            $stores[$key]['search_terms'][] = $category_names;
            $global_search_terms[] = $category_names;
        }
    }
    mysqli_free_result($product_agg_result);
}

$global_rating = 0.0;
$global_rating_result = mysqli_query($conn, "SELECT AVG(avg_rating) AS avg_rating FROM products WHERE is_archived = 0 AND is_active = 1 AND avg_rating > 0");
if ($global_rating_result) {
    $row = mysqli_fetch_assoc($global_rating_result);
    if ($row && isset($row['avg_rating']) && $row['avg_rating'] !== null) {
        $global_rating = (float)$row['avg_rating'];
    }
    mysqli_free_result($global_rating_result);
}

$global_search_blob = trim(implode(' ', array_slice(array_unique(array_filter(array_map(static function ($term) {
    return normalizeText($term);
}, $global_search_terms))), 0, 120)));

foreach ($branches as $branch) {
    $branch_id = (int)($branch['id'] ?? 0);
    if ($branch_id <= 0 || isset($matched_branch_ids[$branch_id])) {
        continue;
    }

    $location = trim(implode(', ', array_filter([
        $branch['address'] ?? '',
        $branch['city'] ?? '',
        $branch['province'] ?? ''
    ])));

    $stores['branch-' . $branch_id] = [
        'key' => 'branch-' . $branch_id,
        'type' => 'branch',
        'name' => trim((string)($branch['name'] ?? 'Branch')),
        'location' => $location,
        'business_type' => 'Pickup branch',
        'city' => trim((string)($branch['city'] ?? '')),
        'province' => trim((string)($branch['province'] ?? '')),
        'live' => $global_has_products,
        'is_open' => !empty($branch['is_open']),
        'rating' => $global_rating,
        'search_terms' => array_filter([
            $branch['name'] ?? '',
            $location,
            $global_search_blob
        ])
    ];
}

$matched_keys = [];
$scanned = 0;

foreach ($stores as $store) {
    $scanned++;
    $search_blob = buildSearchBlob([
        $store['name'] ?? '',
        $store['location'] ?? '',
        $store['business_type'] ?? '',
        $store['city'] ?? '',
        $store['province'] ?? '',
        implode(' ', (array)($store['search_terms'] ?? []))
    ]);

    $type = strtolower(trim((string)($store['type'] ?? '')));
    $is_partner = $type !== 'branch';
    $is_branch = $type === 'branch';
    $rating = (float)($store['rating'] ?? 0);
    $live = !empty($store['is_open']);

    $match_query = ($search === '' || strpos($search_blob, $search) !== false);
    $match_rating = (!$filter_rating4 || $rating >= 4);
    $match_live = (!$filter_live || $live);
    $match_partner = (!$filter_partner || $is_partner);
    $match_branch = (!$filter_branch || $is_branch);

    if ($match_query && $match_rating && $match_live && $match_partner && $match_branch) {
        $matched_keys[] = (string)($store['key'] ?? '');
    }
}

$matched_keys = array_values(array_unique(array_filter($matched_keys)));
$total_matches = count($matched_keys);
$page_keys = array_slice($matched_keys, $offset, $limit);
$next_offset = $offset + count($page_keys);
$has_more = $next_offset < $total_matches;

echo json_encode([
    'success' => true,
    'store_keys' => $page_keys,
    'count' => count($page_keys),
    'total_matches' => $total_matches,
    'offset' => $offset,
    'limit' => $limit,
    'next_offset' => $has_more ? $next_offset : null,
    'has_more' => $has_more,
    'meta' => [
        'scanned' => $scanned,
        'search' => $search,
        'filters' => [
            'rating4' => $filter_rating4,
            'live' => $filter_live,
            'partner' => $filter_partner,
            'branch' => $filter_branch
        ]
    ]
]);

if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}
?>
