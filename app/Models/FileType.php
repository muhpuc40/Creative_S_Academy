<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A group of allowed upload formats (System > File types), e.g. "CLO3D" => zprj, zpac, obj ...
 * Checked for the resource library (chunked uploads), assignment files and email attachments (App\Rules\AllowedFile).
 */
class FileType extends Model
{
    /**
     * Never allowed, whatever System > File types says: config('chunkit.blocked_extensions'),
     * the one list chunkit itself also refuses.
     */
    public static function blocked(): array
    {
        return array_map(fn ($ext) => strtolower(ltrim((string) $ext, '.')), (array) config('chunkit.blocked_extensions', []));
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'extensions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** "ai, .EPS  svg" => ["ai", "eps", "svg"] */
    public static function parse(?string $input): array
    {
        $parts = preg_split('/[\s,]+/', strtolower((string) $input), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(
            array_map(fn ($ext) => preg_replace('/[^a-z0-9]/', '', $ext), $parts)
        )));
    }

    /**
     * Allowed extension => max size in MB, from the active groups.
     * An extension in several groups (pdf, dxf ...) gets the largest of their limits.
     */
    public static function limits(): array
    {
        $limits = [];

        foreach (static::active()->get(['extensions', 'max_size_mb']) as $type) {
            foreach ($type->extensions as $ext) {
                $limits[$ext] = max($limits[$ext] ?? 0, $type->max_size_mb);
            }
        }

        return array_diff_key($limits, array_flip(static::blocked()));
    }

    /** Why this file name / size can't be uploaded, or null when it can. */
    public static function problem(string $fileName, int $bytes): ?string
    {
        $parts = explode('.', strtolower($fileName));
        array_shift($parts);
        $ext = (string) end($parts);

        if (array_intersect($parts, static::blocked())) {
            return 'This file type is not allowed.';
        }

        $limits = static::limits();

        if ($ext === '' || ! isset($limits[$ext])) {
            return $ext === '' ? 'The file needs an extension, like .pdf or .zip.' : ".{$ext} files are not allowed.";
        }

        if ($bytes > $limits[$ext] * 1024 * 1024) {
            return "The file is larger than {$limits[$ext]} MB, the limit for .{$ext} files.";
        }

        return null;
    }

    /** For <input type="file" accept="...">: ".ai,.eps,.pdf,..." */
    public static function accept(): string
    {
        return implode(',', array_map(fn ($ext) => ".{$ext}", array_keys(static::limits())));
    }
}
