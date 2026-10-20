<?php

use App\Models\AuditLog;
use App\Models\TreasuryBankAccount;
use App\Models\TreasuryCashTransaction;
use App\Models\TreasuryPayment;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'treasury');

function treasuryAccount(int $companyId, string $creatorId): TreasuryBankAccount
{
    return TreasuryBankAccount::withoutGlobalScopes()->create([
        'company_id' => $companyId, 'name' => 'Operasional', 'bank_name' => 'BCA',
        'account_number' => '1234567890', 'currency' => 'IDR',
        'opening_balance' => 1000000, 'current_balance' => 1000000,
        'is_active' => true, 'created_by' => $creatorId,
    ]);
}

it('runs maker checker executor payment and posts cash ledger once', function () {
    [$maker, $makerToken] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    [$checker, $checkerToken] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    [$executor, $executorToken] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    expect($maker->id)->not->toBe($checker->id)->and($checker->id)->not->toBe($executor->id);
    $account = treasuryAccount(1, $maker->id);

    $created = $this->postJson('/api/v1/treasury/payments', [
        'bank_account_id' => $account->id, 'direction' => 'outbound', 'counterparty' => 'Vendor A',
        'amount' => 250000, 'scheduled_for' => now()->toDateString(), 'description' => 'Invoice INV-1',
    ], bearerHeaders($makerToken) + ['Idempotency-Key' => 'treasury-create-1'])->assertCreated();
    $id = $created->json('data.id');

    $this->postJson("/api/v1/treasury/payments/{$id}/submit", [], bearerHeaders($makerToken) + ['Idempotency-Key' => 'treasury-submit-1'])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->postJson("/api/v1/treasury/payments/{$id}/approve", [], bearerHeaders($checkerToken) + ['Idempotency-Key' => 'treasury-approve-1'])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->postJson("/api/v1/treasury/payments/{$id}/execute", ['bank_reference' => 'BCA-TRX-001'], bearerHeaders($executorToken) + ['Idempotency-Key' => 'treasury-execute-1'])
        ->assertOk()->assertJsonPath('data.status', 'executed');

    expect((float) $account->fresh()->current_balance)->toBe(750000.0)
        ->and(TreasuryCashTransaction::withoutGlobalScopes()->where('payment_id', $id)->count())->toBe(1)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'treasury.payment.executed')->exists())->toBeTrue();
});

it('enforces tenant isolation and separation of duties', function () {
    [$maker, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $account = treasuryAccount(1, $maker->id);
    $payment = TreasuryPayment::withoutGlobalScopes()->create([
        'company_id' => 1, 'reference' => 'PAY-2026-999999', 'bank_account_id' => $account->id,
        'direction' => 'outbound', 'counterparty' => 'Vendor', 'amount' => 1000, 'currency' => 'IDR',
        'scheduled_for' => now(), 'status' => 'submitted', 'maker_id' => $maker->id,
    ]);

    $this->postJson("/api/v1/treasury/payments/{$payment->id}/approve", [], bearerHeaders($token) + ['Idempotency-Key' => 'self-approve'])->assertUnprocessable();

    DB::table('companies')->insert(['id' => 2, 'name' => 'Other Tenant', 'slug' => 'other-tenant', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    [, $otherTenantToken] = adminWithToken(['company_id' => 2, 'group' => 'finance']);
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/treasury/payments', bearerHeaders($otherTenantToken))
        ->assertOk()->assertJsonPath('meta.total', 0);
});

it('rejects employees and replays an idempotent create response', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $account = treasuryAccount(1, $finance->id);
    $payload = ['bank_account_id' => $account->id, 'direction' => 'inbound', 'counterparty' => 'Customer', 'amount' => 5000, 'scheduled_for' => now()->toDateString()];
    $headers = bearerHeaders($token) + ['Idempotency-Key' => 'treasury-replay-1'];
    $first = $this->postJson('/api/v1/treasury/payments', $payload, $headers)->assertCreated();
    $second = $this->postJson('/api/v1/treasury/payments', $payload, $headers)->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(TreasuryPayment::withoutGlobalScopes()->count())->toBe(1);

    [, $employeeToken] = employeeWithToken(['company_id' => 1]);
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/treasury/payments', bearerHeaders($employeeToken))->assertForbidden();
});
