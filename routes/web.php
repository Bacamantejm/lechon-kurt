<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\MenuController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\OrderTrackingController;
use App\Http\Controllers\Storefront\AccountController;
use App\Http\Controllers\Auth\AuthController;

// Storefront Public Routes
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/menu', [MenuController::class, 'index'])->name('menu');
Route::get('/menu/{slug}', [MenuController::class, 'show'])->name('menu.show');
Route::get('/locations', [HomeController::class, 'locations'])->name('locations');
Route::get('/about', [HomeController::class, 'about'])->name('about');
Route::get('/help-center', [HomeController::class, 'helpCenter'])->name('help-center');
Route::get('/faq', [HomeController::class, 'faq'])->name('faq');

// Cart & Checkout
Route::post('/cart/add', [MenuController::class, 'addToCart'])->name('cart.add');
Route::post('/cart/update', [MenuController::class, 'updateCart'])->name('cart.update');
Route::post('/cart/remove', [MenuController::class, 'removeFromCart'])->name('cart.remove');
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
Route::post('/checkout/process', [CheckoutController::class, 'process'])->name('checkout.process');

// Live Order Tracking
Route::get('/track-order/{order_number?}', [OrderTrackingController::class, 'index'])->name('track.order');

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('register.post');
Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

// Customer Account Routes
Route::get('/my-account', [AccountController::class, 'index'])->name('account.profile');
Route::post('/my-account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
Route::post('/my-account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
Route::get('/my-orders', [AccountController::class, 'orders'])->name('account.orders');
Route::get('/my-favorites', [AccountController::class, 'favorites'])->name('account.favorites');
Route::post('/favorites/toggle', [AccountController::class, 'toggleFavorite'])->name('favorites.toggle');

// Legacy .php Compatibility Routes (Prevents 404 when clicking legacy links)
Route::get('/index.php', function () { return redirect('/'); });
Route::get('/menu.php', function () { return redirect('/menu'); });
Route::get('/locations.php', function () { return redirect('/locations'); });
Route::get('/about.php', function () { return redirect('/about'); });
Route::get('/help_center.php', function () { return redirect('/help-center'); });
Route::get('/faq.php', function () { return redirect('/faq'); });
Route::get('/checkout.php', function () { return redirect('/checkout'); });
Route::get('/track_order.php', function (\Illuminate\Http\Request $request) {
    $orderNumber = $request->query('order_number') ?: $request->query('order_id');
    return $orderNumber ? redirect('/track-order/' . $orderNumber) : redirect('/track-order');
});
Route::get('/login.php', [AuthController::class, 'showLogin']);
Route::get('/register.php', [AuthController::class, 'showRegister']);
Route::get('/my_account.php', function () { return redirect('/my-account'); });
Route::get('/my_orders.php', function () { return redirect('/my-orders'); });
Route::get('/logout.php', [AuthController::class, 'logout']);
