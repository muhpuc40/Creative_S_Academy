<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\HasAccount;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Support\Media;


class Admin extends Authenticatable
{
    use HasAccount, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar',
        'status',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }
    public function avatarUrl(): ?string
    {
        if (!$this->avatar) {
            return null;
        }
        $url = Media::url($this->avatar);
        return $url;
    }
}
