<?php
session_start();
require_once 'includes/config.php';
require_once __DIR__ . '/includes/favorites_helper.php';

if (!isLoggedIn()) {
    requireLogin();
}

if (!favoritesIsCustomerUserSession()) {
    header('Location: index.php');
    exit;
}

$current_page = 'favorites';
$page_title = 'My Favorites';
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!favoritesEnsureTable($conn)) {
    $favorite_store_keys = [];
    $favorite_product_ids = [];
} else {
    $favorite_store_keys = array_keys(favoritesFetchUserFavoriteStoreKeyMap($conn, $user_id));
    $favorite_product_ids = array_map('intval', array_keys(favoritesFetchUserFavoriteProductIdMap($conn, $user_id)));
}

function favoriteAssetPath($path, $fallback = 'images/store-bg.jpg') {
    $path = trim((string)$path);
    if ($path !== '' && (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0)) return $path;
    if ($path !== '' && strpos($path, '/') === false) {
        $path = 'images/menu/' . $path;
    }
    if ($path !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/')))) {
        return $path;
    }
    return $fallback;
}

$favorite_stores = [];
$store_lookup_cache = [];
foreach ($favorite_store_keys as $raw_key) {
    $store_key = favoritesNormalizeStoreKey($raw_key);
    if ($store_key === '') continue;

    $card = [
        'key' => $store_key,
        'name' => 'Saved store',
        'subtitle' => 'Marketplace storefront',
        'location' => 'Lechon Delights Marketplace',
        'link' => 'index.php#marketplaceStores',
        'image' => 'images/store-bg.jpg'
    ];

    if (isset($store_lookup_cache[$store_key])) {
        $card = $store_lookup_cache[$store_key];
    } elseif (preg_match('/^seller-(\d+)$/', $store_key, $matches)) {
        $seller_id = (int)$matches[1];
        $sql = "SELECT
                    COALESCE(NULLIF(TRIM(business_name), ''), full_name) AS store_name,
                    address
                FROM users
                WHERE id = ?
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $seller_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            if ($result) mysqli_free_result($result);
            mysqli_stmt_close($stmt);
            if ($row) {
                $card['name'] = trim((string)($row['store_name'] ?? 'Seller Store'));
                $card['location'] = trim((string)($row['address'] ?? 'Marketplace storefront'));
                $card['subtitle'] = 'Partner store';
                $card['link'] = 'menu.php?seller_id=' . $seller_id;
            }
        }

        $image_stmt = mysqli_prepare($conn, "SELECT image FROM products WHERE seller_id = ? AND is_archived = 0 AND is_active = 1 ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($image_stmt) {
            mysqli_stmt_bind_param($image_stmt, "i", $seller_id);
            mysqli_stmt_execute($image_stmt);
            $image_result = mysqli_stmt_get_result($image_stmt);
            $image_row = $image_result ? mysqli_fetch_assoc($image_result) : null;
            if ($image_result) mysqli_free_result($image_result);
            mysqli_stmt_close($image_stmt);
            if ($image_row && !empty($image_row['image'])) {
                $card['image'] = favoriteAssetPath((string)$image_row['image'], 'images/store-bg.jpg');
            }
        }
    } elseif (preg_match('/^branch-(\d+)$/', $store_key, $matches)) {
        $branch_id = (int)$matches[1];
        $stmt = mysqli_prepare($conn, "SELECT store_name, address, city, province FROM store_locations WHERE store_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $branch_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            if ($result) mysqli_free_result($result);
            mysqli_stmt_close($stmt);
            if ($row) {
                $card['name'] = trim((string)($row['store_name'] ?? 'Pickup branch'));
                $card['location'] = trim(implode(', ', array_filter([
                    trim((string)($row['address'] ?? '')),
                    trim((string)($row['city'] ?? '')),
                    trim((string)($row['province'] ?? ''))
                ])));
                if ($card['location'] === '') $card['location'] = 'Pickup branch';
                $card['subtitle'] = 'Pickup branch';
                $card['link'] = 'locations.php';
            }
        }
    } elseif ($store_key === 'platform-main') {
        $card['name'] = 'Lechon Delights Marketplace';
        $card['subtitle'] = 'Marketplace favorite';
        $card['location'] = 'Main catalog';
        $card['link'] = 'menu.php';
        $card['image'] = 'images/hero-bg.jpg';
    }

    $store_lookup_cache[$store_key] = $card;
    $favorite_stores[] = $card;
}

$favorite_products = [];
$in_stock_favorites_count = 0;
if (!empty($favorite_product_ids)) {
    $id_list = implode(',', array_values(array_filter(array_map('intval', $favorite_product_ids), static function ($id) {
        return $id > 0;
    })));
    if ($id_list !== '') {
        $query = "SELECT
                    p.id,
                    p.name,
                    p.price,
                    p.image,
                    p.category,
                    p.avg_rating,
                    p.review_count,
                    p.seller_id,
                    p.is_available,
                    COALESCE(i.current_stock, p.stock, 0) AS current_stock,
                    COALESCE(NULLIF(TRIM(u.business_name), ''), 'Lechon Delights Kitchen') AS store_name
                  FROM products p
                  LEFT JOIN inventory i ON (i.product_id = p.id AND i.date = CURRENT_DATE())
                  LEFT JOIN users u ON p.seller_id = u.id
                  WHERE p.id IN ($id_list) AND p.is_archived = 0
                  ORDER BY FIELD(p.id, $id_list)";
        $result = mysqli_query($conn, $query);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $seller_id = (int)($row['seller_id'] ?? 0);
                $stock = max(0, (int)($row['current_stock'] ?? 0));
                $is_available = (int)($row['is_available'] ?? 1) === 1;
                $in_stock = ($stock > 0 && $is_available);
                if ($in_stock) {
                    $in_stock_favorites_count++;
                }

                $favorite_products[] = [
                    'id' => (int)$row['id'],
                    'name' => trim((string)($row['name'] ?? 'Product')),
                    'category' => trim((string)($row['category'] ?? 'Filipino favorites')),
                    'price' => (float)($row['price'] ?? 0),
                    'rating' => (float)($row['avg_rating'] ?? 0),
                    'reviews' => (int)($row['review_count'] ?? 0),
                    'store_name' => trim((string)($row['store_name'] ?? 'Marketplace')),
                    'in_stock' => $in_stock,
                    'stock' => $stock,
                    'menu_link' => $seller_id > 0 ? ('menu.php?seller_id=' . $seller_id) : 'menu.php',
                    'image' => favoriteAssetPath((string)($row['image'] ?? ''), 'images/menu/whole-lechon.jpg')
                ];
            }
            mysqli_free_result($result);
        }
    }
}

include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.favorites-page {
    padding: 32px 0 140px;
    background: #f8f9fa;
    min-height: 85vh;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

.favorites-page .container {
    max-width: 1160px;
    margin: 0 auto;
    padding: 0 20px;
}

.favorites-head {
    margin-bottom: 24px;
    border-bottom: 1px solid #eaecf0;
    padding-bottom: 16px;
}

.favorites-head h1 {
    margin: 0 0 4px;
    font-size: 1.35rem;
    font-weight: 700;
    color: #101828;
    font-family: 'Outfit', sans-serif;
}

.favorites-head p {
    margin: 0;
    color: #667085;
    font-size: 0.88rem;
}

.favorite-section {
    margin-top: 24px;
}

.favorite-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.favorite-section-head h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #101828;
    font-family: 'Outfit', sans-serif;
}

.favorite-count {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    background: #f2f4f7;
    color: #344054;
    font-size: 0.76rem;
    font-weight: 600;
}

.favorite-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
}

.favorite-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    transition: all 0.15s ease;
}

.favorite-card:hover {
    border-color: #d0d5dd;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 24, 40, 0.08);
}

.favorite-cover {
    height: 140px;
    background-position: center;
    background-size: cover;
    position: relative;
}

.favorite-toggle {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 1px solid #fee4e2;
    background: #ffffff;
    color: #b3261e;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.15s ease;
}

.favorite-toggle:hover {
    transform: scale(1.08);
}

.favorite-toggle.is-active {
    color: #b3261e;
    background: #ffffff;
}

.favorite-body {
    padding: 14px 16px;
}

.favorite-subtitle {
    margin: 0 0 4px;
    font-size: 0.72rem;
    color: #b3261e;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.favorite-title {
    margin: 0 0 6px;
    font-size: 0.98rem;
    font-weight: 700;
    color: #101828;
    line-height: 1.35;
}

.favorite-meta {
    margin: 0 0 12px;
    color: #475467;
    font-size: 0.84rem;
}

.favorite-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    background: #ffffff;
    border: 1px solid #d0d5dd;
    color: #344054;
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s ease;
}

.favorite-link:hover {
    background: #f8f9fa;
    color: #101828;
    border-color: #98a2b3;
}

.favorite-empty {
    background: #ffffff;
    border: 1px dashed #eaecf0;
    border-radius: 12px;
    padding: 32px 24px;
    text-align: center;
    color: #667085;
    font-size: 0.88rem;
}

.favorite-empty i {
    font-size: 1.6rem;
    color: #98a2b3;
    margin-bottom: 8px;
    display: block;
}

.favorite-empty-text {
    margin-bottom: 12px;
}

.btn-empty-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    background: #b3261e;
    color: #ffffff;
    font-size: 0.82rem;
    font-weight: 600;
    text-decoration: none;
    transition: background-color 0.15s ease;
}

.btn-empty-action:hover {
    background: #981b15;
}
</style>

<div class="favorites-page">
    <div class="container">
        <div class="favorites-head">
            <h1>My Favorites</h1>
            <p>Keep your favorite shops and products in one place for faster re-ordering.</p>
        </div>

        <?php if ($in_stock_favorites_count > 0): ?>
            <div class="favorite-stock-reminder-banner" style="background: linear-gradient(135deg, #15803d 0%, #166534 100%); color: #ffffff; padding: 18px 24px; border-radius: 18px; margin-bottom: 24px; box-shadow: 0 8px 22px rgba(21, 128, 61, 0.2); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <div style="background: rgba(255,255,255,0.2); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        <h4 style="margin: 0 0 2px 0; font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1.08rem; color: #ffffff;">In-Stock Favorite Reminder! ⚡</h4>
                        <p style="margin: 0; font-size: 0.88rem; opacity: 0.95; color: #ffffff;">
                            Great news! <strong><?php echo $in_stock_favorites_count; ?> of your favorite dish<?php echo $in_stock_favorites_count > 1 ? 'es' : ''; ?></strong> <?php echo $in_stock_favorites_count > 1 ? 'are' : 'is'; ?> currently in stock. Order now before stock runs out!
                        </p>
                    </div>
                </div>
                <a href="#favoriteProductsSection" style="background: #ffffff; color: #15803d; padding: 8px 18px; border-radius: 999px; font-weight: 800; font-size: 0.84rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                    <i class="fas fa-cart-shopping"></i> View In-Stock Favorites
                </a>
            </div>
        <?php endif; ?>

        <section class="favorite-section" id="favoriteShopsSection">
            <div class="favorite-section-head">
                <h2>Favorite Shops</h2>
                <span class="favorite-count" id="favoriteShopsCount"><?php echo number_format(count($favorite_stores)); ?> saved</span>
            </div>
            <?php if (empty($favorite_stores)): ?>
                <div class="favorite-empty" id="favoriteShopsEmpty">
                    <i class="fas fa-store-slash"></i>
                    <div class="favorite-empty-text">No favorite shops yet. Browse partner stores and tap the heart icon to save them here.</div>
                    <a href="index.php#marketplaceStores" class="btn-empty-action"><i class="fas fa-store"></i> Browse Partner Stores</a>
                </div>
            <?php else: ?>
                <div class="favorite-grid" id="favoriteShopsGrid">
                    <?php foreach ($favorite_stores as $store): ?>
                        <article class="favorite-card" data-favorite-card data-favorite-type="store" data-favorite-key="<?php echo htmlspecialchars((string)$store['key']); ?>">
                            <div class="favorite-cover" style="background-image:url('<?php echo htmlspecialchars((string)$store['image']); ?>');">
                                <button
                                    type="button"
                                    class="favorite-toggle is-active"
                                    data-favorite-toggle="1"
                                    data-favorite-type="store"
                                    data-favorite-store-key="<?php echo htmlspecialchars((string)$store['key']); ?>"
                                    data-favorite-active="1"
                                    aria-pressed="true"
                                    title="Remove from favorites">
                                    <i class="fas fa-heart"></i>
                                </button>
                            </div>
                            <div class="favorite-body">
                                <p class="favorite-subtitle"><?php echo htmlspecialchars((string)$store['subtitle']); ?></p>
                                <h3 class="favorite-title"><?php echo htmlspecialchars((string)$store['name']); ?></h3>
                                <p class="favorite-meta"><?php echo htmlspecialchars((string)$store['location']); ?></p>
                                <a href="<?php echo htmlspecialchars((string)$store['link']); ?>" class="favorite-link"><i class="fas fa-arrow-right"></i> Open store</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="favorite-empty" id="favoriteShopsEmpty" style="display:none;">
                    <i class="fas fa-store-slash"></i>
                    <div class="favorite-empty-text">No favorite shops yet. Browse partner stores and tap the heart icon to save them here.</div>
                    <a href="index.php#marketplaceStores" class="btn-empty-action"><i class="fas fa-store"></i> Browse Partner Stores</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="favorite-section" id="favoriteProductsSection">
            <div class="favorite-section-head">
                <h2>Favorite Products</h2>
                <span class="favorite-count" id="favoriteProductsCount"><?php echo number_format(count($favorite_products)); ?> saved</span>
            </div>
            <?php if (empty($favorite_products)): ?>
                <div class="favorite-empty" id="favoriteProductsEmpty">
                    <i class="fas fa-utensils"></i>
                    <div class="favorite-empty-text">No favorite products yet. Open the menu and save the dishes you want to order again.</div>
                    <a href="menu.php" class="btn-empty-action"><i class="fas fa-book-open"></i> View Menu</a>
                </div>
            <?php else: ?>
                <div class="favorite-grid" id="favoriteProductsGrid">
                    <?php foreach ($favorite_products as $product): ?>
                        <article class="favorite-card" data-favorite-card data-favorite-type="product" data-favorite-key="<?php echo (int)$product['id']; ?>">
                            <div class="favorite-cover" style="background-image:url('<?php echo htmlspecialchars((string)$product['image']); ?>');">
                                <button
                                    type="button"
                                    class="favorite-toggle is-active"
                                    data-favorite-toggle="1"
                                    data-favorite-type="product"
                                    data-favorite-product-id="<?php echo (int)$product['id']; ?>"
                                    data-favorite-active="1"
                                    aria-pressed="true"
                                    title="Remove from favorites">
                                    <i class="fas fa-heart"></i>
                                </button>
                            </div>
                            <div class="favorite-body">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <p class="favorite-subtitle" style="margin: 0;"><?php echo htmlspecialchars((string)$product['category']); ?></p>
                                    <?php if ($product['in_stock']): ?>
                                        <span class="stock-badge in-stock" style="background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 999px; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fas fa-circle-check"></i> IN STOCK (<?php echo (int)$product['stock']; ?> left)
                                        </span>
                                    <?php else: ?>
                                        <span class="stock-badge out-stock" style="background: #fff1f2; color: #b3261e; border: 1px solid #fecdd3; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fas fa-circle-xmark"></i> OUT OF STOCK
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="favorite-title"><?php echo htmlspecialchars((string)$product['name']); ?></h3>
                                <p class="favorite-meta">
                                    <?php echo htmlspecialchars((string)$product['store_name']); ?> | ₱<?php echo number_format((float)$product['price'], 2); ?>
                                    <?php if ((float)$product['rating'] > 0): ?>
                                        | <?php echo number_format((float)$product['rating'], 1); ?>★
                                    <?php endif; ?>
                                </p>
                                <?php if ($product['in_stock']): ?>
                                    <a href="<?php echo htmlspecialchars((string)$product['menu_link']); ?>" class="favorite-link" style="background: #15803d; color: #ffffff; padding: 6px 14px; border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; margin-top: 8px;">
                                        <i class="fas fa-cart-plus"></i> Order Now
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars((string)$product['menu_link']); ?>" class="favorite-link"><i class="fas fa-arrow-right"></i> View in menu</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="favorite-empty" id="favoriteProductsEmpty" style="display:none;">
                    <i class="fas fa-utensils"></i>
                    <div class="favorite-empty-text">No favorite products yet. Open the menu and save the dishes you want to order again.</div>
                    <a href="menu.php" class="btn-empty-action"><i class="fas fa-book-open"></i> View Menu</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
document.addEventListener('favorites:changed', function (event) {
    const detail = event && event.detail ? event.detail : null;
    if (!detail || detail.isFavorite) {
        return;
    }

    const favoriteType = String(detail.favoriteType || '');
    const favoriteKey = String(detail.key || '');
    if (!favoriteType || !favoriteKey) return;

    document.querySelectorAll('[data-favorite-card]').forEach(function (card) {
        const cardType = String(card.dataset.favoriteType || '');
        const cardKey = String(card.dataset.favoriteKey || '');
        if (cardType === favoriteType && cardKey === favoriteKey) {
            card.remove();
        }
    });

    const shopsGrid = document.getElementById('favoriteShopsGrid');
    const productsGrid = document.getElementById('favoriteProductsGrid');
    const shopsCount = document.getElementById('favoriteShopsCount');
    const productsCount = document.getElementById('favoriteProductsCount');
    const shopsEmpty = document.getElementById('favoriteShopsEmpty');
    const productsEmpty = document.getElementById('favoriteProductsEmpty');

    const shopCards = shopsGrid ? shopsGrid.querySelectorAll('[data-favorite-card]').length : 0;
    const productCards = productsGrid ? productsGrid.querySelectorAll('[data-favorite-card]').length : 0;

    if (shopsCount) shopsCount.textContent = shopCards.toLocaleString() + ' saved';
    if (productsCount) productsCount.textContent = productCards.toLocaleString() + ' saved';
    if (shopsEmpty) shopsEmpty.style.display = shopCards > 0 ? 'none' : 'block';
    if (productsEmpty) productsEmpty.style.display = productCards > 0 ? 'none' : 'block';
});
</script>
<?php include 'includes/footer.php'; ?>

