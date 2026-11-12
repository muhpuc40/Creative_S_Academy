<?php

use App\Http\Controllers\Admin\AccountController;
use Illuminate\Support\Facades\Route;

// The signed-in admin's own account (reached from the topbar avatar menu)
Route::prefix('account')->name('admin.account.')->group(function () {
    Route::get('profile', [AccountController::class, 'profile'])->name('profile');
    Route::get('devices', [AccountController::class, 'devices'])->name('devices');

    // Writes — rate limited (6 per minute) so the current-password checks can't be brute-forced
    Route::middleware('throttle:6,1')->group(function () {
        Route::put('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
        Route::post('devices/revoke', [AccountController::class, 'revokeDevice'])->name('devices.revoke');
        Route::put('password', [AccountController::class, 'updatePassword'])->name('password');
    });
});
