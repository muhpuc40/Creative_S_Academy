<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaymentSubmitRequest;
use App\Http\Resources\Student\PaymentResource;
use App\Models\PaymentAccount;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * POST /api/v1/student/payments
     * Submit the details (TrxID etc.) of one payment covering one or more dues (installment_ids) of one order -
     * they can belong to different courses, as long as they're all in that same order. The
     * selected dues are reserved (unpaid -> pending) until an admin verifies or rejects this.
     */
    public function store(PaymentSubmitRequest $request, PaymentService $payments): JsonResponse
    {
        /** @var \App\Models\Student $student */
        $student = $request->user();

        $payment = $payments->submit(
            $student,
            $request->integer('order_id'),
            $request->input('installment_ids'),
            [
                'amount' => $request->input('amount'),
                'method' => $request->input('method'),
                'payment_account_id' => $request->integer('payment_account_id') ?: null,
                'provider' => $request->input('provider'),
                'sender_account' => $request->input('sender_account'),
                'trx_id' => $request->input('trx_id'),
                'paid_at' => $request->input('paid_at'),
                'note' => $request->input('note'),
            ],
        );

        return response()->json(['data' => (new PaymentResource($payment))->resolve()], 201);
    }

    /** GET /api/v1/student/payments - the student's own payment submissions, newest first. */
    public function index(Request $request): JsonResponse
    {
        $payments = $request->user()->payments()
            ->with(['order:id,order_no', 'installments.item.course:id,title'])
            ->latest('id')
            ->paginate(15);

        return response()->json([
            'data' => PaymentResource::collection($payments)->resolve(),
            'meta' => [
                'page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /** GET /api/v1/payment-accounts - public: where a student can send money. */
    public function accounts(): JsonResponse
    {
        $accounts = PaymentAccount::active()->get(['id', 'method', 'provider', 'account_no', 'account_name', 'branch', 'instructions']);

        return response()->json(['data' => $accounts]);
    }
}
