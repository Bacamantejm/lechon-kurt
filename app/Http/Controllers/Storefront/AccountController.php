<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerFavorite;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $totalOrders = Order::where('user_id', $user->id)->count();
        $activeOrders = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'preparing', 'on_the_way'])
            ->count();
        $favoriteCount = CustomerFavorite::where('user_id', $user->id)->count();

        return view('storefront.account.profile', compact('user', 'totalOrders', 'activeOrders', 'favoriteCount'));
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'full_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        $user->update([
            'full_name' => $request->full_name,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        return redirect()->back()->with('success', 'Profile information updated successfully!');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return redirect()->back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return redirect()->back()->with('success', 'Your password has been changed securely.');
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
            'store_id' => 'nullable|exists:store_locations,store_id',
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
