<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\SsoToken;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SsoService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    public function platformsFor(User $user): array
    {
        $platforms = config('sso.platforms', []);

        return collect($platforms)
            ->map(function (array $platform, string $key) use ($user): array {
                $enabled = (bool) ($platform['enabled'] ?? false);

                // Resolve logo: kalau path relatif (e.g. /images/logo.png) ubah ke absolute URL backend.
                $logoUrl = $platform['logo_url'] ?? null;
                if ($logoUrl && !str_starts_with($logoUrl, 'http')) {
                    $logoUrl = rtrim(config('app.url', 'https://app-erp.ibereality.com'), '/') . '/' . ltrim($logoUrl, '/');
                }

                $allowed = $this->isPlatformAllowed($user, $key);

                // Untuk Email IBE - kalau belum allowed, cek apakah ada invite
                // aktif yang sudah dikirim. Frontend pakai info ini buat tampilkan
                // pesan yang spesifik (cek email vs hubungi HR).
                $claimStatus = null;
                if ($key === 'webmail') {
                    if ($allowed) {
                        $claimStatus = 'claimed';
                    } else {
                        $pendingInvite = \Illuminate\Support\Facades\DB::table('email_migration_invites')
                            ->where('user_id', $user->id)
                            ->whereNull('claimed_at')
                            ->where('expires_at', '>', now())
                            ->orderByDesc('id')
                            ->first();
                        $claimStatus = $pendingInvite ? 'invite_sent' : 'no_invite';
                    }
                }

                return [
                    'key' => $key,
                    'name' => $platform['name'],
                    'description' => $platform['description'],
                    'enabled' => $enabled,
                    'allowed' => $allowed,
                    'logo_url' => $logoUrl,
                    'status_label' => $enabled
                        ? ($allowed ? 'Aktif' : 'Akses Terbatas')
                        : 'Segera Hadir',
                    'claim_status' => $claimStatus,  // null kecuali utk webmail
                ];
            })
            ->values()
            ->all();
    }

    public function generate(User $user, string $targetApp, Request $request): array
    {
        $platform = $this->platformConfig($targetApp);

        if (!($platform['enabled'] ?? false)) {
            throw new AuthorizationException('Platform belum aktif.');
        }

        if (!$this->isPlatformAllowed($user, $targetApp)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke platform ini.');
        }

        $plainToken = Str::random(80);
        $expiresAt = now()->addMinutes((int) config('sso.ttl_minutes', 5));

        DB::transaction(function () use ($user, $targetApp, $request, $plainToken, $expiresAt): void {
            SsoToken::query()
                ->where('user_id', $user->id)
                ->where('target_app', $targetApp)
                ->whereNull('used_at')
                ->delete();

            SsoToken::query()->create([
                'user_id' => $user->id,
                'target_app' => $targetApp,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => $expiresAt,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);
        });

        $redirectUrl = rtrim($platform['base_url'], '/') . ($platform['callback_path'] ?? '/sso/callback');
        // SPA callbacks receive the one-time credential in the URL fragment.
        // Fragments are never sent to the origin/proxy and therefore stay out
        // of HTTP access logs and Referer headers. Server-rendered ERP keeps
        // query transport for backward compatibility because PHP cannot read
        // a fragment.
        $separator = ($platform['token_transport'] ?? 'query') === 'fragment' ? '#' : '?';
        $redirectUrl .= $separator . http_build_query([
            'sso_token' => $plainToken,
            'target_app' => $targetApp,
        ]);

        $this->auditLogs->log(
            action: 'auth.sso.generated',
            actor: $user,
            entityType: 'sso_token',
            entityId: null,
            meta: [
                'target_app' => $targetApp,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            request: $request,
        );

        $result = [
            'target_app' => $targetApp,
            'sso_token' => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'redirect_url' => $redirectUrl,
        ];

        // The current Portal submits server-rendered callbacks as a form POST,
        // keeping the credential out of URL/history/access logs. redirect_url
        // remains during the rolling-upgrade window for older Portal builds.
        if (($platform['token_transport'] ?? 'query') === 'query'
            && ($platform['form_post'] ?? true)) {
            $result['post_url'] = rtrim($platform['base_url'], '/') . ($platform['callback_path'] ?? '/sso/callback');
            $result['post_fields'] = [
                'sso_token' => $plainToken,
                'target_app' => $targetApp,
            ];
        }

        return $result;
    }

    public function exchange(string $plainToken, string $targetApp, Request $request): array
    {
        $this->platformConfig($targetApp);

        $token = SsoToken::query()
            ->with('user.company', 'user.division', 'user.jobTitle')
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('target_app', $targetApp)
            ->first();

        if (!$token || !$token->isUsable()) {
            // Audit failed exchange - signal possible brute force / replay attack
            // Hash partial token untuk korelasi tanpa expose secret di log
            $this->auditLogs->log(
                action: 'auth.sso.failed',
                actor: null,
                entityType: 'sso_token',
                entityId: null,
                meta: [
                    'target_app' => $targetApp,
                    'reason'     => !$token ? 'token_not_found' : 'token_unusable',
                    'token_prefix' => substr(hash('sha256', $plainToken), 0, 12),
                ],
                request: $request,
            );
            throw new NotFoundHttpException('SSO token tidak valid atau sudah kedaluwarsa.');
        }

        $user = $token->user;

        if (!$user || !$this->isPlatformAllowed($user, $targetApp)) {
            // Audit unauthorized platform access attempt
            $this->auditLogs->log(
                action: 'auth.sso.unauthorized',
                actor: $user,
                entityType: 'sso_token',
                entityId: (string) $token->id,
                meta: [
                    'target_app' => $targetApp,
                    'reason'     => !$user ? 'user_missing' : 'platform_not_allowed',
                ],
                request: $request,
            );
            throw new AuthorizationException('Akses platform tidak valid.');
        }

        // Block user yang sudah resign/terminated dari exchange
        if ($user->is_inactive) {
            $this->auditLogs->log(
                action: 'auth.sso.inactive_user',
                actor: $user,
                entityType: 'sso_token',
                entityId: (string) $token->id,
                meta: [
                    'target_app'        => $targetApp,
                    'employment_status' => $user->employment_status,
                ],
                request: $request,
            );
            throw new AuthorizationException($user->inactive_reason);
        }

        $token->forceFill([
            'used_at' => now(),
        ])->save();

        $abilities = $user->isAdmin ? ['admin', 'employee'] : ['employee'];
        $accessToken = $user->createToken("sso:{$targetApp}", $abilities)->plainTextToken;

        $this->auditLogs->log(
            action: 'auth.sso.exchanged',
            actor: $user,
            entityType: 'sso_token',
            entityId: (string) $token->id,
            meta: [
                'target_app' => $targetApp,
            ],
            request: $request,
        );

        $platform = $this->platformConfig($targetApp);

        $payload = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'platform' => [
                'key' => $targetApp,
                'name' => $platform['name'],
            ],
        ];

        // Webmail special: piggy-back mailbox credentials di response yang sama
        // supaya webmail SPA tidak perlu hop ke endpoint lain. Standar SSO flow
        // sudah arahkan user ke webmail.ibereality.com/?sso_token=X&target_app=webmail
        // - webmail bootstrap call /sso/exchange dapat semua data sekaligus.
        if (config('webmail_gateway.allow_legacy_credential_exchange')
            && $targetApp === 'webmail' && $user->mailbox_password_encrypted) {
            try {
                $mailboxPassword = \Illuminate\Support\Facades\Crypt::decryptString($user->mailbox_password_encrypted);
                $displayName = strtok($user->email, '@');
                $accounts = [[
                    'baseUrl'     => '',
                    'username'    => $user->email,
                    'password'    => $mailboxPassword,
                    'displayName' => $displayName,
                ]];

                // Append shared mailboxes yang accessible
                try {
                    $shared = \App\Models\SharedMailbox::query()->where('is_active', true)->get();
                    foreach ($shared as $m) {
                        if (!$m->isAccessibleBy($user)) continue;
                        try {
                            $accounts[] = [
                                'baseUrl'     => '',
                                'username'    => $m->email,
                                'password'    => $m->decryptPassword(),
                                'displayName' => $m->display_name,
                            ];
                        } catch (\Throwable) {}
                    }
                } catch (\Throwable) {}

                $payload['webmail'] = [
                    'accounts'       => $accounts,
                    'activeUsername' => $user->email,
                    'from_portal'    => true,
                ];
            } catch (\Throwable $e) {
                // Mailbox creds decrypt failed - log + skip; user akan dapat error
                // di bootstrap dan jatuh ke fallback (manual mailbox login)
                \Log::warning('SSO exchange: mailbox creds decrypt failed for ' . $user->email . ': ' . $e->getMessage());
            }
        }

        return $payload;
    }

    public function verify(string $plainToken, string $targetApp): array
    {
        $this->platformConfig($targetApp);

        $token = SsoToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('target_app', $targetApp)
            ->first();

        return [
            'valid' => (bool) $token?->isUsable(),
            'expires_at' => $token?->expires_at?->toIso8601String(),
            'used_at' => $token?->used_at?->toIso8601String(),
        ];
    }

    private function platformConfig(string $targetApp): array
    {
        $platform = config("sso.platforms.{$targetApp}");

        if (!is_array($platform)) {
            throw new NotFoundHttpException('Platform SSO tidak ditemukan.');
        }

        return $platform;
    }

    private function isPlatformAllowed(User $user, string $targetApp): bool
    {
        return match ($targetApp) {
            'erp' => $user->isAdmin,
            'attendance' => true,
            'projects' => true,
            // Dokumen - sistem surat menyurat. Semua user authenticated boleh
            // buka untuk lihat surat / draft. Workflow (review, approve, sign)
            // di-gate per-state oleh role, bukan di sini.
            'dokumen' => true,
            // Cyber Command memuat telemetry dan kontrol keamanan sensitif.
            // Batasi di kedua sisi: backend hanya menerbitkan token untuk
            // superadmin, monitor tetap menerapkan allowlist user sendiri.
            'monitor' => $user->isSuperadmin,
            // CRM: superadmin + user/admin yang punya capability crm.view
            'crm' => $user->isSuperadmin
                   || (method_exists($user, 'hasCapability') && $user->hasCapability('crm.view')),
            // Webmail: superadmin selalu boleh (akses semua platform). Karyawan
            // lain harus sudah klaim @ibereality.com + punya mailbox.
            'webmail' => $user->isSuperadmin
                       || ($user->email
                           && str_ends_with($user->email, '@ibereality.com')
                           && $user->mailbox_password_encrypted),
            default => false,
        };
    }
}
