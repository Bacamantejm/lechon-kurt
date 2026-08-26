@extends('layouts.app')

@section('title', 'Live Order Tracking | Lechon Delights')

@push('styles')
<style>
.fp-tracking-page {
    background: #f8f9fa;
    min-height: calc(100vh - 120px);
    padding: 32px 0 100px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.fp-tracking-container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 20px;
}

.fp-status-hero-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 20px;
    padding: 28px 32px;
    box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);
    margin-bottom: 24px;
}

.fp-live-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 12px;
}
.fp-live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #12b76a;
    box-shadow: 0 0 0 3px rgba(18, 183, 106, 0.2);
    animation: fpPulse 1.8s infinite;
}
@keyframes fpPulse {
    0% { transform: scale(0.95); }
    70% { transform: scale(1.15); box-shadow: 0 0 0 6px rgba(18, 183, 106, 0); }
    100% { transform: scale(0.95); }
}

.fp-hero-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.85rem;
    font-weight: 900;
    color: #101828;
    margin: 0 0 6px 0;
}
.fp-hero-subtitle {
    font-size: 0.95rem;
    color: #475467;
    margin: 0;
}

/* Multi-stage Progress Stepper */
.tracking-stepper {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-top: 28px;
    position: relative;
}
.step-node {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    z-index: 2;
}
.step-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #f2f4f7;
    border: 2px solid #eaecf0;
    color: #98a2b3;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    margin-bottom: 10px;
    transition: all 0.3s ease;
}
.step-node.completed .step-icon-wrap {
    background: #ecfdf3;
    border-color: #12b76a;
    color: #027a48;
}
.step-node.active .step-icon-wrap {
    background: #fff1f0;
    border-color: #b3261e;
    color: #b3261e;
    box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.15);
}
.step-label {
    font-size: 0.82rem;
    font-weight: 700;
    color: #475467;
}
.step-node.active .step-label {
    color: #b3261e;
    font-weight: 800;
}

/* Tracking Details Grid */
.tracking-details-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
}
.tracking-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    margin-bottom: 20px;
}
.tracking-card-title {
    font-size: 1.15rem;
    font-weight: 800;
    color: #101828;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.order-item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f2f4f7;
    font-size: 0.9rem;
}
.order-item-row:last-child { border-bottom: none; }

@media (max-width: 860px) {
    .tracking-details-grid { grid-template-columns: 1fr; }
    .tracking-stepper { grid-template-columns: 1fr 1fr; gap: 20px; }
}
</style>
@endpush

@section('content')
<div class="fp-tracking-page">
    <div class="fp-tracking-container">
        
        @if(!$order)
            <!-- Search Order Form -->
            <div class="fp-status-hero-card" style="text-align: center; max-width: 600px; margin: 40px auto;">
                <div style="font-size: 3rem; color: #b3261e; margin-bottom: 14px;"><i class="fas fa-truck-fast"></i></div>
                <h1 class="fp-hero-title">Track Your Lechon Order</h1>
                <p class="fp-hero-subtitle" style="margin-bottom: 24px;">Enter your order number to get live real-time roasting and delivery updates.</p>

                <form action="{{ route('track.order') }}" method="GET" style="display: flex; gap: 10px;">
                    <input type="text" name="order_number" class="form-control" placeholder="e.g. LD-XXXX-1234" value="{{ request('order_number') }}" required style="padding: 14px 16px; border: 1px solid #d0d5dd; border-radius: 12px; font-size: 1rem; width: 100%;">
                    <button type="submit" class="btn-primary" style="padding: 14px 24px; border-radius: 12px; font-weight: 800; white-space: nowrap; background: #b3261e; color: #fff; border: none; cursor: pointer;">
                        Track Order
                    </button>
                </form>
            </div>
        @else
            <!-- Hero Status Card -->
            <div class="fp-status-hero-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <span class="fp-live-badge">
                            <span class="fp-live-dot"></span> Live Fulfillment
                        </span>
                        <h1 class="fp-hero-title">
                            @if($order->status === 'delivered')
                                Order Delivered! Enjoy your meal!
                            @elseif($order->status === 'on_the_way')
                                Rider is on the way to your address!
                            @elseif($order->status === 'preparing')
                                Fresh Lechon is roasting in the pit!
                            @else
                                Order Received &amp; Queueing
                            @endif
                        </h1>
                        <p class="fp-hero-subtitle">
                            Order <strong>#{{ $order->order_number }}</strong> &bull; Placed on {{ $order->created_at->format('M d, Y h:i A') }}
                        </p>
                    </div>

                    <div style="text-align: right;">
                        <span style="font-size: 0.8rem; color: #667085; display: block; margin-bottom: 2px;">Estimated Arrival</span>
                        <strong style="font-size: 1.4rem; color: #b3261e; font-family: 'Outfit', sans-serif;">25–35 Mins</strong>
                    </div>
                </div>

                <!-- 4-Stage Progress Stepper -->
                <div class="tracking-stepper">
                    @php
                        $statusMap = [
                            'pending' => 1,
                            'preparing' => 2,
                            'on_the_way' => 3,
                            'delivered' => 4,
                            'completed' => 4,
                        ];
                        $currentStep = $statusMap[strtolower($order->status)] ?? 1;
                    @endphp

                    <div class="step-node {{ $currentStep > 1 ? 'completed' : ($currentStep == 1 ? 'active' : '') }}">
                        <div class="step-icon-wrap"><i class="fas fa-clipboard-check"></i></div>
                        <span class="step-label">1. Received</span>
                    </div>
                    <div class="step-node {{ $currentStep > 2 ? 'completed' : ($currentStep == 2 ? 'active' : '') }}">
                        <div class="step-icon-wrap"><i class="fas fa-fire-burner"></i></div>
                        <span class="step-label">2. Roasting</span>
                    </div>
                    <div class="step-node {{ $currentStep > 3 ? 'completed' : ($currentStep == 3 ? 'active' : '') }}">
                        <div class="step-icon-wrap"><i class="fas fa-motorcycle"></i></div>
                        <span class="step-label">3. On the Way</span>
                    </div>
                    <div class="step-node {{ $currentStep >= 4 ? 'completed' : '' }}">
                        <div class="step-icon-wrap"><i class="fas fa-house-circle-check"></i></div>
                        <span class="step-label">4. Delivered</span>
                    </div>
                </div>
            </div>

            <!-- Details Grid -->
            <div class="tracking-details-grid">
                
                <!-- Left: Delivery Details & Items -->
                <div>
                    <div class="tracking-card">
                        <h2 class="tracking-card-title"><i class="fas fa-receipt" style="color: #b3261e;"></i> Ordered Items</h2>
                        <div style="display: grid; gap: 4px;">
                            @foreach($order->items as $item)
                                <div class="order-item-row">
                                    <div>
                                        <strong>{{ $item->quantity }}x {{ $item->product_name ?? $item->product?->name }}</strong>
                                        <div style="font-size: 0.78rem; color: #667085;">Freshly roasted with crispy skin</div>
                                    </div>
                                    <strong style="color: #101828;">₱{{ number_format($item->total ?? ($item->price * $item->quantity), 2) }}</strong>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="tracking-card">
                        <h2 class="tracking-card-title"><i class="fas fa-location-dot" style="color: #b3261e;"></i> Delivery Information</h2>
                        <p style="margin: 0 0 8px 0; font-size: 0.95rem; font-weight: 700; color: #101828;">
                            {{ $order->customer_name }} ({{ $order->customer_phone }})
                        </p>
                        <p style="margin: 0; font-size: 0.9rem; color: #475467; line-height: 1.5;">
                            {{ $order->delivery_address }}
                        </p>
                        @if($order->special_instructions)
                            <div style="margin-top: 12px; padding: 10px 14px; background: #f8f9fa; border-radius: 8px; font-size: 0.82rem; color: #667085;">
                                <i class="fas fa-note-sticky"></i> Note: {{ $order->special_instructions }}
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Right: Summary & Support Actions -->
                <div>
                    <div class="tracking-card">
                        <h2 class="tracking-card-title"><i class="fas fa-calculator" style="color: #b3261e;"></i> Payment Summary</h2>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; color: #667085;">
                            <span>Subtotal</span>
                            <strong style="color: #101828;">₱{{ number_format($order->subtotal, 2) }}</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; color: #667085;">
                            <span>Delivery Fee</span>
                            <strong style="color: #101828;">₱{{ number_format($order->delivery_fee, 2) }}</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-top: 1px solid #eaecf0; padding-top: 12px; margin-top: 8px; font-size: 1.15rem; font-weight: 900; color: #101828;">
                            <span>Total Paid</span>
                            <span style="color: #b3261e;">₱{{ number_format($order->total_amount, 2) }}</span>
                        </div>
                        <div style="margin-top: 12px; font-size: 0.8rem; color: #667085; text-transform: uppercase; font-weight: 700;">
                            Payment: {{ strtoupper($order->payment_method) }} &bull; {{ ucfirst($order->payment_status) }}
                        </div>
                    </div>

                    <div class="tracking-card" style="text-align: center;">
                        <h3 style="font-size: 1.05rem; font-weight: 800; margin: 0 0 6px 0;">Need Help With Your Order?</h3>
                        <p style="font-size: 0.85rem; color: #667085; margin: 0 0 16px 0;">Our Cavite roast support team is online to assist you.</p>
                        <a href="{{ route('help-center') }}" class="btn-signin" style="width: 100%; justify-content: center; padding: 12px; border-radius: 10px; font-weight: 800; border-color: #d0d5dd;">
                            <i class="fas fa-headset" style="margin-right: 6px; color: #b3261e;"></i> Contact Customer Support
                        </a>
                    </div>
                </div>

            </div>
        @endif

    </div>
</div>
@endsection
