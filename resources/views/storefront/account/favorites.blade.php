@extends('layouts.app')

@section('title', 'My Favorites | Lechon Delights')

@section('content')
<div style="max-width: 1000px; margin: 0 auto; padding: 32px 20px;">
    
    <div style="margin-bottom: 28px;">
        <h1 style="font-size: 2rem; font-weight: 900; margin-bottom: 6px;">Favorite Stores & Dishes</h1>
        <p style="color: #667085; font-size: 0.95rem;">Quickly re-order your favorite Cavite roast meals.</p>
    </div>

    <!-- Navigation Tabs -->
    <div style="display: flex; gap: 12px; border-bottom: 1px solid #eaecf0; padding-bottom: 14px; margin-bottom: 28px;">
        <a href="{{ route('account.profile') }}" style="font-weight: 600; font-size: 0.95rem; color: #667085; padding-bottom: 12px;">
            <i class="fas fa-user-circle" style="margin-right: 6px;"></i> Profile Information
        </a>
        <a href="{{ route('account.orders') }}" style="font-weight: 600; font-size: 0.95rem; color: #667085; padding-bottom: 12px;">
            <i class="fas fa-box-open" style="margin-right: 6px;"></i> My Orders
        </a>
        <a href="{{ route('account.favorites') }}" style="font-weight: 800; font-size: 0.95rem; color: #b3261e; border-bottom: 2px solid #b3261e; padding-bottom: 12px;">
            <i class="fas fa-heart" style="margin-right: 6px;"></i> Favorite Stores & Items
        </a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
        @forelse($favorites as $fav)
            <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
                @if($fav->product)
                    <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 4px;">{{ $fav->product->product_name }}</h3>
                    <p style="font-size: 0.85rem; color: #667085; margin-bottom: 12px;">{{ $fav->product->store->store_name ?? 'Cavite Roaster' }}</p>
                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f2f4f7; padding-top: 12px;">
                        <span style="font-size: 1.15rem; font-weight: 900;">₱{{ number_format($fav->product->price, 2) }}</span>
                        <a href="{{ route('menu', ['store_id' => $fav->product->store_id]) }}" class="btn-primary" style="padding: 6px 14px; font-size: 0.85rem;">Order</a>
                    </div>
                @elseif($fav->store)
                    <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 4px;">{{ $fav->store->store_name }}</h3>
                    <p style="font-size: 0.85rem; color: #667085; margin-bottom: 12px;">{{ $fav->store->city }}</p>
                    <div style="border-top: 1px solid #f2f4f7; padding-top: 12px;">
                        <a href="{{ route('menu', ['store_id' => $fav->store->id]) }}" class="btn-primary" style="width: 100%; text-align: center; padding: 6px 14px; font-size: 0.85rem;">Browse Menu</a>
                    </div>
                @endif
            </div>
        @empty
            <div style="grid-column: 1 / -1; text-align: center; padding: 48px; background: #ffffff; border-radius: 16px; border: 1px solid #eaecf0;">
                <i class="fas fa-heart-crack" style="font-size: 3rem; color: #d0d5dd; margin-bottom: 12px;"></i>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">No Favorites Saved</h3>
                <p style="color: #667085; font-size: 0.9rem; margin-bottom: 18px;">Click the heart icon on any menu dish or store to save it to your favorites.</p>
                <a href="{{ route('menu') }}" class="btn-primary">Explore Menu Items</a>
            </div>
        @endforelse
    </div>

</div>
@endsection
