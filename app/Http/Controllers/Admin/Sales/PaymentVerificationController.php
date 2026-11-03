<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentVerificationController extends Controller
{
    /** GET /payments - the verification queue, pending first by default. */
    public function index(Request $request): View
    {
        $filter = $request->input('status', 'pending');

        $payments = Payment::query()
            ->with(['student:id,name,email', 'order:id,order_no', 'installments.item.course:id,title'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('trx_id', 'like', $term)
                    ->orWhereHas('order', fn ($o) => $o->where('order_no', 'like', $term))
                    ->orWhereHas('student', fn ($s) => $s->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when($filter, fn ($q) => $q->where('status', $filter))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'filter' => $filter,
        ]);
    }

    /** GET /payments/{payment} - full detail: proof, which dues it covers, the order they sit in. */
    public function show(Payment $payment): View
    {
        $payment->load([
            'student', 'order.items.course', 'account', 'verifier',
            'installments.item.course', 'installments.item.batch',
        ]);

        return view('admin.payments.show', ['payment' => $payment]);
    }

    /** PATCH /payments/{payment}/verify */
    public function verify(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        try {
            $payments->verify($request->user(), $payment);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Payment verified - the dues it covers are now marked paid.');
    }

    /** PATCH /payments/{payment}/reject */
    public function reject(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $payments->reject($request->user(), $payment, $request->input('reason'));
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Payment rejected - the dues it was holding are free to be paid again.');
    }
}
