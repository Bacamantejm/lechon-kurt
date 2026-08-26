@extends('layouts.app')

@section('title', 'Checkout | Lechon Delights')

@push('styles')
<style>
.checkout-page-wrap {
    background: #f8f9fa;
    padding: 32px 0 80px;
    min-height: calc(100vh - 120px);
}
.checkout-page-wrap .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 24px;
}

.checkout-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 32px;
    align-items: start;
}

.checkout-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    margin-bottom: 24px;
}

.checkout-card-title {
    font-size: 1.2rem;
    font-weight: 800;
    color: #171922;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.checkout-card-title i { color: #b3261e; }

/* Option Selectors */
.delivery-type-toggle {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}
.type-card {
    border: 2px solid #eaecf0;
    border-radius: 14px;
    padding: 16px;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s ease;
}
.type-card.active {
    border-color: #b3261e;
    background: #fff1f0;
}
.type-card i { font-size: 1.4rem; margin-bottom: 6px; display: block; color: #b3261e; }
.type-card strong { display: block; font-size: 0.95rem; color: #171922; }
.type-card small { font-size: 0.78rem; color: #64748b; }

.form-group { margin-bottom: 16px; }
.form-group label {
    display: block;
    font-size: 0.84rem;
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
    transition: border-color 0.2s ease;
    box-sizing: border-box;
}
.form-control:focus {
    border-color: #b3261e;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
}

/* Payment Selector */
.payment-methods {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.payment-card {
    border: 2px solid #eaecf0;
    border-radius: 12px;
    padding: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 0.88rem;
}
.payment-card.active {
    border-color: #b3261e;
    background: #fff1f0;
    color: #b3261e;
}

/* Order Summary */
.summary-items-list {
    max-height: 260px;
    overflow-y: auto;
    margin-bottom: 16px;
    display: grid;
    gap: 12px;
}
.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.88rem;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    font-size: 0.9rem;
    color: #64748b;
}
.summary-row.total {
    border-top: 1px solid #eaecf0;
    padding-top: 14px;
    font-size: 1.2rem;
    font-weight: 900;
    color: #171922;
}

.place-order-btn {
    width: 100%;
    padding: 16px;
    background: #b3261e;
    color: #ffffff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background 0.2s ease;
}
.place-order-btn:hover { background: #981b15; }

@media (max-width: 900px) {
    .checkout-grid { grid-template-columns: 1fr; }
}
</style>
@endpush

@section('content')
<div class="checkout-page-wrap">
    <div class="container">
        
        <h1 style="font-size: 1.8rem; font-weight: 900; color: #171922; margin: 0 0 24px 0;">
            Review and Confirm Order
        </h1>

        <form action="{{ route('checkout.process') }}" method="POST" id="checkoutForm">
            @csrf
            <input type="hidden" name="delivery_type" id="deliveryTypeInput" value="delivery">
            <input type="hidden" name="payment_method" id="paymentMethodInput" value="cod">

            <div class="checkout-grid">
                
                <!-- Main Form Area -->
                <div>
                    
                    <!-- 1. Fulfillment Option -->
                    <div class="checkout-card">
                        <h2 class="checkout-card-title">
                            <i class="fas fa-motorcycle"></i> 1. How would you like to receive your food?
                        </h2>
                        
                        <div class="delivery-type-toggle">
                            <div class="type-card active" onclick="setDeliveryType('delivery', this)">
                                <i class="fas fa-truck-fast"></i>
                                <strong>Doorstep Delivery</strong>
                                <small>Express delivery to Cavite</small>
                            </div>
                            <div class="type-card" onclick="setDeliveryType('pickup', this)">
                                <i class="fas fa-person-walking"></i>
                                <strong>Store Pick-up</strong>
                                <small>Claim directly at the branch</small>
                            </div>
                        </div>

                        <!-- Delivery Address Field -->
                        <div id="deliveryAddressSection">
                            <div class="form-group">
                                <label for="deliveryAddress">Delivery Address in Cavite</label>
                                <textarea name="delivery_address" id="deliveryAddress" rows="2" class="form-control" placeholder="House/Unit No., Street, Barangay, City, Cavite">{{ auth()->user()?->address ?? 'General Trias, Cavite' }}</textarea>
                            </div>
                        </div>

                        <!-- Store Pickup Selector -->
                        <div class="form-group" id="pickupStoreSection" style="display: none;">
                            <label for="storeSelect">Select Pickup Store Branch</label>
                            <select name="store_id" id="storeSelect" class="form-control">
                                @foreach($stores as $s)
                                    <option value="{{ $s->store_id }}">
                                        {{ $s->store_name }} &bull; {{ $s->city }}, Cavite
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- 2. Customer Contact Info -->
                    <div class="checkout-card">
                        <h2 class="checkout-card-title">
                            <i class="fas fa-user-check"></i> 2. Customer Information
                        </h2>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label for="customerName">Full Name</label>
                                <input type="text" name="customer_name" id="customerName" class="form-control" value="{{ auth()->user()?->full_name ?? '' }}" required>
                            </div>
                            <div class="form-group">
                                <label for="customerPhone">Phone Number</label>
                                <input type="text" name="customer_phone" id="customerPhone" class="form-control" value="{{ auth()->user()?->phone ?? '' }}" placeholder="09xxxxxxxxx" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="customerNotes">Special Cooking or Delivery Instructions (Optional)</label>
                            <input type="text" name="customer_notes" id="customerNotes" class="form-control" placeholder="e.g. Extra spicy sauce, call upon arrival, landmark near plaza">
                        </div>
                    </div>

                    <!-- 3. Payment Method -->
                    <div class="checkout-card">
                        <h2 class="checkout-card-title">
                            <i class="fas fa-credit-card"></i> 3. Payment Method
                        </h2>

                        <div class="payment-methods">
                            <div class="payment-card active" onclick="setPaymentMethod('cod', this)">
                                <i class="fas fa-money-bill-wave" style="color: #027a48;"></i>
                                <span>Cash on Delivery (COD)</span>
                            </div>
                            <div class="payment-card" onclick="setPaymentMethod('gcash', this)">
                                <i class="fas fa-wallet" style="color: #007dfe;"></i>
                                <span>GCash E-Wallet</span>
                            </div>
                            <div class="payment-card" onclick="setPaymentMethod('maya', this)">
                                <i class="fas fa-mobile-screen-button" style="color: #22c55e;"></i>
                                <span>Maya E-Wallet</span>
                            </div>
                            <div class="payment-card" onclick="setPaymentMethod('card', this)">
                                <i class="fas fa-credit-card" style="color: #b3261e;"></i>
                                <span>Credit / Debit Card</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Right Summary Column -->
                <div>
                    <div class="checkout-card" style="position: sticky; top: 90px;">
                        <h2 class="checkout-card-title">
                            <i class="fas fa-receipt"></i> Order Summary
                        </h2>

                        <div class="summary-items-list">
                            @foreach($cart as $item)
                                <div class="summary-item">
                                    <div>
                                        <strong>{{ $item['quantity'] }}x {{ $item['name'] }}</strong>
                                        <div style="font-size: 0.75rem; color: #64748b;">{{ $item['size'] ?? 'Regular' }}</div>
                                    </div>
                                    <span style="font-weight: 700;">₱{{ number_format($item['price'] * $item['quantity'], 2) }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div style="border-top: 1px solid #eaecf0; padding-top: 14px; margin-bottom: 16px;">
                            <div class="summary-row">
                                <span>Subtotal</span>
                                <strong style="color: #171922;">₱{{ number_format($subtotal, 2) }}</strong>
                            </div>
                            <div class="summary-row" id="deliveryFeeRow">
                                <span>Delivery Fee</span>
                                <strong style="color: #171922;" id="deliveryFeeDisplay">₱{{ number_format($estimatedDeliveryFee, 2) }}</strong>
                            </div>
                            <div class="summary-row total">
                                <span>Total Amount</span>
                                <strong style="color: #b3261e;" id="totalAmountDisplay">
                                    ₱{{ number_format($subtotal + $estimatedDeliveryFee, 2) }}
                                </strong>
                            </div>
                        </div>

                        <button type="submit" class="place-order-btn">
                            Confirm &amp; Place Order <i class="fas fa-check"></i>
                        </button>
                    </div>
                </div>

            </div>
        </form>

    </div>
</div>

@push('scripts')
<script>
const subtotal = {{ (float)$subtotal }};
let deliveryFee = {{ (float)$estimatedDeliveryFee }};

function setDeliveryType(type, element) {
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('active'));
    element.classList.add('active');
    document.getElementById('deliveryTypeInput').value = type;

    if (type === 'pickup') {
        document.getElementById('deliveryAddressSection').style.display = 'none';
        document.getElementById('pickupStoreSection').style.display = 'block';
        deliveryFee = 0;
        document.getElementById('deliveryFeeDisplay').textContent = 'FREE';
    } else {
        document.getElementById('deliveryAddressSection').style.display = 'block';
        document.getElementById('pickupStoreSection').style.display = 'none';
        deliveryFee = 59;
        document.getElementById('deliveryFeeDisplay').textContent = '₱59.00';
    }
    document.getElementById('totalAmountDisplay').textContent = `₱${(subtotal + deliveryFee).toFixed(2)}`;
}

function setPaymentMethod(method, element) {
    document.querySelectorAll('.payment-card').forEach(c => c.classList.remove('active'));
    element.classList.add('active');
    document.getElementById('paymentMethodInput').value = method;
}
</script>
@endpush
@endsection
