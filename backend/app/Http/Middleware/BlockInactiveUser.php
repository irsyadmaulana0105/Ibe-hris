<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tolak semua request dari user yang sudah resign/terminated.
 *
 * Layered defense - sekalipun token bocor atau dipakai dari device lain
 * setelah user di-resign, request tetap 401/403. Pasangan dengan token
 * revocation di ResignationService yang menghapus semua Sanctum token +
 * revoke refresh chain saat user di-mark resigned.
 *
 * Dipasang eksplisit sebagai route middleware SETELAH auth:sanctum supaya
 * $request->user() sudah di-resolve. Endpoint public (login, refresh,
 * sso/exchange) tidak memakai middleware ini.
 */
class BlockInactiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->is_inactive) {
            // Revoke current token defensively - sekalipun ada race condition
            // antara mark resigned dan token usage.
            if (method_exists($user, 'currentAccessToken')) {
                try { $user->currentAccessToken()?->delete(); } catch (\Throwable) {}
            }
            return response()->json([
                'message' => $user->inactive_reason,
                'inactive' => true,
            ], 403);
        }
        return $next($request);
    }
}
