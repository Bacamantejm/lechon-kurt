<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        // Require native helpers and config for complete database parity
        require_once base_path('includes/config.php');
        require_once base_path('includes/favorites_helper.php');
        require_once base_path('includes/store_availability_helper.php');
        require_once base_path('includes/partner_advertisement_helper.php');

        global $conn;

        $is_pickup_mode = $request->query('type') === 'pickup';
        $current_page = $is_pickup_mode ? 'pickup' : 'home';
        $page_title = $is_pickup_mode ? 'Pick-up Stores & Branches' : 'Marketplace Home';

        // Execute query pipeline directly matching index.php
        $current_user_address = '';
        if (auth()->check()) {
            $current_user_address = auth()->user()->address ?? '';
            $_SESSION['user_id'] = auth()->id();
            $_SESSION['full_name'] = auth()->user()->full_name;
            $_SESSION['email'] = auth()->user()->email;
            $_SESSION['user_type'] = auth()->user()->user_type;
        }

        $favorite_store_keys = [];
        if (auth()->check() && function_exists('favoritesFetchUserFavoriteStoreKeyMap') && isset($conn) && $conn) {
            $favorite_store_keys = favoritesFetchUserFavoriteStoreKeyMap($conn, (int)auth()->id());
        }

        $sales_map = [];
        if (isset($conn) && $conn) {
            $sales_sql = "SELECT p.id, COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END),0) total_sold
                          FROM products p
                          LEFT JOIN order_items oi ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                          LEFT JOIN orders o ON oi.order_id = o.id AND o.is_archived = 0 AND o.status <> 'cancelled'
                          WHERE p.is_archived = 0
                          GROUP BY p.id";
            $sales_result = mysqli_query($conn, $sales_sql);
            if ($sales_result) {
                while ($row = mysqli_fetch_assoc($sales_result)) {
                    $sales_map[(int)$row['id']] = (int)$row['total_sold'];
                }
                mysqli_free_result($sales_result);
            }
        }

        $branches = [];
        $branch_by_owner = [];
        $branch_by_email = [];
        $branch_by_name = [];
        $branch_owner_ids = [];
        $branch_owner_images = [];

        if (isset($conn) && $conn) {
            if (function_exists('sahEnsureStoreLocationAvailabilitySchema')) {
                sahEnsureStoreLocationAvailabilitySchema($conn);
            }
            $branch_result = mysqli_query($conn, "SELECT store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, is_active, latitude, longitude FROM store_locations WHERE is_active = 1 ORDER BY store_name");
            if ($branch_result) {
                while ($row = mysqli_fetch_assoc($branch_result)) {
                    $availability = function_exists('sahResolveStoreAvailability') ? sahResolveStoreAvailability($row) : ['is_open' => true, 'label' => 'Open', 'schedule' => $row['opening_hours']];
                    $branch = [
                        'id' => (int)$row['store_id'],
                        'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
                        'name' => trim((string)$row['store_name']),
                        'address' => trim((string)$row['address']),
                        'city' => trim((string)$row['city']),
                        'province' => trim((string)$row['province']),
                        'phone' => trim((string)$row['phone']),
                        'email' => strtolower(trim((string)$row['email'])),
                        'hours' => trim((string)($availability['schedule'] ?? $row['opening_hours'])),
                        'availability' => $availability,
                        'is_open' => !empty($availability['is_open']),
                        'status_label' => trim((string)($availability['label'] ?? 'Closed')),
                        'status_note' => trim((string)($availability['note'] ?? 'Store is currently unavailable.')),
                        'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : null,
                        'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : null
                    ];
                    $branches[] = $branch;
                    if ((int)$branch['owner_user_id'] > 0) {
                        $branch_owner_ids[(int)$branch['owner_user_id']] = true;
                        $branch_by_owner[(int)$branch['owner_user_id']] = $branch;
                    }
                    if ($branch['email'] !== '') $branch_by_email[$branch['email']] = $branch;
                }
                mysqli_free_result($branch_result);
            }
        }

        $scoped_partner_seller_id = 0;
        $stores = [];

        if (isset($conn) && $conn) {
            $seller_result = mysqli_query($conn, "SELECT u.id, u.full_name, u.email, u.phone, u.address, u.business_name, u.business_type, u.created_at, u.profile_image, u.business_logo, fa.business_address, fa.city_name, fa.province_name, fa.barangay_name
                                                  FROM users u
                                                  LEFT JOIN franchise_applications fa ON fa.user_id = u.id AND fa.status = 'approved'
                                                  WHERE u.account_type = 'organization' AND u.is_active = 1
                                                  ORDER BY COALESCE(NULLIF(TRIM(u.business_name), ''), u.full_name)");
            if ($seller_result) {
                while ($row = mysqli_fetch_assoc($seller_result)) {
                    $seller_id = (int)$row['id'];
                    $name = trim((string)$row['business_name']) !== '' ? trim((string)$row['business_name']) : trim((string)$row['full_name']) . ' Store';
                    $match = $branch_by_owner[$seller_id] ?? null;
                    $seller_image = $row['profile_image'] ? asset($row['profile_image']) : asset('images/store-bg.jpg');

                    $stores['seller-' . $seller_id] = [
                        'key' => 'seller-' . $seller_id,
                        'name' => $name,
                        'type' => 'partner',
                        'seller_id' => $seller_id,
                        'branch_id' => $match ? (int)($match['id'] ?? 0) : 0,
                        'business_type' => $row['business_type'] ? ucwords(str_replace('_', ' ', $row['business_type'])) : 'Partner store',
                        'phone' => trim((string)$row['phone']),
                        'location' => $row['address'] ?? ($match['address'] ?? 'Cavite'),
                        'branch' => $match,
                        'latitude' => $match['latitude'] ?? null,
                        'longitude' => $match['longitude'] ?? null,
                        'city' => $row['city_name'] ?? ($match['city'] ?? 'Cavite'),
                        'province' => $row['province_name'] ?? ($match['province'] ?? 'Cavite'),
                        'raw_address' => trim((string)($row['address'] ?? '')),
                        'raw_city' => trim((string)($row['city_name'] ?? '')),
                        'raw_province' => trim((string)($row['province_name'] ?? '')),
                        'image' => $seller_image,
                        'count' => 0,
                        'order_volume' => 0,
                        'start' => 450.00,
                        'rating_sum' => 0,
                        'rating_count' => 0,
                        'rating' => 4.9,
                        'reviews' => 28,
                        'tags' => ['Lechon favorites'],
                        'items' => ['Whole Lechon', 'Lechon Belly'],
                        'live' => true,
                        'is_open' => true,
                        'status_label' => 'Open',
                        'status_note' => 'Open today',
                        'note' => 'Available for delivery',
                        'joined' => date('M Y', strtotime((string)$row['created_at'])),
                        'menu_link' => route('menu', ['seller_id' => $seller_id]),
                        'search' => strtolower($name . ' cavite partner'),
                    ];
                }
                mysqli_free_result($seller_result);
            }
        }

        foreach ($branches as $branch) {
            $key = 'branch-' . $branch['id'];
            if (!isset($stores[$key])) {
                $stores[$key] = [
                    'key' => $key,
                    'name' => $branch['name'],
                    'type' => 'branch',
                    'seller_id' => (int)($branch['owner_user_id'] ?? 0),
                    'branch_id' => (int)$branch['id'],
                    'business_type' => 'Pickup branch',
                    'phone' => $branch['phone'],
                    'location' => $branch['address'] . ', ' . $branch['city'],
                    'branch' => $branch,
                    'latitude' => $branch['latitude'],
                    'longitude' => $branch['longitude'],
                    'city' => $branch['city'],
                    'province' => $branch['province'],
                    'raw_address' => $branch['address'],
                    'raw_city' => $branch['city'],
                    'raw_province' => $branch['province'],
                    'image' => asset('images/store-bg.jpg'),
                    'count' => 5,
                    'order_volume' => 10,
                    'start' => 450.00,
                    'rating_sum' => 25,
                    'rating_count' => 5,
                    'rating' => 4.9,
                    'reviews' => 18,
                    'tags' => ['Pickup branch', 'Lechon favorites'],
                    'items' => ['Whole Lechon', 'Lechon Belly'],
                    'live' => true,
                    'is_open' => $branch['is_open'],
                    'status_label' => $branch['status_label'],
                    'status_note' => $branch['status_note'],
                    'note' => 'Available for pickup',
                    'joined' => 'Branch network',
                    'menu_link' => route('menu', ['store_id' => $branch['id']]),
                    'search' => strtolower($branch['name'] . ' ' . $branch['city'] . ' branch'),
                ];
            }
        }

        $top_rated_stores = array_slice($stores, 0, 8);

        // Featured Products & Bestsellers
        $featured_products = [];
        if (isset($conn) && $conn) {
            $fp_result = mysqli_query($conn, "SELECT p.id, p.name, p.category, p.price, p.image, p.avg_rating, p.review_count, COALESCE(NULLIF(TRIM(u.business_name), ''), 'Lechon Delights Pitmaster') AS seller_name
                                              FROM products p
                                              LEFT JOIN users u ON p.seller_id = u.id
                                              WHERE p.is_archived = 0 AND p.is_active = 1
                                              ORDER BY p.avg_rating DESC, p.review_count DESC LIMIT 12");
            if ($fp_result) {
                while ($prow = mysqli_fetch_assoc($fp_result)) {
                    $featured_products[] = [
                        'id' => (int)$prow['id'],
                        'name' => $prow['name'],
                        'category' => $prow['category'] ?? 'Whole Lechon',
                        'price' => (float)$prow['price'],
                        'rating' => (float)($prow['avg_rating'] > 0 ? $prow['avg_rating'] : 4.9),
                        'sold' => (int)($sales_map[(int)$prow['id']] ?? 35),
                        'store' => $prow['seller_name'],
                        'image' => $prow['image'] ? asset($prow['image']) : asset('images/menu/whole-lechon.jpg'),
                        'menu_link' => route('menu', ['q' => $prow['name']]),
                    ];
                }
                mysqli_free_result($fp_result);
            }
        }

        $featured_ads = [];
        if (function_exists('paGetActiveAdvertisements') && isset($conn) && $conn) {
            $featured_ads = paGetActiveAdvertisements($conn, 6);
        }

        $visible_store_count = count($stores);
        $registered_store_count = count(array_filter($stores, fn($s) => $s['type'] === 'partner'));
        $pickup_branch_count = count($branches);
        $live_store_count = count(array_filter($stores, fn($s) => !empty($s['is_open'])));
        $cavite_branch_count = $pickup_branch_count;
        $spotlights = array_slice($stores, 0, 3);
        $top_brand_stores = array_slice($stores, 0, 6);
        $top_shop_stores = array_slice($branches, 0, 6);

        return view('storefront.home', compact(
            'is_pickup_mode',
            'current_page',
            'page_title',
            'current_user_address',
            'stores',
            'branches',
            'top_rated_stores',
            'featured_products',
            'featured_ads',
            'favorite_store_keys',
            'visible_store_count',
            'registered_store_count',
            'pickup_branch_count',
            'live_store_count',
            'cavite_branch_count',
            'spotlights',
            'top_brand_stores',
            'top_shop_stores',
            'scoped_partner_seller_id'
        ));
    }

    public function locations()
    {
        $locations = \App\Models\StoreLocation::where('is_active', true)->get();
        return view('storefront.locations', compact('locations'));
    }

    public function about()
    {
        return view('storefront.about');
    }

    public function helpCenter()
    {
        return view('storefront.help-center');
    }

    public function faq()
    {
        return view('storefront.faq');
    }
}
