<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    public function index(Request $request, ?string $order_number = null)
    {
        $orderNumber = $order_number ?? $request->query('order_number');
        $order = null;

        if ($orderNumber) {
            $order = Order::with(['items.product', 'store', 'tracking'])
                ->where('order_number', $orderNumber)
                ->first();
        }

        $stages = [
            'pending' => ['label' => 'Order Received', 'icon' => 'fas fa-clipboard-check', 'step' => 1],
            'preparing' => ['label' => 'Roasting & Packing', 'icon' => 'fas fa-fire-burner', 'step' => 2],
            'on_the_way' => ['label' => 'Rider on the Way', 'icon' => 'fas fa-motorcycle', 'step' => 3],
            'delivered' => ['label' => 'Delivered & Enjoy', 'icon' => 'fas fa-house-circle-check', 'step' => 4],
        ];

        return view('storefront.track-order', compact('order', 'orderNumber', 'stages'));
    }
}
