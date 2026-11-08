<?php

namespace App\Http\Requests\Admin;

use App\Enums\AmountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create and update of a combo (the same rules; "slug" ignores the combo being edited). */
class PaymentComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $combo = $this->route('combo');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:255', Rule::unique('payment_combos', 'slug')->ignore($combo?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'thumbnail' => ['nullable', 'image', 'max:2048'],
            'price_type' => ['required', Rule::enum(AmountType::class)],
            'price_value' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'starts_at' => ['nullable', 'date'],
            // compared against "today" when no start date is given, otherwise after the start date
            'ends_at' => ['required', 'date', $this->filled('starts_at') ? 'after:starts_at' : 'after_or_equal:today'],
            'is_active' => ['nullable', 'boolean'],
            'course_ids' => ['required', 'array', 'min:2'],
            'course_ids.*' => ['distinct', 'integer', Rule::exists('course_list', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'course_ids.required' => 'Pick at least 2 courses for the combo.',
            'course_ids.min' => 'Pick at least 2 courses for the combo.',
            'ends_at.after' => 'The deadline must be after the start date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('price_type') === AmountType::Percent->value && (float) $this->input('price_value') > 100) {
                $v->errors()->add('price_value', 'A percent cannot be more than 100.');
            }
        });
    }
}
