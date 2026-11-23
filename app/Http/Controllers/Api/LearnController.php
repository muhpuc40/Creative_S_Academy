<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Student\CoursePage;
use App\Models\Course;
use App\Models\Enrollment;
use App\Services\Enrollment\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An enrolled student's course pages, by course slug: /api/v1/student/learn/{courseSlug}/...
 * Each page is built from one CourseMap (about a dozen queries, whatever the course size).
 * 404 { reason: not_enrolled } when the student has no active enrollment in the course.
 *
 * The pages only describe: to play, open, join or submit, the website calls the existing action
 * endpoints (items/{id}/play, classes/{id}/join, exams/{id}/answer, ...) with the ids given here.
 */
class LearnController extends Controller
{
    public function __construct(private AccessService $access) {}

    /** GET learn/{courseSlug}: course home (progress, access, next class, deadlines, continue). */
    public function overview(Request $request, string $type, string $courseSlug): JsonResponse
    {
        return $this->page($request, $courseSlug, fn (CoursePage $page) => $page->overview());
    }

    /** GET learn/{courseSlug}/outline: stages > modules > items with done / lock states. */
    public function outline(Request $request, string $type, string $courseSlug): JsonResponse
    {
        return $this->page($request, $courseSlug, fn (CoursePage $page) => $page->outline());
    }

    /** GET learn/{courseSlug}/modules/{moduleId}: one module, each item with its details. */
    public function module(Request $request, string $type, string $courseSlug, int $moduleId): JsonResponse
    {
        return $this->page($request, $courseSlug, fn (CoursePage $page) => $page->module($moduleId));
    }

    /** GET learn/{courseSlug}/schedule: classes, video releases, exams and assignment deadlines. */
    public function schedule(Request $request, string $type, string $courseSlug): JsonResponse
    {
        return $this->page($request, $courseSlug, fn (CoursePage $page) => $page->schedule());
    }

    /** GET learn/{courseSlug}/results: exams and assignments with my marks. */
    public function results(Request $request, string $type, string $courseSlug): JsonResponse
    {
        return $this->page($request, $courseSlug, fn (CoursePage $page) => $page->results());
    }

    /** GET learn/{courseSlug}/attendance: my attendance in the live classes. */
    public function attendance(Request $request, string $type, string $courseSlug): JsonResponse
    {
        return $this->page($request, $courseSlug, fn (CoursePage $page) => $page->attendance());
    }

    /** GET learn/{courseSlug}/resources: every resource of the course. */
    public function resources(Request $request, string $type, string $courseSlug): JsonResponse
    {
        return $this->page($request, $courseSlug, fn (CoursePage $page) => $page->resources());
    }

    private function page(Request $request, string $courseSlug, \Closure $build): JsonResponse
    {
        $enrollment = $this->enrollment($request, $courseSlug);
        if (!$enrollment) {
            return response()->json(['message' => 'You are not enrolled in this course.', 'reason' => AccessService::NOT_ENROLLED], 404);
        }

        $data = $build(CoursePage::for($enrollment));
        if ($data === null) {
            return response()->json(['message' => 'Not found.', 'reason' => 'not_found'], 404);
        }

        return response()->json(['data' => $data]);
    }

    private function enrollment(Request $request, string $courseSlug): ?Enrollment
    {
        $courseId = Course::where('slug', $courseSlug)->value('id');

        return $courseId ? $this->access->enrollmentFor($request->user(), (int) $courseId) : null;
    }
}
