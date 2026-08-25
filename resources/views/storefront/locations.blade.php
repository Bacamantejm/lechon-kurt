@extends('layouts.app')

@section('title', 'Cavite Store Branches | Lechon Delights Marketplace')

@section('content')
<div style="max-width: 1280px; margin: 0 auto; padding: 32px 20px;">
    
    <div style="text-align: center; margin-bottom: 36px;">
        <h1 style="font-size: 2.2rem; font-weight: 900; margin-bottom: 8px;">Cavite Store Directory & Branches</h1>
        <p style="color: #667085; font-size: 1rem;">Find verified local roasting pits and marketplace partner branches across Cavite.</p>
    </div>

    <!-- Store Locations Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
        @foreach($locations as $loc)
            <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
                <div style="height: 160px; background: linear-gradient(135deg, #ffd9ce 0%, #fff2eb 100%); display: flex; align-items: center; justify-content: center; position: relative;">
                    <i class="fas fa-store" style="font-size: 3.5rem; color: #b3261e; opacity: 0.8;"></i>
                    @if($loc->is_main_branch)
                        <span style="position: absolute; top: 12px; left: 12px; background: #b3261e; color: #ffffff; padding: 4px 10px; border-radius: 12px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase;">
                            Main Hub
                        </span>
                    @endif
                    <span style="position: absolute; top: 12px; right: 12px; background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 800;">
                        Active Roaster
                    </span>
                </div>
                <div style="padding: 20px;">
                    <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 6px;">{{ $loc->store_name }}</h3>
                    <p style="font-size: 0.88rem; color: #667085; margin-bottom: 14px;">
                        <i class="fas fa-location-dot" style="color: #b3261e; margin-right: 6px;"></i> {{ $loc->address ?? $loc->city }}
                    </p>
                    <div style="display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem; color: #475467; margin-bottom: 16px;">
                        <div><i class="fas fa-phone" style="width: 18px; color: #667085;"></i> {{ $loc->phone ?? '+63 (046) Contact Store' }}</div>
                        <div><i class="fas fa-clock" style="width: 18px; color: #667085;"></i> 8:00 AM - 8:00 PM (Daily Roasting)</div>
                    </div>
                    <div style="border-top: 1px solid #f2f4f7; padding-top: 14px;">
                        <a href="{{ route('menu', ['store_id' => $loc->id]) }}" class="btn-primary" style="width: 100%; text-align: center; padding: 10px;">
                            <i class="fas fa-utensils"></i> Order from this Branch
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

</div>
@endsection
