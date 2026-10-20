<?php

use App\Models\User;

uses()->group('api');

function apiContractSuperadminToken(): string
{
    $user = User::factory()->admin(true)->create(['company_id' => 1]);

    return $user->createToken('api-contract-test', ['admin', 'employee'])->plainTextToken;
}

it('keeps authentication and validation failures in the documented json contract', function () {
    $this->getJson('/api/v1/admin/webhooks', [
        'Accept' => 'application/json',
        'X-Correlation-Id' => 'contract-auth-001',
    ])->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Correlation-Id', 'contract-auth-001')
        ->assertHeader('X-API-Version', '1')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-site')
        ->assertJsonStructure(['message']);

    $token = apiContractSuperadminToken();
    $this->postJson('/api/v1/admin/webhooks', [], [
        ...bearerHeaders($token),
        'X-Correlation-Id' => 'contract-validation-001',
    ])->assertUnprocessable()
        ->assertHeader('X-Correlation-Id', 'contract-validation-001')
        ->assertJsonStructure(['message', 'errors' => ['name', 'url', 'events']]);
});

it('keeps webhook delivery pagination in the documented envelope', function () {
    $token = apiContractSuperadminToken();

    $this->getJson('/api/v1/admin/webhooks/deliveries/log?per_page=5', bearerHeaders($token))
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
        ->assertJsonPath('meta.per_page', 5);
});

it('rejects malformed idempotency keys with a traceable json error', function () {
    $token = apiContractSuperadminToken();

    $this->postJson('/api/v1/admin/webhooks', [
        'name' => 'Contract target',
        'url' => 'https://contract.example.test/webhook',
        'events' => ['integration.test'],
    ], [
        ...bearerHeaders($token),
        'Idempotency-Key' => 'short',
        'X-Correlation-Id' => 'contract-idempotency-001',
    ])->assertUnprocessable()
        ->assertHeader('X-Correlation-Id', 'contract-idempotency-001')
        ->assertJsonStructure(['message']);
});
