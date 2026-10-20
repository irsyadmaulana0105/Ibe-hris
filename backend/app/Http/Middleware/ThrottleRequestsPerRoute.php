<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;
use RuntimeException;

/**
 * Pengganti alias `throttle`: penghitung dipisah PER RUTE.
 *
 * Bawaan Laravel memakai satu kunci per IP (tamu) atau per pengguna untuk
 * SEMUA rute `throttle:X,Y`. Akibatnya (19 Okt 2026): semua karyawan di kantor
 * keluar lewat satu IP publik, panggilan latar belakang (refresh token, SSO
 * verify, webmail yang terus memanggil API dengan sesi kedaluwarsa) menghabiskan
 * jatah bersama, lalu login dari kantor ditolak "Too Many Attempts." walau
 * orangnya baru mencoba sekali.
 *
 * Batas per rute tetap sama; yang berubah hanya tiap rute punya penghitungnya
 * sendiri. Limiter bernama (mis. `throttle:api-login`) tidak terpengaruh -
 * kuncinya diatur oleh limiter itu sendiri.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route ? $request->method().'|'.$route->uri() : $request->method().'|'.$request->path();

        if ($user = $request->user()) {
            return sha1($user->getAuthIdentifier().'|'.$routeKey);
        }

        if ($route) {
            return sha1($route->getDomain().'|'.$request->ip().'|'.$routeKey);
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }
}
