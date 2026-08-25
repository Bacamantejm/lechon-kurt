@extends('layouts.app')

@section('title', 'Live Order Tracking | Lechon Delights Marketplace')

@section('content')
<div style="max-width: 900px; margin: 0 auto; padding: 32px 20px;">
    
    <div style="text-align: center; margin-bottom: 32px;">
        <h1 style="font-size: 2.2rem; font-weight: 900; margin-bottom: 6px;">Live Order Tracking</h1>
        <p style="color: #667085; font-size: 1rem;">Track your freshly roasted lechon from the roasting pit to your doorstep.</p>
    </div>

    <!-- Search / Lookup Bar -->
    <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); margin-bottom: 28px;">
        <form action="{{ route('track.order') }}" method="GET" style="display: flex; gap: 12px;">
            <input type="text" name="order_number" value="{{ $orderNumber }}" placeholder="Enter order tracking number (e.g. LD-XXXX-XXXX)" style="flex: 1; padding: 12px 18px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 1rem; outline: none; font-weight: 600;">
            <button type="submit" class="btn-primary" style="padding: 12px 24px; font-size: 0.95rem;">
                <i class="fas fa-search"></i> Track
            </button>
        </form>
    </div>

    @if($order)
        <!-- Active Order Card -->
        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 20px; padding: 32px; box-shadow: 0 4px 20px rgba(16, 24, 40, 0.06); margin-bottom: 24px;">
            
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eaecf0; padding-bottom: 20px; margin-bottom: 28px; flex-wrap: wrap; gap: 12px;">
                <div>
                    <span style="font-size: 0.82rem; font-weight: 700; color: #667085; text-transform: uppercase;">Order Number</span>
                    <h2 style="font-size: 1.5rem; font-weight: 900; color: #b3261e;">{{ $order->order_number }}</h2>
                </div>
                <div style="text-align: right;">
                    <span style="display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; background: #ecfdf3; color: #027a48; border: 1px solid #abefc6;">
                        {{ ucfirst($order->status) }}
                    </span>
                    <div style="font-size: 0.8rem; color: #667085; margin-top: 4px;">{{ $order->created_at->format('M d, Y h:i A') }}</div>
                </div>
            </div>

            <!-- Multi-Stage Tracking Progress Bar (Foodpanda style) -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 36px; text-align: center; position: relative;">
                @php
                    $statusOrder = ['pending' => 1, 'preparing' => 2, 'on_the_way' => 3, 'delivered' => 4];
                    $currentStep = $statusOrder[$order->status] ?? 1;
                @endphp

                @foreach($stages as $key => $stage)
                    @php $isDone = $stage['step'] <= $currentStep; @endphp
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                        <div style="width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; transition: all 0.3s; {{ $isDone ? 'background: #b3261e; color: #ffffff; box-shadow: 0 4px 12px rgba(179,38,30,0.3);' : 'background: #f2f4f7; color: #98a2b3;' }}">
                            <i class="{{ $stage['icon'] }}"></i>
                        </div>
                        <span style="font-size: 0.85rem; font-weight: 700; {{ $isDone ? 'color: #101828;' : 'color: #98a2b3;' }}">
                            {{ $stage['label'] }}
                        </span>
                    </div>
                @endforeach
            </div>

            <!-- Order Details Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #f8f9fa; border-radius: 14px; padding: 20px;">
                <div>
                    <h4 style="font-size: 0.88rem; font-weight: 800; color: #475467; text-transform: uppercase; margin-bottom: 8px;">Delivery Details</h4>
                    <p style="font-size: 0.95rem; font-weight: 700; color: #101828;">{{ $order->customer_name }} ({{ $order->customer_phone }})</p>
                    <p style="font-size: 0.88rem; color: #667085; margin-top: 4px;">
                        <i class="fas fa-location-dot" style="color: #b3261e; margin-right: 4px;"></i> {{ $order->delivery_address }}
                    </p>
                </div>
                <div>
                    <h4 style="font-size: 0.88rem; font-weight: 800; color: #475467; text-transform: uppercase; margin-bottom: 8px;">Store Branch</h4>
                    <p style="font-size: 0.95rem; font-weight: 700; color: #101828;">{{ $order->store->store_name ?? 'Cavite Roaster' }}</p>
                    <p style="font-size: 0.88rem; color: #667085; margin-top: 4px;">Total Amount: <strong style="color: #b3261e;">₱{{ number_format($order->total_amount, 2) }}</strong> ({{ strtoupper($order->payment_method) }})</p>
                </div>
            </div>

        </div>
    @elseif($orderNumber)
        <div style="text-align: center; padding: 48px; background: #ffffff; border-radius: 16px; border: 1px solid #eaecf0;">
            <i class="fas fa-circle-question" style="font-size: 3rem; color: #d0d5dd; margin-bottom: 12px;"></i>
            <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 6px;">Order Not Found</h3>
            <p style="color: #667085; font-size: 0.95rem;">We couldn't locate tracking details for "{{ $orderNumber }}". Please double-check your order number.</p>
        </div>
    @endif

</div>
@endsection
