<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Preorder extends Model
{
    use HasFactory;

    protected $table = 'preorders';

    protected $fillable = [
        'preorder_number',
        'user_id',
        'store_id',
        'item_type',
        'whole_lechon_size',
        'quantity',
        'target_date',
        'target_time',
        'fulfillment_type',
        'delivery_address',
        'special_requests',
        'estimated_total',
        'downpayment_amount',
        'downpayment_status',
        'downpayment_proof',
        'status',
        'customer_name',
        'customer_phone',
        'customer_email',
        'approved_at',
        'completed_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'estimated_total' => 'decimal:2',
        'downpayment_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store()
    {
        return $this->belongsTo(StoreLocation::class, 'store_id');
    }
}
