<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RbacDelegation;
use App\Models\RbacUserAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnterpriseRbacController extends ApiController
{
    public function assignments(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage');
        $companyId = (int) $actor->company_id;

        $rows = RbacUserAssignment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereExists(function ($users) use ($companyId) {
                $users->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'rbac_user_assignments.user_id')
                    ->where('users.company_id', $companyId);
            })
            ->whereExists(function ($roles) use ($companyId) {
                $roles->selectRaw('1')
                    ->from('rbac_roles')
                    ->whereColumn('rbac_roles.id', 'rbac_user_assignments.role_id')
                    ->where('rbac_roles.company_id', $companyId);
            })
            ->with([
                'role' => fn ($roles) => $roles
                    ->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->select(['id', 'key', 'label']),
                'scopes:id,assignment_id,scope_type,scope_id',
            ])
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')))
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage');

        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('company_id', $actor->company_id)],
            'role_id' => ['required', 'integer', Rule::exists('rbac_roles', 'id')->where('company_id', $actor->company_id)],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'approval_limit' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
            'scopes' => ['sometimes', 'array', 'max:50'],
            'scopes.*.type' => ['required_with:scopes', 'string', Rule::in(['division_id', 'location_id', 'project_id', 'warehouse_id', 'own', 'team'])],
            'scopes.*.id' => ['required_with:scopes', 'string', 'max:100'],
        ]);

        abort_if((string) $data['user_id'] === (string) $actor->id, 403,
            'Pemisahan tugas melarang administrator mengubah assignment miliknya sendiri.');

        $assignment = DB::transaction(function () use ($data, $actor) {
            $assignment = RbacUserAssignment::create([
                'user_id' => $data['user_id'],
                'role_id' => $data['role_id'],
                'company_id' => $actor->company_id,
                'status' => 'active',
                'valid_from' => $data['valid_from'] ?? now(),
                'valid_until' => $data['valid_until'] ?? now()->addDays(config('enterprise_rbac.default_assignment_days', 365)),
                'approval_limit' => $data['approval_limit'] ?? null,
                'granted_by' => $actor->id,
                'reason' => $data['reason'],
                'source' => 'api',
            ]);

            foreach ($data['scopes'] ?? [] as $scope) {
                $assignment->scopes()->create([
                    'scope_type' => $scope['type'],
                    'scope_id' => $scope['id'],
                ]);
            }

            DB::table('audit_logs')->insert([
                'company_id' => $actor->company_id,
                'actor_id' => $actor->id,
                'action' => 'rbac.assignment.granted',
                'entity_type' => 'rbac_user_assignment',
                'entity_id' => $assignment->id,
                'meta' => json_encode(['target_user_id' => $data['user_id'], 'role_id' => $data['role_id']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $assignment;
        });

        return $this->created($assignment->load('role', 'scopes'), 'Enterprise role assignment dibuat.');
    }

    public function revokeAssignment(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $assignment = RbacUserAssignment::query()
            ->where('company_id', $actor->company_id)
            ->findOrFail($id);
        abort_if((string) $assignment->user_id === (string) $actor->id, 403,
            'Pemisahan tugas melarang administrator mencabut assignment miliknya sendiri.');
        abort_if($assignment->status !== 'active', 422, 'Assignment sudah tidak aktif.');

        DB::transaction(function () use ($assignment, $data, $actor) {
            $assignment->update(['status' => 'revoked', 'valid_until' => now(), 'reason' => $data['reason']]);
            $this->audit($actor, 'rbac.assignment.revoked', 'rbac_user_assignment', $assignment->id, [
                'target_user_id' => $assignment->user_id,
                'role_id' => $assignment->role_id,
            ]);
        });

        return $this->success($assignment, 'Assignment dicabut.');
    }

    public function storeDelegation(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage');

        $data = $request->validate([
            'delegator_id' => ['required', Rule::exists('users', 'id')->where('company_id', $actor->company_id)],
            'delegate_id' => ['required', 'different:delegator_id', Rule::exists('users', 'id')->where('company_id', $actor->company_id)],
            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('rbac_roles', 'id')->where('company_id', $actor->company_id),
                'required_without:capability_key',
                'prohibits:capability_key',
            ],
            'capability_key' => [
                'nullable', 'string', 'max:150', 'required_without:role_id',
                'prohibits:role_id',
                Rule::exists('rbac_capabilities', 'capability_key')->where('company_id', $actor->company_id),
            ],
            'approval_limit' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        abort_if((string) $data['delegate_id'] === (string) $actor->id, 403,
            'Pemisahan tugas melarang administrator mendelegasikan hak kepada dirinya sendiri.');

        $delegation = DB::transaction(function () use ($data, $actor) {
            $delegation = RbacDelegation::create([
                ...$data,
                'company_id' => $actor->company_id,
                'status' => 'active',
                'approved_by' => $actor->id,
            ]);
            $this->audit($actor, 'rbac.delegation.granted', 'rbac_delegation', $delegation->id, [
                'delegator_id' => $data['delegator_id'],
                'delegate_id' => $data['delegate_id'],
                'role_id' => $data['role_id'] ?? null,
                'capability_key' => $data['capability_key'] ?? null,
            ]);

            return $delegation;
        });

        return $this->created($delegation, 'Delegasi dibuat.');
    }

    public function revokeDelegation(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $delegation = RbacDelegation::query()->where('company_id', $actor->company_id)->findOrFail($id);
        abort_if($delegation->status !== 'active', 422, 'Delegasi sudah tidak aktif.');
        DB::transaction(function () use ($delegation, $data, $actor) {
            $delegation->update(['status' => 'revoked', 'valid_until' => now(), 'reason' => $data['reason']]);
            $this->audit($actor, 'rbac.delegation.revoked', 'rbac_delegation', $delegation->id, [
                'delegator_id' => $delegation->delegator_id,
                'delegate_id' => $delegation->delegate_id,
            ]);
        });

        return $this->success($delegation, 'Delegasi dicabut.');
    }

    public function shadowSummary(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage');

        $base = DB::table('rbac_access_decisions')->where('company_id', $actor->company_id);

        return $this->success([
            'mode' => config('enterprise_rbac.mode'),
            'total' => (clone $base)->count(),
            'mismatches' => (clone $base)->whereColumn('legacy_allowed', '!=', 'target_allowed')->count(),
            'legacy_allow_target_deny' => (clone $base)->where('legacy_allowed', true)->where('target_allowed', false)->count(),
            'legacy_deny_target_allow' => (clone $base)->where('legacy_allowed', false)->where('target_allowed', true)->count(),
        ]);
    }

    private function requireCompanyId(Request $request): int
    {
        $companyId = (int) $request->user()?->company_id;
        abort_unless($companyId > 0, 403, 'Tenant context required.');

        return $companyId;
    }

    private function audit($actor, string $action, string $entityType, string|int $entityId, array $meta): void
    {
        DB::table('audit_logs')->insert([
            'company_id' => $actor->company_id,
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'meta' => json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
