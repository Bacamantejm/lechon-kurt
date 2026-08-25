<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $fillable = [
        'product_id',
        'seller_id',
        'name',
        'description',
        'price',
        'stock',
        'labor_cost',
        'category',
        'image',
        'sizes',
        'addons',
        'weight_info',
        'pax_info',
        'lead_time_hours',
        'is_active',
        'is_archived',
        'avg_rating',
        'review_count',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'avg_rating' => 'decimal:2',
        'review_count' => 'integer',
        'stock' => 'integer',
    ];

    // Accessors for backward compatibility
    public function getProductNameAttribute(): string
    {
        return $this->name ?? '';
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image;
    }

    public function getIsAvailableAttribute(): bool
    {
        return (bool)$this->is_active;
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function store()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
