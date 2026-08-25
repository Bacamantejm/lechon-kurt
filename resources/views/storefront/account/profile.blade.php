@extends('layouts.app')

@section('title', 'My Account Profile | Lechon Delights')

@section('content')
<div style="max-width: 1000px; margin: 0 auto; padding: 32px 20px;">
    
    <div style="margin-bottom: 28px;">
        <h1 style="font-size: 2rem; font-weight: 900; margin-bottom: 6px;">Customer Account</h1>
        <p style="color: #667085; font-size: 0.95rem;">Manage your delivery addresses and account profile settings.</p>
    </div>

    <!-- Navigation Tabs -->
    <div style="display: flex; gap: 12px; border-bottom: 1px solid #eaecf0; padding-bottom: 14px; margin-bottom: 28px;">
        <a href="{{ route('account.profile') }}" style="font-weight: 800; font-size: 0.95rem; color: #b3261e; border-bottom: 2px solid #b3261e; padding-bottom: 12px;">
            <i class="fas fa-user-circle" style="margin-right: 6px;"></i> Profile Information
        </a>
        <a href="{{ route('account.orders') }}" style="font-weight: 600; font-size: 0.95rem; color: #667085; padding-bottom: 12px;">
            <i class="fas fa-box-open" style="margin-right: 6px;"></i> My Orders
        </a>
        <a href="{{ route('account.favorites') }}" style="font-weight: 600; font-size: 0.95rem; color: #667085; padding-bottom: 12px;">
            <i class="fas fa-heart" style="margin-right: 6px;"></i> Favorite Stores & Items
        </a>
    </div>

    <!-- Profile Card -->
    <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 28px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
        <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 20px; border-bottom: 1px solid #f2f4f7; padding-bottom: 12px;">
            Personal Information
        </h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #475467; margin-bottom: 6px;">Full Name</label>
                <div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-weight: 700;">
                    {{ $user->full_name }}
                </div>
            </div>
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #475467; margin-bottom: 6px;">Email Address</label>
                <div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-weight: 700;">
                    {{ $user->email }}
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #475467; margin-bottom: 6px;">Contact Phone</label>
                <div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-weight: 700;">
                    {{ $user->phone ?? 'Not specified' }}
                </div>
            </div>
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #475467; margin-bottom: 6px;">Account Type</label>
                <div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-weight: 700; text-transform: capitalize;">
                    {{ $user->user_type }} (Active)
                </div>
            </div>
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #475467; margin-bottom: 6px;">Default Cavite Delivery Address</label>
            <div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-weight: 600; color: #344054;">
                {{ $user->address ?? 'No address saved yet.' }}
            </div>
        </div>
    </div>

</div>
@endsection
