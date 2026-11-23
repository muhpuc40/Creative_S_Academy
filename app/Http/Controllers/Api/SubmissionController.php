<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchAssignment;
use App\Models\BatchExam;
use App\Models\Enrollment;
use App\Models\EnrollmentResult;
use App\Rules\AllowedFile;
use App\Services\Enrollment\AccessService;
use App\Services\Enrollment\SubmissionService;
use App\Support\StudentMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An enrolled student's assignments and exams: what's set, whether they can hand in now, their
 * own submission, and the upload itself (one file, saved in private storage).
 * A refusal answers 404 (not found / not available) or 422 with a reason.
 */
class SubmissionController extends Controller
{
    private const MESSAGES = [
        SubmissionService::NOT_FOUND => 'Not found.',
        SubmissionService::NOT_OPEN => 'Submissions are not open yet.',
        SubmissionService::CLOSED => 'Submissions are closed.',
        SubmissionService::CANCELLED => 'This exam was cancelled.',
        SubmissionService::ALREADY_SUBMITTED => 'You have already submitted your answer.',
        SubmissionService::GRADED => 'Your submission is already marked and can no longer be changed.',
        SubmissionService::UNSAFE_FILE => 'This file failed the security check. Please upload a different file.',
        AccessService::NOT_ENROLLED => 'You are not enrolled in this batch.',
        AccessService::INACTIVE => 'This is not available.',
        AccessService::PAYMENT_LOCKED => 'Pay your next installment to unlock this module.',
    ];

    public function __construct(private SubmissionService $submissions) {}

    /** GET /api/v1/student/assignments/{assignmentId} */
    public function assignment(Request $request, string $type, int $assignmentId): JsonResponse
    {
        [$assignment, $enrollment, $refusal] = $this->find($request, BatchAssignment::find($assignmentId));
        if ($refusal) {
            return $refusal;
        }

        $reason = $this->submissions->canSubmit($enrollment, $assignment);
        $mine = $this->submissions->resultFor($enrollment, $assignment);

        return response()->json(['data' => [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'instruction' => $assignment->instruction,
            'assigned_at' => $assignment->assigned_at->toIso8601String(),
            'due_at' => $assignment->due_at->toIso8601String(),
            'allow_late' => $assignment->allow_late,
            'total_marks' => $assignment->total_marks,
            'file' => StudentMedia::assignmentFile($assignment, $enrollment),
            'can_submit' => $reason === SubmissionService::OK,
            'reason' => $reason === SubmissionService::OK ? null : $reason,
            'late_if_submitted_now' => now()->gt($assignment->due_at),
            'my_submission' => $mine ? $this->submission($mine, marksVisible: $mine->status === EnrollmentResult::STATUS_GRADED) : null,
        ]]);
    }

    /** POST /api/v1/student/assignments/{assignmentId}/submission  (multipart: file) */
    public function submitAssignment(Request $request, string $type, int $assignmentId): JsonResponse
    {
        return $this->submit($request, BatchAssignment::find($assignmentId));
    }

    /** GET /api/v1/student/exams/{examId}  -  the paper link appears once the exam has started. */
    public function exam(Request $request, string $type, int $examId): JsonResponse
    {
        [$exam, $enrollment, $refusal] = $this->find($request, BatchExam::find($examId));
        if ($refusal) {
            return $refusal;
        }

        $reason = $this->submissions->canSubmit($enrollment, $exam);
        $mine = $this->submissions->resultFor($enrollment, $exam);
        $started = now()->gte($exam->startsAt());

        return response()->json(['data' => [
            'id' => $exam->id,
            'title' => $exam->title,
            'instruction' => $exam->instruction,
            'starts_at' => $exam->startsAt()->toIso8601String(),
            'ends_at' => $exam->endsAt()->toIso8601String(),
            'closes_at' => $this->submissions->examClosesAt($exam)->toIso8601String(),
            'duration_minutes' => $exam->duration_minutes,
            'total_marks' => $exam->total_marks,
            'status' => $exam->status->value,
            'paper' => $started ? StudentMedia::examPaper($exam, $enrollment) : null,
            'can_submit' => $reason === SubmissionService::OK,
            'reason' => $reason === SubmissionService::OK ? null : $reason,
            'result_published' => $exam->isResultPublished(),
            'my_submission' => $mine ? $this->submission($mine, marksVisible: $exam->isResultPublished()) : null,
        ]]);
    }

    /** POST /api/v1/student/exams/{examId}/answer  (multipart: file) */
    public function submitExam(Request $request, string $type, int $examId): JsonResponse
    {
        return $this->submit($request, BatchExam::find($examId));
    }

    private function submit(Request $request, BatchAssignment|BatchExam|null $work): JsonResponse
    {
        [$work, $enrollment, $refusal] = $this->find($request, $work);
        if ($refusal) {
            return $refusal;
        }

        // the window first, so a closed exam says "closed" rather than complaining about the file
        $reason = $this->submissions->canSubmit($enrollment, $work);
        if ($reason !== SubmissionService::OK) {
            return $this->refuse($reason);
        }

        $request->validate(['file' => ['required', 'file', new AllowedFile]], [
            'file.required' => 'Choose a file to upload.',
            'file.uploaded' => 'The file could not be uploaded. It may be larger than the server allows.',
        ]);

        $result = $this->submissions->submit($enrollment, $work, $request->file('file'));
        if (is_string($result)) {
            return $this->refuse($result);
        }

        return response()->json([
            'message' => 'Submitted.',
            'data' => $this->submission($result, marksVisible: false),
            'progress' => LearningController::progressOf($enrollment->fresh()),
        ], 201);
    }

    /** The student's own submission. Marks and feedback only when they may see them. */
    private function submission(EnrollmentResult $result, bool $marksVisible): array
    {
        return [
            'file_name' => $result->file_name,
            'file_size' => $result->file_size,
            'file' => StudentMedia::submission($result),
            'submitted_at' => $result->submitted_at?->toIso8601String(),
            'is_late' => $result->is_late,
            'status' => $result->status,
            'marks_obtained' => $marksVisible && $result->marks_obtained !== null ? (float) $result->marks_obtained : null,
            'total_marks' => $marksVisible ? $result->total_marks : null,
            'feedback' => $marksVisible ? $result->feedback : null,
        ];
    }

    /**
     * The assignment / exam, the student's enrollment in its batch, and a refusal if they can't see it.
     *
     * @return array{0: BatchAssignment|BatchExam|null, 1: ?Enrollment, 2: ?JsonResponse}
     */
    private function find(Request $request, BatchAssignment|BatchExam|null $work): array
    {
        if (!$work) {
            return [null, null, $this->refuse(SubmissionService::NOT_FOUND)];
        }

        $enrollment = $this->submissions->enrollmentFor($request->user(), $work);
        $visible = $this->submissions->visibility($enrollment, $work);

        return $visible === AccessService::OK || $visible === SubmissionService::CANCELLED
            ? [$work, $enrollment, null]
            : [$work, $enrollment, $this->refuse($visible)];
    }

    private function refuse(string $reason): JsonResponse
    {
        $status = in_array($reason, [SubmissionService::NOT_FOUND, AccessService::INACTIVE], true) ? 404 : 422;

        return response()->json(['message' => self::MESSAGES[$reason] ?? 'Not available.', 'reason' => $reason], $status);
    }
}
