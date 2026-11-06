<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Add / edit a hero slide (Website > Hero content). The slide image is required only when adding. */
class HeroContentRequest extends FormRequest
{
    private const IMAGE_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (string $key) => $this->filled($key) ? trim((string) $this->input($key)) : null;

        $this->merge([
            'button_text' => $clean('button_text'),
            'button_path' => $clean('button_path'),
            // "#ABC123" -> "#abc123"; an empty color box means "no color"
            'bg_color' => $this->filled('bg_color') ? strtolower(trim((string) $this->input('bg_color'))) : null,
        ]);
    }

    public function rules(): array
    {
        $adding = $this->route('hero') === null;

        return [
            'image' => [$adding ? 'required' : 'nullable', ...self::IMAGE_RULES],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            // a button needs both: what it says and where it goes
            'button_text' => ['nullable', 'required_with:button_path', 'string', 'max:60'],
            'button_path' => ['nullable', 'required_with:button_text', 'string', 'max:2048', 'regex:#^(/|https?://)#i'],
            'bg_color' => ['nullable', 'regex:/^#[0-9a-f]{6}$/'],
            'bg_image' => ['nullable', ...self::IMAGE_RULES],
            'remove_bg_image' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'button_path.regex' => 'The button link must start with / (a page on this website) or with https://.',
            'bg_color.regex' => 'The background color must look like #1a1530.',
            'image.max' => 'The image may not be bigger than 4 MB.',
            'bg_image.max' => 'The background image may not be bigger than 4 MB.',
        ];
    }

    /** Text fields ready to save (the image files are handled by the controller). */
    public function hero(): array
    {
        return [
            'title' => trim((string) $this->input('title')),
            'subtitle' => $this->filled('subtitle') ? trim((string) $this->input('subtitle')) : null,
            'button_text' => $this->input('button_text'),
            'button_path' => $this->input('button_path'),
            'bg_color' => $this->input('bg_color'),
            'sort_order' => (int) $this->input('sort_order', 0),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
