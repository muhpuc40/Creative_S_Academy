<?php

namespace App\Support\Security;

use App\Models\FileType;
use App\Models\QuarantinedFile;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * On-demand security scan for System > Security Check.
 *
 * The file scan walks only the uploaded-file folders (config security.scan_paths),
 * flags anything that is a forbidden type, hides a second extension, or carries
 * runnable code, and moves (quarantines) it out of harm's way. The health checks
 * report on configuration, storage and the upload system without changing anything.
 */
class SecurityScanner
{
    /** The non-file checks, in the order the modal runs them. Keep in sync with check(). */
    public const HEALTH = [
        ['id' => 'config', 'title' => 'Configuration & environment'],
        ['id' => 'storage', 'title' => 'Storage & system'],
        ['id' => 'uploads', 'title' => 'Upload system health'],
    ];

    /* ------------------------------------------------------------------ files */

    /** Snapshot the file list for one scan into a work file, so progress is stable across batches. */
    public function startFileScan(): array
    {
        $this->pruneOldWork();

        $token = (string) Str::uuid();
        $files = $this->targets();

        File::ensureDirectoryExists(config('security.work_path'));
        File::put($this->workFile($token), json_encode($files));

        return ['token' => $token, 'total' => count($files)];
    }

    /** Inspect one batch of the snapshot; quarantine any threats; report progress + findings. */
    public function scanBatch(string $token, int $offset): array
    {
        $all = $this->readWork($token);
        $size = max(1, (int) config('security.batch_size'));
        $slice = array_slice($all, $offset, $size);

        $findings = [];
        foreach ($slice as $path) {
            if (! is_file($path)) {
                continue; // moved/removed since the snapshot
            }
            if ($reason = $this->inspect($path)) {
                $row = $this->quarantine($path, $reason);
                $findings[] = [
                    'id' => $row->id,
                    'path' => $row->display_path,
                    'reason' => $row->reason,
                ];
            }
        }

        $next = $offset + count($slice);
        $done = $next >= count($all) || $slice === [];

        if ($done) {
            File::delete($this->workFile($token));
        }

        return [
            'scanned' => $next,
            'total' => count($all),
            'next_offset' => $next,
            'done' => $done,
            'findings' => $findings,
        ];
    }

    /** All files under the scan paths (missing folders and the quarantine/work folders are skipped). */
    public function targets(): array
    {
        $skip = [
            $this->normalize(config('security.quarantine_path')),
            $this->normalize(config('security.work_path')),
        ];

        $out = [];
        foreach ((array) config('security.scan_paths') as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $norm = $this->normalize($path);
                foreach ($skip as $s) {
                    if (str_starts_with($norm, $s.'/')) {
                        continue 2;
                    }
                }
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    /** Why this file is a threat, or null if it looks safe. */
    public function inspect(string $path): ?string
    {
        $blocked = FileType::blocked();
        $parts = explode('.', basename($path));
        $ext = strtolower((string) end($parts));

        // Real extension is one that can run on the server or in the browser.
        if (count($parts) > 1 && in_array($ext, $blocked, true)) {
            return "Forbidden file type (.{$ext})";
        }

        // A dangerous extension hidden before the real one, e.g. shell.php.jpg
        if (count($parts) > 2) {
            foreach (array_slice($parts, 1, -1) as $middle) {
                if (in_array(strtolower($middle), $blocked, true)) {
                    return "Hidden double extension (.{$middle} inside the name)";
                }
            }
        }

        // Runnable code sitting inside an otherwise-inert upload (a disguised web shell).
        $head = @file_get_contents($path, false, null, 0, (int) config('security.sniff_bytes'));
        if ($head !== false && $head !== '') {
            $lower = strtolower($head);
            foreach ((array) config('security.code_markers') as $marker) {
                if ($marker === '#!/') {
                    if (str_starts_with(ltrim($head), '#!/')) {
                        return 'Executable script header inside an upload';
                    }
                } elseif (str_contains($lower, strtolower($marker))) {
                    return "Runnable code inside an upload ({$marker})";
                }
            }
        }

        return null;
    }

    /** Move a flagged file into the quarantine folder and record it. */
    public function quarantine(string $path, string $reason): QuarantinedFile
    {
        $dir = rtrim(config('security.quarantine_path'), '/\\').'/'.date('Y-m-d');
        File::ensureDirectoryExists($dir);

        $size = is_file($path) ? (int) filesize($path) : 0;
        // .quarantined so nothing can run it even if the folder were ever exposed.
        $stored = $dir.'/'.basename($path).'-'.Str::random(8).'.quarantined';
        File::move($path, $stored);

        return QuarantinedFile::create([
            'original_path' => $path,
            'stored_path' => $stored,
            'display_path' => $this->displayPath($path),
            'reason' => $reason,
            'size' => $size,
        ]);
    }

    /** Put a quarantined file back where it came from. Returns an error string, or null on success. */
    public function restore(QuarantinedFile $item): ?string
    {
        if (! is_file($item->stored_path)) {
            $item->delete();

            return 'The quarantined file is missing; the record was cleared.';
        }
        if (file_exists($item->original_path)) {
            return 'A file already exists where this was; restore cancelled.';
        }

        File::ensureDirectoryExists(dirname($item->original_path));
        File::move($item->stored_path, $item->original_path);
        $item->delete();

        return null;
    }

    /** Delete a quarantined file for good. */
    public function remove(QuarantinedFile $item): void
    {
        if (is_file($item->stored_path)) {
            File::delete($item->stored_path);
        }
        $item->delete();
    }

    /* ----------------------------------------------------------------- health */

    /** Run one named health check (see HEALTH). */
    public function check(string $id): array
    {
        $items = match ($id) {
            'config' => $this->configItems(),
            'storage' => $this->storageItems(),
            'uploads' => $this->uploadItems(),
            default => [],
        };

        $title = collect(self::HEALTH)->firstWhere('id', $id)['title'] ?? $id;

        return [
            'id' => $id,
            'title' => $title,
            'status' => $this->worst($items),
            'items' => $items,
        ];
    }

    private function configItems(): array
    {
        $env = (string) config('app.env');
        $local = $env === 'local';

        return [
            $this->item(
                'Debug mode',
                config('app.debug') ? ($local ? 'warn' : 'fail') : 'ok',
                config('app.debug') ? 'On — leaks stack traces; keep it off in production.' : 'Off.'
            ),
            $this->item('App key', config('app.key') ? 'ok' : 'fail', config('app.key') ? 'Set.' : 'APP_KEY is empty — run php artisan key:generate.'),
            $this->item('Environment', 'ok', $env),
            $this->item(
                'HTTPS-only session cookie',
                $local ? 'ok' : (config('session.secure') ? 'ok' : 'warn'),
                $local ? 'Not required in local.' : (config('session.secure') ? 'Enabled.' : 'Set SESSION_SECURE_COOKIE=true behind HTTPS.')
            ),
        ];
    }

    private function storageItems(): array
    {
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbOk = false;
        }

        return [
            $this->item('Database connection', $dbOk ? 'ok' : 'fail', $dbOk ? 'Reachable.' : 'Could not connect to the database.'),
            $this->item('Private uploads writable', is_writable(storage_path('app/private')) ? 'ok' : 'fail', storage_path('app/private')),
            $this->item('Public uploads writable', is_dir(storage_path('app/public')) && ! is_writable(storage_path('app/public')) ? 'fail' : 'ok', storage_path('app/public')),
            $this->storageLinkItem(),
            $this->item('.env not web-exposed', file_exists(public_path('.env')) ? 'fail' : 'ok', file_exists(public_path('.env')) ? 'A .env is inside public/ — remove it now.' : 'Not in the web root.'),
        ];
    }

    /**
     * The public/storage link. On Windows `storage:link` writes an absolute target, so moving or
     * renaming the project folder leaves a stale link that no longer resolves — report that apart
     * from a link that was never created. is_dir() follows the link; readlink() is the fallback for
     * the Windows case where is_dir() on a valid symlink can report false.
     */
    private function storageLinkItem(): array
    {
        $link = public_path('storage');

        if (is_dir($link)) {
            return $this->item('Storage symlink', 'ok', 'Linked.');
        }

        $target = @readlink($link);
        if ($target !== false) {
            $resolved = preg_match('#^(/|[A-Za-z]:[\\\\/])#', $target)
                ? $target
                : dirname($link).DIRECTORY_SEPARATOR.$target;
            if (is_dir($resolved)) {
                return $this->item('Storage symlink', 'ok', 'Linked.');
            }

            return $this->item('Storage symlink', 'warn', 'Stale link — delete public/storage, then run php artisan storage:link.');
        }

        if (is_link($link) || @lstat($link) !== false) {
            return $this->item('Storage symlink', 'warn', 'Stale link — delete public/storage, then run php artisan storage:link.');
        }

        return $this->item('Storage symlink', 'warn', 'Missing — run php artisan storage:link.');
    }

    private function uploadItems(): array
    {
        $active = FileType::where('is_active', true)->count();

        $stale = 0;
        try {
            if (class_exists(\Muhpuc40\Chunkit\Models\ChunkedUpload::class)) {
                $stale = \Muhpuc40\Chunkit\Models\ChunkedUpload::where('expires_at', '<', now())->count();
            }
        } catch (\Throwable) {
            $stale = 0;
        }

        return [
            $this->item('Active file types', $active > 0 ? 'ok' : 'warn', $active > 0 ? "{$active} active group(s)." : 'None active — nothing can be uploaded.'),
            $this->item('Blocked extension list', count(FileType::blocked()) > 0 ? 'ok' : 'warn', count(FileType::blocked()).' extensions can never be uploaded.'),
            $this->item('Unfinished uploads', $stale > 0 ? 'warn' : 'ok', $stale > 0 ? "{$stale} expired upload(s) can be pruned (php artisan chunkit:prune)." : 'None waiting to be pruned.'),
        ];
    }

    /* ------------------------------------------------------------------ utils */

    private function item(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /** Worst status across a set of items: fail beats warn beats ok. */
    private function worst(array $items): string
    {
        $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
        $max = 0;
        foreach ($items as $i) {
            $max = max($max, $rank[$i['status']] ?? 0);
        }

        return array_flip($rank)[$max];
    }

    private function workFile(string $token): string
    {
        return rtrim(config('security.work_path'), '/\\').'/'.$token.'.json';
    }

    private function readWork(string $token): array
    {
        $file = $this->workFile($token);

        return is_file($file) ? (array) json_decode(File::get($file), true) : [];
    }

    private function pruneOldWork(): void
    {
        $dir = config('security.work_path');
        if (! is_dir($dir)) {
            return;
        }
        foreach (File::files($dir) as $f) {
            if ($f->getMTime() < now()->subHour()->getTimestamp()) {
                File::delete($f->getPathname());
            }
        }
    }

    private function displayPath(string $path): string
    {
        $norm = $this->normalize($path);
        $after = Str::after($norm, '/storage/app/');

        return $after !== $norm ? $after : basename($path);
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
