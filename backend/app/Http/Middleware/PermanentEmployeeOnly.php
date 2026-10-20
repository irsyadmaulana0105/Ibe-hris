<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermanentEmployeeOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admin selalu boleh akses
        if ($user?->isAdmin) {
            return $next($request);
        }

        if (!$user?->isPermanent) {
            abort(403, 'Fitur ini hanya tersedia untuk karyawan tetap.');
        }

        return $next($request);
    }
}
