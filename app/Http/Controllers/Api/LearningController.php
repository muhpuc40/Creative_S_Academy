<?php

namespace App\Http\Controllers\Api;

use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Models\CourseCurriculum;
use App\Models\CourseModuleItem;
use App\Models\Enrollment;
use App\Services\Enrollment\AccessService;
use App\Services\Enrollment\CourseMap;
use App\Services\Enrollment\ProgressService;
use App\Support\StudentMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An enrolled student working through one curriculum item: play a recorded video, mark it
 * complete, open a resource. Every endpoint asks AccessService first (paid, released, in order,
 * inside the access period). A refusal answers 404 (no such item) or 422 with a reason.
 */
class LearningController extends Controller
{
    public const MESSAGES = [
        'not_found' => 'Not found.',
        'no_video' => 'This video is not available yet.',
        'no_resource' => 'This resource is not available yet.',
        AccessService::NOT_ENROLLED => 'You are not enrolled in this course.',
        AccessService::INACTIVE => 'This is not available.',
        AccessService::ACCESS_EXPIRED => 'Your access to this course has ended. Renew to watch again.',
        AccessService::PAYMENT_LOCKED => 'Pay your next installment to unlock this module.',
        AccessService::NOT_RELEASED => 'This video is not released yet.',
        AccessService::PREVIOUS_INCOMPLETE => 'Complete the previous video first.',
    ];

    public function __construct(
        private AccessService $access,
        private ProgressService $progress,
    ) {}

    /**
     * GET /api/v1/student/items/{itemId}/play
     * A recorded video: { type: file|external, url, expires_at, name, resume_at }. An uploaded video
     * gets a signed link valid for 2 hours; ask again for a fresh one. resume_at = seconds where the
     * student stopped last time (null = from the start).
     */
    public function play(Request $request, string $type, int $itemId): JsonResponse
    {
        [$item, $enrollment, $refusal] = $this->open($request, $itemId, ItemType::Recorded);
        if ($refusal) {
            return $refusal;
        }

        $content = $item->content;
        if (!$content || (!$content->hasStoredFile() && !$content->file_url)) {
            return self::refuse('no_video');
        }

        return response()->json(['data' => ['name' => $content->name] + StudentMedia::content($content, $enrollment)
            + ['resume_at' => $this->progress->positionOf($enrollment, $item)]]);
    }

    /**
     * POST /api/v1/student/items/{itemId}/position   { seconds }
     * Where the student is in a recorded video, so it resumes there next time. The player sends it
     * once a minute while playing, plus once on pause / leaving the page (limited to 2 a minute:
     * the resume point may be up to a minute behind). Doesn't mark anything complete.
     *
     * Called often, so it only checks what a resume point needs: the video exists, the
     * student is enrolled in its course and inside the access period. (Saving a position never
     * opens anything; play still runs every check.)
     */
    public function position(Request $request, string $type, int $itemId): JsonResponse
    {
        $seconds = (int) $request->validate(['seconds' => ['required', 'integer', 'min:0', 'max:86400']])['seconds'];

        [$item, $enrollment] = $this->find($request, $itemId, ItemType::Recorded);
        if (!$item) {
            return self::refuse('not_found');
        }
        if (!$enrollment) {
            return self::refuse(AccessService::NOT_ENROLLED);
        }
        if (!$enrollment->hasContentAccess()) {
            return self::refuse(AccessService::ACCESS_EXPIRED);
        }

        $this->progress->savePosition($enrollment, $item, $seconds);

        return response()->json(['data' => ['resume_at' => $seconds]]);
    }

    /**
     * POST /api/v1/student/items/{itemId}/complete
     * "Mark as complete" on a recorded video (no watch-time check). Allowed only while the student
     * may open the video, so the one-by-one order holds. Answers with the new progress.
     */
    public function complete(Request $request, string $type, int $itemId): JsonResponse
    {
        [$item, $enrollment, $refusal, $map] = $this->open($request, $itemId, ItemType::Recorded);
        if ($refusal) {
            return $refusal;
        }

        $this->progress->complete($enrollment, $item, 'marked', $map);

        return response()->json(['data' => self::progressOf($enrollment->fresh())]);
    }

    /**
     * GET /api/v1/student/items/{itemId}/resource
     * A resource: { type: file|external, url, expires_at, name }. Opening it counts it as done.
     */
    public function resource(Request $request, string $type, int $itemId): JsonResponse
    {
        [$item, $enrollment, $refusal, $map] = $this->open($request, $itemId, ItemType::Resources);
        if ($refusal) {
            return $refusal;
        }

        $resource = $item->resource;
        if (!$resource?->url) {
            return self::refuse('no_resource');
        }

        $this->progress->complete($enrollment, $item, 'opened', $map);

        return response()->json(['data' => ['name' => $resource->name] + StudentMedia::resource($resource, $enrollment)]);
    }

    /** { progress_percent, completed, completed_at } of an enrollment, for the page to update. */
    public static function progressOf(Enrollment $enrollment): array
    {
        return [
            'progress_percent' => (float) $enrollment->progress_percent,
            'completed' => $enrollment->completed_at !== null,
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
        ];
    }

    public static function refuse(string $reason): JsonResponse
    {
        $status = in_array($reason, ['not_found', AccessService::INACTIVE], true) ? 404 : 422;

        return response()->json(['message' => self::MESSAGES[$reason] ?? 'Not available.', 'reason' => $reason], $status);
    }

    /**
     * The item (of the expected type), the student's enrollment in its course, and a refusal
     * response if they may not open it now.
     *
     * @return array{0: ?CourseModuleItem, 1: ?Enrollment, 2: ?JsonResponse}
     */
    /**
     * The item, the student's enrollment, a refusal if it can't be opened now, and the CourseMap
     * the check used (pass it on to ProgressService so the course isn't loaded twice).
     *
     * @return array{0: ?CourseModuleItem, 1: ?Enrollment, 2: ?JsonResponse, 3: ?CourseMap}
     */
    private function open(Request $request, int $itemId, ItemType $expected): array
    {
        [$item, $enrollment] = $this->find($request, $itemId, $expected);
        if (!$item) {
            return [null, null, self::refuse('not_found'), null];
        }
        if (!$enrollment) {
            return [$item, null, self::refuse(AccessService::NOT_ENROLLED), null];
        }

        $map = CourseMap::for($enrollment);
        $reason = $map->reason($item->id);
        if ($reason !== AccessService::OK) {
            return [$item, $enrollment, self::refuse($reason), $map];
        }

        return [$item, $enrollment, null, $map];
    }

    /** @return array{0: ?CourseModuleItem, 1: ?Enrollment} the item (null = no such item of that type) and the student's active enrollment in its course */
    private function find(Request $request, int $itemId, ItemType $expected): array
    {
        $item = CourseModuleItem::with(['module', 'content', 'resource'])->find($itemId);
        if (!$item || $item->item_type !== $expected || !$item->module) {
            return [null, null];
        }

        $courseId = CourseCurriculum::withTrashed()->whereKey($item->module->course_curriculum_id)->value('course_list_id');

        return [$item, $courseId ? $this->access->enrollmentFor($request->user(), (int) $courseId) : null];
    }
}
