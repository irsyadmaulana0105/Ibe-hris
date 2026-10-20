<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('app_locale');
        $availableLocales = array_keys(config('app.available_locales', []));

        if ($locale && in_array($locale, $availableLocales, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
