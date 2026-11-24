<?php

use App\Http\Controllers\Api\AffiliateClickController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\LearnController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ComboController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\PopupController;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\LockApiLoginChallenge;
use App\Http\Middleware\TrackDeviceActivity;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:120,1,public-api')->group(function () {

    Route::get('home', [HomeController::class, 'index']);
    Route::get('popups', [PopupController::class, 'index']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('courses', [CourseController::class, 'index']);
    Route::get('courses/{course}', [CourseController::class, 'show']);
    Route::get('combos', [ComboController::class, 'index']);
    Route::get('combos/{combo}', [ComboController::class, 'show']); // slug
    Route::post('ref/{ref_code}/click', [AffiliateClickController::class, 'click']);
    Route::get('ref/attribution', [AffiliateClickController::class, 'attribution']);
    Route::get('payment-accounts', [PaymentController::class, 'accounts']);
});


// Sign-up (student or affiliate): 1. register  2. login/send-code  3. login/verify
Route::post('v1/{type}/register', [AuthController::class, 'register'])
    ->where('type', 'student|affiliate')
    ->middleware('throttle:5,1,register');

// /api/v1/{admin|student|instructor|affiliate}/
Route::prefix('v1/{type}')->where(['type' => 'student|instructor|affiliate'])->group(function () {

    // Login: 1. password  2. send code  3. verify code
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('login/send-code', [AuthController::class, 'sendCode'])->middleware(['throttle:otp', LockApiLoginChallenge::class]);
    Route::post('login/verify', [AuthController::class, 'verify'])->middleware(['throttle:otp', LockApiLoginChallenge::class]);
    Route::post('password/reset/start', [PasswordResetController::class, 'start'])->middleware('throttle:password-reset');
    Route::post('password/reset/send-code', [PasswordResetController::class, 'sendCode'])->middleware('throttle:password-reset');
    Route::post('password/reset/verify-code', [PasswordResetController::class, 'verifyCode'])->middleware('throttle:password-reset');
    Route::post('password/reset/complete', [PasswordResetController::class, 'complete'])->middleware('throttle:password-reset');

    Route::middleware(['auth:sanctum', EnsureUserType::class, TrackDeviceActivity::class])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('v1/{type}')->where(['type' => 'student'])
    ->middleware(['auth:sanctum', EnsureUserType::class, TrackDeviceActivity::class])
    ->group(function () {
        Route::post('checkout/quote', [CheckoutController::class, 'quote'])
            ->middleware('throttle:30,1,checkout-quote');
        Route::post('checkout/order', [CheckoutController::class, 'store'])
            ->middleware('throttle:10,1,checkout-order');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{orderNo}', [OrderController::class, 'show'])
            ->where('orderNo', '[A-Za-z0-9-]{1,30}');
        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware('throttle:10,1,payment-submit');
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('enrollments', [EnrollmentController::class, 'index']);
        Route::post('enrollments/{enrollmentId}/renew', [EnrollmentController::class, 'renew'])
            ->where('enrollmentId', '[0-9]+')->middleware('throttle:10,1,enrollment-renew');
        Route::prefix('learn/{courseSlug}')->where(['courseSlug' => '[A-Za-z0-9-]{1,200}'])
            ->group(function () {
                Route::get('/', [LearnController::class, 'overview']);
                Route::get('outline', [LearnController::class, 'outline']);
                Route::get('modules/{moduleId}', [LearnController::class, 'module'])
                    ->where('moduleId', '[0-9]+');
                Route::get('schedule', [LearnController::class, 'schedule']);
                Route::get('results', [LearnController::class, 'results']);
                Route::get('attendance', [LearnController::class, 'attendance']);
                Route::get('resources', [LearnController::class, 'resources']);
            });

        Route::get('classes', [ClassController::class, 'index']);
        Route::post('classes/{classId}/join', [ClassController::class, 'join'])
            ->where('classId', '[0-9]+')->middleware('throttle:20,1,class-join');
        Route::get('classes/{classId}/recording', [ClassController::class, 'recording'])
            ->where('classId', '[0-9]+');
        Route::post('classes/{classId}/recording/complete', [ClassController::class, 'completeRecording'])
            ->where('classId', '[0-9]+')->middleware('throttle:30,1,recording-complete');
        Route::post('classes/{classId}/recording/position', [ClassController::class, 'recordingPosition'])
            ->where('classId', '[0-9]+')->middleware('throttle:2,1,recording-position');
        Route::get('items/{itemId}/play', [LearningController::class, 'play'])
            ->where('itemId', '[0-9]+')->middleware('throttle:60,1,item-play');
        Route::post('items/{itemId}/complete', [LearningController::class, 'complete'])
            ->where('itemId', '[0-9]+')->middleware('throttle:30,1,item-complete');
        Route::post('items/{itemId}/position', [LearningController::class, 'position'])
            ->where('itemId', '[0-9]+')->middleware('throttle:2,1,item-position');
        Route::get('items/{itemId}/resource', [LearningController::class, 'resource'])
            ->where('itemId', '[0-9]+')->middleware('throttle:60,1,item-resource');
        Route::get('assignments/{assignmentId}', [SubmissionController::class, 'assignment'])
            ->where('assignmentId', '[0-9]+');
        Route::post('assignments/{assignmentId}/submission', [SubmissionController::class, 'submitAssignment'])
            ->where('assignmentId', '[0-9]+')->middleware('throttle:10,1,assignment-submit');
        Route::get('exams/{examId}', [SubmissionController::class, 'exam'])
            ->where('examId', '[0-9]+');
        Route::post('exams/{examId}/answer', [SubmissionController::class, 'submitExam'])
            ->where('examId', '[0-9]+')->middleware('throttle:10,1,exam-submit');
    });
