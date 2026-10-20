<?php

namespace App\Services;

use App\Support\EnterpriseDocumentRbacV1;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class EnterpriseRbacProvisioningService
{
    public function provisionCompany(int $companyId): void
    {
        if (! $this->schemaReady()) {
            return;
        }

        /** @var array<string, array{0: string, 1: array<int, string>}> $catalog */
        $catalog = config('enterprise_roles.roles', []);
        EnterpriseDocumentRbacV1::assertValid();
        $this->assertDocumentControlCatalog($catalog);

        DB::transaction(function () use ($catalog, $companyId): void {
            $this->assertReservedRoleIdentities($companyId);
            $this->assertNoUnexpectedCanonicalGrants($companyId);

            foreach ($catalog as $key => [$label, $capabilities]) {
                $roleDescription = $key === 'document_controller'
                    ? EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE['description']
                    : 'Enterprise role bundle; scope and approval limit are assigned per user.';
                DB::table('rbac_roles')->insertOrIgnore([
                    'company_id' => $companyId,
                    'key' => $key,
                    'label' => $label,
                    'description' => $roleDescription,
                    'is_builtin' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('rbac_roles')
                    ->where('company_id', $companyId)
                    ->where('key', $key)
                    ->update([
                        'label' => $label,
                        'description' => $roleDescription,
                        'is_builtin' => true,
                        'updated_at' => now(),
                    ]);

                $roleId = DB::table('rbac_roles')
                    ->where('company_id', $companyId)
                    ->where('key', $key)
                    ->value('id');

                foreach (array_unique($capabilities) as $capability) {
                    $targetMetadata = EnterpriseDocumentRbacV1::metadataForCapability($capability);
                    $module = $targetMetadata['module'] ?? explode('.', $capability)[0];
                    $description = $targetMetadata['description'] ?? null;
                    DB::table('rbac_capabilities')->insertOrIgnore([
                        'company_id' => $companyId,
                        'capability_key' => $capability,
                        'module' => $module,
                        'description' => $description,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $metadata = ['module' => $module, 'updated_at' => now()];
                    if ($description !== null) {
                        $metadata['description'] = $description;
                    }
                    DB::table('rbac_capabilities')
                        ->where('company_id', $companyId)
                        ->where('capability_key', $capability)
                        ->update($metadata);

                    $capabilityId = DB::table('rbac_capabilities')
                        ->where('company_id', $companyId)
                        ->where('capability_key', $capability)
                        ->value('id');

                    DB::table('rbac_role_capability')->updateOrInsert([
                        'role_id' => $roleId,
                        'capability_id' => $capabilityId,
                    ]);
                }
            }

            $this->assertExactCanonicalGrants($companyId);
        });
    }

    public function provisionAllCompanies(): void
    {
        if (! $this->schemaReady()) {
            return;
        }

        DB::table('companies')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $companyId) => $this->provisionCompany($companyId));
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('companies')
            && Schema::hasTable('rbac_roles')
            && Schema::hasTable('rbac_capabilities')
            && Schema::hasTable('rbac_role_capability');
    }

    /** @param array<string, array{0: string, 1: array<int, string>}> $catalog */
    private function assertDocumentControlCatalog(array $catalog): void
    {
        foreach ($catalog as $role => $definition) {
            if (count($definition) !== 2 || ! is_string($definition[0]) || ! is_array($definition[1])) {
                throw new LogicException('The enterprise role catalog is incomplete.');
            }

            $capabilities = $definition[1];
            foreach ($capabilities as $capability) {
                if (EnterpriseDocumentRbacV1::ownsCapabilityNamespace($capability)
                    && ! isset(EnterpriseDocumentRbacV1::CAPABILITIES[$capability])) {
                    throw new LogicException('The enterprise document-control namespace is invalid.');
                }
            }
            $documentCapabilities = array_values(array_filter(
                $capabilities,
                fn (string $capability): bool => isset(EnterpriseDocumentRbacV1::CAPABILITIES[$capability]),
            ));
            $expected = in_array($role, EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES, true)
                ? EnterpriseDocumentRbacV1::capabilitiesForRole($role)
                : [];
            if ($documentCapabilities !== $expected) {
                throw new LogicException('The enterprise document-control grants are invalid.');
            }
        }

        foreach (EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLE_LABELS as $role => $expectedLabel) {
            if (! isset($catalog[$role]) || count($catalog[$role]) !== 2) {
                throw new LogicException('The enterprise role catalog is incomplete.');
            }

            [$label, $capabilities] = $catalog[$role];
            if ($label !== $expectedLabel) {
                throw new LogicException('The enterprise role metadata is invalid.');
            }
            if (count(array_keys($capabilities, 'admin.access', true)) !== 1) {
                throw new LogicException('The enterprise admin-surface grant is invalid.');
            }
        }
    }

    private function assertReservedRoleIdentities(int $companyId): void
    {
        $roles = DB::table('rbac_roles')
            ->where('company_id', $companyId)
            ->whereIn('key', EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES)
            ->get(['key', 'label', 'is_builtin'])
            ->keyBy('key');

        foreach (EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLE_LABELS as $key => $label) {
            $role = $roles->get($key);
            if ($role !== null && (! (bool) $role->is_builtin || $role->label !== $label)) {
                throw new LogicException('A reserved enterprise role identity is invalid.');
            }
        }
    }

    private function assertNoUnexpectedCanonicalGrants(int $companyId): void
    {
        if ($this->hasCrossCompanyOwnedGrant($companyId)) {
            throw new LogicException('Cross-company document-control grant exists.');
        }

        $unexpected = array_diff(
            $this->canonicalDomainGrantPairs($companyId),
            EnterpriseDocumentRbacV1::expectedGrantPairs(),
        );
        if ($unexpected !== []) {
            throw new LogicException('Unexpected canonical document-control grant exists.');
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
        if ($this->canonicalDomainGrantPairs($companyId) !== EnterpriseDocumentRbacV1::expectedGrantPairs()) {
            throw new LogicException('Canonical document-control grants are incomplete.');
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
}
