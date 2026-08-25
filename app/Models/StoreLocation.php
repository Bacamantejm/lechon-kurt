<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreLocation extends Model
{
    use HasFactory;

    protected $table = 'store_locations';

    protected $fillable = [
        'store_name',
        'branch_name',
        'address',
        'city',
        'province',
        'latitude',
        'longitude',
        'phone',
        'email',
        'opening_time',
        'closing_time',
        'is_active',
        'is_main_branch',
        'store_image',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_active' => 'boolean',
        'is_main_branch' => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'store_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'store_id');
    }
}
