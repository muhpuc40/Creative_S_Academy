<?php

namespace App\Http\Requests\Admin;

use App\Rules\AllowedFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ComposeEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $table = match ($this->input('recipient_type')) {
            'instructor' => 'instructors',
            'affiliate' => 'affiliates',
            default => 'students',
        };

        return [
            'recipient_type' => ['required', Rule::in(['student', 'instructor', 'affiliate'])],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => [
                'integer',
                'distinct',
                Rule::exists($table, 'id')->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at')),
            ],
            'custom_emails' => ['nullable', 'string', 'max:10000'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
            'attachment' => ['nullable', 'file', 'max:10240', new AllowedFile], // 10 MB: what mail servers accept
        ];
    }

    public function customEmails(): array
    {
        return collect(preg_split('/[,;\r\n]+/', (string) $this->input('custom_emails'), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $customEmails = $this->customEmails();

            foreach ($customEmails as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validator->errors()->add('custom_emails', "Invalid email address: {$email}");
                    break;
                }
            }

            $userIds = $this->input('user_ids');

            if ((! is_array($userIds) || count($userIds) === 0) && count($customEmails) === 0) {
                $validator->errors()->add('user_ids', 'Select at least one user or enter a custom email address.');
            }
        });
    }
}
