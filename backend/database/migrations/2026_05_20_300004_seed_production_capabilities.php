<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed RBAC capabilities untuk modul Produksi:
 *   production.view   - list products, serials, asset registry (read)
 *   production.manage - CRUD products, generate SN, update spec values
 *   production.admin  - delete products, recall SN, override asset status
 */
return new class extends Migration {
    public function up(): void
    {
        $companies = DB::table('companies')->pluck('id');

        $caps = [
            ['Produksi', 'production.view'],
            ['Produksi', 'production.manage'],
            ['Produksi', 'production.admin'],
        ];

        $matrix = [
            'production.view'   => ['superadmin', 'admin', 'produksi', 'user'],
            'production.manage' => ['superadmin', 'admin', 'produksi'],
            'production.admin'  => ['superadmin', 'admin'],
        ];

        foreach ($companies as $companyId) {
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

            foreach ($matrix as $capKey => $roleKeys) {
                $capId = DB::table('rbac_capabilities')
                    ->where('company_id', $companyId)
                    ->where('capability_key', $capKey)
                    ->value('id');
                if (!$capId) continue;

                foreach ($roleKeys as $roleKey) {
                    $roleId = DB::table('rbac_roles')
                        ->where('company_id', $companyId)
                        ->where('key', $roleKey)
                        ->value('id');
                    if (!$roleId) continue;

                    $exists = DB::table('rbac_role_capability')
                        ->where('role_id', $roleId)
                        ->where('capability_id', $capId)
                        ->exists();
                    if (!$exists) {
                        DB::table('rbac_role_capability')->insert([
                            'role_id'       => $roleId,
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
            ->whereIn('capability_key', ['production.view', 'production.manage', 'production.admin'])
            ->delete();
    }
};
