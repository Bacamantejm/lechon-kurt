@extends('layouts.app')

@section('title', 'Checkout & Delivery | Lechon Delights Marketplace')

@section('content')
<div style="max-width: 1100px; margin: 0 auto; padding: 28px 20px;">
    
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 2rem; font-weight: 900; margin-bottom: 6px;">Secure Checkout</h1>
        <p style="color: #667085; font-size: 0.95rem;">Confirm your delivery address and payment method to complete your lechon feast order.</p>
    </div>

    <form action="{{ route('checkout.process') }}" method="POST">
        @csrf
        <div style="display: grid; grid-template-columns: 1fr 380px; gap: 28px; align-items: start;">
            
            <!-- Left Form Details -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                
                <!-- 1. Contact & Customer Details -->
                <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                        <span style="width: 28px; height: 28px; border-radius: 50%; background: #b3261e; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 0.82rem;">1</span>
                        Customer Details
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Recipient Full Name</label>
                            <input type="text" name="customer_name" value="{{ auth()->user()->full_name ?? old('customer_name') }}" required style="width: 100%; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.95rem; outline: none;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Contact Phone Number</label>
                            <input type="text" name="customer_phone" value="{{ auth()->user()->phone ?? old('customer_phone') }}" required placeholder="e.g. 09171234567" style="width: 100%; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.95rem; outline: none;">
                        </div>
                    </div>
                </div>

                <!-- 2. Fulfillment & Store Branch -->
                <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                        <span style="width: 28px; height: 28px; border-radius: 50%; background: #b3261e; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 0.82rem;">2</span>
                        Fulfillment & Delivery Address
                    </h3>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Roaster Branch</label>
                        <select name="store_id" required style="width: 100%; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.95rem; outline: none; background: #ffffff;">
                            @foreach($stores as $store)
                                <option value="{{ $store->store_id }}">{{ $store->store_name }} — {{ $store->address ?? $store->city }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #344054; margin-bottom: 8px;">Fulfillment Type</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <label style="display: flex; align-items: center; gap: 10px; padding: 14px; border: 1px solid #d0d5dd; border-radius: 10px; cursor: pointer;">
                                <input type="radio" name="delivery_type" value="delivery" checked style="accent-color: #b3261e;">
                                <div>
                                    <div style="font-weight: 700; font-size: 0.95rem;">Doorstep Delivery</div>
                                    <div style="font-size: 0.78rem; color: #667085;">Dispatched via Lalamove / In-House</div>
                                </div>
                            </label>
                            <label style="display: flex; align-items: center; gap: 10px; padding: 14px; border: 1px solid #d0d5dd; border-radius: 10px; cursor: pointer;">
                                <input type="radio" name="delivery_type" value="pickup" style="accent-color: #b3261e;">
                                <div>
                                    <div style="font-weight: 700; font-size: 0.95rem;">Store Pick-up</div>
                                    <div style="font-size: 0.78rem; color: #667085;">Free pickup directly at roast branch</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Delivery Street Address (Cavite)</label>
                        <textarea name="delivery_address" rows="2" placeholder="Unit/House No., Street, Barangay, City, Cavite" style="width: 100%; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.95rem; outline: none; font-family: inherit;">{{ auth()->user()->address ?? old('delivery_address') }}</textarea>
                    </div>

                    <div>
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Special Delivery Notes (Optional)</label>
                        <input type="text" name="customer_notes" placeholder="e.g. Leave with guard at gate, extra gravy on the side" style="width: 100%; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.95rem; outline: none;">
                    </div>
                </div>

                <!-- 3. Payment Method -->
                <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                        <span style="width: 28px; height: 28px; border-radius: 50%; background: #b3261e; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 0.82rem;">3</span>
                        Payment Method
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <label style="display: flex; align-items: center; gap: 10px; padding: 14px; border: 1px solid #d0d5dd; border-radius: 10px; cursor: pointer;">
                            <input type="radio" name="payment_method" value="cod" checked style="accent-color: #b3261e;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem;">Cash on Delivery (COD)</div>
                                <div style="font-size: 0.78rem; color: #667085;">Pay rider upon arrival</div>
                            </div>
                        </label>
                        <label style="display: flex; align-items: center; gap: 10px; padding: 14px; border: 1px solid #d0d5dd; border-radius: 10px; cursor: pointer;">
                            <input type="radio" name="payment_method" value="gcash" style="accent-color: #b3261e;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem;">GCash / Maya (PayMongo)</div>
                                <div style="font-size: 0.78rem; color: #667085;">Fast & secure instant e-wallet</div>
                            </div>
                        </label>
                    </div>
                </div>

            </div>

            <!-- Right Summary Breakdown -->
            <div style="position: sticky; top: 80px;">
                <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 22px; box-shadow: 0 4px 16px rgba(16, 24, 40, 0.06);">
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px; border-bottom: 1px solid #eaecf0; padding-bottom: 12px;">
                        Order Summary
                    </h3>

                    <div style="max-height: 240px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px;">
                        @foreach($cart as $item)
                            <div style="display: flex; justify-content: space-between; font-size: 0.88rem;">
                                <span>{{ $item['name'] }} &times; {{ $item['quantity'] }}</span>
                                <span style="font-weight: 700;">₱{{ number_format($item['price'] * $item['quantity'], 2) }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div style="border-top: 1px solid #eaecf0; padding-top: 14px; margin-bottom: 20px; display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem; color: #667085;">
                            <span>Subtotal</span>
                            <span>₱{{ number_format($subtotal, 2) }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem; color: #667085;">
                            <span>Delivery Fee</span>
                            <span style="color: #027a48; font-weight: 700;">₱{{ number_format($estimatedDeliveryFee, 2) }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 1.25rem; font-weight: 900; color: #101828; border-top: 1px dashed #d0d5dd; padding-top: 12px;">
                            <span>Total</span>
                            <span style="color: #b3261e;">₱{{ number_format($subtotal + $estimatedDeliveryFee, 2) }}</span>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="width: 100%; padding: 14px; font-size: 1rem; font-weight: 800;">
                        <i class="fas fa-lock"></i> Place Order Now
                    </button>
                </div>
            </div>

        </div>
    </form>

</div>
@endsection
