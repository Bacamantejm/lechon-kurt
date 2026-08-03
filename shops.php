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

        $shops[] = [
            'key' => 'branch_' . $store_id,
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
            'image' => 'images/store-bg.jpg',
            'menu_link' => 'menu.php?branch_id=' . $store_id,
            'tags' => ['branch', strtolower($row['store_name']), strtolower($city_label)]
        ];
    }
    @mysqli_free_result($branch_res);
}

// Fetch approved organization partner sellers from users & franchise_applications
$latest_approved_partner_sql = "SELECT fa1.* FROM franchise_applications fa1 INNER JOIN (SELECT user_id, MAX(id) AS latest_id FROM franchise_applications WHERE status = 'approved' GROUP BY user_id) latest ON latest.latest_id = fa1.id";
$seller_sql = "SELECT u.id, u.full_name, u.email, u.phone, u.address, u.business_name, u.business_type, u.business_logo, u.profile_image, fa.business_address, fa.city_name
              FROM users u
              INNER JOIN ({$latest_approved_partner_sql}) fa ON fa.user_id = u.id
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

        $shops[] = [
            'key' => 'seller_' . $seller_id,
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
        
        $spotlight_products[] = [
            'id' => (int)$p['id'],
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
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)) !important;
    gap: 18px !important;
}

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
}

.market-store-row:hover {
    transform: translateY(-4px) !important;
    box-shadow: 0 12px 28px rgba(74, 32, 20, 0.12) !important;
    border-color: #ebd7c5 !important;
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
    background-size: cover !important;
    background-position: center !important;
    transition: transform 0.3s ease !important;
}

.market-store-row:hover .market-store-row-thumb {
    transform: scale(1.04) !important;
}

.market-type-pill {
    position: absolute !important;
    left: 10px !important;
    top: 10px !important;
    z-index: 5 !important;
    background: rgba(42, 33, 29, 0.88) !important;
    color: #ffffff !important;
    font-size: 0.68rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    font-weight: 700 !important;
}

.market-time-pill {
    position: absolute !important;
    left: 10px !important;
    bottom: 10px !important;
    z-index: 5 !important;
    background: rgba(255, 255, 255, 0.94) !important;
    color: #b3261e !important;
    font-size: 0.68rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    font-weight: 800 !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1) !important;
}

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
    transform: scale(1.1) !important;
}

.swimlane-nav-btn {
    transition: transform 0.2s ease, background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease !important;
}

.swimlane-nav-btn:hover {
    background: #171922 !important;
    color: #ffffff !important;
    border-color: #171922 !important;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25) !important;
    transform: translateY(-50%) scale(1.08) !important;
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

.store-card-rating {
    font-size: 0.85rem !important;
    font-weight: 700 !important;
    color: #171922 !important;
    display: flex !important;
    align-items: center !important;
    white-space: nowrap !important;
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
    margin-top: 6px !important;
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

@media (max-width: 991px) {
    .market-explorer {
        grid-template-columns: 1fr !important;
    }
    .market-sidebar {
        position: static !important;
    }
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
                    
                    <!-- Featured Shop Spotlight Card (Real DB Store Spotlight) -->
                    <div style="background:#fff4f6; border:1px solid #fce4e8; border-radius:20px; padding:24px; margin-bottom:32px; box-shadow:0 6px 20px rgba(179,38,30,0.04);">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
                            <div style="display:flex; align-items:center; gap:14px;">
                                <div style="width:54px; height:54px; border-radius:14px; background:url('<?php echo htmlspecialchars($featured_shop['image'] ?? 'images/store-bg.jpg'); ?>') center/cover; border:2px solid #ffffff; box-shadow:0 4px 12px rgba(0,0,0,0.08); flex-shrink:0;"></div>
                                <div>
                                    <h2 style="font-size:1.25rem; font-weight:800; color:#171922; margin:0 0 2px;">
                                        <?php echo htmlspecialchars($featured_shop['name']); ?> <span style="font-size:0.82rem; font-weight:600; color:#64748b; margin-left:6px;">(<?php echo htmlspecialchars($featured_shop['location']); ?>)</span>
                                    </h2>
                                    <p style="font-size:0.86rem; color:#667085; margin:0;">
                                        From 30-45 min <span style="margin:0 4px; color:#cbd5e1;">•</span> Delivery PHP 49.00
                                    </p>
                                </div>
                            </div>
                            <a href="<?php echo htmlspecialchars($featured_shop['menu_link']); ?>" style="background:#ffffff; color:#171922; border:1px solid #171922; padding:9px 20px; border-radius:12px; font-weight:700; font-size:0.88rem; text-decoration:none; transition:all 0.2s ease;" onmouseover="this.style.background='#171922'; this.style.color='#fff';" onmouseout="this.style.background='#fff'; this.style.color='#171922';">
                                View shop
                            </a>
                        </div>

                        <!-- Horizontal Product Swimlane Carousel (Real Products from DB) -->
                        <?php if (!empty($spotlight_products)): ?>
                        <div style="position:relative;">
                            <!-- Left Arrow Button -->
                            <button type="button" class="swimlane-nav-btn" onclick="document.getElementById('swimlaneContainer').scrollBy({left: -360, behavior: 'smooth'});" style="position:absolute; left:-14px; top:50%; transform:translateY(-50%); width:38px; height:38px; border-radius:50%; background:#ffffff; border:1px solid #cbd5e1; color:#171922; box-shadow:0 4px 14px rgba(0,0,0,0.15); display:inline-flex; align-items:center; justify-content:center; cursor:pointer; z-index:5;" title="Scroll left">
                                <i class="fas fa-chevron-left"></i>
                            </button>

                            <div class="panda-swimlane" id="swimlaneContainer" style="display:flex; gap:14px; overflow-x:auto; padding-bottom:8px; scroll-behavior:smooth; scrollbar-width:none;">
                                <?php foreach ($spotlight_products as $p): ?>
                                    <div class="swimlane-item-card" data-cat="<?php echo htmlspecialchars($p['cat_key']); ?>" data-price="<?php echo (float)$p['price']; ?>" style="flex:0 0 170px; background:#ffffff; border:1px solid #efddcd; border-radius:16px; padding:12px; position:relative; box-shadow:0 4px 14px rgba(15,23,42,0.03); display:flex; flex-direction:column; justify-content:space-between;">
                                        <!-- Product Image + Floating (+) Add Button -->
                                        <div style="position:relative; width:100%; height:110px; border-radius:12px; overflow:hidden; background:url('<?php echo htmlspecialchars($p['image']); ?>') center/cover; margin-bottom:10px;">
                                            <button type="button" class="swimlane-add-btn" style="position:absolute; bottom:6px; right:6px; width:30px; height:30px; border-radius:50%; background:#ffffff; border:1px solid #cbd5e1; color:#171922; font-size:0.95rem; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 3px 8px rgba(0,0,0,0.12); transition:all 0.2s ease;" onclick="event.preventDefault(); event.stopPropagation(); this.style.transform='scale(1.1)';">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>

                                        <!-- Price Tag + Discount Badge -->
                                        <div>
                                            <div style="display:flex; align-items:center; gap:6px; margin-bottom:3px;">
                                                <strong style="font-size:0.95rem; font-weight:800; color:#b3261e;">PHP <?php echo number_format($p['price'], 2); ?></strong>
                                            </div>
                                            <span style="display:inline-block; background:#fff0f3; color:#b3261e; font-size:0.7rem; font-weight:800; padding:2px 6px; border-radius:6px; margin-bottom:6px;">
                                                <?php echo htmlspecialchars($p['category']); ?>
                                            </span>
                                            <h4 style="font-size:0.82rem; font-weight:700; color:#171922; margin:0; line-height:1.35; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                                                <?php echo htmlspecialchars($p['name']); ?>
                                            </h4>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Right Arrow Button -->
                            <button type="button" class="swimlane-nav-btn" onclick="document.getElementById('swimlaneContainer').scrollBy({left: 360, behavior: 'smooth'});" style="position:absolute; right:-14px; top:50%; transform:translateY(-50%); width:38px; height:38px; border-radius:50%; background:#ffffff; border:1px solid #cbd5e1; color:#171922; box-shadow:0 4px 14px rgba(0,0,0,0.15); display:inline-flex; align-items:center; justify-content:center; cursor:pointer; z-index:5;" title="Scroll right">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Shop by Store Header -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h2 style="font-size:1.5rem; font-weight:800; color:#171922; margin:0;">Shop by store</h2>
                        <span style="font-size:0.88rem; color:#667085;" id="shopsCountLabel">Showing <?php echo count($shops); ?> shops</span>
                    </div>

                    <!-- 3-Column Store Grid Layout (Real DB Store Cards) -->
                    <div class="market-store-list store-list-grid" id="shopsGrid">
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
                               data-cat="<?php echo htmlspecialchars($shop['cat_key']); ?>"
                               data-vouchers="<?php echo !empty($shop['has_vouchers']) ? '1' : '0'; ?>"
                               data-price="<?php echo (float)$shop['start_price']; ?>"
                               data-rating="<?php echo (float)$shop['rating']; ?>"
                               data-search="<?php echo htmlspecialchars(strtolower($shop['name'] . ' ' . $shop['summary'] . ' ' . implode(' ', $shop['tags']))); ?>">
                                
                                <!-- Image Wrapper -->
                                <div class="store-card-image-wrap">
                                    <div class="market-store-row-thumb" style="background-image:url('<?php echo htmlspecialchars($shop['image']); ?>');"></div>
                                    <span class="market-type-pill"><?php echo htmlspecialchars($shop['type']); ?></span>
                                    <span class="market-time-pill"><?php echo !empty($shop['is_open']) ? 'Open now' : 'Closed now'; ?></span>
                                    <button
                                        type="button"
                                        class="market-store-favorite-btn<?php echo $is_shop_favorite ? ' is-active' : ''; ?>"
                                        data-favorite-toggle="1"
                                        data-favorite-type="store"
                                        data-favorite-store-key="<?php echo htmlspecialchars($shop_key_value); ?>"
                                        data-favorite-active="<?php echo $is_shop_favorite ? '1' : '0'; ?>"
                                        aria-pressed="<?php echo $is_shop_favorite ? 'true' : 'false'; ?>"
                                        title="<?php echo $is_shop_favorite ? 'Remove from favorites' : 'Save to favorites'; ?>"
                                        onclick="event.preventDefault(); event.stopPropagation();">
                                        <i class="<?php echo $is_shop_favorite ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </div>

                                <!-- Details Container -->
                                <div class="store-card-details">
                                    <div class="store-card-row-head">
                                        <h3><?php echo htmlspecialchars($shop['name']); ?></h3>
                                        <span class="store-card-rating">
                                            <i class="fas fa-star" style="color:#ef6b2e; margin-right:3px;"></i><?php echo number_format((float)$shop['rating'], 1); ?>
                                            <span class="store-card-reviews" style="font-size:0.75rem; color:#64748b; font-weight:normal;">(<?php echo (int)$shop['reviews']; ?>)</span>
                                        </span>
                                    </div>
                                    
                                    <div class="store-card-summary">
                                        <?php echo htmlspecialchars($shop['summary']); ?>
                                    </div>
                                    
                                    <div class="panda-card-footer-line">
                                        <span class="panda-card-city"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($city_label); ?></span>
                                        <strong class="panda-card-price-text"><?php echo htmlspecialchars($price_text); ?></strong>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
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

    const applyShopFilters = function () {
        const query = (headerSearch ? headerSearch.value : '').toLowerCase().trim();
        const vouchersOnly = filterVouchers ? filterVouchers.checked : false;

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

        let visibleCount = 0;
        const visibleCardsArray = [];

        shopCards.forEach(function (card) {
            const cardCat = card.dataset.cat || '';
            const cardVouchers = card.dataset.vouchers === '1';
            const cardPrice = parseFloat(card.dataset.price || '0');
            const cardSearch = card.dataset.search || '';

            // Check filters
            const matchesVouchers = !vouchersOnly || cardVouchers;
            const matchesTypes = selectedTypes.length === 0 || selectedTypes.includes(cardCat);
            const matchesQuery = !query || cardSearch.includes(query);

            let matchesPrice = true;
            if (selectedPrice === 'under_300') matchesPrice = cardPrice < 300;
            else if (selectedPrice === '300_1000') matchesPrice = cardPrice >= 300 && cardPrice <= 1000;
            else if (selectedPrice === 'above_1000') matchesPrice = cardPrice > 1000;

            if (matchesVouchers && matchesTypes && matchesQuery && matchesPrice) {
                card.style.display = '';
                visibleCount++;
                visibleCardsArray.push(card);
            } else {
                card.style.display = 'none';
            }
        });

        // Handle Swimlane Filter
        swimlaneCards.forEach(function (scard) {
            const scat = scard.dataset.cat || '';
            const matches = selectedTypes.length === 0 || selectedTypes.includes(scat);
            scard.style.display = matches ? 'flex' : 'none';
        });

        // Sorting Logic
        if (selectedSort !== 'relevance' && visibleCardsArray.length > 1) {
            visibleCardsArray.sort(function (a, b) {
                if (selectedSort === 'top_rated') {
                    return parseFloat(b.dataset.rating || '0') - parseFloat(a.dataset.rating || '0');
                }
                return 0;
            });
            visibleCardsArray.forEach(function (card) {
                shopsGrid.appendChild(card);
            });
        }

        if (zeroState) {
            zeroState.style.display = visibleCount === 0 ? 'block' : 'none';
        }

        if (countLabel) {
            countLabel.textContent = 'Showing ' + visibleCount + ' shops';
        }
    };

    if (filterVouchers) filterVouchers.addEventListener('change', applyShopFilters);
    shopTypeChecks.forEach(chk => chk.addEventListener('change', applyShopFilters));
    sortRadios.forEach(r => r.addEventListener('change', applyShopFilters));
    priceRadios.forEach(r => r.addEventListener('change', applyShopFilters));
    if (headerSearch) headerSearch.addEventListener('input', applyShopFilters);
});
</script>

<?php require_once 'includes/footer.php'; ?>
