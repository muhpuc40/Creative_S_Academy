<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Course files that only logged-in admins (and later, signed links for students) may open:
 * resources, assignment files, exam papers. Kept on the PRIVATE "local" disk
 * (storage/app/private), never on the public disk. The DB column holds the path
 * (or, for a resource, an external https:// link - those are left alone).
 */
class PrivateFile
{
    public const DISK = 'local';

    /** Types a browser may show inline. Anything else (svg, html, zip, exe ...) is downloaded. */
    private const INLINE = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'audio/mpeg', 'text/plain',
    ];

    /** Stores an upload under $directory with a random name, keeping its extension. Returns the path. */
    public static function store(UploadedFile $file, string $directory): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return $file->storeAs($directory, Str::random(40).($ext !== '' ? ".{$ext}" : ''), self::DISK);
    }

    /** External links and empty values are ignored. */
    public static function delete(?string $path): void
    {
        if ($path && ! Media::isExternal($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Sends the file. $name (e.g. the resource name) becomes the download name, with the file's extension.
     * "sandbox" stops any script inside the file (e.g. an SVG) from running on this site.
     */
    public static function response(?string $path, string $name): BinaryFileResponse
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);
        abort_unless($path && ! Media::isExternal($path) && $disk->exists($path), 404);

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $fileName = (Str::slug($name) ?: 'file').($ext !== '' ? ".{$ext}" : '');
        $inline = in_array($mime, self::INLINE, true);

        return response()->file($disk->path($path), [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $fileName),
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
