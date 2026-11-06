<?php

namespace App\Enums;

/**
 * Every switch the admin has for what the website shows (table hero_settings, one row each).
 *
 * To add a new action (e.g. "popup_show"): add a case here and fill in the methods below.
 * Nothing else is needed: its row is created on first use with the default, the admin page
 * (Website > Hero settings) shows it, and GET /api/v1/home sends it in data.display.
 */
enum DisplayAction: string
{
    case DefaultCourseList = 'default_course_list';
    case HeroContent = 'hero_content';
    case HeroSliderInterval = 'hero_slider_interval';

    public function label(): string
    {
        return match ($this) {
            self::DefaultCourseList => 'Course list',
            self::HeroContent => 'Hero content slider',
            self::HeroSliderInterval => 'Auto-slide',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DefaultCourseList => 'A slider of all published courses.',
            self::HeroContent => 'The slides from Website',
            self::HeroSliderInterval => 'Move to the next slide by itself. Pauses while a visitor points at or touches the slider.',
        };
    }

    /**
     * Actions in the same group are a choice of one: turning one on turns the others off.
     * The hero can show the course list OR the hero content, never both.
     */
    public function group(): ?string
    {
        return match ($this) {
            self::DefaultCourseList, self::HeroContent => 'hero_mode',
            default => null,
        };
    }

    public function defaultStatus(): bool
    {
        return match ($this) {
            self::DefaultCourseList, self::HeroSliderInterval => true,
            self::HeroContent => false,
        };
    }

    /** Allowed values (value => label), or null when the action is only on/off. */
    public function options(): ?array
    {
        return match ($this) {
            self::HeroSliderInterval => [3000 => '3 seconds', 5000 => '5 seconds', 7000 => '7 seconds', 10000 => '10 seconds', 15000 => '15 seconds', 20000 => '20 seconds', 30000 => '30 seconds', 45000 => '45 seconds', 60000 => '1 Minute'],
            default => null,
        };
    }

    public function defaultValue(): int|string|null
    {
        return match ($this) {
            self::HeroSliderInterval => 5000,
            default => null,
        };
    }

    /** The stored value (always a string in the DB) in its real type, for the API. */
    public function cast(?string $value): int|string|null
    {
        if ($value === null || $value === '') {
            return $this->defaultValue();
        }

        return match ($this) {
            self::HeroSliderInterval => (int) $value,
            default => $value,
        };
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'hero_mode' => 'Hero section shows',
            default => ucfirst(str_replace('_', ' ', $group)),
        };
    }
}
