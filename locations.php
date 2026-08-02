
<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$current_page = 'locations';
$page_title = 'Locations | Browse Shops and Store Menus';
require_once 'includes/config.php';
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
    return [
        ['id'=>1004,'name'=>'General Trias, Cavite Branch','address'=>"9002 Governor's Drive, Manggahan",'city'=>'General Trias','province'=>'Cavite','phone'=>'0917-887-2168','email'=>'gentrias@lechondelights.com','hours'=>'8:00 AM - 8:00 PM','latitude'=>null,'longitude'=>null],
    ];
}

$branches = [];
$branch_by_email = [];
$branch_by_name = [];
$branch_by_owner = [];
sahEnsureStoreLocationAvailabilitySchema($conn);
$branch_result = mysqli_query($conn, "SELECT store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, is_active, latitude, longitude FROM store_locations WHERE is_active = 1 ORDER BY store_name");
if ($branch_result) {
    while ($row = mysqli_fetch_assoc($branch_result)) {
        if (!locationsIsCavite((string)($row['address'] ?? ''), (string)($row['city'] ?? ''), (string)($row['province'] ?? ''))) {
            continue;
        }
        $branch = [
            'id'=>(int)$row['store_id'],'owner_user_id'=>(int)($row['owner_user_id'] ?? 0),'name'=>trim((string)$row['store_name']),'address'=>trim((string)$row['address']),'city'=>trim((string)$row['city']),'province'=>trim((string)$row['province']),
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

$stores = [];
$latest_approved_partner_sql = "SELECT fa1.* FROM franchise_applications fa1 INNER JOIN (SELECT user_id, MAX(id) AS latest_id FROM franchise_applications WHERE status = 'approved' GROUP BY user_id) latest ON latest.latest_id = fa1.id";
$partner_sql = "SELECT u.id, u.full_name, u.email, u.phone, u.address, u.business_name, u.business_type, u.created_at,
                       fa.business_address, fa.city_name, fa.province_name, fa.barangay_name
                FROM users u
                INNER JOIN ({$latest_approved_partner_sql}) fa ON fa.user_id = u.id
                WHERE u.account_type = 'organization' AND u.is_active = 1
                ORDER BY COALESCE(NULLIF(TRIM(u.business_name), ''), u.full_name)";
$partner_result = mysqli_query($conn, $partner_sql);
if ($partner_result) {
    while ($row = mysqli_fetch_assoc($partner_result)) {
        $seller_id = (int)$row['id'];
        $store_name = trim((string)$row['business_name']) !== '' ? trim((string)$row['business_name']) : trim((string)$row['full_name']) . ' Store';
        $email = strtolower(trim((string)$row['email']));
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
$global_items = array_slice(array_values(array_unique(array_filter(array_map(static function ($row) { return trim((string)($row['name'] ?? '')); }, $global_products)))), 0, 3);
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
    $store['summary'] = !empty($store['items']) ? implode(' | ', $store['items']) : 'Open this shop to view their latest menu and available items.';
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
<section class="locations-hero">
    <div class="container">
        <div class="locations-hero-copy">
            <span class="locations-kicker"><i class="fas fa-store"></i> Cavite Shop Directory</span>
            <h1>All Cavite restaurants and partner shops in one place</h1>
            <p>Browse every available Cavite store, partner shop, and pickup branch from one clean directory. Instead of directions or phone calls, each card now takes customers straight to that shop's menu.</p>
        </div>
        <div class="locations-hero-stats">
            <div class="hero-stat"><strong><?php echo number_format($total_store_count); ?></strong><span>Visible stores</span></div>
            <div class="hero-stat"><strong><?php echo number_format($live_store_count); ?></strong><span>Live menus</span></div>
            <div class="hero-stat"><strong><?php echo number_format($partner_store_count); ?></strong><span>Partner shops</span></div>
            <div class="hero-stat"><strong><?php echo number_format(max($city_count, $branch_store_count)); ?></strong><span>Cavite areas covered</span></div>
        </div>
    </div>
</section>
<section class="locations-directory">
    <div class="container">
        <div class="directory-toolbar">
            <div class="directory-search"><i class="fas fa-search"></i><input type="text" id="storeSearch" placeholder="Search shops, places, branches, or menu tags..."></div>
            <div class="directory-filters">
                <button type="button" class="filter-chip active" data-filter="all">All</button>
                <button type="button" class="filter-chip" data-filter="live">Live Menus</button>
                <button type="button" class="filter-chip" data-filter="partner">Partner Shops</button>
                <button type="button" class="filter-chip" data-filter="branch">Branches</button>
                <button type="button" class="filter-chip" data-filter="open">Open Now</button>
            </div>
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
                <label for="storeSort">Sort by</label>
                <select id="storeSort">
                    <option value="featured">Featured</option>
                    <option value="rating">Rating</option>
                    <option value="top_rated">Top rated</option>
                    <option value="items">Most items</option>
                    <option value="newest">Newest</option>
                    <option value="name">Name</option>
                </select>
            </div>
        </div>
        <div class="directory-heading">
            <div>
                <p class="directory-label">Cavite marketplace layout</p>
                <h2>All Cavite restaurants</h2>
                <p class="directory-subtitle">Card-based browsing for Cavite-only stores, wired to each shop's actual menu route.</p>
            </div>
            <div class="directory-count"><span id="visibleStores"><?php echo number_format($total_store_count); ?></span><small>showing now</small></div>
        </div>
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
        <div class="empty-state" id="emptyState" style="display:none;"><i class="fas fa-store-slash"></i><h3>No matching stores</h3><p>Try a different shop name, city, branch, or filter.</p></div>
        <div class="restaurant-grid" id="restaurantGrid">
            <?php foreach ($stores as $store): ?>
                <article class="restaurant-card" data-store-name="<?php echo htmlspecialchars((string)$store['name']); ?>" data-store-search="<?php echo htmlspecialchars((string)$store['search']); ?>" data-type="<?php echo htmlspecialchars((string)$store['type']); ?>" data-live="<?php echo !empty($store['live']) ? '1' : '0'; ?>" data-status="<?php echo htmlspecialchars((string)($store['status_class'] ?? 'closed')); ?>" data-city="<?php echo htmlspecialchars((string)($store['city'] ?? '')); ?>" data-province="<?php echo htmlspecialchars((string)($store['province'] ?? '')); ?>" data-area="<?php echo htmlspecialchars((string)trim(((string)($store['city'] ?? '')) . ' ' . ((string)($store['province'] ?? '')))); ?>" data-rating="<?php echo htmlspecialchars(number_format((float)($store['rating'] ?? 0), 3, '.', '')); ?>" data-count="<?php echo (int)($store['count'] ?? 0); ?>" data-newest="<?php echo (int)($store['sort_newest'] ?? 0); ?>">
                    <a href="<?php echo htmlspecialchars((string)$store['menu_link']); ?>" class="restaurant-card-link">
                        <div class="restaurant-media">
                            <img src="<?php echo htmlspecialchars((string)$store['image']); ?>" alt="<?php echo htmlspecialchars((string)$store['name']); ?>" loading="lazy">
                            <div class="restaurant-media-overlay"></div>
                            <div class="restaurant-brand-badge"><?php echo htmlspecialchars((string)$store['brand_initials']); ?></div>
                            <span class="restaurant-pill restaurant-pill-rating"><i class="fas fa-star"></i><?php echo number_format((float)($store['rating'] ?? 0), 1); ?></span>
                            <span class="restaurant-pill restaurant-pill-status restaurant-pill-status-<?php echo htmlspecialchars((string)$store['status_class']); ?>"><?php echo htmlspecialchars((string)$store['status_label']); ?></span>
                            <div class="restaurant-hover-panel">
                                <div class="restaurant-hover-copy">
                                    <strong><?php echo htmlspecialchars((string)$store['name']); ?></strong>
                                    <span><?php echo htmlspecialchars((string)$store['type_label']); ?> | <?php echo number_format((int)($store['count'] ?? 0)); ?> items</span>
                                </div>
                                <span class="restaurant-hover-cta">Browse menu</span>
                            </div>
                        </div>
                        <div class="restaurant-body">
                            <div class="restaurant-topline"><h3><?php echo htmlspecialchars((string)$store['name']); ?></h3><span class="restaurant-type"><?php echo htmlspecialchars((string)$store['type_label']); ?></span></div>
                            <p class="restaurant-location"><i class="fas fa-location-dot"></i><span><?php echo htmlspecialchars((string)$store['location']); ?></span></p>
                            <p class="restaurant-summary"><?php echo htmlspecialchars((string)$store['summary']); ?></p>
                            <div class="restaurant-tags"><?php foreach (array_slice((array)$store['tags'], 0, 3) as $tag): ?><span><?php echo htmlspecialchars((string)$tag); ?></span><?php endforeach; ?></div>
                            <div class="restaurant-meta-row">
                                <span><i class="fas fa-bowl-food"></i> <?php echo number_format((int)($store['count'] ?? 0)); ?> items</span>
                                <?php if (!empty($store['start'])): ?><span><i class="fas fa-tag"></i> From P<?php echo number_format((float)$store['start'], 2); ?></span><?php endif; ?>
                                <span><i class="fas fa-comment-dots"></i> <?php echo number_format((int)($store['reviews'] ?? 0)); ?> reviews</span>
                                <?php if (!empty($store['schedule_summary'])): ?><span><i class="fas fa-clock"></i> <?php echo htmlspecialchars((string)$store['schedule_summary']); ?></span><?php endif; ?>
                            </div>
                            <div class="restaurant-footer"><div class="restaurant-note"><?php echo htmlspecialchars((string)$store['note']); ?></div><span class="restaurant-cta">Open Menu <i class="fas fa-arrow-right"></i></span></div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php include 'includes/footer.php'; ?>
<style>
:root{--loc-red:#b3261e;--loc-orange:#ef6b2e;--loc-ink:#241b18;--loc-muted:#74645c;--loc-line:#eedbca;--loc-shadow:0 18px 36px rgba(74,32,20,.12);}body{background:radial-gradient(circle at 6% 8%,rgba(239,107,46,.14),transparent 32%),radial-gradient(circle at 92% 14%,rgba(179,38,30,.12),transparent 28%),linear-gradient(180deg,#fffaf4 0%,#fff 42%,#fff7f0 100%);} .locations-hero{padding:128px 0 44px;} .locations-hero .container,.locations-directory .container{max-width:1240px;} .locations-hero .container{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,420px);gap:26px;align-items:stretch;} .locations-hero-copy,.locations-hero-stats{background:rgba(255,255,255,.88);border:1px solid var(--loc-line);border-radius:30px;box-shadow:var(--loc-shadow);backdrop-filter:blur(10px);} .locations-hero-copy{padding:34px;} .locations-kicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:16px;padding:8px 14px;border-radius:999px;background:#ffe8d2;color:var(--loc-red);font-size:.82rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;} .locations-hero h1{margin:0 0 14px;font-size:clamp(2.3rem,4vw,4rem);line-height:1.02;letter-spacing:-.05em;color:var(--loc-ink);} .locations-hero p{margin:0;font-size:1.02rem;line-height:1.8;color:var(--loc-muted);} .locations-hero-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:18px;} .hero-stat{display:grid;gap:4px;padding:18px;border-radius:22px;background:radial-gradient(circle at top right,rgba(239,107,46,.12),transparent 44%),linear-gradient(180deg,#fffefc 0%,#fff5eb 100%);border:1px solid #f3dfcf;} .hero-stat strong{font-size:1.75rem;color:var(--loc-ink);line-height:1;} .hero-stat span{color:var(--loc-muted);font-weight:600;} .locations-directory{padding:0 0 72px;} .directory-toolbar{display:flex;gap:16px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:18px;padding:16px;border-radius:24px;border:1px solid var(--loc-line);background:rgba(255,255,255,.94);box-shadow:0 14px 30px rgba(74,32,20,.08);} .directory-search{flex:1 1 320px;display:flex;align-items:center;gap:12px;min-height:54px;padding:0 18px;border-radius:18px;background:#fff8f0;border:1px solid transparent;} .directory-search:focus-within{border-color:#e7c2a5;background:#fff;} .directory-search i{color:#9a563a;} .directory-search input{width:100%;border:none;outline:none;background:transparent;color:var(--loc-ink);} .directory-filters,.directory-jumps{display:flex;gap:10px;flex-wrap:wrap;} .directory-sort{display:flex;align-items:center;gap:10px;padding:8px 10px 8px 14px;border-radius:18px;border:1px solid #ecd6c4;background:#fff9f3;} .directory-sort label{font-size:.82rem;font-weight:800;color:#8a4a31;text-transform:uppercase;letter-spacing:.06em;} .directory-sort select{min-height:38px;padding:0 34px 0 12px;border:1px solid #e5c8b2;border-radius:12px;background:#fff;color:var(--loc-ink);font-weight:700;outline:none;} .filter-chip,.jump-chip{min-height:40px;padding:0 15px;border-radius:999px;border:1px solid #e8cfbb;background:#fff7ef;color:#864230;font-weight:700;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,background-color .18s ease,color .18s ease,border-color .18s ease;} .filter-chip:hover,.filter-chip.active,.jump-chip:hover,.jump-chip.active{background:linear-gradient(135deg,var(--loc-red),var(--loc-orange));color:#fff;border-color:transparent;box-shadow:0 12px 24px rgba(179,38,30,.22);} .directory-heading{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:14px;} .directory-label{margin:0 0 6px;color:var(--loc-red);font-size:.86rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;} .directory-heading h2{margin:0 0 6px;color:var(--loc-ink);font-size:clamp(1.9rem,3vw,2.8rem);letter-spacing:-.04em;} .directory-subtitle{margin:0;color:var(--loc-muted);} .directory-count{min-width:132px;padding:16px 18px;text-align:center;border-radius:22px;background:#fff;border:1px solid var(--loc-line);box-shadow:0 12px 22px rgba(74,32,20,.08);} .directory-count span{display:block;font-size:1.7rem;font-weight:800;color:var(--loc-ink);} .directory-count small{color:var(--loc-muted);font-weight:600;} .directory-jumps{margin:0 0 18px;} .nearest-store-notice,.empty-state{margin-bottom:18px;border-radius:22px;border:1px solid var(--loc-line);background:#fff;box-shadow:0 12px 24px rgba(74,32,20,.08);} .nearest-store-notice{padding:14px 18px;color:#8e4a2e;font-weight:700;} .empty-state{padding:36px 24px;text-align:center;} .empty-state i{font-size:2rem;color:var(--loc-red);margin-bottom:10px;} .empty-state h3{margin:0 0 8px;color:var(--loc-ink);} .empty-state p{margin:0;color:var(--loc-muted);} .restaurant-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(214px,1fr));gap:16px;} .restaurant-card{border-radius:20px;background:#fff;border:1px solid var(--loc-line);box-shadow:0 12px 24px rgba(74,32,20,.1);overflow:hidden;transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;} .restaurant-card:hover{transform:translateY(-5px);box-shadow:0 20px 30px rgba(74,32,20,.15);border-color:#e3b99a;} .restaurant-card.nearest-selected{border-color:var(--loc-red);box-shadow:0 0 0 5px rgba(179,38,30,.08),0 20px 30px rgba(74,32,20,.15);} .restaurant-card-link{display:block;color:inherit;text-decoration:none;} .restaurant-media{position:relative;aspect-ratio:16/9;overflow:hidden;background:linear-gradient(135deg,#f9d3bc,#f6b98f);} .restaurant-media img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .28s ease,filter .28s ease;} .restaurant-card:hover .restaurant-media img{transform:scale(1.05);filter:saturate(1.08);} .restaurant-media-overlay{position:absolute;inset:auto 0 0;height:60%;background:linear-gradient(180deg,rgba(20,12,8,0) 0%,rgba(20,12,8,.45) 100%);} .restaurant-brand-badge{position:absolute;left:12px;bottom:12px;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:14px;background:rgba(255,255,255,.94);color:var(--loc-red);font-weight:900;font-size:.88rem;box-shadow:0 12px 24px rgba(20,12,8,.18);} .restaurant-pill{position:absolute;top:10px;display:inline-flex;align-items:center;gap:5px;min-height:28px;padding:0 9px;border-radius:999px;font-size:.72rem;font-weight:800;box-shadow:0 10px 20px rgba(20,12,8,.16);} .restaurant-pill-rating{right:10px;background:rgba(255,247,227,.96);color:#7f4b08;} .restaurant-pill-status{left:10px;background:rgba(255,255,255,.96);}.restaurant-pill-status-open{background:rgba(220,252,231,.96);color:#166534;}.restaurant-pill-status-away{background:rgba(254,243,199,.98);color:#92400e;}.restaurant-pill-status-closed{background:rgba(254,226,226,.98);color:#991b1b;} .restaurant-hover-panel{position:absolute;inset:auto 10px 10px 10px;display:flex;align-items:end;justify-content:space-between;gap:12px;padding:14px;border-radius:16px;background:linear-gradient(135deg,rgba(17,13,11,.84),rgba(49,23,15,.72));color:#fff;opacity:0;transform:translateY(10px);transition:opacity .22s ease,transform .22s ease;} .restaurant-card:hover .restaurant-hover-panel{opacity:1;transform:translateY(0);} .restaurant-hover-copy{display:grid;gap:4px;} .restaurant-hover-copy strong{font-size:.92rem;line-height:1.2;} .restaurant-hover-copy span{font-size:.72rem;color:rgba(255,255,255,.82);} .restaurant-hover-cta{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 10px;border-radius:999px;background:linear-gradient(135deg,var(--loc-red),var(--loc-orange));font-size:.72rem;font-weight:800;white-space:nowrap;} .restaurant-body{display:grid;gap:10px;padding:13px 13px 14px;} .restaurant-topline{display:flex;align-items:start;justify-content:space-between;gap:8px;} .restaurant-topline h3{margin:0;color:var(--loc-ink);font-size:.95rem;line-height:1.28;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;} .restaurant-type{flex:0 0 auto;padding:5px 9px;border-radius:999px;background:#fff3e6;color:#8e4025;font-size:.68rem;font-weight:700;} .restaurant-location,.restaurant-summary,.restaurant-note{margin:0;color:var(--loc-muted);} .restaurant-location{display:flex;align-items:start;gap:7px;line-height:1.45;font-size:.84rem;} .restaurant-location i{margin-top:3px;color:var(--loc-red);} .restaurant-summary{min-height:38px;font-size:.84rem;line-height:1.48;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;} .restaurant-tags{display:flex;flex-wrap:wrap;gap:6px;} .restaurant-tags span{padding:5px 8px;border-radius:999px;background:#fff8ef;border:1px solid #f0dcc8;color:#7e6053;font-size:.72rem;font-weight:700;} .restaurant-meta-row{display:flex;flex-wrap:wrap;gap:8px;font-size:.75rem;color:#866b5f;} .restaurant-meta-row span{display:inline-flex;align-items:center;gap:5px;} .restaurant-footer{display:flex;align-items:end;justify-content:space-between;gap:10px;padding-top:2px;} .restaurant-note{flex:1 1 auto;font-size:.77rem;line-height:1.42;} .restaurant-cta{flex:0 0 auto;display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:0 12px;border-radius:999px;background:linear-gradient(135deg,var(--loc-red),var(--loc-orange));color:#fff;font-size:.76rem;font-weight:800;box-shadow:0 10px 20px rgba(179,38,30,.2);} .highlight{background:#ffe08a;border-radius:4px;padding:0 2px;} @media (max-width:960px){.locations-hero .container{grid-template-columns:1fr;}} @media (max-width:768px){.locations-hero{padding-top:108px;} .locations-hero-copy,.locations-hero-stats,.directory-toolbar,.directory-count{border-radius:22px;} .locations-hero-copy{padding:26px 22px;} .locations-hero-stats{grid-template-columns:repeat(2,minmax(0,1fr));} .directory-heading{align-items:start;} .restaurant-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;} .restaurant-topline{flex-direction:column;} .restaurant-type{align-self:flex-start;} .restaurant-footer{flex-direction:column;align-items:stretch;} .restaurant-cta{justify-content:center;} .directory-sort{width:100%;justify-content:space-between;}} @media (max-width:540px){.locations-directory{padding-bottom:56px;} .directory-toolbar{padding:14px;} .directory-jumps{margin-bottom:16px;} .restaurant-grid{grid-template-columns:1fr;} .restaurant-hover-panel{display:none;}}
</style>
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
