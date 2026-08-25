@extends('layouts.app')

@section('title', 'My Order History | Lechon Delights')

@section('content')
<div style="max-width: 1000px; margin: 0 auto; padding: 32px 20px;">
    
    <div style="margin-bottom: 28px;">
        <h1 style="font-size: 2rem; font-weight: 900; margin-bottom: 6px;">My Past Orders</h1>
        <p style="color: #667085; font-size: 0.95rem;">Review and track your recent roasted lechon orders.</p>
    </div>

    <!-- Navigation Tabs -->
    <div style="display: flex; gap: 12px; border-bottom: 1px solid #eaecf0; padding-bottom: 14px; margin-bottom: 28px;">
        <a href="{{ route('account.profile') }}" style="font-weight: 600; font-size: 0.95rem; color: #667085; padding-bottom: 12px;">
            <i class="fas fa-user-circle" style="margin-right: 6px;"></i> Profile Information
        </a>
        <a href="{{ route('account.orders') }}" style="font-weight: 800; font-size: 0.95rem; color: #b3261e; border-bottom: 2px solid #b3261e; padding-bottom: 12px;">
            <i class="fas fa-box-open" style="margin-right: 6px;"></i> My Orders
        </a>
        <a href="{{ route('account.favorites') }}" style="font-weight: 600; font-size: 0.95rem; color: #667085; padding-bottom: 12px;">
            <i class="fas fa-heart" style="margin-right: 6px;"></i> Favorite Stores & Items
        </a>
    </div>

    <!-- Orders List -->
    <div style="display: flex; flex-direction: column; gap: 16px;">
        @forelse($orders as $order)
            <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 22px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                        <span style="font-weight: 800; font-size: 1.1rem; color: #b3261e;">{{ $order->order_number }}</span>
                        <span style="padding: 3px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; background: #ecfdf3; color: #027a48; border: 1px solid #abefc6;">
                            {{ ucfirst($order->status) }}
                        </span>
                    </div>
                    <div style="font-size: 0.85rem; color: #667085; margin-bottom: 4px;">
                        Ordered on {{ $order->created_at->format('M d, Y h:i A') }} &bull; {{ $order->items->count() }} item(s)
                    </div>
                    <div style="font-size: 0.85rem; color: #475467;">
                        <i class="fas fa-location-dot" style="color: #b3261e; margin-right: 4px;"></i> {{ Str::limit($order->delivery_address, 45) }}
                    </div>
                </div>

                <div style="text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                    <div style="font-size: 1.25rem; font-weight: 900; color: #101828;">
                        ₱{{ number_format($order->total_amount, 2) }}
                    </div>
                    <a href="{{ route('track.order', ['order_number' => $order->order_number]) }}" class="btn-outline" style="font-size: 0.85rem; padding: 6px 14px;">
                        <i class="fas fa-motorcycle"></i> Track Progress
                    </a>
                </div>
            </div>
        @empty
            <div style="text-align: center; padding: 48px; background: #ffffff; border-radius: 16px; border: 1px solid #eaecf0;">
                <i class="fas fa-box-open" style="font-size: 3rem; color: #d0d5dd; margin-bottom: 12px;"></i>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">No Orders Placed Yet</h3>
                <p style="color: #667085; font-size: 0.9rem; margin-bottom: 18px;">When you place an order, its details and delivery tracking will appear here.</p>
                <a href="{{ route('menu') }}" class="btn-primary">Browse Menu & Order</a>
            </div>
        @endforelse
    </div>

</div>
@endsection
