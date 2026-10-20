<?php

use App\Models\Company;
use App\Models\CompanyModuleEntitlement;
use App\Models\CompanySubscription;
use App\Models\User;
use App\Services\ModuleEntitlementService;
use Illuminate\Validation\ValidationException;

uses()->group('api', 'saas');

it('keeps every module enabled for a legacy company', function () {
    [$admin, $token] = adminWithToken();

    $response = $this->getJson('/api/v1/auth/me', bearerHeaders($token));

    $response->assertOk()
        ->assertJsonPath('data.module_entitlements.people', true)
        ->assertJsonPath('data.module_entitlements.marketing', true)
        ->assertJsonPath('data.module_entitlements.finance', true)
        ->assertJsonPath('data.module_entitlements.treasury', true)
        ->assertJsonPath('data.module_entitlements.production', true)
        ->assertJsonPath('data.module_entitlements.distribution', true)
        ->assertJsonPath('data.module_entitlements.warehouse', true);
});

it('allows a superadmin to configure a plan and module overrides', function () {
    $admin = User::factory()->admin(true)->create(['company_id' => 1]);
    $token = $admin->createToken('test', ['admin', 'employee'])->plainTextToken;

    $response = $this->putJson('/api/v1/admin/saas/entitlements', [
        'plan' => 'professional',
        'modules' => ['marketing' => false, 'sales' => false],
    ], bearerHeaders($token));

    $response->assertOk()
        ->assertJsonPath('data.plan', 'professional')
        ->assertJsonPath('data.modules.people', true)
        ->assertJsonPath('data.modules.marketing', false)
        ->assertJsonPath('data.modules.sales', false)
        ->assertJsonPath('data.modules.production', false);

    expect(CompanyModuleEntitlement::query()->where('company_id', 1)->count())->toBe(9);
});

it('manages a tenant commercial subscription lifecycle', function () {
    $tenant = Company::query()->create(['name' => 'Tenant Uji', 'slug' => 'tenant-uji']);
    $admin = User::factory()->admin(true)->create(['company_id' => 1]);
    $token = $admin->createToken('test', ['admin', 'employee'])->plainTextToken;

    $this->putJson('/api/v1/admin/saas/entitlements', [
        'company_id' => $tenant->id,
        'plan' => 'professional',
        'status' => 'trialing',
        'licensed_users' => 50,
        'trial_ends_at' => now()->addDays(14)->toDateString(),
        'billing_email' => 'billing@tenant.test',
        'external_reference' => 'MANUAL-001',
    ], bearerHeaders($token))->assertOk()
        ->assertJsonPath('data.company_id', $tenant->id)
        ->assertJsonPath('data.subscription.status', 'trialing')
        ->assertJsonPath('data.subscription.licensed_users', 50);

    $this->getJson('/api/v1/admin/saas/entitlements?company_id='.$tenant->id, bearerHeaders($token))
        ->assertOk()->assertJsonPath('data.plan', 'professional')
        ->assertJsonPath('data.subscription.billing_email', 'billing@tenant.test');

    $this->putJson('/api/v1/admin/saas/entitlements', [
        'company_id' => $tenant->id,
        'plan' => 'manufacturing',
    ], bearerHeaders($token))->assertOk()
        ->assertJsonPath('data.subscription.status', 'trialing')
        ->assertJsonPath('data.subscription.licensed_users', 50)
        ->assertJsonPath('data.subscription.billing_email', 'billing@tenant.test');

    $subscription = CompanySubscription::query()->where('company_id', $tenant->id)->firstOrFail();
    expect($subscription->external_reference)->toBe('MANUAL-001')
        ->and($subscription->trial_ends_at)->not->toBeNull();
});

it('rejects an enabled module when its dependency is disabled', function () {
    $admin = User::factory()->admin(true)->create(['company_id' => 1]);
    $token = $admin->createToken('test', ['admin', 'employee'])->plainTextToken;

    $this->putJson('/api/v1/admin/saas/entitlements', [
        'plan' => 'manufacturing',
        'modules' => ['warehouse' => false, 'production' => true],
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('modules.production');
});

it('forbids a regular admin from changing SaaS configuration', function () {
    [, $token] = adminWithToken();

    $this->putJson('/api/v1/admin/saas/entitlements', [
        'plan' => 'starter',
    ], bearerHeaders($token))->assertForbidden();
});

it('enforces licensed user capacity and disables suspended subscriptions', function () {
    $company = Company::query()->create(['name' => 'Tenant Kuota', 'slug' => 'tenant-kuota']);
    $admin = User::factory()->admin(true)->create(['company_id' => $company->id]);
    $service = app(ModuleEntitlementService::class);
    $service->configure($company->id, 'starter', [], $admin, null, ['status' => 'active', 'licensed_users' => 1]);

    expect(fn () => $service->assertUserCapacity($company->id))->toThrow(ValidationException::class);

    $service->configure($company->id, 'starter', [], $admin, null, ['status' => 'suspended', 'licensed_users' => 1]);
    expect($service->forCompany($company->id)['people'])->toBeFalse();
});

it('blocks commercial APIs when their SaaS modules are disabled', function () {
    [$admin, $token] = adminWithToken();
    app(ModuleEntitlementService::class)->configure(1, 'starter', [], $admin);
    $headers = bearerHeaders($token);

    $this->getJson('/api/v1/admin/sales-orders', $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'MODULE_NOT_ENABLED')
        ->assertJsonPath('module', 'sales');

    $this->getJson('/api/v1/admin/purchase-requests', $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'MODULE_NOT_ENABLED')
        ->assertJsonPath('module', 'procurement');

    $this->getJson('/api/v1/admin/delivery-fleets', $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'MODULE_NOT_ENABLED')
        ->assertJsonPath('module', 'distribution');
});

it('keeps commercial APIs accessible for legacy tenants without entitlement rows', function () {
    [, $token] = adminWithToken();
    $headers = bearerHeaders($token);

    $this->getJson('/api/v1/admin/sales-orders', $headers)->assertOk();
    $this->getJson('/api/v1/admin/purchase-requests', $headers)->assertOk();
    $this->getJson('/api/v1/admin/delivery-fleets', $headers)->assertOk();
});
