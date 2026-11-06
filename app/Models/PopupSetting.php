<?php

namespace App\Models;

use App\Enums\PopupFrequency;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When and where one popup shows.
 *
 * pages: a list of paths. "*" = every page, "/" = the home page, "/courses/*" = every page
 *        under /courses, "/courses/ui-ux" = that page only.
 * delay_seconds: counted from when the visitor arrived on the site, not from each page.
 */
class PopupSetting extends Model
{
    public const ALL_PAGES = ['*'];

    public const HOME_ONLY = ['/'];

    protected $fillable = ['is_active', 'sort_order', 'pages', 'delay_seconds', 'frequency'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'pages' => 'array',
            'delay_seconds' => 'integer',
            'frequency' => PopupFrequency::class,
        ];
    }

    public function popup(): BelongsTo
    {
        return $this->belongsTo(Popup::class);
    }

    /** "all", "home" or "specific": how the admin form shows the pages. */
    protected function pagesMode(): Attribute
    {
        return Attribute::get(fn () => match ($this->pages) {
            self::ALL_PAGES => 'all',
            self::HOME_ONLY => 'home',
            default => 'specific',
        });
    }
}
