<?php

namespace App\Http\Requests\Api;

use App\Enums\PaymentPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared by POST checkout/quote (price preview) and POST checkout/order (actually buy). */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Buying a combo as-is: still send one "items" row per course in it (for batch_id).
            'combo_id' => ['nullable', 'integer', 'exists:payment_combos,id'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.course_id' => ['required', 'integer', 'distinct', 'exists:course_list,id'],
            'items.*.batch_id' => ['required', 'integer', 'exists:batch_list,id'],
            'items.*.payment_plan' => ['nullable', Rule::enum(PaymentPlan::class)],
            // Vouchers are per-student (issued by name), so there's nothing to look up here
            // beyond shape - CheckoutService checks it belongs to this student and is usable.
            'voucher_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
