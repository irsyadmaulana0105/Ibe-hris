<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Multi-role management + self switcher.
 *
 * Endpoint karyawan (self):
 *   GET  /me/available-roles → list role yang bisa di-switch
 *
 * Endpoint admin (superadmin only):
 *   GET    /admin/users/{id}/roles       → list roles user
 *   POST   /admin/users/{id}/roles       → assign role tambahan
 *   DELETE /admin/users/{id}/roles/{role} → revoke role
 */
class UserRoleController extends ApiController
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    // ═══════════════════ SELF ═══════════════════

    /**
     * Karyawan lihat semua role yang bisa mereka pakai.
     * Frontend pakai untuk populate role switcher dropdown di header.
     */
    public function selfAvailableRoles(Request $request): JsonResponse
    {
        $user = $request->user();
        $available = $user->available_roles;

        // Map ke label human-readable
        $labels = [
            'user' => 'Karyawan',
            'admin' => 'HR Admin',
            'finance' => 'Finance',
            'marketing' => 'Marketing',
            'produksi' => 'Produksi',
            'direktur' => 'Direktur / CEO',
            'superadmin' => 'Superadmin',
        ];

        return $this->success([
            'primary_role' => $user->group,
            'available' => array_map(fn ($r) => [
                'role' => $r,
                'label' => $labels[$r] ?? ucfirst($r),
                'is_primary' => $r === $user->group,
            ], $available),
        ]);
    }

    // ═══════════════════ ADMIN ═══════════════════

    /**
     * GET /admin/user-accounts
     *
     * List user dengan group != 'user' (founder + admin accounts).
     * Karyawan biasa sudah ada di /admin/employees, endpoint ini khusus untuk
     * founder & staff internal yang tidak masuk employee directory.
     */
    public function listAdminAccounts(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $users = User::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->whereIn('group', ['admin', 'superadmin', 'finance', 'marketing', 'produksi', 'direktur'])
            ->with(['roles', 'jobTitle:id,name', 'division:id,name'])
            ->orderByRaw("FIELD(`group`, 'superadmin', 'direktur', 'admin', 'finance', 'marketing', 'produksi')")
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'primary_role' => $u->group,
                'job_title' => $u->jobTitle?->name,
                'division' => $u->division?->name,
                'additional_roles' => $u->roles->pluck('role')->all(),
                'avatar_url' => $u->avatar_url ?? null,
            ]);

        return $this->success([
            'accounts' => $users,
            'total' => $users->count(),
        ]);
    }

    /** GET /admin/users/{id}/roles */
    public function listForUser(Request $request, string $userId): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Employee management access required.');

        $user = User::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($userId);

        $additional = $user->roles()->with('grantor:id,name')->get()->map(fn ($r) => [
            'role' => $r->role,
            'granted_by' => $r->grantor?->name,
            'notes' => $r->notes,
            'granted_at' => $r->granted_at?->format('Y-m-d'),
        ]);

        return $this->success([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'primary' => $user->group,
            'additional' => $additional,
        ]);
    }

    /** POST /admin/users/{id}/roles → { role, notes } */
    public function assign(Request $request, string $userId): JsonResponse
    {
        $admin = $request->user();
        // Cuma superadmin yang bisa grant role admin/finance/dll
        if (! $admin->isSuperadmin) {
            abort(403, 'Hanya superadmin yang bisa grant role tambahan.');
        }

        $payload = $request->validate([
            'role' => ['required', Rule::in(['admin', 'superadmin', 'finance', 'marketing', 'produksi', 'direktur'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($userId);

        $user->assignRole($payload['role'], $admin, $payload['notes'] ?? null);

        $this->auditLog->log(
            'user_role.assigned', $admin, 'user_role', $user->id,
            ['role' => $payload['role'], 'target_user' => $user->name],
            $request,
        );

        return $this->success(null, "Role '{$payload['role']}' berhasil di-grant ke {$user->name}.");
    }

    /** DELETE /admin/users/{id}/roles/{role} */
    public function revoke(Request $request, string $userId, string $role): JsonResponse
    {
        $admin = $request->user();
        if (! $admin->isSuperadmin) {
            abort(403, 'Hanya superadmin yang bisa revoke role.');
        }

        $user = User::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($userId);

        try {
            $user->revokeRole($role);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLog->log(
            'user_role.revoked', $admin, 'user_role', $user->id,
            ['role' => $role, 'target_user' => $user->name],
            $request,
        );

        return $this->success(null, "Role '{$role}' berhasil di-revoke dari {$user->name}.");
    }
}
