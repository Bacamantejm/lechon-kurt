<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StoreLocation;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $categories = ProductCategory::where('is_active', true)->orderBy('sort_order')->get();
        $stores = StoreLocation::where('is_active', true)->get();

        $selectedStoreId = $request->query('store_id', session('selected_store_id', $stores->first()?->id));
        $selectedCategoryId = $request->query('category_id');
        $search = $request->query('q');

        $productsQuery = Product::with(['store', 'category'])->where('is_available', true);

        if ($selectedStoreId) {
            $productsQuery->where('store_id', $selectedStoreId);
        }

        if ($selectedCategoryId) {
            $productsQuery->where('category_id', $selectedCategoryId);
        }

        if ($search) {
            $productsQuery->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $products = $productsQuery->get();
        $cart = session()->get('cart', []);

        return view('storefront.menu', compact('categories', 'stores', 'products', 'selectedStoreId', 'cart'));
    }

    public function addToCart(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($request->product_id);
        $cart = session()->get('cart', []);

        $id = $product->id;
        if (isset($cart[$id])) {
            $cart[$id]['quantity'] += $request->quantity;
        } else {
            $cart[$id] = [
                'id' => $product->id,
                'name' => $product->product_name,
                'price' => (float)$product->price,
                'quantity' => (int)$request->quantity,
                'image' => $product->image_url,
                'store_id' => $product->store_id,
            ];
        }

        session()->put('cart', $cart);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "{$product->product_name} added to order!",
                'cart_count' => count($cart),
                'cart' => $cart,
            ]);
        }

        return redirect()->back()->with('success', "{$product->product_name} added to cart!");
    }

    public function updateCart(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'quantity' => 'required|integer|min:0',
        ]);

        $cart = session()->get('cart', []);
        $id = $request->product_id;

        if ($request->quantity <= 0) {
            unset($cart[$id]);
        } elseif (isset($cart[$id])) {
            $cart[$id]['quantity'] = (int)$request->quantity;
        }

        session()->put('cart', $cart);

        return response()->json([
            'success' => true,
            'cart_count' => count($cart),
            'cart' => $cart,
        ]);
    }

    public function removeFromCart(Request $request)
    {
        $request->validate(['product_id' => 'required']);
        $cart = session()->get('cart', []);

        unset($cart[$request->product_id]);
        session()->put('cart', $cart);

        return response()->json([
            'success' => true,
            'cart_count' => count($cart),
            'cart' => $cart,
        ]);
    }
}
