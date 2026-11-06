<?php

namespace App\Enums;

/**
 * What a popup is (Website > Popups).
 *  - Modal:  image + title + text + button, in the middle of the page.
 *  - Corner: a small image in the bottom-left corner (with an optional link).
 * A welcome popup is a modal shown "Once ever" (PopupFrequency::Once).
 */
enum PopupKind: string
{
    case Modal = 'modal';
    case Corner = 'corner';

    public function label(): string
    {
        return match ($this) {
            self::Modal => 'Modal',
            self::Corner => 'Corner',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Modal => 'A modal in the middle of the page.',
            self::Corner => 'A small image in the bottom-left corner.',
        };
    }

    /** Image + title + text + button (Modal), or just an image (Corner). */
    public function hasText(): bool
    {
        return $this !== self::Corner;
    }
}
