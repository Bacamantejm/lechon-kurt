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
                    COALESCE(NULLIF(TRIM(u.business_name), ''), 'Lechon Delights Kitchen') AS store_name
                  FROM products p
                  LEFT JOIN users u ON p.seller_id = u.id
                  WHERE p.id IN ($id_list) AND p.is_archived = 0
                  ORDER BY FIELD(p.id, $id_list)";
        $result = mysqli_query($conn, $query);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $seller_id = (int)($row['seller_id'] ?? 0);
                $favorite_products[] = [
                    'id' => (int)$row['id'],
                    'name' => trim((string)($row['name'] ?? 'Product')),
                    'category' => trim((string)($row['category'] ?? 'Filipino favorites')),
                    'price' => (float)($row['price'] ?? 0),
                    'rating' => (float)($row['avg_rating'] ?? 0),
                    'reviews' => (int)($row['review_count'] ?? 0),
                    'store_name' => trim((string)($row['store_name'] ?? 'Marketplace')),
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
.favorites-page {
    padding: 28px 0 60px;
}

.favorites-page .container {
    max-width: 1160px;
}

.favorites-head {
    margin-bottom: 16px;
}

.favorites-head h1 {
    margin: 0 0 10px;
    font-size: clamp(2rem, 3.2vw, 2.8rem);
}

.favorites-head p {
    margin: 0;
    color: #6b7280;
}

.favorite-section {
    margin-top: 28px;
}

.favorite-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.favorite-section-head h2 {
    margin: 0;
    font-size: 1.4rem;
}

.favorite-count {
    font-weight: 700;
    color: #64748b;
}

.favorite-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 14px;
}

.favorite-card {
    background: #fff;
    border: 1px solid #efdccf;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
}

.favorite-cover {
    height: 150px;
    background-position: center;
    background-size: cover;
    position: relative;
}

.favorite-toggle {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.95);
    color: #8b7e76;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.favorite-toggle.is-active {
    color: #b3261e;
    background: #fff1ea;
    border-color: #f4c8b1;
}

.favorite-body {
    padding: 14px 15px 16px;
}

.favorite-subtitle {
    margin: 0 0 6px;
    font-size: 0.82rem;
    color: #7c8798;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.favorite-title {
    margin: 0 0 8px;
    font-size: 1.1rem;
}

.favorite-meta {
    margin: 0 0 14px;
    color: #667085;
    font-size: 0.92rem;
}

.favorite-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    font-weight: 700;
    color: #111827;
}

.favorite-empty {
    background: #fff;
    border: 1px dashed #ebd9cb;
    border-radius: 18px;
    padding: 20px;
    color: #667085;
}
</style>

<div class="favorites-page">
    <div class="container">
        <div class="favorites-head">
            <h1>My Favorites</h1>
            <p>Keep your favorite shops and products in one place for faster re-ordering.</p>
        </div>

        <section class="favorite-section" id="favoriteShopsSection">
            <div class="favorite-section-head">
                <h2>Favorite Shops</h2>
                <span class="favorite-count" id="favoriteShopsCount"><?php echo number_format(count($favorite_stores)); ?> saved</span>
            </div>
            <?php if (empty($favorite_stores)): ?>
                <div class="favorite-empty" id="favoriteShopsEmpty">No favorite shops yet. Browse stores and tap the heart icon to save one.</div>
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
                <div class="favorite-empty" id="favoriteShopsEmpty" style="display:none;">No favorite shops yet. Browse stores and tap the heart icon to save one.</div>
            <?php endif; ?>
        </section>

        <section class="favorite-section" id="favoriteProductsSection">
            <div class="favorite-section-head">
                <h2>Favorite Products</h2>
                <span class="favorite-count" id="favoriteProductsCount"><?php echo number_format(count($favorite_products)); ?> saved</span>
            </div>
            <?php if (empty($favorite_products)): ?>
                <div class="favorite-empty" id="favoriteProductsEmpty">No favorite products yet. Open the menu and save the dishes you want to order again.</div>
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
                                <p class="favorite-subtitle"><?php echo htmlspecialchars((string)$product['category']); ?></p>
                                <h3 class="favorite-title"><?php echo htmlspecialchars((string)$product['name']); ?></h3>
                                <p class="favorite-meta">
                                    <?php echo htmlspecialchars((string)$product['store_name']); ?> | PHP <?php echo number_format((float)$product['price'], 2); ?>
                                    <?php if ((float)$product['rating'] > 0): ?>
                                        | <?php echo number_format((float)$product['rating'], 1); ?>★
                                    <?php endif; ?>
                                </p>
                                <a href="<?php echo htmlspecialchars((string)$product['menu_link']); ?>" class="favorite-link"><i class="fas fa-arrow-right"></i> View in menu</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="favorite-empty" id="favoriteProductsEmpty" style="display:none;">No favorite products yet. Open the menu and save the dishes you want to order again.</div>
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

