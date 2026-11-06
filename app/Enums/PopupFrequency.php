<?php

namespace App\Enums;

/**
 * How often one visitor sees a popup. The website remembers it in the visitor's browser.
 * "Once ever" is the welcome popup: shown on the first visit only.
 */
enum PopupFrequency: string
{
    case Once = 'once';
    case PerVisit = 'per_visit';
    case Daily = 'daily';
    case Always = 'always';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Once ever (first visit only)',
            self::PerVisit => 'Once per visit',
            self::Daily => 'Once a day',
            self::Always => 'Every page load',
        };
    }
}
