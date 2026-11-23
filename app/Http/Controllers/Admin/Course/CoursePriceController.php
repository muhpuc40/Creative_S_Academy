<?php

namespace App\Http\Controllers\Admin\Course;

use App\Enums\AmountType;
use App\Http\Controllers\Admin\Course\Concerns\ManagesCourse;
use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A price is never edited: "change price" adds a new row and keeps the old one,
 * so students who already enrolled keep their price and installments.
 */
class CoursePriceController extends Controller
{
    use ManagesCourse;

    public function store(Request $request, Course $course): RedirectResponse
    {
        $data = $request->validate([
            'actual_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'discount_type' => ['nullable', Rule::enum(AmountType::class)],
            'discount_value' => ['nullable', 'numeric', 'min:0', Rule::when($request->input('discount_type') === 'percent', 'max:100')],
            'access_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'renewal_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'renewal_days' => ['nullable', 'required_with:renewal_fee', 'integer', 'min:1', 'max:3650'],
        ], [
            'renewal_days.required_with' => 'Enter how many days one renewal adds.',
        ]);

        $type = $data['discount_type'] ?? null;
        $value = $type ? (float) ($data['discount_value'] ?? 0) : 0;

        $course->changePrice(
            (float) $data['actual_price'],
            $type ? AmountType::from($type) : null,
            $value,
            $request->boolean('copy_rules', true),
            [
                'access_days' => (int) $data['access_days'],
                'renewal_fee' => isset($data['renewal_fee']) ? (float) $data['renewal_fee'] : null,
                'renewal_days' => isset($data['renewal_fee']) ? (int) $data['renewal_days'] : null,
            ],
        );

        return $this->toCourse($course, 'pricing', 'New price saved. The old price is kept in the history.');
    }
}
