<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Muhpuc40\Chunkit\Models\ChunkedUpload;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('orders:expire')->everySixHours()->withoutOverlapping();
Schedule::command('security:check')->daily()->withoutOverlapping();
Schedule::command('chunkit:prune')->dailyAt('03:00');
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
