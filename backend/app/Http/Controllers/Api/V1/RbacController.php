<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RbacCapability;
use App\Models\RbacRole;
use App\Support\EnterpriseDocumentRbacV1;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RbacController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $companyId = $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage', 'RBAC access required.');

        $roles = RbacRole::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();
        $capabilities = RbacCapability::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('module')
            ->orderBy('capability_key')
            ->get();

        // Build matrix as { capability_id: { role_id: bool } }
        $matrix = DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
            ->where('r.company_id', $companyId)
            ->where('c.company_id', $companyId)
            ->get(['rc.role_id', 'rc.capability_id'])
            ->groupBy('capability_id')
            ->map(fn ($rows) => $rows->pluck('role_id')->all());

        return $this->success([
            'roles' => $roles,
            'capabilities' => $capabilities,
            'matrix' => $matrix,
        ]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $actor = $request->user();
        $companyId = $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage', 'RBAC access required.');

        $data = $request->validate([
            'key' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9_]*$/D',
                Rule::unique('rbac_roles', 'key')->where('company_id', $companyId),
            ],
            'label' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        abort_if(
            $this->isReservedRole($data['key']),
            422,
            'Role katalog tidak bisa dibuat melalui endpoint kustom.'
        );

        $role = RbacRole::withoutGlobalScopes()->create([
            ...$data,
            'company_id' => $companyId,
            'is_builtin' => false,
        ]);

        return $this->created($role, 'Role ditambahkan.');
    }

    public function destroyRole(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $companyId = $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage', 'RBAC access required.');

        $role = RbacRole::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);
        abort_if(
            $role->is_builtin || $this->isReservedRole($role->key),
            422,
            'Role bawaan atau katalog tidak bisa dihapus.'
        );
        $role->delete();

        return $this->success(message: 'Role dihapus.');
    }

    public function storeCapability(Request $request): JsonResponse
    {
        $actor = $request->user();
        $companyId = $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage', 'RBAC access required.');

        $data = $request->validate([
            'module' => ['required', 'string', 'max:50'],
            'capability_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D',
                Rule::unique('rbac_capabilities', 'capability_key')->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        abort_if(
            $this->isReservedCapability($data['capability_key']),
            422,
            'Capability katalog tidak bisa dibuat melalui endpoint kustom.'
        );

        return $this->created(RbacCapability::withoutGlobalScopes()->create([
            ...$data,
            'company_id' => $companyId,
        ]), 'Capability ditambahkan.');
    }

    public function destroyCapability(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $companyId = $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage', 'RBAC access required.');

        DB::transaction(function () use ($companyId, $id) {
            $capability = RbacCapability::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);

            abort_if(
                $this->isReservedCapability($capability->capability_key),
                422,
                'Capability katalog tidak bisa dihapus.'
            );

            $capability->delete();
        });

        return $this->success(message: 'Capability dihapus.');
    }

    public function toggle(Request $request): JsonResponse
    {
        $actor = $request->user();
        $companyId = $this->requireCompanyId($request);
        $this->requireCapability($actor, 'rbac.manage', 'RBAC access required.');

        $data = $request->validate([
            'role_id' => [
                'required',
                'integer',
                Rule::exists('rbac_roles', 'id')->where('company_id', $companyId),
            ],
            'capability_id' => [
                'required',
                'integer',
                Rule::exists('rbac_capabilities', 'id')->where('company_id', $companyId),
            ],
            'enabled' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($companyId, $data) {
            $role = RbacRole::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($data['role_id']);
            $capability = RbacCapability::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($data['capability_id']);

            $pivot = DB::table('rbac_role_capability')
                ->where('role_id', $role->id)
                ->where('capability_id', $capability->id);
            $current = (clone $pivot)->exists();
            $sealedExpectation = $this->sealedBuiltInGrantExpectation($role, $capability);

            if ($sealedExpectation !== null) {
                abort_unless(
                    $current === $sealedExpectation && $data['enabled'] === $sealedExpectation,
                    422,
                    'Grant katalog bawaan bersifat immutable.'
                );

                return;
            }

            if ($data['enabled']) {
                if (! $current) {
                    DB::table('rbac_role_capability')->insertOrIgnore([
                        'role_id' => $role->id,
                        'capability_id' => $capability->id,
                    ]);
                }
            } else {
                $pivot->delete();
            }
        });

        return $this->success(message: 'Toggle disimpan.');
    }

    private function requireCompanyId(Request $request): int
    {
        $companyId = (int) $request->user()?->company_id;
        abort_unless($companyId > 0, 403, 'Tenant context required.');

        return $companyId;
    }

    private function isReservedCapability(string $capability): bool
    {
        $capability = strtolower($capability);

        return $capability === EnterpriseDocumentRbacV1::ADMIN_ACCESS['capability_key']
            || EnterpriseDocumentRbacV1::ownsCapabilityNamespace($capability);
    }

    private function isReservedRole(string $role): bool
    {
        return in_array(strtolower($role), EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES, true);
    }

    private function sealedBuiltInGrantExpectation(
        RbacRole $role,
        RbacCapability $capability,
    ): ?bool {
        $roleKey = strtolower((string) $role->key);
        $capabilityKey = strtolower((string) $capability->capability_key);
        $reservedRoleGrant = $this->isReservedRole($roleKey)
            && ($this->isReservedCapability($capabilityKey));
        $builtInOwnedGrant = $role->is_builtin
            && EnterpriseDocumentRbacV1::ownsCapabilityNamespace($capabilityKey);

        if (! $reservedRoleGrant && ! $builtInOwnedGrant) {
            return null;
        }

        if (EnterpriseDocumentRbacV1::ownsCapabilityNamespace($capabilityKey)) {
            EnterpriseDocumentRbacV1::assertValid();

            return in_array(
                $roleKey.'|'.$capabilityKey,
                EnterpriseDocumentRbacV1::expectedGrantPairs(),
                true,
            );
        }

        if ($capabilityKey === EnterpriseDocumentRbacV1::ADMIN_ACCESS['capability_key']
            && $this->isReservedRole($roleKey)) {
            return true;
        }

        return null;
    }
}
