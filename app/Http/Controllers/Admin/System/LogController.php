<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class LogController extends Controller
{
    /** Only the end of the log is shown: a log can grow to hundreds of MB, far too much to load into a page. */
    private const TAIL_BYTES = 512 * 1024;

    public function index(): View
    {
        $path = config('logging.channels.app_file.path', storage_path('logs/'.config('app.name').'.log'));
        $exists = File::exists($path);
        $readable = $exists && File::isReadable($path);
        $bytes = $readable ? File::size($path) : 0;
        $logs = $readable ? $this->tail($path, $bytes) : '';

        return view('admin.system.logs', [
            'logName' => basename($path),
            'logs' => $logs,
            'exists' => $exists,
            'readable' => $readable,
            'updatedAt' => $readable ? date('d M Y, h:i:s A', File::lastModified($path)) : null,
            'size' => $readable ? $this->fileSize($bytes) : null,
            'lines' => $logs === '' ? 0 : substr_count($logs, "\n") + 1,
            'truncated' => $bytes > self::TAIL_BYTES,
        ]);
    }

    /** The last TAIL_BYTES of the file, starting at a whole line. */
    private function tail(string $path, int $bytes): string
    {
        if ($bytes <= self::TAIL_BYTES) {
            return (string) File::get($path);
        }

        $handle = fopen($path, 'rb');
        fseek($handle, -self::TAIL_BYTES, SEEK_END);
        $text = (string) fread($handle, self::TAIL_BYTES);
        fclose($handle);

        $firstBreak = strpos($text, "\n");

        return $firstBreak === false ? $text : substr($text, $firstBreak + 1);
    }

    private function fileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes / (1024 * 1024), 2).' MB';
    }
}
