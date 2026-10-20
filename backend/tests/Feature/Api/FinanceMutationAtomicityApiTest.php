<?php

use App\Keuangan\Models\Account;
use App\Keuangan\Models\Bill;
use App\Keuangan\Models\Journal;
use App\Keuangan\Services\BillService;
use App\Models\Payroll;
use App\Models\Reimbursement;
use App\Models\ReimbursementCategory;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses()->group('api', 'finance-atomicity');

function createFinanceAtomicityAccount(
    int $companyId,
    string $code,
    string $name,
    string $type,
    string $normalBalance,
): Account {
    return Account::withoutGlobalScopes()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'normal_balance' => $normalBalance,
        'is_header' => false,
        'is_active' => true,
    ]);
}

it('fails payroll payment closed and removes proof when the journal cannot be created', function () {
    Storage::fake('local');
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payroll = Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'approved',
        'journal_id' => null,
    ]);

    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/mark-paid", [
        'transfer_proof' => UploadedFile::fake()->create('transfer.pdf', 24, 'application/pdf'),
        'transfer_reference' => 'PAY-FAIL-CLOSED-001',
    ], array_merge(bearerHeaders($token), [
        'Idempotency-Key' => 'payroll-fail-closed-001',
    ]))->assertUnprocessable()->assertJsonValidationErrors('journal');

    $unchanged = $payroll->fresh();
    expect($unchanged->status)->toBe('approved')
        ->and($unchanged->journal_id)->toBeNull()
        ->and($unchanged->transfer_proof_path)->toBeNull()
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('replays payroll submit approve and pay mutations without duplicate state or journal', function () {
    [$hr, $hrToken] = adminWithToken(['company_id' => 1, 'group' => 'admin']);
    [$approver, $approverToken] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    [$payer, $payerToken] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payroll = Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'draft',
        'journal_id' => null,
    ]);
    createFinanceAtomicityAccount(1, '5210', 'Beban Gaji', 'expense', 'debit');
    createFinanceAtomicityAccount(1, '1120', 'Bank', 'asset', 'debit');

    $submitHeaders = array_merge(bearerHeaders($hrToken), ['Idempotency-Key' => 'payroll-submit-retry-001']);
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/submit", [], $submitHeaders)
        ->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/submit", [], $submitHeaders)
        ->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    app('auth')->forgetGuards();
    $approveHeaders = array_merge(bearerHeaders($approverToken), ['Idempotency-Key' => 'payroll-approve-retry-001']);
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/approve", [], $approveHeaders)
        ->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/approve", [], $approveHeaders)
        ->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    app('auth')->forgetGuards();
    $payHeaders = array_merge(bearerHeaders($payerToken), ['Idempotency-Key' => 'payroll-payment-retry-001']);
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/mark-paid", [], $payHeaders)
        ->assertOk()->assertJsonPath('data.status', 'paid');
    $this->postJson("/api/v1/admin/payrolls/{$payroll->id}/mark-paid", [], $payHeaders)
        ->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    $paid = $payroll->fresh();
    expect($paid->submitted_by)->toBe($hr->id)
        ->and($paid->approved_by)->toBe($approver->id)
        ->and($paid->paid_by)->toBe($payer->id)
        ->and($paid->journal_id)->not->toBeNull()
        ->and(Journal::withoutGlobalScopes()->where('status', 'posted')->count())->toBe(1);
});

it('revalidates a stale bill under row lock and cannot create an overpayment journal', function () {
    [$finance] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    createFinanceAtomicityAccount(1, '2110', 'Hutang Usaha', 'liability', 'credit');
    createFinanceAtomicityAccount(1, '1120', 'Bank', 'asset', 'debit');
    $bill = Bill::withoutGlobalScopes()->create([
        'company_id' => 1,
        'number' => 'BILL-2026/08/9001',
        'date' => now()->toDateString(),
        'supplier_name' => 'Supplier Atomicity',
        'total' => 100,
        'paid_amount' => 0,
        'status' => 'received',
    ]);

    $first = app(BillService::class)->pay($finance, $bill, '1120', 60);
    expect($first->status)->toBe('partial')
        ->and((float) $first->paid_amount)->toBe(60.0)
        ->and(Journal::withoutGlobalScopes()->count())->toBe(1);

    expect(fn () => app(BillService::class)->pay($finance, $bill, '1120', 60))
        ->toThrow(ValidationException::class);

    expect((float) $bill->fresh()->paid_amount)->toBe(60.0)
        ->and(Journal::withoutGlobalScopes()->count())->toBe(1);
});

it('replays bill payment and blocks an overpay without a second journal', function () {
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $maker = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    createFinanceAtomicityAccount(1, '2110', 'Hutang Usaha', 'liability', 'credit');
    createFinanceAtomicityAccount(1, '1120', 'Bank', 'asset', 'debit');
    $bill = Bill::withoutGlobalScopes()->create([
        'company_id' => 1,
        'number' => 'BILL-2026/08/9002',
        'date' => now()->toDateString(),
        'supplier_name' => 'Supplier Idempotency',
        'total' => 100,
        'paid_amount' => 0,
        'status' => 'received',
        'created_by' => $maker->id,
    ]);
    $headers = array_merge(bearerHeaders($token), ['Idempotency-Key' => 'bill-payment-retry-001']);
    $payload = ['payment_account_code' => '1120', 'amount' => 60];

    $this->postJson("/api/v1/finance/bills/{$bill->id}/pay", $payload, $headers)
        ->assertOk()->assertJsonPath('data.status', 'partial');
    $this->postJson("/api/v1/finance/bills/{$bill->id}/pay", $payload, $headers)
        ->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    $this->postJson("/api/v1/finance/bills/{$bill->id}/pay", [
        'payment_account_code' => '1120',
        'amount' => 50,
    ], array_merge(bearerHeaders($token), ['Idempotency-Key' => 'bill-overpay-retry-001']))
        ->assertUnprocessable()->assertJsonValidationErrors('amount');

    expect((float) $bill->fresh()->paid_amount)->toBe(60.0)
        ->and(Journal::withoutGlobalScopes()->count())->toBe(1);
});

it('rolls reimbursement journal and state back and removes proof when receipt generation fails', function () {
    Storage::fake('public');
    Storage::fake('local');
    [$finance, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $hr = User::factory()->admin()->create(['company_id' => 1, 'group' => 'admin']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $expense = createFinanceAtomicityAccount(1, '5270', 'Beban Transportasi', 'expense', 'debit');
    createFinanceAtomicityAccount(1, '1120', 'Bank', 'asset', 'debit');
    $category = ReimbursementCategory::withoutGlobalScopes()->create([
        'company_id' => 1,
        'name' => 'Transport Atomicity',
        'is_active' => true,
        'expense_account_id' => $expense->id,
    ]);
    $reimbursement = Reimbursement::withoutGlobalScopes()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'category_id' => $category->id,
        'date' => now()->toDateString(),
        'event' => 'Atomic receipt test',
        'unit_price' => 250000,
        'quantity' => 1,
        'total_amount' => 250000,
        'approved_amount' => 250000,
        'status' => 'hr_approved',
        'hr_approved_by' => $hr->id,
        'approved_by' => $hr->id,
    ]);

    Pdf::shouldReceive('loadView')->once()->andThrow(new RuntimeException('Synthetic PDF failure'));

    $this->postJson("/api/v1/admin/reimbursements/{$reimbursement->id}/settle", [
        'proof' => UploadedFile::fake()->image('transfer.jpg'),
        'reference' => 'ATOMIC-PDF-FAIL-001',
    ], array_merge(bearerHeaders($token), [
        'Idempotency-Key' => 'reimbursement-pdf-fail-001',
    ]))->assertUnprocessable()->assertJsonValidationErrors('settlement');

    $unchanged = $reimbursement->fresh();
    expect($unchanged->status)->toBe('hr_approved')
        ->and($unchanged->settled_at)->toBeNull()
        ->and($unchanged->receipt_number)->toBeNull()
        ->and($unchanged->receipt_pdf_path)->toBeNull()
        ->and($unchanged->payment_proof_path)->toBeNull()
        ->and($unchanged->journal_id)->toBeNull()
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
