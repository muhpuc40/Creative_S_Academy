<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The academy's connected Google account (System > Google Meet). Tokens are stored encrypted. */
class GoogleAccount extends Model
{
    protected $fillable = ['email', 'refresh_token', 'access_token', 'access_token_expires_at', 'connected_by'];

    protected $hidden = ['refresh_token', 'access_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'connected_by');
    }
}
