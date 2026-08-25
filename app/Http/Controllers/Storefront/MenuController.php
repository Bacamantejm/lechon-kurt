<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreLocation;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $categories = Product::whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category');

        $stores = StoreLocation::where('is_active', true)->get();

        $selectedStoreId = $request->query('store_id', session('selected_store_id', $stores->first()?->store_id));
        $selectedCategory = $request->query('category');
        $search = $request->query('q');

        $productsQuery = Product::where('is_active', true)->where('is_archived', false);

        if ($selectedCategory) {
            $productsQuery->where('category', $selectedCategory);
        }

        if ($search) {
            $productsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $products = $productsQuery->get();
        $cart = session()->get('cart', []);

        return view('storefront.menu', compact('categories', 'stores', 'products', 'selectedStoreId', 'selectedCategory', 'cart'));
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
            $cart[$id]['quantity'] += (int)$request->quantity;
        } else {
            $cart[$id] = [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float)$product->price,
                'quantity' => (int)$request->quantity,
                'image' => $product->image,
                'store_id' => $product->seller_id,
            ];
        }

        session()->put('cart', $cart);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "{$product->name} added to order!",
                'cart_count' => count($cart),
                'cart' => $cart,
            ]);
        }

        return redirect()->back()->with('success', "{$product->name} added to cart!");
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

    public function show(string $slug)
    {
        $product = Product::where('id', $slug)
            ->orWhere('name', 'like', "%{$slug}%")
            ->firstOrFail();

        return redirect()->route('menu', ['q' => $product->name]);
    }
}
