<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\Affiliate;
use App\Models\Instructor;
use App\Models\PersonalAccessToken;
use App\Models\Student;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // API tokens: last_used_at saved at most once a minute (see the model)
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Stored in the database as: admin | student | instructor | affiliate
        // (user_devices.user_type, user_login_infos.user_type, personal_access_tokens.tokenable_type)
        Relation::enforceMorphMap([
            'admin' => Admin::class,
            'student' => Student::class,
            'instructor' => Instructor::class,
            'affiliate' => Affiliate::class,
        ]);

        // Step 1: password attempts
        RateLimiter::for('login', fn (Request $request) =>
            Limit::perMinute(5)->by($request->ip().'|'.$request->input('login'))
        );

        // Steps 2 and 3: send code / verify code
        RateLimiter::for('otp', fn (Request $request) =>
            Limit::perMinute(10)->by($request->ip().'|'.$request->input('challenge_id'))
        );

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(20)->by('reset-ip:'.$request->ip()),
            Limit::perMinute(5)->by('reset-step:'.$request->ip().'|'.hash('sha256',
                json_encode([$request->route('type'), $request->input('login'), $request->input('challenge_id')]))),
        ]);

    }
}
