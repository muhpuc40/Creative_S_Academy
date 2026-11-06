<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * One slide of the home page hero (Website > Hero content in the admin panel).
 * The website shows the active ones as a slider, in sort_order, when the
 * "hero_content" display action is on (Website > Hero settings).
 *
 * Background: bg_color, bg_image, both (the color shows while the image loads) or neither
 * (the website's default background).
 *
 * Public API: GET /api/v1/home -> data.hero.slides
 */
class HeroContent extends Model
{
    protected $table = 'hero_contents';

    protected $fillable = ['image', 'title', 'subtitle', 'button_text', 'button_path', 'bg_color', 'bg_image', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // the image files go with the row
        static::deleted(function (HeroContent $hero) {
            Media::delete($hero->image);
            Media::delete($hero->bg_image);
        });
    }

    /** Link of the slide image the browser can open. */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => Media::url($this->image));
    }

    /** Link of the background image, or null. */
    protected function bgImageUrl(): Attribute
    {
        return Attribute::get(fn () => Media::url($this->bg_image));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Slider order: sort_order, then oldest first. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
