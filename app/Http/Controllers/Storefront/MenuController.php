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
        require_once base_path('includes/config.php');
        require_once base_path('includes/favorites_helper.php');
        require_once base_path('includes/delivery_pricing_helper.php');
        require_once base_path('includes/partner_dashboard_helper.php');

        global $conn;

        $current_page = 'menu';
        $page_title = "Menu & Order | Lechon Delights";

        $scoped_partner_seller_id = 0;
        if (auth()->check()) {
            $_SESSION['user_id'] = auth()->id();
            $_SESSION['full_name'] = auth()->user()->full_name;
            $_SESSION['email'] = auth()->user()->email;
            $_SESSION['user_type'] = auth()->user()->user_type;
            $_SESSION['address'] = auth()->user()->address ?? '';
        }

        $requested_seller_id = (int)$request->query('seller_id', session('storefront_seller_id', 0));
        $requested_branch_id = (int)$request->query('branch_id', $request->query('store_id', 0));

        if ($requested_seller_id > 0) {
            $_SESSION['storefront_seller_id'] = $requested_seller_id;
        }

        return view('storefront.menu', compact(
            'current_page',
            'page_title',
            'requested_seller_id',
            'requested_branch_id',
            'scoped_partner_seller_id'
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
        $_SESSION['cart'] = $cart;

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
        $_SESSION['cart'] = $cart;
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
        $_SESSION['cart'] = $cart;
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
