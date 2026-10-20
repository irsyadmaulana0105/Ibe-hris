<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\FaceEngineClient;
use App\Services\InviteTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function __construct(
        private readonly FaceEngineClient $faceEngine,
    ) {}

    /**
     * POST /api/v1/auth/login
     * Issue a Sanctum personal access token.
     * Accepts email, NIP, or phone number as identifier.
     * reCAPTCHA is intentionally skipped for API clients - enforce at network/rate-limit level.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $identifier = $request->identifier;

        // Support email (primary OR legacy/Gmail lama), NIP, atau phone login.
        // Karyawan yang sudah klaim @ibereality.com tetap bisa login pakai
        // email Gmail original mereka (disimpan di legacy_email).
        $user = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $identifier)
                ->orWhere('legacy_email', $identifier)
                ->first()
            : User::where('phone', $identifier)
                ->orWhere('nip', $identifier)
                ->orWhere('employee_number', $identifier)
                ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => [__('auth.failed')],
            ]);
        }

        // Block user yang sudah off-boarded - meski credentials benar, akses
        // ditolak. Pesan generic supaya tidak leak alasan ke attacker.
        if ($user->is_inactive) {
            throw ValidationException::withMessages([
                'identifier' => [$user->inactive_reason],
            ]);
        }

        // Kata sandi benar, tapi kalau 2FA aktif token BELUM boleh terbit.
        // Klien harus menyelesaikan /auth/two-factor-challenge dulu.
        // Saat fitur ini dipasang tidak ada satu pun akun yang mengaktifkan 2FA,
        // jadi cabang ini tidak pernah tereksekusi sampai ada yang mendaftar —
        // sengaja, supaya penggelarannya tidak bisa mengunci siapa pun.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->success(TwoFactorController::issueChallenge($user), 'Masukkan kode verifikasi dua langkah.');
        }

        $deviceName = $request->device_name ?? 'api-client';
        $abilities = $user->isAdmin ? ['admin', 'employee'] : ['employee'];
        $token = $user->createToken($deviceName, $abilities)->plainTextToken;

        // Refresh cookie - HttpOnly, scope .ibereality.com, 8 jam. Memungkinkan
        // SPA auto-refresh access token (1 jam) tanpa user login ulang, sambil
        // refresh-nya inaccessible dari JS (XSS-immune).
        $refreshCookie = app(\App\Services\RefreshTokenService::class)
            ->issue($user, $request);

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ])->withCookie($refreshCookie);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revoke sesi DEVICE INI saja - sanctum access token current + refresh
     * token yang match cookie request ini. Sesi user di device lain (laptop,
     * HP lain) TETAP valid.
     *
     * Untuk logout SEMUA device → pakai /logout-all (kasih warning ke user
     * karena ngaruh ke perangkat lain).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $svc = app(\App\Services\RefreshTokenService::class);
        $svc->revokeByCurrentCookie($request);

        return $this->success(message: __('Logged out successfully.'))
            ->withCookie($svc->forgetCookie());
    }

    /**
     * POST /api/v1/auth/logout-all
     * Revoke every Sanctum token issued to the current user across every
     * platform (portal, absen, projects). Used by the "Logout dari semua
     * platform" button in client apps to fully end the SSO session.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        $svc = app(\App\Services\RefreshTokenService::class);
        $svc->revokeAllForUser($user->id);

        return $this->success(
            ['revoked_count' => $count],
            __('All sessions logged out.'),
        )->withCookie($svc->forgetCookie());
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Public - cuma butuh refresh cookie (HttpOnly). Validate, rotate, issue
     * new access token + new refresh cookie. Replay token sudah used → revoke
     * chain.
     */
    public function refresh(Request $request): JsonResponse
    {
        $svc = app(\App\Services\RefreshTokenService::class);

        try {
            $result = $svc->rotate($request);
        } catch (\RuntimeException $e) {
            // Pesan generik supaya attacker tidak bisa probe alasan kegagalan.
            // Log internal sudah cukup detail di RefreshTokenService.
            return response()->json([
                'message' => 'Refresh token invalid atau kadaluwarsa.',
            ], 401)->withCookie($svc->forgetCookie());
        }

        /** @var \App\Models\User $user */
        $user = $result['user'];
        $abilities = $user->isAdmin ? ['admin', 'employee'] : ['employee'];
        $accessToken = $user->createToken('refresh:'.$request->ip(), $abilities)->plainTextToken;

        return $this->success([
            'token' => $accessToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ])->withCookie($result['cookie']);
    }

    /**
     * POST /api/v1/auth/webmail-ticket
     * Exchange Sanctum Bearer for a 60-second one-time SSO ticket. Frontend
     * then redirects user to /auth/webmail-redirect?ticket=xxx (no headers
     * required), backend redeems and auto-logs into webmail.
     */
    public function webmailTicket(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->email || ! $user->mailbox_password_encrypted) {
            return response()->json(['message' => 'Mailbox belum di-provision. Hubungi HR.'], 422);
        }

        $ticket = bin2hex(random_bytes(32));
        \DB::table('webmail_sso_tickets')->insert([
            // Only a digest is persisted. A database leak cannot replay a live ticket.
            'ticket' => hash('sha256', $ticket),
            'user_id' => $user->id,
            'expires_at' => now()->addSeconds(60),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $webmailUrl = rtrim(config('services.stalwart.webmail_url', 'https://webmail.ibereality.com'), '/');

        return $this->success([
            // Portal sends this field using a top-level POST form. The ticket is
            // never placed in URL/history/referrer and no mailbox password enters JS.
            'post_url' => "{$webmailUrl}/sso/exchange",
            'ticket' => $ticket,
            'expires_in' => 60,
        ]);
    }

    /**
     * POST /api/v1/auth/exchange-webmail-ticket
     *
     * Public endpoint - ticket IS the auth (one-time use, 60s expiry).
     * Webmail SPA panggil setelah baca ?wt=... dari URL query param.
     * Return credentials JSON yang webmail simpan di localStorage.
     *
     * Replaces HTML-based webmail-redirect flow karena hash navigation tidak
     * reliable di Android PWA (hash sering di-strip saat cross-domain redirect).
     */
    public function exchangeWebmailTicket(Request $request): JsonResponse
    {
        if (! config('webmail_gateway.allow_legacy_credential_exchange')) {
            return response()->json([
                'message' => 'Legacy browser credential exchange dinonaktifkan.',
            ], 410);
        }
        $data = $request->validate([
            'ticket' => ['required', 'string', 'min:20', 'max:100'],
        ]);

        $row = \DB::table('webmail_sso_tickets')
            ->where('ticket', $data['ticket'])
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Ticket invalid atau kadaluwarsa.'], 410);
        }

        \DB::table('webmail_sso_tickets')->where('id', $row->id)->update(['used_at' => now()]);

        $user = \App\Models\User::query()->withoutGlobalScopes()->find($row->user_id);
        if (! $user || ! $user->mailbox_password_encrypted) {
            return response()->json(['message' => 'Mailbox tidak tersedia.'], 422);
        }

        try {
            $password = \Crypt::decryptString($user->mailbox_password_encrypted);
        } catch (\Throwable) {
            return response()->json(['message' => 'Mailbox credential rusak.'], 500);
        }

        app(\App\Services\AuditLogService::class)->log(
            'webmail.sso_exchange',
            $user, 'mailbox', $user->id,
            ['email' => $user->email],
            $request,
        );

        $displayName = strtok($user->email, '@');
        $accounts = [[
            'baseUrl' => '',
            'username' => $user->email,
            'password' => $password,
            'displayName' => $displayName,
        ]];

        // Append shared mailboxes yang accessible
        try {
            $shared = \App\Models\SharedMailbox::query()->where('is_active', true)->get();
            foreach ($shared as $m) {
                if (! $m->isAccessibleBy($user)) {
                    continue;
                }
                try {
                    $accounts[] = [
                        'baseUrl' => '',
                        'username' => $m->email,
                        'password' => $m->decryptPassword(),
                        'displayName' => $m->display_name,
                    ];
                } catch (\Throwable) {
                    // skip
                }
            }
        } catch (\Throwable) {
            // shared_mailboxes table maybe not migrated - skip
        }

        // Portal bearer untuk webmail-bridge calls (push subscribe, contacts refresh)
        $portalToken = null;
        try {
            $portalToken = $user->createToken('webmail-bridge', ['portal:webmail-bridge'])->plainTextToken;
        } catch (\Throwable) {
        }

        $contacts = $this->buildContactDirectory($user);

        return response()->json([
            'data' => [
                'accounts' => $accounts,
                'activeUsername' => $user->email,
                'from_portal' => true,
                'portal_token' => $portalToken,
                'contacts' => $contacts,
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/webmail-redirect?ticket=xxx (public - single-use)
     * Redeems ticket, returns HTML that primes the Stalwart webmail's
     * localStorage auth state (key `ibe_mail_auth_v2`) with the user's
     * email + decrypted IMAP password, then redirects to the webmail.
     * The webmail mounts, reads localStorage, calls its JMAP session-init
     * with Basic auth → user is logged in transparently.
     */
    public function webmailRedirect(Request $request)
    {
        if (! config('webmail_gateway.allow_legacy_credential_exchange')) {
            return response('Legacy webmail redirect dinonaktifkan.', 410)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('Cache-Control', 'no-store');
        }
        $ticket = $request->query('ticket');
        if (! $ticket) {
            return response('Missing ticket', 400);
        }

        $row = \DB::table('webmail_sso_tickets')
            ->where('ticket', $ticket)->whereNull('used_at')
            ->where('expires_at', '>', now())->first();
        if (! $row) {
            return response('Ticket invalid atau expired. Silakan klik ulang Email Saya.', 410)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        \DB::table('webmail_sso_tickets')->where('id', $row->id)->update(['used_at' => now()]);

        $user = \App\Models\User::query()->withoutGlobalScopes()->find($row->user_id);
        if (! $user || ! $user->mailbox_password_encrypted) {
            return response('User mailbox tidak tersedia.', 422);
        }

        try {
            $password = \Crypt::decryptString($user->mailbox_password_encrypted);
        } catch (\Throwable $e) {
            return response('Mailbox credential rusak.', 500);
        }

        $webmailUrl = rtrim(config('services.stalwart.webmail_url', 'https://webmail.ibereality.com'), '/');

        app(\App\Services\AuditLogService::class)->log(
            'webmail.sso_redirect',
            $user, 'mailbox', $user->id,
            ['email' => $user->email],
            $request,
        );

        $displayName = strtok($user->email, '@');

        // Mulai dengan akun user sendiri (active).
        $accounts = [[
            'baseUrl' => '',
            'username' => $user->email,
            'password' => $password,
            'displayName' => $displayName,
        ]];

        // Append shared mailboxes yang accessible - supaya langsung muncul di
        // account picker webmail (gak perlu UI switch terpisah). Authority
        // ditentukan dari shared_mailboxes.allowed_groups vs user.group.
        try {
            $shared = \App\Models\SharedMailbox::query()->where('is_active', true)->get();
            foreach ($shared as $m) {
                if (! $m->isAccessibleBy($user)) {
                    continue;
                }
                try {
                    $accounts[] = [
                        'baseUrl' => '',
                        'username' => $m->email,
                        'password' => $m->decryptPassword(),
                        'displayName' => $m->display_name,
                    ];
                } catch (\Throwable $e) {
                    // skip mailbox yang corrupt decrypt-nya; sisanya tetap di-include
                }
            }
        } catch (\Throwable $e) {
            // Tabel mungkin belum migrated di env baru → abaikan.
        }

        $contacts = $this->buildContactDirectory($user);

        // Short-lived bearer untuk webmail bridge calls (fetch directory ulang
        // saat user navigasi langsung tanpa via portal). Abilities terbatas
        // - cuma akses endpoint contacts + shared mailboxes.
        $portalToken = null;
        try {
            $portalToken = $user->createToken('webmail-bridge', ['portal:webmail-bridge'])->plainTextToken;
        } catch (\Throwable $e) {
            // Gagal mint → fitur autocomplete-via-fetch off, sisanya jalan.
        }

        $authState = json_encode([
            'accounts' => $accounts,
            'activeUsername' => $user->email,
            // Marker supaya webmail bootstrap tahu user masuk lewat portal - webmail
            // akan inject tombol "Kembali ke Portal" di pojok kanan atas. Kalau
            // user login langsung (URL/bookmark), flag tidak ada → tombol tidak muncul.
            'from_portal' => true,
            // Direktori untuk autocomplete recipient - di-cache di localStorage
            // sisi webmail (`ibe_mail_contacts`).
            'contacts' => $contacts,
            // Portal bearer - webmail pakai untuk fetch ulang contacts kalau cache
            // expired/empty (mis. user buka webmail langsung dari bookmark).
            'portal_token' => $portalToken,
        ], JSON_UNESCAPED_SLASHES);

        $authStateJs = json_encode($authState);
        $webmailUrlJs = json_encode($webmailUrl.'/');
        $emailHtml = e($user->email);
        $nameHtml = e($user->name);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8">
<title>Membuka email Anda...</title>
<style>
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
         background:linear-gradient(135deg,#1a7a8c,#0d5a6a); color:#fff;
         display:flex; min-height:100vh; align-items:center; justify-content:center; padding:24px; }
  .card { background:rgba(255,255,255,.1); backdrop-filter:blur(20px); padding:36px 32px;
          border-radius:20px; text-align:center; max-width:420px; }
  .spinner { width:46px; height:46px; margin:0 auto 18px;
             border:4px solid rgba(255,255,255,.2); border-top-color:#fff;
             border-radius:50%; animation:spin .9s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }
  h1 { margin:0 0 6px; font-size:18px; font-weight:700; }
  p { margin:0; font-size:13px; opacity:.85; }
  .email { margin-top:8px; font-family:monospace; opacity:.6; font-size:11px; }
</style></head>
<body>
  <div class="card">
    <div class="spinner"></div>
    <h1>Membuka email Anda</h1>
    <p>Login otomatis sebagai <strong>{$nameHtml}</strong></p>
    <div class="email">{$emailHtml}</div>
  </div>
  <script>
    (function () {
      var target = {$webmailUrlJs};
      try {
        // Use a same-origin frame trick: navigate the top window after writing
        // the auth state to the webmail origin. Since this page is served from
        // the API origin (app-erp.ibereality.com), we can't write to webmail's
        // localStorage directly. Instead, we open the webmail with a #sso=...
        // hash and the webmail SPA's bootstrap reads it (see webmail bootstrap).
        // For now, append the auth state as a fragment - webmail JS handles it.
        var encoded = encodeURIComponent({$authStateJs});
        window.location.replace(target + '#sso=' + encoded);
      } catch (e) {
        window.location.replace(target);
      }
    })();
  </script>
</body></html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * GET /api/v1/auth/mailbox-credentials
     * Returns the current user's mailbox email + decrypted password (legacy
     * fallback when SSO redirect fails). Used by admin debugging only.
     */
    public function mailboxCredentials(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->email || ! $user->mailbox_password_encrypted) {
            return response()->json([
                'message' => 'Mailbox belum di-provision. Hubungi HR untuk aktivasi.',
            ], 422);
        }

        try {
            $password = \Crypt::decryptString($user->mailbox_password_encrypted);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Mailbox credential rusak. Hubungi HR.'], 500);
        }

        app(\App\Services\AuditLogService::class)->log(
            'webmail.credentials_viewed',
            $user, 'mailbox', $user->id,
            ['email' => $user->email],
            $request,
        );

        return $this->success([
            'email' => $user->email,
            'password' => $password,
            'webmail_url' => config('services.stalwart.webmail_url', 'https://webmail.ibereality.com'),
            'note' => 'Browser akan minta password sekali saat login pertama. Setelah itu klik "Save password" agar otomatis login berikutnya.',
        ]);
    }

    /**
     * GET /api/v1/auth/webmail-session
     * Returns an auto-submitting HTML form that logs the user into Stalwart
     * webmail (https://webmail.ibereality.com) without re-prompting password.
     * The mailbox password is stored encrypted (Crypt::encryptString) per user
     * and decrypted only at request time. Token is verified via Sanctum.
     *
     * The webmail uses standard form-based auth, so we render a self-submit
     * HTML page; the user's browser POSTs the credentials transparently.
     */
    public function webmailSession(Request $request)
    {
        $user = $request->user();
        if (! $user->email || ! $user->mailbox_password_encrypted) {
            return response()->json([
                'message' => 'Mailbox belum di-provision untuk akun Anda. Hubungi HR untuk aktivasi.',
            ], 422);
        }

        try {
            $password = \Crypt::decryptString($user->mailbox_password_encrypted);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Mailbox credential rusak / tidak bisa di-decrypt. Hubungi HR untuk reset.',
            ], 500);
        }

        $webmailUrl = config('services.stalwart.webmail_url', 'https://webmail.ibereality.com');
        $email = e($user->email);
        $pass = e($password);
        $name = e($user->name);

        // Audit the SSO login attempt
        app(\App\Services\AuditLogService::class)->log(
            'webmail.sso_initiated',
            $user,
            'mailbox',
            $user->id,
            ['email' => $user->email],
            $request,
        );

        $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Membuka Webmail...</title>
  <style>
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
           background:linear-gradient(135deg,#1a7a8c,#0d5a6a); color:#fff;
           display:flex; min-height:100vh; align-items:center; justify-content:center; }
    .card { background:rgba(255,255,255,.1); backdrop-filter:blur(20px); padding:40px;
            border-radius:20px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.3); }
    .spinner { width:42px; height:42px; margin:0 auto 16px;
               border:4px solid rgba(255,255,255,.2); border-top-color:#fff;
               border-radius:50%; animation:spin 0.9s linear infinite; }
    @keyframes spin { to { transform:rotate(360deg); } }
    h1 { margin:0 0 8px; font-size:18px; font-weight:700; }
    p  { margin:0; font-size:13px; opacity:.85; }
  </style>
</head>
<body>
  <div class="card">
    <div class="spinner"></div>
    <h1>Membuka webmail untuk {$name}</h1>
    <p>Login otomatis sebagai {$email}…</p>
  </div>
  <form id="sso" method="POST" action="{$webmailUrl}/jmap/auth/login" style="display:none">
    <input name="email" value="{$email}">
    <input name="password" value="{$pass}">
    <input name="remember" value="true">
  </form>
  <script>
    // Try webmail JMAP login. Fall back to opening webmail and copy creds for paste.
    document.getElementById('sso').submit();
    setTimeout(function(){
      // If login form didn't accept and we're still here after 3s, redirect to plain webmail
      window.location.href = '{$webmailUrl}';
    }, 3000);
  </script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * GET /api/v1/auth/me
     * Return authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()->load('division', 'jobTitle', 'company')));
    }

    /**
     * PUT /api/v1/auth/me
     * Self-update fields karyawan boleh edit. Field sensitif (nip, division_id,
     * job_title_id, employment_status, salary_monthly) hanya bisa di-edit admin.
     */
    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'in:male,female'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'regency' => ['sometimes', 'nullable', 'string', 'max:100'],
            'district' => ['sometimes', 'nullable', 'string', 'max:100'],
            'village' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'marital_status' => ['sometimes', 'nullable', 'in:single,married,widowed,divorced'],
            // NIK KTP - karyawan bisa isi sendiri (16 digit). Dipakai untuk
            // placeholder kontrak kerja dan kartu identitas formal.
            'nip' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{16,20}$/'],
            'ktp_number' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{16}$/'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $user->update($data);

        return $this->success(new UserResource($user->fresh()->load('division', 'jobTitle', 'company')), 'Profil diperbarui.');
    }

    /**
     * POST /api/v1/auth/me/photo
     * Upload foto profil. Disimpan di disk public, lalu (kalau rembg tersedia)
     * background dihapus otomatis supaya cocok dipakai di kartu identitas.
     * Karyawan boleh kirim `keep_original=1` kalau tidak ingin background dibuang.
     */
    public function updatePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'keep_original' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $rawPath = $request->file('photo')->store('profile-photos', 'public');

        // Auto-remove background (rembg/U2Net). Output selalu PNG dengan alpha.
        $finalPath = $rawPath;
        if (! $request->boolean('keep_original')) {
            $processed = $this->stripBackgroundIfAvailable($rawPath);
            if ($processed) {
                $finalPath = $processed;
            }
        }

        $user->update(['profile_photo_path' => $finalPath]);

        return $this->success(new UserResource($user->fresh()->load('division', 'jobTitle', 'company')), 'Foto profil diperbarui.');
    }

    /**
     * Jalankan tool `rembg-bg` (Python u2net) untuk hapus background.
     * Mengembalikan path PNG hasil di disk public, atau null kalau tool
     * tidak tersedia atau gagal (fallback ke foto asli).
     */
    private function stripBackgroundIfAvailable(string $rawPath): ?string
    {
        $binary = config('services.rembg.path', '/usr/local/bin/rembg-bg');
        if (! is_executable($binary)) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $absInput = $disk->path($rawPath);
        $outputName = 'profile-photos/'.pathinfo($rawPath, PATHINFO_FILENAME).'-nobg.png';
        $absOutput = $disk->path($outputName);

        $process = new \Symfony\Component\Process\Process([$binary, $absInput, $absOutput]);
        $process->setTimeout(45);
        try {
            $process->run();
            if ($process->isSuccessful() && file_exists($absOutput)) {
                return $outputName;
            }
            \Illuminate\Support\Facades\Log::warning('rembg failed: '.$process->getErrorOutput());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('rembg exception: '.$e->getMessage());
        }

        return null;
    }

    /**
     * POST /api/v1/auth/me/password
     * Ganti password - disinkronkan ke portal absen (users.password) DAN
     * mailbox Stalwart (kalau user punya). Pakai current_password untuk
     * verifikasi supaya tidak bisa diganti orang lain dengan token saja.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'max:64', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini salah.'],
            ]);
        }

        $newPassword = $request->input('new_password');
        $user->password = Hash::make($newPassword);

        // Sinkron ke Stalwart kalau user punya mailbox (@ibereality.com)
        $mailboxSynced = false;
        if ($user->email && str_ends_with($user->email, '@ibereality.com')) {
            $stalwart = app(\App\Services\StalwartMailClient::class);
            $localPart = strtok($user->email, '@');
            $accountId = $stalwart->findAccountByName($localPart);
            if ($accountId !== null) {
                $mailboxSynced = $stalwart->updatePassword($accountId, $newPassword);
                if ($mailboxSynced) {
                    $user->mailbox_password_encrypted = \Crypt::encryptString($newPassword);
                }
            }
        }

        $user->save();

        // Audit
        app(\App\Services\AuditLogService::class)->log(
            'auth.password_changed',
            $user, 'user', $user->id,
            ['mailbox_synced' => $mailboxSynced],
            $request,
        );

        return $this->success([
            'mailbox_synced' => $mailboxSynced,
        ], $mailboxSynced
            ? 'Password berhasil diganti. Portal & webmail sudah disinkron.'
            : 'Password portal berhasil diganti.');
    }

    /**
     * GET /api/v1/auth/me/avatar-data
     * Returns avatar sebagai data URL (base64). Dipakai komponen export PNG
     * (html-to-image) untuk hindari masalah CORS pada gambar lintas-origin.
     */
    public function avatarData(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->profile_photo_path) {
            return $this->success(['data_url' => null]);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (! $disk->exists($user->profile_photo_path)) {
            return $this->success(['data_url' => null]);
        }

        $bytes = $disk->get($user->profile_photo_path);
        $ext = strtolower(pathinfo($user->profile_photo_path, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';

        return $this->success([
            'data_url' => 'data:'.$mime.';base64,'.base64_encode($bytes),
        ]);
    }

    /**
     * POST /api/v1/auth/accept-invite  (public)
     * Accept a one-time invite token and set the employee's own password.
     * Returns a Sanctum token so the employee is immediately logged in.
     */
    public function acceptInvite(Request $request, InviteTokenService $inviteTokens): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string'],
        ]);

        $employee = $inviteTokens->accept($request->token, $request->password);

        // Defensive: kalau karyawan sudah di-resign sebelum sempat accept invite
        // (mis. invite token belum dipakai berbulan-bulan, lalu di-resign), block.
        if ($employee->is_inactive) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'token' => [$employee->inactive_reason],
            ]);
        }

        $token = $employee->createToken('api-client', ['employee'])->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($employee->load('division', 'jobTitle', 'company')),
        ], 'Password berhasil diset. Selamat datang!');
    }

    /**
     * POST /api/v1/auth/face-setup
     * Save or clear the user's face descriptor based on the captured selfie image.
     */
    public function faceSetup(Request $request): JsonResponse
    {
        $request->validate([
            'selfie_base64' => ['nullable', 'string', 'max:2000000'],
        ]);

        $user = $request->user();

        if ($request->selfie_base64 === null) {
            \App\Support\FotoWajah::hapus($user, $user->face_photo_pending);
            \App\Support\FotoWajah::hapus($user, $user->face_photo);
            $user->forceFill([
                'face_descriptor' => null,
                'face_descriptor_pending' => null,
                'face_photo_pending' => null,
                'face_photo' => null,
                'face_quality_pending' => null,
            ])->save();

            return $this->success(message: 'Data wajah berhasil dihapus.');
        }

        $hasil = $this->faceEngine->extract($request->selfie_base64);

        // Foto yang didaftarkan disimpan (privat, URL bertanda tangan) supaya HR
        // menilai WAJAH YANG SEBENARNYA didaftarkan, bukan foto profil.
        $lama = $user->face_photo_pending;
        $foto = \App\Support\FotoWajah::simpan($user, $request->selfie_base64);

        $user->forceFill([
            'face_descriptor_pending' => $hasil['descriptor'],
            'face_photo_pending' => $foto,
            'face_quality_pending' => $hasil['quality'] + ['face_count' => $hasil['face_count']],
        ])->save();
        \App\Support\FotoWajah::hapus($user, $lama);

        return $this->success(message: 'Pendaftaran wajah menunggu persetujuan admin.');
    }

    // ──────────────────────────────────────────────────────────────────
    // Webmail contact directory - autocomplete recipient di compose drawer
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build daftar IBE Reality contacts: semua user aktif `@ibereality.com`
     * + shared mailboxes yang accessible. Dipakai untuk push-via-SSO state
     * dan untuk endpoint fetch (refresh).
     */
    private function buildContactDirectory(User $user): array
    {
        $contacts = [];
        try {
            $rows = User::withoutGlobalScopes()
                ->where('email', 'like', '%@ibereality.com')
                ->whereNotNull('email')
                ->where('id', '!=', $user->id)
                ->orderBy('name')
                ->get(['email', 'name']);
            foreach ($rows as $r) {
                if (! $r->email) {
                    continue;
                }
                $contacts[] = ['email' => $r->email, 'name' => $r->name ?: strtok($r->email, '@')];
            }
            try {
                foreach (\App\Models\SharedMailbox::query()->where('is_active', true)->get() as $m) {
                    if (! $m->isAccessibleBy($user)) {
                        continue;
                    }
                    $contacts[] = ['email' => $m->email, 'name' => $m->display_name];
                }
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
        }

        return $contacts;
    }

    /** GET /api/v1/auth/webmail-contacts - refresh autocomplete cache dari webmail */
    public function webmailContactsIndex(Request $request): JsonResponse
    {
        return $this->success($this->buildContactDirectory($request->user()));
    }

    /**
     * POST /api/v1/auth/webmail-directory - fetch contacts pakai mailbox creds.
     * Dipakai webmail kalau user masuk langsung tanpa via portal (gak punya
     * Sanctum bearer). Auth = email + mailbox password yang sudah dicocokkan
     * vs users.mailbox_password_encrypted. Validasi pakai constant-time
     * comparison untuk tahan timing-attack.
     */
    public function webmailDirectoryByMailbox(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withoutGlobalScopes()
            ->where('email', $data['username'])
            ->first();

        if (! $user || ! $user->mailbox_password_encrypted) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        try {
            $expected = \Crypt::decryptString($user->mailbox_password_encrypted);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Credential check failed'], 500);
        }

        if (! hash_equals($expected, $data['password'])) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return $this->success($this->buildContactDirectory($user));
    }

    // ──────────────────────────────────────────────────────────────────
    // Shared mailboxes - superadmin one-click switch ke akun email bersama
    // ──────────────────────────────────────────────────────────────────

    /** GET /api/v1/auth/shared-mailboxes - list akun bersama yg accessible utk caller */
    public function sharedMailboxesIndex(Request $request, \App\Services\SharedMailboxService $svc): JsonResponse
    {
        return $this->success($svc->listForUser($request->user()));
    }

    /** POST /api/v1/auth/shared-mailbox-ticket - generate one-time ticket utk switch */
    public function sharedMailboxTicket(Request $request, \App\Services\SharedMailboxService $svc): JsonResponse
    {
        $data = $request->validate([
            'shared_mailbox_id' => ['required', 'integer'],
        ]);
        try {
            $payload = $svc->generateTicket($request->user(), (int) $data['shared_mailbox_id']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return $this->success($payload);
    }

    /** GET /api/v1/auth/shared-mailbox-redirect?ticket=xxx - redeem + auto-login */
    public function sharedMailboxRedirect(Request $request, \App\Services\SharedMailboxService $svc)
    {
        $ticket = $request->query('ticket');
        if (! $ticket) {
            return response('Missing ticket', 400);
        }

        $result = $svc->redeemTicket($ticket);
        if (! $result) {
            return response('Ticket invalid atau expired.', 410)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $mailbox = $result['mailbox'];
        $password = $result['password'];
        $caller = $result['caller'];

        app(\App\Services\AuditLogService::class)->log(
            'webmail.shared_switched',
            $caller, 'shared_mailbox', (string) $mailbox->id,
            ['target_email' => $mailbox->email],
            $request,
        );

        $webmailUrl = rtrim(config('services.stalwart.webmail_url', 'https://webmail.ibereality.com'), '/');

        // Continuity token - supaya setelah switch user masih bisa ganti shared
        // mailbox lain atau back ke akun pribadi tanpa logout dulu. Token ini
        // dibikin atas nama CALLER (bukan shared mailbox - shared mailbox bukan
        // Laravel User), supaya hak akses tetap caller's.
        $portalToken = null;
        if ($caller) {
            try {
                $portalToken = $caller->createToken('webmail-bridge', ['portal:webmail-bridge'])->plainTextToken;
            } catch (\Throwable $e) {
            }
        }

        $authState = json_encode([
            'accounts' => [[
                'baseUrl' => '',
                'username' => $mailbox->email,
                'password' => $password,
                'displayName' => $mailbox->display_name,
            ]],
            'activeUsername' => $mailbox->email,
            'from_portal' => true,
            'portal_token' => $portalToken,
            // Marker tambahan: webmail bisa render badge "Shared inbox" supaya
            // user paham ini bukan akun pribadi mereka.
            'shared_mailbox' => true,
        ], JSON_UNESCAPED_SLASHES);

        $authStateJs = json_encode($authState);
        $webmailUrlJs = json_encode($webmailUrl.'/');
        $emailHtml = e($mailbox->email);
        $nameHtml = e($mailbox->display_name);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8">
<title>Membuka shared inbox...</title>
<style>
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
         background:linear-gradient(135deg,#7c3aed,#4c1d95); color:#fff;
         display:flex; min-height:100vh; align-items:center; justify-content:center; padding:24px; }
  .card { background:rgba(255,255,255,.1); backdrop-filter:blur(20px); padding:36px 32px;
          border-radius:20px; text-align:center; max-width:420px; }
  .spinner { width:46px; height:46px; margin:0 auto 18px;
             border:4px solid rgba(255,255,255,.2); border-top-color:#fff;
             border-radius:50%; animation:spin .9s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }
  h1 { margin:0 0 6px; font-size:18px; font-weight:700; }
  p { margin:0; font-size:13px; opacity:.85; }
  .email { margin-top:8px; font-family:monospace; opacity:.6; font-size:11px; }
  .badge { display:inline-block; padding:3px 10px; background:rgba(255,255,255,.18);
           border-radius:99px; font-size:10px; letter-spacing:.1em; text-transform:uppercase; margin-bottom:8px; }
</style></head>
<body>
  <div class="card">
    <div class="badge">Shared Inbox</div>
    <div class="spinner"></div>
    <h1>Membuka {$nameHtml}</h1>
    <p>Login otomatis ke mailbox bersama</p>
    <div class="email">{$emailHtml}</div>
  </div>
  <script>
    (function () {
      var target = {$webmailUrlJs};
      try {
        var encoded = encodeURIComponent({$authStateJs});
        window.location.replace(target + '#sso=' + encoded);
      } catch (e) {
        window.location.replace(target);
      }
    })();
  </script>
</body></html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
