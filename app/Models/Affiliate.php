<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\HasAccount;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Affiliate login account. Same shape and auth procedure as Student/Instructor/Admin
 * (register -> OTP -> login, device limits, Sanctum tokens via HasAccount).
 * The affiliate PROGRAM data (ref code, approval, payout info) lives on AffiliateProfile.
 */
class Affiliate extends Authenticatable
{
    use HasAccount, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'avatar', 'status', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(AffiliateProfile::class);
    }
}
