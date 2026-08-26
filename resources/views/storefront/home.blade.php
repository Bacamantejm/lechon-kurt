@extends('layouts.app')

@section('title', 'Marketplace Home | Lechon Delights')

@push('styles')
<style>
.market-home {
    --menu-red: #b3261e;
    --menu-orange: #ef6b2e;
    --rose: #b3261e;
    --ink: #2a211d;
    --muted: #7a6c63;
    --line: #efddcd;
    --card: #ffffff;
    --shadow-card: 0 12px 26px rgba(74, 32, 20, 0.1);
    --transition-lift: transform .22s cubic-bezier(.22, 1, .36, 1), box-shadow .22s ease, border-color .22s ease;
    position: relative;
    overflow: visible;
    background: #ffffff;
    color: var(--ink);
    padding: 20px 0 56px;
}

.market-home .container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 24px;
    position: relative;
    z-index: 1;
    box-sizing: border-box;
}

/* Dual Hero Banners */
.panda-hero-banners {
    display: grid;
    gap: 16px;
    margin-bottom: 24px;
}

.panda-hero-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 24px 32px;
    border-radius: 24px;
    position: relative;
    overflow: hidden;
    min-height: 150px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
}

.panda-card-pink {
    background: linear-gradient(135deg, #fff0f3 0%, #ffdfe5 100%);
    border: 1px solid #ffd0d8;
}

.panda-card-soft {
    background: linear-gradient(135deg, #fff8f0 0%, #ffe8d4 100%);
    border: 1px solid #fedbc1;
}

.panda-card-arch {
    position: absolute;
    right: -20px;
    top: -30px;
    bottom: -30px;
    width: 260px;
    background: rgba(255, 255, 255, 0.55);
    border-radius: 50% 0 0 50%;
    pointer-events: none;
    z-index: 1;
    transition: transform 0.3s ease;
}

.panda-hero-card:hover .panda-card-arch {
    transform: scale(1.05);
}

.panda-card-content {
    flex: 1 1 340px;
    max-width: 480px;
    margin-left: 0 !important;
    text-align: left !important;
    display: grid;
    gap: 8px;
    z-index: 2;
}

.panda-card-title {
    margin: 0;
    font-family: "Outfit", "Plus Jakarta Sans", sans-serif;
    font-size: clamp(1.4rem, 2.2vw, 1.85rem);
    font-weight: 800;
    color: #171922;
    line-height: 1.15;
    letter-spacing: -0.02em;
    text-align: left !important;
}

.panda-card-desc {
    margin: 0;
    font-size: 0.92rem;
    color: #4b5563;
    line-height: 1.45;
    font-weight: 500;
    text-align: left !important;
}

.panda-card-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: fit-content;
    margin-top: 6px;
    min-height: 38px;
    padding: 0 20px;
    border-radius: 999px;
    background: #b3261e;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.86rem;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(.22,1,.36,1);
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.22);
}

.panda-card-btn:hover {
    background: #901e17;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(179, 38, 30, 0.3);
    color: #fff;
}

.panda-card-btn-alt {
    background: #171922;
    box-shadow: 0 4px 12px rgba(23, 25, 34, 0.18);
}

.panda-card-btn-alt:hover {
    background: #2b3144;
    box-shadow: 0 6px 16px rgba(23, 25, 34, 0.25);
    color: #fff;
}

.panda-card-graphic {
    position: relative;
    flex: 0 0 180px;
    height: 150px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    z-index: 2;
    margin-left: auto;
}

.panda-card-graphic img.panda-mascot-img {
    height: 165px;
    width: auto;
    object-fit: contain;
    margin-bottom: -10px;
    filter: drop-shadow(0 10px 18px rgba(179, 38, 30, 0.16));
    transition: transform 0.3s cubic-bezier(.22,1,.36,1);
    background: transparent;
}

.panda-hero-card:hover .panda-mascot-img {
    transform: scale(1.08) translateY(-4px);
}

.panda-card-graphic-cluster {
    position: relative;
    flex: 0 0 170px;
    width: 170px;
    height: 150px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
    margin-left: auto;
}

.panda-card-heart-bg {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 260px;
    pointer-events: none;
    z-index: 1;
    overflow: hidden;
}

.panda-heart-shape {
    position: absolute;
    background: rgba(239, 107, 46, 0.12);
    border-radius: 50%;
}
.panda-heart-shape.heart-lg { width: 180px; height: 180px; right: -40px; top: -30px; }
.panda-heart-shape.heart-sm { width: 90px; height: 90px; right: 100px; bottom: -20px; }

.panda-float-badge-wrap {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.panda-badge-item {
    position: absolute;
    background: #ffffff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.12);
    z-index: 3;
}

.panda-badge-calendar {
    top: 18px; right: 50px; width: 70px; height: 70px; color: #ef6b2e; font-size: 1.8rem;
    box-shadow: 0 12px 28px rgba(239, 107, 46, 0.22);
}
.panda-badge-gift {
    bottom: 12px; right: 6px; width: 50px; height: 50px; background: #fff3eb; color: #ef6b2e; font-size: 1.3rem;
}
.panda-badge-party {
    top: 10px; right: 120px; width: 42px; height: 42px; color: #b3261e; font-size: 1.1rem;
}

/* Explorer Layout & Sidebar */
.market-explorer {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    gap: 32px;
    align-items: start;
}

.market-sidebar {
    position: sticky;
    top: 112px;
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.02);
}

.market-sidebar-section {
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 16px;
    margin-bottom: 16px;
}
.market-sidebar-section:last-child {
    border-bottom: none;
    padding-bottom: 0;
    margin-bottom: 0;
}

.market-sidebar h3 {
    margin: 0 0 10px 0;
    font-size: 1.15rem;
    font-weight: 800;
    color: #1e293b;
}

.market-sidebar h4 {
    margin: 0 0 10px 0;
    font-size: 0.88rem;
    font-weight: 700;
    color: #475569;
}

.market-radio-list, .market-check-list {
    display: grid;
    gap: 12px;
}

.market-radio, .market-check {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.88rem;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
}

.market-radio input, .market-check input {
    width: 18px;
    height: 18px;
    accent-color: #b3261e;
}

/* Brands Slider */
.panda-brand-slider {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    padding: 6px 2px 14px;
    scrollbar-width: none;
}
.panda-brand-slider::-webkit-scrollbar { display: none; }

.panda-brand-item {
    flex: 0 0 115px;
    background: #ffffff;
    border: 1px solid #f0e2d5;
    border-radius: 16px;
    padding: 10px 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    text-decoration: none;
    color: #2a211d;
    box-shadow: 0 4px 10px rgba(74, 32, 20, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.panda-brand-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 18px rgba(74, 32, 20, 0.09);
    border-color: #ef6b2e;
}

.panda-brand-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background-size: cover;
    background-position: center;
    margin-bottom: 8px;
    box-shadow: 0 3px 8px rgba(0,0,0,0.08);
}

.panda-brand-name {
    font-family: 'Outfit', sans-serif;
    font-size: 0.82rem;
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 3px;
    color: #2a211d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}

.panda-brand-meta {
    font-size: 0.71rem;
    color: #7d6f65;
    font-weight: 600;
}

/* 3-Column Store Grid */
.store-list-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
}

.market-store-row {
    display: flex;
    flex-direction: column;
    background: #ffffff;
    border: 1px solid #f0e2d5;
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.22s ease, box-shadow 0.22s ease;
    box-shadow: 0 4px 12px rgba(74, 32, 20, 0.04);
    text-decoration: none;
    color: inherit;
}
.market-store-row:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(74, 32, 20, 0.1);
    border-color: #ebd7c5;
}

.store-card-image-wrap {
    position: relative;
    width: 100%;
    height: 135px;
    overflow: hidden;
}

.market-store-row-thumb {
    width: 100%;
    height: 100%;
    background-size: cover;
    background-position: center;
    transition: transform 0.3s ease;
}
.market-store-row:hover .market-store-row-thumb { transform: scale(1.04); }

.market-type-pill {
    position: absolute; left: 10px; top: 10px; z-index: 5; background: rgba(42, 33, 29, 0.85);
    color: #ffffff; font-size: 0.68rem; padding: 3px 8px; border-radius: 6px; font-weight: 700;
}
.market-time-pill {
    position: absolute; left: 10px; bottom: 10px; z-index: 5; background: rgba(255, 255, 255, 0.92);
    color: #b3261e; font-size: 0.68rem; padding: 3px 8px; border-radius: 6px; font-weight: 800;
}

.store-card-details {
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    flex: 1;
    gap: 6px;
}

.store-card-row-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}

.store-card-row-head h3 {
    margin: 0;
    font-family: 'Outfit', sans-serif;
    font-size: 1.05rem;
    font-weight: 800;
    color: #171922;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.panda-card-footer-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #f5eae0;
    gap: 8px;
}

@media (max-width: 900px) {
    .market-explorer { grid-template-columns: 1fr; }
    .market-sidebar { position: static; max-width: 100%; margin-bottom: 20px; }
}
</style>
@endpush

@section('content')
<div class="market-home">
    <div class="container">
        
        <div class="market-explorer">
            <!-- Left Sticky Sidebar -->
            <aside class="market-sidebar" id="marketSidebar">
                <div class="market-sidebar-section">
                    <h3>Filters</h3>
                </div>

                <div class="market-sidebar-section">
                    <h4>Sort by</h4>
                    <div class="market-radio-list">
                        <label class="market-radio"><input type="radio" name="storeSort" value="relevance" checked> <span>Relevance</span></label>
                        <label class="market-radio"><input type="radio" name="storeSort" value="fastest"> <span>Fastest delivery</span></label>
                        <label class="market-radio"><input type="radio" name="storeSort" value="distance"> <span>Distance</span></label>
                        <label class="market-radio"><input type="radio" name="storeSort" value="top_rated"> <span>Top rated</span></label>
                    </div>
                </div>

                <div class="market-sidebar-section">
                    <h4>Quick filters</h4>
                    <div class="market-check-list">
                        <label class="market-check"><input type="checkbox" id="filterRatings4"> <span>Ratings 4+</span></label>
                        <label class="market-check"><input type="checkbox" id="filterLiveOnly"> <span>Open now only</span></label>
                        <label class="market-check"><input type="checkbox" id="filterPartnerOnly"> <span>Partner stores</span></label>
                        <label class="market-check"><input type="checkbox" id="filterBranchOnly"> <span>Pickup branches</span></label>
                    </div>
                </div>

                <div class="market-sidebar-section">
                    <h4>Cavite Cities</h4>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px;">
                        @foreach(['General Trias', 'Dasmariñas', 'Imus', 'Bacoor', 'Tagaytay', 'Silang', 'Tanza'] as $city)
                            <a href="{{ route('home', ['city' => $city]) }}" style="font-size: 0.76rem; font-weight: 700; color: #475467; text-decoration: none; padding: 4px 10px; border-radius: 999px; border: 1px solid #eaecf0; background: #f8f9fa;">
                                {{ $city }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </aside>

            <!-- Right Main Marketplace Content -->
            <div>
                
                <!-- Dual Hero Promotional Banners with Mascot -->
                <div class="panda-hero-banners">
                    <!-- Order Fresh Lechon Banner -->
                    <article class="panda-hero-card panda-card-pink">
                        <div class="panda-card-arch"></div>
                        <div class="panda-card-content">
                            <h2 class="panda-card-title">Order Fresh Lechon</h2>
                            <p class="panda-card-desc">Enjoy crispy skin and juicy meat, roasted fresh for every order.</p>
                            <a href="{{ route('menu') }}" class="panda-card-btn">Order Now</a>
                        </div>
                        <div class="panda-card-graphic">
                            <img src="{{ asset('assets/images/lechon_mascot_user.png') }}" alt="Lechon Mascot" class="panda-mascot-img" onerror="this.src='{{ asset('images/panda_fresh_lechon.jpg') }}'">
                        </div>
                    </article>

                    <!-- Pre-order for Celebrations Banner -->
                    <article class="panda-hero-card panda-card-soft">
                        <div class="panda-card-heart-bg">
                            <div class="panda-heart-shape heart-lg"></div>
                            <div class="panda-heart-shape heart-sm"></div>
                        </div>
                        <div class="panda-card-content">
                            <h2 class="panda-card-title">Pre-order for Celebrations</h2>
                            <p class="panda-card-desc">Avoid the rush by booking your whole or half lechon ahead of time.</p>
                            <a href="{{ route('menu') }}" class="panda-card-btn panda-card-btn-alt">Reserve Now</a>
                        </div>
                        <div class="panda-card-graphic-cluster">
                            <div class="panda-float-badge-wrap">
                                <div class="panda-badge-item panda-badge-calendar"><i class="fas fa-calendar-check"></i></div>
                                <div class="panda-badge-item panda-badge-gift"><i class="fas fa-gift"></i></div>
                                <div class="panda-badge-item panda-badge-party"><i class="fas fa-utensils"></i></div>
                            </div>
                        </div>
                    </article>
                </div>

                <!-- Top Lechon Brands & Hubs Carousel -->
                @if(!empty($top_rated_stores))
                <section style="margin-bottom: 28px;">
                    <div style="margin-bottom: 12px;">
                        <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; font-weight: 800; color: #171922; margin: 0 0 4px 0;">Top lechon brands & hubs</h2>
                        <p style="font-size: 0.86rem; color: #64748b; margin: 0;">Popular Cavite lechon pitmasters, whole lechon suppliers, and quick pickup branches.</p>
                    </div>
                    <div class="panda-brand-slider">
                        @foreach($top_rated_stores as $top_store)
                            <a href="{{ $top_store['menu_link'] }}" class="panda-brand-item">
                                <div class="panda-brand-avatar" style="background-image: url('{{ $top_store['image'] }}');"></div>
                                <div class="panda-brand-name">{{ $top_store['name'] }}</div>
                                <div class="panda-brand-meta">
                                    <i class="fas fa-star" style="color: #ef6b2e;"></i> {{ $top_store['rating'] }} &bull; 25-35m
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
                @endif

                <!-- Best Sellers Grid -->
                @if(!empty($featured_products) && count($featured_products) > 0)
                <section style="margin-bottom: 32px; padding: 18px 20px; background: linear-gradient(135deg, #fff9f2 0%, #ffffff 100%); border: 1px solid #efddcd; border-radius: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; background: #fff1f2; border: 1px solid #ffe4e6; border-radius: 999px; color: #b3261e; font-size: 0.72rem; font-weight: 800; text-transform: uppercase;">
                                <i class="fas fa-fire" style="color: #ef6b2e;"></i> Top Customer Choices
                            </div>
                            <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 800; color: #171922; margin: 4px 0 2px 0;">Best Sellers & Top Rated Dishes</h2>
                        </div>
                        <a href="{{ route('menu') }}" style="color: #b3261e; font-weight: 700; font-size: 0.82rem; text-decoration: none;">
                            Explore Full Menu <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px;">
                        @foreach($featured_products->take(6) as $idx => $prod)
                            <a href="{{ $prod['menu_link'] }}" style="background: #ffffff; border: 1px solid #efddcd; border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; text-decoration: none; color: inherit;">
                                <div style="height: 110px; background-image: url('{{ $prod['image'] }}'); background-size: cover; background-position: center; position: relative;">
                                    <span style="position: absolute; top: 8px; left: 8px; background: linear-gradient(135deg, #b3261e, #ef6b2e); color: #fff; font-size: 0.68rem; font-weight: 800; padding: 2px 7px; border-radius: 999px;">
                                        #{{ $idx + 1 }} Best Seller
                                    </span>
                                </div>
                                <div style="padding: 10px 12px; display: flex; flex-direction: column; flex: 1;">
                                    <h4 style="font-family: 'Outfit', sans-serif; font-size: 0.88rem; font-weight: 800; margin: 0 0 4px 0; line-height: 1.2;">{{ $prod['name'] }}</h4>
                                    <p style="font-size: 0.75rem; color: #64748b; margin: 0 0 8px 0;">{{ $prod['store'] }}</p>
                                    <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #f0e2d5; padding-top: 6px;">
                                        <strong style="color: #b3261e; font-size: 0.9rem; font-weight: 800;">₱{{ number_format($prod['price'], 2) }}</strong>
                                        <span style="font-size: 0.72rem; background: #b3261e; color: #fff; padding: 3px 8px; border-radius: 6px; font-weight: 700;">Order</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
                @endif

                <!-- All Cavite Stores Section -->
                <section>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                        <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.45rem; font-weight: 800; color: #171922; margin: 0;">All Cavite stores</h2>
                    </div>

                    <div class="store-list-grid" id="marketStoreGrid">
                        @foreach($stores as $store)
                            <a href="{{ $store['menu_link'] }}" class="market-store-row">
                                <div class="store-card-image-wrap">
                                    <div class="market-store-row-thumb" style="background-image: url('{{ $store['image'] }}');"></div>
                                    <span class="market-type-pill">{{ $store['business_type'] }}</span>
                                    <span class="market-time-pill">{{ $store['status_label'] }}</span>
                                </div>
                                <div class="store-card-details">
                                    <div class="store-card-row-head">
                                        <h3>{{ $store['name'] }}</h3>
                                        <span style="font-size: 0.85rem; font-weight: 800; color: #171922;">
                                            <i class="fas fa-star" style="color: #ef6b2e;"></i> {{ $store['rating'] }}
                                        </span>
                                    </div>
                                    <div class="panda-card-footer-line">
                                        <span class="panda-card-city"><i class="fas fa-location-dot" style="color: #b3261e;"></i> {{ $store['city'] }}</span>
                                        <span class="panda-card-price-text">₱{{ number_format($store['start'], 2) }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>

            </div>
        </div>

    </div>
</div>
@endsection
