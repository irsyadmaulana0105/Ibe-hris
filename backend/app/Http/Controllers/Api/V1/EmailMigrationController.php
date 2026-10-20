<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\StalwartMailClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Str;

/**
 * Self-service email migration:
 *   - Admin triggers a broadcast: every karyawan (group=user) with a non-ibereality
 *     email gets a fresh single-use claim token, sent via email to their current
 *     address.
 *   - Karyawan clicks the link → public claim page → picks a username → backend
 *     provisions {username}@ibereality.com Stalwart mailbox and updates User.email.
 */
class EmailMigrationController extends ApiController
{
    public function __construct(private readonly StalwartMailClient $stalwart) {}

    /**
     * POST /api/v1/admin/email-broadcasts
     * Admin: create invites + send emails. Returns a per-user status summary.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Karyawan management access required.');

        $payload = $request->validate([
            'user_ids'  => ['nullable', 'array'],
            'user_ids.*' => ['string'],
            'resend'    => ['nullable', 'boolean'],
            'force'     => ['nullable', 'boolean'],  // resend bahkan untuk user yang sudah migrasi
        ]);

        $query = User::query()->withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('group', 'user');
        if (!empty($payload['user_ids'])) {
            $query->whereIn('id', $payload['user_ids']);
        }
        $candidates = $query->get();
        $force = !empty($payload['force']);

        $rows = [];
        foreach ($candidates as $user) {
            // Skip jika sudah migrated kecuali force=true
            $alreadyMigrated = $user->email && str_ends_with($user->email, '@ibereality.com');
            if ($alreadyMigrated && !$force) {
                $rows[] = ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => 'already_migrated'];
                continue;
            }
            if (!$user->email) {
                $rows[] = ['user_id' => $user->id, 'name' => $user->name, 'email' => null, 'status' => 'no_email'];
                continue;
            }

            // Expire invite lama supaya tidak ada token aktif paralel
            DB::table('email_migration_invites')
                ->where('user_id', $user->id)
                ->whereNull('claimed_at')
                ->update(['expires_at' => now()->subSecond(), 'updated_at' => now()]);

            $token = bin2hex(random_bytes(24));
            DB::table('email_migration_invites')->insert([
                'user_id'       => $user->id,
                'token'         => $token,
                'sent_to_email' => $user->email,
                'sent_at'       => now(),
                'expires_at'    => now()->addDays(7),
                'admin_id'      => $admin->id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $claimUrl = rtrim(config('app.frontend_url', 'https://attendance.ibereality.com'), '/')
                . '/claim-email?token=' . $token;

            try {
                Mail::raw($this->emailBody($user->name, $claimUrl), function ($m) use ($user) {
                    $m->to($user->email, $user->name)
                      ->subject('Aktivasi Email IBE Reality Anda - pilih username sendiri');
                });
                $rows[] = ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => 'sent'];
            } catch (\Throwable $e) {
                Log::warning("Email migration broadcast failed for {$user->email}: " . $e->getMessage());
                $rows[] = ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => 'send_failed', 'error' => $e->getMessage()];
            }
        }

        return $this->success([
            'sent_count' => collect($rows)->where('status', 'sent')->count(),
            'rows'       => $rows,
        ], 'Broadcast selesai.');
    }

    /**
     * POST /api/v1/admin/email-broadcasts/{userId}/resend
     * Generate link aktivasi BARU untuk satu karyawan (invalidate yg lama).
     * Bisa dipakai juga buat user yang sudah migrasi (force=true).
     */
    public function resendOne(Request $request, string $userId): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Karyawan management access required.');

        $request->merge(['user_ids' => [$userId], 'force' => $request->boolean('force', true)]);
        return $this->broadcast($request);
    }

    /**
     * POST /api/v1/admin/email-broadcasts/{userId}/rollback
     * Rollback migrasi: hapus mailbox Stalwart + kembalikan email ke nilai
     * yang admin tentukan (biasanya email Gmail lama). Karyawan jadi bisa
     * klaim ulang dengan username berbeda.
     */
    public function rollback(Request $request, string $userId): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Karyawan management access required.');

        $payload = $request->validate([
            'new_email' => ['required', 'email'],          // email lama yang mau dikembalikan (e.g. Gmail)
            'delete_mailbox' => ['nullable', 'boolean'],   // default: true
        ]);

        $user = User::query()->withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('id', $userId)
            ->firstOrFail();

        $oldEmail = $user->email;
        $deletedMailbox = false;

        // Hapus mailbox Stalwart kalau diminta + user punya @ibereality.com email
        if (($payload['delete_mailbox'] ?? true) && $oldEmail && str_ends_with($oldEmail, '@ibereality.com')) {
            $deletedMailbox = $this->stalwart->deprovision($oldEmail);
        }

        $user->update([
            'email'                      => $payload['new_email'],
            'mailbox_password_encrypted' => null,
        ]);

        // Hapus invite yang masih pending supaya bersih
        DB::table('email_migration_invites')
            ->where('user_id', $user->id)
            ->whereNull('claimed_at')
            ->delete();

        app(\App\Services\AuditLogService::class)->log(
            'email_migration.rollback',
            $admin, 'user', $user->id,
            [
                'old_email'       => $oldEmail,
                'restored_email'  => $payload['new_email'],
                'deleted_mailbox' => $deletedMailbox,
            ],
            $request,
        );

        return $this->success([
            'old_email'       => $oldEmail,
            'restored_email'  => $payload['new_email'],
            'deleted_mailbox' => $deletedMailbox,
        ], 'Migrasi di-rollback. Karyawan sekarang bisa diundang ulang.');
    }

    /**
     * GET /api/v1/admin/email-broadcasts
     * Status karyawan: berapa sudah claim, pending, belum dikirim.
     */
    public function status(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Karyawan management access required.');

        $rows = User::query()->withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('group', 'user')
            ->orderBy('name')
            ->get()
            ->map(function (User $u) {
                $invite = DB::table('email_migration_invites')
                    ->where('user_id', $u->id)
                    ->orderByDesc('id')
                    ->first();
                $hasIbeEmail = $u->email && str_ends_with($u->email, '@ibereality.com');
                $status = $hasIbeEmail
                    ? 'migrated'
                    : ($invite
                        ? ($invite->claimed_at
                            ? 'claimed_no_email'
                            : ($invite->expires_at < now() ? 'expired' : 'sent_pending'))
                        : 'not_sent');
                return [
                    'user_id'      => $u->id,
                    'name'         => $u->name,
                    'current_email'=> $u->email,
                    'new_email'    => $invite->new_email ?? null,
                    'sent_at'      => $invite->sent_at ?? null,
                    'claimed_at'   => $invite->claimed_at ?? null,
                    'expires_at'   => $invite->expires_at ?? null,
                    'status'       => $status,
                ];
            });

        return $this->success([
            'rows'    => $rows->values(),
            'summary' => [
                'total'     => $rows->count(),
                'migrated'  => $rows->where('status', 'migrated')->count(),
                'pending'   => $rows->where('status', 'sent_pending')->count(),
                'expired'   => $rows->where('status', 'expired')->count(),
                'not_sent'  => $rows->where('status', 'not_sent')->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/claim-email/{token}/check?username=xxx
     * Public: cek apakah username masih bisa dipakai (tidak diambil user lain
     * di tabel users atau mailbox Stalwart).
     */
    public function checkUsername(Request $request, string $token): JsonResponse
    {
        if (!$this->findActiveInvite($token)) {
            return response()->json(['available' => false, 'message' => 'Link tidak valid.'], 410);
        }
        $username = strtolower(trim((string) $request->query('username', '')));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,30}$/', $username)) {
            return response()->json(['available' => false, 'message' => 'Format username tidak valid.']);
        }

        $domain = config('services.stalwart.domain', 'ibereality.com');
        $email = "{$username}@{$domain}";

        $takenInUsers = User::query()->withoutGlobalScopes()->where('email', $email)->exists();
        if ($takenInUsers) {
            return response()->json(['available' => false, 'message' => 'Username sudah dipakai karyawan lain.']);
        }

        if ($this->stalwart->findAccountByName($username) !== null) {
            return response()->json(['available' => false, 'message' => 'Username sudah terdaftar di mail server.']);
        }

        return response()->json(['available' => true, 'message' => 'Username tersedia.']);
    }

    /**
     * GET /api/v1/auth/claim-email/{token}
     * Public: validates token, returns karyawan name + suggested username.
     */
    public function showClaim(string $token): JsonResponse
    {
        $invite = $this->findActiveInvite($token);
        if (!$invite) return response()->json(['message' => 'Link aktivasi sudah tidak berlaku.'], 410);

        $user = User::query()->withoutGlobalScopes()->find($invite->user_id);
        if (!$user) return response()->json(['message' => 'User tidak ditemukan.'], 404);

        return response()->json([
            'data' => [
                'name'              => $user->name,
                'suggested_username'=> $this->stalwart->slugifyFirstName($user->name),
                'domain'            => config('services.stalwart.domain', 'ibereality.com'),
                'expires_at'        => $invite->expires_at,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/claim-email
     * Public: payload {token, username}. Provision mailbox + update User.email.
     */
    public function claim(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token'    => ['required', 'string'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{1,30}$/', 'min:2', 'max:32'],
        ]);

        $invite = $this->findActiveInvite($payload['token']);
        if (!$invite) return response()->json(['message' => 'Link aktivasi sudah tidak berlaku.'], 410);

        $user = User::query()->withoutGlobalScopes()->find($invite->user_id);
        if (!$user) return response()->json(['message' => 'User tidak ditemukan.'], 404);

        $domain   = config('services.stalwart.domain', 'ibereality.com');
        $username = strtolower($payload['username']);
        $email    = "{$username}@{$domain}";

        // Username availability - both users.email and Stalwart account name
        $takenInUsers = User::query()->withoutGlobalScopes()
            ->where('email', $email)
            ->where('id', '!=', $user->id)
            ->exists();
        if ($takenInUsers) {
            return response()->json([
                'message' => 'Username sudah dipakai. Silakan pilih yang lain.',
                'errors'  => ['username' => ['Username sudah dipakai.']],
            ], 422);
        }
        if ($this->stalwart->findAccountByName($username) !== null) {
            return response()->json([
                'message' => 'Username sudah dipakai. Silakan pilih yang lain.',
                'errors'  => ['username' => ['Username sudah dipakai.']],
            ], 422);
        }

        // Provision Stalwart mailbox with chosen username
        $password = $this->stalwart->generatePassword();
        $domainId = $this->stalwart->getDomainId($domain);
        if (!$domainId) {
            return response()->json(['message' => 'Domain mail server belum siap. Hubungi HR.'], 500);
        }

        $created = $this->createStalwartAccount($username, $password, $user->name, $domainId);
        if (!$created) {
            return response()->json(['message' => 'Gagal membuat mailbox. Hubungi HR.'], 500);
        }

        // Password portal absen TIDAK diubah - karyawan tetap pakai password
        // yang sama saat awal registrasi. Random password Stalwart hanya untuk
        // mailbox (disimpan encrypted untuk SSO ticket flow ke webmail).
        // Email lama disimpan di legacy_email - karyawan tetap bisa login pakai
        // alamat itu (Gmail) selain alamat baru @ibereality.com.
        $oldEmail = $user->email;
        $user->update([
            'legacy_email'               => $user->legacy_email ?? $oldEmail,
            'email'                      => $email,
            'mailbox_password_encrypted' => Crypt::encryptString($password),
        ]);
        DB::table('email_migration_invites')->where('id', $invite->id)->update([
            'claimed_at'      => now(),
            'chosen_username' => $username,
            'new_email'       => $email,
            'updated_at'      => now(),
        ]);

        Log::info("Email migration: {$oldEmail} -> {$email} (user_id={$user->id})");

        return response()->json([
            'data' => [
                'email'    => $email,
                'password' => $password,
                'login_url'=> rtrim(config('app.frontend_url', 'https://attendance.ibereality.com'), '/') . '/login',
                'webmail_url' => config('services.stalwart.webmail_url', 'https://webmail.ibereality.com'),
            ],
            'message' => 'Email berhasil diaktifkan. Silakan login dengan email + password baru.',
        ]);
    }

    private function findActiveInvite(string $token): ?object
    {
        return DB::table('email_migration_invites')
            ->where('token', $token)
            ->whereNull('claimed_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    private function createStalwartAccount(string $username, string $password, string $displayName, string $domainId): bool
    {
        // Use the same payload shape as StalwartMailClient::provisionForUser does internally.
        $payload = [
            'name'        => $username,
            'domainId'    => $domainId,
            'description' => $displayName,
            'credentials' => (object) [
                '0' => [
                    '@type'      => 'Password',
                    'secret'     => $password,
                    'allowedIps' => (object) [],
                ],
            ],
        ];

        $ref = new \ReflectionClass($this->stalwart);
        $cli = $ref->getMethod('cli');
        $cli->setAccessible(true);
        $result = $cli->invoke($this->stalwart, ['create', 'Account/User', '--json', json_encode($payload)]);
        if (!$result['ok']) {
            Log::warning("Stalwart claim create failed for {$username}: {$result['error']}");
            return false;
        }
        return true;
    }

    private function emailBody(string $name, string $claimUrl): string
    {
        return <<<TXT
Halo {$name},

IBE Reality menyediakan email perusahaan @ibereality.com untukmu.

Kamu bisa pilih sendiri username (misal: amanda@ibereality.com, arummp@ibereality.com).

Klik link berikut untuk aktivasi (berlaku 7 hari):
{$claimUrl}

Setelah selesai:
  - Login portal absen pakai email baru + password yang ditampilkan
  - Webmail tersedia di https://webmail.ibereality.com

Kalau bukan kamu yang meminta atau ada pertanyaan, abaikan email ini atau hubungi HR.

Salam,
HR IBE Reality
TXT;
    }
}
