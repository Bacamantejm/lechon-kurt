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

    public function index()
    {
        $cart = session()->get('cart', []);
        if (empty($cart)) {
            return redirect()->route('menu')->with('info', 'Your cart is empty. Choose delicious lechon meals first!');
        }

        $stores = StoreLocation::where('is_active', true)->get();
        $subtotal = array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cart));
        $estimatedDeliveryFee = 59.00;

        return view('storefront.checkout', compact('cart', 'stores', 'subtotal', 'estimatedDeliveryFee'));
    }

    public function process(Request $request)
    {
        $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'required|string|max:20',
            'delivery_type' => 'required|in:delivery,pickup',
            'payment_method' => 'required|in:cod,gcash,maya,card',
            'delivery_address' => 'required_if:delivery_type,delivery',
            'store_id' => 'required|exists:store_locations,id',
        ]);

        $cart = session()->get('cart', []);
        if (empty($cart)) {
            return redirect()->route('menu')->with('error', 'Cart session expired.');
        }

        $subtotal = array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cart));
        $deliveryFee = ($request->delivery_type === 'pickup') ? 0.00 : 59.00;
        $totalAmount = $subtotal + $deliveryFee;

        $orderNumber = 'LD-' . strtoupper(Str::random(4)) . '-' . rand(1000, 9999);

        DB::beginTransaction();
        try {
            $order = Order::create([
                'order_number' => $orderNumber,
                'user_id' => auth()->id(),
                'store_id' => $request->store_id,
                'status' => 'pending',
                'payment_method' => $request->payment_method,
                'payment_status' => ($request->payment_method === 'cod') ? 'pending' : 'paid',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount_amount' => 0.00,
                'total_amount' => $totalAmount,
                'delivery_type' => $request->delivery_type,
                'delivery_address' => $request->delivery_address ?? 'Store Pick-up',
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_notes' => $request->customer_notes,
            ]);

            foreach ($cart as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['id'],
                    'product_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'subtotal' => $item['price'] * $item['quantity'],
                ]);
            }

            DB::commit();
            session()->forget('cart');

            return redirect()->route('track.order', ['order_number' => $orderNumber])
                ->with('success', 'Order placed successfully! Track your meal below.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Order placement failed: ' . $e->getMessage());
        }
    }
}
