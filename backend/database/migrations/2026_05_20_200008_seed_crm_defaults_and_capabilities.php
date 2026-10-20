<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default CRM pipeline + stages + RBAC capabilities + assign ke roles default.
 * Berjalan per Company (tenant-aware).
 */
return new class extends Migration {
    public function up(): void
    {
        $companies = DB::table('companies')->pluck('id');

        foreach ($companies as $companyId) {
            $this->seedPipelineFor((int) $companyId);
            $this->seedCapabilitiesFor((int) $companyId);
        }
    }

    private function seedPipelineFor(int $companyId): void
    {
        // Skip kalau sudah ada pipeline (idempotent)
        $exists = DB::table('crm_pipelines')->where('company_id', $companyId)->exists();
        if ($exists) return;

        $pipelineId = DB::table('crm_pipelines')->insertGetId([
            'company_id'  => $companyId,
            'name'        => 'Sales',
            'description' => 'Default sales pipeline',
            'is_default'  => true,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $stages = [
            ['Prospecting',   '#94a3b8', 'open', 10, 10],
            ['Qualification', '#3b82f6', 'open', 20, 25],
            ['Proposal',      '#a855f7', 'open', 30, 50],
            ['Negotiation',   '#f59e0b', 'open', 40, 75],
            ['Closed Won',    '#22c55e', 'won',  50, 100],
            ['Closed Lost',   '#ef4444', 'lost', 60, 0],
        ];

        foreach ($stages as [$name, $color, $type, $sort, $prob]) {
            DB::table('crm_stages')->insert([
                'company_id'          => $companyId,
                'pipeline_id'         => $pipelineId,
                'name'                => $name,
                'color'               => $color,
                'type'                => $type,
                'sort_order'          => $sort,
                'default_probability' => $prob,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }

    private function seedCapabilitiesFor(int $companyId): void
    {
        // Capabilities: crm.view, crm.manage, crm.admin
        $caps = [
            ['CRM', 'crm.view'],     // baca leads/deals miliknya + tim
            ['CRM', 'crm.manage'],   // create/update/delete deals, leads, contacts
            ['CRM', 'crm.admin'],    // kelola pipeline, reassign deals, override owner
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

        // Assign default ke roles
        // superadmin → semua
        // admin → crm.view + crm.manage + crm.admin
        // user → crm.view + crm.manage (sales rep biasa)
        // finance/intern → crm.view aja (read-only buat liat pipeline)
        $matrix = [
            'crm.view'   => ['superadmin', 'admin', 'finance', 'user', 'intern'],
            'crm.manage' => ['superadmin', 'admin', 'user'],
            'crm.admin'  => ['superadmin', 'admin'],
        ];

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

    public function down(): void
    {
        DB::table('crm_stages')->truncate();
        DB::table('crm_pipelines')->truncate();
        DB::table('rbac_capabilities')->whereIn('capability_key', ['crm.view', 'crm.manage', 'crm.admin'])->delete();
    }
};
