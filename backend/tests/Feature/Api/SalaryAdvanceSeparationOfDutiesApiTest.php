<?php

use App\Keuangan\Models\Account;
use App\Keuangan\Models\Journal;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function financeWithToken(array $overrides = []): array
{
    $finance = User::factory()->create(array_merge([
        'group' => 'finance',
        'company_id' => 1,
    ], $overrides));
    $token = $finance->createToken('test', ['admin', 'employee'])->plainTextToken;

    return [$finance, $token];
}

function seedSalaryAdvanceCashAccounts(int $companyId = 1): void
{
    Account::withoutGlobalScopes()->create([
        'company_id' => $companyId,
        'code' => '1110',
        'name' => 'Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_header' => false,
        'is_active' => true,
    ]);
}

it('separates HR approval from finance settlement and posts a balanced journal', function () {
    [$employee, $employeeToken] = employeeWithToken();
    [$hr, $hrToken] = adminWithToken();
    [$finance, $financeToken] = financeWithToken();
    seedSalaryAdvanceCashAccounts();

    $submission = $this->postJson('/api/v1/salary-advances', [
        'date' => now()->toDateString(),
        'amount' => 750000,
        'purpose' => 'Kasbon operasional lapangan',
    ], bearerHeaders($employeeToken))->assertCreated();
    $id = $submission->json('data.id');

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/salary-advances/{$id}/approve", [
        'notes' => 'Kebutuhan terverifikasi HR.',
    ], bearerHeaders($hrToken))
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.journal_id', null);

    $approved = SalaryAdvance::withoutGlobalScopes()->findOrFail($id);
    expect($approved->hr_approved_by)->toBe($hr->id)
        ->and($approved->settled_at)->toBeNull()
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/salary-advances/{$id}/settle", [
        'reference' => 'TRF-UAT-0001',
    ], bearerHeaders($financeToken))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payment_reference', 'TRF-UAT-0001');

    $paid = SalaryAdvance::withoutGlobalScopes()->findOrFail($id);
    $journal = Journal::withoutGlobalScopes()->findOrFail($paid->journal_id);
    expect($paid->settled_by)->toBe($finance->id)
        ->and($journal->status)->toBe('posted')
        ->and((float) $journal->total_debit)->toBe(750000.0)
        ->and((float) $journal->total_credit)->toBe(750000.0)
        ->and(DB::table('audit_logs')->where('action', 'salary_advance.hr_approved')->where('entity_id', $id)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'salary_advance.settled')->where('entity_id', $id)->exists())->toBeTrue();
});

it('does not settle or mutate an approved kasbon when the journal hard gate fails', function () {
    [$employee, $employeeToken] = employeeWithToken();
    [$hr, $hrToken] = adminWithToken();
    [, $financeToken] = financeWithToken();

    $submission = $this->postJson('/api/v1/salary-advances', [
        'date' => now()->toDateString(),
        'amount' => 250000,
        'purpose' => 'Kasbon tanpa akun kas',
    ], bearerHeaders($employeeToken))->assertCreated();
    $id = $submission->json('data.id');

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/salary-advances/{$id}/approve", [], bearerHeaders($hrToken))->assertOk();

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/salary-advances/{$id}/settle", [
        'reference' => 'TRF-FAIL-001',
    ], bearerHeaders($financeToken))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('journal');

    $unchanged = SalaryAdvance::withoutGlobalScopes()->findOrFail($id);
    expect($unchanged->status)->toBe('approved')
        ->and($unchanged->settled_at)->toBeNull()
        ->and($unchanged->journal_id)->toBeNull()
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'salary_advance.settled')->where('entity_id', $id)->exists())->toBeFalse();
});

it('blocks the HR approver from also performing finance settlement', function () {
    [$employee, $employeeToken] = employeeWithToken();
    [$superadmin, $token] = adminWithToken(['group' => 'superadmin']);
    seedSalaryAdvanceCashAccounts();

    $submission = $this->postJson('/api/v1/salary-advances', [
        'date' => now()->toDateString(),
        'amount' => 300000,
        'purpose' => 'Kasbon uji four eyes',
    ], bearerHeaders($employeeToken))->assertCreated();
    $id = $submission->json('data.id');

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/salary-advances/{$id}/approve", [], bearerHeaders($token))->assertOk();

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/salary-advances/{$id}/settle", [
        'reference' => 'TRF-SOD-001',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('segregation_of_duties');

    $advance = SalaryAdvance::withoutGlobalScopes()->findOrFail($id);
    expect($advance->hr_approved_by)->toBe($superadmin->id)
        ->and($advance->status)->toBe('approved')
        ->and($advance->settled_at)->toBeNull()
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0);
});
