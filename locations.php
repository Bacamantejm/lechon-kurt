
<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$current_page = 'locations';
$page_title = 'Locations | Browse Shops and Store Menus';
require_once 'includes/config.php';
require_once __DIR__ . '/includes/rider_helper.php';
if (!empty($_SESSION['user_id']) && isDeliveryDriverUser($conn, (int)$_SESSION['user_id'])) {
    header("Location: rider/index.php");
    exit();
}
require_once 'includes/store_availability_helper.php';

function locationsAssetPath(string $path, string $fallback = 'images/store-bg.jpg'): string {
    $path = trim($path);
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
function locationsNormalizeKey(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/\b(branch|store|lechon|delights)\b/', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)preg_replace('/\s+/', ' ', $value));
}
function locationsLabel(string $address = '', string $city = '', string $province = '', string $barangay = ''): string {
    $raw_parts = [];
    foreach ([$address, $barangay, $city, $province] as $part) {
        $value = trim((string)$part);
        if ($value === '') continue;
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
        if ($norm === '' || isset($seen[$norm])) continue;
        $seen[$norm] = true;
        $parts[] = trim($part);
    }

    return !empty($parts) ? implode(', ', $parts) : 'Cavite';
}
function locationsIsUsableAddress(string $address): bool {
    $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $address)));
    if ($normalized === '') return false;
    if (strlen($normalized) < 7) return false;
    $invalid = ['asd', 'asdasd', 'test', 'sample', 'n/a', 'na', 'none', '-', '--'];
    return !in_array($normalized, $invalid, true);
}
function locationsPickBestAddress(array $candidates): string {
    $first_non_empty = '';
    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value === '') {
            continue;
        }
        if ($first_non_empty === '') {
            $first_non_empty = $value;
        }
        if (locationsIsUsableAddress($value)) {
            return $value;
        }
    }
    return $first_non_empty;
}
function locationsIsCavite(string $address = '', string $city = '', string $province = ''): bool {
    $haystack = strtolower(trim(implode(' ', [$address, $city, $province])));
    if ($haystack === '') return false;
    return strpos($haystack, 'cavite') !== false
        || strpos($haystack, 'general trias') !== false
        || strpos($haystack, 'imus') !== false
        || strpos($haystack, 'bacoor') !== false
        || strpos($haystack, 'dasmarinas') !== false
        || strpos($haystack, 'dasmariñas') !== false
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

function locationsSanitizeItems(array $items): array {
    $clean = [];
    $blacklist = ['tae', 'panis', 'test', 'sample', 'asd', 'asdasd', 'kain tae'];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item === '') continue;
        $lower = strtolower($item);
        $bad = false;
        foreach ($blacklist as $b) {
            if (strpos($lower, $b) !== false) {
                $bad = true;
                break;
            }
        }
        if (!$bad) {
            $clean[] = $item;
        }
    }
    if (empty($clean)) {
        return ['Whole Lechon', 'Lechon Belly', 'Lechon Paksiw'];
    }
    return $clean;
}

function locationsAddUniqueLimited(array &$items, string $value, int $limit = 3): void {
    $value = trim($value);
    if ($value === '' || in_array($value, $items, true) || count($items) >= $limit) return;
    $items[] = $value;
}
function locationsInitials(string $value): string {
    $parts = preg_split('/\s+/', trim($value)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials !== '' ? $initials : 'LD';
}
function locationsMenuLink(array $store): string {
    $params = [];
    $seller_id = isset($store['seller_id']) ? (int)$store['seller_id'] : 0;
    $branch_id = isset($store['branch_id']) ? (int)$store['branch_id'] : 0;
    if ($seller_id > 0) $params['seller_id'] = $seller_id;
    if ($branch_id > 0) $params['branch_id'] = $branch_id;
    return empty($params) ? 'menu.php' : 'menu.php?' . http_build_query($params);
}
function locationsBranchFallbacks(): array {
    return [];
}

$branches = [];
$branch_by_email = [];
$branch_by_name = [];
$branch_by_owner = [];
sahEnsureStoreLocationAvailabilitySchema($conn);
$branch_result = mysqli_query($conn, "SELECT store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, is_active, latitude, longitude FROM store_locations WHERE is_active = 1 ORDER BY store_name");
if ($branch_result) {
    $sample_branch_blacklist = [
        "dasmariñas central branch", "dasmarinas central branch", "dasmariñas branch", "dasmarinas branch", "dasmariñas main branch", "dasmarinas main branch",
        "bacoor express branch", "bacoor branch",
        "imus heritage branch", "imus branch",
        "general trias branch",
        "tagaytay ridge branch", "tagaytay branch",
        "silang branch", "justine business", "justine business (silang depot)",
        "janna restaurant", "janna restaurant (gen. trias)",
        "lydias", "linda", "ako budoy"
    ];
    $sample_email_blacklist = [
        "dasma@lechondelights.com", "bacoor@lechondelights.com", "imus@lechondelights.com",
        "tagaytay@lechondelights.com", "gentrias@lechondelights.com", "silang@lechondelights.com",
        "jannasantos@gmail.com", "justinehero033@gmail.com", "useraccount@gmail.com", "localone@gmail.com"
    ];
    while ($row = mysqli_fetch_assoc($branch_result)) {
        $row_name_norm = strtolower(trim((string)$row["store_name"]));
        $row_email_norm = strtolower(trim((string)$row["email"]));
        if (in_array($row_name_norm, $sample_branch_blacklist, true) || in_array($row_email_norm, $sample_email_blacklist, true)) {
            continue;
        }
        if (!locationsIsCavite((string)($row['address'] ?? ''), (string)($row['city'] ?? ''), (string)($row['province'] ?? ''))) {
            continue;
        }
        $branch = [
            'id'=>(int)$row['store_id'],'owner_user_id'=>(int)($row['owner_user_id'] ?? 0),'name'=>fixMojibakeText(trim((string)$row['store_name'])),'address'=>fixMojibakeText(trim((string)$row['address'])),'city'=>fixMojibakeText(trim((string)$row['city'])),'province'=>fixMojibakeText(trim((string)$row['province'])),
            'phone'=>trim((string)$row['phone']),'email'=>strtolower(trim((string)$row['email'])),'hours'=>trim((string)$row['opening_hours']),
            'opening_time'=>trim((string)($row['opening_time'] ?? '')),'closing_time'=>trim((string)($row['closing_time'] ?? '')),'operating_days'=>trim((string)($row['operating_days'] ?? '1,2,3,4,5,6,7')),'availability_mode'=>trim((string)($row['availability_mode'] ?? 'schedule')),'manual_status'=>trim((string)($row['manual_status'] ?? 'closed')),'is_active'=>(int)($row['is_active'] ?? 1),
            'latitude'=>isset($row['latitude']) && $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude'=>isset($row['longitude']) && $row['longitude'] !== null ? (float)$row['longitude'] : null,
        ];
        $branch['availability'] = sahResolveStoreAvailability($branch);
        if ($branch['hours'] === '') {
            $branch['hours'] = (string)($branch['availability']['schedule'] ?? '');
        }
        $branches[] = $branch;
    }
    mysqli_free_result($branch_result);
}
if (empty($branches)) $branches = locationsBranchFallbacks();
foreach ($branches as $branch) {
    if (!isset($branch['availability']) || !is_array($branch['availability'])) {
        $branch['availability'] = sahResolveStoreAvailability([
            'is_active' => 1,
            'availability_mode' => 'schedule',
            'manual_status' => 'closed',
            'opening_time' => '08:00:00',
            'closing_time' => '20:00:00',
            'operating_days' => '1,2,3,4,5,6,7',
        ]);
        if (empty($branch['hours'])) {
            $branch['hours'] = (string)($branch['availability']['schedule'] ?? 'Daily | 8:00 AM - 8:00 PM');
        }
    }
    if ((int)($branch['owner_user_id'] ?? 0) > 0) $branch_by_owner[(int)$branch['owner_user_id']] = $branch;
    if ($branch['email'] !== '') $branch_by_email[$branch['email']] = $branch;
    $branch_by_name[locationsNormalizeKey($branch['name'])] = $branch;
}

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

$stores = [];
$latest_approved_partner_sql = "SELECT fa1.* FROM franchise_applications fa1 INNER JOIN (SELECT user_id, MAX(id) AS latest_id FROM franchise_applications WHERE status = 'approved' GROUP BY user_id) latest ON latest.latest_id = fa1.id";
$partner_scope_filter = ($scoped_partner_seller_id > 0) ? " AND u.id = " . (int)$scoped_partner_seller_id : "";
$partner_sql = "SELECT u.id, u.full_name, u.email, u.phone, u.address, u.business_name, u.business_type, u.created_at,
                       fa.business_address, fa.city_name, fa.province_name, fa.barangay_name
                FROM users u
                INNER JOIN ({$latest_approved_partner_sql}) fa ON fa.user_id = u.id
                WHERE u.account_type = 'organization' AND u.is_active = 1 {$partner_scope_filter}
                ORDER BY COALESCE(NULLIF(TRIM(u.business_name), ''), u.full_name)";
$partner_result = mysqli_query($conn, $partner_sql);
if ($partner_result) {
    $sample_partner_blacklist = [
        "dasmariñas central branch", "dasmarinas central branch", "dasmariñas branch", "dasmarinas branch", "dasmariñas main branch", "dasmarinas main branch",
        "bacoor express branch", "bacoor branch",
        "imus heritage branch", "imus branch",
        "general trias branch",
        "tagaytay ridge branch", "tagaytay branch",
        "silang branch", "justine business", "justine business (silang depot)",
        "janna restaurant", "janna restaurant (gen. trias)",
        "lydias", "linda", "ako budoy"
    ];
    $sample_partner_emails = [
        "dasma@lechondelights.com", "bacoor@lechondelights.com", "imus@lechondelights.com",
        "tagaytay@lechondelights.com", "gentrias@lechondelights.com", "silang@lechondelights.com",
        "jannasantos@gmail.com", "justinehero033@gmail.com", "useraccount@gmail.com", "localone@gmail.com"
    ];
    while ($row = mysqli_fetch_assoc($partner_result)) {
        $seller_id = (int)$row['id'];
        $store_name = trim((string)$row['business_name']) !== '' ? trim((string)$row['business_name']) : trim((string)$row['full_name']) . ' Store';
        $email = strtolower(trim((string)$row['email']));
        $store_name_norm = strtolower(trim((string)$store_name));
        if (in_array($store_name_norm, $sample_partner_blacklist, true) || in_array($email, $sample_partner_emails, true)) {
            continue;
        }
        $matched_branch = $branch_by_owner[$seller_id] ?? ($branch_by_email[$email] ?? ($branch_by_name[locationsNormalizeKey($store_name)] ?? null));
        $partner_application_address = trim((string)($row['business_address'] ?? ''));
        $partner_profile_address = trim((string)($row['address'] ?? ''));
        $partner_address = locationsPickBestAddress([$partner_application_address, $partner_profile_address]);
        $partner_barangay = trim((string)($row['barangay_name'] ?? ''));
        $partner_city = trim((string)($row['city_name'] ?? ''));
        $partner_province = trim((string)($row['province_name'] ?? ''));
        $branch_address = trim((string)($matched_branch['address'] ?? ''));
        $branch_city = trim((string)($matched_branch['city'] ?? ''));
        $branch_province = trim((string)($matched_branch['province'] ?? ''));
        $partner_has_preferred_address = locationsIsUsableAddress($partner_address) || locationsIsUsableAddress($partner_profile_address) || $partner_city !== '' || $partner_province !== '';
        $resolved_address = $partner_has_preferred_address
            ? locationsPickBestAddress([$partner_address, $partner_profile_address, $branch_address])
            : locationsPickBestAddress([$branch_address, $partner_profile_address, $partner_address]);
        $resolved_city = $partner_city !== '' ? $partner_city : $branch_city;
        $resolved_province = $partner_province !== '' ? $partner_province : $branch_province;
        $resolved_location = locationsLabel($resolved_address, $resolved_city, $resolved_province, $partner_barangay);
        $is_cavite_partner = locationsIsCavite($partner_address, $partner_city, $partner_province)
            || locationsIsCavite($partner_profile_address, '', '')
            || locationsIsCavite($branch_address, $branch_city, $branch_province)
            || locationsIsCavite($resolved_address, $resolved_city, $resolved_province);
        if (
            !$matched_branch
            && !$is_cavite_partner
        ) {
            continue;
        }
        $availability = $matched_branch['availability'] ?? sahResolveStoreAvailability([
            'is_active' => 1,
            'availability_mode' => 'schedule',
            'manual_status' => 'closed',
            'opening_time' => '08:00:00',
            'closing_time' => '20:00:00',
            'operating_days' => '1,2,3,4,5,6,7',
        ]);
        $stores['seller-' . $seller_id] = [
            'key'=>'seller-' . $seller_id,'name'=>$store_name,'seller_id'=>$seller_id,'branch_id'=>$matched_branch ? (int)($matched_branch['id'] ?? 0) : 0,
            'type'=>'partner',
            'business_type'=>trim((string)$row['business_type']) !== '' ? ucwords(str_replace('_', ' ', (string)$row['business_type'])) : 'Marketplace shop',
            'phone'=>trim((string)$row['phone']),
            'location'=>$resolved_location,
            'city'=>$resolved_city,
            'province'=>$resolved_province,
            'branch'=>$matched_branch,
            'hours'=>$matched_branch['hours'] ?? (string)($availability['schedule'] ?? ''),
            'availability'=>$availability,
            'image'=>'','count'=>0,'rating_sum'=>0.0,'rating_count'=>0,'rating'=>0.0,'reviews'=>0,'start'=>null,'tags'=>[],'items'=>[],
            'search_terms'=>array_filter([$store_name,$row['full_name'] ?? '',$row['email'] ?? '',$row['business_type'] ?? '',$resolved_address,$resolved_city,$resolved_province,$partner_address,$partner_profile_address,$partner_application_address,$partner_barangay,$partner_city,$partner_province,$availability['label'] ?? '',$availability['schedule'] ?? '']),
            'live'=>false,
            'note'=>trim((string)($availability['note'] ?? '')) !== '' ? trim((string)$availability['note']) : 'Browse this shop menu and available items.',
            'joined'=>!empty($row['created_at']) ? date('M Y', strtotime((string)$row['created_at'])) : 'Recently joined',
            'created_ts'=>!empty($row['created_at']) ? (int)strtotime((string)$row['created_at']) : 0,
        ];
    }
    mysqli_free_result($partner_result);
}

$global_products = [];
$global_min_price = null;
$global_rating_sum = 0.0;
$global_rating_count = 0;
$global_reviews = 0;
$product_sql = "SELECT p.id, p.seller_id, p.name, p.category, p.price, p.image, p.avg_rating, p.review_count, COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(u.full_name), ''), 'Lechon Delights Marketplace') AS seller_name FROM products p LEFT JOIN users u ON u.id = p.seller_id WHERE p.is_archived = 0 AND p.is_active = 1 ORDER BY p.created_at DESC, p.id DESC";
$product_result = mysqli_query($conn, $product_sql);
if ($product_result) {
    while ($row = mysqli_fetch_assoc($product_result)) {
        $seller_id = isset($row['seller_id']) ? (int)$row['seller_id'] : 0;
        $key = $seller_id > 0 ? 'seller-' . $seller_id : 'platform-main';
        if (!isset($stores[$key])) {
            continue;
        }
        $price = (float)$row['price'];
        $rating = (float)($row['avg_rating'] ?? 0);
        $category = trim((string)($row['category'] ?? '')) !== '' ? trim((string)$row['category']) : 'Filipino favorites';
        $image = locationsAssetPath((string)($row['image'] ?? ''), 'images/menu/whole-lechon.jpg');
        $stores[$key]['live'] = true; $stores[$key]['count']++; $stores[$key]['start'] = $stores[$key]['start'] === null ? $price : min((float)$stores[$key]['start'], $price); $stores[$key]['reviews'] += (int)($row['review_count'] ?? 0);
        if ($rating > 0) { $stores[$key]['rating_sum'] += $rating; $stores[$key]['rating_count']++; $global_rating_sum += $rating; $global_rating_count++; }
        if ($stores[$key]['image'] === '') $stores[$key]['image'] = $image;
        locationsAddUniqueLimited($stores[$key]['tags'], $category, 3); locationsAddUniqueLimited($stores[$key]['items'], (string)$row['name'], 3);
        $stores[$key]['search_terms'][] = (string)$row['name']; $stores[$key]['search_terms'][] = $category; $stores[$key]['search_terms'][] = (string)($row['seller_name'] ?? '');
        $global_products[] = ['name'=>trim((string)$row['name']),'category'=>$category];
        $global_min_price = $global_min_price === null ? $price : min((float)$global_min_price, $price);
        $global_reviews += (int)($row['review_count'] ?? 0);
    }
    mysqli_free_result($product_result);
}
$global_rating = $global_rating_count > 0 ? ($global_rating_sum / $global_rating_count) : 0.0;
$global_items = array_slice(locationsSanitizeItems(array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['name'] ?? '')); }, $global_products))))), 0, 3);
$global_tags = array_slice(array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['category'] ?? '')); }, $global_products)))), 0, 3);

foreach ($branches as $branch) {
    $has_matching_store = false;
    foreach ($stores as $candidate) {
        if (!empty($candidate['branch']) && (int)($candidate['branch']['id'] ?? 0) === (int)$branch['id']) { $has_matching_store = true; break; }
    }
    if ($has_matching_store) continue;
    $stores['branch-' . (int)$branch['id']] = [
        'key'=>'branch-' . (int)$branch['id'],'name'=>trim((string)$branch['name']),'seller_id'=>0,'branch_id'=>(int)$branch['id'],'type'=>'branch','business_type'=>'Pickup branch','phone'=>trim((string)$branch['phone']),
        'location'=>locationsLabel((string)$branch['address'], (string)$branch['city'], (string)$branch['province']),'city'=>trim((string)$branch['city']),'province'=>trim((string)$branch['province']),'branch'=>$branch,'hours'=>trim((string)$branch['hours']),
        'availability'=>$branch['availability'] ?? sahResolveStoreAvailability($branch),
        'image'=>locationsAssetPath('images/store-bg.jpg', 'images/hero-bg.jpg'),'count'=>count($global_products),'rating_sum'=>0.0,'rating_count'=>0,'rating'=>$global_rating,'reviews'=>$global_reviews,'start'=>$global_min_price,
        'tags'=>!empty($global_tags) ? $global_tags : ['Lechon favorites'],'items'=>!empty($global_items) ? $global_items : ['Whole Lechon', 'Lechon Belly'],'search_terms'=>array_filter([$branch['name'],$branch['address'],$branch['city'],$branch['province']]),
        'live'=>!empty($global_products),'note'=>trim((string)(($branch['availability']['note'] ?? ''))) !== '' ? trim((string)$branch['availability']['note']) : 'Browse available marketplace items and pickup options.','joined'=>'Branch network','created_ts'=>0,
    ];
}

foreach ($stores as &$store) {
    if ($store['rating_count'] > 0) $store['rating'] = $store['rating_sum'] / $store['rating_count'];
    if ($store['image'] === '') $store['image'] = locationsAssetPath('images/hero-bg.jpg', 'images/store-bg.jpg');
    if (empty($store['tags'])) $store['tags'] = [$store['business_type']];
    $clean_store_items = locationsSanitizeItems($store['items'] ?? []);
    $store['summary'] = implode(' · ', array_slice($clean_store_items, 0, 3));
    $search_blob = implode(' ', array_filter([
        strtolower(trim((string)$store['name'])), strtolower(trim((string)$store['location'])), strtolower(trim((string)$store['business_type'])), strtolower(trim((string)$store['city'])), strtolower(trim((string)$store['province'])),
        strtolower(trim((string)$store['note'])), strtolower(trim((string)implode(' ', $store['tags']))), strtolower(trim((string)implode(' ', $store['items']))), strtolower(trim((string)implode(' ', $store['search_terms']))),
    ]));
    $store['search'] = trim((string)preg_replace('/\s+/', ' ', $search_blob));
    $store['menu_link'] = locationsMenuLink($store);
    $store['live_label'] = !empty($store['live']) ? 'Menu Ready' : 'Profile Ready';
    $store['type_label'] = $store['type'] === 'branch' ? 'Store branch' : ($store['type'] === 'partner' ? 'Partner shop' : ($store['type'] === 'platform' ? 'Marketplace' : 'Shop'));
    $store['brand_initials'] = locationsInitials((string)$store['name']);
    $store['sort_newest'] = (int)($store['created_ts'] ?? 0);
    $availability = isset($store['availability']) && is_array($store['availability']) ? $store['availability'] : sahResolveStoreAvailability($store);
    $store['status_label'] = (string)($availability['label'] ?? 'Closed');
    $store['status_class'] = (string)($availability['class'] ?? 'closed');
    $store['schedule_summary'] = trim((string)($availability['schedule'] ?? $store['hours'] ?? ''));
}
unset($store);

$stores = array_values(array_filter($stores, static function ($store) {
    return locationsIsCavite((string)($store['location'] ?? ''), (string)($store['city'] ?? ''), (string)($store['province'] ?? ''));
}));
usort($stores, static function ($a, $b) {
    return [!empty($b['live']) ? 1 : 0, (int)($b['count'] ?? 0), (float)($b['rating'] ?? 0), strtolower((string)($a['name'] ?? ''))] <=> [!empty($a['live']) ? 1 : 0, (int)($a['count'] ?? 0), (float)($a['rating'] ?? 0), strtolower((string)($b['name'] ?? ''))];
});

$total_store_count = count($stores);
$live_store_count = count(array_filter($stores, static fn($store) => !empty($store['live'])));
$partner_store_count = count(array_filter($stores, static fn($store) => in_array($store['type'], ['partner', 'seller'], true)));
$branch_store_count = count(array_filter($stores, static fn($store) => $store['type'] === 'branch'));
$city_count = count(array_unique(array_filter(array_map(static fn($store) => trim((string)($store['city'] ?? '')), $stores))));
$quick_jump_terms = ['All Areas' => ''];

foreach (['Cavite', 'General Trias', 'Imus', 'Bacoor', 'Dasmarinas', 'Tagaytay', 'Trece Martires', 'Kawit'] as $term) {
    foreach ($stores as $store) {
        $haystack = strtolower((string)($store['search'] ?? ''));
        if ($haystack !== '' && strpos($haystack, strtolower($term)) !== false) {
            $quick_jump_terms[$term] = $term;
            break;
        }
    }
}

foreach ($stores as $store) {
    $city = trim((string)($store['city'] ?? ''));
    if ($city === '' || isset($quick_jump_terms[$city])) {
        continue;
    }
    $quick_jump_terms[$city] = $city;
    if (count($quick_jump_terms) >= 9) {
        break;
    }
}

$area_filter_options = [];
foreach ($stores as $store) {
    $city = trim((string)($store['city'] ?? ''));
    $province = trim((string)($store['province'] ?? ''));
    $label = $city !== '' ? $city : $province;
    if ($label === '') continue;
    if ($province !== '' && $city !== '' && strcasecmp($city, $province) !== 0) {
        $label .= ', ' . $province;
    }
    $key = strtolower(trim((string)preg_replace('/\s+/', ' ', $label)));
    if (!isset($area_filter_options[$key])) {
        $area_filter_options[$key] = $label;
    }
}
asort($area_filter_options, SORT_NATURAL | SORT_FLAG_CASE);

include 'includes/header.php';
?>
<style>
:root {
    --loc-red: #b3261e;
    --loc-red-hover: #981b15;
    --loc-ink: #101828;
    --loc-muted: #475467;
    --loc-line: #eaecf0;
    --loc-card: #ffffff;
    --loc-bg: #f8f9fa;
    --loc-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

body {
    background: var(--loc-bg) !important;
}

/* ==========================================================================
   COMPACT HEADER SECTION
   ========================================================================== */
.locations-header-section {
    padding: 28px 0 20px;
}
.locations-header-section .container,
.locations-directory .container {
    max-width: 1240px;
}
.locations-compact-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    padding: 24px 28px;
    background: var(--loc-card);
    border: 1px solid var(--loc-line);
    border-radius: 16px;
    box-shadow: var(--loc-shadow);
}
.locations-kicker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
    padding: 4px 12px;
    border-radius: 999px;
    background: #fff1f0;
    color: var(--loc-red);
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border: 1px solid #fee4e2;
}
.locations-compact-header h1 {
    margin: 0 0 6px;
    font-size: clamp(1.4rem, 2.2vw, 1.85rem);
    line-height: 1.2;
    letter-spacing: -0.02em;
    color: var(--loc-ink);
    font-weight: 800;
}
.locations-compact-header p {
    margin: 0;
    font-size: 0.88rem;
    line-height: 1.5;
    color: var(--loc-muted);
}
.locations-stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-radius: 12px;
    background: #f8f9fa;
    border: 1px solid var(--loc-line);
    white-space: nowrap;
    font-size: 0.82rem;
    color: var(--loc-muted);
}
.locations-stat-pill strong {
    color: var(--loc-ink);
    font-weight: 800;
    font-size: 0.95rem;
}
.locations-stat-pill .stat-sep {
    color: #cbd5e1;
}

/* ==========================================================================
   DIRECTORY TOOLBAR & FILTERS
   ========================================================================== */
.locations-directory {
    padding: 0 0 140px;
}
.directory-toolbar {
    display: flex;
    gap: 12px;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 14px;
    padding: 14px 18px;
    border-radius: 16px;
    border: 1px solid var(--loc-line);
    background: var(--loc-card);
    box-shadow: var(--loc-shadow);
}
.directory-search {
    flex: 1 1 280px;
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 40px;
    padding: 0 14px;
    border-radius: 10px;
    background: #f8f9fa;
    border: 1px solid var(--loc-line);
    transition: all 0.2s ease;
}
.directory-search:focus-within {
    border-color: var(--loc-red);
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}
.directory-search i {
    color: var(--loc-muted);
    font-size: 0.88rem;
}
.directory-search input {
    width: 100%;
    border: none;
    outline: none;
    background: transparent;
    color: var(--loc-ink);
    font-size: 0.88rem;
}
.directory-filters {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.filter-chip,
.jump-chip {
    min-height: 36px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid var(--loc-line);
    background: #ffffff;
    color: var(--loc-ink);
    font-weight: 700;
    font-size: 0.82rem;
    cursor: pointer;
    transition: all 0.18s ease;
}
.filter-chip:hover,
.jump-chip:hover {
    border-color: var(--loc-red);
    color: var(--loc-red);
}
.filter-chip.active,
.jump-chip.active {
    background: var(--loc-red);
    color: #ffffff;
    border-color: var(--loc-red);
    box-shadow: 0 2px 8px rgba(179, 38, 30, 0.2);
}
.directory-dropdowns {
    display: flex;
    gap: 8px;
    align-items: center;
}
.directory-sort {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 6px 2px 10px;
    border-radius: 10px;
    border: 1px solid var(--loc-line);
    background: #f8f9fa;
}
.directory-sort label {
    font-size: 0.72rem;
    font-weight: 800;
    color: var(--loc-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.directory-sort select {
    min-height: 32px;
    padding: 0 24px 0 8px;
    border: 1px solid var(--loc-line);
    border-radius: 6px;
    background: #ffffff;
    color: var(--loc-ink);
    font-weight: 700;
    font-size: 0.82rem;
    outline: none;
    cursor: pointer;
}
.directory-jumps {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}
.nearest-store-notice,
.empty-state {
    margin-bottom: 18px;
    border-radius: 16px;
    border: 1px solid var(--loc-line);
    background: var(--loc-card);
    box-shadow: var(--loc-shadow);
}
.nearest-store-notice {
    padding: 12px 16px;
    color: var(--loc-red);
    font-weight: 700;
    font-size: 0.85rem;
}
.empty-state {
    padding: 44px 20px;
    text-align: center;
}
.empty-state i {
    font-size: 2.2rem;
    color: var(--loc-red);
    margin-bottom: 10px;
}
.empty-state h3 {
    margin: 0 0 6px;
    color: var(--loc-ink);
    font-size: 1.15rem;
    font-weight: 800;
}
.empty-state p {
    margin: 0;
    color: var(--loc-muted);
    font-size: 0.88rem;
}

/* ==========================================================================
   CLEAN & COMPACT RESTAURANT GRID (MATCHING LANDING PAGE)
   ========================================================================== */
.restaurant-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.restaurant-card {
    border-radius: 16px;
    background: var(--loc-card);
    border: 1px solid var(--loc-line);
    box-shadow: var(--loc-shadow);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
}
.restaurant-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 24px rgba(16, 24, 40, 0.08);
    border-color: #d0d5dd;
}
.restaurant-card.nearest-selected {
    border-color: var(--loc-red);
    box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.12), 0 10px 24px rgba(16, 24, 40, 0.08);
}
.restaurant-card-link {
    display: flex;
    flex-direction: column;
    height: 100%;
    color: inherit;
    text-decoration: none;
}
.restaurant-media {
    position: relative;
    width: 100%;
    height: 140px;
    overflow: hidden;
    background: #f1f5f9;
}
.restaurant-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}
.restaurant-card:hover .restaurant-media img {
    transform: scale(1.04);
}
.market-type-pill {
    position: absolute;
    top: 8px;
    left: 8px;
    z-index: 2;
    background: rgba(16, 24, 40, 0.78);
    backdrop-filter: blur(4px);
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
}
.market-time-pill {
    position: absolute;
    bottom: 8px;
    left: 8px;
    z-index: 2;
    font-size: 0.68rem;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}
.market-time-pill.pill-open {
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
}
.market-time-pill.pill-closed {
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
}
.restaurant-pill-rating {
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 2;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(4px);
    color: #101828;
    font-size: 0.72rem;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 999px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.restaurant-body {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    flex: 1 1 auto;
    padding: 10px 14px 12px 14px;
    gap: 5px;
}
.restaurant-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.restaurant-topline h3 {
    margin: 0;
    color: var(--loc-ink);
    font-size: 0.95rem;
    font-weight: 800;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.restaurant-location {
    margin: 0;
    color: var(--loc-muted);
    font-size: 0.75rem;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 5px;
}
.restaurant-location i {
    color: var(--loc-red);
    font-size: 0.72rem;
    flex-shrink: 0;
}
.restaurant-summary {
    margin: 0;
    font-size: 0.78rem;
    color: #64748b;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.panda-card-dss-info {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 2px 0 1px;
}
.dss-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
}
.dss-badge.dss-eta {
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
}
.dss-badge.dss-items {
    background: #f8f9fa;
    color: #475467;
    border: 1px solid #eaecf0;
}
.panda-card-footer-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
    padding-top: 6px;
    border-top: 1px solid var(--loc-line);
    gap: 8px;
}
.panda-card-city {
    font-size: 0.74rem;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.panda-card-city i {
    font-size: 0.7rem;
}
.panda-card-price-text {
    font-size: 0.86rem;
    font-weight: 800;
    color: var(--loc-red);
    white-space: nowrap;
}
.highlight {
    background: #fedf89;
    border-radius: 3px;
    padding: 0 2px;
}

/* ==========================================================================
   RESPONSIVE LAYOUT BREAKPOINTS
   ========================================================================== */
@media (max-width: 1100px) {
    .restaurant-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 768px) {
    .locations-compact-header {
        flex-direction: column;
        align-items: flex-start;
        padding: 18px 20px;
    }
    .restaurant-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .directory-dropdowns {
        width: 100%;
        justify-content: space-between;
    }
    .directory-sort {
        flex: 1 1 auto;
    }
    .directory-sort select {
        width: 100%;
    }
}
@media (max-width: 520px) {
    .locations-directory {
        padding-bottom: 140px !important;
    }
    .restaurant-grid {
        grid-template-columns: 1fr;
    }
    .directory-toolbar {
        padding: 10px;
    }
    .restaurant-media {
        height: 130px;
    }
}

/* ==========================================================================
   DARK MODE ENGINE
   ========================================================================== */
html.dark-mode body,
body.dark-mode {
    background: #0f172a !important;
    color: #f8fafc !important;
}
body.dark-mode .locations-compact-header,
body.dark-mode .directory-toolbar,
body.dark-mode .restaurant-card,
body.dark-mode .empty-state,
body.dark-mode .nearest-store-notice {
    background: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25) !important;
}
body.dark-mode .locations-kicker {
    background: rgba(179, 38, 30, 0.2) !important;
    border-color: rgba(239, 68, 68, 0.3) !important;
    color: #ef4444 !important;
}
body.dark-mode .locations-compact-header h1,
body.dark-mode .restaurant-topline h3,
body.dark-mode .empty-state h3 {
    color: #f8fafc !important;
}
body.dark-mode .locations-compact-header p,
body.dark-mode .restaurant-location,
body.dark-mode .restaurant-summary,
body.dark-mode .empty-state p {
    color: #94a3b8 !important;
}
body.dark-mode .locations-stat-pill,
body.dark-mode .directory-search,
body.dark-mode .directory-sort,
body.dark-mode .filter-chip,
body.dark-mode .jump-chip,
body.dark-mode .dss-badge.dss-items {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
body.dark-mode .directory-search input,
body.dark-mode .directory-sort select {
    color: #f8fafc !important;
    background: #0f172a !important;
}
body.dark-mode .locations-stat-pill strong {
    color: #f8fafc !important;
}
body.dark-mode .filter-chip:hover,
body.dark-mode .jump-chip:hover {
    background: #334155 !important;
    color: #ffffff !important;
}
body.dark-mode .filter-chip.active,
body.dark-mode .jump-chip.active {
    background: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
}
body.dark-mode .restaurant-card:hover {
    border-color: #475569 !important;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.4) !important;
}
body.dark-mode .panda-card-footer-line {
    border-top-color: #334155 !important;
}
body.dark-mode .panda-card-city {
    color: #94a3b8 !important;
}
body.dark-mode .highlight {
    background: #854d0e !important;
    color: #fef08a !important;
}
</style>

<section class="locations-header-section">
    <div class="container">
        <div class="locations-compact-header">
            <div class="locations-header-text">
                <span class="locations-kicker"><i class="fas fa-store"></i> Cavite Store Directory</span>
                <h1>Cavite Lechon Shops &amp; Branches</h1>
                <p>Browse local lechon stores and pickup branches in Cavite. Select a shop to explore their full menu and order directly.</p>
            </div>
            <div class="locations-header-meta">
                <div class="locations-stat-pill">
                    <span class="stat-item"><strong id="visibleStores"><?php echo number_format($total_store_count); ?></strong> Stores</span>
                    <span class="stat-sep">·</span>
                    <span class="stat-item"><strong><?php echo number_format($live_store_count); ?></strong> Menus</span>
                    <span class="stat-sep">·</span>
                    <span class="stat-item"><strong><?php echo number_format(max($city_count, $branch_store_count)); ?></strong> Cavite Areas</span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="locations-directory">
    <div class="container">
        <!-- Compact Search & Filter Toolbar -->
        <div class="directory-toolbar">
            <div class="directory-search">
                <i class="fas fa-search"></i>
                <input type="text" id="storeSearch" placeholder="Search shops, branches, or menu items...">
            </div>
            
            <div class="directory-filters">
                <button type="button" class="filter-chip active" data-filter="all">All</button>
                <button type="button" class="filter-chip" data-filter="open">Open Now</button>
                <button type="button" class="filter-chip" data-filter="branch">Branches</button>
                <button type="button" class="filter-chip" data-filter="partner">Partner Shops</button>
            </div>

            <div class="directory-dropdowns">
                <div class="directory-sort directory-area">
                    <label for="storeAreaFilter">Area</label>
                    <select id="storeAreaFilter">
                        <option value="">All Areas</option>
                        <?php foreach ($area_filter_options as $area_label): ?>
                            <option value="<?php echo htmlspecialchars($area_label); ?>"><?php echo htmlspecialchars($area_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="directory-sort">
                    <label for="storeSort">Sort</label>
                    <select id="storeSort">
                        <option value="featured">Featured</option>
                        <option value="rating">Rating</option>
                        <option value="items">Most items</option>
                        <option value="name">Name</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Area Jump Pills (Matching Landing Page style) -->
        <div class="directory-jumps" id="directoryJumps">
            <?php foreach ($quick_jump_terms as $label => $term): ?>
                <button
                    type="button"
                    class="jump-chip<?php echo $term === '' ? ' active' : ''; ?>"
                    data-jump-search="<?php echo htmlspecialchars($term); ?>"
                >
                    <?php echo htmlspecialchars($label); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="nearest-store-notice" id="nearestStoreNotice" style="display:none;"></div>
        <div class="empty-state" id="emptyState" style="display:none;">
            <i class="fas fa-store-slash"></i>
            <h3>No matching stores</h3>
            <p>Try searching for a different branch, city, or area.</p>
        </div>

        <!-- Modern Compact Store Cards Grid (Matching Landing Page) -->
        <div class="restaurant-grid" id="restaurantGrid">
            <?php foreach ($stores as $store): ?>
                <?php
                $clean_store_items = locationsSanitizeItems($store['items'] ?? []);
                $clean_summary = implode(' · ', array_slice($clean_store_items, 0, 3));
                $city_label = !empty($store['city']) ? $store['city'] . ', Cavite' : $store['location'];
                $is_open = ($store['status_class'] ?? '') === 'open';
                $price_display = !empty($store['start']) ? 'From ₱' . number_format((float)$store['start'], 2) : 'Menu Ready';
                ?>
                <article class="restaurant-card"
                    data-store-name="<?php echo htmlspecialchars((string)$store['name']); ?>"
                    data-store-search="<?php echo htmlspecialchars((string)$store['search']); ?>"
                    data-type="<?php echo htmlspecialchars((string)$store['type']); ?>"
                    data-live="<?php echo !empty($store['live']) ? '1' : '0'; ?>"
                    data-status="<?php echo htmlspecialchars((string)($store['status_class'] ?? 'closed')); ?>"
                    data-city="<?php echo htmlspecialchars((string)($store['city'] ?? '')); ?>"
                    data-province="<?php echo htmlspecialchars((string)($store['province'] ?? '')); ?>"
                    data-area="<?php echo htmlspecialchars((string)trim(((string)($store['city'] ?? '')) . ' ' . ((string)($store['province'] ?? '')))); ?>"
                    data-rating="<?php echo htmlspecialchars(number_format((float)($store['rating'] ?? 0), 3, '.', '')); ?>"
                    data-count="<?php echo (int)($store['count'] ?? 0); ?>"
                    data-newest="<?php echo (int)($store['sort_newest'] ?? 0); ?>">
                    
                    <a href="<?php echo htmlspecialchars((string)$store['menu_link']); ?>" class="restaurant-card-link">
                        <!-- Image Wrap -->
                        <div class="restaurant-media">
                            <img src="<?php echo htmlspecialchars((string)$store['image']); ?>" alt="<?php echo htmlspecialchars((string)$store['name']); ?>" loading="lazy">
                            <span class="market-type-pill"><?php echo htmlspecialchars((string)$store['type_label']); ?></span>
                            <span class="market-time-pill <?php echo $is_open ? 'pill-open' : 'pill-closed'; ?>">
                                <i class="fas <?php echo $is_open ? 'fa-circle-check' : 'fa-clock'; ?>"></i>
                                <?php echo $is_open ? 'Open now' : 'Closed'; ?>
                            </span>
                            <span class="restaurant-pill-rating">
                                <i class="fas fa-star" style="color:#ef6b2e;"></i><?php echo (float)($store['rating'] ?? 0) > 0 ? number_format((float)$store['rating'], 1) : 'New'; ?>
                                <?php if ((int)($store['reviews'] ?? 0) > 0): ?><small style="font-weight:normal; color:#64748b;">(<?php echo (int)$store['reviews']; ?>)</small><?php endif; ?>
                            </span>
                        </div>

                        <!-- Card Body -->
                        <div class="restaurant-body">
                            <div class="restaurant-topline">
                                <h3><?php echo htmlspecialchars((string)$store['name']); ?></h3>
                            </div>
                            
                            <p class="restaurant-location">
                                <i class="fas fa-location-dot"></i><span><?php echo htmlspecialchars((string)$store['location']); ?></span>
                            </p>
                            
                            <p class="restaurant-summary"><?php echo htmlspecialchars($clean_summary); ?></p>

                            <div class="panda-card-dss-info">
                                <span class="dss-badge dss-eta"><i class="fas fa-truck-fast"></i> 20-30 min</span>
                                <span class="dss-badge dss-items"><i class="fas fa-bowl-food"></i> <?php echo number_format((int)($store['count'] ?? 0)); ?> items</span>
                            </div>

                            <div class="panda-card-footer-line">
                                <span class="panda-card-city"><i class="fas fa-store"></i> <?php echo htmlspecialchars($city_label); ?></span>
                                <strong class="panda-card-price-text"><?php echo htmlspecialchars($price_display); ?></strong>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('storeSearch');
    const cards = Array.from(document.querySelectorAll('.restaurant-card'));
    const filterChips = Array.from(document.querySelectorAll('.filter-chip'));
    const jumpChips = Array.from(document.querySelectorAll('.jump-chip'));
    const sortSelect = document.getElementById('storeSort');
    const areaSelect = document.getElementById('storeAreaFilter');
    const visibleStores = document.getElementById('visibleStores');
    const emptyState = document.getElementById('emptyState');
    const nearestNotice = document.getElementById('nearestStoreNotice');
    const urlParams = new URLSearchParams(window.location.search);
    const queryFilter = normalizeSearch(urlParams.get('filter') || '');
    const availableFilters = new Set(filterChips.map(function (chip) { return normalizeSearch(chip.getAttribute('data-filter') || ''); }));
    let activeFilter = availableFilters.has(queryFilter) ? queryFilter : 'all';
    const defaultOrder = new Map(cards.map(function (card, index) { return [card, index]; }));
    function normalizeSearch(value) { let text = String(value || '').toLowerCase().trim(); try { text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (error) {} return text.replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim(); }
    function escapeRegExp(value) { return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function escapeHtml(value) { return String(value || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function getSearchTokens(value) { return normalizeSearch(value).split(' ').filter(function (part) { return part !== ''; }); }
    function restoreHighlights(card) { card.querySelectorAll('h3, .restaurant-location span, .restaurant-summary').forEach(function (element) { if (Object.prototype.hasOwnProperty.call(element.dataset, 'originalText')) element.textContent = element.dataset.originalText; }); }
    function applyHighlights(card, term) {
        const safePattern = escapeRegExp(term); if (!safePattern) { restoreHighlights(card); return; }
        const regex = new RegExp('(' + safePattern + ')', 'gi');
        card.querySelectorAll('h3, .restaurant-location span, .restaurant-summary').forEach(function (element) {
            const originalText = element.dataset.originalText || element.textContent || '';
            element.dataset.originalText = originalText;
            element.innerHTML = escapeHtml(originalText).replace(regex, '<span class="highlight">$1</span>');
        });
    }
    function matchesFilter(card) {
        if (activeFilter === 'live') return card.getAttribute('data-live') === '1';
        if (activeFilter === 'partner') { const type = card.getAttribute('data-type'); return type === 'partner' || type === 'seller'; }
        if (activeFilter === 'branch') return card.getAttribute('data-type') === 'branch';
        if (activeFilter === 'open') return normalizeSearch(card.getAttribute('data-status') || '') === 'open';
        return true;
    }
    function matchesArea(card) {
        const areaTerm = normalizeSearch(areaSelect ? areaSelect.value : '');
        if (areaTerm === '') return true;
        const areaHaystack = normalizeSearch((card.getAttribute('data-area') || '') + ' ' + (card.getAttribute('data-city') || '') + ' ' + (card.getAttribute('data-province') || ''));
        return areaHaystack.includes(areaTerm);
    }
    function sortCards() {
        const grid = document.getElementById('restaurantGrid');
        if (!grid) return;
        const mode = sortSelect ? (sortSelect.value || 'featured') : 'featured';
        const sorted = cards.slice().sort(function (a, b) {
            const nameA = normalizeSearch(a.getAttribute('data-store-name') || '');
            const nameB = normalizeSearch(b.getAttribute('data-store-name') || '');
            const ratingA = parseFloat(a.getAttribute('data-rating') || '0');
            const ratingB = parseFloat(b.getAttribute('data-rating') || '0');
            const countA = parseInt(a.getAttribute('data-count') || '0', 10);
            const countB = parseInt(b.getAttribute('data-count') || '0', 10);
            const newestA = parseInt(a.getAttribute('data-newest') || '0', 10);
            const newestB = parseInt(b.getAttribute('data-newest') || '0', 10);
            if (mode === 'rating' || mode === 'top_rated') return (ratingB - ratingA) || (countB - countA) || nameA.localeCompare(nameB);
            if (mode === 'items') return (countB - countA) || (ratingB - ratingA) || nameA.localeCompare(nameB);
            if (mode === 'newest') return (newestB - newestA) || (ratingB - ratingA) || nameA.localeCompare(nameB);
            if (mode === 'name') return nameA.localeCompare(nameB);
            return (defaultOrder.get(a) ?? 0) - (defaultOrder.get(b) ?? 0);
        });
        sorted.forEach(function (card) { grid.appendChild(card); });
    }
    function runFiltering() {
        sortCards();
        const term = normalizeSearch(searchInput ? searchInput.value : '');
        const termTokens = getSearchTokens(term);
        let visible = 0;
        cards.forEach(function (card) {
            const haystack = normalizeSearch(card.getAttribute('data-store-search') || '');
            const matchesSearch = termTokens.length === 0 || termTokens.every(function (token) { return haystack.includes(token); });
            const matches = matchesSearch && matchesFilter(card) && matchesArea(card);
            if (matches) { card.style.display = ''; visible++; if (term !== '') applyHighlights(card, term); else restoreHighlights(card); }
            else { card.style.display = 'none'; restoreHighlights(card); }
        });
        if (visibleStores) visibleStores.textContent = String(visible);
        if (emptyState) emptyState.style.display = visible === 0 ? 'block' : 'none';
        jumpChips.forEach(function (chip) {
            const chipSearch = normalizeSearch(chip.getAttribute('data-jump-search') || '');
            chip.classList.toggle('active', chipSearch === term);
        });
    }
    function clearNearestSelection() { cards.forEach(function (card) { card.classList.remove('nearest-selected'); }); }
    function applyNearestStoreFromQuery() {
        const nearest = normalizeSearch(urlParams.get('nearest') || ''); if (!nearest) return; clearNearestSelection(); let targetCard = null;
        cards.forEach(function (card) { const name = normalizeSearch(card.getAttribute('data-store-name') || ''); if (!targetCard && (name.includes(nearest) || nearest.includes(name))) targetCard = card; });
        if (!targetCard) return; targetCard.classList.add('nearest-selected');
        if (nearestNotice) { nearestNotice.style.display = 'block'; nearestNotice.innerHTML = '<i class="fas fa-location-arrow"></i> Highlighting nearest match: <strong>' + escapeHtml(targetCard.getAttribute('data-store-name') || 'Store') + '</strong>'; }
        setTimeout(function () { targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 180);
    }
    filterChips.forEach(function (item) {
        const itemFilter = normalizeSearch(item.getAttribute('data-filter') || 'all');
        item.classList.toggle('active', itemFilter === activeFilter);
    });
    filterChips.forEach(function (chip) { chip.addEventListener('click', function () { activeFilter = normalizeSearch(chip.getAttribute('data-filter') || 'all'); filterChips.forEach(function (item) { item.classList.remove('active'); }); chip.classList.add('active'); runFiltering(); }); });
    jumpChips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            if (!searchInput) return;
            searchInput.value = chip.getAttribute('data-jump-search') || '';
            runFiltering();
            const grid = document.getElementById('restaurantGrid');
            if (grid) {
                grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
    if (searchInput) {
        const querySearch = (urlParams.get('search') || '').trim(); if (querySearch !== '') searchInput.value = querySearch;
        searchInput.addEventListener('input', runFiltering);
        searchInput.addEventListener('keypress', function (event) { if (event.key === 'Enter') runFiltering(); });
    }
    if (sortSelect) {
        const querySort = (urlParams.get('sort') || '').trim();
        if (querySort !== '') sortSelect.value = querySort;
        sortSelect.addEventListener('change', runFiltering);
    }
    if (areaSelect) {
        const queryArea = (urlParams.get('area') || '').trim();
        if (queryArea !== '') {
            const normalizedQueryArea = normalizeSearch(queryArea);
            const targetOption = Array.from(areaSelect.options).find(function (option) {
                return normalizeSearch(option.value || option.textContent || '') === normalizedQueryArea;
            });
            if (targetOption) {
                areaSelect.value = targetOption.value;
            }
        }
        areaSelect.addEventListener('change', runFiltering);
    }
    runFiltering(); applyNearestStoreFromQuery();
});
</script>
