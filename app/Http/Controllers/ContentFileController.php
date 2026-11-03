<?php

namespace App\Http\Controllers;

use App\Models\CourseContent;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a private course-content file. Supports HTTP Range requests, so a video can be
 * seeked without downloading it whole.
 *
 * Reached only through:
 *   - admin.library.content.file  (logged-in active admin)
 *   - content.preview             (temporary signed link, free preview items only)
 */
class ContentFileController extends Controller
{
    public function __invoke(CourseContent $content): BinaryFileResponse
    {
        abort_unless($content->hasStoredFile(), 404);

        $disk = Storage::disk($content->file_disk);
        abort_unless($disk->exists($content->file_path), 404);

        return response()->file($disk->path($content->file_path), [
            'Content-Type' => $content->file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
