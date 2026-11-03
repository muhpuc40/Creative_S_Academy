<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/**
 * What a class / exam / assignment item shows. Reusable by many items.
 *
 * Two kinds of source:
 *   - url        an external link (or an old upload on the public disk)
 *   - file_path  a file uploaded through chunkit, kept on a PRIVATE disk (file_disk).
 *                It has no public URL: admins open it via admin.library.content.file,
 *                and the public course API only gets a short-lived signed link for
 *                free preview items. The student playback API (HLS, enrollment +
 *                module-access check) will plug in here later.
 */
class CourseContent extends Model
{
    protected $table = 'course_content';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(fn () => Media::url($this->thumbnail));
    }

    /** Link of an external / public file. Null for a private upload (see previewUrl()). */
    protected function fileUrl(): Attribute
    {
        return Attribute::get(fn () => $this->hasStoredFile() ? null : Media::url($this->url));
    }

    /** True when the content is a private file uploaded through chunkit. */
    public function hasStoredFile(): bool
    {
        return $this->file_path !== null && $this->file_disk !== null;
    }

    /**
     * A link anyone holding it can play for $minutes - only for FREE PREVIEW items.
     * External links are returned as they are.
     */
    public function previewUrl(int $minutes = 120): ?string
    {
        if (! $this->hasStoredFile()) {
            return $this->file_url;
        }

        return URL::temporarySignedRoute('content.preview', now()->addMinutes($minutes), ['content' => $this->id]);
    }

    /** e.g. "1.0 GB". Plain PHP, so it works without the intl extension (XAMPP has none). */
    public function fileSizeForHumans(): string
    {
        $bytes = (float) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $bytes : number_format($bytes, 1)).' '.$units[$i];
    }

    /** Folder that holds this content's private file. */
    public function storageDirectory(): string
    {
        return 'course-content/'.$this->id;
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourseModuleItem::class, 'course_content_id');
    }
}
