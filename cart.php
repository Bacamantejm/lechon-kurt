<?php
session_start();
header("Location: menu.php?open_cart=1");
exit;

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
                <h2>Order Summary</h2>
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
                    <div class="checkout-choice">
                        <p class="checkout-choice-title">Choose checkout type</p>
                        <div class="checkout-choice-buttons">
                            <a href="checkout.php" class="btn-primary" id="cartPageOrderNowBtn" data-checkout-link="1">
                                <i class="fas fa-bolt"></i> Order Now
                            </a>
                            <a href="<?php echo htmlspecialchars($preorder_checkout_link); ?>" class="btn-secondary" id="cartPagePreOrderBtn" data-checkout-link="1">
                                <i class="fas fa-calendar-alt"></i> Pre-Order
                            </a>
                        </div>
                        <p class="checkout-choice-note">Order now for ASAP delivery/pickup, or pre-order for a scheduled date and time.</p>
                    </div>
                    <p class="checkout-security-note">
                        <i class="fas fa-shield-alt"></i>
                        Secure checkout with real-time totals before payment.
                    </p>
                </div>
            </aside>
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

@media (max-width: 640px) {
    .cart-page {
        padding: 30px 0 44px;
    }

    .cart-items-panel,
    .cart-summary-panel {
        padding: 14px;
    }

    .cart-page-item {
        grid-template-columns: 70px minmax(0, 1fr);
    }

    .cart-page-item-image {
        width: 70px;
        height: 70px;
    }

    .cart-page-item-right {
        grid-column: 1 / -1;
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        margin-top: 4px;
    }

    .cart-header-stats {
        grid-template-columns: 1fr;
    }
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

    loadCart();
});
</script>

<?php include 'includes/footer.php'; ?>
