<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\HasAccount;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class Student extends Authenticatable
{
    use HasAccount, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'avatar', 'roll', 'status',
        'date_of_birth', 'gender', 'address', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Link of the avatar image, or null when none is set (same rules as Admin::avatarUrl()). */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar) {
            return null;
        }
        if (str_starts_with($this->avatar, 'http')) {
            return $this->avatar;
        }

        // host-relative (/storage/...), so it loads from whatever host serves the admin page
        $url = Storage::disk('public')->url($this->avatar);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }
}
