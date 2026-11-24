<?php

namespace App\Http\Controllers\Api;

use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Models\BatchClassSchedule;
use App\Models\ClassAttendance;
use App\Models\Enrollment;
use App\Services\Enrollment\AccessService;
use App\Services\Enrollment\ClassJoinService;
use App\Services\Enrollment\CourseMap;
use App\Services\Enrollment\ProgressService;
use App\Support\StudentMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live classes for the Join button: the list, and the tracked join that records attendance.
 * The meeting link is never listed; it only comes back from a successful join.
 */
class ClassController extends Controller
{
    /** Recent classes (ended within this many days) are listed too, with the student's attendance. */
    private const RECENT_DAYS = 14;

    private const MESSAGES = [
        ClassJoinService::NOT_FOUND => 'Class not found.',
        AccessService::NOT_ENROLLED => 'You are not enrolled in this class.',
        AccessService::INACTIVE => 'This class is not available.',
        AccessService::PAYMENT_LOCKED => 'Pay your next installment to join this class.',
        ClassJoinService::CANCELLED => 'This class was cancelled.',
        ClassJoinService::TOO_EARLY => 'Joining opens 15 minutes before the class starts.',
        ClassJoinService::ENDED => 'This class has ended.',
        ClassJoinService::NO_LINK => 'The meeting link is not ready yet. Please try again in a moment.',
    ];

    /**
     * GET /api/v1/student/classes
     * Live classes of the student's active enrollments: "upcoming" (not ended, soonest first) and
     * "recent" (ended in the last 14 days, newest first).
     */
    public function index(Request $request, ClassJoinService $joins): JsonResponse
    {
        $enrollments = Enrollment::active()->where('student_id', $request->user()->id)->get()->keyBy('batch_list_id');

        $classes = BatchClassSchedule::query()
            ->whereIn('batch_list_id', $enrollments->keys())
            ->whereHas('item', fn($q) => $q->where('item_type', ItemType::Live))
            ->where('class_start_time', '>=', now()->subDays(self::RECENT_DAYS)->subDay())
            ->with(['item.module.curriculum', 'liveInfo', 'batch:id,name,course_list_id', 'batch.course:id,title,slug'])
            ->orderBy('class_start_time')
            ->get();

        $attendance = ClassAttendance::whereIn('enrollment_id', $enrollments->pluck('id'))
            ->whereIn('class_schedule_id', $classes->pluck('id'))
            ->get()
            ->keyBy('class_schedule_id');


        /** @var \Illuminate\Database\Eloquent\Collection<int, BatchClassSchedule> $classes */

        // one CourseMap per enrollment (not per class): each course is loaded once
        $maps = [];
        $mapFor = function (int $batchId) use ($enrollments, &$maps): ?CourseMap {
            $enrollment = $enrollments[$batchId] ?? null;

            return $enrollment ? ($maps[$batchId] ??= CourseMap::for($enrollment)) : null;
        };

        $rows = $classes->map(function (BatchClassSchedule $class) use ($enrollments, $attendance, $joins, $mapFor) {
            $reason = $joins->check($enrollments[$class->batch_list_id] ?? null, $class, $mapFor($class->batch_list_id));
            if ($reason === AccessService::INACTIVE) {
                return null;   // hidden item / module / stage: not shown at all
            }

            $mine = $attendance[$class->id] ?? null;

            return [
                'id' => $class->id,
                'course' => ['title' => $class->batch?->course?->title, 'slug' => $class->batch?->course?->slug],
                'batch' => $class->batch?->name,
                'module' => $class->item?->module?->title,
                'starts_at' => $class->class_start_time->toIso8601String(),
                'ends_at' => $class->endsAt()->toIso8601String(),
                'opens_at' => $joins->opensAt($class)->toIso8601String(),
                'status' => $class->status->value,
                'platform' => $class->liveInfo?->platform?->value,
                'can_join_now' => $reason === ClassJoinService::OK,
                'reason' => $reason === ClassJoinService::OK ? null : $reason,
                'my_attendance' => $mine ? [
                    'status' => $mine->status,
                    'first_joined_at' => $mine->first_joined_at?->toIso8601String(),
                ] : null,
                'ended' => now()->gt($class->endsAt()),
            ];
        })->filter()->values();

        [$recent, $upcoming] = $rows->partition(fn($row) => $row['ended']);
        $strip = fn($row) => collect($row)->except('ended')->all();

        return response()->json([
            'data' => [
                'upcoming' => $upcoming->map($strip)->values(),
                'recent' => $recent->reverse()->map($strip)->values(),
            ]
        ]);
    }

    /**
     * GET /api/v1/student/classes/{classId}/recording
     * The recording the admin attached to a live class: { type: file|external, url, expires_at, name }.
     * Needs the live class's own checks (paid, not hidden) plus the access period.
     */
    public function recording(Request $request, string $type, int $classId, ClassJoinService $joins, AccessService $access): JsonResponse
    {
        [$class, $enrollment, $refusal] = $this->recordingFor($request, $classId, $joins, $access);
        if ($refusal) {
            return $refusal;
        }

        $content = $class->liveInfo->recording;

        return response()->json(['data' => ['name' => $content->name] + StudentMedia::content($content, $enrollment)
            + ['resume_at' => app(ProgressService::class)->positionOf($enrollment, $class->item)]]);
    }

    /**
     * POST /api/v1/student/classes/{classId}/recording/position   { seconds }
     * Where the student is in the class recording, so it resumes there next time.
     */
    public function recordingPosition(Request $request, string $type, int $classId, ClassJoinService $joins, AccessService $access, ProgressService $progress): JsonResponse
    {
        $seconds = (int) $request->validate(['seconds' => ['required', 'integer', 'min:0', 'max:86400']])['seconds'];

        // called once a minute while watching: only what a resume point needs (see LearningController::position)
        $class = BatchClassSchedule::with('item')->find($classId);
        if (!$class || $class->item?->item_type !== ItemType::Live) {
            return LearningController::refuse('not_found');
        }
        $enrollment = $joins->enrollmentFor($request->user(), $class);
        if (!$enrollment) {
            return LearningController::refuse(AccessService::NOT_ENROLLED);
        }
        if (!$enrollment->hasContentAccess()) {
            return LearningController::refuse(AccessService::ACCESS_EXPIRED);
        }

        $progress->savePosition($enrollment, $class->item, $seconds);

        return response()->json(['data' => ['resume_at' => $seconds]]);
    }

    /**
     * POST /api/v1/student/classes/{classId}/recording/complete
     * "Mark as complete" on a live class's recording: the live class counts as done (missed it,
     * watched the recording). Answers with the new progress.
     */
    public function completeRecording(Request $request, string $type, int $classId, ClassJoinService $joins, AccessService $access, ProgressService $progress): JsonResponse
    {
        [$class, $enrollment, $refusal] = $this->recordingFor($request, $classId, $joins, $access);
        if ($refusal) {
            return $refusal;
        }

        $progress->complete($enrollment, $class->item, 'recording');

        return response()->json(['data' => LearningController::progressOf($enrollment->fresh())]);
    }

    /**
     * The class, the student's enrollment in its batch, and a refusal if its recording can't be
     * opened now: not_found | not_enrolled | inactive | payment_locked | access_expired | cancelled | no_recording.
     *
     * @return array{0: ?BatchClassSchedule, 1: ?Enrollment, 2: ?JsonResponse}
     */
    private function recordingFor(Request $request, int $classId, ClassJoinService $joins, AccessService $access): array
    {
        $class = BatchClassSchedule::with(['item', 'liveInfo.recording'])->find($classId);
        if (!$class || $class->item?->item_type !== ItemType::Live) {
            return [null, null, LearningController::refuse('not_found')];
        }

        $enrollment = $joins->enrollmentFor($request->user(), $class);
        $reason = $access->checkRecording($enrollment, $class->item);
        if ($reason !== AccessService::OK) {
            return [$class, $enrollment, LearningController::refuse($reason)];
        }

        if ($class->status === \App\Enums\ClassStatus::Cancelled) {
            return [$class, $enrollment, response()->json(['message' => self::MESSAGES[ClassJoinService::CANCELLED], 'reason' => ClassJoinService::CANCELLED], 422)];
        }

        $content = $class->liveInfo?->recording;
        if (!$content || (!$content->hasStoredFile() && !$content->file_url)) {
            return [$class, $enrollment, response()->json(['message' => 'The recording is not available yet.', 'reason' => 'no_recording'], 422)];
        }

        return [$class, $enrollment, null];
    }

    /**
     * POST /api/v1/student/classes/{classId}/join
     * Checks the student may join now, records attendance (and progress), then answers with the
     * meeting link to send the browser to. A refusal answers 404 (no such class) or 422 with a
     * reason: not_enrolled | inactive | payment_locked | cancelled | too_early | ended | no_link.
     */
    public function join(Request $request, string $type, int $classId, ClassJoinService $joins): JsonResponse
    {
        $class = BatchClassSchedule::with(['item', 'liveInfo'])->find($classId);
        $enrollment = $class ? $joins->enrollmentFor($request->user(), $class) : null;
        $reason = $class ? $joins->check($enrollment, $class) : ClassJoinService::NOT_FOUND;

        if ($reason !== ClassJoinService::OK) {
            return response()->json(array_filter([
                'message' => self::MESSAGES[$reason] ?? 'You cannot join this class right now.',
                'reason' => $reason,
                'opens_at' => $reason === ClassJoinService::TOO_EARLY ? $joins->opensAt($class)->toIso8601String() : null,
            ]), in_array($reason, [ClassJoinService::NOT_FOUND, AccessService::INACTIVE], true) ? 404 : 422);
        }

        $attendance = $joins->join($enrollment, $class);

        return response()->json([
            'url' => $class->liveInfo->meeting_url,
            'attendance' => [
                'status' => $attendance->status,
                'first_joined_at' => $attendance->first_joined_at?->toIso8601String(),
                'join_count' => $attendance->join_count,
            ],
        ]);
    }
}
