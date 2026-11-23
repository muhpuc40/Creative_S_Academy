<?php

namespace App\Support;

use App\Models\CourseContent;
use App\Models\CourseResource;
use App\Models\Enrollment;
use Illuminate\Support\Facades\URL;

/**
 * Links a student's browser can open for a video or a resource, after AccessService said yes.
 *   - an uploaded (private) file -> a signed link to StudentMediaController, valid for LINK_MINUTES
 *     and tied to the enrollment (it stops working if the enrollment is revoked)
 *   - an external link          -> returned as it is
 * A <video> tag cannot send the login token, which is why a private file gets a signed link.
 * The signature covers the path and query only (signed:relative), so the link keeps working
 * behind a proxy that changes the host or http/https.
 */
class StudentMedia
{
    public const LINK_MINUTES = 120;

    /** @return array{type: string, url: string|null, expires_at: string|null} */
    public static function content(CourseContent $content, Enrollment $enrollment): array
    {
        if ($content->hasStoredFile()) {
            $expires = now()->addMinutes(self::LINK_MINUTES);

            return [
                'type' => 'file',
                'url' => url(URL::temporarySignedRoute('student.media.content', $expires, ['content' => $content->id, 'enrollment' => $enrollment->id], absolute: false)),
                'expires_at' => $expires->toIso8601String(),
            ];
        }

        return ['type' => 'external', 'url' => $content->file_url, 'expires_at' => null];
    }

    /** @return array{type: string, url: string|null, expires_at: string|null} */
    public static function resource(CourseResource $resource, Enrollment $enrollment): array
    {
        if ($resource->url && !Media::isExternal($resource->url)) {
            $expires = now()->addMinutes(self::LINK_MINUTES);

            return [
                'type' => 'file',
                'url' => url(URL::temporarySignedRoute('student.media.resource', $expires, ['resource' => $resource->id, 'enrollment' => $enrollment->id], absolute: false)),
                'expires_at' => $expires->toIso8601String(),
            ];
        }

        return ['type' => 'external', 'url' => $resource->url, 'expires_at' => null];
    }

    /** The assignment's brief file (signed, 2 hours), or null if it has none. */
    public static function assignmentFile(\App\Models\BatchAssignment $assignment, Enrollment $enrollment): ?array
    {
        return $assignment->assignment_file ? self::signed('student.media.assignment', ['assignment' => $assignment->id, 'enrollment' => $enrollment->id]) : null;
    }

    /** The exam paper (signed, 2 hours), or null if it has none. Hand it out only once the exam started. */
    public static function examPaper(\App\Models\BatchExam $exam, Enrollment $enrollment): ?array
    {
        return $exam->exam_paper ? self::signed('student.media.exam-paper', ['exam' => $exam->id, 'enrollment' => $enrollment->id]) : null;
    }

    /** The student's own submitted file (signed, 2 hours). */
    public static function submission(\App\Models\EnrollmentResult $result): ?array
    {
        return $result->file_path ? self::signed('student.media.submission', ['result' => $result->id]) : null;
    }

    /** @return array{type: string, url: string, expires_at: string} */
    private static function signed(string $route, array $params): array
    {
        $expires = now()->addMinutes(self::LINK_MINUTES);

        return [
            'type' => 'file',
            'url' => url(URL::temporarySignedRoute($route, $expires, $params, absolute: false)),
            'expires_at' => $expires->toIso8601String(),
        ];
    }
}
