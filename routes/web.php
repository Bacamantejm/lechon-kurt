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
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Customer Account Protected Routes
Route::middleware('auth')->group(function () {
    Route::get('/my-account', [AccountController::class, 'index'])->name('account.profile');
    Route::get('/my-orders', [AccountController::class, 'orders'])->name('account.orders');
    Route::get('/my-favorites', [AccountController::class, 'favorites'])->name('account.favorites');
    Route::post('/favorites/toggle', [AccountController::class, 'toggleFavorite'])->name('favorites.toggle');
});
