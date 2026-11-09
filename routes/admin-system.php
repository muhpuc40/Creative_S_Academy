<?php

// Loaded from routes/web.php inside the admin group.

use App\Http\Controllers\Admin\System\GoogleMeetController;
use App\Http\Controllers\Admin\System\LogController;
use App\Http\Controllers\Admin\System\ServerController;

use Illuminate\Support\Facades\Route;

Route::get('system/logs', [LogController::class, 'index'])
    ->name('admin.logs.index');
Route::get('system/server', [ServerController::class, 'index'])
    ->name('admin.server.index');

// Google Meet: connect the academy's Google account, then make Meet links for live classes
Route::get('system/google-meet', [GoogleMeetController::class, 'index'])->name('admin.google-meet.index');
Route::put('system/google-meet/client', [GoogleMeetController::class, 'saveClient'])->name('admin.google-meet.client');
Route::get('system/google-meet/connect', [GoogleMeetController::class, 'connect'])->name('admin.google-meet.connect');
Route::get('system/google-meet/callback', [GoogleMeetController::class, 'callback'])->name('admin.google-meet.callback');
Route::delete('system/google-meet', [GoogleMeetController::class, 'disconnect'])->name('admin.google-meet.disconnect');
Route::post('system/google-meet/link', [GoogleMeetController::class, 'link'])
    ->middleware('throttle:20,1')
    ->name('admin.google-meet.link');
