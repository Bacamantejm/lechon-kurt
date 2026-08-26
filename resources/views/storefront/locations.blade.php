@extends('layouts.app')

@section('title', 'Cavite Store Branches | Lechon Delights Marketplace')

@section('content')
<div style="max-width: 1280px; margin: 0 auto; padding: 36px 20px 80px;">
    
    <div style="text-align: center; margin-bottom: 36px;">
        <h1 style="font-size: 2.2rem; font-weight: 900; color: #101828; margin-bottom: 8px;">Cavite Store Directory &amp; Hubs</h1>
        <p style="color: #667085; font-size: 1rem; margin: 0 auto; max-width: 600px;">Find verified local roasting pits, pickup branches, and marketplace partner roasters across Cavite.</p>
    </div>

    <!-- Store Locations Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
        @foreach($locations as $loc)
            <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 18px; overflow: hidden; box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="height: 160px; background: linear-gradient(135deg, #ffd9ce 0%, #fff2eb 100%); display: flex; align-items: center; justify-content: center; position: relative;">
                        <i class="fas fa-store" style="font-size: 3.5rem; color: #b3261e; opacity: 0.85;"></i>
                        <span style="position: absolute; top: 12px; right: 12px; background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 800;">
                            Open for Roasting
                        </span>
                    </div>
                    <div style="padding: 20px;">
                        <h3 style="font-size: 1.2rem; font-weight: 800; color: #101828; margin: 0 0 6px 0;">{{ $loc->store_name }}</h3>
                        <p style="font-size: 0.88rem; color: #667085; margin: 0 0 14px 0;">
                            <i class="fas fa-location-dot" style="color: #b3261e; margin-right: 6px;"></i> {{ $loc->address ?? $loc->city }}
                        </p>
                        <div style="display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem; color: #475467; margin-bottom: 16px;">
                            <div><i class="fas fa-phone" style="width: 18px; color: #667085;"></i> {{ $loc->phone ?? '+63 (046) Contact Store' }}</div>
                            <div><i class="fas fa-clock" style="width: 18px; color: #667085;"></i> 8:00 AM - 8:00 PM (Daily Roasting)</div>
                        </div>
                    </div>
                </div>
                <div style="padding: 0 20px 20px 20px;">
                    <a href="{{ route('menu', ['store_id' => $loc->store_id]) }}" class="btn-primary" style="display: block; text-align: center; padding: 12px; border-radius: 10px; font-weight: 800; text-decoration: none; background: #b3261e; color: #fff;">
                        <i class="fas fa-utensils" style="margin-right: 6px;"></i> Order from this Hub
                    </a>
                </div>
            </div>
        @endforeach
    </div>

</div>
@endsection
