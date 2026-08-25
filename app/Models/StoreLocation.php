<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreLocation extends Model
{
    use HasFactory;

    protected $table = 'store_locations';
    protected $primaryKey = 'store_id';

    protected $fillable = [
        'owner_user_id',
        'store_name',
        'address',
        'city',
        'province',
        'phone',
        'email',
        'opening_hours',
        'opening_time',
        'closing_time',
        'operating_days',
        'availability_mode',
        'manual_status',
        'is_active',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    public function getIdAttribute()
    {
        return $this->store_id;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'seller_id', 'owner_user_id');
    }
}
