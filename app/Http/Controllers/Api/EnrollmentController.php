<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Student\EnrollmentResource;
use App\Http\Resources\Student\OrderResource;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Enrollment\RenewalService;
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

    /**
     * POST /api/v1/student/enrollments/{enrollmentId}/renew
     * Makes a renewal order (the course's current renewal fee, paid in full) and answers with it,
     * so the website can open its pay page. Refused with 422 and a reason:
     * not_offered | not_needed | too_early (with opens_at) | pending (with order_no) | not_enrolled.
     */
    public function renew(Request $request, string $type, int $enrollmentId, RenewalService $renewals, EnrollmentService $enrollments): JsonResponse
    {
        $enrollment = $request->user()->enrollments()->find($enrollmentId);
        if (!$enrollment) {
            return response()->json(['message' => 'Not found.', 'reason' => 'not_found'], 404);
        }

        $result = $renewals->create($enrollment, $enrollments);

        if (is_string($result)) {
            return response()->json(array_filter([
                'message' => self::RENEW_MESSAGES[$result] ?? 'You cannot renew this course right now.',
                'reason' => $result,
                'opens_at' => $result === RenewalService::TOO_EARLY ? $renewals->opensAt($enrollment)?->toIso8601String() : null,
                'order_no' => $result === RenewalService::PENDING ? $renewals->pendingOrder($enrollment)?->order_no : null,
            ]), 422);
        }

        return response()->json(['message' => 'Renewal order created.', 'data' => new OrderResource($result)], 201);
    }

    private const RENEW_MESSAGES = [
        RenewalService::NOT_OFFERED => 'This course does not offer renewal.',
        RenewalService::NOT_NEEDED => 'Your access to this course does not expire.',
        RenewalService::TOO_EARLY => 'You can renew from 15 days before your access ends.',
        RenewalService::PENDING => 'You already have a renewal order waiting for payment.',
        RenewalService::NOT_ENROLLED => 'Your enrollment is not active.',
    ];
}
