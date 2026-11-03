<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Student\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /** GET /api/v1/student/orders - the authenticated student's own orders, newest first. */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->with(['items.course:id,title,thumbnail', 'items.batch:id,name', 'items.installments'])
            ->latest('id')
            ->paginate(15);

        return response()->json([
            'data' => OrderResource::collection($orders)->resolve(),
            'meta' => [
                'page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /** GET /api/v1/student/orders/{order} - only this student's own order, never anyone else's. */
    public function show(Request $request, string $type, int $order): JsonResponse
    {
        $order = $request->user()->orders()
            ->with(['items.course:id,title,thumbnail', 'items.batch:id,name', 'items.installments'])
            ->findOr($order, fn () => abort(404, 'Order not found.'));

        return response()->json(['data' => (new OrderResource($order))->resolve()]);
    }
}
