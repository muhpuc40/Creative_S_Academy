<?php

namespace App\Http\Controllers;

use App\Models\BatchAssignment;
use App\Models\BatchExam;
use App\Models\CourseContent;
use App\Models\CourseResource;
use App\Models\Enrollment;
use App\Models\EnrollmentResult;
use App\Support\PrivateFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a private video or resource to an enrolled student. Reached only through the signed,
 * short-lived links App\Support\StudentMedia hands out (after AccessService said yes), so the
 * access rules were already checked; here only "is the enrollment still active?" is checked again.
 * Videos support HTTP Range requests (seeking) and are never cached.
 */
class StudentMediaController extends Controller
{
    public function content(CourseContent $content, Enrollment $enrollment): BinaryFileResponse
    {
        abort_unless($enrollment->isActive() && $content->hasStoredFile(), 404);

        $disk = Storage::disk($content->file_disk);
        abort_unless($disk->exists($content->file_path), 404);

        $response = response()->file($disk->path($content->file_path), [
            'Content-Type' => $content->file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // a file response is "public" by default: never let a shared cache keep a student's video
        return $this->private($response);
    }

    public function resource(CourseResource $resource, Enrollment $enrollment): BinaryFileResponse
    {
        abort_unless($enrollment->isActive(), 404);

        return $this->private(PrivateFile::response($resource->url, $resource->name));
    }

    public function assignment(BatchAssignment $assignment, Enrollment $enrollment): BinaryFileResponse
    {
        abort_unless($enrollment->isActive() && (int) $enrollment->batch_list_id === (int) $assignment->batch_list_id, 404);

        return $this->private(PrivateFile::response($assignment->assignment_file, $assignment->title));
    }

    /** The exam paper opens only once the exam has started, even with a valid link. */
    public function examPaper(BatchExam $exam, Enrollment $enrollment): BinaryFileResponse
    {
        abort_unless($enrollment->isActive() && (int) $enrollment->batch_list_id === (int) $exam->batch_list_id, 404);
        abort_if(now()->lt($exam->startsAt()), 404);

        return $this->private(PrivateFile::response($exam->exam_paper, $exam->title));
    }

    /** A student's own submitted assignment / exam answer. */
    public function submission(EnrollmentResult $result): BinaryFileResponse
    {
        abort_unless($result->enrollment?->isActive(), 404);

        return $this->private(PrivateFile::response($result->file_path, pathinfo((string) $result->file_name, PATHINFO_FILENAME) ?: 'submission'));
    }

    private function private(BinaryFileResponse $response): BinaryFileResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
