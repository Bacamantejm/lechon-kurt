<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'price',
        'quantity',
        'size',
        'addons',
        'total',
        'is_reviewed',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total' => 'decimal:2',
        'quantity' => 'integer',
        'is_reviewed' => 'boolean',
    ];

    public function getUnitPriceAttribute(): float
    {
        return (float)($this->price ?? 0);
    }

    public function getSubtotalAttribute(): float
    {
        return (float)($this->total ?? 0);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
