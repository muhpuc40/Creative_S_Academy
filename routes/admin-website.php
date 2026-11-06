<?php

// Loaded from routes/web.php INSIDE the admin group (login + active admin required).

use App\Http\Controllers\Admin\Website\HeroContentController;
use App\Http\Controllers\Admin\Website\HeroSettingController;
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
