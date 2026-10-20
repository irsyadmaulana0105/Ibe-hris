<?php

use App\Support\EnterpriseDocumentRbacV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        EnterpriseDocumentRbacV1::assertValid();

        DB::transaction(function (): void {
            foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
                $this->bootstrapCanonicalRolesIfEmpty((int) $companyId);
                $this->assertCanonicalRoleIdentities((int) $companyId);
                $this->ensureDocumentControllerRole((int) $companyId);
                $this->assertCanonicalRolesPresent((int) $companyId);
                $this->assertNoUnexpectedCanonicalGrants((int) $companyId);
                $this->ensureAdminSurface((int) $companyId);

                foreach (EnterpriseDocumentRbacV1::CAPABILITIES as $capability => $metadata) {
                    DB::table('rbac_capabilities')->insertOrIgnore([
                        'company_id' => $companyId,
                        'capability_key' => $capability,
                        'module' => $metadata['module'],
                        'description' => $metadata['description'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('rbac_capabilities')
                        ->where('company_id', $companyId)
                        ->where('capability_key', $capability)
                        ->update([
                            'module' => $metadata['module'],
                            'description' => $metadata['description'],
                            'updated_at' => now(),
                        ]);

                    $capabilityId = DB::table('rbac_capabilities')
                        ->where('company_id', $companyId)
                        ->where('capability_key', $capability)
                        ->value('id');

                    DB::table('rbac_roles')
                        ->where('company_id', $companyId)
                        ->whereIn('key', $metadata['roles'])
                        ->pluck('id')
                        ->each(fn (int $roleId) => DB::table('rbac_role_capability')->updateOrInsert([
                            'role_id' => $roleId,
                            'capability_id' => $capabilityId,
                        ]));
                }

                $this->assertExactCanonicalGrants((int) $companyId);
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. These capability rows and grants may
        // have existed before this migration or may already be referenced by
        // tenant-specific assignments. A code rollback must leave additive
        // authorization metadata intact; an explicit audited cleanup is safer
        // than deleting shared production grants from a migration rollback.
    }

    private function ensureDocumentControllerRole(int $companyId): void
    {
        $role = EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE;
        DB::table('rbac_roles')->insertOrIgnore([
            'company_id' => $companyId,
            'key' => $role['key'],
            'label' => $role['label'],
            'description' => $role['description'],
            'is_builtin' => $role['is_builtin'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rbac_roles')
            ->where('company_id', $companyId)
            ->where('key', $role['key'])
            ->update([
                'label' => $role['label'],
                'description' => $role['description'],
                'is_builtin' => $role['is_builtin'],
                'updated_at' => now(),
            ]);
    }

    private function bootstrapCanonicalRolesIfEmpty(int $companyId): void
    {
        if (DB::table('rbac_roles')->where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLE_LABELS as $key => $label) {
            DB::table('rbac_roles')->insert([
                'company_id' => $companyId,
                'key' => $key,
                'label' => $label,
                'description' => $key === EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE['key']
                    ? EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE['description']
                    : 'Enterprise role bundle; scope and approval limit are assigned per user.',
                'is_builtin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function ensureAdminSurface(int $companyId): void
    {
        $capability = EnterpriseDocumentRbacV1::ADMIN_ACCESS;
        DB::table('rbac_capabilities')->insertOrIgnore([
            'company_id' => $companyId,
            'module' => $capability['module'],
            'capability_key' => $capability['capability_key'],
            'description' => $capability['description'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rbac_capabilities')
            ->where('company_id', $companyId)
            ->where('capability_key', $capability['capability_key'])
            ->update([
                'module' => $capability['module'],
                'description' => $capability['description'],
                'updated_at' => now(),
            ]);
        $capabilityId = DB::table('rbac_capabilities')
            ->where('company_id', $companyId)
            ->where('capability_key', $capability['capability_key'])
            ->value('id');

        DB::table('rbac_roles')
            ->where('company_id', $companyId)
            ->whereIn('key', EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES)
            ->pluck('id')
            ->each(fn (int $roleId) => DB::table('rbac_role_capability')->updateOrInsert([
                'role_id' => $roleId,
                'capability_id' => $capabilityId,
            ]));
    }

    private function assertCanonicalRolesPresent(int $companyId): void
    {
        $roles = DB::table('rbac_roles')
            ->where('company_id', $companyId)
            ->whereIn('key', EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES)
            ->pluck('key')
            ->all();
        sort($roles, SORT_STRING);

        $expected = EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES;
        sort($expected, SORT_STRING);
        if ($roles !== $expected) {
            throw new RuntimeException('Canonical document-control roles are incomplete.');
        }
    }

    private function assertCanonicalRoleIdentities(int $companyId): void
    {
        $roles = DB::table('rbac_roles')
            ->where('company_id', $companyId)
            ->whereIn('key', EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES)
            ->get(['key', 'label', 'is_builtin'])
            ->keyBy('key');

        foreach (EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLE_LABELS as $key => $label) {
            $role = $roles->get($key);
            if ($role === null && $key === EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE['key']) {
                continue;
            }
            if ($role === null || ! (bool) $role->is_builtin || $role->label !== $label) {
                throw new RuntimeException('Canonical document-control role identity is invalid.');
            }
        }
    }

    private function assertNoUnexpectedCanonicalGrants(int $companyId): void
    {
        if ($this->hasCrossCompanyOwnedGrant($companyId)) {
            throw new RuntimeException('Cross-company document-control grant exists.');
        }

        $unexpected = array_diff(
            $this->canonicalDomainGrantPairs($companyId),
            $this->expectedCanonicalDomainGrantPairs(),
        );
        if ($unexpected !== []) {
            throw new RuntimeException('Unexpected canonical document-control grant exists.');
        }
    }

    private function hasCrossCompanyOwnedGrant(int $companyId): bool
    {
        return DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
            ->where('r.company_id', $companyId)
            ->where(function ($query): void {
                $query->whereNull('c.company_id')
                    ->orWhereColumn('c.company_id', '!=', 'r.company_id');
            })
            ->where(function ($query): void {
                $query->where('c.capability_key', EnterpriseDocumentRbacV1::ADMIN_ACCESS['capability_key'])
                    ->orWhere('c.capability_key', 'like', 'documents.%')
                    ->orWhere('c.capability_key', 'like', 'finance.company_bank.%');
            })
            ->exists();
    }

    private function assertExactCanonicalGrants(int $companyId): void
    {
        if ($this->canonicalDomainGrantPairs($companyId) !== $this->expectedCanonicalDomainGrantPairs()) {
            throw new RuntimeException('Canonical document-control grants are incomplete.');
        }
    }

    /** @return array<int, string> */
    private function canonicalDomainGrantPairs(int $companyId): array
    {
        return DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
            ->where('r.company_id', $companyId)
            ->where('r.is_builtin', true)
            ->where('c.company_id', $companyId)
            ->where(function ($query): void {
                $query->where('c.capability_key', 'like', 'documents.%')
                    ->orWhere('c.capability_key', 'like', 'finance.company_bank.%');
            })
            ->orderBy('r.key')
            ->orderBy('c.capability_key')
            ->get(['r.key as role_key', 'c.capability_key'])
            ->map(fn (object $row): string => $row->role_key.'|'.$row->capability_key)
            ->all();
    }

    /** @return array<int, string> */
    private function expectedCanonicalDomainGrantPairs(): array
    {
        return EnterpriseDocumentRbacV1::expectedGrantPairs();
    }
};
