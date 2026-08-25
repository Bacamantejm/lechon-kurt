@extends('layouts.app')

@section('title', 'Cavite\'s Finest Roasted Lechon Marketplace')

@section('content')
<div style="max-width: 1280px; margin: 0 auto; padding: 24px 20px;">
    
    <!-- Hero Banner -->
    <div style="background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%); border-radius: 20px; padding: 48px 36px; color: #ffffff; margin-bottom: 40px; box-shadow: 0 10px 25px rgba(179, 38, 30, 0.2); position: relative; overflow: hidden;">
        <div style="max-width: 600px; position: relative; z-index: 2;">
            <span style="display: inline-block; background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 20px; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px;">
                🔥 Direct from Cavite Master Roasters
            </span>
            <h1 style="color: #ffffff; font-size: 2.8rem; font-weight: 900; line-height: 1.15; margin-bottom: 16px; letter-spacing: -1px;">
                Crispy Skin. Juicy Meat. Delivered Hot.
            </h1>
            <p style="font-size: 1.1rem; color: rgba(255,255,255,0.9); margin-bottom: 28px; line-height: 1.5;">
                Order authentic native Cebu-style & classic Luzon roasted lechon from top branches across Cavite with real-time tracking.
            </p>
            <div style="display: flex; gap: 14px; flex-wrap: wrap;">
                <a href="{{ route('menu') }}" class="btn-primary" style="background: #ffffff; color: #b3261e; font-size: 1rem; padding: 12px 28px; font-weight: 800; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <i class="fas fa-utensils"></i> Order Lechon Now
                </a>
                <a href="{{ route('locations') }}" class="btn-outline" style="background: rgba(255,255,255,0.15); color: #ffffff; border-color: rgba(255,255,255,0.3); font-size: 1rem; padding: 12px 24px;">
                    <i class="fas fa-map-location-dot"></i> Browse Cavite Branches
                </a>
            </div>
        </div>
    </div>

    <!-- City Filter Pills -->
    <div style="margin-bottom: 32px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="font-size: 1.5rem; font-weight: 800;">📍 Select Your Location in Cavite</h2>
        </div>
        <div style="display: flex; gap: 10px; overflow-x: auto; padding-bottom: 8px; scrollbar-width: none;">
            <a href="{{ route('home') }}" style="padding: 8px 18px; border-radius: 30px; font-size: 0.9rem; font-weight: 700; white-space: nowrap; transition: all 0.2s; {{ $selectedCity === 'All' ? 'background: #b3261e; color: #ffffff;' : 'background: #ffffff; color: #475467; border: 1px solid #d0d5dd;' }}">
                All Cavite ({{ $stores->count() }})
            </a>
            @foreach($caviteCities as $city)
                <a href="{{ route('home', ['city' => $city]) }}" style="padding: 8px 18px; border-radius: 30px; font-size: 0.9rem; font-weight: 700; white-space: nowrap; transition: all 0.2s; {{ $selectedCity === $city ? 'background: #b3261e; color: #ffffff;' : 'background: #ffffff; color: #475467; border: 1px solid #d0d5dd;' }}">
                    {{ $city }}
                </a>
            @endforeach
        </div>
    </div>

    <!-- Store Branches Grid -->
    <div style="margin-bottom: 48px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 1.5rem; font-weight: 800;">Featured Lechon Stores</h2>
            <a href="{{ route('locations') }}" style="color: #b3261e; font-weight: 700; font-size: 0.9rem;">View all branches &rarr;</a>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
            @forelse($stores as $store)
                <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); transition: transform 0.2s, box-shadow 0.2s;">
                    <div style="height: 140px; background: linear-gradient(135deg, #ffd9ce 0%, #fff2eb 100%); display: flex; align-items: center; justify-content: center; position: relative;">
                        <i class="fas fa-store" style="font-size: 3rem; color: #b3261e; opacity: 0.8;"></i>
                        <span style="position: absolute; top: 12px; right: 12px; background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 800;">
                            Open for Orders
                        </span>
                    </div>
                    <div style="padding: 18px;">
                        <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">{{ $store->store_name }}</h3>
                        <p style="font-size: 0.85rem; color: #667085; margin-bottom: 12px;">
                            <i class="fas fa-location-dot" style="color: #b3261e; margin-right: 4px;"></i> {{ $store->address ?? $store->city }}
                        </p>
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f2f4f7; padding-top: 12px;">
                            <span style="font-size: 0.82rem; font-weight: 700; color: #475467;">
                                <i class="fas fa-clock" style="margin-right: 4px;"></i> 8:00 AM - 8:00 PM
                            </span>
                            <a href="{{ route('menu', ['store_id' => $store->id]) }}" class="btn-primary" style="padding: 6px 14px; font-size: 0.82rem;">
                                View Menu
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; padding: 40px; text-align: center; background: #ffffff; border-radius: 16px; border: 1px solid #eaecf0;">
                    <p style="color: #667085; font-size: 1.05rem;">No store branches found in this location yet.</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Bestseller Delicacies -->
    <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 1.5rem; font-weight: 800;">🔥 Cavite's Top Bestsellers</h2>
            <a href="{{ route('menu') }}" style="color: #b3261e; font-weight: 700; font-size: 0.9rem;">See full menu &rarr;</a>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px;">
            @foreach($bestsellers as $item)
                <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 16px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="height: 150px; background: #f8f9fa; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; overflow: hidden;">
                            @if($item->image_url)
                                <img src="{{ asset($item->image_url) }}" alt="{{ $item->product_name }}" style="width: 100%; height: 100%; object-fit: cover;">
                            @else
                                <i class="fas fa-drumstick-bite" style="font-size: 2.5rem; color: #b3261e; opacity: 0.7;"></i>
                            @endif
                        </div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #b3261e; text-transform: uppercase;">{{ $item->store->store_name ?? 'Cavite Roaster' }}</span>
                        <h3 style="font-size: 1.1rem; font-weight: 800; margin: 4px 0 6px;">{{ $item->product_name }}</h3>
                        <p style="font-size: 0.85rem; color: #667085; line-height: 1.4; margin-bottom: 12px;">{{ Str::limit($item->description, 60) }}</p>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f2f4f7; padding-top: 12px;">
                        <span style="font-size: 1.25rem; font-weight: 900; color: #101828;">₱{{ number_format($item->price, 2) }}</span>
                        <form action="{{ route('cart.add') }}" method="POST">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $item->id }}">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="btn-primary" style="padding: 7px 14px; font-size: 0.85rem;">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

</div>
@endsection
