<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\ContentFileController;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\LockAdminLoginChallenge;
use Illuminate\Support\Facades\Route;

// A free preview video for the public course page. Only works with the temporary signed
// link the course API hands out (CourseContent::previewUrl()), and only until it expires.
Route::get('media/content/{content}', ContentFileController::class)
    ->whereNumber('content')
    ->middleware(['signed', 'throttle:120,1'])
    ->name('content.preview');

Route::middleware('guest')->group(function () {
    // Admin login: 1. password  2. send code  3. verify code
    Route::get('login', [LoginController::class, 'show'])
        ->name('admin.login');
    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:login');

    Route::get('login/verify', [LoginController::class, 'verifyPage'])->name('admin.login.verify');
    Route::post('login/send-code', [LoginController::class, 'sendCode'])
        ->middleware(['throttle:otp', LockAdminLoginChallenge::class])
        ->name('admin.login.send-code');

    Route::post('login/verify', [LoginController::class, 'verify'])
        ->middleware(['throttle:otp', LockAdminLoginChallenge::class])
        ->name('admin.login.verify.submit');

    // Device limit reached: choose an old device to sign out
    Route::get('login/devices', [LoginController::class, 'devices'])->name('admin.login.devices');
    Route::post('login/devices', [LoginController::class, 'chooseDevice'])->name('admin.login.devices.choose');

    Route::post('login/cancel', [LoginController::class, 'cancel'])->name('admin.login.cancel');
});

Route::middleware(['auth', EnsureAdminIsActive::class])->group(function () {
    Route::redirect('/', '/dashboard');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::post('logout', [LoginController::class, 'destroy'])->name('admin.logout');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

    require __DIR__ . '/admin-courses.php';
    require __DIR__ . '/admin-batches.php';
    require __DIR__ . '/admin-sales.php';
    require __DIR__ . '/admin-affiliate.php';
    require __DIR__ . '/admin-communication.php';
    require __DIR__ . '/admin-website.php';
    require __DIR__ . '/admin-security.php';
    require __DIR__ . '/admin-system.php';

});
