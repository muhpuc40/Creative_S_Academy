<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The Meta Pixel of the student website (System > Meta Pixel). Always one row (id = 1). */
class MetaPixelSetting extends Model
{
    protected $fillable = ['pixel_id', 'is_active', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /** What the website should load: the ID when it is set and switched on, otherwise null (no pixel). */
    public static function activeId(): ?string
    {
        $settings = static::query()->find(1);

        return $settings?->is_active && $settings->pixel_id ? $settings->pixel_id : null;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }
}
