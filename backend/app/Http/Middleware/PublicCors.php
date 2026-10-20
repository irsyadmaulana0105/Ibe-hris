<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS terbuka untuk endpoint publik (lead capture forms). Endpoint ini
 * harus reachable dari domain landing page apapun. Aman karena:
 *   - Tidak ada session/Authorization header (Sanctum)
 *   - Rate-limited per IP (throttle:30,1 di route)
 *   - Honeypot + rate-limit di service
 *   - Hanya POST submit + GET schema, gak ada CRUD
 */
class PublicCors
{
    public function handle(Request $request, Closure $next): Response
    {
        // Handle preflight OPTIONS request langsung
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $this->headers());
        }

        $response = $next($request);
        foreach ($this->headers() as $key => $value) {
            $response->headers->set($key, $value);
        }
        return $response;
    }

    private function headers(): array
    {
        return [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, X-Requested-With',
            'Access-Control-Max-Age'       => '86400',
        ];
    }
}
