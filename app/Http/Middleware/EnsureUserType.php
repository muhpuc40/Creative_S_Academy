<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** A student token cannot open /instructor/... routes, and so on. */
class EnsureUserType
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless(
            $user && $user->isActive() && $user->userType() === $request->route('type'),
            403,
            'Wrong account type.'
        );
        return $next($request);
    }
}
