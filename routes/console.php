<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Chunked uploads (muhpuc40/chunkit). Both need the scheduler's single cron entry on the
 * server:  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 */

// Deletes unfinished uploads older than chunkit.ttl_hours (24h), with their chunks.
Schedule::command('chunkit:prune')->dailyAt('03:00');

// A finished upload that was never saved to a content (the admin closed the form) is not
// touched by chunkit:prune, so remove it after a day, file included.
Schedule::call(function () {
    ChunkedUpload::where('status', ChunkedUpload::STATUS_COMPLETED)
        ->where('updated_at', '<', now()->subDay())
        ->get()
        ->each(function ($upload) {
            /** @var ChunkedUpload $upload */
            if ($upload->destination_path) {
                Storage::disk(config('chunkit.final_disk'))->deleteDirectory(dirname($upload->destination_path));
            }
            $upload->delete();
        });
})->hourly()->name('chunkit:forget-unsaved-uploads');
