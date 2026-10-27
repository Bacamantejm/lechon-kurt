<?php
session_start();

$current_page = 'cart';
$page_title = "Your Cart | Lechon Delights";

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function resolveCartImageSrc($imagePath) {
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '') {
        return 'images/menu/whole-lechon.jpg';
    }

    if (stripos($imagePath, 'http://') === 0 || stripos($imagePath, 'https://') === 0) {
        return $imagePath;
    }

    if (strpos($imagePath, '/') === false) {
        return 'images/menu/' . $imagePath;
    }

    return $imagePath;
}

$cart_items = [];
$subtotal = 0;
$total_quantity = 0;

foreach ($_SESSION['cart'] as $index => $item) {
    $price = (float)($item['price'] ?? 0);
    $quantity = (int)($item['quantity'] ?? 0);
    $item_total = $price * $quantity;

    $subtotal += $item_total;
    $total_quantity += $quantity;

    $cart_items[] = [
        'index' => $index,
        'name' => $item['name'] ?? 'Unknown Item',
        'image' => resolveCartImageSrc($item['image'] ?? ''),
        'price' => $price,
        'quantity' => $quantity,
        'size' => $item['size'] ?? 'Regular',
        'addons' => is_array($item['addons'] ?? null) ? $item['addons'] : [],
        'item_total' => $item_total
    ];
}

$delivery_option = $_SESSION['delivery_option'] ?? 'pickup';
$delivery_location = $_SESSION['delivery_location'] ?? 'metro_manila';
$delivery_fee = 0;
$current_delivery_quote = is_array($_SESSION['current_delivery_quote'] ?? null) ? $_SESSION['current_delivery_quote'] : [];

if ($delivery_option === 'delivery') {
    if (!empty($current_delivery_quote['success'])) {
        $delivery_fee = (float)($current_delivery_quote['fee'] ?? 0);
    } elseif (isset($_SESSION['delivery_fees'][$delivery_location]['fee'])) {
        $delivery_fee = (float)$_SESSION['delivery_fees'][$delivery_location]['fee'];
    }
}

$vat_rate = 0.12;
$vat_amount = round($subtotal * $vat_rate, 2);
$grand_total = $subtotal + $delivery_fee + $vat_amount;
$downpayment = round($grand_total * 0.30, 2);

include 'includes/header.php';
?>

<section class="cart-page">
    <div class="container">
        <div class="cart-page-header">
            <h1>Your Cart</h1>
            <p>Review your items and order summary before checkout.</p>
            <div class="cart-header-stats">
                <div class="cart-header-stat">
                    <span class="cart-header-stat-label">Items</span>
                    <strong id="cartHeaderItemCount"><?php echo count($cart_items); ?></strong>
                </div>
                <div class="cart-header-stat">
                    <span class="cart-header-stat-label">Quantity</span>
                    <strong id="cartHeaderQtyCount"><?php echo (int)$total_quantity; ?></strong>
                </div>
                <div class="cart-header-stat">
                    <span class="cart-header-stat-label">Grand Total</span>
                    <strong id="cartHeaderGrandTotal">PHP <?php echo number_format($grand_total, 2); ?></strong>
                </div>
            </div>
        </div>

        <div class="cart-page-layout">
            <div class="cart-items-panel">
                <div class="cart-panel-head">
                    <h2>Cart Items</h2>
                    <button type="button" class="btn-secondary" id="cartPageClearBtn">Clear Cart</button>
                </div>
                <p class="cart-panel-note">Quick tip: adjust quantities here and totals update instantly.</p>

                <div id="cartPageEmptyState" class="cart-empty-state" <?php echo !empty($cart_items) ? 'style="display:none;"' : ''; ?>>
                    <i class="fas fa-shopping-cart"></i>
                    <p>Your cart is empty.</p>
                    <a href="menu.php" class="btn-primary">Browse Menu</a>
                </div>

                <div id="cartPageItems" class="cart-page-items" <?php echo empty($cart_items) ? 'style="display:none;"' : ''; ?>>
                    <?php foreach ($cart_items as $item): ?>
                    <article class="cart-page-item" data-index="<?php echo (int)$item['index']; ?>">
                        <img class="cart-page-item-image"
                             src="<?php echo htmlspecialchars($item['image']); ?>"
                             alt="<?php echo htmlspecialchars($item['name']); ?>"
                             onerror="this.onerror=null;this.src='images/menu/whole-lechon.jpg';">
                        <div class="cart-page-item-details">
                            <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                            <?php if (!empty($item['size']) && $item['size'] !== 'Regular'): ?>
                            <p><strong>Size:</strong> <?php echo htmlspecialchars($item['size']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($item['addons'])): ?>
                            <p><strong>Add-ons:</strong> <?php echo htmlspecialchars(implode(', ', $item['addons'])); ?></p>
                            <?php endif; ?>
                            <p><strong>Unit Price:</strong> PHP <?php echo number_format($item['price'], 2); ?></p>
                            <div class="cart-page-qty">
                                <button type="button" class="qty-btn qty-minus" data-action="decrease" data-index="<?php echo (int)$item['index']; ?>">-</button>
                                <span><?php echo (int)$item['quantity']; ?></span>
                                <button type="button" class="qty-btn qty-plus" data-action="increase" data-index="<?php echo (int)$item['index']; ?>">+</button>
                            </div>
                        </div>
                        <div class="cart-page-item-right">
                            <div class="cart-page-item-total">PHP <?php echo number_format($item['item_total'], 2); ?></div>
                            <button type="button" class="remove-btn" data-action="remove" data-index="<?php echo (int)$item['index']; ?>">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <aside class="cart-summary-panel">
                <!-- Delivery Service Selection (Reference Screen 3) -->
                <div class="cart-delivery-choice-cards">
                    <label class="cart-deliv-card active" id="cartDelivCardStandard">
                        <input type="radio" name="cartDeliveryOption" value="delivery" checked>
                        <div class="deliv-card-info">
                            <i class="fas fa-motorcycle" style="color:#b3261e;"></i>
                            <span class="deliv-card-title">Standard Delivery, 25-35 Mins</span>
                            <span class="deliv-card-fee">Free</span>
                        </div>
                        <div class="deliv-radio-indicator"><i class="fas fa-check"></i></div>
                    </label>
                    <label class="cart-deliv-card" id="cartDelivCardExpress">
                        <input type="radio" name="cartDeliveryOption" value="pickup">
                        <div class="deliv-card-info">
                            <i class="fas fa-store" style="color:#175cd3;"></i>
                            <span class="deliv-card-title">Store Pick-up, 15 Mins</span>
                            <span class="deliv-card-fee">Free</span>
                        </div>
                        <div class="deliv-radio-indicator"><i class="fas fa-check"></i></div>
                    </label>
                </div>

                <h2>Order Summary</h2>
                
                <!-- Promo Voucher Input Box (Reference Screen 3) -->
                <div class="cart-promo-box">
                    <div class="cart-promo-input-wrap">
                        <i class="fas fa-ticket-alt" style="color:#98a2b3;"></i>
                        <input type="text" id="cartPromoCodeInput" placeholder="VOUCHER CODE">
                        <button type="button" class="cart-promo-apply-btn" onclick="applyCartPromoCode()">Apply</button>
                    </div>
                </div>

                <div class="summary-row">
                    <span>Items</span>
                    <span id="cartPageItemCount"><?php echo count($cart_items); ?></span>
                </div>
                <div class="summary-row">
                    <span>Total Quantity</span>
                    <span id="cartPageQtyCount"><?php echo (int)$total_quantity; ?></span>
                </div>
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="cartPageSubtotal">PHP <?php echo number_format($subtotal, 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>Delivery Fee</span>
                    <span id="cartPageDelivery">PHP <?php echo number_format($delivery_fee, 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>VAT (12%)</span>
                    <span id="cartPageVat">PHP <?php echo number_format($vat_amount, 2); ?></span>
                </div>
                <div class="summary-row grand">
                    <span>Grand Total</span>
                    <span id="cartPageGrandTotal">PHP <?php echo number_format($grand_total, 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>30% Downpayment</span>
                    <span id="cartPageDownpayment">PHP <?php echo number_format($downpayment, 2); ?></span>
                </div>

                <div class="summary-actions">
                    <a href="menu.php" class="btn-outline">Continue Shopping</a>
                    <div class="checkout-choice" style="margin-top: 12px;">
                        <a href="checkout.php?mode=<?php echo htmlspecialchars($_SESSION['delivery_option'] ?? 'delivery'); ?>" class="btn-primary" id="cartPageOrderNowBtn" data-checkout-link="1" style="width: 100%; text-align: center; justify-content: center; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fas fa-lock"></i> Proceed to Checkout
                        </a>
                    </div>
                    <p class="checkout-security-note">
                        <i class="fas fa-shield-alt"></i>
                        Secure checkout with real-time totals before payment.
                    </p>
                </div>
            </aside>
        </div>
    </div>

    <!-- Mobile Sticky Bottom Checkout Bar (Reference Screen 3) -->
    <div class="cart-mobile-sticky-bar" id="cartMobileStickyBar">
        <div class="cart-sticky-info">
            <span class="sticky-label">Total Amount</span>
            <strong class="sticky-price" id="cartStickyTotal">PHP <?php echo number_format($grand_total, 2); ?></strong>
        </div>
        <div class="cart-sticky-actions">
            <a href="checkout.php?mode=<?php echo htmlspecialchars($_SESSION['delivery_option'] ?? 'delivery'); ?>" class="cart-sticky-checkout-btn">
                Confirm Order <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<style>
.cart-page {
    padding: 48px 0 58px;
    background:
        radial-gradient(circle at 8% 0%, rgba(239, 107, 46, 0.11), transparent 34%),
        radial-gradient(circle at 92% 8%, rgba(179, 38, 30, 0.09), transparent 30%),
        #f7f5f2;
}

.cart-page-header {
    margin-bottom: 20px;
}

.cart-page-header h1 {
    margin: 0 0 8px;
    color: #2c241f;
}

.cart-page-header p {
    margin: 0;
    color: #6f635b;
}

.cart-header-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 16px;
}

.cart-header-stat {
    border: 1px solid #ead8c8;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.92);
    padding: 10px 12px;
    display: grid;
    gap: 3px;
}

.cart-header-stat-label {
    color: #7a6c63;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 700;
}

.cart-header-stat strong {
    color: #2f2721;
    font-size: 1rem;
}

.cart-page-layout {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
    gap: 20px;
    align-items: start;
}

.cart-items-panel,
.cart-summary-panel {
    background: #fff;
    border: 1px solid #ece5de;
    border-radius: 14px;
    padding: 18px;
}

.cart-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.cart-panel-note {
    margin: -4px 0 14px;
    color: #7b6b61;
    font-size: 0.86rem;
}

.cart-panel-head h2,
.cart-summary-panel h2 {
    margin: 0;
    font-size: 1.2rem;
    color: #2f2721;
}

.cart-page-items {
    display: grid;
    gap: 12px;
}

.cart-page-item {
    display: grid;
    grid-template-columns: 88px minmax(0, 1fr) auto;
    gap: 12px;
    border: 1px solid #eee8e2;
    border-radius: 12px;
    padding: 12px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.cart-page-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(65, 34, 22, 0.08);
}

.cart-page-item-image {
    width: 88px;
    height: 88px;
    border-radius: 10px;
    object-fit: cover;
    background: #f5f1eb;
}

.cart-page-item-details h3 {
    margin: 0 0 6px;
    font-size: 1rem;
    color: #2f2721;
}

.cart-page-item-details p {
    margin: 0 0 4px;
    color: #6d6058;
    font-size: 0.9rem;
}

.cart-page-qty {
    display: inline-flex;
    align-items: center;
    border: 1px solid #e5ddd4;
    border-radius: 999px;
    overflow: hidden;
    margin-top: 8px;
}

.qty-btn {
    border: none;
    background: #f8f5f0;
    width: 30px;
    height: 30px;
    font-size: 1rem;
    cursor: pointer;
    color: #3f342d;
}

.qty-btn:hover {
    background: #f0eae2;
}

.qty-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    background: #f6f3ef;
}

.cart-page-qty span {
    min-width: 34px;
    text-align: center;
    font-weight: 600;
    color: #3f342d;
}

.cart-page-item-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: space-between;
    gap: 10px;
}

.cart-page-item-total {
    font-weight: 700;
    color: #8f2f20;
}

.remove-btn {
    border: 1px solid #edd9ce;
    background: #fff7f4;
    color: #8f2f20;
    padding: 7px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.86rem;
}

.remove-btn:hover {
    background: #ffefe9;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 0;
    border-bottom: 1px solid #f1ebe4;
    color: #5e5148;
}

.summary-row.grand {
    font-weight: 700;
    color: #2f2721;
    border-bottom: 1px solid #e6dcd2;
    font-size: 1.02rem;
}

.summary-actions {
    display: grid;
    gap: 10px;
    margin-top: 14px;
}

.summary-actions .btn-outline,
.summary-actions .btn-primary,
.summary-actions .btn-secondary {
    text-align: center;
}

.checkout-choice {
    border: 1px solid #e8ded5;
    border-radius: 12px;
    background: #fcf9f5;
    padding: 12px;
}

.checkout-choice-title {
    margin: 0 0 8px;
    color: #3d322b;
    font-weight: 700;
}

.checkout-choice-buttons {
    display: grid;
    gap: 8px;
}

.checkout-choice-buttons a {
    display: block;
}

.checkout-choice-note {
    margin: 10px 0 0;
    color: #6c5f56;
    font-size: 0.84rem;
    line-height: 1.35;
}

.cart-empty-state {
    text-align: center;
    padding: 30px 10px 20px;
    color: #6c6158;
}

.cart-empty-state i {
    font-size: 2rem;
    margin-bottom: 10px;
    color: #c8b6a9;
}

.checkout-security-note {
    margin: 0;
    font-size: 0.83rem;
    color: #4f6f5d;
    background: #f2fbf5;
    border: 1px solid #d7efdd;
    border-radius: 10px;
    padding: 10px 12px;
}

.checkout-security-note i {
    margin-right: 6px;
}

.cart-summary-panel {
    position: sticky;
    top: 92px;
}

@media (max-width: 992px) {
    .cart-page-layout {
        grid-template-columns: 1fr;
    }

    .cart-summary-panel {
        position: static;
    }
}

/* Screen 3 Delivery Option Choice Cards */
.cart-delivery-choice-cards {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}
.cart-deliv-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border: 1px solid #e4e7ec;
    border-radius: 12px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
}
.cart-deliv-card input {
    display: none;
}
.deliv-card-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    color: #344054;
    font-weight: 600;
}
.deliv-card-fee {
    font-size: 0.76rem;
    color: #027a48;
    background: #ecfdf3;
    padding: 1px 7px;
    border-radius: 4px;
    font-weight: 700;
}
.deliv-radio-indicator {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 1.5px solid #d0d5dd;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    color: transparent;
    transition: all 0.2s ease;
}
.cart-deliv-card.active,
.cart-deliv-card:has(input:checked) {
    border-color: #b3261e;
    background: #fff9f9;
}
.cart-deliv-card.active .deliv-radio-indicator,
.cart-deliv-card:has(input:checked) .deliv-radio-indicator {
    background: #b3261e;
    border-color: #b3261e;
    color: #ffffff;
}

/* Screen 3 Promo Voucher Input */
.cart-promo-box {
    margin: 12px 0 16px 0;
}
.cart-promo-input-wrap {
    display: flex;
    align-items: center;
    background: #ffffff;
    border: 1px solid #d0d5dd;
    border-radius: 999px;
    padding: 4px 6px 4px 14px;
    gap: 8px;
}
.cart-promo-input-wrap input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 0.84rem;
    background: transparent;
    text-transform: uppercase;
    font-weight: 700;
    color: #101828;
}
.cart-promo-apply-btn {
    background: #b3261e;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 7px 16px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 0.2s ease;
    white-space: nowrap;
}
.cart-promo-apply-btn:hover {
    background: #981b15;
}

/* Screen 3 Sticky Bottom Mobile Checkout Bar */
.cart-mobile-sticky-bar {
    display: none;
}

@media (max-width: 640px) {
    .cart-page {
        padding: 16px 0 220px !important;
    }

    .cart-items-panel,
    .cart-summary-panel {
        padding: 14px;
        border-radius: 16px;
    }

    .cart-page-item {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;
        padding: 10px 12px !important;
    }

    .cart-page-item-image {
        width: 64px !important;
        height: 64px !important;
        flex: 0 0 64px !important;
        border-radius: 10px !important;
    }

    .cart-page-item-details {
        flex: 1 1 auto !important;
        min-width: 0 !important;
    }

    .cart-page-item-details h3 {
        font-size: 0.92rem !important;
        margin: 0 0 2px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .cart-page-item-details p {
        font-size: 0.78rem !important;
        margin: 0 0 2px !important;
    }

    .cart-page-item-right {
        grid-column: auto !important;
        flex-direction: column !important;
        align-items: flex-end !important;
        gap: 6px !important;
        margin-top: 0 !important;
        flex-shrink: 0 !important;
    }

    .cart-page-qty {
        margin-top: 0 !important;
    }

    .qty-btn {
        width: 26px !important;
        height: 26px !important;
        font-size: 0.85rem !important;
    }

    .cart-page-qty span {
        min-width: 24px !important;
        font-size: 0.8rem !important;
    }

    .cart-page-item-total {
        font-size: 0.92rem !important;
        font-weight: 800 !important;
    }

    .remove-btn {
        padding: 3px 8px !important;
        font-size: 0.74rem !important;
    }

    .cart-header-stats {
        grid-template-columns: 1fr;
    }

    .cart-mobile-sticky-bar {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        position: fixed !important;
        bottom: 64px !important;
        left: 0 !important;
        right: 0 !important;
        background: rgba(255, 255, 255, 0.98) !important;
        backdrop-filter: blur(14px) !important;
        -webkit-backdrop-filter: blur(14px) !important;
        border-top: 1px solid #eaecf0 !important;
        padding: 10px 16px !important;
        z-index: 1000 !important;
        box-shadow: 0 -4px 16px rgba(16, 24, 40, 0.08) !important;
    }

    .cart-sticky-info {
        display: flex !important;
        flex-direction: column !important;
        gap: 2px !important;
    }

    .cart-sticky-info .sticky-label {
        font-size: 0.72rem !important;
        color: #667085 !important;
        font-weight: 600 !important;
        text-transform: uppercase !important;
    }

    .cart-sticky-info .sticky-price {
        font-size: 1.15rem !important;
        font-weight: 800 !important;
        color: #b3261e !important;
        line-height: 1 !important;
    }

    .cart-sticky-checkout-btn {
        background: #b3261e !important;
        color: #ffffff !important;
        border-radius: 999px !important;
        padding: 10px 20px !important;
        font-size: 0.86rem !important;
        font-weight: 700 !important;
        text-decoration: none !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        box-shadow: 0 4px 12px rgba(179, 38, 30, 0.25) !important;
    }
}

/* ==========================================================================
   CART PAGE DARK THEME ENGINE
   ========================================================================== */
body.dark-mode .cart-page {
    background: #0f172a !important;
}

body.dark-mode .cart-page-header h1 {
    color: #f8fafc !important;
}

body.dark-mode .cart-page-header p {
    color: #94a3b8 !important;
}

body.dark-mode .cart-header-stat {
    background: #1e293b !important;
    border-color: #334155 !important;
}

body.dark-mode .cart-header-stat-label {
    color: #94a3b8 !important;
}

body.dark-mode .cart-header-stat strong {
    color: #f8fafc !important;
}

body.dark-mode .cart-items-panel,
body.dark-mode .cart-summary-panel {
    background: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25) !important;
}

body.dark-mode .cart-panel-head h2,
body.dark-mode .cart-summary-panel h2 {
    color: #f8fafc !important;
}

body.dark-mode .cart-panel-note {
    color: #94a3b8 !important;
}

body.dark-mode .cart-page-item {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .cart-page-item:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4) !important;
    border-color: #475569 !important;
}

body.dark-mode .cart-page-item-details h3 {
    color: #f8fafc !important;
}

body.dark-mode .cart-page-item-details p {
    color: #cbd5e1 !important;
}

body.dark-mode .cart-page-qty button {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .cart-page-qty button:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .cart-page-qty span {
    color: #f8fafc !important;
}

body.dark-mode .cart-page-item-total {
    color: #ef4444 !important;
}

body.dark-mode .summary-row {
    color: #cbd5e1 !important;
}

body.dark-mode .summary-row span:last-child {
    color: #f8fafc !important;
}

body.dark-mode .summary-row.grand span:last-child {
    color: #ef4444 !important;
}

body.dark-mode .checkout-choice {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .checkout-choice-title {
    color: #f8fafc !important;
}

body.dark-mode .checkout-choice-note {
    color: #94a3b8 !important;
}

body.dark-mode .checkout-security-note {
    color: #94a3b8 !important;
}

body.dark-mode .cart-empty-state {
    color: #94a3b8 !important;
}

body.dark-mode .cart-empty-state i {
    color: #64748b !important;
}

body.dark-mode .cart-empty-state p {
    color: #cbd5e1 !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cartItemsEl = document.getElementById('cartPageItems');
    const emptyStateEl = document.getElementById('cartPageEmptyState');
    const clearBtn = document.getElementById('cartPageClearBtn');
    const checkoutBtns = Array.from(document.querySelectorAll('[data-checkout-link="1"]'));

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(ch) { return map[ch]; });
    }

    function formatMoney(value) {
        return `PHP ${Number(value || 0).toFixed(2)}`;
    }

    function resolveImageSrc(imagePath) {
        if (!imagePath) return 'images/menu/whole-lechon.jpg';
        const trimmed = String(imagePath).trim();
        if (!trimmed) return 'images/menu/whole-lechon.jpg';
        if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) return trimmed;
        if (trimmed.includes('/')) return trimmed;
        return `images/menu/${trimmed}`;
    }

    function setSummary(data) {
        const subtotal = Number(data.subtotal || 0);
        const delivery = Number(data.delivery_fee || 0);
        const vat = subtotal * 0.12;
        const grandTotal = subtotal + delivery + vat;
        const downpayment = grandTotal * 0.30;
        const qtyCount = (data.items || []).reduce(function(sum, item) {
            return sum + Number(item.quantity || 0);
        }, 0);

        document.getElementById('cartPageItemCount').textContent = String((data.items || []).length);
        document.getElementById('cartPageQtyCount').textContent = String(qtyCount);
        document.getElementById('cartPageSubtotal').textContent = formatMoney(subtotal);
        document.getElementById('cartPageDelivery').textContent = formatMoney(delivery);
        document.getElementById('cartPageVat').textContent = formatMoney(vat);
        document.getElementById('cartPageGrandTotal').textContent = formatMoney(grandTotal);
        document.getElementById('cartPageDownpayment').textContent = formatMoney(downpayment);

        const stickyTotalEl = document.getElementById('cartStickyTotal');
        if (stickyTotalEl) stickyTotalEl.textContent = formatMoney(grandTotal);

        const headerItemCount = document.getElementById('cartHeaderItemCount');
        const headerQtyCount = document.getElementById('cartHeaderQtyCount');
        const headerGrandTotal = document.getElementById('cartHeaderGrandTotal');
        if (headerItemCount) headerItemCount.textContent = String((data.items || []).length);
        if (headerQtyCount) headerQtyCount.textContent = String(qtyCount);
        if (headerGrandTotal) headerGrandTotal.textContent = formatMoney(grandTotal);

        const hasItems = (data.items || []).length > 0;
        checkoutBtns.forEach(function(btn) {
            btn.style.pointerEvents = hasItems ? 'auto' : 'none';
            btn.style.opacity = hasItems ? '1' : '0.6';
        });

        ['cartBadge', 'floatingCartBadge'].forEach(function(id) {
            const badge = document.getElementById(id);
            if (badge) {
                badge.textContent = String(data.cart_count || 0);
            }
        });
    }

    function renderItems(data) {
        const items = data.items || [];
        setSummary(data);

        if (items.length === 0) {
            if (emptyStateEl) emptyStateEl.style.display = 'block';
            if (cartItemsEl) cartItemsEl.style.display = 'none';
            if (cartItemsEl) cartItemsEl.innerHTML = '';
            return;
        }

        if (emptyStateEl) emptyStateEl.style.display = 'none';
        if (cartItemsEl) cartItemsEl.style.display = 'grid';

        const html = items.map(function(item) {
            const addonsLine = item.addons && item.addons.length > 0
                ? `<p><strong>Add-ons:</strong> ${escapeHtml(item.addons.join(', '))}</p>`
                : '';
            const sizeLine = item.size && item.size !== 'Regular'
                ? `<p><strong>Size:</strong> ${escapeHtml(item.size)}</p>`
                : '';
            const availableStock = item.available_stock === null || typeof item.available_stock === 'undefined'
                ? null
                : Number(item.available_stock);
            const canIncrease = availableStock === null ? Number(item.quantity || 0) < 20 : Number(item.quantity || 0) < Math.min(20, availableStock);
            const plusDisabled = canIncrease ? '' : 'disabled';

            return `
                <article class="cart-page-item" data-index="${item.index}">
                    <img class="cart-page-item-image"
                         src="${escapeHtml(resolveImageSrc(item.image))}"
                         alt="${escapeHtml(item.name)}"
                         onerror="this.onerror=null;this.src='images/menu/whole-lechon.jpg';">
                    <div class="cart-page-item-details">
                        <h3>${escapeHtml(item.name)}</h3>
                        ${sizeLine}
                        ${addonsLine}
                        <p><strong>Unit Price:</strong> ${formatMoney(item.price)}</p>
                        <div class="cart-page-qty">
                            <button type="button" class="qty-btn qty-minus" data-action="decrease" data-index="${item.index}">-</button>
                            <span>${Number(item.quantity || 0)}</span>
                            <button type="button" class="qty-btn qty-plus" data-action="increase" data-index="${item.index}" ${plusDisabled}>+</button>
                        </div>
                    </div>
                    <div class="cart-page-item-right">
                        <div class="cart-page-item-total">${formatMoney((Number(item.price || 0) * Number(item.quantity || 0)))}</div>
                        <button type="button" class="remove-btn" data-action="remove" data-index="${item.index}">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </article>
            `;
        }).join('');

        if (cartItemsEl) {
            cartItemsEl.innerHTML = html;
        }
    }

    async function loadCart() {
        try {
            const response = await fetch('get_cart.php');
            const data = await response.json();
            renderItems(data);
        } catch (error) {
            console.error('Error loading cart:', error);
        }
    }

    async function postCartAction(action, index = null) {
        const body = new URLSearchParams({ action: action });
        if (index !== null) {
            body.append('index', String(index));
        }

        const response = await fetch('update_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });

        return response.json();
    }

    document.addEventListener('click', async function(e) {
        const actionBtn = e.target.closest('[data-action][data-index]');
        if (actionBtn && actionBtn.closest('.cart-page-item')) {
            const action = actionBtn.getAttribute('data-action');
            const index = actionBtn.getAttribute('data-index');
            try {
                const result = await postCartAction(action, index);
                await loadCart();
                if (result.success) {
                    document.dispatchEvent(new CustomEvent('cartUpdated'));
                    if (window.showToast) window.showToast('Cart updated', 'success');
                } else if (result.message) {
                    if (window.showToast) {
                        window.showToast(result.message, 'error');
                    } else {
                        alert(result.message);
                    }
                }
            } catch (error) {
                console.error('Error updating cart:', error);
            }
        }
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', async function() {
            const confirmed = await (window.swalConfirmAction
                ? window.swalConfirmAction({
                    title: 'Clear cart?',
                    text: 'This will remove all items from your cart.',
                    icon: 'warning',
                    confirmButtonText: 'Yes, clear cart',
                    confirmButtonColor: '#c62828',
                    cancelButtonColor: '#6c757d'
                })
                : Promise.resolve(confirm('Are you sure you want to clear your cart?')));
            if (!confirmed) {
                return;
            }
            try {
                const result = await postCartAction('clear');
                await loadCart();
                if (result.success) {
                    document.dispatchEvent(new CustomEvent('cartUpdated'));
                    if (window.showToast) window.showToast('Cart cleared', 'success');
                } else if (result.message) {
                    if (window.showToast) {
                        window.showToast(result.message, 'error');
                    } else {
                        alert(result.message);
                    }
                }
            } catch (error) {
                console.error('Error clearing cart:', error);
            }
        });
    }

    window.applyCartPromoCode = function() {
        const input = document.getElementById('cartPromoCodeInput');
        const code = input ? input.value.trim() : '';
        if (!code) {
            if (window.showToast) window.showToast('Please enter a voucher code', 'error');
            else alert('Please enter a voucher code');
            return;
        }
        if (window.showToast) window.showToast('Voucher "' + code + '" applied successfully!', 'success');
        else alert('Voucher applied!');
    };

    document.querySelectorAll('.cart-deliv-card').forEach(function(card) {
        card.addEventListener('click', function() {
            document.querySelectorAll('.cart-deliv-card').forEach(function(c) { c.classList.remove('active'); });
            this.classList.add('active');
            const radio = this.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });

    loadCart();
});
</script>

<?php include 'includes/footer.php'; ?>
