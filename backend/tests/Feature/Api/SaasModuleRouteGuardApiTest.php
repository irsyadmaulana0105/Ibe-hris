<?php

use App\Models\Company;
use App\Models\CompanyModuleEntitlement;
use App\Models\User;
use App\Produksi\Http\Middleware\VendorTokenMiddleware;
use App\Produksi\Models\Vendor;
use App\Produksi\Models\VendorAccessToken;
use App\Services\EnterpriseAuthorizationService;
use App\Services\ModuleEntitlementService;
use App\Services\ModuleRouteManifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses()->group('api', 'saas', 'enterprise-rbac');

beforeEach(function () {
    Cache::flush();
});

function grantModuleRbacCapability(User $user, string $capability, string $suffix): void
{
    $roleId = DB::table('rbac_roles')->insertGetId([
        'company_id' => $user->company_id,
        'key' => "module_guard_{$suffix}",
        'label' => "Module guard {$suffix}",
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $capabilityId = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => $user->company_id,
        'module' => explode('.', $capability)[0],
        'capability_key' => $capability,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('rbac_role_capability')->insert([
        'role_id' => $roleId,
        'capability_id' => $capabilityId,
    ]);
    DB::table('rbac_user_assignments')->insert([
        'id' => (string) Str::ulid(),
        'company_id' => $user->company_id,
        'user_id' => $user->id,
        'role_id' => $roleId,
        'status' => 'active',
        'valid_from' => now()->subMinute(),
        'valid_until' => now()->addDay(),
        'reason' => 'SaaS module guard test',
        'source' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('maps every protected modular route unambiguously and attaches the route guard', function () {
    $manifest = app(ModuleRouteManifest::class);
    $routes = collect(Route::getRoutes()->getRoutes());

    $representatives = [
        'api/v1/admin/employees' => 'people',
        'api/v1/auth/face-setup' => 'people',
        'api/v1/crm/leads' => 'marketing',
        'api/v1/finance/journals' => 'finance',
        'api/v1/admin/work-orders' => 'production',
        'api/v1/admin/inventory/stock' => 'warehouse',
        'api/v1/admin/quotations' => 'sales',
        'api/v1/admin/purchase-orders' => 'procurement',
        'api/v1/admin/shipments' => 'distribution',
    ];

    foreach ($representatives as $uri => $module) {
        expect($manifest->moduleForUri($uri))->toBe($module);
        $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri);
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('module.routes');
    }

    foreach (config('saas_module_routes') as $module => $patterns) {
        expect(config("saas_modules.modules.{$module}"))->not->toBeNull();
        foreach ($patterns as $pattern) {
            expect($routes->contains(fn ($route) => Str::is($pattern, $route->uri())))
                ->toBeTrue("Manifest pattern [{$pattern}] does not match a registered route.");
        }
    }
});

it('blocks representative reads and mutations for every disabled module', function () {
    [$admin, $token] = adminWithToken();
    app(ModuleEntitlementService::class)->configure(1, 'starter', [], $admin);
    $headers = bearerHeaders($token);

    foreach ([
        ['GET', '/api/v1/crm/leads', 'marketing'],
        ['POST', '/api/v1/crm/leads', 'marketing'],
        ['GET', '/api/v1/finance/accounts', 'finance'],
        ['POST', '/api/v1/finance/journals', 'finance'],
        ['GET', '/api/v1/admin/work-orders', 'production'],
        ['POST', '/api/v1/admin/work-orders', 'production'],
        ['GET', '/api/v1/admin/warehouses', 'warehouse'],
        ['POST', '/api/v1/admin/warehouses', 'warehouse'],
    ] as [$method, $uri, $module]) {
        $this->json($method, $uri, [], $headers)
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_NOT_ENABLED')
            ->assertJsonPath('module', $module);
    }
});

it('blocks people reads and mutations when the people module is disabled', function () {
    [$admin, $token] = adminWithToken();
    app(ModuleEntitlementService::class)->configure(1, 'enterprise', ['people' => false], $admin);
    $headers = bearerHeaders($token);

    $this->getJson('/api/v1/admin/employees', $headers)
        ->assertForbidden()->assertJsonPath('module', 'people');
    $this->postJson('/api/v1/admin/employees', [], $headers)
        ->assertForbidden()->assertJsonPath('module', 'people');
});

it('blocks a valid vendor progress token when the production module is disabled', function () {
    [$admin] = adminWithToken();
    app(ModuleEntitlementService::class)->configure(1, 'starter', [], $admin);
    $vendor = Vendor::query()->create(['company_id' => 1, 'name' => 'Vendor SaaS Guard']);
    VendorAccessToken::query()->create([
        'company_id' => 1,
        'vendor_id' => $vendor->id,
        'token' => 'vendor-module-guard-token',
        'expires_at' => now()->addHour(),
    ]);

    $request = Request::create('/api/v1/vendor/vendor-module-guard-token/orders', 'GET');
    $route = new Illuminate\Routing\Route(
        ['GET'],
        'api/v1/vendor/{token}/orders',
        fn () => response()->json(['ok' => true]),
    );
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    $response = app(VendorTokenMiddleware::class)->handle(
        $request,
        fn () => response()->json(['ok' => true]),
    );

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['code'])->toBe('MODULE_NOT_ENABLED')
        ->and(VendorAccessToken::query()->firstOrFail()->last_used_at)->toBeNull();
});

it('uses the authenticated tenant entitlement and cannot be bypassed with a company query', function () {
    $other = Company::query()->create(['name' => 'Other Enterprise', 'slug' => 'other-enterprise']);
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $service = app(ModuleEntitlementService::class);
    $service->configure(1, 'starter', [], $admin);
    $service->configure($other->id, 'enterprise', [], $admin);

    $this->getJson('/api/v1/finance/accounts?company_id='.$other->id, bearerHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('code', 'MODULE_NOT_ENABLED')
        ->assertJsonPath('module', 'finance');
});

it('denies every module for a suspended tenant before controller validation', function () {
    [$admin, $token] = adminWithToken();
    app(ModuleEntitlementService::class)->configure(
        1,
        'enterprise',
        [],
        $admin,
        null,
        ['status' => 'suspended'],
    );

    $this->postJson('/api/v1/admin/employees', [], bearerHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('code', 'MODULE_NOT_ENABLED')
        ->assertJsonPath('module', 'people');
});

it('computes effective module switches from configured dependencies', function () {
    [$admin] = adminWithToken();
    $service = app(ModuleEntitlementService::class);
    $service->configure(1, 'manufacturing', [], $admin);

    CompanyModuleEntitlement::query()->withoutGlobalScopes()
        ->where('company_id', 1)
        ->where('module_key', 'warehouse')
        ->update(['enabled' => false]);
    Cache::forget('company:1:module-entitlements');

    $modules = $service->forCompany(1);
    expect($modules['warehouse'])->toBeFalse()
        ->and($modules['production'])->toBeFalse()
        ->and($modules['distribution'])->toBeFalse()
        ->and($modules['procurement'])->toBeFalse();
});

it('keeps explicit shadow fallback but requires granular finance capability in enforce mode', function () {
    [$legacyFinance, $legacyToken] = adminWithToken(['group' => 'finance']);
    config()->set('enterprise_rbac.mode', 'shadow');
    $this->postJson('/api/v1/finance/journals', [], bearerHeaders($legacyToken))
        ->assertUnprocessable();

    $enforced = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $enforcedToken = $enforced->createToken('enforced-finance', ['admin', 'employee'])->plainTextToken;
    config()->set('enterprise_rbac.mode', 'enforce');
    app('auth')->forgetGuards();
    app()->forgetInstance(EnterpriseAuthorizationService::class);

    $this->postJson('/api/v1/finance/journals', [], bearerHeaders($enforcedToken))
        ->assertForbidden();

    grantModuleRbacCapability($enforced, 'finance.journal.create', 'journal_create');
    app('auth')->forgetGuards();
    app()->forgetInstance(EnterpriseAuthorizationService::class);

    $this->postJson('/api/v1/finance/journals', [], bearerHeaders($enforcedToken))
        ->assertUnprocessable();
    $this->postJson('/api/v1/finance/invoices', [], bearerHeaders($enforcedToken))
        ->assertForbidden();
});

it('fails closed in enforce mode when the enterprise policy schema is incomplete', function () {
    $finance = User::factory()->create(['company_id' => 1, 'group' => 'finance']);
    config()->set('enterprise_rbac.mode', 'enforce');
    Schema::drop('rbac_assignment_scopes');
    app()->forgetInstance(EnterpriseAuthorizationService::class);

    expect(app(EnterpriseAuthorizationService::class)->allows($finance, 'finance.module.access'))
        ->toBeFalse();
});
