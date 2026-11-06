<?php

// Loaded from routes/web.php INSIDE the admin group (login + active admin required).

use App\Http\Controllers\Admin\Website\HeroContentController;
use App\Http\Controllers\Admin\Website\HeroSettingController;
use App\Http\Controllers\Admin\Website\PopupController;
use Illuminate\Support\Facades\Route;

// Website > Hero content: the slides of the home page hero
Route::get('website/hero', [HeroContentController::class, 'index'])->name('admin.website.hero.index');
Route::post('website/hero', [HeroContentController::class, 'store'])->name('admin.website.hero.store');
Route::put('website/hero/{hero}', [HeroContentController::class, 'update'])->whereNumber('hero')->name('admin.website.hero.update');
Route::patch('website/hero/{hero}/toggle', [HeroContentController::class, 'toggle'])->whereNumber('hero')->name('admin.website.hero.toggle');
Route::delete('website/hero/{hero}', [HeroContentController::class, 'destroy'])->whereNumber('hero')->name('admin.website.hero.destroy');

// Website > Hero settings: what the hero shows (course list / hero slides) and the slide time
Route::get('website/hero-settings', [HeroSettingController::class, 'edit'])->name('admin.website.hero-settings.edit');
Route::put('website/hero-settings', [HeroSettingController::class, 'update'])->name('admin.website.hero-settings.update');

// Website > Popups: what each popup shows, and on which pages, after how long, how often
Route::get('website/popups', [PopupController::class, 'index'])->name('admin.website.popups.index');
Route::post('website/popups', [PopupController::class, 'store'])->name('admin.website.popups.store');
Route::put('website/popups/{popup}', [PopupController::class, 'update'])->whereNumber('popup')->name('admin.website.popups.update');
Route::patch('website/popups/{popup}/toggle', [PopupController::class, 'toggle'])->whereNumber('popup')->name('admin.website.popups.toggle');
Route::delete('website/popups/{popup}', [PopupController::class, 'destroy'])->whereNumber('popup')->name('admin.website.popups.destroy');
