<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Keep endpoint-specific stricter policies (notably the legacy SSO
        // callback, whose request URL can contain a one-time credential).
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        // Permissions-Policy: izinkan camera/geo dari subdomain sendiri saja
        // (dipakai face approval + attendance geofence). Origin lain harus block.
        $allowedOrigins = '"https://attendance.ibereality.com" "https://app-erp.ibereality.com" "https://portal.ibereality.com" "https://app-project.ibereality.com" "https://dokumen.ibereality.com"';
        $response->headers->set(
            'Permissions-Policy',
            "geolocation=(self {$allowedOrigins}), camera=(self {$allowedOrigins}), microphone=(), payment=(), usb=()"
        );
        $enforceHttps = app()->environment(['production', 'staging']);
        if ($enforceHttps) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        // Mencegah Adobe Flash / PDF cross-domain access via clientaccesspolicy.xml.
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->set('Origin-Agent-Cluster', '?1');
        // Cross-Origin Opener Policy - isolasi window.opener dari halaman lain
        // di tab yang sama. Cegah tabnabbing & cross-origin window manipulation.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // CSP - strict server-wide setelah Livewire endpoint dilepas.
        // - default-src 'self' - semua resource hanya dari own origin
        // - img-src self + data + blob untuk inline SVG/base64 + canvas-toDataURL
        // - style-src self + inline (Tailwind di SPA pakai inline style; PDF
        //   blade juga generate inline style). Untuk paranoid mode masa depan
        //   ganti ke nonce-based
        // - font-src self + data (icon font yang inlined)
        // - connect-src self + subdomain IBE + Stalwart webmail untuk fetch
        // - object/embed/base-uri/form-action di-restrict
        // - frame-ancestors 'self' identik dengan X-Frame-Options SAMEORIGIN
        // Tidak set CSP untuk request `OPTIONS` (preflight) - tidak relevan
        if ($request->method() !== 'OPTIONS') {
            $csp = "default-src 'self'; "
                 ."script-src 'self' 'unsafe-inline'; "
                 ."style-src 'self' 'unsafe-inline'; "
                 ."img-src 'self' data: blob: https://*.ibereality.com; "
                 ."font-src 'self' data:; "
                 ."connect-src 'self' https://*.ibereality.com; "
                 // object-src blob: - izinkan <object>/<embed> untuk PDF preview
                 // inline (bikin dari URL.createObjectURL). Aman karena blob URL
                 // origin-restricted ke tab yang buatnya.
                 ."object-src 'self' blob:; "
                 // frame-src blob: - kalau nanti pakai <iframe> untuk preview
                 // PDF (fallback), blob: URL boleh loaded.
                 ."frame-src 'self' blob:; "
                 ."base-uri 'self'; "
                 ."form-action 'self' https://portal.ibereality.com; "
                 ."frame-ancestors 'self'"
                 .($enforceHttps ? '; upgrade-insecure-requests' : '');
            // A file-serving endpoint may intentionally provide a stricter
            // sandbox policy. Never replace that response-specific policy.
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', $csp);
            }
        }

        return $response;
    }
}
