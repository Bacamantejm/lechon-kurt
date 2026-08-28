<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreLocation;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    public function index(Request $request, ?string $order_number = null)
    {
        require_once base_path('includes/config.php');
        if (file_exists(base_path('logistics_service.php'))) {
            require_once base_path('logistics_service.php');
        }

        global $conn;

        $google_maps_api_key = function_exists('getGoogleMapsApiKey')
            ? getGoogleMapsApiKey()
            : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));
        $google_geocoding_enabled = function_exists('shouldUseGoogleGeocoding') ? shouldUseGoogleGeocoding() : true;

        $user_id = auth()->id() ?? (int)($_SESSION['user_id'] ?? 1);
        $order_id = (int)$request->query('order_id', 0);
        $orderNumber = $order_number ?? $request->query('order_number');

        $order = null;
        if ($order_id > 0) {
            $order = Order::find($order_id);
        } elseif ($orderNumber) {
            $order = Order::where('order_number', $orderNumber)->first();
        }

        if (!$order && auth()->check()) {
            $order = Order::where('user_id', auth()->id())->latest()->first();
        }

        if (!$order) {
            $order = Order::latest()->first();
        }

        $order_items = [];
        $store_details = null;
        $is_pickup = false;
        $order_id = $order ? $order->id : 0;

        if ($order) {
            $order_id = $order->id;
            $order = $order->toArray();
            $is_pickup = (strtolower(trim((string)($order['delivery_option'] ?? 'delivery'))) === 'pickup');

            $order_items = OrderItem::leftJoin('products', function ($join) {
                $join->on('products.id', '=', 'order_items.product_id')
                    ->orOn('products.product_id', '=', 'order_items.product_id');
            })
            ->where('order_items.order_id', $order_id)
            ->select('order_items.*', 'products.image as product_image')
            ->get()
            ->toArray();

            if (!empty($order['pickup_location'])) {
                $store = StoreLocation::where('store_id', $order['pickup_location'])->first();
                if ($store) {
                    $store_details = $store->toArray();
                }
            }
        }

        return view('storefront.track-order', compact(
            'order',
            'order_id',
            'user_id',
            'is_pickup',
            'order_items',
            'store_details',
            'google_maps_api_key',
            'google_geocoding_enabled'
        ));
    }
}
