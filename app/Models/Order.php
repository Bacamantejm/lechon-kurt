<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'order_number',
        'user_id',
        'store_id',
        'status',
        'payment_method',
        'payment_status',
        'subtotal',
        'delivery_fee',
        'discount_amount',
        'total_amount',
        'delivery_type',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'customer_name',
        'customer_phone',
        'customer_notes',
        'voucher_code',
        'lalamove_order_id',
        'pickup_time',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'delivery_latitude' => 'decimal:8',
        'delivery_longitude' => 'decimal:8',
        'pickup_time' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store()
    {
        return $this->belongsTo(StoreLocation::class, 'store_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function tracking()
    {
        return $this->hasOne(LogisticsTracking::class, 'order_id');
    }
}
