<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            $user = Auth::guard('web')->user();
            $timeoutMinutes = (int) config('session.lifetime');
            if ($user?->isSuperadmin) {
                $timeoutMinutes = (int) config('session.timeout_superadmin', 240);
            } elseif ($user?->group === 'admin') {
                $timeoutMinutes = (int) config('session.timeout_admin', 100);
            } elseif ($user?->group === 'user') {
                $timeoutMinutes = (int) config('session.timeout_user', 5);
            }
            $timeoutSeconds = $timeoutMinutes * 60;
            $lastActivity = (int) ($request->session()->get('last_activity') ?? 0);
            $now = time();

            if ($lastActivity && ($now - $lastActivity) > $timeoutSeconds) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('message', __('Session Has Expired'));
            }

            $request->session()->put('last_activity', $now);
        }

        return $next($request);
    }
}
