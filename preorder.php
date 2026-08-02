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
                <div class="progress-step-label">Address</div>
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
        <!-- Step 1: Product Selection -->
        <div class="step-content active" data-step="1">
            <div class="step-title">Select Your Product</div>
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
                <div id="productList" class="product-grid"></div>
            </div>

            <!-- Cart Section for Pre-order -->
            <div class="preorder-cart-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h4 style="margin: 0; display: flex; align-items: center; gap: 10px;">Your Pre-order Items <span id="cartCountBadge" style="display:none; background: var(--primary-color); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem;">0</span></h4>
                    <button type="button" id="clearCartBtn" class="btn-sm" style="display:none; background: #fff; border: 1px solid #ddd; color: #666; font-size: 0.8rem; padding: 4px 12px; border-radius: 20px; cursor: pointer;" onclick="clearCart()"><i class="fas fa-trash-alt"></i> Clear</button>
                </div>
                <div id="preorderCartItems" class="cart-items-container">
                    <p class="empty-cart-msg">No items selected yet.</p>
                </div>
                <div class="cart-total-row">
                    <span>Total Estimated (Incl. VAT):</span>
                    <span id="cartTotalDisplay">₱0.00</span>
                </div>
            </div>

            <div class="button-group">
                <button type="button" class="btn btn-primary next-btn">Next</button>
            </div>
        </div>

        <!-- Step 2: Address Information -->
        <div class="step-content" data-step="2">
            <div class="step-title">Delivery Address</div>

            <div class="form-row full">
                <div class="form-group">
                    <label for="fullName">Full Name *</label>
                    <input type="text" id="fullName" name="fullName" placeholder="Enter your full name" value="<?php echo htmlspecialchars($user_profile['full_name']); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" placeholder="your@email.com" value="<?php echo htmlspecialchars($user_profile['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number *</label>
                    <input type="tel" id="phone" name="phone" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($user_profile['phone']); ?>" required>
                </div>
            </div>
            <p class="autofill-note"><i class="fas fa-user-check"></i> We auto-filled your account details to make pre-order faster. You can still edit them.</p>

            <div class="form-row full">
                <div class="form-group">
                    <label for="streetAddress">Street Address *</label>
                    <input type="text" id="streetAddress" name="streetAddress" placeholder="House no., Street name, Building" value="<?php echo htmlspecialchars($prefill_street); ?>" required>
                </div>
            </div>

            <div class="form-row full">
                <div class="form-group">
                    <label for="preorderAddressSearch">Map Pinpoint (Recommended)</label>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <input type="text" id="preorderAddressSearch" placeholder="Type address and pick from suggestions" style="flex:1; min-width:220px;">
                        <button type="button" class="btn btn-secondary" id="preorderUseMyLocation"><i class="fas fa-location-arrow"></i> Use My Location</button>
                    </div>
                    <div id="preorderMap" style="height:300px; margin-top:12px; border-radius:12px; border:1px solid #e5e7eb; background:#f8fafc;"></div>
                    <small style="display:block; margin-top:8px; color:#64748b;">Pin the exact location on the map for better address accuracy.</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="preorderRegion">Region (PSGC) *</label>
                    <select id="preorderRegion" required>
                        <option value="">-- Select Region --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="preorderProvince">Province (PSGC) *</label>
                    <select id="preorderProvince" required>
                        <option value="">-- Select Province --</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="city">City / Municipality *</label>
                    <select id="city" name="city" required>
                        <option value="">-- Select City/Municipality --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="barangay">Barangay *</label>
                    <select id="barangay" name="barangay" required>
                        <option value="">-- Select Barangay --</option>
                    </select>
                </div>
            </div>

            <input type="hidden" id="province" name="province" value="<?php echo htmlspecialchars($prefill_province); ?>">
            <input type="hidden" id="preorder_region_name" name="preorder_region_name" value="">
            <input type="hidden" id="preorder_region_code" name="preorder_region_code" value="">
            <input type="hidden" id="preorder_province_name" name="preorder_province_name" value="">
            <input type="hidden" id="preorder_province_code" name="preorder_province_code" value="">
            <input type="hidden" id="preorder_city_name" name="preorder_city_name" value="">
            <input type="hidden" id="preorder_city_code" name="preorder_city_code" value="">
            <input type="hidden" id="preorder_barangay_name" name="preorder_barangay_name" value="">
            <input type="hidden" id="preorder_barangay_code" name="preorder_barangay_code" value="">
            <input type="hidden" id="latitude" name="latitude" value="">
            <input type="hidden" id="longitude" name="longitude" value="">

            <div class="form-row">
                <div class="form-group">
                    <label for="pickupDate">Pickup/Delivery Date *</label>
                    <input type="date" id="pickupDate" name="pickupDate" required>
                </div>
                <div class="form-group">
                    <label for="pickupTime">Preferred Time *</label>
                    <select id="pickupTime" name="pickupTime" required>
                        <option value="">-- Select Time --</option>
                        <option value="8:00 AM">8:00 AM</option>
                        <option value="9:00 AM">9:00 AM</option>
                        <option value="10:00 AM">10:00 AM</option>
                        <option value="11:00 AM">11:00 AM</option>
                        <option value="12:00 PM">12:00 PM</option>
                        <option value="1:00 PM">1:00 PM</option>
                        <option value="2:00 PM">2:00 PM</option>
                        <option value="3:00 PM">3:00 PM</option>
                        <option value="4:00 PM">4:00 PM</option>
                        <option value="5:00 PM">5:00 PM</option>
                        <option value="6:00 PM">6:00 PM</option>
                        <option value="7:00 PM">7:00 PM</option>
                        <option value="8:00 PM">8:00 PM</option>
                    </select>
                </div>
            </div>

            <div class="button-group">
                <button type="button" class="btn btn-secondary prev-btn">Back</button>
                <button type="button" class="btn btn-primary next-btn">Next</button>
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
const preferredBarangay = <?php echo json_encode($prefill_barangay); ?>;
const preferredCity = <?php echo json_encode($prefill_city); ?>;
const preferredProvince = <?php echo json_encode($prefill_province); ?>;
const preferredStreet = <?php echo json_encode($prefill_street); ?>;
const userAddressSeed = <?php echo json_encode((string)$user_profile['address']); ?>;
const preorderGoogleMapsApiKey = <?php echo json_encode((string)$google_maps_api_key); ?>;
const PREORDER_PSGC_API_BASE = 'https://psgc.gitlab.io/api';
let cart = []; // Array to store selected items: { id, name, price, quantity, image }
const VAT_RATE = 0.12;
let preorderMap = null;
let preorderMarker = null;
let preorderGeocoder = null;
let preorderGoogleGeocodingAvailable = <?php echo $google_geocoding_enabled ? 'true' : 'false'; ?>;
let preorderAutocomplete = null;
let isPreorderMapInitialized = false;
let latestPreorderResolvedAddress = userAddressSeed || '';

function getPreorderTotals() {
    const subtotal = cart.reduce((sum, item) => sum + ((parseFloat(item.price) || 0) * (parseInt(item.quantity) || 0)), 0);
    const vatAmount = Math.round(subtotal * VAT_RATE * 100) / 100;
    const total = subtotal + vatAmount;
    return { subtotal, vatAmount, total };
}

function preorderNormalizePlaceName(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/\b(region|province|city|municipality|barangay|brgy|of|the)\b/g, '')
        .replace(/[^a-z0-9]/g, '');
}

function preorderGetSelectedText(selectElement) {
    if (!selectElement || selectElement.selectedIndex < 0) return '';
    const option = selectElement.options[selectElement.selectedIndex];
    if (!option || !option.value) return '';
    return option.textContent.trim();
}

function preorderSetSelectOptions(selectElement, items, placeholder) {
    if (!selectElement) return;
    selectElement.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = placeholder;
    selectElement.appendChild(defaultOption);

    [...items]
        .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
        .forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item.code || '');
            option.textContent = String(item.name || '');
            selectElement.appendChild(option);
        });
}

function preorderFindOptionValueByName(selectElement, targetName) {
    if (!selectElement || !targetName) return '';
    const normalizedTarget = preorderNormalizePlaceName(targetName);
    const options = Array.from(selectElement.options || []);
    for (const option of options) {
        if (!option.value) continue;
        const normalizedOption = preorderNormalizePlaceName(option.textContent);
        if (!normalizedOption) continue;
        if (
            normalizedOption === normalizedTarget ||
            normalizedOption.includes(normalizedTarget) ||
            normalizedTarget.includes(normalizedOption)
        ) {
            return option.value;
        }
    }
    return '';
}

async function fetchPreorderPsgc(path) {
    const response = await fetch(PREORDER_PSGC_API_BASE + path, {
        method: 'GET',
        headers: { Accept: 'application/json' }
    });
    if (!response.ok) {
        throw new Error('PSGC API request failed: ' + path);
    }
    return response.json();
}

function syncPreorderAddressFields() {
    const regionSelect = document.getElementById('preorderRegion');
    const provinceSelect = document.getElementById('preorderProvince');
    const citySelect = document.getElementById('city');
    const barangaySelect = document.getElementById('barangay');
    const streetInput = document.getElementById('streetAddress');

    const regionName = preorderGetSelectedText(regionSelect);
    const provinceName = preorderGetSelectedText(provinceSelect);
    const cityName = preorderGetSelectedText(citySelect);
    const barangayName = preorderGetSelectedText(barangaySelect);

    const setValue = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.value = value || '';
    };

    setValue('preorder_region_code', regionSelect?.value || '');
    setValue('preorder_region_name', regionName);
    setValue('preorder_province_code', provinceSelect?.value || '');
    setValue('preorder_province_name', provinceName);
    setValue('preorder_city_code', citySelect?.value || '');
    setValue('preorder_city_name', cityName);
    setValue('preorder_barangay_code', barangaySelect?.value || '');
    setValue('preorder_barangay_name', barangayName);

    const provinceHidden = document.getElementById('province');
    if (provinceHidden) {
        provinceHidden.value = provinceName || regionName || preferredProvince || '';
    }

    const street = (streetInput?.value || '').trim();
    const addressParts = [street, barangayName, cityName, provinceHidden?.value || '', regionName].filter(Boolean);
    if (addressParts.length >= 3) {
        latestPreorderResolvedAddress = addressParts.join(', ');
    }
}

async function loadPreorderRegions() {
    const regionSelect = document.getElementById('preorderRegion');
    if (!regionSelect) return;
    const regions = await fetchPreorderPsgc('/regions');
    preorderSetSelectOptions(regionSelect, regions, '-- Select Region --');
    regionSelect.disabled = false;
}

async function loadPreorderProvinces(regionCode) {
    const provinceSelect = document.getElementById('preorderProvince');
    if (!provinceSelect) return [];
    if (!regionCode) {
        preorderSetSelectOptions(provinceSelect, [], '-- Select Province --');
        provinceSelect.disabled = true;
        return [];
    }
    const provinces = await fetchPreorderPsgc('/regions/' + encodeURIComponent(regionCode) + '/provinces');
    preorderSetSelectOptions(
        provinceSelect,
        provinces,
        provinces.length ? '-- Select Province --' : '-- No Province --'
    );
    provinceSelect.disabled = provinces.length === 0;
    return provinces;
}

async function loadPreorderCities(regionCode, provinceCode) {
    const citySelect = document.getElementById('city');
    if (!citySelect) return [];
    if (!regionCode) {
        preorderSetSelectOptions(citySelect, [], '-- Select City/Municipality --');
        citySelect.disabled = true;
        return [];
    }

    const path = provinceCode
        ? '/provinces/' + encodeURIComponent(provinceCode) + '/cities-municipalities'
        : '/regions/' + encodeURIComponent(regionCode) + '/cities-municipalities';

    const cities = await fetchPreorderPsgc(path);
    preorderSetSelectOptions(citySelect, cities, '-- Select City/Municipality --');
    citySelect.disabled = cities.length === 0;
    return cities;
}

async function loadPreorderBarangays(cityCode, selectedBarangayName = '') {
    const barangaySelect = document.getElementById('barangay');
    if (!barangaySelect) return [];
    if (!cityCode) {
        preorderSetSelectOptions(barangaySelect, [], '-- Select Barangay --');
        barangaySelect.disabled = true;
        syncPreorderAddressFields();
        return [];
    }

    const barangays = await fetchPreorderPsgc('/cities-municipalities/' + encodeURIComponent(cityCode) + '/barangays');
    preorderSetSelectOptions(barangaySelect, barangays, '-- Select Barangay --');
    barangaySelect.disabled = barangays.length === 0;

    if (selectedBarangayName) {
        const optionValue = preorderFindOptionValueByName(barangaySelect, selectedBarangayName);
        if (optionValue) {
            barangaySelect.value = optionValue;
        }
    }

    syncPreorderAddressFields();
    return barangays;
}

function extractPreorderAddressParts(components = []) {
    const getLongName = (typeMatcher) => {
        const match = components.find((component) => (component.types || []).some(typeMatcher));
        return match?.long_name || '';
    };

    return {
        region: getLongName((type) => type === 'administrative_area_level_1'),
        province: getLongName((type) => type === 'administrative_area_level_2'),
        city: getLongName((type) => type === 'locality' || type === 'administrative_area_level_3'),
        barangay: getLongName((type) => type === 'sublocality_level_1' || type === 'neighborhood')
    };
}

function isGoogleGeocodingUnavailableStatus(status) {
    const normalized = String(status || '').trim().toUpperCase();
    return normalized === 'REQUEST_DENIED'
        || normalized === 'OVER_QUERY_LIMIT'
        || normalized === 'OVER_DAILY_LIMIT'
        || normalized === 'INVALID_REQUEST';
}

async function reversePreorderGeocodeFromNominatim(lat, lng) {
    if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
        return { formattedAddress: '', parts: {} };
    }
    try {
        const endpoint = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
            + '&addressdetails=1'
            + '&countrycodes=ph'
            + '&lat=' + encodeURIComponent(String(lat))
            + '&lon=' + encodeURIComponent(String(lng));
        const response = await fetch(endpoint, {
            method: 'GET',
            headers: { Accept: 'application/json' }
        });
        if (!response.ok) {
            return { formattedAddress: '', parts: {} };
        }

        const data = await response.json();
        const address = data?.address || {};
        return {
            formattedAddress: String(data?.display_name || '').trim(),
            parts: {
                region: String(address.state || address.region || '').trim(),
                province: String(address.state_district || address.province || address.county || '').trim(),
                city: String(address.city || address.town || address.municipality || address.county || '').trim(),
                barangay: String(address.suburb || address.neighbourhood || address.village || address.hamlet || '').trim()
            }
        };
    } catch (error) {
        return { formattedAddress: '', parts: {} };
    }
}

async function applyPreorderAddressComponents(parts = {}) {
    const regionSelect = document.getElementById('preorderRegion');
    const provinceSelect = document.getElementById('preorderProvince');
    const citySelect = document.getElementById('city');
    const barangaySelect = document.getElementById('barangay');
    if (!regionSelect || !provinceSelect || !citySelect || !barangaySelect) return;

    const regionCode = preorderFindOptionValueByName(regionSelect, parts.region);
    if (regionCode) {
        regionSelect.value = regionCode;
        const provinces = await loadPreorderProvinces(regionCode);
        if (!provinces.length) {
            await loadPreorderCities(regionCode, '');
        }
    }

    const provinceCode = preorderFindOptionValueByName(provinceSelect, parts.province);
    if (provinceCode) {
        provinceSelect.value = provinceCode;
        await loadPreorderCities(regionSelect.value, provinceCode);
    }

    const cityCode = preorderFindOptionValueByName(citySelect, parts.city);
    if (cityCode) {
        citySelect.value = cityCode;
        await loadPreorderBarangays(cityCode);
    }

    const barangayCode = preorderFindOptionValueByName(barangaySelect, parts.barangay);
    if (barangayCode) {
        barangaySelect.value = barangayCode;
    }

    syncPreorderAddressFields();
}

async function initializePreorderAddressSection() {
    const regionSelect = document.getElementById('preorderRegion');
    const provinceSelect = document.getElementById('preorderProvince');
    const citySelect = document.getElementById('city');
    const barangaySelect = document.getElementById('barangay');
    if (!regionSelect || !provinceSelect || !citySelect || !barangaySelect) return;

    await loadPreorderRegions();

    let seedRegion = '';
    if ((preferredProvince || '').toLowerCase().includes('cavite')) {
        seedRegion = 'CALABARZON';
    } else if (/(manila|ncr|metro manila)/i.test(userAddressSeed || '')) {
        seedRegion = 'National Capital Region';
    }

    let initialRegionCode = preorderFindOptionValueByName(regionSelect, seedRegion);
    if (!initialRegionCode && seedRegion === 'CALABARZON') {
        initialRegionCode = preorderFindOptionValueByName(regionSelect, 'Region IV-A');
    }
    if (!initialRegionCode && seedRegion === 'National Capital Region') {
        initialRegionCode = preorderFindOptionValueByName(regionSelect, 'NCR');
    }
    if (!initialRegionCode && regionSelect.options.length > 1) {
        initialRegionCode = regionSelect.options[1].value;
    }
    if (initialRegionCode) {
        regionSelect.value = initialRegionCode;
        const provinces = await loadPreorderProvinces(initialRegionCode);

        let initialProvinceCode = '';
        if (provinces.length && preferredProvince) {
            initialProvinceCode = preorderFindOptionValueByName(provinceSelect, preferredProvince);
            if (initialProvinceCode) {
                provinceSelect.value = initialProvinceCode;
            }
        }

        await loadPreorderCities(initialRegionCode, initialProvinceCode);

        if (preferredCity) {
            const initialCityCode = preorderFindOptionValueByName(citySelect, preferredCity);
            if (initialCityCode) {
                citySelect.value = initialCityCode;
                await loadPreorderBarangays(initialCityCode, preferredBarangay || '');
            } else {
                await loadPreorderBarangays(citySelect.value, preferredBarangay || '');
            }
        }
    }

    regionSelect.addEventListener('change', async () => {
        try {
            await loadPreorderProvinces(regionSelect.value);
            await loadPreorderCities(regionSelect.value, '');
            await loadPreorderBarangays('');
        } catch (error) {
            console.error('Failed loading provinces/cities:', error);
        }
        syncPreorderAddressFields();
    });

    provinceSelect.addEventListener('change', async () => {
        try {
            await loadPreorderCities(regionSelect.value, provinceSelect.value);
            await loadPreorderBarangays('');
        } catch (error) {
            console.error('Failed loading cities/barangays:', error);
        }
        syncPreorderAddressFields();
    });

    citySelect.addEventListener('change', async () => {
        try {
            await updateBarangays();
        } catch (error) {
            console.error('Failed loading barangays:', error);
        }
        syncPreorderAddressFields();
    });

    barangaySelect.addEventListener('change', syncPreorderAddressFields);
    document.getElementById('streetAddress')?.addEventListener('input', syncPreorderAddressFields);

    syncPreorderAddressFields();
}

async function updatePreorderAddressFromCoordinates(lat, lng) {
    document.getElementById('latitude').value = String(lat);
    document.getElementById('longitude').value = String(lng);

    const applyResolvedAddress = async (formattedAddress, parts = {}) => {
        const normalizedFormatted = String(formattedAddress || '').trim();
        const streetCandidate = (normalizedFormatted.split(',')[0] || '').trim();
        const streetInput = document.getElementById('streetAddress');
        const searchInput = document.getElementById('preorderAddressSearch');

        if (streetInput && streetCandidate) streetInput.value = streetCandidate;
        if (searchInput && normalizedFormatted) searchInput.value = normalizedFormatted;
        if (normalizedFormatted) latestPreorderResolvedAddress = normalizedFormatted;

        await applyPreorderAddressComponents(parts || {});
        syncPreorderAddressFields();
    };

    if (!preorderGeocoder || !preorderGoogleGeocodingAvailable) {
        const fallback = await reversePreorderGeocodeFromNominatim(lat, lng);
        await applyResolvedAddress(fallback.formattedAddress, fallback.parts);
        return;
    }

    preorderGeocoder.geocode({ location: { lat, lng } }, async (results, status) => {
        if (status === 'OK' && results && results.length) {
            const result = results[0];
            await applyResolvedAddress(
                String(result.formatted_address || '').trim(),
                extractPreorderAddressParts(result.address_components || [])
            );
            return;
        }

        if (isGoogleGeocodingUnavailableStatus(status)) {
            preorderGoogleGeocodingAvailable = false;
        }

        const fallback = await reversePreorderGeocodeFromNominatim(lat, lng);
        await applyResolvedAddress(fallback.formattedAddress, fallback.parts);
    });
}

function initPreorderMap() {
    if (isPreorderMapInitialized) return;
    const mapCanvas = document.getElementById('preorderMap');
    if (!mapCanvas || !window.google || !google.maps) return;

    isPreorderMapInitialized = true;
    preorderMap = new google.maps.Map(mapCanvas, {
        center: { lat: 14.5995, lng: 120.9842 },
        zoom: 11
    });

    preorderGeocoder = new google.maps.Geocoder();

    const searchInput = document.getElementById('preorderAddressSearch');
    if (searchInput && google.maps.places && typeof google.maps.places.Autocomplete === 'function') {
        preorderAutocomplete = new google.maps.places.Autocomplete(searchInput);
        preorderAutocomplete.bindTo('bounds', preorderMap);
        preorderAutocomplete.addListener('place_changed', async () => {
            const place = preorderAutocomplete.getPlace();
            if (!place || !place.geometry) return;

            preorderMap.setCenter(place.geometry.location);
            preorderMap.setZoom(17);
            preorderMarker.setPosition(place.geometry.location);

            const formattedAddress = String(place.formatted_address || '').trim();
            const streetCandidate = (formattedAddress.split(',')[0] || '').trim();
            if (formattedAddress) {
                latestPreorderResolvedAddress = formattedAddress;
                searchInput.value = formattedAddress;
            }
            const streetInput = document.getElementById('streetAddress');
            if (streetInput && streetCandidate) streetInput.value = streetCandidate;

            document.getElementById('latitude').value = String(place.geometry.location.lat());
            document.getElementById('longitude').value = String(place.geometry.location.lng());
            await applyPreorderAddressComponents(extractPreorderAddressParts(place.address_components || []));
            syncPreorderAddressFields();
        });
    }

    preorderMarker = new google.maps.Marker({
        map: preorderMap,
        draggable: true
    });

    preorderMarker.addListener('dragend', () => {
        const position = preorderMarker.getPosition();
        if (!position) return;
        updatePreorderAddressFromCoordinates(position.lat(), position.lng());
    });

    preorderMap.addListener('click', (event) => {
        preorderMarker.setPosition(event.latLng);
        updatePreorderAddressFromCoordinates(event.latLng.lat(), event.latLng.lng());
    });
}

// Barangays by City/Municipality in Cavite
const barangaysByCity = {
    'Bacoor': ['Alima', 'Aniban I', 'Aniban II', 'Aniban III', 'Aniban IV', 'Aniban V', 'Banalo', 'Bayanan', 'Campo Santo', 'Daang Bukid', 'Digman', 'Dulong Bayan', 'Habay I', 'Habay II', 'Kaingin', 'Ligas I', 'Ligas II', 'Ligas III', 'Mabolo I', 'Mabolo II', 'Mabolo III', 'Maliksi I', 'Maliksi II', 'Maliksi III', 'Molino I', 'Molino II', 'Molino III', 'Molino IV', 'Molino V', 'Molino VI', 'Molino VII', 'Niog I', 'Niog II', 'Niog III', 'Panapaan I', 'Panapaan II', 'Panapaan III', 'Panapaan IV', 'Panapaan V', 'Panapaan VI', 'Panapaan VII', 'Panapaan VIII', 'Queens Row Central', 'Queens Row East', 'Queens Row West', 'Real I', 'Real II', 'Salinas I', 'Salinas II', 'Salinas III', 'Salinas IV', 'San Nicolas I', 'San Nicolas II', 'San Nicolas III', 'Springville', 'Tabing Dagat', 'Talaba I', 'Talaba II', 'Talaba III', 'Talaba IV', 'Talaba V', 'Talaba VI', 'Talaba VII', 'Zapote I', 'Zapote II', 'Zapote III', 'Zapote IV', 'Zapote V'],
    'Cavite City': ['Barangay 1', 'Barangay 2', 'Barangay 3', 'Barangay 4', 'Barangay 5', 'Barangay 6', 'Barangay 7', 'Barangay 8', 'Barangay 9', 'Barangay 10', 'Barangay 11', 'Barangay 12', 'Barangay 13', 'Barangay 14', 'Barangay 15', 'Barangay 16', 'Barangay 17', 'Barangay 18', 'Barangay 19', 'Barangay 20', 'Barangay 21', 'Barangay 22', 'Barangay 23', 'Barangay 24', 'Barangay 25', 'Barangay 26', 'Barangay 27', 'Barangay 28', 'Barangay 29', 'Barangay 30', 'Barangay 31', 'Barangay 32', 'Barangay 33', 'Barangay 34', 'Barangay 35', 'Barangay 36', 'Barangay 37', 'Barangay 38', 'Barangay 39', 'Barangay 40', 'Barangay 41', 'Barangay 42', 'Barangay 43', 'Barangay 44', 'Barangay 45', 'Barangay 46', 'Barangay 47', 'Barangay 48', 'Barangay 49', 'Barangay 50', 'Barangay 51', 'Barangay 52', 'Barangay 53', 'Barangay 54', 'Barangay 55', 'Barangay 56', 'Barangay 57', 'Barangay 58', 'Barangay 59', 'Barangay 60', 'Barangay 61', 'Barangay 62', 'Barangay 63', 'Barangay 64', 'Barangay 65', 'Barangay 66', 'Barangay 67', 'Barangay 68', 'Barangay 69', 'Barangay 70', 'Barangay 71', 'Barangay 72', 'Barangay 73', 'Barangay 74', 'Barangay 75', 'Barangay 76', 'Barangay 77', 'Barangay 78', 'Barangay 79', 'Barangay 80', 'Barangay 81', 'Barangay 82', 'Barangay 83', 'Barangay 84'],
    'Dasmarinas': ['Paliparan I', 'Paliparan II', 'Paliparan III', 'Salawag', 'Salitran I', 'Salitran II', 'Salitran III', 'Salitran IV', 'Sampaloc I', 'Sampaloc II', 'Sampaloc III', 'Sampaloc IV', 'Sampaloc V', 'San Agustin I', 'San Agustin II', 'San Agustin III', 'San Andres I', 'San Andres II', 'San Antonio de Padua I', 'San Antonio de Padua II', 'San Dionisio', 'San Esteban', 'San Isidro Labrador I', 'San Isidro Labrador II', 'San Jose', 'San Juan', 'San Lorenzo Ruiz I', 'San Lorenzo Ruiz II', 'San Luis I', 'San Luis II', 'San Mateo', 'San Miguel I', 'San Miguel II', 'San Nicolas I', 'San Nicolas II', 'San Roque', 'San Simon', 'Santa Cristina I', 'Santa Cristina II', 'Santa Lucia', 'Santa Maria', 'Santiago', 'Emmanuel I', 'Emmanuel II', 'Burol I', 'Burol II', 'Burol III', 'Fatima I', 'Fatima II', 'Fatima III', 'Datu Esmael', 'Emmanuel III', 'Langkaan I', 'Langkaan II', 'Luzviminda I', 'Luzviminda II', 'Victoria Reyes', 'Zone I-A', 'Zone I-B', 'Zone II-A', 'Zone II-B', 'Zone III', 'Zone IV-A', 'Zone IV-B'],
    'Imus': ['Alapan I-A', 'Alapan I-B', 'Alapan I-C', 'Alapan II-A', 'Alapan II-B', 'Anabu I-A', 'Anabu I-B', 'Anabu I-C', 'Anabu I-D', 'Anabu I-E', 'Anabu I-F', 'Anabu I-G', 'Anabu II-A', 'Anabu II-B', 'Anabu II-C', 'Anabu II-D', 'Anabu II-E', 'Anabu II-F', 'Bagong Silang', 'Bayan Luma I', 'Bayan Luma II', 'Bayan Luma III', 'Bayan Luma IV', 'Bayan Luma V', 'Bayan Luma VI', 'Bayan Luma VII', 'Bayan Luma VIII', 'Buhay na Tubig', 'Bucandala I', 'Bucandala II', 'Bucandala III', 'Bucandala IV', 'Bucandala V', 'Carsadang Bago I', 'Carsadang Bago II', 'Magdalo', 'Maharlika', 'Malagasang I-A', 'Malagasang I-B', 'Malagasang I-C', 'Malagasang I-D', 'Malagasang I-E', 'Malagasang I-F', 'Malagasang I-G', 'Malagasang II-A', 'Malagasang II-B', 'Malagasang II-C', 'Malagasang II-D', 'Malagasang II-E', 'Malagasang II-F', 'Mariano Espeleta I', 'Mariano Espeleta II', 'Mariano Espeleta III', 'Medicion I-A', 'Medicion I-B', 'Medicion I-C', 'Medicion I-D', 'Medicion II-A', 'Medicion II-B', 'Medicion II-C', 'Medicion II-D', 'Medicion II-E', 'Medicion II-F', 'Palico I', 'Palico II', 'Palico III', 'Palico IV', 'Pasong Buaya I', 'Pasong Buaya II', 'Poblacion I-A', 'Poblacion I-B', 'Poblacion I-C', 'Poblacion II-A', 'Poblacion II-B', 'Poblacion III-A', 'Poblacion III-B', 'Poblacion IV-A', 'Poblacion IV-B', 'Poblacion IV-C', 'Poblacion IV-D', 'Toclong I-A', 'Toclong I-B', 'Toclong I-C', 'Toclong II-A', 'Toclong II-B'],
    'Tagaytay': ['Asisan', 'Bagong Tubig', 'Calabuso', 'Dapdap East', 'Dapdap West', 'Francisco', 'Guinhawa North', 'Guinhawa South', 'Iruhin Central', 'Iruhin East', 'Iruhin South', 'Iruhin West', 'Kaybagal Central', 'Kaybagal North', 'Kaybagal South', 'Mag-Asawang Ilat', 'Maharlika East', 'Maharlika West', 'Maitim 2nd Central', 'Maitim 2nd East', 'Maitim 2nd West', 'Mendez Crossing East', 'Mendez Crossing West', 'Neogan', 'Patutong Malaki North', 'Patutong Malaki South', 'Sambong', 'San Jose', 'Silang Crossing East', 'Silang Crossing West', 'Sungay East', 'Sungay West', 'Tolentino East', 'Tolentino West', 'Zambal'],
    'Trece Martires': ['Aguado', 'Cabezas', 'Cabuco', 'Conchu', 'De Ocampo', 'Gregoria', 'Inocencio', 'Lapidario', 'Llavac', 'Luciano', 'Osorio', 'Perez', 'San Agustin'],
    'General Trias': ['Alingaro', 'Arnaldo', 'Bacao I', 'Bacao II', 'Bagumbayan', 'Biclatan', 'Buenavista I', 'Buenavista II', 'Buenavista III', 'Corregidor', 'Dulong Bayan', 'Gov. Ferrer', 'Javalera', 'Manggahan', 'Navarro', 'Ninety Sixth', 'Panungyanan', 'Pasong Camachile I', 'Pasong Camachile II', 'Pasong Kawayan I', 'Pasong Kawayan II', 'Pinagtipunan', 'Prinza', 'Sampalucan', 'San Francisco', 'San Gabriel', 'San Juan I', 'San Juan II', 'Santa Clara', 'Santiago', 'Tapia', 'Tejero', 'Vibora'],
    'Kawit': ['Balsahan-Bisita', 'Binakayan-Aplaya', 'Binakayan-Kanluran', 'Congbalay-Legaspi', 'Gahak', 'Kaingen', 'Kapipol', 'Magdalo (Putol)', 'Manggahan-Lawin', 'Marulas', 'Panamitan', 'Poblacion', 'Pulvorista', 'Samala-Marquez', 'San Sebastian', 'Santa Isabel', 'Tabon I', 'Tabon II', 'Tabon III', 'Toclong', 'Tramo-Bantayan', 'Wakas I', 'Wakas II'],
    'Noveleta': ['Magdiwang', 'Poblacion', 'Salcedo I', 'Salcedo II', 'San Antonio I', 'San Antonio II', 'San Jose I', 'San Jose II', 'San Juan I', 'San Juan II', 'San Rafael I', 'San Rafael II', 'San Rafael III', 'San Rafael IV', 'Santa Rosa I', 'Santa Rosa II'],
    'Rosario': ['Bagbad I', 'Bagbad II', 'Kanluran', 'Ligtong I', 'Ligtong II', 'Ligtong III', 'Ligtong IV', 'Muzon I', 'Muzon II', 'Poblacion', 'Sapa I', 'Sapa II', 'Sapa III', 'Sapa IV', 'Silangan I', 'Silangan II', 'Tejeros Convention', 'Wawa I', 'Wawa II', 'Wawa III'],
    'Tanza': ['Amaya I', 'Amaya II', 'Amaya III', 'Amaya IV', 'Amaya V', 'Amaya VI', 'Amaya VII', 'Bagtas', 'Biga I', 'Biga II', 'Biwas', 'Bucal I', 'Bucal II', 'Bucal III-A', 'Bucal III-B', 'Capipisa', 'Daang Amaya I', 'Daang Amaya II', 'Daang Amaya III', 'Halayhay', 'Julugan I', 'Julugan II', 'Julugan III', 'Julugan IV', 'Julugan V', 'Julugan VI', 'Julugan VII', 'Julugan VIII', 'Lambingan', 'Mulawin', 'Paradahan I', 'Paradahan II', 'Pata', 'Poblacion I', 'Poblacion II', 'Poblacion III', 'Poblacion IV', 'Sahud Ulan', 'Sanja Mayor', 'Santol', 'Tanauan', 'Tres Cruses'],
    'Naic': ['Bagong Karsada', 'Balsahan', 'Bancaan', 'Bucana', 'Calubcob', 'Capt. C. Nazareno', 'Gomez-Zamora', 'Halang', 'Humbac', 'Ibayo Estacion', 'Ibayo Silangan', 'Kanluran', 'Labac', 'Latoria', 'Mabulo', 'Makina', 'Malainen Bago', 'Malainen Luma', 'Molino', 'Munting Mapino', 'Muzon', 'Palangue Central', 'Palangue North', 'Palangue South', 'Poblacion', 'Sabang', 'San Roque', 'Santulan', 'Sapa', 'Timalan Balsahan', 'Timalan Concepcion'],
    'Silang': ['Acacia', 'Adlas', 'Anahaw I', 'Anahaw II', 'Balite I', 'Balite II', 'Balite III', 'Balubad', 'Barangay I', 'Barangay II', 'Barangay III', 'Barangay IV', 'Barangay V', 'Batas', 'Biga I', 'Biga II', 'Biluso', 'Bucal', 'Buho', 'Bulihan', 'Cabangaan', 'Carmen', 'Dakila', 'Iba', 'Inchican', 'Ipil I', 'Ipil II', 'Kaong', 'Lalaan I', 'Lalaan II', 'Litlit', 'Lucsuhin', 'Lumil', 'Maguyam', 'Malabag', 'Mataas na Burol', 'Nasugbu', 'Narra I', 'Narra II', 'Narra III', 'Pooc I', 'Pooc II', 'Putingkahoy', 'Sabutan', 'San Miguel I', 'San Miguel II', 'San Vicente I', 'San Vicente II', 'Santa Rosa I', 'Santa Rosa II', 'Santol', 'Soong', 'Tartaria', 'Tibig', 'Toledo', 'Tubuan I', 'Tubuan II', 'Tubuan III', 'Ulat', 'Yakal'],
    'Amadeo': ['Banaybanay', 'Barangay I', 'Barangay II', 'Barangay III', 'Barangay IV', 'Barangay V', 'Barangay VI', 'Barangay VII', 'Barangay VIII', 'Bucal', 'Buho', 'Dagatan', 'Halang', 'Loma', 'Mapalad', 'Minantok Kanluran', 'Minantok Silangan', 'Pangil', 'Salaban', 'Talon', 'Tamacan'],
    'Indang': ['Agus-us', 'Alulod', 'Banaba Cerca', 'Banaba Lejos', 'Bancod', 'Barangay 1', 'Barangay 2', 'Barangay 3', 'Barangay 4', 'Buna Cerca', 'Buna Lejos I', 'Buna Lejos II', 'Calumpang Cerca', 'Calumpang Lejos I', 'Calumpang Lejos II', 'Carasuchi', 'Daine I', 'Daine II', 'Guyam Malaki', 'Guyam Munti', 'Harasan', 'Kayquit I', 'Kayquit II', 'Kayquit III', 'Kaytapos', 'Limbon', 'Lumampong Balagbag', 'Lumampong Halayhay', 'Mahabangkahoy Cerca', 'Mahabangkahoy Lejos', 'Mataas na Lupa', 'Pulo', 'Tambo Balagbag', 'Tambo Ilaya', 'Tambo Malaki', 'Tambo Kulit'],
    'General Mariano Alvarez': ['Aldiano Olaes', 'Barangay 1', 'Barangay 2', 'Barangay 3', 'Barangay 4', 'Benjamin Tirona', 'Bernardo Pulido', 'Epifanio Malia', 'Fiorello Calawit', 'Francisco De Castro', 'Francisco Reyes', 'Gavino Maderan', 'Gregoria De Jesus', 'Inocencio Salud', 'Jacinto Lumbreras', 'Kapitan Kua', 'Koronel Jose P. Elises', 'Macario Dacon', 'Marcelino Memije', 'Nicolasa Virata', 'Pantaleon Granados', 'Rafael Gonzales', 'Ramon Cruz', 'San Gabriel', 'San Jose', 'Severino De Las Alas', 'Tiniente Tiago'],
    'Carmona': ['Barangay 1', 'Barangay 2', 'Barangay 3', 'Barangay 4', 'Barangay 5', 'Barangay 6', 'Barangay 7', 'Barangay 8', 'Bancal', 'Cabilang Baybay', 'Lantic', 'Lantik', 'Mabuhay', 'Maduya', 'Milagrosa', 'Poblacion 1A', 'Poblacion 1B', 'Poblacion 1C', 'Poblacion 1D', 'Poblacion 2A', 'Poblacion 2B', 'Poblacion 3A', 'Poblacion 3B'],
    'Alfonso': ['Amuyong', 'Bilog', 'Buck Estate', 'Kaysuyo', 'Luksuhin', 'Mangas I', 'Mangas II', 'Marahan I', 'Marahan II', 'Pajo', 'Poblacion I', 'Poblacion II', 'Sikat', 'Sulsugin', 'Taywanak', 'Upli'],
    'Magallanes': ['Barangay I', 'Barangay II', 'Barangay III', 'Barangay IV', 'Bend', 'Bombon', 'Crispin Balagtas', 'Kabilang Baybay', 'Medina', 'Pacheco', 'Ramirez', 'San Agustin', 'Tua', 'Urdaneta'],
    'Maragondon': ['Barangay I', 'Barangay II', 'Barangay III', 'Barangay IV', 'Barangay V', 'Bucal I', 'Bucal II', 'Bucal III', 'Bucal IV-A', 'Bucal IV-B', 'Cabooan', 'Caingin', 'Garita I-A', 'Garita I-B', 'Layong Mabilog', 'Mabato', 'Pantihan I', 'Pantihan II', 'Pantihan III', 'Pantihan IV', 'Patungan', 'Pinagsanhan A', 'Pinagsanhan B', 'Poblacion I-A', 'Poblacion I-B', 'Poblacion II-A', 'Poblacion II-B', 'San Miguel I-A', 'San Miguel I-B', 'San Miguel II-A', 'San Miguel II-B', 'Talipusngo', 'Tulay Kanluran', 'Tulay Silangan'],
    'Mendez': ['Anuling Cerca I', 'Anuling Cerca II', 'Anuling Lejos I', 'Anuling Lejos II', 'Banayad', 'Bukal', 'Galicia I', 'Galicia II', 'Galicia III', 'Poblacion I', 'Poblacion II', 'Poblacion III'],
    'Ternate': ['Poblacion I-A', 'Poblacion I-B', 'Poblacion II', 'Poblacion III', 'San Jose', 'San Juan I', 'San Juan II', 'Sapang I', 'Sapang II']
};

let currentStep = 1;

document.addEventListener('DOMContentLoaded', function() {
    renderProducts('all');
    setupButtons();
    setupFilters();
    setupProgressNavigation();
    
    // Set minimum date to today for pickup date
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('pickupDate').setAttribute('min', today);

    initializePreorderAddressSection().catch((error) => {
        console.error('Failed to initialize preorder address section:', error);
        initializePreorderFallbackAddressOptions();
        updateBarangays(preferredBarangay || '').catch(() => {});
        syncPreorderAddressFields();
        Swal.fire('Warning', 'Address reference data is unavailable right now. Please refresh and try again.', 'warning');
    });

    const mapSearchInput = document.getElementById('preorderAddressSearch');
    if (mapSearchInput && userAddressSeed) {
        mapSearchInput.value = userAddressSeed;
    }
    const streetInputSeed = document.getElementById('streetAddress');
    if (streetInputSeed && !streetInputSeed.value && preferredStreet) {
        streetInputSeed.value = preferredStreet;
    }

    if (!preorderGoogleMapsApiKey) {
        const mapCanvas = document.getElementById('preorderMap');
        if (mapCanvas) {
            mapCanvas.innerHTML = '<div style="padding:16px;color:#991b1b;font-size:14px;">Google Maps is unavailable because API key is missing.</div>';
        }
        const useMyLocationBtnNoKey = document.getElementById('preorderUseMyLocation');
        if (useMyLocationBtnNoKey) {
            useMyLocationBtnNoKey.disabled = true;
        }
    }

    const useMyLocationBtn = document.getElementById('preorderUseMyLocation');
    if (useMyLocationBtn) {
        useMyLocationBtn.addEventListener('click', () => {
            if (!navigator.geolocation) {
                Swal.fire('Location Unavailable', 'Your browser does not support geolocation.', 'warning');
                return;
            }

            if (!preorderMap || !preorderMarker) {
                if (window.google && window.google.maps) {
                    initPreorderMap();
                }
                if (!preorderMap || !preorderMarker) {
                    Swal.fire('Map Loading', 'Please wait for the map to load, then try again.', 'info');
                    return;
                }
            }

            useMyLocationBtn.disabled = true;
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const coords = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };
                    preorderMap.setCenter(coords);
                    preorderMap.setZoom(17);
                    preorderMarker.setPosition(coords);
                    updatePreorderAddressFromCoordinates(coords.lat, coords.lng);
                    useMyLocationBtn.disabled = false;
                },
                () => {
                    Swal.fire('Location Error', 'Unable to get your current location. Please allow location access.', 'warning');
                    useMyLocationBtn.disabled = false;
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        });
    }

    if (window.google && window.google.maps) {
        initPreorderMap();
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
    
    const filtered = category === 'all' ? products : products.filter(p => p.category === category);

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
        
        // Check if in cart
        const inCart = cart.find(i => i.id === p.id);
        const qty = inCart ? inCart.quantity : 0;
        const btnText = qty > 0 ? `Added (${qty})` : 'Add to Order';
        const btnClass = qty > 0 ? 'btn-success' : 'btn-outline';
        
        return `
        <div class="product-card ${qty > 0 ? 'selected' : ''}">
            <div class="product-image">${imageHtml}</div>
            <div class="product-info">
                <h4>${p.name}</h4>
                <p class="product-price">₱${p.price.toLocaleString()}</p>
                <p class="product-desc">${p.description || 'Premium quality lechon'}</p>
                <button type="button" class="btn btn-sm ${btnClass} add-cart-btn" onclick="toggleCartItem(${p.id})">${btnText}</button>
            </div>
        </div>
    `}).join('');
}

function toggleCartItem(id) {
    const product = products.find(p => p.id === id);
    if (!product) return;
    
    const existingItem = cart.find(i => i.id === id);
    
    if (existingItem) {
        // If already in cart, increment quantity
        existingItem.quantity++;
    } else {
        cart.push({ ...product, quantity: 1 });
    }

    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Added to cart',
        showConfirmButton: false,
        timer: 1500
    });
    
    updateCartUI();
    // Re-render to update button state
    const activeBtn = document.querySelector('.category-link.active');
    renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
}

function updateCartItemQty(id, change) {
    const idx = cart.findIndex(i => i.id === id);
    if (idx === -1) return;
    
    cart[idx].quantity += change;
    if (cart[idx].quantity <= 0) {
        cart.splice(idx, 1);
    }
    
    updateCartUI();
    // Re-render grid
    const activeBtn = document.querySelector('.category-link.active');
    renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
}

function updateCartUI() {
    const container = document.getElementById('preorderCartItems');
    const totalDisplay = document.getElementById('cartTotalDisplay');
    const countBadge = document.getElementById('cartCountBadge');
    const clearBtn = document.getElementById('clearCartBtn');

    if (!container) return; // Safety check
    
    const totals = getPreorderTotals();
    const itemCount = cart.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
    
    if (cart.length === 0) {
        container.innerHTML = '<p class="empty-cart-msg">No items selected yet.</p>';
        if(countBadge) countBadge.style.display = 'none';
        if(clearBtn) clearBtn.style.display = 'none';
    } else {
        if(countBadge) {
            countBadge.textContent = itemCount;
            countBadge.style.display = 'inline-block';
        }
        if(clearBtn) clearBtn.style.display = 'inline-block';

        const itemsHtml = cart.map(item => {
            let imageHtml = '<div class="cart-item-thumb-placeholder"><i class="fas fa-drumstick-bite"></i></div>';
            if (item.image && item.image !== 'default.jpg' && item.image !== '') {
                let imgSrc = item.image;
                if (!imgSrc.startsWith('http') && !imgSrc.includes('/')) {
                    imgSrc = 'images/menu/' + imgSrc;
                }
                imageHtml = `<img src="${imgSrc}" alt="${item.name}" class="cart-item-thumb">`;
            }

            return `
            <div class="cart-item-row">
                <div class="cart-item-image-col">${imageHtml}</div>
                <div class="cart-item-details-col">
                    <div class="cart-item-name">${item.name}</div>
                    <div class="cart-item-price-single">₱${item.price.toLocaleString()} each</div>
                    <div class="cart-item-controls">
                        <button type="button" class="tiny-btn" onclick="updateCartItemQty(${item.id}, -1)"><i class="fas fa-minus"></i></button>
                        <span class="qty-display">${item.quantity}</span>
                        <button type="button" class="tiny-btn" onclick="updateCartItemQty(${item.id}, 1)"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <div class="cart-item-total-col">
                    <div class="cart-item-price">₱${(item.price * item.quantity).toLocaleString()}</div>
                    <button type="button" class="remove-item-btn" onclick="removeFromCart(${item.id})" title="Remove item"><i class="fas fa-trash"></i></button>
                </div>
            </div>
        `;
        }).join('');
        
        container.innerHTML = itemsHtml;
    }
    
    totalDisplay.textContent = '₱' + totals.total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    updateSummary();
}

function setupProgressNavigation() {
    document.querySelectorAll('.progress-step').forEach(stepEl => {
        stepEl.addEventListener('click', function() {
            const targetStep = parseInt(this.dataset.step, 10);

            // Only allow navigation to steps that have been completed or are active.
            if (this.classList.contains('completed') || this.classList.contains('active')) {
                // No validation needed to go back to a completed step.
                goToStep(targetStep);
            } else {
                // To move forward, the user must use the "Next" button
                // which includes validation. We can give a small hint.
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

function clearCart() {
    cart = [];
    updateCartUI();
    const activeBtn = document.querySelector('.category-link.active');
    renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
}

function updateSummary() {
    const totals = getPreorderTotals();
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    
    document.getElementById('payItemCount').textContent = itemCount;
    const subtotalElement = document.getElementById('paySubtotal');
    const vatElement = document.getElementById('payVat');
    if (subtotalElement) subtotalElement.textContent = '₱' + totals.subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (vatElement) vatElement.textContent = '₱' + totals.vatAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('payTotal').textContent = '₱' + totals.total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function setupButtons() {
    document.querySelectorAll('.next-btn').forEach(btn => {
        btn.addEventListener('click', nextStep);
    });
    document.querySelectorAll('.prev-btn').forEach(btn => {
        btn.addEventListener('click', prevStep);
    });
}

function removeFromCart(id) {
    const idx = cart.findIndex(i => i.id === id);
    if (idx !== -1) {
        cart.splice(idx, 1);
        updateCartUI();
        const activeBtn = document.querySelector('.category-link.active');
        renderProducts(activeBtn ? activeBtn.dataset.category : 'all');
    }
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
}

function validateStep(step) {
    if (step === 1) {
        if (cart.length === 0) {
            Swal.fire('Error', 'Please select at least one product', 'warning');
            return false;
        }
    } else if (step === 2) {
        const fullName = document.getElementById('fullName').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const street = document.getElementById('streetAddress').value.trim();
        const regionCode = document.getElementById('preorderRegion').value;
        const province = document.getElementById('province').value;
        const city = document.getElementById('city').value;
        const barangay = document.getElementById('barangay').value;

        if (!fullName) {
            Swal.fire('Error', 'Please enter your full name', 'warning');
            return false;
        }
        if (!email) {
            Swal.fire('Error', 'Please enter your email', 'warning');
            return false;
        }
        if (!phone) {
            Swal.fire('Error', 'Please enter your phone number', 'warning');
            return false;
        }
        if (!street) {
            Swal.fire('Error', 'Please enter your street address', 'warning');
            return false;
        }
        if (!regionCode) {
            Swal.fire('Error', 'Please select a region', 'warning');
            return false;
        }
        if (!province) {
            Swal.fire('Error', 'Please select a province', 'warning');
            return false;
        }
        if (!city) {
            Swal.fire('Error', 'Please select a city', 'warning');
            return false;
        }
        if (!barangay) {
            Swal.fire('Error', 'Please select a barangay', 'warning');
            return false;
        }
        const pickupDate = document.getElementById('pickupDate').value;
        const pickupTime = document.getElementById('pickupTime').value;
        if (!pickupDate) {
            Swal.fire('Error', 'Please select a pickup/delivery date', 'warning');
            return false;
        }
        if (!pickupTime) {
            Swal.fire('Error', 'Please select a preferred time', 'warning');
            return false;
        }
    } else if (step === 3) {
        populateConfirmation();
    }
    return true;
}

function initializePreorderFallbackAddressOptions() {
    const regionSelect = document.getElementById('preorderRegion');
    const provinceSelect = document.getElementById('preorderProvince');
    const citySelect = document.getElementById('city');
    if (!regionSelect || !provinceSelect || !citySelect) return;

    preorderSetSelectOptions(regionSelect, [{ code: '040000000', name: 'CALABARZON' }], '-- Select Region --');
    regionSelect.value = '040000000';
    regionSelect.disabled = false;

    preorderSetSelectOptions(provinceSelect, [{ code: '042100000', name: 'Cavite' }], '-- Select Province --');
    provinceSelect.value = '042100000';
    provinceSelect.disabled = false;

    const fallbackCities = Object.keys(barangaysByCity).map((name) => ({ code: name, name }));
    preorderSetSelectOptions(citySelect, fallbackCities, '-- Select City/Municipality --');
    citySelect.disabled = false;

    if (preferredCity) {
        const cityOption = preorderFindOptionValueByName(citySelect, preferredCity);
        if (cityOption) {
            citySelect.value = cityOption;
        }
    }
}

async function updateBarangays(selectedBarangay = '') {
    const citySelect = document.getElementById('city');
    const brgySelect = document.getElementById('barangay');
    if (!citySelect || !brgySelect) return;

    const cityValue = citySelect.value || '';
    if (!cityValue) {
        preorderSetSelectOptions(brgySelect, [], '-- Select Barangay --');
        brgySelect.disabled = true;
        syncPreorderAddressFields();
        return;
    }

    const cityLooksLikePsgcCode = /^\d{6,12}$/.test(cityValue);
    if (cityLooksLikePsgcCode) {
        try {
            await loadPreorderBarangays(cityValue, selectedBarangay);
            return;
        } catch (error) {
            console.error('PSGC barangay load failed, using fallback list:', error);
        }
    }

    const fallbackCityName = preorderGetSelectedText(citySelect) || cityValue;
    const fallbackBarangays = Array.isArray(barangaysByCity[fallbackCityName]) ? barangaysByCity[fallbackCityName] : [];
    brgySelect.innerHTML = '<option value="">-- Select Barangay --</option>';
    fallbackBarangays.forEach((brgy) => {
        const option = document.createElement('option');
        option.value = brgy;
        option.textContent = brgy;
        brgySelect.appendChild(option);
    });
    brgySelect.disabled = fallbackBarangays.length === 0;

    if (selectedBarangay) {
        const hasMatch = Array.from(brgySelect.options).some((option) => option.value === selectedBarangay);
        if (!hasMatch) {
            const opt = document.createElement('option');
            opt.value = selectedBarangay;
            opt.textContent = selectedBarangay;
            brgySelect.appendChild(opt);
        }
        brgySelect.value = selectedBarangay;
    }

    syncPreorderAddressFields();
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
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);

    const fullName = document.getElementById('fullName').value;
    const email = document.getElementById('email').value;
    const phone = document.getElementById('phone').value;
    const street = document.getElementById('streetAddress').value;
    const city = document.getElementById('preorder_city_name').value || preorderGetSelectedText(document.getElementById('city'));
    const barangay = document.getElementById('preorder_barangay_name').value || preorderGetSelectedText(document.getElementById('barangay'));
    const province = document.getElementById('province').value;
    const pickupDate = document.getElementById('pickupDate').value;
    const pickupTime = document.getElementById('pickupTime').value;

    const address = `${street}, ${barangay}, ${city}, ${province}`;

    let itemsTable = `
        <table class="confirmation-table" style="width:100%; border-collapse: collapse; margin-bottom: 15px;">
            <thead>
                <tr style="border-bottom: 1px solid #ddd; background-color: #f8f9fa;">
                    <th style="text-align: left; padding: 10px; font-size: 0.9rem;">Product</th>
                    <th style="text-align: center; padding: 10px; font-size: 0.9rem;">Qty</th>
                    <th style="text-align: right; padding: 10px; font-size: 0.9rem;">Total</th>
                </tr>
            </thead>
            <tbody>
                ${cart.map(item => `
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px; font-size: 0.95rem;">${item.name}</td>
                        <td style="text-align: center; padding: 10px; font-size: 0.95rem;">${item.quantity}</td>
                        <td style="text-align: right; padding: 10px; font-size: 0.95rem;">₱${(item.price * item.quantity).toLocaleString()}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;

    let summaryHTML = `
        <h4 style="color: #c62828; margin-bottom: 20px;">Order Summary</h4>
        ${itemsTable}
        <div class="summary-row">
            <span><strong>Subtotal:</strong></span>
            <span>₱${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
        </div>
        <div class="summary-row">
            <span><strong>VAT (12%):</strong></span>
            <span>₱${vatAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
        </div>
        <div class="summary-row">
            <span><strong>Total (Incl. VAT):</strong></span>
            <span>₱${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
        </div>
        <hr style="margin: 15px 0; border: 1px solid #e0e0e0;">
        <h4 style="color: #c62828; margin: 20px 0;">Delivery Information</h4>
        <div class="summary-row">
            <span><strong>Full Name:</strong></span>
            <span>${fullName}</span>
        </div>
        <div class="summary-row">
            <span><strong>Email:</strong></span>
            <span>${email}</span>
        </div>
        <div class="summary-row">
            <span><strong>Phone:</strong></span>
            <span>${phone}</span>
        </div>
        <div class="summary-row">
            <span><strong>Delivery Address:</strong></span>
            <span>${address}</span>
        </div>
        <div class="summary-row">
            <span><strong>Pickup/Delivery Date:</strong></span>
            <span>${new Date(pickupDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
        </div>
        <div class="summary-row">
            <span><strong>Preferred Time:</strong></span>
            <span>${pickupTime}</span>
        </div>
        <hr style="margin: 15px 0; border: 1px solid #e0e0e0;">
        <h4 style="color: #c62828; margin: 20px 0;">Payment Details</h4>
        <div class="summary-row">
            <span><strong>Payment Type:</strong></span>
            <span>${paymentType === 'downpayment' ? '30% Downpayment' : 'Full Payment'}</span>
        </div>
        <div class="summary-row">
            <span><strong>Amount to Pay Now:</strong></span>
            <span style="color: #c62828; font-weight: bold;">₱${paymentAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
        </div>
    `;

    if (paymentType === 'downpayment') {
        summaryHTML += `
        <div class="summary-row">
            <span><strong>Remaining Balance:</strong></span>
            <span>₱${remaining.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
        </div>
        `;
    }

    document.getElementById('confirmSummary').innerHTML = summaryHTML;
}

document.getElementById('preorderForm').addEventListener('submit', function(e) {
    e.preventDefault();
    syncPreorderAddressFields();
    
    if (cart.length === 0) {
        Swal.fire('Error', 'Your cart is empty', 'error');
        return;
    }

    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing Payment...';

    const formData = {
        items: cart, // Send cart items array
        // Legacy fields for backward compatibility if backend check fails, though we will update backend
        full_name: document.getElementById('fullName').value,
        email: document.getElementById('email').value,
        phone: document.getElementById('phone').value,
        street_address: document.getElementById('streetAddress').value,
        province: document.getElementById('province').value,
        city: document.getElementById('preorder_city_name').value || preorderGetSelectedText(document.getElementById('city')),
        barangay: document.getElementById('preorder_barangay_name').value || preorderGetSelectedText(document.getElementById('barangay')),
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

    // Send to backend to create PayMongo session
    fetch('process_preorder_payment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(formData)
    })
    .then(response => {
        // #region agent log
        fetch('http://127.0.0.1:7242/ingest/877aa32d-157e-483d-b038-7c4939dc8ba5',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                sessionId:'debug-session',
                runId:'pre-fix-1',
                hypothesisId:'H1',
                location:'preorder.php:872',
                message:'Received response from process_preorder_payment.php',
                data:{status:response.status,statusText:response.statusText,url:response.url},
                timestamp:Date.now()
            })
        }).catch(()=>{});
        // #endregion

        // Always read and attempt to parse JSON, even if status != 200,
        // because the backend may still return a valid JSON body.
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
        console.log('Payment response:', data);
        // #region agent log
        fetch('http://127.0.0.1:7242/ingest/877aa32d-157e-483d-b038-7c4939dc8ba5',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                sessionId:'debug-session',
                runId:'pre-fix-1',
                hypothesisId:'H3',
                location:'preorder.php:888',
                message:'Parsed payment response JSON',
                data:{success:data.success,hasCheckoutUrl:!!data.checkout_url,error:data.error||null},
                timestamp:Date.now()
            })
        }).catch(()=>{});
        // #endregion
        
        if (data.success) {
            // Redirect to PayMongo checkout
            console.log('Redirecting to:', data.checkout_url);
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
        console.error('Full error:', error);
        // #region agent log
        fetch('http://127.0.0.1:7242/ingest/877aa32d-157e-483d-b038-7c4939dc8ba5',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                sessionId:'debug-session',
                runId:'pre-fix-1',
                hypothesisId:'H2',
                location:'preorder.php:903',
                message:'Fetch to process_preorder_payment.php failed',
                data:{errorMessage:error.message},
                timestamp:Date.now()
            })
        }).catch(()=>{});
        // #endregion
        
        let errorMessage = error.message;
        if (error.message.includes('JSON')) {
            errorMessage = 'Server response error. Please check the browser console (F12) for details.';
        }
        
        Swal.fire('Error', errorMessage, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
});
</script>
<?php if ($google_maps_api_key !== ''): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode($google_maps_api_key); ?>&libraries=places&callback=initPreorderMap" async defer></script>
<?php endif; ?>

<style>
:root {
    --primary-color: #c62828;
    --primary-dark: #b71c1c;
    --secondary-color: #ff9800;
    --text-main: #2d3436;
    --text-light: #636e72;
    --bg-light: #f8f9fa;
    --card-radius: 16px;
    --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    --shadow-sm: 0 4px 6px rgba(0,0,0,0.05);
    --shadow-md: 0 10px 20px rgba(0,0,0,0.08);
    --shadow-hover: 0 20px 40px rgba(0,0,0,0.12);
}

/* Page Header */
.page-header {
    background: linear-gradient(135deg, rgba(0,0,0,0.85), rgba(0,0,0,0.7)), url('images/menu-bg.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    color: white;
    text-align: center;
    padding: 160px 20px 100px;
    position: relative;
    margin-bottom: -60px;
    z-index: 1;
}

.page-header h1 {
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 15px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.3);
}

.page-header p {
    font-size: 1.2rem;
    opacity: 0.9;
    max-width: 600px;
    margin: 0 auto;
    font-weight: 300;
}

.preorder-section {
    padding-bottom: 80px;
    background-color: var(--bg-light);
    min-height: 80vh;
}

.preorder-container {
    background: white;
    border-radius: var(--card-radius);
    box-shadow: var(--shadow-md);
    padding: 40px;
    max-width: 900px;
    margin: 0 auto;
    position: relative;
    z-index: 2;
}

.preorder-container.expanded {
    max-width: 1200px;
}

/* Progress Bar */
.progress-bar-container {
    margin-bottom: 40px;
    padding: 0 20px;
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
    top: 20px;
    left: 0;
    right: 0;
    height: 4px;
    background: #eee;
    z-index: 0;
    border-radius: 4px;
}

.progress-step {
    position: relative;
    z-index: 1;
    text-align: center;
    width: 100px;
}

.progress-step-circle {
    width: 40px;
    height: 40px;
    background: white;
    border: 3px solid #ddd;
    color: #999;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    margin: 0 auto 10px;
    transition: var(--transition);
}

.progress-step.active .progress-step-circle {
    border-color: var(--primary-color);
    background: var(--primary-color);
    color: white;
    transform: scale(1.1);
    box-shadow: 0 0 0 5px rgba(198, 40, 40, 0.1);
}

.progress-step.completed .progress-step-circle {
    border-color: #4CAF50;
    background: #4CAF50;
    color: white;
}

.progress-step-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.progress-step.active .progress-step-label {
    color: var(--primary-color);
}

/* Steps Content */
.step-content {
    display: none;
    animation: fadeIn 0.4s ease;
}

.step-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.step-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 25px;
    text-align: center;
    position: relative;
    padding-bottom: 15px;
}

.step-title::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 3px;
    background: var(--primary-color);
    border-radius: 3px;
}

/* Product Grid */
#productList {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

/* Category Navigation (Same as menu.php) */
.category-nav {
    background-color: rgba(255, 255, 255, 0.95);
    padding: 15px 20px;
    border-radius: 100px;
    box-shadow: var(--shadow-md);
    margin: 0 auto 30px;
    max-width: 100%;
    position: sticky;
    top: 90px;
    z-index: 99;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.2);
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
    cursor: pointer;
}

.category-link:hover,
.category-link.active {
    background: var(--primary-color);
    color: white;
    box-shadow: 0 4px 10px rgba(198, 40, 40, 0.3);
}

.preorder-cart-section {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    padding: 20px;
    margin-top: 20px;
}

.cart-items-container {
    max-height: 200px;
    overflow-y: auto;
    margin: 15px 0;
}

/* Enhanced Cart Section */
.cart-item-row {
    display: flex;
    align-items: center;
    padding: 10px;
    border-bottom: 1px solid #eee;
    background: white;
    border-radius: 8px;
    margin-bottom: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.cart-item-image-col {
    width: 50px;
    height: 50px;
    margin-right: 10px;
    flex-shrink: 0;
}

.cart-item-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 4px;
}

.cart-item-thumb-placeholder {
    width: 100%;
    height: 100%;
    background: #f0f0f0;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ccc;
}

.cart-item-details-col {
    flex-grow: 1;
}

.cart-item-name {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-main);
    margin-bottom: 2px;
}

.cart-item-price-single {
    font-size: 0.8rem;
    color: #888;
    margin-bottom: 5px;
}

.cart-item-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.tiny-btn {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 1px solid #ddd;
    background: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-main);
    font-size: 0.7rem;
    transition: all 0.2s;
}

.tiny-btn:hover {
    background: #f0f0f0;
    color: var(--primary-color);
    border-color: var(--primary-color);
}

.qty-display {
    font-weight: 600;
    font-size: 0.9rem;
    min-width: 20px;
    text-align: center;
}

.cart-item-total-col {
    text-align: right;
    margin-left: 10px;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: space-between;
    height: 50px;
}

.cart-item-price {
    font-weight: 700;
    color: var(--primary-color);
    font-size: 0.95rem;
}

.remove-item-btn {
    background: none;
    border: none;
    color: #ff5252;
    cursor: pointer;
    font-size: 0.9rem;
    padding: 4px;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.remove-item-btn:hover {
    opacity: 1;
}

.product-card {
    border: 2px solid #eee;
    border-radius: 12px;
    padding: 0;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
    background: white;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-md);
    border-color: #ddd;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 0.85rem;
    min-width: auto;
}

.product-image {
    height: 160px;
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
    transition: transform 0.5s;
}

.product-card:hover .product-image img {
    transform: scale(1.05);
}

.product-image i {
    font-size: 3rem;
    color: #ddd;
}

.product-info {
    padding: 15px;
    text-align: center;
}

.product-info h4 {
    margin: 0 0 5px;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-main);
}

.product-price {
    color: var(--primary-color);
    font-weight: 700;
    font-size: 1.1rem;
    margin-bottom: 5px;
}

.check-icon {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 25px;
    height: 25px;
    background: var(--primary-color);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    opacity: 0;
    transform: scale(0);
    transition: var(--transition);
}

.product-card.selected .check-icon {
    opacity: 1;
    transform: scale(1);
}

input:focus, select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.1);
    background: white;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-row.full {
    grid-template-columns: 1fr;
}

/* Quantity Selector */
.quantity-selector {
    display: flex;
    align-items: center;
    max-width: 160px;
    border: 1px solid #ddd;
    border-radius: 50px;
    overflow: hidden;
}

.qty-btn {
    width: 40px;
    height: 40px;
    background: #f8f9fa;
    border: none;
    cursor: pointer;
    font-size: 1.2rem;
    color: var(--text-main);
    transition: background 0.2s;
}

.qty-btn:hover {
    background: #eee;
}

.quantity-selector input {
    width: 100%;
    text-align: center;
    border: none;
    font-weight: 700;
    font-size: 1.1rem;
    padding: 0;
}

/* Summary Box */
.summary-box {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 25px;
    margin: 30px 0;
    border: 1px solid #eee;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 1rem;
    color: var(--text-light);
}

.summary-row.total {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px dashed #ddd;
    font-weight: 800;
    color: var(--text-main);
    font-size: 1.3rem;
}

#summaryTotal, #payTotal {
    color: var(--primary-color);
}

/* Payment Option Cards */
.payment-options-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.payment-option-card {
    border: 2px solid #eee;
    border-radius: 12px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    display: block;
}

.payment-option-card:hover {
    border-color: #ddd;
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.payment-option-card.selected {
    border-color: var(--primary-color);
    background-color: #fff9f9;
}

.payment-option-card input {
    display: none;
}

.option-content {
    text-align: center;
}

.option-content i {
    font-size: 2rem;
    color: var(--primary-color);
    margin-bottom: 10px;
}

.option-content h4 {
    margin: 0 0 5px;
    color: var(--text-main);
}

.option-content p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-light);
}

/* Buttons */
.button-group {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    gap: 15px;
}

.btn {
    padding: 14px 30px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    border: none;
    min-width: 140px;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    box-shadow: 0 4px 15px rgba(198, 40, 40, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(198, 40, 40, 0.4);
}

.btn-secondary {
    background: #e9ecef;
    color: var(--text-main);
}

.btn-secondary:hover {
    background: #dee2e6;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header h1 { font-size: 2.2rem; }
    .preorder-container { padding: 25px; }
    .form-row { grid-template-columns: 1fr; gap: 0; }
    .progress-step-label { font-size: 0.7rem; }
    .button-group { flex-direction: column-reverse; }
    .btn { width: 100%; }
    .payment-options-grid { grid-template-columns: 1fr; }
}

/* Modern Food Preorder Refresh */
:root {
    --pre-red: #b3261e;
    --pre-orange: #ef6b2e;
    --pre-cream: #fff8ef;
    --pre-ink: #2a211d;
    --pre-muted: #7b6d64;
    --pre-border: #efdcca;
    --pre-shadow: 0 18px 36px rgba(74, 32, 20, 0.12);
}

body {
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.1), transparent 32%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.12), transparent 30%),
        var(--pre-cream);
}

.page-header {
    padding: 128px 20px 84px;
    margin-bottom: 0;
    background:
        linear-gradient(130deg, rgba(17, 11, 8, 0.86), rgba(45, 22, 14, 0.73)),
        url('images/stores-bg.jpg') center/cover no-repeat;
}

.page-header h1 {
    letter-spacing: -0.03em;
}

.page-header p {
    color: #f8e6d7;
}

.preorder-section {
    padding-top: 42px;
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
}

.preorder-container {
    border: 1px solid var(--pre-border);
    border-radius: 24px;
    box-shadow: var(--pre-shadow);
    background: #fffefc;
}

.checkout-type-switch {
    border: 1px solid #efdbc9;
    border-radius: 16px;
    padding: 14px;
    background: #fff7ee;
    margin-bottom: 18px;
}

.checkout-type-title {
    margin: 0 0 8px;
    color: #3d2b22;
    font-weight: 700;
    font-size: 0.95rem;
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
    padding: 9px 12px;
    border-radius: 999px;
    border: 1px solid #d9b79c;
    background: #fff;
    color: #7a2e21;
    font-weight: 700;
    transition: all 0.2s ease;
}

.checkout-type-chip:hover {
    background: #fff2e6;
    border-color: #c98f65;
}

.checkout-type-chip.is-active {
    background: #c62828;
    border-color: #c62828;
    color: #fff;
    cursor: default;
    pointer-events: none;
}

.checkout-type-note {
    margin: 10px 0 0;
    color: #6f5f53;
    font-size: 0.85rem;
}

.progress-bar-container {
    border: 1px solid #efdbc9;
    border-radius: 18px;
    background: #fff6ec;
    padding: 18px 18px 12px;
}

.progress-step-circle {
    border: 2px solid #e9c9b2;
    color: #8f2f20;
    background: #fff;
}

.progress-step.active .progress-step-circle,
.progress-step.completed .progress-step-circle {
    border-color: transparent;
    background: linear-gradient(135deg, var(--pre-red), var(--pre-orange));
    color: #fff;
}

.step-content {
    border: 1px solid #f2e4d6;
    border-radius: 18px;
    background: #fff;
    padding: 24px;
}

.step-title {
    color: var(--pre-ink);
    letter-spacing: -0.01em;
}

.tenant-scope-note {
    margin: -6px 0 16px;
    padding: 10px 12px;
    border: 1px solid #eed8c7;
    border-radius: 10px;
    background: #fff8ef;
    color: #5f4b40;
    font-size: 0.9rem;
}

.autofill-note {
    margin: -4px 0 14px;
    color: #6b5a4f;
    font-size: 0.88rem;
}

.autofill-note i {
    color: #b35b2f;
    margin-right: 6px;
}

.empty-product-note {
    margin: 0;
    padding: 14px;
    border: 1px dashed #e5cdb7;
    border-radius: 12px;
    background: #fff9f1;
    color: #6f5d52;
}

.category-nav {
    border: 1px solid #ecd6c2;
    border-radius: 14px;
    background: #fff8ef;
}

.category-link {
    border: 1px solid #e7ceba;
    border-radius: 999px;
    background: #fff;
    color: #7f3829;
}

.category-link.active,
.category-link:hover {
    background: linear-gradient(135deg, var(--pre-red), var(--pre-orange));
    color: #fff;
    border-color: transparent;
}

.product-card {
    border: 1px solid var(--pre-border);
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(74, 32, 20, 0.08);
}

.product-card:hover {
    box-shadow: 0 16px 30px rgba(74, 32, 20, 0.13);
}

.product-image {
    background: linear-gradient(135deg, #fff3e4, #ffe8d3);
}

.product-price {
    color: #9f3322;
}

.preorder-cart-section,
.summary-box {
    background: #fff8ef;
    border: 1px solid var(--pre-border);
    border-radius: 16px;
}

.cart-item-row {
    background: #fff;
    border: 1px solid #efddcc;
    border-radius: 10px;
    padding: 10px;
}

.payment-option-card {
    border: 1px solid var(--pre-border);
    border-radius: 14px;
    background: #fff;
}

.payment-option-card.selected {
    background: #fff4e7;
    border-color: #d79f79;
}

input,
select,
textarea {
    border: 1px solid #e8d4c2;
    border-radius: 10px;
    background: #fffefc;
}

input:focus,
select:focus,
textarea:focus {
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
}

.btn-primary {
    background: linear-gradient(135deg, var(--pre-red), var(--pre-orange));
    box-shadow: 0 12px 25px rgba(179, 38, 30, 0.26);
}

.btn-primary:hover {
    box-shadow: 0 15px 30px rgba(179, 38, 30, 0.34);
}

.btn-secondary {
    border: 1px solid #dbc6b5;
    background: #fff;
    color: #4a3a32;
}

.btn-secondary:hover {
    background: #f7ede4;
}

@media (max-width: 768px) {
    .checkout-type-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .checkout-type-chip {
        justify-content: center;
    }
}
</style>
<?php include 'includes/footer.php'; ?>

