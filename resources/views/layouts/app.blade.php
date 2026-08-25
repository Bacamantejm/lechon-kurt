<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Lechon Delights Marketplace | Cavite\'s Finest Lechon')</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- SweetAlert2 -->
    <script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --primary-red: #b3261e;
            --brand-hover: #981b15;
            --page-bg: #f8f9fa;
            --card-bg: #ffffff;
            --primary-ink: #101828;
            --muted-text: #475467;
            --border-neutral: #eaecf0;
            --border-input: #d0d5dd;
            --shadow-card: 0 1px 3px rgba(16, 24, 40, 0.04);
            --shadow-md: 0 4px 16px rgba(16, 24, 40, 0.08);
            --radius-md: 12px;
            --radius-lg: 16px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--page-bg);
            color: var(--primary-ink);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            padding-bottom: 100px;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', sans-serif;
            color: var(--primary-ink);
        }

        a { text-decoration: none; color: inherit; }

        /* Site Header */
        .site-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #ffffff;
            border-bottom: 1px solid var(--border-neutral);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            height: 64px;
            display: flex;
            align-items: center;
        }

        .header-container {
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            font-size: 1.25rem;
            color: var(--primary-red);
            font-family: 'Outfit', sans-serif;
        }

        .brand-logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #b3261e, #ef6b2e);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.1rem;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 24px;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .nav-link:hover {
            color: var(--primary-red);
        }

        .nav-link.active {
            color: var(--primary-red);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: 1px solid var(--border-input);
            background: #ffffff;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #344054;
            transition: all 0.2s ease;
        }

        .btn-outline:hover {
            background: #f9fafb;
            border-color: #98a2b3;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 18px;
            background: var(--primary-red);
            color: #ffffff;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            border: 1px solid var(--primary-red);
            transition: background 0.2s ease;
            cursor: pointer;
        }

        .btn-primary:hover {
            background: var(--brand-hover);
        }

        .cart-badge {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #ffffff;
            border: 1px solid var(--border-input);
            color: #344054;
            font-size: 1.05rem;
        }

        .cart-count {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--primary-red);
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            width: 19px;
            height: 19px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Footer */
        .site-footer {
            background: #171922;
            color: #94a3b8;
            border-top: 1px solid #2b3144;
            font-size: 0.85rem;
            margin-top: 60px;
        }

        .footer-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 40px 20px 24px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 32px;
            margin-bottom: 32px;
        }

        .footer-col h4 {
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 800;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .footer-col ul {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .footer-col a:hover {
            color: #ffffff;
        }

        /* Mobile Bottom Nav */
        .mobile-bottom-bar {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: #ffffff;
            border-top: 1px solid var(--border-neutral);
            z-index: 1050;
            justify-content: space-around;
            align-items: center;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            font-size: 0.72rem;
            font-weight: 600;
            color: #667085;
        }

        .mobile-nav-item i {
            font-size: 1.15rem;
        }

        .mobile-nav-item.active {
            color: var(--primary-red);
        }

        @media (max-width: 768px) {
            .nav-links { display: none; }
            .mobile-bottom-bar { display: flex; }
            body { padding-bottom: 90px; }
        }
    </style>
    @yield('styles')
</head>
<body>

    <!-- Header Navigation -->
    <header class="site-header">
        <div class="header-container">
            <a href="{{ route('home') }}" class="brand-logo">
                <div class="brand-logo-icon">
                    <i class="fas fa-piggy-bank"></i>
                </div>
                <span>Lechon Delights</span>
            </a>

            <nav class="nav-links">
                <a href="{{ route('home') }}" class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}">Discover</a>
                <a href="{{ route('menu') }}" class="nav-link {{ request()->routeIs('menu*') ? 'active' : '' }}">Menu</a>
                <a href="{{ route('locations') }}" class="nav-link {{ request()->routeIs('locations') ? 'active' : '' }}">Branches</a>
                <a href="{{ route('track.order') }}" class="nav-link {{ request()->routeIs('track.order') ? 'active' : '' }}">Track Order</a>
                <a href="{{ route('help-center') }}" class="nav-link {{ request()->routeIs('help-center') ? 'active' : '' }}">Help Center</a>
            </nav>

            <div class="header-actions">
                <a href="{{ route('menu') }}" class="cart-badge" title="View Cart">
                    <i class="fas fa-bag-shopping"></i>
                    <span class="cart-count">{{ count(session('cart', [])) }}</span>
                </a>

                @auth
                    <a href="{{ route('account.profile') }}" class="btn-outline">
                        <i class="fas fa-user-circle"></i>
                        <span>{{ Str::limit(auth()->user()->full_name, 12) }}</span>
                    </a>
                    <form action="{{ route('logout') }}" method="POST" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-outline" title="Sign Out">
                            <i class="fas fa-arrow-right-from-bracket"></i>
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn-outline">Log in</a>
                    <a href="{{ route('register') }}" class="btn-primary">Create account</a>
                @endauth
            </div>
        </div>
    </header>

    <!-- Main Content Body -->
    <main>
        @if(session('success'))
            <div style="max-width: 1280px; margin: 16px auto 0; padding: 12px 20px; background: #ecfdf3; border: 1px solid #abefc6; color: #027a48; border-radius: 10px; font-weight: 600;">
                <i class="fas fa-circle-check" style="margin-right: 8px;"></i> {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div style="max-width: 1280px; margin: 16px auto 0; padding: 12px 20px; background: #fff1f0; border: 1px solid #fee4e2; color: #b3261e; border-radius: 10px; font-weight: 600;">
                <i class="fas fa-triangle-exclamation" style="margin-right: 8px;"></i> {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>

    <!-- Site Footer -->
    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-grid">
                <div class="footer-col">
                    <div class="brand-logo" style="color: #ffffff; margin-bottom: 12px;">
                        <div class="brand-logo-icon"><i class="fas fa-piggy-bank"></i></div>
                        <span>Lechon Delights</span>
                    </div>
                    <p style="line-height: 1.6; color: #94a3b8; font-size: 0.85rem;">
                        Discover Cavite's finest lechon shops, compare local roast branches in one place, and get authentic delicacies delivered fast.
                    </p>
                </div>
                <div class="footer-col">
                    <h4>Cavite Locations</h4>
                    <ul>
                        <li><a href="{{ route('locations') }}?city=General+Trias">General Trias</a></li>
                        <li><a href="{{ route('locations') }}?city=Dasmarinas">Dasmariñas</a></li>
                        <li><a href="{{ route('locations') }}?city=Imus">Imus</a></li>
                        <li><a href="{{ route('locations') }}?city=Bacoor">Bacoor</a></li>
                        <li><a href="{{ route('locations') }}?city=Tagaytay">Tagaytay</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Customer Care</h4>
                    <ul>
                        <li><a href="{{ route('menu') }}">Explore Menu</a></li>
                        <li><a href="{{ route('track.order') }}">Track Order</a></li>
                        <li><a href="{{ route('help-center') }}">Help Center</a></li>
                        <li><a href="{{ route('faq') }}">FAQ</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Business Partners</h4>
                    <ul>
                        <li><a href="{{ url('/admin') }}">Seller Dashboard</a></li>
                        <li><a href="{{ url('/admin') }}">Partner Portal</a></li>
                        <li><a href="{{ route('about') }}">About Marketplace</a></li>
                    </ul>
                </div>
            </div>
            <div style="border-top: 1px solid #2b3144; padding-top: 20px; text-align: center; color: #64748b; font-size: 0.8rem;">
                &copy; {{ date('Y') }} Lechon Delights Marketplace. All rights reserved. Built with Laravel 11.
            </div>
        </div>
    </footer>

    <!-- Mobile Bottom Bar -->
    <nav class="mobile-bottom-bar">
        <a href="{{ route('home') }}" class="mobile-nav-item {{ request()->routeIs('home') ? 'active' : '' }}">
            <i class="fas fa-compass"></i>
            <span>Discover</span>
        </a>
        <a href="{{ route('menu') }}" class="mobile-nav-item {{ request()->routeIs('menu*') ? 'active' : '' }}">
            <i class="fas fa-utensils"></i>
            <span>Menu</span>
        </a>
        <a href="{{ route('track.order') }}" class="mobile-nav-item {{ request()->routeIs('track.order') ? 'active' : '' }}">
            <i class="fas fa-motorcycle"></i>
            <span>Tracking</span>
        </a>
        <a href="{{ auth()->check() ? route('account.profile') : route('login') }}" class="mobile-nav-item {{ request()->routeIs('account*') || request()->routeIs('login') ? 'active' : '' }}">
            <i class="fas fa-user"></i>
            <span>{{ auth()->check() ? 'Account' : 'Sign In' }}</span>
        </a>
    </nav>

    @yield('scripts')
</body>
</html>
