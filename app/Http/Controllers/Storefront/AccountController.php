<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerFavorite;
use App\Models\Order;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        return view('storefront.account.profile', compact('user'));
    }

    public function orders()
    {
        $orders = Order::with('items')
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(10);

        return view('storefront.account.orders', compact('orders'));
    }

    public function favorites()
    {
        $favorites = CustomerFavorite::with(['product.store', 'store'])
            ->where('user_id', auth()->id())
            ->get();

        return view('storefront.account.favorites', compact('favorites'));
    }

    public function toggleFavorite(Request $request)
    {
        $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'store_id' => 'nullable|exists:store_locations,id',
        ]);

        $existing = CustomerFavorite::where('user_id', auth()->id())
            ->where('product_id', $request->product_id)
            ->where('store_id', $request->store_id)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['favorited' => false, 'message' => 'Removed from favorites']);
        }

        CustomerFavorite::create([
            'user_id' => auth()->id(),
            'product_id' => $request->product_id,
            'store_id' => $request->store_id,
        ]);

        return response()->json(['favorited' => true, 'message' => 'Added to favorites']);
    }
}
