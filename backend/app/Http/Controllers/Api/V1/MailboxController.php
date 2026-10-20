<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\StalwartMailClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Stalwart mailbox administration via HRIS portal. Menggantikan akses langsung
 * ke mail.ibereality.com admin UI yang sekarang dianggap backdoor.
 *
 * Hanya owner (is_owner=true) atau superadmin yang boleh manage. Action:
 *   - List semua mailbox yang ada di Stalwart
 *   - Reset password mailbox (rotate + sinkron ke users.mailbox_password_encrypted)
 *   - Provision new (untuk role mailbox / system mailbox)
 *   - Deprovision (hapus mailbox)
 *
 * SEMUA action di-audit log.
 */
class MailboxController extends ApiController
{
    public function __construct(private readonly StalwartMailClient $stalwart) {}

    /** GET /api/v1/admin/mailboxes */
    public function index(Request $request): JsonResponse
    {
        $this->requireOwnerOrSuper($request->user());
        $ref = new \ReflectionClass($this->stalwart);
        $cli = $ref->getMethod('cli');
        $cli->setAccessible(true);
        $result = $cli->invoke($this->stalwart, ['query', 'Account']);
        if (!$result['ok']) {
            return response()->json(['message' => 'Stalwart query failed: ' . $result['error']], 500);
        }
        $rows = [];
        foreach (preg_split('/\R/', trim($result['stdout'])) as $line) {
            // Format: "id   email   description   created_at"
            if (preg_match('/^([a-z0-9]+)\s+([^\s@]+@[^\s]+)\s+(.*?)\s+(\d{4}-\d{2}-\d{2}T)/', $line, $m)) {
                $email = trim($m[2]);
                $linkedUser = User::query()->withoutGlobalScopes()->where('email', $email)->first();
                $rows[] = [
                    'id'           => $m[1],
                    'email'        => $email,
                    'description'  => trim($m[3]),
                    'linked_user'  => $linkedUser ? ['id' => $linkedUser->id, 'name' => $linkedUser->name, 'group' => $linkedUser->group] : null,
                    'has_password' => $linkedUser?->mailbox_password_encrypted !== null,
                ];
            }
        }
        return $this->success(['rows' => $rows]);
    }

    /** POST /api/v1/admin/mailboxes/{accountId}/rotate-password */
    public function rotatePassword(Request $request, string $accountId): JsonResponse
    {
        $admin = $request->user();
        $this->requireOwnerOrSuper($admin);

        $newPassword = $this->stalwart->generatePassword();
        if (!$this->stalwart->updatePassword($accountId, $newPassword)) {
            return response()->json(['message' => 'Failed to rotate password.'], 500);
        }

        // Sync ke user record kalau ada email yang match
        $email = $request->input('email');
        if ($email) {
            $user = User::query()->withoutGlobalScopes()->where('email', $email)->first();
            if ($user) {
                $user->update(['mailbox_password_encrypted' => Crypt::encryptString($newPassword)]);
            }
        }

        app(\App\Services\AuditLogService::class)->log(
            'mailbox.password_rotated',
            $admin, 'mailbox', $accountId,
            ['email' => $email, 'sync_user' => isset($user)],
            $request,
        );

        return $this->success(['password' => $newPassword], 'Password baru di-generate. Catat sekarang.');
    }

    /** POST /api/v1/admin/mailboxes - create new mailbox (untuk system/role mailbox) */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireOwnerOrSuper($admin);
        $data = $request->validate([
            'username'    => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{1,30}$/'],
            'description' => ['required', 'string', 'max:200'],
        ]);

        $domain = config('services.stalwart.domain', 'ibereality.com');
        $domainId = $this->stalwart->getDomainId($domain);
        if (!$domainId) {
            return response()->json(['message' => 'Domain not found in Stalwart.'], 500);
        }
        if ($this->stalwart->findAccountByName($data['username']) !== null) {
            return response()->json(['message' => 'Mailbox sudah ada.'], 422);
        }

        $password = $this->stalwart->generatePassword();
        $payload = [
            'name'        => $data['username'],
            'domainId'    => $domainId,
            'description' => $data['description'],
            'credentials' => (object) ['0' => ['@type' => 'Password', 'secret' => $password, 'allowedIps' => (object) []]],
        ];
        $ref = new \ReflectionClass($this->stalwart);
        $cli = $ref->getMethod('cli');
        $cli->setAccessible(true);
        $result = $cli->invoke($this->stalwart, ['create', 'Account/User', '--json', json_encode($payload)]);
        if (!$result['ok']) {
            return response()->json(['message' => 'Create failed: ' . $result['error']], 500);
        }

        app(\App\Services\AuditLogService::class)->log(
            'mailbox.created',
            $admin, 'mailbox', $data['username'],
            ['email' => "{$data['username']}@{$domain}", 'description' => $data['description']],
            $request,
        );

        return $this->created([
            'email'    => "{$data['username']}@{$domain}",
            'password' => $password,
        ], 'Mailbox dibuat. Catat password - tidak ditampilkan ulang.');
    }

    /** DELETE /api/v1/admin/mailboxes/{accountId} */
    public function destroy(Request $request, string $accountId): JsonResponse
    {
        $admin = $request->user();
        $this->requireOwnerOrSuper($admin);
        $email = $request->query('email');
        if (!$email) return response()->json(['message' => 'Email required as ?email=...'], 422);

        // Block hapus core role mailbox (admin@, hrd@, finance@, noreply@)
        $protected = ['admin@ibereality.com', 'hrd@ibereality.com', 'finance@ibereality.com', 'noreply@ibereality.com'];
        if (in_array($email, $protected, true)) {
            return response()->json(['message' => 'Mailbox perusahaan ini protected, tidak bisa dihapus.'], 403);
        }

        $ok = $this->stalwart->deprovision($email);
        if (!$ok) return response()->json(['message' => 'Deprovision failed.'], 500);

        app(\App\Services\AuditLogService::class)->log(
            'mailbox.deleted',
            $admin, 'mailbox', $accountId,
            ['email' => $email],
            $request,
        );

        return $this->success(message: 'Mailbox dihapus.');
    }

    private function requireOwnerOrSuper(User $user): void
    {
        // is_owner OR superadmin allowed; admin/finance/user blocked
        $isOwner = (bool) ($user->is_owner ?? false);
        if (!$isOwner && $user->group !== 'superadmin') {
            abort(403, 'Hanya owner / superadmin yang boleh kelola mailbox.');
        }
    }
}
