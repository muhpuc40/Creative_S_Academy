<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file the security scan moved out of the uploads folders into quarantine.
 * It can be restored to where it came from, or deleted for good.
 */
class QuarantinedFile extends Model
{
    protected $table = 'security_quarantines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
