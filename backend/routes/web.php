<?php

use App\Helpers;
use App\Http\Controllers\Api\V1\WorkCertificateController;
use App\Http\Controllers\SsoCallbackController;
use App\Models\Quotation;
use App\Services\QuotationService;
use App\Surat\Http\Controllers\LetterVerifyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// use Livewire\Livewire; // soft-removed - endpoint /livewire/* tidak didaftarkan

require __DIR__.'/private_media.php';

// ── Public Quote View (no auth, token-based) ─────────────────────────────────
// Customer akses via link di email untuk preview + accept/reject penawaran online.
Route::get('/quote/{token}', function (string $token) {
    $q = Quotation::with(['items', 'preparedBy:id,name'])
        ->where('public_token', $token)
        ->first();
    if (! $q) {
        abort(404);
    }
    $sections = app(QuotationService::class)->groupedSections($q);

    return view('public.quote', [
        'q' => $q,
        'sections' => $sections,
        'token' => $token,
        'company' => [
            'brand' => 'IBE Reality',
            'name' => 'PT INTEGRASI BISNIS EKSEKUTIF',
            'address' => 'Surabaya, Jawa Timur',
            'email' => 'info@ibereality.com',
            'website' => 'ibereality.com',
        ],
    ]);
})->name('public-quote');

// ── React SPA catch-all ───────────────────────────────────────────────────────
// Serves public/app/index.html for every /app/* request so that the React
// Router BrowserRouter handles all client-side navigation (login, dashboard,
// set-password, etc.) without a hard 404 on page refresh.
// Must be PUBLIC - no auth middleware - so /app/login and /app/set-password
// are accessible before the user has a token.
Route::get('/app/{any?}', function () {
    $path = public_path('app/index.html');
    abort_if(! file_exists($path), 503, 'Frontend not deployed. Run: npm run build');

    return response(file_get_contents($path))->header('Content-Type', 'text/html');
})->where('any', '.*')->name('spa');

// ── ERP Next shell ───────────────────────────────────────────────────────────
// Production build: erp-next/apps/workspace → public/erp-next/. The existing
// /admin SPA remains available as an operational compatibility layer until all
// 105 feature routes are native in ERP Next. Both run on this origin, so SSO
// bearer storage and API/CORS policy stay unified during the safe cutover.
Route::get('/erp-next/{any?}', function () {
    $path = public_path('erp-next/index.html');
    abort_if(! file_exists($path), 503, 'ERP Next not built. cd erp-next && npm run build');

    return response(file_get_contents($path))->header('Content-Type', 'text/html');
})->where('any', '.*')->name('erp-next');

// Canonical transactional surface of ERP Next. /admin remains the rollback
// artifact until production telemetry confirms the new route is stable.
Route::get('/erp-operations', function () {
    $path = public_path('erp-next-operations/index.html');
    abort_if(! file_exists($path), 503, 'ERP Next Operations not built. cd apps/erp-admin && npm run build');

    return response(file_get_contents($path))->header('Content-Type', 'text/html');
})->name('erp-next-operations');
Route::get('/erp-operations/{any}', function () {
    $path = public_path('erp-next-operations/index.html');
    abort_if(! file_exists($path), 503, 'ERP Next Operations not built. cd apps/erp-admin && npm run build');

    return response(file_get_contents($path))->header('Content-Type', 'text/html');
})->where('any', '.*')->name('erp-next-operations.path');

// ── ERP Admin (React 19 + Refine + Ant Design) - catch-all ───────────────────
// Source di apps/erp-admin/ (Vite build → backend/public/admin/).
// Catch-all serve index.html supaya SPA router handle semua client-side path
// tanpa hard 404. Legacy Livewire admin sudah dipindah ke /admin-legacy/*.
Route::get('/admin/{any?}', function () {
    $path = public_path('admin/index.html');
    abort_if(! file_exists($path), 503, 'ERP admin not built. cd apps/erp-admin && npm run build');

    return response(file_get_contents($path))->header('Content-Type', 'text/html');
})->where('any', '.*')->name('erp-admin');

Route::get('/', function () {
    return redirect('/app');
});

// Login/register/forgot-password di ERP backend di-redirect ke PORTAL SSO supaya
// karyawan masuk lewat satu pintu. Target HARUS portal.ibereality.com (BUKAN
// app-erp.ibereality.com itu sendiri - kalau pakai diri sendiri akan loop infinite).
// target_app=erp + next=/launch/erp memberi tahu portal untuk auto-launch balik
// ke ERP setelah login berhasil.
$portalLoginUrl = rtrim((string) env('SSO_PORTAL_URL', 'https://portal.ibereality.com'), '/').'/login?target_app=erp&next=/launch/erp';
Route::redirect('/login', $portalLoginUrl);
Route::redirect('/register', $portalLoginUrl);
Route::redirect('/forgot-password', $portalLoginUrl);

// SSO callback dari portal - claim ticket → set Laravel web session → /admin.
Route::get('/sso/callback', [SsoCallbackController::class, 'handle'])->name('sso.callback');
Route::post('/sso/callback', [SsoCallbackController::class, 'handle'])->name('sso.callback.post');

// Public verify surat keterangan kerja - dari QR code / URL di footer PDF
Route::get('/verify/skk/{hash}', [WorkCertificateController::class, 'verify'])
    ->name('skk.verify');

Route::get('/lang/{locale}', function (string $locale) {
    $availableLocales = array_keys(config('app.available_locales', []));
    if (! in_array($locale, $availableLocales, true)) {
        abort(404);
    }

    session(['app_locale' => $locale]);
    app()->setLocale($locale);

    return redirect()->back();
})->name('lang.switch');

Route::middleware([
    'auth:sanctum',
    'block.inactive',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::post('/debug/client-log', function (Request $request) {
        Log::warning('client-debug', [
            'type' => $request->input('type'),
            'details' => $request->input('details'),
            'url' => $request->input('url'),
            'user_agent' => $request->input('user_agent'),
            'ip' => $request->ip(),
        ]);

        return response()->noContent();
    })->middleware('throttle:20,1')->name('debug.client-log');

    Route::get('/', fn () => Auth::user()->isAdmin ? redirect('/admin') : redirect(Helpers::getFrontendUrl()));

    // USER AREA - semua halaman karyawan sudah migrate ke React absen SPA
    // (attendance.ibereality.com). /home redirect ke FE supaya bookmark lama
    // tetap kebawa ke SPA. Halaman Blade/Livewire karyawan sudah dihapus.
    Route::middleware('user')->group(function () {
        Route::get('/home', fn () => redirect(Helpers::getFrontendUrl()))->name('home');
    });
});

// ── Jetstream user/profile shadow redirect ──────────────────────────────
// Sebelumnya: GET /user/profile → Jetstream profile page (Livewire-rendered).
// Sekarang: kalau user direct-access, lempar ke portal launcher. SPA punya
// halaman profile sendiri (di attendance.ibereality.com). Tidak hapus
// Jetstream feature di config supaya middleware infrastructure tetap utuh.
Route::middleware('auth')->get('/user/profile', function () {
    return redirect(rtrim(env('SSO_PORTAL_URL', 'https://portal.ibereality.com'), '/').'/platforms');
});

// ── Livewire neutralized ─────────────────────────────────────────────────
// Composer dep tetap ada (Jetstream/Fortify masih trampoline auth flow lewat
// FortifyServiceProvider yang sekarang redirect ke portal SSO). Tapi endpoint
// HTTP /livewire/update + /livewire/livewire.js TIDAK didaftarkan lagi -
// surface area attack berkurang + memungkinkan CSP strict server-wide.
// Kalau Blade page direct-access yang masih punya Livewire markup, akan
// 404 di POST endpoint (graceful - fallback ke server-side render saja).
//
// Sebelum reaktivasi, audit:
//   1. Semua Blade view di resources/views/auth tidak di-akses langsung
//      (Fortify::loginView/registerView/dll redirect ke portal SSO)
//   2. /user/api-tokens redirect ke portal launcher (lihat di bawah)
//   3. Tidak ada custom Livewire component yang bergantung di /livewire/update

// Public surat verification - di-akses via QR code di footer PDF, no auth.
// - Regex `[a-f0-9-]{32,36}` filter di route-level: bukan UUID → 404 langsung
//   tanpa query DB (defense vs probe campaign).
// - throttle 30/menit per IP: attacker tidak bisa enumerate qr_token cepat,
//   walaupun secara entropi UUID v4 (122 bit) sudah practically infeasible.
//   Rate limit melindungi resource server dari noise scanning.
Route::get('/surat-verify/{token}', [LetterVerifyController::class, 'verify'])
    ->where('token', '[a-f0-9-]{32,36}')
    ->middleware('throttle:30,1')
    ->name('surat.verify');
