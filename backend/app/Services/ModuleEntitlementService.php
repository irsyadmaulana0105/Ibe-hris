<?php

namespace App\Services;

use App\Models\CompanyModuleEntitlement;
use App\Models\CompanySubscription;
use App\Models\SaasPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ModuleEntitlementService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function catalog(): array
    {
        return [
            'modules' => config('saas_modules.modules', []),
            'plans' => config('saas_modules.plans', []),
        ];
    }

    public function forCompany(?int $companyId): array
    {
        $keys = array_keys(config('saas_modules.modules', []));
        if (! $companyId) {
            return array_fill_keys($keys, false);
        }

        // Supports zero-downtime code-first deploys: old tenants remain fully
        // operational during the short window before the additive migration runs.
        if (! Schema::hasTable('company_module_entitlements')) {
            return $this->withDependenciesEnforced(
                array_fill_keys($keys, (bool) config('saas_modules.legacy_default_enabled', true))
            );
        }

        return Cache::remember("company:{$companyId}:module-entitlements", 300, function () use ($companyId, $keys) {
            $subscription = CompanySubscription::query()->withoutGlobalScopes()->where('company_id', $companyId)->first();
            if ($subscription && in_array($subscription->status, ['suspended', 'cancelled'], true)) {
                return array_fill_keys($keys, false);
            }
            $rows = CompanyModuleEntitlement::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->get(['module_key', 'enabled', 'valid_from', 'valid_until']);

            // Zero-configuration compatibility: tenants that existed before SaaS
            // keep every ERP module until an administrator explicitly saves a plan.
            if ($rows->isEmpty()) {
                return $this->withDependenciesEnforced(
                    array_fill_keys($keys, (bool) config('saas_modules.legacy_default_enabled', true))
                );
            }

            $now = now();
            $result = array_fill_keys($keys, false);
            foreach ($rows as $row) {
                if (! array_key_exists($row->module_key, $result)) {
                    continue;
                }
                $valid = (! $row->valid_from || $row->valid_from->lte($now))
                    && (! $row->valid_until || $row->valid_until->gte($now));
                $result[$row->module_key] = $row->enabled && $valid;
            }

            return $this->withDependenciesEnforced($result);
        });
    }

    public function enabled(?int $companyId, string $moduleKey): bool
    {
        return $this->forCompany($companyId)[$moduleKey] ?? false;
    }

    /**
     * Convert persisted switches into effective entitlements. A manually
     * edited or partially migrated row set must never enable a module while a
     * declared dependency is disabled or unknown.
     *
     * @param  array<string, bool>  $modules
     * @return array<string, bool>
     */
    private function withDependenciesEnforced(array $modules): array
    {
        $definitions = config('saas_modules.modules', []);

        // Iterate to a fixed point so transitive dependencies are enforced.
        // The upper bound also prevents a malformed cyclic config from looping.
        for ($pass = 0; $pass < count($definitions); $pass++) {
            $changed = false;
            foreach ($definitions as $module => $definition) {
                if (! ($modules[$module] ?? false)) {
                    continue;
                }

                foreach ($definition['depends_on'] ?? [] as $dependency) {
                    if (! ($modules[$dependency] ?? false)) {
                        $modules[$module] = false;
                        $changed = true;
                        break;
                    }
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $modules;
    }

    public function subscriptionForCompany(int $companyId): ?CompanySubscription
    {
        if (! Schema::hasTable('company_subscriptions')) {
            return null;
        }

        return CompanySubscription::query()
            ->withoutGlobalScopes()
            ->with('plan')
            ->where('company_id', $companyId)
            ->first();
    }

    public function assertUserCapacity(int $companyId): void
    {
        $subscription = $this->subscriptionForCompany($companyId);
        $limit = $subscription?->licensed_users ?? $subscription?->plan?->user_limit;
        if (! $limit) {
            return;
        }
        $active = User::query()->withoutGlobalScopes()->where('company_id', $companyId)
            ->whereNotIn('employment_status', User::INACTIVE_STATUSES)->count();
        if ($active >= $limit) {
            throw ValidationException::withMessages(['subscription' => "Kuota {$limit} pengguna aktif pada paket SaaS telah tercapai."]);
        }
    }

    public function configure(int $companyId, string $planKey, array $overrides, User $actor, ?Request $request = null, array $subscriptionData = []): array
    {
        if (! Schema::hasTable('company_module_entitlements')) {
            throw ValidationException::withMessages(['database' => 'Migration SaaS belum dijalankan.']);
        }
        $planDefinition = config("saas_modules.plans.{$planKey}");
        if (! is_array($planDefinition)) {
            throw ValidationException::withMessages(['plan' => 'Paket SaaS tidak dikenal.']);
        }

        $moduleDefinitions = config('saas_modules.modules', []);
        $enabled = array_fill_keys(array_keys($moduleDefinitions), false);
        foreach ($planDefinition['modules'] as $key) {
            $enabled[$key] = true;
        }
        foreach ($overrides as $key => $value) {
            if (! array_key_exists($key, $enabled)) {
                throw ValidationException::withMessages(["modules.{$key}" => 'Modul tidak dikenal.']);
            }
            $enabled[$key] = (bool) $value;
        }

        foreach ($enabled as $key => $isEnabled) {
            if (! $isEnabled) {
                continue;
            }
            foreach ($moduleDefinitions[$key]['depends_on'] ?? [] as $dependency) {
                if (! ($enabled[$dependency] ?? false)) {
                    throw ValidationException::withMessages(["modules.{$key}" => "Modul {$key} membutuhkan {$dependency}."]);
                }
            }
        }

        $before = $this->forCompany($companyId);
        DB::transaction(function () use ($companyId, $planKey, $planDefinition, $enabled, $overrides, $actor, $subscriptionData) {
            $plan = SaasPlan::query()->updateOrCreate(
                ['key' => $planKey],
                ['name' => $planDefinition['name'], 'user_limit' => $planDefinition['user_limit'] ?? null,
                    'monthly_price' => $planDefinition['monthly_price'] ?? null, 'yearly_price' => $planDefinition['yearly_price'] ?? null,
                    'currency' => $planDefinition['currency'] ?? 'IDR', 'is_active' => true]
            );
            foreach (array_keys(config('saas_modules.modules', [])) as $key) {
                $plan->modules()->updateOrCreate(
                    ['module_key' => $key],
                    ['enabled' => in_array($key, $planDefinition['modules'], true)]
                );
            }
            $existingSubscription = CompanySubscription::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->first();
            $subscriptionValue = static function (string $key, mixed $default = null) use ($subscriptionData, $existingSubscription): mixed {
                if (array_key_exists($key, $subscriptionData)) {
                    return $subscriptionData[$key];
                }

                return $existingSubscription?->{$key} ?? $default;
            };
            CompanySubscription::query()->withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId],
                ['plan_id' => $plan->id, 'status' => $subscriptionValue('status', 'active'),
                    'licensed_users' => $subscriptionValue('licensed_users', $planDefinition['user_limit'] ?? null),
                    'trial_ends_at' => $subscriptionValue('trial_ends_at'),
                    'current_period_ends_at' => $subscriptionValue('current_period_ends_at'),
                    'external_reference' => $subscriptionValue('external_reference'),
                    'billing_email' => $subscriptionValue('billing_email')]
            );
            foreach ($enabled as $key => $value) {
                CompanyModuleEntitlement::query()->withoutGlobalScopes()->updateOrCreate(
                    ['company_id' => $companyId, 'module_key' => $key],
                    [
                        'enabled' => $value,
                        'source' => array_key_exists($key, $overrides) ? 'override' : 'plan',
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]
                );
            }
        });

        Cache::forget("company:{$companyId}:module-entitlements");
        $after = $this->forCompany($companyId);
        $this->auditLog->log('saas.entitlements.updated', $actor, 'Company', $companyId, [
            'plan' => $planKey,
            'subscription' => $subscriptionData,
            'before' => $before,
            'after' => $after,
        ], $request);

        return $after;
    }
}
