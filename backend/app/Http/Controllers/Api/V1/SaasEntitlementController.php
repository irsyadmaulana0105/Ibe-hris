<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ModuleEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaasEntitlementController extends ApiController
{
    public function __construct(private readonly ModuleEntitlementService $entitlements) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireAdmin($user);
        $companyId = $user->isSuperadmin && $request->integer('company_id') ? $request->integer('company_id') : $user->company_id;
        $subscription = $this->entitlements->subscriptionForCompany($companyId);

        return $this->success([
            'plan' => $subscription?->plan?->key,
            'status' => $subscription?->status ?? 'legacy',
            'company_id' => $companyId,
            'subscription' => $subscription,
            'modules' => $this->entitlements->forCompany($companyId),
            'catalog' => $this->entitlements->catalog(),
            'legacy_default' => $subscription === null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperadmin, 403, 'Hanya superadmin dapat mengubah paket SaaS.');

        $keys = array_keys(config('saas_modules.modules', []));
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(config('saas_modules.plans', [])))],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['boolean'],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'status' => ['sometimes', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'cancelled'])],
            'licensed_users' => ['nullable', 'integer', 'min:1'],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
        ]);
        foreach (array_keys($data['modules'] ?? []) as $key) {
            abort_unless(in_array($key, $keys, true), 422, "Modul {$key} tidak dikenal.");
        }

        $companyId = $data['company_id'] ?? $user->company_id;
        $subscriptionData = array_intersect_key($data, array_flip(['status', 'licensed_users', 'trial_ends_at', 'current_period_ends_at', 'external_reference', 'billing_email']));
        $modules = $this->entitlements->configure(
            $companyId,
            $data['plan'],
            $data['modules'] ?? [],
            $user,
            $request,
            $subscriptionData,
        );

        return $this->success(['company_id' => $companyId, 'plan' => $data['plan'], 'modules' => $modules, 'subscription' => $this->entitlements->subscriptionForCompany($companyId)], 'Konfigurasi SaaS berhasil disimpan.');
    }
}
