<?php

// Loaded from routes/web.php inside the admin group.

use App\Http\Controllers\Admin\Communication\EmailComposerController;
use App\Http\Controllers\Admin\Communication\SmsComposerController;
use Illuminate\Support\Facades\Route;

Route::get('communication/email', [EmailComposerController::class, 'create'])
    ->name('admin.communication.email.create');
Route::post('communication/email/image', [EmailComposerController::class, 'uploadImage'])
    ->name('admin.communication.email.image');
Route::post('communication/email', [EmailComposerController::class, 'send'])
    ->middleware('throttle:10,1,compose-email') // named: unnamed throttles share one counter per admin
    ->name('admin.communication.email.send');

Route::get('communication/sms', [SmsComposerController::class, 'create'])
    ->name('admin.communication.sms.create');
Route::post('communication/sms', [SmsComposerController::class, 'send'])
    ->middleware('throttle:10,1,compose-sms')
    ->name('admin.communication.sms.send');

Route::view('communication/softphone', 'admin.communication.softphone')
    ->name('admin.communication.softphone.index');