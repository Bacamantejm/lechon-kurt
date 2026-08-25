<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreLocation;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $selectedCity = $request->query('city', 'All');

        $storesQuery = StoreLocation::where('is_active', true);
        if ($selectedCity !== 'All' && !empty($selectedCity)) {
            $storesQuery->where('city', 'like', "%{$selectedCity}%");
        }
        $stores = $storesQuery->get();

        $bestsellers = Product::where('is_active', true)
            ->where('is_archived', false)
            ->take(8)
            ->get();

        $caviteCities = [
            'General Trias', 'Dasmariñas', 'Imus', 'Bacoor', 'Tagaytay', 
            'Silang', 'Tanza', 'Kawit', 'Rosario', 'Cavite City'
        ];

        return view('storefront.home', compact('stores', 'bestsellers', 'caviteCities', 'selectedCity'));
    }

    public function locations()
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
