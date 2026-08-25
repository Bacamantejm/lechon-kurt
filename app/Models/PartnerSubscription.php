<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerSubscription extends Model
{
    use HasFactory;

    protected $table = 'partner_subscriptions';

    protected $fillable = [
        'user_id',
        'store_id',
        'plan_name',
        'plan_tier',
        'billing_cycle',
        'price',
        'start_date',
        'end_date',
        'next_billing_date',
        'status',
        'auto_renew',
        'payment_reference',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_billing_date' => 'date',
        'auto_renew' => 'boolean',
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
}
