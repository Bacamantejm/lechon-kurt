<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $fillable = [
        'store_id',
        'category_id',
        'product_name',
        'description',
        'price',
        'original_price',
        'image_url',
        'is_available',
        'is_bestseller',
        'is_featured',
        'preparation_time_minutes',
        'rating',
        'reviews_count',
        'portion_size',
        'sku',
        'stock_quantity',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'is_available' => 'boolean',
        'is_bestseller' => 'boolean',
        'is_featured' => 'boolean',
        'rating' => 'decimal:2',
        'reviews_count' => 'integer',
        'stock_quantity' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(StoreLocation::class, 'store_id');
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'product_id');
    }
}
