<?php

use App\Models\AuditLog;
use App\Models\CompanyModuleEntitlement;

it('returns a tenant scoped redacted module summary for an admin', function () {
    [, $token] = adminWithToken(['company_id' => 1]);

    $response = $this->withHeaders([
        ...bearerHeaders($token),
        'X-Company-Id' => '1',
        'X-AI-Purpose' => 'management-dashboard',
        'X-Correlation-Id' => 'test-correlation',
    ])->getJson('/api/v1/ai/modules/people/summary');

    $response->assertOk()
        ->assertJsonPath('data.module', 'people')
        ->assertJsonPath('data.company_id', 1)
        ->assertJsonStructure(['data' => ['generated_at', 'metrics', 'alerts']]);

    $json = strtolower($response->getContent());
    expect($json)->not->toContain('password')->not->toContain('bank_account')->not->toContain('@');
    expect(AuditLog::withoutGlobalScopes()->where('action', 'ai.module_summary.read')->where('company_id', 1)->exists())->toBeTrue();
});

it('rejects an AI summary requested for another tenant', function () {
    [, $token] = adminWithToken(['company_id' => 1]);

    $this->withHeaders([...bearerHeaders($token), 'X-Company-Id' => '2'])
        ->getJson('/api/v1/ai/modules/people/summary')->assertForbidden();
});

it('rejects a disabled module', function () {
    [, $token] = adminWithToken(['company_id' => 1]);
    foreach (array_keys(config('saas_modules.modules')) as $key) {
        CompanyModuleEntitlement::withoutGlobalScopes()->create([
            'company_id' => 1,
            'module_key' => $key,
            'enabled' => $key !== 'marketing',
            'source' => 'override',
        ]);
    }

    $this->getJson('/api/v1/ai/modules/marketing/summary', bearerHeaders($token))->assertForbidden();
});

it('rejects a regular employee', function () {
    [, $token] = employeeWithToken(['company_id' => 1]);

    $this->getJson('/api/v1/ai/modules/people/summary', bearerHeaders($token))->assertForbidden();
});
