<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StoreLocation;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
{
    public function test_cart_operations_and_order_placement(): void
    {
        $product = Product::where('is_active', true)->first();
        $store = StoreLocation::where('is_active', true)->first();

        if (!$product || !$store) {
            $this->markTestSkipped('No product or store found for checkout test');
        }

        // 1. Add to Cart
        $addRes = $this->post('/cart/add', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $addRes->assertSessionHas('cart');

        // 2. View Checkout
        $checkoutRes = $this->get('/checkout');
        $checkoutRes->assertStatus(200);
        $checkoutRes->assertSee($product->name);

        // 3. Process Checkout
        $orderRes = $this->post('/checkout/process', [
            'customer_name' => 'Automated Test Customer',
            'customer_phone' => '09171234567',
            'delivery_type' => 'delivery',
            'payment_method' => 'cod',
            'delivery_address' => 'Unit 101, Test Street, Dasmariñas, Cavite',
            'store_id' => $store->store_id,
            'customer_notes' => 'Test order placement',
        ]);

        $orderRes->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Automated Test Customer',
            'customer_phone' => '09171234567',
            'payment_method' => 'cod',
        ]);
    }
}
