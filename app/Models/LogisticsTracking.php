<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogisticsTracking extends Model
{
    use HasFactory;

    protected $table = 'logistics_tracking';

    protected $fillable = [
        'order_id',
        'tracking_number',
        'logistics_provider_id',
        'delivery_method_id',
        'driver_id',
        'driver_name',
        'driver_phone',
        'driver_vehicle',
        'current_status',
        'status_timestamp',
        'pickup_time',
        'delivery_time',
        'estimated_delivery',
        'current_latitude',
        'current_longitude',
        'last_location_update',
        'special_instructions',
        'external_tracking_id',
        'external_tracking_url',
        'total_distance_km',
        'cost',
        'proof_of_delivery_path',
        'proof_of_delivery_timestamp',
        'customer_signature_path',
        'customer_name_confirmed',
        'delivery_notes',
        'failed_reason',
        'failed_timestamp',
        'attempts',
    ];

    protected $casts = [
        'status_timestamp' => 'datetime',
        'pickup_time' => 'datetime',
        'delivery_time' => 'datetime',
        'estimated_delivery' => 'datetime',
        'current_latitude' => 'decimal:8',
        'current_longitude' => 'decimal:8',
        'last_location_update' => 'datetime',
        'total_distance_km' => 'decimal:2',
        'cost' => 'decimal:2',
        'proof_of_delivery_timestamp' => 'datetime',
        'failed_timestamp' => 'datetime',
        'attempts' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
