<?php

namespace App\Http\Requests\Api;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/v1/student/payments - proof of money sent for one or more dues of one order. */
class PaymentSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer'],
            // Which dues this one payment covers - can span several courses in the same order.
            // Ownership and status (must all be unpaid) are checked in PaymentService, not here.
            'installment_ids' => ['required', 'array', 'min:1', 'max:50'],
            'installment_ids.*' => ['required', 'integer', 'distinct', 'exists:order_item_installments,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_account_id' => ['nullable', 'integer', 'exists:payment_accounts,id'],
            'provider' => ['nullable', 'string', 'max:60'],
            'sender_account' => ['nullable', 'string', 'max:60'],
            // A reference the student types in: bKash/Nagad TrxID, or a bank reference number.
            'trx_id' => ['required_unless:method,cash', 'nullable', 'string', 'max:60'],
            'proof_image' => ['required_unless:method,cash', 'nullable', 'image', 'max:4096'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
