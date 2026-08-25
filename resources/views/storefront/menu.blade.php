@extends('layouts.app')

@section('title', 'Menu & Quick Order | Lechon Delights Marketplace')

@section('content')
<div style="max-width: 1320px; margin: 0 auto; padding: 24px 20px;">
    
    <!-- Top Filter Bar -->
    <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 18px 24px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
        <div style="display: flex; gap: 12px; align-items: center; flex: 1; min-width: 260px;">
            <form action="{{ route('menu') }}" method="GET" style="display: flex; gap: 10px; width: 100%;">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search lechon, sisig, rice meals..." style="width: 100%; padding: 10px 16px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.95rem; outline: none;">
                @if(request('store_id'))
                    <input type="hidden" name="store_id" value="{{ request('store_id') }}">
                @endif
                <button type="submit" class="btn-primary" style="padding: 10px 20px;">
                    <i class="fas fa-magnifying-glass"></i>
                </button>
            </form>
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <label style="font-size: 0.85rem; font-weight: 700; color: #475467;">Store Branch:</label>
            <select onchange="location = this.value;" style="padding: 10px 16px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.9rem; font-weight: 600; outline: none; background: #ffffff; color: #101828;">
                <option value="{{ route('menu') }}">All Cavite Branches</option>
                @foreach($stores as $store)
                    <option value="{{ route('menu', ['store_id' => $store->id]) }}" {{ $selectedStoreId == $store->id ? 'selected' : '' }}>
                        {{ $store->store_name }} ({{ $store->city }})
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Layout Grid: Menu Products + Sticky Cart Sidebar -->
    <div style="display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start;">
        
        <!-- Main Products Column -->
        <div>
            <!-- Category Pills -->
            <div style="display: flex; gap: 10px; overflow-x: auto; padding-bottom: 12px; margin-bottom: 20px; scrollbar-width: none;">
                <a href="{{ route('menu') }}" style="padding: 8px 18px; border-radius: 30px; font-size: 0.85rem; font-weight: 700; white-space: nowrap; {{ !request('category') ? 'background: #b3261e; color: #ffffff;' : 'background: #ffffff; color: #475467; border: 1px solid #d0d5dd;' }}">
                    All Items
                </a>
                @foreach($categories as $category)
                    <a href="{{ route('menu', ['category' => $category]) }}" style="padding: 8px 18px; border-radius: 30px; font-size: 0.85rem; font-weight: 700; white-space: nowrap; {{ request('category') == $category ? 'background: #b3261e; color: #ffffff;' : 'background: #ffffff; color: #475467; border: 1px solid #d0d5dd;' }}">
                        {{ $category }}
                    </a>
                @endforeach
            </div>

            <!-- Products Catalog Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px;">
                @forelse($products as $product)
                    <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 14px; padding: 14px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="height: 130px; background: #f8f9fa; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; overflow: hidden;">
                                @if($product->image_url)
                                    <img src="{{ asset($product->image_url) }}" alt="{{ $product->product_name }}" style="width: 100%; height: 100%; object-fit: cover;">
                                @else
                                    <i class="fas fa-drumstick-bite" style="font-size: 2.2rem; color: #b3261e; opacity: 0.6;"></i>
                                @endif
                            </div>
                            <span style="font-size: 0.72rem; font-weight: 700; color: #b3261e;">{{ $product->store->store_name ?? 'Cavite Branch' }}</span>
                            <h4 style="font-size: 1rem; font-weight: 800; margin: 3px 0 4px;">{{ $product->product_name }}</h4>
                            <p style="font-size: 0.82rem; color: #667085; line-height: 1.4; margin-bottom: 10px;">{{ Str::limit($product->description, 50) }}</p>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f2f4f7; padding-top: 10px;">
                            <span style="font-size: 1.15rem; font-weight: 900; color: #101828;">₱{{ number_format($product->price, 2) }}</span>
                            <form action="{{ route('cart.add') }}" method="POST">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                <input type="hidden" name="quantity" value="1">
                                <button type="submit" class="btn-primary" style="padding: 6px 14px; font-size: 0.82rem;">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div style="grid-column: 1 / -1; padding: 48px 20px; text-align: center; background: #ffffff; border-radius: 16px; border: 1px solid #eaecf0;">
                        <i class="fas fa-bowl-food" style="font-size: 3rem; color: #d0d5dd; margin-bottom: 12px;"></i>
                        <p style="color: #667085; font-size: 1rem;">No items found matching your filters. Try selecting another branch or category.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Sticky Quick Order Cart Sidebar -->
        <div style="position: sticky; top: 80px;">
            <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 20px; box-shadow: 0 4px 16px rgba(16, 24, 40, 0.06);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #eaecf0; padding-bottom: 12px;">
                    <h3 style="font-size: 1.15rem; font-weight: 800;">
                        <i class="fas fa-bag-shopping" style="color: #b3261e; margin-right: 6px;"></i> Your Order
                    </h3>
                    <span style="font-size: 0.82rem; font-weight: 700; color: #667085;">{{ count($cart) }} item(s)</span>
                </div>

                @if(count($cart) > 0)
                    <div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; margin-bottom: 18px; padding-right: 4px;">
                        @php $subtotal = 0; @endphp
                        @foreach($cart as $item)
                            @php $subtotal += $item['price'] * $item['quantity']; @endphp
                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.88rem;">
                                <div>
                                    <div style="font-weight: 700; color: #101828;">{{ $item['name'] }}</div>
                                    <div style="font-size: 0.78rem; color: #667085;">₱{{ number_format($item['price'], 2) }} &times; {{ $item['quantity'] }}</div>
                                </div>
                                <div style="font-weight: 800; color: #101828;">
                                    ₱{{ number_format($item['price'] * $item['quantity'], 2) }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div style="border-top: 1px solid #eaecf0; padding-top: 14px; margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem; color: #667085; margin-bottom: 6px;">
                            <span>Subtotal</span>
                            <span>₱{{ number_format($subtotal, 2) }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem; color: #667085; margin-bottom: 8px;">
                            <span>Delivery</span>
                            <span style="color: #027a48; font-weight: 700;">Calculated at checkout</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 900; color: #101828; border-top: 1px dashed #d0d5dd; padding-top: 8px;">
                            <span>Total</span>
                            <span style="color: #b3261e;">₱{{ number_format($subtotal, 2) }}</span>
                        </div>
                    </div>

                    <a href="{{ route('checkout') }}" class="btn-primary" style="width: 100%; padding: 12px; font-size: 0.95rem;">
                        Proceed to Checkout &rarr;
                    </a>
                @else
                    <div style="text-align: center; padding: 32px 10px; color: #667085;">
                        <i class="fas fa-basket-shopping" style="font-size: 2.5rem; color: #d0d5dd; margin-bottom: 10px;"></i>
                        <p style="font-size: 0.9rem; font-weight: 600;">Your cart is empty</p>
                        <p style="font-size: 0.78rem; color: #98a2b3; margin-top: 4px;">Click "+ Add" on any menu item to begin your order.</p>
                    </div>
                @endif
            </div>
        </div>

    </div>

</div>
@endsection
