<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter([
        'https://app-erp.ibereality.com',
        'https://attendance.ibereality.com',
        'https://absensi.ibereality.com',
        'https://absen.ibereality.com',
        'https://portal.ibereality.com',
        'https://app-project.ibereality.com',  // out-of-scope monorepo — different project
        'https://crm.ibereality.com',
        'https://webmail.ibereality.com',
        'https://mail.ibereality.com',
        'https://dokumen.ibereality.com',
        // Isolated VPS canary hosts. These never replace production DNS and
        // remain explicit so arbitrary tenant subdomains are not trusted.
        'https://staging-erp.ibereality.com',
        'https://staging-portal.ibereality.com',
        'https://staging-attendance.ibereality.com',
        'https://staging-projects.ibereality.com',
        'https://staging-crm.ibereality.com',
        'https://staging-documents.ibereality.com',
        'https://staging-webmail.ibereality.com',
        env('FRONTEND_URL', ''),
        ...array_map('trim', explode(',', (string) env('STAGING_ALLOWED_ORIGINS', ''))),
    ]))),

    // Allow every local Vite port during development. Production origins stay
    // explicitly allow-listed above; this pattern never matches public hosts.
    'allowed_origins_patterns' => [
        '#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#',
    ],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 86400,

    // Cross-domain cookies butuh `supports_credentials=true` + browser send
    // `credentials: 'include'` di SPA. Ditambah Set-Cookie SameSite=None;Secure
    // dari RefreshTokenService → refresh cookie ikut di request cross-eTLD.
    // Catatan Safari ITP: kalau ITP active dan user belum ada interaction
    // dengan API domain, cookie mungkin di-strip. Fallback: SPA tetap dapat 401
    // → soft-logout modal jalan normal.
    'supports_credentials' => true,

];
