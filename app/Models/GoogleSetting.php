<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The Google Cloud OAuth client used for Google Meet (System > Google Meet). The secret is stored encrypted. */
class GoogleSetting extends Model
{
    protected $fillable = ['client_id', 'client_secret', 'updated_by'];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted'];
    }
}
