<?php

use App\Jobs\SendPayrollSlipEmail;
use App\Keuangan\Models\Account;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

uses()->group('api', 'payroll');

it('lists payrolls for payroll admins by company and period', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user', 'name' => 'Payroll Employee']);
    $employeeTwo = User::factory()->create(['company_id' => 1, 'group' => 'user', 'name' => 'Payroll Employee Two']);
    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'month' => 4,
        'year' => 2026,
        'gross_total' => 8000000,
        'deduction_total' => 1000000,
        'net_total' => 7000000,
        'status' => 'approved',
    ]);
    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employeeTwo->id,
        'month' => 4,
        'year' => 2026,
        'gross_total' => 5000000,
        'deduction_total' => 500000,
        'net_total' => 4500000,
        'status' => 'draft',
    ]);

    $otherCompany = Company::create(['name' => 'Other Company', 'slug' => 'payroll-other-company']);
    $otherEmployee = User::factory()->create(['company_id' => $otherCompany->id, 'group' => 'user', 'name' => 'Other Payroll']);
    Payroll::factory()->create(['company_id' => $otherCompany->id, 'user_id' => $otherEmployee->id, 'month' => 4, 'year' => 2026]);

    $this->getJson('/api/v1/admin/payrolls?month=2026-04', bearerHeaders($token))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'Payroll Employee'])
        ->assertJsonFragment(['name' => 'Payroll Employee Two'])
        ->assertJsonMissing(['name' => 'Other Payroll']);
});

it('includes payroll finance summary for the selected period', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $employeeTwo = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $employeeThree = User::factory()->create(['company_id' => 1, 'group' => 'user']);

    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'month' => 4,
        'year' => 2026,
        'gross_total' => 8000000,
        'deduction_total' => 1000000,
        'net_total' => 7000000,
        'status' => 'draft',
    ]);
    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employeeTwo->id,
        'month' => 4,
        'year' => 2026,
        'gross_total' => 6000000,
        'deduction_total' => 500000,
        'net_total' => 5500000,
        'status' => 'approved',
    ]);
    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employeeThree->id,
        'month' => 4,
        'year' => 2026,
        'gross_total' => 4000000,
        'deduction_total' => 250000,
        'net_total' => 3750000,
        'status' => 'paid',
    ]);
    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'month' => 3,
        'year' => 2026,
        'gross_total' => 9999999,
        'deduction_total' => 999999,
        'net_total' => 9000000,
        'status' => 'paid',
    ]);

    $this->getJson('/api/v1/admin/payrolls?month=2026-04', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('summary.payroll_count', 3)
        ->assertJsonPath('summary.gross_total', 18000000)
        ->assertJsonPath('summary.deduction_total', 1750000)
        ->assertJsonPath('summary.net_total', 16250000)
        ->assertJsonPath('summary.status_counts.draft', 1)
        ->assertJsonPath('summary.status_counts.approved', 1)
        ->assertJsonPath('summary.status_counts.paid', 1);
});

it('generates payroll for a selected employee', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'name' => 'Generate Payroll',
        'salary_monthly' => 7000000,
    ]);

    $this->postJson('/api/v1/admin/payrolls', [
        'user_id' => $employee->id,
        'month' => '2026-04',
        'bonus_extra' => 100000,
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Generate Payroll')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.base_salary', 7000000);

    $this->assertDatabaseHas('payrolls', [
        'user_id' => $employee->id,
        'month' => 4,
        'year' => 2026,
        'status' => 'draft',
    ]);
});

it('bulk generates payrolls for company employees', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    User::factory()->count(2)->create(['company_id' => 1, 'group' => 'user']);

    $this->postJson('/api/v1/admin/payrolls/bulk-generate', [
        'month' => '2026-04',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.generated_count', 2);

    expect(Payroll::withoutGlobalScopes()->where('company_id', 1)->where('month', 4)->where('year', 2026)->count())->toBe(2);
});

it('approves draft payroll', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payroll = Payroll::factory()->create(['company_id' => 1, 'user_id' => $employee->id, 'status' => 'draft']);

    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/approve", [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

it('rejects approval for non-draft payroll', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payroll = Payroll::factory()->create(['company_id' => 1, 'user_id' => $employee->id, 'status' => 'approved']);

    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/approve", [], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('marks approved payroll as paid', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payroll = Payroll::factory()->create(['company_id' => 1, 'user_id' => $employee->id, 'status' => 'approved']);
    Account::withoutGlobalScopes()->create([
        'company_id' => 1, 'code' => '5210', 'name' => 'Beban Gaji',
        'type' => 'expense', 'normal_balance' => 'debit', 'is_header' => false, 'is_active' => true,
    ]);
    Account::withoutGlobalScopes()->create([
        'company_id' => 1, 'code' => '1120', 'name' => 'Bank',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_header' => false, 'is_active' => true,
    ]);

    $response = $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/mark-paid", [], bearerHeaders($token));
    $response
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');
    expect($payroll->fresh()->journal_id)->toBeString()->not->toBeEmpty();
});

it('rejects paid action for non-approved payroll', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payroll = Payroll::factory()->create(['company_id' => 1, 'user_id' => $employee->id, 'status' => 'draft']);

    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/mark-paid", [], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('forbids regular employees from payroll admin endpoints', function () {
    [, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/payrolls', bearerHeaders($token))
        ->assertForbidden();
});

it('downloads payroll slip as pdf for payroll admins', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'name' => 'Slip Payroll',
        'email' => 'slip@example.test',
    ]);
    $payroll = Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'approved',
    ]);

    $this->get('/api/v1/admin/payrolls/' . $payroll->id . '/slip', bearerHeaders($token))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('queues payroll slip email for approved payroll', function () {
    [$finance, $token] = adminWithToken([
        'company_id' => 1,
        'group' => 'finance',
        'email' => 'finance-admin@example.test',
    ]);
    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'email' => 'employee-slip@example.test',
    ]);
    $payroll = Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'approved',
    ]);

    Bus::fake();

    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/send-slip-email", [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.recipient_email', 'employee-slip@example.test');

    Bus::assertDispatched(SendPayrollSlipEmail::class, function (SendPayrollSlipEmail $job) {
        return $job->recipientEmail === 'employee-slip@example.test';
    });
});

it('rejects payroll slip email for draft payroll', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'email' => 'draft-slip@example.test',
    ]);
    $payroll = Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/send-slip-email", [], bearerHeaders($token))
        ->assertUnprocessable();
});

it('exports payroll period report as csv', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
        'name' => 'Export Payroll',
        'email' => 'export-payroll@example.test',
        'nip' => 'EMP-CSV-01',
    ]);

    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'month' => 4,
        'year' => 2026,
        'gross_total' => 8000000,
        'deduction_total' => 1000000,
        'net_total' => 7000000,
        'status' => 'approved',
    ]);

    $response = $this->get('/api/v1/admin/payrolls/export?month=2026-04', bearerHeaders($token));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('content-disposition');

    expect($response->streamedContent())->toContain('Payroll Period Report')
        ->toContain('04/2026')
        ->toContain('Export Payroll')
        ->toContain('EMP-CSV-01')
        ->toContain('8000000')
        ->toContain('7000000');
});
