<?php

use App\Models\AuditLog;
use App\Models\GovernanceRecord;

uses()->group('api', 'governance');

it('allows admins to create and list company governance evidence', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $created = $this->postJson('/api/v1/admin/governance/records', [
        'type' => 'risk', 'title' => 'Kebocoran data payroll', 'description' => 'Risiko akses tidak sah.',
        'severity' => 'high', 'likelihood' => 3, 'impact' => 5, 'owner_id' => $admin->id,
        'due_date' => now()->addDays(14)->toDateString(),
        'details' => ['treatment' => 'Review least privilege'],
    ], bearerHeaders($token));

    $created->assertCreated()
        ->assertJsonPath('data.type', 'risk')
        ->assertJsonPath('data.reference', 'RSK-'.now()->format('Y').'-0001');

    $this->getJson('/api/v1/admin/governance/records?type=risk', bearerHeaders($token))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'governance.record.created')->exists())->toBeTrue();
});

it('keeps governance records isolated by company', function () {
    [, $token] = adminWithToken(['company_id' => 1]);
    GovernanceRecord::withoutGlobalScopes()->create([
        'company_id' => 2, 'type' => 'capa', 'reference' => 'CAPA-2026-0001',
        'title' => 'Data perusahaan lain', 'status' => 'open',
    ]);

    $this->getJson('/api/v1/admin/governance/records', bearerHeaders($token))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('updates status and preserves audit evidence', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $record = GovernanceRecord::withoutGlobalScopes()->create([
        'company_id' => 1, 'type' => 'recovery_test', 'reference' => 'DRT-2026-0001',
        'title' => 'Restore database staging', 'status' => 'scheduled',
    ]);

    $this->patchJson("/api/v1/admin/governance/records/{$record->id}", [
        'status' => 'verified',
        'evidence' => ['rto_minutes' => 42, 'rpo_minutes' => 10, 'result' => 'pass'],
    ], bearerHeaders($token))->assertOk()->assertJsonPath('data.status', 'verified');

    expect($record->fresh()->closed_at)->not->toBeNull();
    expect(AuditLog::withoutGlobalScopes()->where('action', 'governance.record.updated')->exists())->toBeTrue();
});

it('forbids regular employees from governance records', function () {
    [, $employeeToken] = employeeWithToken(['company_id' => 1]);
    $this->getJson('/api/v1/admin/governance/records', bearerHeaders($employeeToken))->assertForbidden();
});

it('validates governance values', function () {
    [, $adminToken] = adminWithToken(['company_id' => 1]);
    $this->postJson('/api/v1/admin/governance/records', [
        'type' => 'unknown', 'title' => 'Invalid', 'likelihood' => 9,
    ], bearerHeaders($adminToken))->assertUnprocessable()->assertJsonValidationErrors(['type', 'likelihood']);
});

it('returns governance summary for compliance dashboard', function () {
    [, $token] = adminWithToken(['company_id' => 1]);
    GovernanceRecord::withoutGlobalScopes()->insert([
        ['company_id' => 1, 'type' => 'capa', 'reference' => 'CAPA-1', 'title' => 'Open CAPA', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => 1, 'type' => 'capa', 'reference' => 'CAPA-2', 'title' => 'Closed CAPA', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->getJson('/api/v1/admin/governance/summary', bearerHeaders($token))
        ->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.open', 1)
        ->assertJsonPath('data.by_type.capa.closed', 1);
});
