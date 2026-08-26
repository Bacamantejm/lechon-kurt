<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreLocation;
use App\Models\User;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $sellerId = $request->query('seller_id', session('storefront_seller_id', 0));
        $branchId = $request->query('branch_id', $request->query('store_id', 0));

        $storeLocations = StoreLocation::where('is_active', true)->get();
        $selectedStore = null;

        if ($branchId > 0) {
            $selectedStore = StoreLocation::where('store_id', $branchId)->first();
        } elseif ($sellerId > 0) {
            $seller = User::find($sellerId);
            if ($seller) {
                $selectedStore = (object)[
                    'id' => $seller->id,
                    'store_id' => $seller->id,
                    'store_name' => $seller->business_name ?: $seller->full_name . ' Store',
                    'address' => $seller->address ?? 'Cavite',
                    'city' => 'Cavite',
                    'phone' => $seller->phone ?? '',
                    'rating' => 4.9,
                    'reviews' => 42,
                ];
            }
        }

        if (!$selectedStore && $storeLocations->isNotEmpty()) {
            $selectedStore = $storeLocations->first();
        }

        // Fetch products
        $productsQuery = Product::where('is_active', true)->where('is_archived', false);
        if ($sellerId > 0) {
            $productsQuery->where('seller_id', $sellerId);
        }

        $allProducts = $productsQuery->orderBy('category')->orderBy('name')->get();

        $menuCategories = [];
        $productDetails = [];

        foreach ($allProducts as $p) {
            $cat = $p->category ?: 'Signature Lechon';
            if (!isset($menuCategories[$cat])) {
                $menuCategories[$cat] = [];
            }

            // Parse sizes and addons
            $sizes = ['Regular'];
            $sizePrices = ['Regular' => (float)$p->price];
            $weights = ['Regular' => $p->weight_info ?? '1kg'];
            $goodFor = ['Regular' => $p->pax_info ?? '3-4 persons'];

            if (!empty($p->sizes)) {
                $parsedSizes = json_decode($p->sizes, true);
                if (is_array($parsedSizes)) {
                    $sizes = [];
                    foreach ($parsedSizes as $sName => $sPrice) {
                        $sizes[] = $sName;
                        $sizePrices[$sName] = (float)$sPrice;
                    }
                }
            }

            $addons = [];
            if (!empty($p->addons)) {
                $parsedAddons = json_decode($p->addons, true);
                if (is_array($parsedAddons)) {
                    $addons = $parsedAddons;
                }
            }

            $imageSrc = $p->image ? asset($p->image) : asset('images/menu/whole-lechon.jpg');

            $itemData = [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description ?? 'Freshly roasted Cavite lechon prepared with aromatic herbs and native spices.',
                'price' => (float)$p->price,
                'category' => $cat,
                'image' => $imageSrc,
                'sizes' => $sizes,
                'size_prices' => $sizePrices,
                'weights' => $weights,
                'good_for' => $goodFor,
                'addons' => $addons,
                'stock' => (int)($p->stock ?? 10),
                'avg_rating' => (float)($p->avg_rating > 0 ? $p->avg_rating : 4.9),
                'review_count' => (int)($p->review_count ?: 18),
            ];

            $menuCategories[$cat][] = $itemData;
            $productDetails[$p->id] = $itemData;
        }

        $cart = session()->get('cart', []);
        $deliveryOption = session()->get('delivery_option', 'pickup');

        return view('storefront.menu', compact(
            'menuCategories',
            'productDetails',
            'storeLocations',
            'selectedStore',
            'cart',
            'deliveryOption'
        ));
    }

    public function addToCart(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($request->product_id);
        $cart = session()->get('cart', []);

        $size = $request->input('size', 'Regular');
        $addons = $request->input('addons', []);
        $addonPrice = 0.00;
        $sizePrice = (float)$product->price;

        if (!empty($product->sizes)) {
            $parsedSizes = json_decode($product->sizes, true);
            if (is_array($parsedSizes) && isset($parsedSizes[$size])) {
                $sizePrice = (float)$parsedSizes[$size];
            }
        }

        $cartKey = $product->id . '_' . md5($size . json_encode($addons));
        $unitPrice = $sizePrice + $addonPrice;

        if (isset($cart[$cartKey])) {
            $cart[$cartKey]['quantity'] += (int)$request->quantity;
        } else {
            $cart[$cartKey] = [
                'id' => $product->id,
                'key' => $cartKey,
                'name' => $product->name,
                'size' => $size,
                'addons' => $addons,
                'price' => $unitPrice,
                'quantity' => (int)$request->quantity,
                'image' => $product->image ? asset($product->image) : asset('images/menu/whole-lechon.jpg'),
                'store_id' => $product->seller_id,
            ];
        }

        session()->put('cart', $cart);

        $subtotal = array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cart));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "{$product->name} added to cart!",
                'cart_count' => count($cart),
                'subtotal' => $subtotal,
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
        $subtotal = array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cart));

        return response()->json([
            'success' => true,
            'cart_count' => count($cart),
            'subtotal' => $subtotal,
            'cart' => $cart,
        ]);
    }

    public function removeFromCart(Request $request)
    {
        $request->validate(['product_id' => 'required']);
        $cart = session()->get('cart', []);

        unset($cart[$request->product_id]);
        session()->put('cart', $cart);
        $subtotal = array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cart));

        return response()->json([
            'success' => true,
            'cart_count' => count($cart),
            'subtotal' => $subtotal,
            'cart' => $cart,
        ]);
    }

    public function show(string $slug)
    {
        $product = Product::where('id', $slug)
            ->orWhere('name', 'like', "%{$slug}%")
            ->firstOrFail();

        return redirect()->route('menu', ['q' => $product->name, 'product_id' => $product->id]);
    }
}
