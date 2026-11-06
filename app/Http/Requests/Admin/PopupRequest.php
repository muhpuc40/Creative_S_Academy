<?php

namespace App\Http\Requests\Admin;

use App\Enums\PopupFrequency;
use App\Enums\PopupKind;
use App\Models\PopupSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add / edit a popup (Website > Popups).
 *
 * Pages come from two fields:
 *   pages_mode = all | home | specific
 *   paths      = one path per line (only for "specific"), e.g. /courses/* or /courses/ui-ux
 * and are saved as one list in popup_settings.pages.
 */
class PopupRequest extends FormRequest
{
    // "*" (all pages), "/", "/courses", "/courses/*", "/courses/ui-ux". A * is allowed only as the last part.
    private const PATH = '#^(?:\*|/(?:[A-Za-z0-9._~-]+/)*(?:[A-Za-z0-9._~-]+|\*)?)$#';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (string $key) => $this->filled($key) ? trim((string) $this->input($key)) : null;

        $this->merge([
            'title' => $clean('title'),
            'body' => $clean('body'),
            'button_text' => $clean('button_text'),
            'link' => $clean('link'),
            'pages' => match ($this->input('pages_mode')) {
                'all' => PopupSetting::ALL_PAGES,
                'home' => PopupSetting::HOME_ONLY,
                default => $this->paths(),
            },
        ]);
    }

    public function rules(): array
    {
        $hasText = $this->kind()?->hasText() ?? true;
        $popup = $this->route('popup');

        return [
            'kind' => ['required', Rule::enum(PopupKind::class)],

            // content: a corner popup is only an image, so it must have one
            'image' => [Rule::requiredIf(! $hasText && ! $popup?->image), 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
            'title' => [Rule::requiredIf($hasText), 'nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:1000'],
            // a button needs both: what it says and where it goes
            'button_text' => ['nullable', 'string', 'max:60', ...($hasText ? ['required_with:link'] : [])],
            'link' => ['nullable', 'string', 'max:2048', 'regex:#^(/|https?://)#i', ...($hasText ? ['required_with:button_text'] : [])],

            // settings
            'pages_mode' => ['required', Rule::in(['all', 'home', 'specific'])],
            'pages' => ['required', 'array', 'min:1', 'max:20'],
            'pages.*' => ['string', 'max:255', 'regex:'.self::PATH],
            'delay_seconds' => ['required', 'integer', 'min:0', 'max:600'],
            'frequency' => ['required', Rule::enum(PopupFrequency::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'pages' => 'pages',
            'pages.*' => 'page path',
            'delay_seconds' => 'delay',
            'frequency' => 'how often',
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'A corner popup needs an image.',
            'image.max' => 'The image may not be bigger than 4 MB.',
            'link.regex' => 'The link must start with / (a page on this website) or with https://.',
            'pages.required' => 'Add at least one page path.',
            'pages.*.regex' => 'Each page path must start with /, like /courses or /courses/*. A * is allowed only at the end.',
        ];
    }

    public function kind(): ?PopupKind
    {
        return PopupKind::tryFrom((string) $this->input('kind'));
    }

    /** Fields for the popups table. A corner popup keeps only its link (text fields are cleared). */
    public function content(): array
    {
        $hasText = $this->kind()->hasText();

        return [
            'kind' => $this->kind(),
            'title' => $hasText ? $this->input('title') : null,
            'body' => $hasText ? $this->input('body') : null,
            'button_text' => $hasText ? $this->input('button_text') : null,
            'link' => $this->input('link'),
        ];
    }

    /** Fields for the popup_settings table. */
    public function settings(): array
    {
        return [
            'is_active' => $this->boolean('is_active'),
            'sort_order' => (int) $this->input('sort_order', 0),
            'pages' => $this->input('pages'),
            'delay_seconds' => (int) $this->input('delay_seconds'),
            'frequency' => $this->input('frequency'),
        ];
    }

    /** The "paths" box: one path per line, trimmed, no trailing slash, no repeats. */
    private function paths(): array
    {
        $lines = preg_split('/\R/', (string) $this->input('paths'));
        $paths = array_map(fn ($line) => ($p = trim($line)) === '/' ? '/' : rtrim($p, '/'), $lines);

        return array_values(array_unique(array_filter($paths, fn ($p) => $p !== '')));
    }
}
