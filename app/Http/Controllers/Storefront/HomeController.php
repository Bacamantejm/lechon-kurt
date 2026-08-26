<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerFavorite;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StoreLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $is_pickup_mode = $request->query('type') === 'pickup';
        $current_user_address = auth()->user()?->address ?? '';

        // 1. Fetch active store locations & partner sellers
        $branches = StoreLocation::where('is_active', true)->orderBy('store_name')->get();
        $sellers = User::where('account_type', 'organization')
            ->where('is_active', true)
            ->orderBy('business_name')
            ->get();

        $stores = [];
        foreach ($branches as $branch) {
            $key = 'branch-' . $branch->store_id;
            $stores[$key] = [
                'key' => $key,
                'name' => $branch->store_name,
                'type' => 'branch',
                'seller_id' => $branch->owner_user_id ?? 0,
                'branch_id' => $branch->store_id,
                'business_type' => 'Pickup branch',
                'phone' => $branch->phone ?? '',
                'location' => ($branch->address ? $branch->address . ', ' : '') . ($branch->city ?? 'Cavite'),
                'city' => $branch->city ?? 'Cavite',
                'province' => $branch->province ?? 'Cavite',
                'image' => asset('images/store-bg.jpg'),
                'menu_link' => route('menu', ['store_id' => $branch->store_id]),
                'rating' => 4.9,
                'reviews' => 28,
                'start' => 450.00,
                'is_open' => true,
                'status_label' => 'Open Now',
                'latitude' => (float)$branch->latitude,
                'longitude' => (float)$branch->longitude,
            ];
        }

        foreach ($sellers as $seller) {
            $key = 'seller-' . $seller->id;
            $name = $seller->business_name ?: $seller->full_name . ' Store';
            $stores[$key] = [
                'key' => $key,
                'name' => $name,
                'type' => 'partner',
                'seller_id' => $seller->id,
                'branch_id' => 0,
                'business_type' => $seller->business_type ? ucwords(str_replace('_', ' ', $seller->business_type)) : 'Partner store',
                'phone' => $seller->phone ?? '',
                'location' => $seller->address ?? 'Cavite',
                'city' => 'Cavite',
                'province' => 'Cavite',
                'image' => $seller->profile_image ? asset($seller->profile_image) : asset('images/store-bg.jpg'),
                'menu_link' => route('menu', ['seller_id' => $seller->id]),
                'rating' => 4.8,
                'reviews' => 42,
                'start' => 380.00,
                'is_open' => true,
                'status_label' => 'Open Now',
                'latitude' => null,
                'longitude' => null,
            ];
        }

        // Top rated brands carousel
        $top_rated_stores = array_slice($stores, 0, 8);

        // Featured Bestseller Products
        $featured_products = Product::where('is_active', true)
            ->where('is_archived', false)
            ->take(12)
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'seller_id' => $p->seller_id,
                    'name' => $p->name,
                    'store' => $p->seller?->business_name ?? 'Lechon Delights Roaster',
                    'category' => $p->category ?? 'Whole Lechon',
                    'price' => (float)$p->price,
                    'rating' => (float)($p->avg_rating > 0 ? $p->avg_rating : 4.9),
                    'reviews' => (int)($p->review_count ?: 15),
                    'sold' => (int)($p->stock ? 100 - $p->stock : 25),
                    'image' => $p->image ? asset($p->image) : asset('images/menu/whole-lechon.jpg'),
                    'menu_link' => route('menu', ['q' => $p->name]),
                ];
            });

        // User favorites map
        $favorite_store_keys = [];
        if (auth()->check()) {
            $favs = CustomerFavorite::where('user_id', auth()->id())->get();
            foreach ($favs as $f) {
                if ($f->product_id) {
                    $favorite_store_keys['product_' . $f->product_id] = true;
                }
                if ($f->store_id) {
                    $favorite_store_keys['branch-' . $f->store_id] = true;
                }
            }
        }

        return view('storefront.home', compact(
            'is_pickup_mode',
            'current_user_address',
            'stores',
            'top_rated_stores',
            'featured_products',
            'favorite_store_keys'
        ));
    }

    public function locations(Request $request)
    {
        $locations = StoreLocation::where('is_active', true)->get();
        return view('storefront.locations', compact('locations'));
    }

    public function about()
    {
        return view('storefront.about');
    }

    public function helpCenter()
    {
        return view('storefront.help-center');
    }

    public function faq()
    {
        return view('storefront.faq');
    }
}
