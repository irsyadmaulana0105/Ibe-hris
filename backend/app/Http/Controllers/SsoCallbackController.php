<?php

namespace App\Http\Controllers;

use App\Models\SsoToken;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Web callback untuk SSO portal. Portal redirect ke /sso/callback?sso_token=&target_app=erp
 * setelah karyawan klaim SSO ticket. Sini:
 *
 *   1. Validate ticket (SsoToken model, one-time use)
 *   2. Issue Sanctum personal access token
 *   3. Auth::login() supaya Laravel session juga ke-set (untuk Livewire/Blade)
 *   4. Return HTML page yang inject token ke localStorage('ibe_hris_token')
 *      lalu redirect ke ERP Next workspace
 *
 * Kenapa HTML bridge: hris-admin (React 19 + Refine) pakai localStorage token
 * via Bearer header, BUKAN Laravel session. Kalau langsung redirect ke /admin
 * tanpa set localStorage → SPA check() detect kosong → redirect ke portal lagi
 * → portal auto-launch balik → infinite loop.
 */
class SsoCallbackController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function handle(Request $request): RedirectResponse|Response
    {
        $ssoToken = $request->input('sso_token');
        $targetApp = $request->input('target_app');

        if (! $ssoToken || $targetApp !== 'erp') {
            return redirect('/login')->with('error', 'SSO callback tidak valid.');
        }

        $token = SsoToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', (string) $ssoToken))
            ->where('target_app', $targetApp)
            ->first();

        if (! $token || ! $token->isUsable() || ! $token->user) {
            return redirect('/login')->with('error', 'SSO token tidak valid atau sudah expired.');
        }

        $user = $token->user;
        if (! $user->isAdmin) {
            // Bukan admin - arahkan ke absen frontend (HRIS admin SPA hanya untuk admin)
            return redirect()->away(rtrim((string) env('FRONTEND_URL', 'https://attendance.ibereality.com'), '/'));
        }

        $token->forceFill(['used_at' => now()])->save();

        // Sanctum token untuk SPA
        $abilities = ['admin', 'employee'];
        $accessToken = $user->createToken('sso:erp:web', $abilities)->plainTextToken;

        // Laravel session untuk Livewire/Blade (fallback path)
        Auth::login($user, remember: false);
        $request->session()->regenerate();

        $this->audit->log(
            action: 'auth.sso.web_session_started',
            actor: $user,
            entityType: 'sso_token',
            entityId: (string) $token->id,
            meta: ['target_app' => $targetApp],
            request: $request,
        );

        // HTML bridge: set localStorage lalu redirect. JS minimal supaya
        // page load cepat - escape token via json_encode untuk safety.
        $tokenJson = json_encode($accessToken);
        $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Login berhasil - memuat ERP Next…</title>
<style>
  body{margin:0;display:grid;place-items:center;min-height:100vh;font-family:system-ui,sans-serif;background:linear-gradient(160deg,#0a363d,#1a7a8c);color:#fff}
  .box{text-align:center}.spinner{width:36px;height:36px;border-radius:50%;border:3px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .8s linear infinite;margin:0 auto 16px}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="box">
  <div class="spinner"></div>
  <div>Memuat ERP Next…</div>
</div>
<script>
(function(){
  // Clear stale auth artifacts dari session lama (mis. login ganti user role) sebelum
  // set token baru. Cegah UI cached dari role sebelumnya bocor atau bikin routing mismatch.
  try {
    // localStorage: wipe SEMUA kunci auth-related. Whitelist ONLY key non-auth (theme, lang).
    var keep = ['theme', 'lang', 'i18nextLng'];
    var toRemove = [];
    for (var i = 0; i < localStorage.length; i++) {
      var k = localStorage.key(i);
      if (k && keep.indexOf(k) === -1) toRemove.push(k);
    }
    toRemove.forEach(function(k){ try { localStorage.removeItem(k); } catch(e) {} });

    // sessionStorage: wipe seluruh, tidak ada yang penting di-persist antar login
    try { sessionStorage.clear(); } catch(e) {}

    // Cookies same-origin: expire semua non-essential (Laravel session tetap OK karena backend regenerate)
    document.cookie.split(';').forEach(function(c){
      var eqPos = c.indexOf('=');
      var name = (eqPos > -1 ? c.substr(0, eqPos) : c).trim();
      if (!name || name === 'XSRF-TOKEN' || name === 'laravel_session') return;
      document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
      document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=.ibereality.com';
    });
  } catch (e) {}

  // Set the same bearer token for both ERP Next Workspace and the operational
  // compatibility surface. They share one backend/issuer on this origin.
  try {
    localStorage.setItem('ibe-erp-next:token', {$tokenJson});
    localStorage.setItem('ibe_hris_token', {$tokenJson});
  } catch (e) {}
  window.location.replace('/erp-next/');
})();
</script>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
