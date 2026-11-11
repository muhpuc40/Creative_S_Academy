<?php

// Loaded from routes/web.php INSIDE the admin group (login + active admin required).

use App\Http\Controllers\Admin\Course\CourseCategoryController;
use App\Http\Controllers\Admin\Course\CourseContentController;
use App\Http\Controllers\Admin\Course\CourseController;
use App\Http\Controllers\Admin\Course\CourseCurriculumController;
use App\Http\Controllers\Admin\Course\CourseFaqController;
use App\Http\Controllers\Admin\Course\CourseLearningOutcomeController;
use App\Http\Controllers\Admin\Course\CourseModuleController;
use App\Http\Controllers\Admin\Course\CourseModuleItemController;
use App\Http\Controllers\Admin\Course\CoursePaymentRuleController;
use App\Http\Controllers\Admin\Course\CoursePriceController;
use App\Http\Controllers\Admin\Course\CourseResourceController;
use App\Http\Controllers\Admin\Course\CourseReviewController;
use App\Http\Controllers\ContentFileController;
use Illuminate\Support\Facades\Route;

// Library pages first: "upload-content" must not be read as a course id.
Route::get('courses/upload-content', [CourseContentController::class, 'index'])->name('admin.library.content.index');
Route::post('courses/upload-content', [CourseContentController::class, 'store'])->name('admin.library.content.store');
Route::put('courses/upload-content/{content}', [CourseContentController::class, 'update'])->name('admin.library.content.update');
Route::delete('courses/upload-content/{content}', [CourseContentController::class, 'destroy'])->name('admin.library.content.destroy');
// Opens/plays a privately stored content file (admins only).
Route::get('courses/upload-content/{content}/file', ContentFileController::class)->whereNumber('content')->name('admin.library.content.file');

Route::get('courses/upload-resource', [CourseResourceController::class, 'index'])->name('admin.library.resources.index');
Route::post('courses/upload-resource', [CourseResourceController::class, 'store'])->name('admin.library.resources.store');
Route::put('courses/upload-resource/{resource}', [CourseResourceController::class, 'update'])->name('admin.library.resources.update');
Route::delete('courses/upload-resource/{resource}', [CourseResourceController::class, 'destroy'])->name('admin.library.resources.destroy');
// Opens a privately stored resource file (admins only).
Route::get('courses/upload-resource/{resource}/file', [CourseResourceController::class, 'file'])->whereNumber('resource')->name('admin.library.resources.file');

// Course: list, create, details, edit, delete
Route::get('courses', [CourseController::class, 'index'])->name('admin.courses.index');
Route::get('courses/create', [CourseController::class, 'create'])->name('admin.courses.create');
Route::post('courses', [CourseController::class, 'store'])->name('admin.courses.store');
Route::get('courses/{course}', [CourseController::class, 'show'])->whereNumber('course')->name('admin.courses.show');
Route::get('courses/{course}/edit', [CourseController::class, 'edit'])->whereNumber('course')->name('admin.courses.edit');
Route::put('courses/{course}', [CourseController::class, 'update'])->whereNumber('course')->name('admin.courses.update');
Route::delete('courses/{course}', [CourseController::class, 'destroy'])->whereNumber('course')->name('admin.courses.destroy');

// Everything inside a course (managed from the details page)
Route::post('courses/{course}/curricula', [CourseCurriculumController::class, 'store'])->whereNumber('course')->name('admin.curricula.store');
Route::put('curricula/{curriculum}', [CourseCurriculumController::class, 'update'])->name('admin.curricula.update');
Route::delete('curricula/{curriculum}', [CourseCurriculumController::class, 'destroy'])->name('admin.curricula.destroy');

Route::post('curricula/{curriculum}/modules', [CourseModuleController::class, 'store'])->name('admin.modules.store');
Route::put('modules/{module}', [CourseModuleController::class, 'update'])->name('admin.modules.update');
Route::delete('modules/{module}', [CourseModuleController::class, 'destroy'])->name('admin.modules.destroy');

Route::post('modules/{module}/items', [CourseModuleItemController::class, 'store'])->name('admin.items.store');
Route::put('items/{item}', [CourseModuleItemController::class, 'update'])->name('admin.items.update');
Route::delete('items/{item}', [CourseModuleItemController::class, 'destroy'])->name('admin.items.destroy');

Route::post('courses/{course}/prices', [CoursePriceController::class, 'store'])->whereNumber('course')->name('admin.prices.store');
Route::post('prices/{price}/rules', [CoursePaymentRuleController::class, 'store'])->name('admin.rules.store');
Route::put('rules/{rule}', [CoursePaymentRuleController::class, 'update'])->name('admin.rules.update');
Route::delete('rules/{rule}', [CoursePaymentRuleController::class, 'destroy'])->name('admin.rules.destroy');

Route::post('courses/{course}/outcomes', [CourseLearningOutcomeController::class, 'store'])->whereNumber('course')->name('admin.outcomes.store');
Route::put('outcomes/{outcome}', [CourseLearningOutcomeController::class, 'update'])->name('admin.outcomes.update');
Route::delete('outcomes/{outcome}', [CourseLearningOutcomeController::class, 'destroy'])->name('admin.outcomes.destroy');

Route::post('courses/{course}/faqs', [CourseFaqController::class, 'store'])->whereNumber('course')->name('admin.faqs.store');
Route::put('faqs/{faq}', [CourseFaqController::class, 'update'])->name('admin.faqs.update');
Route::delete('faqs/{faq}', [CourseFaqController::class, 'destroy'])->name('admin.faqs.destroy');

Route::put('reviews/{review}', [CourseReviewController::class, 'update'])->name('admin.reviews.update');
Route::delete('reviews/{review}', [CourseReviewController::class, 'destroy'])->name('admin.reviews.destroy');

// Course categories
Route::get('course-categories', [CourseCategoryController::class, 'index'])->name('admin.categories.index');
Route::post('course-categories', [CourseCategoryController::class, 'store'])->name('admin.categories.store');
Route::put('course-categories/{category}', [CourseCategoryController::class, 'update'])->name('admin.categories.update');
Route::delete('course-categories/{category}', [CourseCategoryController::class, 'destroy'])->name('admin.categories.destroy');
