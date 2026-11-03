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
 * Scheduled jobs. They only run if the scheduler runs:
 *   server (cron):  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 *   local (XAMPP):  php artisan schedule:work   (keep it open in a terminal)
 */

// Expires unpaid orders whose 24h payment window has passed (frees seats + vouchers).
// The one place orders expire - see App\Console\Commands\ExpireUnpaidOrders.
// Every 6 hours, so an unpaid order is released 24-30h after checkout.
Schedule::command('orders:expire')->everySixHours()->withoutOverlapping();

// Chunked uploads (muhpuc40/chunkit): deletes unfinished uploads older than chunkit.ttl_hours (24h), with their chunks.
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
