<?php

use App\Http\Controllers\Api\AffiliateClickController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EnrollmentController;
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


// Every throttle here has its own name (3rd value): without one, all unnamed throttles share ONE counter
// per IP (or per user), so public course pages would use up the 5-a-minute sign-up allowance.
Route::prefix('v1')->middleware('throttle:120,1,public-api')->group(function () {

    Route::get('home', [HomeController::class, 'index']);
    Route::get('popups', [PopupController::class, 'index']);

    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('courses', [CourseController::class, 'index']);
    Route::get('courses/{course}', [CourseController::class, 'show']);
    Route::get('combos', [ComboController::class, 'index']);
    Route::get('combos/{combo}', [ComboController::class, 'show']); // slug

    // Affiliate ref links: track a visit, and let the frontend check who a visitor is attributed to.
    Route::post('ref/{ref_code}/click', [AffiliateClickController::class, 'click']);
    Route::get('ref/attribution', [AffiliateClickController::class, 'attribution']);

    // Where a student can send money - shown on the pay page.
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

// Checkout + orders: students only. A separate {type} group (rather than reusing the one above)
// so the student|instructor|affiliate route above never has to special-case who can buy courses.
Route::prefix('v1/{type}')->where(['type' => 'student'])
    ->middleware(['auth:sanctum', EnsureUserType::class, TrackDeviceActivity::class])
    ->group(function () {
        // per student, each with its own counter (named throttles)
        Route::post('checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:30,1,checkout-quote');
        Route::post('checkout/order', [CheckoutController::class, 'store'])->middleware('throttle:10,1,checkout-order');
        Route::get('orders', [OrderController::class, 'index']);
        // by order number (CSA-2611-0001), never by the database id
        Route::get('orders/{orderNo}', [OrderController::class, 'show'])->where('orderNo', '[A-Za-z0-9-]{1,30}');
        Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1,payment-submit');
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('enrollments', [EnrollmentController::class, 'index']); // "My courses"
        Route::post('enrollments/{enrollmentId}/renew', [EnrollmentController::class, 'renew'])
            ->where('enrollmentId', '[0-9]+')->middleware('throttle:10,1,enrollment-renew');
        // live classes: the list for the Join button, and the tracked join (records attendance)
        Route::get('classes', [ClassController::class, 'index']);
        Route::post('classes/{classId}/join', [ClassController::class, 'join'])
            ->where('classId', '[0-9]+')->middleware('throttle:20,1,class-join');
        Route::get('classes/{classId}/recording', [ClassController::class, 'recording'])->where('classId', '[0-9]+');
        Route::post('classes/{classId}/recording/complete', [ClassController::class, 'completeRecording'])
            ->where('classId', '[0-9]+')->middleware('throttle:30,1,recording-complete');
        // one curriculum item: play a recorded video, mark it complete, open a resource
        Route::get('items/{itemId}/play', [LearningController::class, 'play'])->where('itemId', '[0-9]+')->middleware('throttle:60,1,item-play');
        Route::post('items/{itemId}/complete', [LearningController::class, 'complete'])->where('itemId', '[0-9]+')->middleware('throttle:30,1,item-complete');
        Route::get('items/{itemId}/resource', [LearningController::class, 'resource'])->where('itemId', '[0-9]+')->middleware('throttle:60,1,item-resource');
        // assignments and exams: details, and handing in one file (private storage)
        Route::get('assignments/{assignmentId}', [SubmissionController::class, 'assignment'])->where('assignmentId', '[0-9]+');
        Route::post('assignments/{assignmentId}/submission', [SubmissionController::class, 'submitAssignment'])
            ->where('assignmentId', '[0-9]+')->middleware('throttle:10,1,assignment-submit');
        Route::get('exams/{examId}', [SubmissionController::class, 'exam'])->where('examId', '[0-9]+');
        Route::post('exams/{examId}/answer', [SubmissionController::class, 'submitExam'])
            ->where('examId', '[0-9]+')->middleware('throttle:10,1,exam-submit');
    });
