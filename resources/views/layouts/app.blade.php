<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Marketplace Home | Lechon Delights')</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS Dependencies -->
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --ink: #171922;
            --muted: #667085;
            --line: #efddcd;
            --rose: #b3261e;
            --bg: #f8f9fa;
            --card: #ffffff;
            --shadow: 0 12px 30px rgba(15,23,42,.1);
            --primary-color: #b3261e;
            --primary-dark: #8f261a;
            --motion-ease: cubic-bezier(.22,1,.36,1);
            --motion-fast: .22s;
            --transition-fast: all var(--motion-fast) var(--motion-ease);
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            overflow-x: clip !important;
            max-width: 100vw; width: 100%;
            font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #ffffff;
            color: var(--ink);
        }
        h1,h2,h3,h4,h5,h6 { font-family: "Outfit", "Plus Jakarta Sans", sans-serif; }

        /* Site Header */
        .site-header {
            position: sticky;
            top: 0;
            z-index: 1200;
            background: #ffffff !important;
            border-bottom: 1px solid var(--line);
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
            width: 100%;
        }
        .market-header-top, .market-header-bottom {
            max-width: 1280px; margin: 0 auto; padding: 0 24px; width: 100%; box-sizing: border-box;
        }
        .market-header-top {
            min-height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: nowrap; padding-top: 10px; padding-bottom: 10px;
        }
        .market-header-bottom {
            min-height: 48px; border-top: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: nowrap; padding-top: 8px; padding-bottom: 8px; position: relative;
        }
        
        .logo-link { display: inline-flex; gap: 10px; align-items: center; text-decoration: none; flex-shrink: 0; }
        .logo-icon {
            width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;
            color: #fff; background: linear-gradient(135deg, #b3261e, #ef6b2e); box-shadow: 0 6px 16px rgba(179,38,30,.2); font-size: .95rem;
        }
        .logo-copy { display: grid; gap: 1px; }
        .logo-title { font-size: 1.15rem; font-weight: 800; color: var(--ink); line-height: 1; }
        .logo-sub { font-size: .65rem; text-transform: uppercase; letter-spacing: .12em; color: #7f879a; font-weight: 700; }

        .market-address-wrap { position: relative; display: inline-block; }
        .market-address-trigger {
            border: 1px solid var(--line); background: #fff7ef; border-radius: 999px; min-height: 38px; padding: 0 14px;
            color: #2a3042; display: inline-flex; align-items: center; gap: 7px; cursor: pointer; font-size: .84rem; font-weight: 700;
            max-width: 280px; transition: var(--transition-fast); flex-shrink: 0;
        }
        .market-address-trigger:hover { background: #ffeede; border-color: #ef6b2e; }

        .auth-buttons { display: flex; align-items: center; gap: 10px; }
        .btn-signin, .btn-register {
            min-height: 38px; padding: 0 16px; border-radius: 999px; border: 1px solid; text-decoration: none; font-weight: 700; font-size: .84rem;
            display: inline-flex; align-items: center; justify-content: center; transition: var(--transition-fast); white-space: nowrap;
        }
        .btn-signin { border-color: #d0d5dd; color: #344054; background: #ffffff; }
        .btn-signin:hover { background: #f8fafc; border-color: #98a2b3; color: #101828; }
        .btn-register { border-color: #b3261e; background: #b3261e; color: #ffffff; }
        .btn-register:hover { background: #981b15; border-color: #981b15; color: #ffffff; }

        .cart-icon-btn {
            width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: #222a3d;
            display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition-fast); position: relative; font-size: .95rem; text-decoration: none;
        }
        .cart-icon-btn:hover { background: #171922; color: #fff; border-color: #171922; }
        .cart-badge {
            position: absolute; top: -5px; right: -5px; min-width: 19px; height: 19px; border-radius: 999px;
            background: #b3261e; color: #fff; border: 2px solid #fff; font-size: .68rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px;
        }

        .market-home-nav { display: flex; align-items: center; gap: 9px; flex-shrink: 0; }
        .market-home-link {
            text-decoration: none; color: #56617a; font-size: .88rem; font-weight: 700; min-height: 38px; padding: 0 14px;
            border-radius: 999px; border: 1px solid transparent; display: inline-flex; gap: 6px; align-items: center; transition: var(--transition-fast);
        }
        .market-home-link:hover, .market-home-link.active { background: #fff4e8; border-color: var(--line); color: var(--ink); }

        .market-home-search-wrap {
            flex: 0 1 420px; max-width: 440px; min-width: 220px; position: relative; z-index: 1200;
        }
        .market-home-search {
            width: 100%; display: inline-flex; align-items: center; gap: 10px; min-height: 42px; background: #f4f5f8;
            border: 1px solid #dcdfe6; border-radius: 999px; padding: 0 16px;
        }
        .market-home-search input { width: 100%; border: none; background: transparent; outline: none; font-size: .92rem; color: #1f2333; }

        /* Address Popover Modal */
        .market-address-popover {
            position: absolute; top: calc(100% + 10px); left: 0; width: min(460px, 92vw); background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: 20px; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.18); padding: 20px 22px;
            z-index: 1250; display: none;
        }
        .market-address-wrap.is-open .market-address-popover { display: block; }
        
        .market-address-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .market-address-pill {
            border: 1px solid #cbd5e1; background: #ffffff; color: #334155; border-radius: 999px; padding: 6px 14px;
            font-size: 0.82rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.2s ease;
        }
        .market-address-pill:hover { border-color: #b3261e; color: #b3261e; background: #fff5f5; }

        /* Footer */
        .footer { background: #171922; color: #ffffff; padding: 56px 0 32px; margin-top: 0; }
        .footer-container { max-width: 1280px; margin: 0 auto; padding: 0 24px; }
        .footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .footer-logo { display: inline-flex; gap: 10px; align-items: center; text-decoration: none; margin-bottom: 14px; }
        .footer-logo-title { color: #ffffff; font-size: 1.25rem; font-weight: 800; font-family: "Outfit", sans-serif; }
        .footer-logo-sub { color: #94a3b8; font-size: 0.68rem; letter-spacing: 0.1em; display: block; }
        .footer-brand-desc { color: #94a3b8; font-size: 0.9rem; line-height: 1.6; max-width: 340px; margin-bottom: 18px; }
        .social-links { display: flex; gap: 12px; }
        .social-links a { width: 36px; height: 36px; border-radius: 10px; background: #242936; color: #ffffff; display: flex; align-items: center; justify-content: center; text-decoration: none; transition: background 0.2s; }
        .social-links a:hover { background: #b3261e; }
        .footer-col h4 { font-size: 1rem; font-weight: 800; margin-bottom: 16px; color: #ffffff; }
        .footer-col ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
        .footer-col ul a { color: #94a3b8; text-decoration: none; font-size: 0.88rem; transition: color 0.2s; }
        .footer-col ul a:hover { color: #ffffff; }
        .footer-bottom { border-top: 1px solid #242936; padding-top: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; color: #94a3b8; font-size: 0.85rem; }
        .footer-links { display: flex; gap: 18px; }
        .footer-links a { color: #94a3b8; text-decoration: none; }
        .footer-links a:hover { color: #ffffff; }

        /* Mobile Bottom Nav */
        .mobile-bottom-nav {
            display: none; position: fixed; bottom: 0; left: 0; right: 0; height: 60px; background: #ffffff;
            border-top: 1px solid #eaecf0; z-index: 1250; box-shadow: 0 -4px 16px rgba(16, 24, 40, 0.06);
            padding: 4px 8px; justify-content: space-around; align-items: center;
        }
        .mob-nav-item {
            flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-decoration: none; color: #64748b; font-size: 0.68rem; font-weight: 700; gap: 2px;
        }
        .mob-nav-item.active { color: #b3261e; }
        .mob-nav-item i { font-size: 1.15rem; }

        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            .footer-grid { grid-template-columns: 1fr; gap: 28px; }
            .footer { padding-bottom: 90px !important; }
            .market-header-top { padding: 10px 16px; }
            .market-header-bottom { padding: 8px 16px; }
        }
    </style>
    @stack('styles')
</head>
<body>

    <!-- Site Header -->
    <header class="site-header">
        <!-- Top Row -->
        <div class="market-header-top">
            <a href="{{ route('home') }}" class="logo-link">
                <span class="logo-icon"><i class="fas fa-piggy-bank"></i></span>
                <span class="logo-copy">
                    <span class="logo-title">Lechon Delights</span>
                    <span class="logo-sub">MARKETPLACE</span>
                </span>
            </a>

            <!-- Address Trigger with Cavite Picker -->
            <div class="market-address-wrap" id="marketAddressWrap">
                <button type="button" class="market-address-trigger" onclick="document.getElementById('marketAddressWrap').classList.toggle('is-open')">
                    <i class="fas fa-location-dot" style="color: #b3261e;"></i>
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        {{ auth()->user()?->address ?? 'General Trias, Cavite' }}
                    </span>
                    <i class="fas fa-chevron-down" style="font-size: 0.75rem; color: #94a3b8; margin-left: 2px;"></i>
                </button>

                <div class="market-address-popover">
                    <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; font-weight: 800;">Choose Your Delivery Location</h4>
                    <p style="font-size: 0.82rem; color: #64748b; margin-bottom: 12px;">Select a Cavite city to see nearby master roast houses and fast delivery options.</p>
                    <div class="market-address-pills">
                        @foreach(['General Trias', 'Dasmariñas', 'Imus', 'Bacoor', 'Tagaytay', 'Silang', 'Tanza', 'Kawit', 'Rosario', 'Cavite City'] as $city)
                            <a href="{{ route('home', ['city' => $city]) }}" class="market-address-pill">{{ $city }}</a>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Actions: Cart & Auth Buttons -->
            <div class="auth-buttons">
                <a href="{{ route('menu') }}" class="cart-icon-btn" title="View Cart">
                    <i class="fas fa-bag-shopping"></i>
                    <span class="cart-badge" id="cartBadgeCount">{{ count(session('cart', [])) }}</span>
                </a>

                @auth
                    <div style="position: relative; display: inline-block;">
                        <a href="{{ route('account.profile') }}" class="btn-signin" style="gap: 6px;">
                            <i class="fas fa-user-circle" style="color: #b3261e;"></i> {{ explode(' ', auth()->user()->full_name)[0] }}
                        </a>
                    </div>
                    <form action="{{ route('logout') }}" method="POST" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-signin" style="border-color: #fee4e2; color: #b3261e; background: #fff1f0;">Sign Out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn-signin">Log In</a>
                    <a href="{{ route('register') }}" class="btn-register">Create account</a>
                @endauth
            </div>
        </div>

        <!-- Bottom Row: Navigation & Search -->
        <div class="market-header-bottom">
            <nav class="market-home-nav">
                <a href="{{ route('home') }}" class="market-home-link {{ !request('type') ? 'active' : '' }}">
                    <i class="fas fa-motorcycle" style="color: #b3261e;"></i> Delivery
                </a>
                <a href="{{ route('home', ['type' => 'pickup']) }}" class="market-home-link {{ request('type') === 'pickup' ? 'active' : '' }}">
                    <i class="fas fa-person-walking" style="color: #027a48;"></i> Pick-up
                </a>
                <a href="{{ route('locations') }}" class="market-home-link">
                    <i class="fas fa-store" style="color: #175cd3;"></i> Shops
                </a>
            </nav>

            <div class="market-home-search-wrap">
                <form action="{{ route('menu') }}" method="GET" class="market-home-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search for restaurants, cuisines, and dishes">
                </form>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="site-main">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-grid">
                <div class="footer-col">
                    <a href="{{ route('home') }}" class="footer-logo">
                        <span class="logo-icon" style="background: linear-gradient(135deg, #b3261e, #ef6b2e);"><i class="fas fa-piggy-bank"></i></span>
                        <span class="footer-logo-text">
                            <span class="footer-logo-title">Lechon Delights</span>
                            <span class="footer-logo-sub">MARKETPLACE</span>
                        </span>
                    </a>
                    <p class="footer-brand-desc">Discover Cavite lechon shops, compare local roast branches in one place, and get authentic roasted delicacies delivered fast.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>

                <div class="footer-col">
                    <h4>Cavite Locations</h4>
                    <ul>
                        <li><a href="{{ route('locations', ['search' => 'General Trias']) }}">General Trias</a></li>
                        <li><a href="{{ route('locations', ['search' => 'Dasmarinas']) }}">Dasmariñas</a></li>
                        <li><a href="{{ route('locations', ['search' => 'Imus']) }}">Imus</a></li>
                        <li><a href="{{ route('locations', ['search' => 'Bacoor']) }}">Bacoor</a></li>
                        <li><a href="{{ route('locations', ['search' => 'Tagaytay']) }}">Tagaytay</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Customer Care</h4>
                    <ul>
                        <li><a href="{{ route('menu') }}">Explore Menu</a></li>
                        <li><a href="{{ route('locations') }}">Store Directory</a></li>
                        <li><a href="{{ route('track.order') }}">Track Order</a></li>
                        <li><a href="{{ route('help-center') }}">Help Center</a></li>
                        <li><a href="{{ route('faq') }}">FAQ</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Business Partner</h4>
                    <ul>
                        <li><a href="{{ url('/admin/login') }}">Partner Portal</a></li>
                        <li><a href="{{ route('about') }}">About Marketplace</a></li>
                        <li><a href="{{ route('help-center') }}">Partner Support</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; {{ date('Y') }} Lechon Delights Marketplace. All rights reserved.</p>
                <div class="footer-links">
                    <a href="{{ route('faq') }}">Privacy Policy</a>
                    <a href="{{ route('faq') }}">Terms of Service</a>
                    <a href="{{ route('locations') }}">Sitemap</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="{{ route('home') }}" class="mob-nav-item {{ request()->routeIs('home') ? 'active' : '' }}">
            <i class="fas fa-house"></i>
            <span>Home</span>
        </a>
        <a href="{{ route('menu') }}" class="mob-nav-item {{ request()->routeIs('menu') ? 'active' : '' }}">
            <i class="fas fa-utensils"></i>
            <span>Menu</span>
        </a>
        <a href="{{ route('track.order') }}" class="mob-nav-item {{ request()->routeIs('track.order') ? 'active' : '' }}">
            <i class="fas fa-motorcycle"></i>
            <span>Track</span>
        </a>
        <a href="{{ auth()->check() ? route('account.orders') : route('login') }}" class="mob-nav-item {{ request()->routeIs('account.orders') ? 'active' : '' }}">
            <i class="fas fa-receipt"></i>
            <span>Orders</span>
        </a>
        <a href="{{ auth()->check() ? route('account.profile') : route('login') }}" class="mob-nav-item {{ request()->routeIs('account.profile') ? 'active' : '' }}">
            <i class="fas fa-user"></i>
            <span>Account</span>
        </a>
    </nav>

    @stack('scripts')
</body>
</html>
