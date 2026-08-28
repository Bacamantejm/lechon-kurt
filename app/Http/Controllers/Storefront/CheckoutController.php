<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreLocation;
use App\Services\DeliveryPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    protected DeliveryPricingService $deliveryPricing;

    public function __construct(DeliveryPricingService $deliveryPricing)
    {
        $this->deliveryPricing = $deliveryPricing;
    }

    public function index(Request $request)
    {
        require_once base_path('includes/config.php');
        require_once base_path('includes/partner_voucher_helper.php');
        require_once base_path('includes/checkout_address_helper.php');
        require_once base_path('includes/delivery_pricing_helper.php');

        global $conn;

        if (auth()->check()) {
            $_SESSION['user_id'] = auth()->id();
            $_SESSION['full_name'] = auth()->user()->full_name;
            $_SESSION['email'] = auth()->user()->email;
            $_SESSION['user_type'] = auth()->user()->user_type;
            $_SESSION['address'] = auth()->user()->address ?? '';
            $_SESSION['phone'] = auth()->user()->phone ?? '';
        }

        $cart = session()->get('cart', $_SESSION['cart'] ?? []);
        $_SESSION['cart'] = $cart;

        if (empty($cart)) {
            return redirect()->route('menu')->with('info', 'Your cart is empty. Choose delicious lechon meals first!');
        }

        $current_page = 'checkout';
        $page_title = "Checkout | Lechon Delights";

        $user_id = auth()->id() ?? (int)($_SESSION['user_id'] ?? 0);
        $user = auth()->check() ? [
            'full_name' => auth()->user()->full_name,
            'email' => auth()->user()->email,
            'phone' => auth()->user()->phone ?? '',
            'address' => auth()->user()->address ?? '',
        ] : [];

        if (isset($conn) && $conn) {
            pvEnsureVoucherSchema($conn);
            caEnsureUserSavedAddressSchema($conn);
            caEnsureDefaultUserProfileAddress(
                $conn,
                $user_id,
                (string)($user['address'] ?? ''),
                (string)($user['full_name'] ?? ''),
                (string)($user['phone'] ?? '')
            );
        }

        $saved_addresses = (isset($conn) && $conn) ? caFetchUserSavedAddresses($conn, $user_id) : [];
        $default_saved_address_id = 0;
        foreach ($saved_addresses as $saved_address_row) {
            if ((int)($saved_address_row['is_default'] ?? 0) === 1) {
                $default_saved_address_id = (int)$saved_address_row['id'];
                break;
            }
        }

        $stores = (isset($conn) && $conn) ? StoreLocation::where('is_active', true)->get()->toArray() : [];
        $_SESSION['store_locations'] = $stores;

        $subtotal = 0;
        $checkout_item_count = 0;
        $checkout_total_quantity = 0;
        foreach ($cart as $item) {
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
            $checkout_item_count++;
            $checkout_total_quantity += (int)($item['quantity'] ?? 0);
        }

        $deliveryPricingConfig = function_exists('dpGetDeliveryPricingConfig') ? dpGetDeliveryPricingConfig() : [];
        $base_delivery_fee = (float)($deliveryPricingConfig['base_fee'] ?? 50);
        $estimatedDeliveryFee = 59.00;

        return view('storefront.checkout', compact(
            'current_page',
            'page_title',
            'user',
            'user_id',
            'cart',
            'stores',
            'saved_addresses',
            'default_saved_address_id',
            'subtotal',
            'checkout_item_count',
            'checkout_total_quantity',
            'estimatedDeliveryFee',
            'base_delivery_fee'
        ));
    }

    public function process(Request $request)
    {
        $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'required|string|max:20',
            'delivery_type' => 'required|in:delivery,pickup',
            'payment_method' => 'required|in:cod,gcash,maya,card',
            'delivery_address' => 'required_if:delivery_type,delivery',
            'store_id' => 'required|exists:store_locations,store_id',
        ]);

        $cart = session()->get('cart', $_SESSION['cart'] ?? []);
        if (empty($cart)) {
            return redirect()->route('menu')->with('error', 'Cart session expired.');
        }

        $subtotal = array_sum(array_map(fn($item) => ($item['price'] ?? 0) * ($item['quantity'] ?? 1), $cart));
        $deliveryFee = ($request->delivery_type === 'pickup') ? 0.00 : 59.00;
        $totalAmount = $subtotal + $deliveryFee;

        $orderNumber = 'LD-' . strtoupper(Str::random(4)) . '-' . rand(1000, 9999);

        DB::beginTransaction();
        try {
            $order = Order::create([
                'order_number' => $orderNumber,
                'user_id' => auth()->id(),
                'pickup_location' => $request->store_id,
                'status' => 'pending',
                'payment_method' => $request->payment_method,
                'payment_status' => ($request->payment_method === 'cod') ? 'pending' : 'paid',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'voucher_discount' => 0.00,
                'total_amount' => $totalAmount,
                'delivery_option' => $request->delivery_type,
                'delivery_date' => now()->toDateString(),
                'delivery_address' => $request->delivery_address ?? 'Store Pick-up',
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_email' => auth()->user()?->email ?? 'guest@lechondelights.com',
                'special_instructions' => $request->customer_notes,
            ]);

            foreach ($cart as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => (string)($item['id'] ?? $item['product_id'] ?? 1),
                    'product_name' => $item['name'] ?? 'Lechon Special',
                    'quantity' => (int)($item['quantity'] ?? 1),
                    'price' => $item['price'] ?? 0,
                    'total' => ($item['price'] ?? 0) * ($item['quantity'] ?? 1),
                ]);
            }

            DB::commit();
            session()->forget('cart');
            unset($_SESSION['cart']);

            return redirect()->route('track.order', ['order_number' => $orderNumber])
                ->with('success', 'Order placed successfully! Track your meal below.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Order placement failed: ' . $e->getMessage());
        }
    }
}
