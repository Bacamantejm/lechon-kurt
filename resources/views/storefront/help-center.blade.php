@extends('layouts.app')

@section('title', 'Help Center & Support | Lechon Delights')

@section('content')
<div style="max-width: 900px; margin: 0 auto; padding: 40px 20px;">
    <div style="text-align: center; margin-bottom: 36px;">
        <h1 style="font-size: 2.2rem; font-weight: 900; margin-bottom: 8px;">Help Center & Customer Care</h1>
        <p style="color: #667085; font-size: 1rem;">Need assistance with your lechon order, delivery tracking, or pre-order reservation?</p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 36px;">
        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px; text-align: center;">
            <i class="fas fa-truck-fast" style="font-size: 2rem; color: #b3261e; margin-bottom: 12px;"></i>
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 6px;">Order Tracking</h3>
            <p style="font-size: 0.85rem; color: #667085; margin-bottom: 16px;">Check the live progress of your roast delivery in real time.</p>
            <a href="{{ route('track.order') }}" class="btn-outline" style="width: 100%; justify-content: center;">Track Order</a>
        </div>

        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px; text-align: center;">
            <i class="fas fa-calendar-check" style="font-size: 2rem; color: #027a48; margin-bottom: 12px;"></i>
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 6px;">Advance Pre-Orders</h3>
            <p style="font-size: 0.85rem; color: #667085; margin-bottom: 16px;">Book whole lechons in advance for birthdays and holiday fiestas.</p>
            <a href="{{ route('menu') }}" class="btn-outline" style="width: 100%; justify-content: center;">Browse Pre-Orders</a>
        </div>

        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px; text-align: center;">
            <i class="fas fa-comments" style="font-size: 2rem; color: #175cd3; margin-bottom: 12px;"></i>
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 6px;">Store Contact</h3>
            <p style="font-size: 0.85rem; color: #667085; margin-bottom: 16px;">Find phone numbers and direct directions to local branches.</p>
            <a href="{{ route('locations') }}" class="btn-outline" style="width: 100%; justify-content: center;">View Directory</a>
        </div>
    </div>

    <!-- Contact Support Box -->
    <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 28px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
        <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 8px;">Direct Support Hotline</h3>
        <p style="color: #667085; font-size: 0.95rem; margin-bottom: 18px;">Our customer support team is available daily from 7:00 AM to 9:00 PM (PHT).</p>
        <div style="display: flex; gap: 24px; flex-wrap: wrap; font-size: 0.95rem; color: #344054; font-weight: 600;">
            <div><i class="fas fa-envelope" style="color: #b3261e; margin-right: 8px;"></i> support@lechondelights.com</div>
            <div><i class="fas fa-phone" style="color: #b3261e; margin-right: 8px;"></i> (046) 889-LECHON / 0917-123-4567</div>
        </div>
    </div>
</div>
@endsection
