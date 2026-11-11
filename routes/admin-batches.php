<?php

// Loaded from routes/web.php INSIDE the admin group (login + active admin required).

use App\Http\Controllers\Admin\Batch\BatchAssignmentController;
use App\Http\Controllers\Admin\Batch\BatchClassController;
use App\Http\Controllers\Admin\Batch\BatchController;
use App\Http\Controllers\Admin\Batch\BatchExamController;
use Illuminate\Support\Facades\Route;

// Batch: list, create, details, edit, delete
Route::get('batches', [BatchController::class, 'index'])->name('admin.batches.index');
Route::get('batches/create', [BatchController::class, 'create'])->name('admin.batches.create');
Route::post('batches', [BatchController::class, 'store'])->name('admin.batches.store');
Route::get('batches/{batch}', [BatchController::class, 'show'])->whereNumber('batch')->name('admin.batches.show');
Route::get('batches/{batch}/edit', [BatchController::class, 'edit'])->whereNumber('batch')->name('admin.batches.edit');
Route::put('batches/{batch}', [BatchController::class, 'update'])->whereNumber('batch')->name('admin.batches.update');
Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->whereNumber('batch')->name('admin.batches.destroy');

// Everything inside a batch (managed from the details page)
Route::post('batches/{batch}/classes', [BatchClassController::class, 'store'])->whereNumber('batch')->name('admin.batch-classes.store');
Route::put('batch-classes/{schedule}', [BatchClassController::class, 'update'])->whereNumber('schedule')->name('admin.batch-classes.update');
Route::patch('batch-classes/{schedule}/held', [BatchClassController::class, 'held'])->whereNumber('schedule')->name('admin.batch-classes.held');
Route::delete('batch-classes/{schedule}', [BatchClassController::class, 'destroy'])->whereNumber('schedule')->name('admin.batch-classes.destroy');

Route::post('batches/{batch}/exams', [BatchExamController::class, 'store'])->whereNumber('batch')->name('admin.batch-exams.store');
Route::put('batch-exams/{exam}', [BatchExamController::class, 'update'])->whereNumber('exam')->name('admin.batch-exams.update');
Route::patch('batch-exams/{exam}/result', [BatchExamController::class, 'result'])->whereNumber('exam')->name('admin.batch-exams.result');
Route::delete('batch-exams/{exam}', [BatchExamController::class, 'destroy'])->whereNumber('exam')->name('admin.batch-exams.destroy');
Route::get('batch-exams/{exam}/paper', [BatchExamController::class, 'paper'])->whereNumber('exam')->name('admin.batch-exams.paper'); // private file, admins only

Route::post('batches/{batch}/assignments', [BatchAssignmentController::class, 'store'])->whereNumber('batch')->name('admin.batch-assignments.store');
Route::put('batch-assignments/{assignment}', [BatchAssignmentController::class, 'update'])->whereNumber('assignment')->name('admin.batch-assignments.update');
Route::delete('batch-assignments/{assignment}', [BatchAssignmentController::class, 'destroy'])->whereNumber('assignment')->name('admin.batch-assignments.destroy');
Route::get('batch-assignments/{assignment}/file', [BatchAssignmentController::class, 'file'])->whereNumber('assignment')->name('admin.batch-assignments.file'); // private file, admins only
