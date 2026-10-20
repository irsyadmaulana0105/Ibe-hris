<?php

use App\Models\User;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BlockInactiveUser;
use Illuminate\Support\Facades\Route;

uses()->group('api');

it('rejects unsupported API content types with a traceable response', function () {
    $this->call('POST', '/api/v1/auth/login', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
        'CONTENT_LENGTH' => '7',
        'HTTP_X_CORRELATION_ID' => 'policy-content-001',
    ], 'invalid')
        ->assertStatus(415)
        ->assertHeader('X-Correlation-Id', 'policy-content-001')
        ->assertJsonPath('correlation_id', 'policy-content-001');
});

it('rejects an authenticated account without tenant context', function () {
    $user = User::factory()->admin(true)->create(['company_id' => null]);
    $token = $user->createToken('tenant-policy-test', ['admin', 'employee'])->plainTextToken;

    $this->getJson('/api/v1/company/branding', bearerHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('message', 'Authenticated account has no active tenant context.');
});

it('adds version isolation and sensitive cache headers', function () {
    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'missing@example.test',
        'password' => 'incorrect-password',
    ])->assertHeader('X-API-Version', '1')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-site')
        ->assertHeader('Origin-Agent-Cluster', '?1');
});

it('protects every admin API route with the admin middleware', function () {
    $adminRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/'));

    expect($adminRoutes)->not->toBeEmpty();

    $unprotected = $adminRoutes
        ->reject(function ($route) {
            $middleware = $route->gatherMiddleware();

            return in_array('admin', $middleware, true)
                || in_array(AdminMiddleware::class, $middleware, true);
        })
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('forbids employees from the admin API surface', function () {
    [, $token] = employeeWithToken(['company_id' => 1]);

    $this->getJson('/api/v1/admin/company-banks', bearerHeaders($token))
        ->assertForbidden();
});

it('places the inactive identity guard after authentication on every bearer route', function () {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(function ($route) {
            $middleware = $route->gatherMiddleware();
            $authIndex = array_search('auth:sanctum', $middleware, true);
            $guardIndex = array_search('block.inactive', $middleware, true);

            if ($guardIndex === false) {
                $guardIndex = array_search(BlockInactiveUser::class, $middleware, true);
            }

            return $guardIndex !== false && $authIndex !== false && $guardIndex > $authIndex;
        })
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('revokes a manually issued bearer token when the employee is inactive', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'employment_status' => 'resigned',
        'resigned_at' => now(),
    ]);
    $token = $user->createToken('stale-device')->plainTextToken;

    $this->getJson('/api/v1/auth/me', bearerHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('inactive', true);

    expect($user->tokens()->count())->toBe(0);
});

it('keeps active identities and public authentication endpoints available', function () {
    [, $token] = employeeWithToken(['company_id' => 1]);

    $this->getJson('/api/v1/auth/me', bearerHeaders($token))
        ->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'missing@example.test',
        'password' => 'incorrect-password',
    ])->assertUnprocessable();
});
