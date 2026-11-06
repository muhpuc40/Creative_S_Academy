<?php

namespace App\Models;

use App\Enums\PopupKind;
use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A website popup: what it shows. When and where it shows is in its setting (PopupSetting).
 * Admin: Website > Popups.  Public API: GET /api/v1/popups
 */
class Popup extends Model
{
    protected $fillable = ['kind', 'image', 'title', 'body', 'button_text', 'link'];

    protected function casts(): array
    {
        return ['kind' => PopupKind::class];
    }

    protected static function booted(): void
    {
        // the setting row goes by the foreign key; the image file goes here
        static::deleted(fn (Popup $popup) => Media::delete($popup->image));
    }

    public function setting(): HasOne
    {
        return $this->hasOne(PopupSetting::class);
    }

    /** Link of the image the browser can open, or null. */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => Media::url($this->image));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('setting', fn (Builder $q) => $q->where('is_active', true));
    }

    /** By the setting's sort_order, then oldest first. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy(PopupSetting::select('sort_order')->whereColumn('popup_settings.popup_id', 'popups.id'))
            ->orderBy('id');
    }
}
