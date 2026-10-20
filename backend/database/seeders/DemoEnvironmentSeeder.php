<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyModuleEntitlement;
use App\Models\CompanySubscription;
use App\Models\SaasPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoEnvironmentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment() !== 'demo') {
            throw new RuntimeException('DemoEnvironmentSeeder may only run with APP_ENV=demo.');
        }

        $password = (string) env('DEMO_SEED_PASSWORD');
        if (strlen($password) < 14 || str_starts_with($password, 'CHANGE_ME')) {
            throw new RuntimeException('DEMO_SEED_PASSWORD must be a non-placeholder value of at least 14 characters.');
        }

        fake()->seed(20260802);
        $this->call(DatabaseSeeder::class);

        User::query()->whereIn('email', ['admin@example.com', 'superadmin@example.com'])
            ->update(['password' => Hash::make($password)]);

        $catalog = config('demo_catalog.tenants', []);
        $personas = config('demo_catalog.personas', []);
        $knownModules = array_keys(config('saas_modules.modules', []));
        $domain = config('demo_catalog.email_domain', 'demo.ibereality.com');

        if ($catalog === [] || $personas === []) {
            throw new RuntimeException('Demo catalog must define at least one tenant and persona.');
        }

        foreach (config('saas_modules.plans', []) as $planKey => $definition) {
            $plan = SaasPlan::query()->updateOrCreate(
                ['key' => $planKey],
                [
                    'name' => $definition['name'],
                    'description' => "Demo subscription plan: {$definition['name']}",
                    'user_limit' => $definition['user_limit'],
                    'monthly_price' => $definition['monthly_price'],
                    'yearly_price' => $definition['yearly_price'],
                    'currency' => $definition['currency'],
                    'is_active' => true,
                ],
            );

            foreach ($knownModules as $moduleKey) {
                DB::table('saas_plan_modules')->updateOrInsert(
                    ['plan_id' => $plan->id, 'module_key' => $moduleKey],
                    [
                        'enabled' => in_array($moduleKey, $definition['modules'], true),
                        'limits' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        $companies = [];
        foreach ($catalog as $tenantKey => $tenant) {
            $unknownModules = array_diff($tenant['modules'], $knownModules);
            if ($unknownModules !== []) {
                throw new RuntimeException("Unknown modules for demo tenant {$tenantKey}: ".implode(', ', $unknownModules));
            }

            $companies[$tenantKey] = Company::query()->updateOrCreate(
                ['slug' => "demo-{$tenantKey}"],
                [
                    'name' => $tenant['name'],
                    'short_name' => $tenant['short_name'],
                    'tagline' => 'Demo environment',
                    'email' => "contact@{$domain}",
                    'city' => 'Sidoarjo',
                    'is_active' => true,
                ],
            );
        }

        // Roles are company-scoped, so seed them only after every demo tenant exists.
        $this->call(EnterpriseRbacSeeder::class);

        $userCount = 0;
        foreach ($catalog as $tenantKey => $tenant) {
            $company = $companies[$tenantKey];
            $enabledModules = array_flip($tenant['modules']);

            foreach ($knownModules as $moduleKey) {
                CompanyModuleEntitlement::query()->updateOrCreate(
                    ['company_id' => $company->id, 'module_key' => $moduleKey],
                    [
                        'enabled' => isset($enabledModules[$moduleKey]),
                        'source' => 'demo_seed',
                        'limits' => ['dataset' => env('DEMO_DATASET_VERSION', 'unversioned')],
                        'valid_from' => now()->startOfDay(),
                        'valid_until' => now()->addYear()->endOfDay(),
                    ],
                );
            }

            $plan = SaasPlan::query()->where('key', $tenant['plan'])->first();
            if ($plan) {
                CompanySubscription::query()->updateOrCreate(
                    ['company_id' => $company->id],
                    [
                        'plan_id' => $plan->id,
                        'status' => 'active',
                        'licensed_users' => count($tenant['personas']),
                        'current_period_ends_at' => now()->addYear()->endOfDay(),
                        'external_reference' => "demo:{$tenantKey}",
                        'billing_email' => "billing@{$domain}",
                    ],
                );
            }

            foreach (array_values($tenant['personas']) as $index => $personaKey) {
                if (! isset($personas[$personaKey])) {
                    throw new RuntimeException("Unknown persona {$personaKey} for demo tenant {$tenantKey}.");
                }
                [$name, $group, $enterpriseRole] = $personas[$personaKey];
                $user = User::query()->updateOrCreate(
                    ['email' => "{$tenantKey}.{$personaKey}@{$domain}"],
                    [
                        'company_id' => $company->id,
                        'nip' => sprintf('DEMO-%s-%03d', strtoupper(substr($tenantKey, 0, 4)), $index + 1),
                        'employee_number' => sprintf('DM%s%03d', strtoupper(substr($tenantKey, 0, 3)), $index + 1),
                        'name' => "{$name} - {$tenant['short_name']}",
                        'phone' => sprintf('0800%03d%04d', array_search($tenantKey, array_keys($catalog), true) + 1, $index + 1),
                        'gender' => $index % 2 === 0 ? 'male' : 'female',
                        'address' => 'Alamat sintetis untuk demonstrasi',
                        'city' => 'Sidoarjo',
                        'group' => $group,
                        'employment_status' => 'permanent',
                        'attendance_location_mode' => $tenantKey === 'enterprise' && $personaKey === 'employee'
                            ? 'mobile_field'
                            : 'office_geofence',
                        'email_verified_at' => now(),
                        'password' => Hash::make($password),
                    ],
                );

                $roleId = DB::table('rbac_roles')
                    ->where('company_id', $company->id)
                    ->where('key', $enterpriseRole)
                    ->value('id');

                if ($roleId) {
                    $assignmentKey = [
                        'company_id' => $company->id,
                        'user_id' => $user->id,
                        'role_id' => $roleId,
                        'source' => 'demo_seed',
                    ];
                    $values = [
                        'id' => (string) Str::ulid(),
                        'status' => 'active',
                        'valid_from' => now()->startOfDay(),
                        'valid_until' => now()->addYear()->endOfDay(),
                        'reason' => 'Versioned synthetic demo persona',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ];

                    $existingId = DB::table('rbac_user_assignments')->where($assignmentKey)->value('id');
                    if ($existingId) {
                        unset($values['id'], $values['created_at']);
                        DB::table('rbac_user_assignments')->where('id', $existingId)->update($values);
                    } else {
                        DB::table('rbac_user_assignments')->insert($assignmentKey + $values);
                    }
                }
                $userCount++;
            }
        }

        // Populate the flagship enterprise tenant with connected synthetic
        // transactions so every presentation module has meaningful content.
        $this->call(EnterpriseDemoDatasetSeeder::class);

        $this->command?->info(sprintf(
            'Synthetic demo dataset %s loaded with %d tenants and %d scoped accounts.',
            env('DEMO_DATASET_VERSION', 'unversioned'),
            count($catalog),
            $userCount,
        ));
    }
}
