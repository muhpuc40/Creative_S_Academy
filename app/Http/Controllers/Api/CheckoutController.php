<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CheckoutRequest;
use App\Http\Resources\Student\OrderResource;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    /**
     * POST /api/v1/student/checkout/quote
     * Price preview - combo/voucher/batch are all checked, but nothing is created. Use this to
     * show the student the bill before they confirm.
     */
    public function quote(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        /** @var \App\Models\Student $student */
        $student = $request->user();

        $data = $checkout->quote(
            $student,
            $request->integer('combo_id') ?: null,
            $request->input('items'),
            $request->input('voucher_code'),
        );

        return response()->json($data);
    }

    /**
     * POST /api/v1/student/checkout/order
     * Same validation as quote(), run again with row locks, then the order is created.
     * A 0-total order (voucher covers everything) is created already paid.
     */
    public function store(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        /** @var \App\Models\Student $student */
        $student = $request->user();

        $order = $checkout->checkout(
            $student,
            $request->integer('combo_id') ?: null,
            $request->input('items'),
            $request->input('voucher_code'),
        );

        return response()->json(['data' => (new OrderResource($order))->resolve()], 201);
    }
}
