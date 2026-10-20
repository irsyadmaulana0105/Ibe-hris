<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed RBAC capabilities + warehouse default ("Gudang Utama") per company.
 */
return new class extends Migration {
    public function up(): void
    {
        $companies = DB::table('companies')->pluck('id');

        foreach ($companies as $companyId) {
            // Default warehouse - "Gudang Utama"
            $exists = DB::table('inventory_warehouses')
                ->where('company_id', $companyId)
                ->where('code', 'WH-MAIN')
                ->exists();
            if (!$exists) {
                DB::table('inventory_warehouses')->insert([
                    'company_id' => $companyId,
                    'code'       => 'WH-MAIN',
                    'name'       => 'Gudang Utama',
                    'type'       => 'main',
                    'is_default' => true,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // RBAC capabilities
            $caps = [
                ['Inventory', 'inventory.view'],
                ['Inventory', 'inventory.manage'],
                ['Inventory', 'inventory.admin'],
            ];
            foreach ($caps as [$module, $key]) {
                $exists = DB::table('rbac_capabilities')
                    ->where('company_id', $companyId)
                    ->where('capability_key', $key)
                    ->exists();
                if (!$exists) {
                    DB::table('rbac_capabilities')->insert([
                        'company_id'     => $companyId,
                        'capability_key' => $key,
                        'module'         => $module,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }
            }

            $matrix = [
                'inventory.view'   => ['superadmin', 'admin', 'produksi', 'finance', 'user'],
                'inventory.manage' => ['superadmin', 'admin', 'produksi'],
                'inventory.admin'  => ['superadmin', 'admin'],
            ];
            foreach ($matrix as $capKey => $roleKeys) {
                $capId = DB::table('rbac_capabilities')
                    ->where('company_id', $companyId)
                    ->where('capability_key', $capKey)->value('id');
                if (!$capId) continue;
                foreach ($roleKeys as $roleKey) {
                    $roleId = DB::table('rbac_roles')
                        ->where('company_id', $companyId)
                        ->where('key', $roleKey)->value('id');
                    if (!$roleId) continue;
                    $exists = DB::table('rbac_role_capability')
                        ->where('role_id', $roleId)
                        ->where('capability_id', $capId)->exists();
                    if (!$exists) {
                        DB::table('rbac_role_capability')->insert([
                            'role_id' => $roleId,
                            'capability_id' => $capId,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('rbac_capabilities')
            ->whereIn('capability_key', ['inventory.view', 'inventory.manage', 'inventory.admin'])
            ->delete();
        DB::table('inventory_warehouses')->where('code', 'WH-MAIN')->delete();
    }
};
