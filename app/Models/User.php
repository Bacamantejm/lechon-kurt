<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'email',
        'password',
        'full_name',
        'phone',
        'address',
        'profile_image',
        'business_logo',
        'user_type',
        'role_id',
        'account_type',
        'business_name',
        'business_type',
        'business_registration',
        'website',
        'tax_id',
        'is_active',
        'account_control_status',
        'access_restriction_notes',
        'access_restricted_at',
        'access_restricted_by',
        'middle_name',
        'birth_date',
        'gender',
        'nickname',
        'email_otp_code',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_otp_code',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'birth_date' => 'date',
        ];
    }

    public function isCustomer(): bool
    {
        return $this->user_type === 'customer';
    }

    public function isAdmin(): bool
    {
        return in_array($this->user_type, ['admin', 'super_admin']);
    }

    public function isEmployee(): bool
    {
        return $this->user_type === 'employee';
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function preorders()
    {
        return $this->hasMany(Preorder::class, 'user_id');
    }

    public function favorites()
    {
        return $this->hasMany(CustomerFavorite::class, 'user_id');
    }

    public function subscription()
    {
        return $this->hasOne(PartnerSubscription::class, 'user_id')->latestOfMany();
    }
}
