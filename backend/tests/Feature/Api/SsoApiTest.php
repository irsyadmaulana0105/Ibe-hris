<?php

use App\Models\SsoToken;
use App\Models\User;

uses()->group('api');

it('lists sso platforms for authenticated user', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $this->getJson('/api/v1/auth/sso/platforms', bearerHeaders($token))
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.key', 'erp')
        ->assertJsonPath('data.0.allowed', true)
        ->assertJsonPath('data.1.key', 'attendance')
        ->assertJsonPath('data.1.enabled', true)
        ->assertJsonPath('data.1.allowed', true);
});

it('rejects security monitor launch for non-superadmin users', function () {
    [$admin, $adminToken] = adminWithToken(['company_id' => 1]);

    $this->postJson('/api/v1/auth/sso/generate', [
        'target_app' => 'monitor',
    ], bearerHeaders($adminToken))->assertForbidden();
});

it('allows superadmin to launch the security monitor with its supported redirect transport', function () {
    $superadmin = User::factory()->create([
        'company_id' => 1,
        'group' => 'superadmin',
    ]);
    $superadminToken = $superadmin->createToken('test', ['admin', 'employee'])->plainTextToken;

    $response = $this->postJson('/api/v1/auth/sso/generate', [
        'target_app' => 'monitor',
    ], bearerHeaders($superadminToken));

    $response->assertOk()
        ->assertJsonPath('data.target_app', 'monitor');

    expect($response->json('data.redirect_url'))
        ->toStartWith('https://secure.ibereality.com/sso/callback?sso_token=')
        ->toContain('&target_app=monitor')
        ->and($response->json('data.post_url'))->toBeNull()
        ->and($response->json('data.post_fields'))->toBeNull();

    $this->postJson('/api/v1/auth/sso/exchange', [
        'target_app' => 'monitor',
        'sso_token' => $response->json('data.sso_token'),
    ])
        ->assertOk()
        ->assertJsonPath('data.platform.key', 'monitor')
        ->assertJsonPath('data.user.id', $superadmin->id);
});

it('generates and exchanges a one-time sso token for erp', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1, 'email' => 'admin@example.test']);

    $generate = $this->postJson('/api/v1/auth/sso/generate', [
        'target_app' => 'erp',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.target_app', 'erp');

    $plainToken = $generate->json('data.sso_token');

    expect(SsoToken::query()->count())->toBe(1);

    $this->postJson('/api/v1/auth/sso/exchange', [
        'target_app' => 'erp',
        'sso_token' => $plainToken,
    ])
        ->assertOk()
        ->assertJsonPath('data.platform.key', 'erp')
        ->assertJsonPath('data.user.email', 'admin@example.test');

    expect(SsoToken::query()->first()?->used_at)->not->toBeNull();
});

it('keeps server callback compatible while using fragments for spa callbacks', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $erpUrl = $this->postJson('/api/v1/auth/sso/generate', [
        'target_app' => 'erp',
    ], bearerHeaders($token))->assertOk()->json('data.redirect_url');

    $attendanceUrl = $this->postJson('/api/v1/auth/sso/generate', [
        'target_app' => 'attendance',
    ], bearerHeaders($token))->assertOk()->json('data.redirect_url');

    expect($erpUrl)
        ->toContain('/sso/callback?sso_token=')
        ->not->toContain('#sso_token=')
        ->and($attendanceUrl)
        ->toContain('/sso/callback#sso_token=')
        ->not->toContain('?sso_token=');
});

it('offers post transport for the server rendered erp callback', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $response = $this->postJson('/api/v1/auth/sso/generate', [
        'target_app' => 'erp',
    ], bearerHeaders($token))->assertOk();

    expect($response->json('data.post_url'))->toEndWith('/sso/callback')
        ->and($response->json('data.post_fields.target_app'))->toBe('erp')
        ->and($response->json('data.post_fields.sso_token'))->toHaveLength(80);
});

it('prevents caching and referrer leakage on the legacy erp callback bridge', function () {
    $user = User::factory()->admin()->create(['company_id' => 1]);
    $plainToken = str_repeat('b', 80);

    SsoToken::query()->create([
        'user_id' => $user->id,
        'target_app' => 'erp',
        'token_hash' => hash('sha256', $plainToken),
        'expires_at' => now()->addMinute(),
    ]);

    $response = $this->post('/sso/callback', [
        'sso_token' => $plainToken,
        'target_app' => 'erp',
    ])
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private');

    expect($response->getContent())
        ->toContain("window.location.replace('/erp-next/');")
        ->toContain("localStorage.setItem('ibe-erp-next:token'")
        ->toContain("localStorage.setItem('ibe_hris_token'")
        ->not->toContain("window.location.replace('/admin/');");
});

it('rejects generating an unavailable platform token', function () {
    // Plain admin (group='admin', not superadmin) with a factory-generated fake
    // email (not @ibereality.com) and no mailbox creds is NOT allowed on the
    // webmail platform → SsoService::isPlatformAllowed() returns false → 403.
    [$admin, $token] = adminWithToken([
        'company_id' => 1,
        'email' => 'admin@example.test',
        'mailbox_password_encrypted' => null,
    ]);

    $this->postJson('/api/v1/auth/sso/generate', [
        'target_app' => 'webmail',
    ], bearerHeaders($token))
        ->assertForbidden();
});

it('rejects exchange for expired or reused tokens', function () {
    $user = User::factory()->admin()->create(['company_id' => 1]);
    $plainToken = str_repeat('a', 80);

    SsoToken::query()->create([
        'user_id' => $user->id,
        'target_app' => 'erp',
        'token_hash' => hash('sha256', $plainToken),
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/v1/auth/sso/exchange', [
        'target_app' => 'erp',
        'sso_token' => $plainToken,
    ])->assertNotFound();

    SsoToken::query()->delete();

    SsoToken::query()->create([
        'user_id' => $user->id,
        'target_app' => 'erp',
        'token_hash' => hash('sha256', $plainToken),
        'expires_at' => now()->addMinute(),
        'used_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/sso/exchange', [
        'target_app' => 'erp',
        'sso_token' => $plainToken,
    ])->assertNotFound();
});
