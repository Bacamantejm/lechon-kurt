@extends('layouts.app')

@section('title', 'My Account Profile | Lechon Delights')

@push('styles')
<style>
.account-wrap {
    max-width: 1000px;
    margin: 0 auto;
    padding: 32px 20px 80px;
}
.account-nav-tabs {
    display: flex;
    gap: 12px;
    border-bottom: 1px solid #eaecf0;
    padding-bottom: 14px;
    margin-bottom: 28px;
}
.account-nav-tab {
    font-size: 0.95rem;
    font-weight: 700;
    color: #667085;
    text-decoration: none;
    padding-bottom: 12px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2px solid transparent;
    transition: all 0.2s ease;
}
.account-nav-tab.active {
    color: #b3261e;
    border-bottom-color: #b3261e;
}

.account-stat-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}
.stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.account-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 18px;
    padding: 28px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    margin-bottom: 24px;
}
.account-card-title {
    font-size: 1.2rem;
    font-weight: 800;
    color: #101828;
    margin: 0 0 20px 0;
    border-bottom: 1px solid #f2f4f7;
    padding-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-group { margin-bottom: 18px; }
.form-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 700;
    color: #344054;
    margin-bottom: 6px;
}
.form-control {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    font-size: 0.92rem;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.2s ease;
}
.form-control:focus {
    border-color: #b3261e;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
}

.btn-submit {
    padding: 12px 24px;
    background: #b3261e;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    font-weight: 800;
    font-size: 0.92rem;
    cursor: pointer;
    transition: background 0.2s ease;
}
.btn-submit:hover { background: #981b15; }

@media (max-width: 768px) {
    .account-stat-grid { grid-template-columns: 1fr; }
}
</style>
@endpush

@section('content')
<div class="account-wrap">
    
    <div style="margin-bottom: 28px;">
        <h1 style="font-size: 2rem; font-weight: 900; color: #101828; margin: 0 0 6px 0;">Customer Account</h1>
        <p style="color: #667085; font-size: 0.95rem; margin: 0;">Manage your profile information, delivery address, and security settings.</p>
    </div>

    <!-- Alert Notifications -->
    @if(session('success'))
        <div style="padding: 14px 18px; background: #ecfdf3; border: 1px solid #abefc6; border-radius: 12px; color: #027a48; font-weight: 700; margin-bottom: 20px;">
            <i class="fas fa-check-circle" style="margin-right: 6px;"></i> {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div style="padding: 14px 18px; background: #fff1f0; border: 1px solid #fee4e2; border-radius: 12px; color: #b3261e; font-weight: 700; margin-bottom: 20px;">
            <ul style="margin: 0; padding-left: 18px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Navigation Tabs -->
    <div class="account-nav-tabs">
        <a href="{{ route('account.profile') }}" class="account-nav-tab active">
            <i class="fas fa-user-circle"></i> Profile Information
        </a>
        <a href="{{ route('account.orders') }}" class="account-nav-tab">
            <i class="fas fa-box-open"></i> My Orders
        </a>
        <a href="{{ route('account.favorites') }}" class="account-nav-tab">
            <i class="fas fa-heart"></i> Favorite Stores &amp; Items
        </a>
    </div>

    <!-- Stats Summary Row -->
    <div class="account-stat-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #fff1f0; color: #b3261e;">
                <i class="fas fa-bag-shopping"></i>
            </div>
            <div>
                <strong style="font-size: 1.4rem; color: #101828; display: block; font-family: 'Outfit', sans-serif;">{{ $totalOrders }}</strong>
                <span style="font-size: 0.82rem; color: #667085; font-weight: 600;">Total Orders</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #eff8ff; color: #175cd3;">
                <i class="fas fa-truck-fast"></i>
            </div>
            <div>
                <strong style="font-size: 1.4rem; color: #101828; display: block; font-family: 'Outfit', sans-serif;">{{ $activeOrders }}</strong>
                <span style="font-size: 0.82rem; color: #667085; font-weight: 600;">Active Deliveries</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fff4ed; color: #b54708;">
                <i class="fas fa-heart"></i>
            </div>
            <div>
                <strong style="font-size: 1.4rem; color: #101828; display: block; font-family: 'Outfit', sans-serif;">{{ $favoriteCount }}</strong>
                <span style="font-size: 0.82rem; color: #667085; font-weight: 600;">Saved Favorites</span>
            </div>
        </div>
    </div>

    <!-- 1. Personal Information Form -->
    <div class="account-card">
        <h2 class="account-card-title"><i class="fas fa-address-card" style="color: #b3261e;"></i> Profile Details</h2>

        <form action="{{ route('account.profile.update') }}" method="POST">
            @csrf
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
                <div class="form-group">
                    <label for="fullName">Full Name</label>
                    <input type="text" name="full_name" id="fullName" class="form-control" value="{{ old('full_name', $user->full_name) }}" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address (Read-only)</label>
                    <input type="email" id="email" class="form-control" value="{{ $user->email }}" style="background: #f8f9fa; color: #667085;" readonly>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" name="phone" id="phone" class="form-control" value="{{ old('phone', $user->phone) }}" placeholder="09xxxxxxxxx">
                </div>
                <div class="form-group">
                    <label for="accountType">Account Role</label>
                    <input type="text" class="form-control" value="{{ ucfirst($user->user_type) }} Customer" style="background: #f8f9fa; color: #667085;" readonly>
                </div>
            </div>

            <div class="form-group">
                <label for="address">Default Cavite Delivery Address</label>
                <textarea name="address" id="address" rows="2" class="form-control" placeholder="House No., Street, Barangay, City, Cavite">{{ old('address', $user->address) }}</textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-save" style="margin-right: 6px;"></i> Save Profile Changes
            </button>
        </form>
    </div>

    <!-- 2. Security & Password Update -->
    <div class="account-card">
        <h2 class="account-card-title"><i class="fas fa-lock" style="color: #b3261e;"></i> Security &amp; Password</h2>

        <form action="{{ route('account.password.update') }}" method="POST">
            @csrf
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px;">
                <div class="form-group">
                    <label for="currentPassword">Current Password</label>
                    <input type="password" name="current_password" id="currentPassword" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="passwordConfirmation">Confirm New Password</label>
                    <input type="password" name="password_confirmation" id="passwordConfirmation" class="form-control" required>
                </div>
            </div>

            <button type="submit" class="btn-submit" style="background: #171922;">
                <i class="fas fa-key" style="margin-right: 6px;"></i> Update Password
            </button>
        </form>
    </div>

</div>
@endsection
