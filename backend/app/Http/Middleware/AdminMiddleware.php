<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Legacy groups remain effective in shadow mode. In enterprise enforce
        // mode, a time-bound assignment carrying admin.access can also enter
        // the admin surface; individual controllers still require domain caps.
        if (Auth::check() && Auth::user()?->hasCapability('admin.access')) {
            return $next($request);
        }

        // If the user is not an admin, return a 403 Forbidden response
        abort(403);
    }
}
