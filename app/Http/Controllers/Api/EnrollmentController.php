<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Student\EnrollmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    /**
     * GET /api/v1/student/enrollments - "My courses": every course the student is enrolled in,
     * newest first. A course appears here once its first payment is verified (or straight away
     * when it was free); an unpaid order is not an enrollment yet - see GET orders for those.
     */
    public function index(Request $request): JsonResponse
    {
        $enrollments = $request->user()->enrollments()
            ->with([
                'course:id,title,slug,thumbnail',
                'batch:id,name,start_date',
                'orderItem.order:id,order_no',
                'orderItem.installments',
            ])
            ->latest('enrolled_at')
            ->latest('id')
            ->get();

        return response()->json(['data' => EnrollmentResource::collection($enrollments)->resolve()]);
    }
}
