@extends('layouts.app')

@section('title', 'Menu & Order | Lechon Delights')

@push('styles')
<style>
/* 1:1 Menu Page Styling */
.menu-page-wrap {
    background: #f8f9fa;
    min-height: calc(100vh - 120px);
    padding-bottom: 80px;
}

/* Storefront Header */
.panda-storefront-header {
    background: #ffffff;
    border-bottom: 1px solid #eaecf0;
    padding: 24px 0;
    margin-bottom: 0;
}
.panda-storefront-header .container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.store-hero-info {
    display: flex;
    align-items: center;
    gap: 18px;
}
.store-hero-avatar {
    width: 68px;
    height: 68px;
    border-radius: 16px;
    background: linear-gradient(135deg, #b3261e, #ef6b2e);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    font-weight: 900;
    font-family: 'Outfit', sans-serif;
    box-shadow: 0 4px 14px rgba(179, 38, 30, 0.2);
}
.store-hero-details h1 {
    margin: 0 0 4px 0;
    font-size: 1.6rem;
    font-weight: 900;
    color: #171922;
}
.store-hero-meta {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 0.86rem;
    color: #64748b;
    font-weight: 600;
}
.store-hero-meta span { display: inline-flex; align-items: center; gap: 5px; }

/* Sticky Category Navigation Bar */
.panda-menu-sticky-bar {
    position: sticky;
    top: 64px;
    z-index: 1000;
    background: #ffffff;
    border-bottom: 1px solid #eaecf0;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
}
.panda-menu-sticky-bar .container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.panda-cat-strip-wrap {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    padding: 12px 0;
    scrollbar-width: none;
}
.panda-cat-strip-wrap::-webkit-scrollbar { display: none; }
.panda-cat-tab {
    padding: 8px 18px;
    border-radius: 999px;
    font-size: 0.88rem;
    font-weight: 700;
    color: #475467;
    text-decoration: none;
    white-space: nowrap;
    border: 1px solid transparent;
    transition: all 0.2s ease;
}
.panda-cat-tab:hover, .panda-cat-tab.active {
    background: #fff1f0;
    color: #b3261e;
    border-color: #fee4e2;
}

/* Layout Grid: Menu Content + Quick Order Side Stack */
.menu-main-layout {
    max-width: 1280px;
    margin: 24px auto 0;
    padding: 0 24px;
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 28px;
    align-items: start;
}

/* Category Dishes Section */
.menu-category-section {
    margin-bottom: 36px;
    scroll-margin-top: 140px;
}
.menu-category-title {
    font-size: 1.35rem;
    font-weight: 800;
    color: #171922;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.panda-food-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
}

.food-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.food-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}

.food-card-thumb {
    width: 100%;
    height: 140px;
    background-size: cover;
    background-position: center;
    position: relative;
}
.food-card-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(23, 25, 34, 0.85);
    color: #ffffff;
    font-size: 0.7rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 6px;
}

.food-card-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    flex: 1;
    justify-content: space-between;
}
.food-card-name {
    font-size: 1.05rem;
    font-weight: 800;
    color: #171922;
    margin: 0 0 6px 0;
    line-height: 1.2;
}
.food-card-desc {
    font-size: 0.82rem;
    color: #64748b;
    line-height: 1.4;
    margin-bottom: 12px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.food-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid #f2f4f7;
    padding-top: 12px;
    margin-top: auto;
}
.food-card-price {
    font-size: 1.15rem;
    font-weight: 900;
    color: #b3261e;
    font-family: 'Outfit', sans-serif;
}
.food-add-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
}
.food-add-btn:hover {
    background: #b3261e;
    color: #ffffff;
    border-color: #b3261e;
}

/* Quick Order Side Stack */
.quick-order-panel {
    position: sticky;
    top: 130px;
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
}
.quick-order-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 12px;
    margin-bottom: 16px;
}
.quick-order-tab {
    padding: 8px;
    border: none;
    background: transparent;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.85rem;
    color: #64748b;
    cursor: pointer;
}
.quick-order-tab.active {
    background: #ffffff;
    color: #171922;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}

.quick-order-hero {
    background: #fff1f0;
    border: 1px solid #fee4e2;
    border-radius: 14px;
    padding: 14px;
    text-align: center;
    margin-bottom: 16px;
}
.quick-order-hero h3 { margin: 0 0 2px; font-size: 0.95rem; font-weight: 800; color: #b3261e; }
.quick-order-hero p { margin: 0; font-size: 0.78rem; color: #7f1d1d; }

.quick-order-checkout-btn {
    width: 100%;
    padding: 14px;
    background: #b3261e;
    color: #ffffff;
    border: none;
    border-radius: 12px;
    font-weight: 800;
    font-size: 0.95rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background 0.2s ease;
}
.quick-order-checkout-btn:hover { background: #981b15; }

/* Customization Modal */
.product-preview-modal {
    position: fixed;
    inset: 0;
    background: rgba(16, 24, 40, 0.6);
    backdrop-filter: blur(4px);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.preview-modal-content {
    background: #ffffff;
    border-radius: 20px;
    max-width: 500px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.2);
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
}
.preview-close {
    position: absolute;
    top: 14px;
    right: 14px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: #f1f5f9;
    font-size: 1.2rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

.preview-size-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.preview-size-pill {
    padding: 8px 16px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    background: #ffffff;
}
.preview-size-pill.active {
    border-color: #b3261e;
    background: #fff1f0;
    color: #b3261e;
}

@media (max-width: 900px) {
    .menu-main-layout { grid-template-columns: 1fr; }
    .quick-order-panel { display: none; }
}
</style>
@endpush

@section('content')
<div class="menu-page-wrap">
    
    <!-- Storefront Header -->
    <section class="panda-storefront-header">
        <div class="container">
            <div class="store-hero-info">
                <div class="store-hero-avatar">
                    <i class="fas fa-piggy-bank"></i>
                </div>
                <div class="store-hero-details">
                    <h1>{{ $selectedStore->store_name ?? 'Lechon Delights Kitchen' }}</h1>
                    <div class="store-hero-meta">
                        <span><i class="fas fa-location-dot" style="color: #b3261e;"></i> {{ $selectedStore->city ?? 'Cavite' }}</span>
                        <span><i class="fas fa-star" style="color: #ef6b2e;"></i> 4.9 (120+ reviews)</span>
                        <span><i class="fas fa-clock" style="color: #027a48;"></i> 25–40 mins</span>
                    </div>
                </div>
            </div>

            <!-- Branch Switcher -->
            <div>
                <select onchange="window.location.href = this.value;" style="padding: 10px 14px; border: 1px solid #d0d5dd; border-radius: 10px; font-weight: 700; font-size: 0.85rem; outline: none; background: #ffffff;">
                    @foreach($storeLocations as $loc)
                        <option value="{{ route('menu', ['store_id' => $loc->store_id]) }}" {{ ($selectedStore->store_id ?? 0) == $loc->store_id ? 'selected' : '' }}>
                            {{ $loc->store_name }} ({{ $loc->city }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <!-- Sticky Category Navigation Bar -->
    <div class="panda-menu-sticky-bar">
        <div class="container">
            <div class="panda-cat-strip-wrap">
                @foreach($menuCategories as $catName => $items)
                    <a href="#cat-{{ Str::slug($catName) }}" class="panda-cat-tab">
                        {{ $catName }} ({{ count($items) }})
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Main Menu Layout -->
    <div class="menu-main-layout">
        
        <!-- Dishes Listing by Category -->
        <div>
            @foreach($menuCategories as $catName => $items)
                <div class="menu-category-section" id="cat-{{ Str::slug($catName) }}">
                    <h2 class="menu-category-title">
                        <i class="fas fa-utensils" style="color: #b3261e;"></i> {{ $catName }}
                    </h2>

                    <div class="panda-food-grid">
                        @foreach($items as $item)
                            <div class="food-card">
                                <div class="food-card-thumb" style="background-image: url('{{ $item['image'] }}');">
                                    <span class="food-card-badge">{{ $item['good_for']['Regular'] ?? 'Popular' }}</span>
                                </div>
                                <div class="food-card-body">
                                    <div>
                                        <h3 class="food-card-name">{{ $item['name'] }}</h3>
                                        <p class="food-card-desc">{{ $item['description'] }}</p>
                                    </div>
                                    <div class="food-card-footer">
                                        <span class="food-card-price">₱{{ number_format($item['price'], 2) }}</span>
                                        <button type="button" class="food-add-btn" onclick="openProductModal({{ json_encode($item) }})" title="Customize and Add to Cart">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Sticky Quick Order Side Column -->
        <aside>
            <div class="quick-order-panel">
                <div class="quick-order-tabs">
                    <button type="button" class="quick-order-tab active">Delivery</button>
                    <button type="button" class="quick-order-tab">Pick-up</button>
                </div>

                <div class="quick-order-hero">
                    <div style="font-size: 1.4rem; margin-bottom: 4px; color: #b3261e;"><i class="fas fa-truck-fast"></i></div>
                    <h3>Cavite Doorstep Express</h3>
                    <p>Hot, insulated roast delivery straight to your door</p>
                </div>

                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.95rem; font-weight: 700; margin-bottom: 8px;">
                        <span>Cart Total ({{ count($cart) }} items)</span>
                        <span style="color: #b3261e; font-size: 1.1rem; font-weight: 900;">
                            ₱{{ number_format(array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cart)), 2) }}
                        </span>
                    </div>
                </div>

                @if(!empty($cart))
                    <a href="{{ route('checkout') }}" class="quick-order-checkout-btn">
                        Proceed to Checkout <i class="fas fa-arrow-right"></i>
                    </a>
                @else
                    <button type="button" class="quick-order-checkout-btn" style="opacity: 0.6; cursor: not-allowed;" disabled>
                        Add Items to Order
                    </button>
                @endif
            </div>
        </aside>

    </div>

</div>

<!-- Product Customization Modal -->
<div class="product-preview-modal" id="productModal" style="display: none;">
    <div class="preview-modal-content">
        <button type="button" class="preview-close" onclick="closeProductModal()">&times;</button>
        <div id="modalProductImage" style="height: 180px; background-size: cover; background-position: center;"></div>
        
        <div style="padding: 24px;">
            <h3 id="modalProductName" style="font-size: 1.35rem; font-weight: 900; margin: 0 0 6px 0;"></h3>
            <p id="modalProductDesc" style="font-size: 0.85rem; color: #64748b; line-height: 1.4; margin-bottom: 18px;"></p>

            <form action="{{ route('cart.add') }}" method="POST" id="modalAddToCartForm">
                @csrf
                <input type="hidden" name="product_id" id="modalProductId">
                <input type="hidden" name="size" id="modalSelectedSize" value="Regular">

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 800; color: #171922; margin-bottom: 6px;">Portion Size</label>
                    <div class="preview-size-pills" id="modalSizePills"></div>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 800; color: #171922; margin-bottom: 6px;">Quantity</label>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <button type="button" onclick="adjustModalQty(-1)" style="width: 38px; height: 38px; border-radius: 10px; border: 1px solid #d0d5dd; background: #fff; font-weight: 800; cursor: pointer;">-</button>
                        <input type="number" name="quantity" id="modalQtyInput" value="1" min="1" max="20" style="width: 50px; text-align: center; font-size: 1rem; font-weight: 800; border: none; outline: none;" readonly>
                        <button type="button" onclick="adjustModalQty(1)" style="width: 38px; height: 38px; border-radius: 10px; border: 1px solid #d0d5dd; background: #fff; font-weight: 800; cursor: pointer;">+</button>
                    </div>
                </div>

                <button type="submit" class="quick-order-checkout-btn">
                    Add to Cart &bull; <span id="modalFinalPrice">₱0.00</span>
                </button>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
let currentModalProduct = null;
let currentModalBasePrice = 0;

function openProductModal(product) {
    currentModalProduct = product;
    document.getElementById('modalProductId').value = product.id;
    document.getElementById('modalProductName').textContent = product.name;
    document.getElementById('modalProductDesc').textContent = product.description;
    document.getElementById('modalProductImage').style.backgroundImage = `url('${product.image}')`;
    document.getElementById('modalQtyInput').value = 1;

    const pillsContainer = document.getElementById('modalSizePills');
    pillsContainer.innerHTML = '';

    const sizes = product.sizes || ['Regular'];
    sizes.forEach((sizeName, idx) => {
        const price = product.size_prices[sizeName] || product.price;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `preview-size-pill ${idx === 0 ? 'active' : ''}`;
        btn.textContent = `${sizeName} (₱${Number(price).toFixed(2)})`;
        btn.onclick = () => selectModalSize(sizeName, price, btn);
        pillsContainer.appendChild(btn);
    });

    selectModalSize(sizes[0], product.size_prices[sizes[0]] || product.price);
    document.getElementById('productModal').style.display = 'flex';
}

function closeProductModal() {
    document.getElementById('productModal').style.display = 'none';
}

function selectModalSize(sizeName, price, btnElement) {
    document.getElementById('modalSelectedSize').value = sizeName;
    currentModalBasePrice = Number(price);

    if (btnElement) {
        document.querySelectorAll('.preview-size-pill').forEach(p => p.classList.remove('active'));
        btnElement.classList.add('active');
    }
    updateModalTotalPrice();
}

function adjustModalQty(delta) {
    const qtyInput = document.getElementById('modalQtyInput');
    let val = Math.max(1, Math.min(20, parseInt(qtyInput.value) + delta));
    qtyInput.value = val;
    updateModalTotalPrice();
}

function updateModalTotalPrice() {
    const qty = parseInt(document.getElementById('modalQtyInput').value) || 1;
    const total = currentModalBasePrice * qty;
    document.getElementById('modalFinalPrice').textContent = `₱${total.toFixed(2)}`;
}
</script>
@endpush
@endsection
