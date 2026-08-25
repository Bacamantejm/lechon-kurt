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
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_address',
        'delivery_date',
        'delivery_time',
        'estimated_delivery_time',
        'payment_method',
        'subtotal',
        'delivery_fee',
        'voucher_id',
        'voucher_code',
        'voucher_discount',
        'total_amount',
        'status',
        'confirmed_at',
        'special_instructions',
        'is_archived',
        'delivery_option',
        'pickup_location',
        'delivery_location',
        'latitude',
        'longitude',
        'delivery_instructions',
        'payment_status',
        'downpayment_amount',
        'remaining_balance',
        'payment_method_detail',
        'transaction_id',
        'receipt_sent',
        'cancellation_reason',
        'has_proof_of_delivery',
        'actual_delivery_time',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'voucher_discount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'delivery_date' => 'date',
        'estimated_delivery_time' => 'datetime',
        'confirmed_at' => 'datetime',
        'actual_delivery_time' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_archived' => 'boolean',
        'receipt_sent' => 'boolean',
        'has_proof_of_delivery' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store()
    {
        return $this->belongsTo(StoreLocation::class, 'pickup_location', 'store_id');
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
