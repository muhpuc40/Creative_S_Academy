<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add / edit a "where to pay" account (Sales > Accounts). */
class PaymentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // "01700-000 000" -> "01700000000"; bank numbers keep only digits too.
        if ($this->filled('account_no') && $this->input('method') !== PaymentMethod::Cash->value) {
            $this->merge(['account_no' => preg_replace('/\D+/', '', (string) $this->input('account_no'))]);
        }
    }

    public function rules(): array
    {
        $method = $this->input('method');
        $account = $this->route('account');

        return [
            'method' => ['required', Rule::enum(PaymentMethod::class)],

            // bKash/Nagad/Rocket or the bank's name. Not needed for cash.
            'provider' => ['required_unless:method,cash', 'nullable', 'string', 'max:60'],

            'account_no' => array_filter([
                'required_unless:method,cash',
                'nullable',
                'string',
                'max:60',
                // A Bangladeshi mobile number for bKash/Nagad/Rocket.
                $method === PaymentMethod::Mfs->value ? 'regex:/^01[3-9]\d{8}$/' : null,
                // The same number twice for the same provider would only confuse students.
                Rule::unique('payment_accounts', 'account_no')
                    ->where('method', $method)
                    ->where('provider', $this->input('provider'))
                    ->ignore($account?->id),
            ]),

            'account_name' => [$method === PaymentMethod::Bank->value ? 'required' : 'nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],

            // Cash has no number, so it needs instructions (where and when to pay).
            'instructions' => ['required_if:method,cash', 'nullable', 'string', 'max:1000'],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required_unless' => 'Write the provider (bKash, Nagad...) or the bank name.',
            'account_no.required_unless' => 'Write the account or mobile number.',
            'account_no.regex' => 'A bKash/Nagad/Rocket number must be an 11-digit mobile number, e.g. 01712345678.',
            'account_no.unique' => 'This number is already added for this provider.',
            'account_name.required' => 'Write the bank account holder name.',
            'instructions.required_if' => 'For cash, write where and when students can pay.',
        ];
    }

    /** Cleaned values, ready to save. Cash keeps no number/branch; only bank keeps a branch. */
    public function account(): array
    {
        $data = $this->validated();
        $isCash = $data['method'] === PaymentMethod::Cash->value;

        return [
            'method' => $data['method'],
            'provider' => $isCash ? null : $data['provider'],
            'account_no' => $isCash ? null : $data['account_no'],
            'account_name' => $data['account_name'] ?? null,
            'branch' => $data['method'] === PaymentMethod::Bank->value ? ($data['branch'] ?? null) : null,
            'instructions' => $data['instructions'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
