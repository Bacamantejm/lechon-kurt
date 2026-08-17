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

// Fetch products with optional tenant/storefront scope
$products_base_sql = "SELECT id, product_id, name, description, price, image, category
                      FROM products
                      WHERE is_active = 1
                        AND (is_archived = 0 OR is_archived IS NULL)";
$all_products = [];

if ($active_seller_id > 0) {
    $products_sql = $products_base_sql . " AND seller_id = ? ORDER BY category, name ASC";
    $products_stmt = mysqli_prepare($conn, $products_sql);
    if ($products_stmt) {
        mysqli_stmt_bind_param($products_stmt, "i", $active_seller_id);
        mysqli_stmt_execute($products_stmt);
        $products_result = mysqli_stmt_get_result($products_stmt);
        if ($products_result) {
            while ($row = mysqli_fetch_assoc($products_result)) {
                $all_products[] = [
                    'id' => (int)$row['id'],
                    'product_id' => (string)($row['product_id'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'description' => (string)($row['description'] ?? ''),
                    'price' => (float)($row['price'] ?? 0),
                    'image' => (string)($row['image'] ?? 'default.jpg'),
                    'category' => (string)($row['category'] ?? 'lechon')
                ];
            }
            mysqli_free_result($products_result);
        }
        mysqli_stmt_close($products_stmt);
    }
} else {
    $products_sql = $products_base_sql . " ORDER BY category, name ASC";
    $products_result = mysqli_query($conn, $products_sql);
    if ($products_result) {
        while ($row = mysqli_fetch_assoc($products_result)) {
            $all_products[] = [
                'id' => (int)$row['id'],
                'product_id' => (string)($row['product_id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'price' => (float)($row['price'] ?? 0),
                'image' => (string)($row['image'] ?? 'default.jpg'),
                'category' => (string)($row['category'] ?? 'lechon')
            ];
        }
        mysqli_free_result($products_result);
    }
}

// Get distinct categories for filter
$categories = [];
foreach ($all_products as $p) {
    $categories[$p['category']] = true;
}
$categories = array_keys($categories);
sort($categories);

$store_scope_query = $active_seller_id > 0 ? '?seller_id=' . $active_seller_id : '';

// Fetch active store locations for pick-up
$stores = [];
$store_sql = "SELECT store_id AS id, store_id, owner_user_id, store_name, address, city, province, phone, opening_hours, opening_time, closing_time, latitude, longitude FROM store_locations WHERE is_active = 1";
if ($active_seller_id > 0) {
    $store_sql .= " AND (owner_user_id = " . (int)$active_seller_id . " OR store_id = " . (int)$active_seller_id . ")";
}
$store_sql .= " ORDER BY store_name ASC";
$store_query_res = mysqli_query($conn, $store_sql);
if ($store_query_res && mysqli_num_rows($store_query_res) > 0) {
    while ($s = mysqli_fetch_assoc($store_query_res)) {
        $stores[] = $s;
    }
}
if (empty($stores)) {
    $all_s_res = mysqli_query($conn, "SELECT store_id AS id, store_id, owner_user_id, store_name, address, city, province, phone, opening_hours, opening_time, closing_time, latitude, longitude FROM store_locations WHERE is_active = 1 ORDER BY store_name ASC");
    if ($all_s_res) {
        while ($s = mysqli_fetch_assoc($all_s_res)) {
            $stores[] = $s;
        }
    }
}

$time_slots = ['8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM'];

include 'includes/header.php';
?>

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
        <!-- Step 1: Product Selection with Dedicated Pre-Order Cart -->
        <div class="step-content active" data-step="1">
            <div class="preorder-step1-layout">
                <!-- Left Main: Product Catalog -->
                <div class="preorder-catalog-main">
                    <div class="step-title">Select Your Pre-Order Items</div>
                    <?php if ($active_seller_id > 0): ?>
                        <p class="tenant-scope-note">
                            Showing products from
                            <strong><?php echo htmlspecialchars($storefront_name !== '' ? $storefront_name : ('Partner #' . $active_seller_id)); ?></strong>
                            only.
                        </p>
                    <?php endif; ?>
                    
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
                <h4 class="preorder-block-title"><i class="fas fa-calendar-clock"></i> Scheduled Pick-up Date & Time</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="pickupDate">Pick-up Date *</label>
                        <input type="date" id="pickupDate" name="pickupDate" required min="<?php echo date('Y-m-d'); ?>">
                        <small style="color:#667085; font-size:0.78rem;">Select your event or celebration date.</small>
                    </div>
                    <div class="form-group">
                        <label for="pickupTime">Available Pick-up Time *</label>
                        <select id="pickupTime" name="pickupTime" required>
                            <option value="">-- Select Pick-up Time --</option>
                            <option value="8:00 AM">8:00 AM (Morning Batch)</option>
                            <option value="9:00 AM">9:00 AM</option>
                            <option value="10:00 AM">10:00 AM</option>
                            <option value="11:00 AM">11:00 AM (Lunch Rush)</option>
                            <option value="12:00 PM">12:00 PM (Lunch Peak)</option>
                            <option value="1:00 PM">1:00 PM</option>
                            <option value="2:00 PM">2:00 PM</option>
                            <option value="3:00 PM">3:00 PM (Afternoon Batch)</option>
                            <option value="4:00 PM">4:00 PM</option>
                            <option value="5:00 PM">5:00 PM (Dinner Rush)</option>
                            <option value="6:00 PM">6:00 PM (Dinner Peak)</option>
                            <option value="7:00 PM">7:00 PM</option>
                            <option value="8:00 PM">8:00 PM (Last Pick-up)</option>
                        </select>
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

function onStoreChange(val) {
    syncPreorderStoreAddress();
    initStoreMap();
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

document.addEventListener('DOMContentLoaded', function() {
    renderProducts('all');
    setupButtons();
    setupFilters();
    setupProgressNavigation();
    syncPreorderStoreAddress();
    
    // Delegated click listener for product cards & add buttons
    document.addEventListener('click', function(e) {
        const addBtn = e.target.closest('.btn-add-preorder');
        const card = e.target.closest('.product-card');
        
        if (addBtn) {
            e.preventDefault();
            e.stopPropagation();
            const id = addBtn.getAttribute('data-product-id');
            if (id) {
                addToCart(id);
            }
        } else if (card) {
            if (!e.target.closest('button') && !e.target.closest('a')) {
                const id = card.getAttribute('data-product-id');
                if (id) {
                    addToCart(id);
                }
            }
        }
    });
    
    // Set minimum date to today for pickup date
    const today = new Date().toISOString().split('T')[0];
    const pickupDateInput = document.getElementById('pickupDate');
    if (pickupDateInput) {
        pickupDateInput.setAttribute('min', today);
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
    const filtered = cat === 'all' ? products : products.filter(p => p.category === cat);

    if (filtered.length === 0) {
        const emptyMessage = activeSellerId > 0
            ? 'No active products are currently posted for this partner.'
            : 'No active products are currently available.';
        productList.innerHTML = `<p class="empty-product-note">${emptyMessage}</p>`;
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
        
        return `
            <div class="product-card ${isSelected ? 'selected' : ''}" data-product-id="${p.id}" onclick="addToCart(${p.id})">
                <div class="check-icon"><i class="fas fa-check"></i></div>
                <div class="product-image">${imageHtml}</div>
                <div class="product-info">
                    <h4>${p.name}</h4>
                    <div class="product-price">₱${priceNum.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                    <button type="button" class="btn ${isSelected ? 'btn-primary' : 'btn-outline'} btn-sm btn-block btn-add-preorder" data-product-id="${p.id}" onclick="event.stopPropagation(); addToCart(${p.id})">
                        ${isSelected ? `<i class="fas fa-check"></i> Added (${qty})` : '<i class="fas fa-plus"></i> Add to Order'}
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function addToCart(productId) {
    const product = products.find(p => String(p.id) === String(productId) || String(p.product_id) === String(productId));
    if (!product) {
        console.warn('Product not found for ID:', productId);
        return;
    }
    
    const existing = cart.find(i => String(i.id) === String(productId) || String(i.product_id) === String(productId));
    if (existing) {
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
    
    showPreorderToast('Added to Pre-Order Cart');
    
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
            Swal.fire({
                title: 'Selection Required',
                text: 'Please select at least one product for your pre-order.',
                icon: 'warning',
                confirmButtonText: 'Got it'
            });
            return false;
        }
    } else if (step === 2) {
        const fullName = document.getElementById('fullName').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const pickupDate = document.getElementById('pickupDate').value.trim();
        const pickupTime = document.getElementById('pickupTime').value.trim();

        if (!fullName) {
            Swal.fire({
                title: 'Claimant Name Required',
                text: 'Please enter the full name of the person picking up the order.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return false;
        }
        if (!email) {
            Swal.fire({
                title: 'Email Address Required',
                text: 'Please enter your email address for the order confirmation & claim receipt.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return false;
        }
        if (!phone) {
            Swal.fire({
                title: 'Mobile Phone Required',
                text: 'Please enter your mobile phone number for order readiness SMS alerts.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return false;
        }
        if (!pickupDate) {
            Swal.fire({
                title: 'Pick-up Date Required',
                text: 'Please select your preferred pick-up date.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return false;
        }
        if (!pickupTime) {
            Swal.fire({
                title: 'Time Slot Required',
                text: 'Please select your preferred pick-up time slot.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
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

.form-group label {
    font-size: 0.86rem;
    font-weight: 700;
    color: #344054;
}

input, select, textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--pre-border-input);
    border-radius: 10px;
    font-size: 0.9rem;
    color: var(--pre-ink);
    background: #ffffff;
    box-sizing: border-box;
    transition: all 0.15s ease;
}

input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--pre-red);
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}

/* Quantity Selector */
.quantity-selector {
    display: flex;
    align-items: center;
    max-width: 130px;
    border: 1px solid var(--pre-border-input);
    border-radius: 8px;
    overflow: hidden;
    background: #ffffff;
}

.qty-btn {
    width: 36px;
    height: 36px;
    background: #f8f9fa;
    border: none;
    cursor: pointer;
    font-size: 1.1rem;
    font-weight: 700;
    color: #344054;
    transition: background 0.15s ease;
}

.qty-btn:hover {
    background: #eaecf0;
    color: var(--pre-red);
}

.quantity-selector input {
    width: 100%;
    text-align: center;
    border: none;
    font-weight: 700;
    font-size: 0.95rem;
    padding: 0;
}

/* Payment Options */
.payment-options-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}

.payment-option-card {
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    padding: 18px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.15s ease;
}

.payment-option-card:hover {
    border-color: #d0d5dd;
}

.payment-option-card.selected {
    background: #fff1f0;
    border: 1.5px solid var(--pre-red);
}

.payment-option-card input {
    display: none;
}

.option-content {
    text-align: center;
}

.option-content i {
    font-size: 1.8rem;
    color: var(--pre-red);
    margin-bottom: 8px;
}

.option-content h4 {
    margin: 0 0 4px;
    font-size: 1rem;
    font-weight: 700;
    color: var(--pre-ink);
}

.option-content p {
    margin: 0;
    font-size: 0.82rem;
    color: var(--pre-muted);
}

/* Buttons */
.button-group {
    display: flex;
    justify-content: space-between;
    margin-top: 24px;
    gap: 12px;
}

.btn {
    padding: 10px 22px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.88rem;
    cursor: pointer;
    border: none;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-decoration: none;
}

.btn-primary {
    background: var(--pre-red);
    color: #ffffff;
    box-shadow: none;
}

.btn-primary:hover {
    background: var(--pre-red-hover);
    color: #ffffff;
    transform: none;
    box-shadow: none;
}

.btn-secondary, .btn-outline {
    background: #ffffff;
    border: 1px solid var(--pre-border-input);
    color: #344054;
}

.btn-secondary:hover, .btn-outline:hover {
    background: #f8f9fa;
    color: var(--pre-red);
    border-color: #d0d5dd;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.8rem;
    border-radius: 8px;
    min-width: auto;
}

/* Store Pick-up Step 2 Styles */
.preorder-pickup-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    background: #ecfdf3;
    border: 1px solid #abefc6;
    border-radius: 12px;
    margin-bottom: 20px;
}

.preorder-pickup-banner i {
    color: #027a48;
    font-size: 1.15rem;
    margin-top: 2px;
}

.preorder-pickup-banner strong {
    display: block;
    color: #027a48;
    font-size: 0.92rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.preorder-pickup-banner p {
    margin: 0;
    font-size: 0.82rem;
    color: #027a48;
    line-height: 1.4;
}

.preorder-section-block {
    background: #ffffff;
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 20px;
}

.preorder-block-title {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--pre-ink);
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f2f4f7;
}

.preorder-block-title i {
    color: var(--pre-red);
}

.store-details-card {
    background: #f8f9fa;
    border: 1px solid var(--pre-border);
    border-radius: 12px;
    padding: 14px 16px;
    margin-top: 12px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.store-details-main {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.store-details-main .store-icon {
    width: 38px;
    height: 38px;
    background: #fff1f0;
    border: 1px solid #fee4e2;
    color: var(--pre-red);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.store-info-text h4 {
    margin: 0 0 4px;
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--pre-ink);
}

.store-info-text p {
    margin: 0 0 8px;
    font-size: 0.84rem;
    color: var(--pre-muted);
}

.store-meta-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.store-tag {
    font-size: 0.76rem;
    padding: 3px 8px;
    background: #ffffff;
    border: 1px solid var(--pre-border-input);
    border-radius: 6px;
    color: #475467;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.btn-directions {
    padding: 8px 14px;
    font-size: 0.8rem;
    white-space: nowrap;
}

.store-map-wrapper {
    position: relative;
    border: 1px solid var(--pre-border);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
}

.store-map-container {
    width: 100%;
    height: 240px;
    background: #e2e8f0;
}

.store-map-note {
    position: absolute;
    bottom: 8px;
    left: 8px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(4px);
    border: 1px solid #eaecf0;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.76rem;
    font-weight: 700;
    color: #344054;
    z-index: 400;
}

@media (max-width: 768px) {
    .page-header h1 { font-size: 1.6rem; }
    .preorder-container { padding: 16px; }
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
</style>
<?php include 'includes/footer.php'; ?>

