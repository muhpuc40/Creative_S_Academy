<?php

use App\Http\Controllers\Admin\AccountController;
use Illuminate\Support\Facades\Route;

// The signed-in admin's own account (reached from the topbar avatar menu)
Route::prefix('account')->name('admin.account.')->group(function () {
    Route::get('profile', [AccountController::class, 'profile'])->name('profile');
    Route::put('profile', [AccountController::class, 'updateProfile'])->name('profile.update');

    Route::get('devices', [AccountController::class, 'devices'])->name('devices');
    Route::post('devices/revoke', [AccountController::class, 'revokeDevice'])->name('devices.revoke');

    Route::put('password', [AccountController::class, 'updatePassword'])->name('password');
});
