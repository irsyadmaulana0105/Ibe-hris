<?php

use App\Keuangan\Models\Account;
use App\Keuangan\Models\Journal;
use App\Models\BusinessTrip;
use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Reimbursement;
use App\Models\ReimbursementCategory;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses()->group('api', 'workflow-black-box');

it('runs employee to HR to finance reimbursement flow with tenant and audit integrity', function () {
    Storage::fake('public');
    Storage::fake('local');
    [$employee, $employeeToken] = employeeWithToken(['company_id' => 1]);
    [$hr, $hrToken] = adminWithToken(['company_id' => 1]);
    $finance = User::factory()->create(['company_id' => 1, 'group' => 'finance']);
    $financeToken = $finance->createToken('workflow-finance', ['admin', 'employee'])->plainTextToken;
    $category = ReimbursementCategory::create([
        'company_id' => 1,
        'name' => 'Transport UAT',
        'is_active' => true,
    ]);
    Account::withoutGlobalScopes()->create([
        'company_id' => 1, 'code' => '1120', 'name' => 'Bank UAT',
        'type' => 'asset', 'normal_balance' => 'debit',
        'is_header' => false, 'is_active' => true,
    ]);

    $submission = $this->postJson('/api/v1/reimbursements', [
        'date' => now()->toDateString(),
        'category_id' => $category->id,
        'event' => 'Kunjungan klien UAT',
        'unit_price' => 125000,
        'quantity' => 2,
        'attachment' => UploadedFile::fake()->image('receipt.jpg'),
        'bank_name' => 'Bank UAT',
        'bank_account_number' => '1234567890',
        'bank_account_holder' => $employee->name,
    ], bearerHeaders($employeeToken))->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.total_amount', 250000);

    $id = $submission->json('data.id');
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/reimbursements/{$id}/approve", [
        'approved_amount' => 225000,
        'notes' => 'Disetujui sesuai kebijakan perjalanan.',
    ], bearerHeaders($hrToken))->assertOk()
        ->assertJsonPath('data.status', 'hr_approved')
        ->assertJsonPath('data.approved_amount', 225000);

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/reimbursements/{$id}/settle", [
        'proof' => UploadedFile::fake()->image('transfer.jpg'),
        'reference' => 'UAT-TRF-001',
    ], bearerHeaders($financeToken))->assertOk()
        ->assertJsonPath('data.status', 'settled');

    $settled = Reimbursement::withoutGlobalScopes()->findOrFail($id);
    $journal = Journal::withoutGlobalScopes()->findOrFail($settled->journal_id);
    expect($settled->company_id)->toBe(1)
        ->and($settled->user_id)->toBe($employee->id)
        ->and($settled->receipt_number)->not->toBeNull()
        ->and($settled->payment_reference)->toBe('UAT-TRF-001')
        ->and($journal->status)->toBe('posted')
        ->and((float) $journal->total_debit)->toBe(225000.0)
        ->and((float) $journal->total_credit)->toBe(225000.0)
        ->and(DB::table('audit_logs')->where('action', 'reimbursement.hr_approved')->where('entity_id', $id)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'reimbursement.settled')->where('entity_id', $id)->exists())->toBeTrue();

    Storage::disk('local')->assertExists($settled->attachment_path);
    Storage::disk('local')->assertExists($settled->payment_proof_path);
    Storage::disk('local')->assertExists($settled->receipt_pdf_path);
});

it('does not settle reimbursement or retain transfer proof when journal posting fails', function () {
    Storage::fake('public');
    Storage::fake('local');
    [$employee, $employeeToken] = employeeWithToken(['company_id' => 1]);
    [$hr, $hrToken] = adminWithToken(['company_id' => 1]);
    $finance = User::factory()->create(['company_id' => 1, 'group' => 'finance']);
    $financeToken = $finance->createToken('workflow-finance', ['admin', 'employee'])->plainTextToken;
    $category = ReimbursementCategory::create([
        'company_id' => 1, 'name' => 'Journal Hard Gate UAT', 'is_active' => true,
    ]);

    $submission = $this->postJson('/api/v1/reimbursements', [
        'date' => now()->toDateString(), 'category_id' => $category->id,
        'event' => 'UAT tanpa akun bank', 'unit_price' => 100000, 'quantity' => 1,
        'attachment' => UploadedFile::fake()->image('receipt.jpg'),
        'bank_name' => 'Bank UAT', 'bank_account_number' => '1234567890',
        'bank_account_holder' => $employee->name,
    ], bearerHeaders($employeeToken))->assertCreated();
    $id = $submission->json('data.id');

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/reimbursements/{$id}/approve", [], bearerHeaders($hrToken))->assertOk();
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/reimbursements/{$id}/settle", [
        'proof' => UploadedFile::fake()->image('transfer.jpg'),
        'reference' => 'UAT-NO-BANK',
    ], bearerHeaders($financeToken))->assertUnprocessable()->assertJsonValidationErrors('journal');

    $unchanged = Reimbursement::withoutGlobalScopes()->findOrFail($id);
    expect($unchanged->status)->toBe('hr_approved')
        ->and($unchanged->settled_at)->toBeNull()
        ->and($unchanged->journal_id)->toBeNull()
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('rejects reimbursement categories owned by another tenant', function () {
    Storage::fake('public');
    Storage::fake('local');
    [$employee, $token] = employeeWithToken(['company_id' => 1]);
    DB::table('companies')->insert([
        'id' => 2, 'name' => 'Tenant Category Lain', 'slug' => 'tenant-category-lain',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignCategory = ReimbursementCategory::withoutGlobalScopes()->create([
        'company_id' => 2,
        'name' => 'Kategori Rahasia Tenant Lain',
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/reimbursements', [
        'date' => now()->toDateString(),
        'category_id' => $foreignCategory->id,
        'event' => 'Percobaan lintas tenant',
        'unit_price' => 100000,
        'quantity' => 1,
        'attachment' => UploadedFile::fake()->image('receipt.jpg'),
        'bank_name' => 'Bank UAT',
        'bank_account_number' => '1234567890',
        'bank_account_holder' => $employee->name,
    ], bearerHeaders($token))->assertUnprocessable()->assertJsonValidationErrors('category_id');

    expect(Reimbursement::withoutGlobalScopes()->where('user_id', $employee->id)->exists())->toBeFalse();
});

it('runs employee to HR salary advance flow and rejects a repeated decision', function () {
    [$employee, $employeeToken] = employeeWithToken(['company_id' => 1]);
    [$hr, $hrToken] = adminWithToken(['company_id' => 1]);

    $submission = $this->postJson('/api/v1/salary-advances', [
        'date' => now()->toDateString(),
        'amount' => 750000,
        'purpose' => 'Kasbon kebutuhan operasional UAT',
    ], bearerHeaders($employeeToken))->assertCreated()->assertJsonPath('data.status', 'pending');

    $id = $submission->json('data.id');
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/salary-advances/{$id}/approve", [
        'notes' => 'Disetujui HR UAT.',
    ], bearerHeaders($hrToken))->assertOk()->assertJsonPath('data.status', 'approved');

    $this->postJson("/api/v1/admin/salary-advances/{$id}/reject", [
        'notes' => 'Keputusan kedua tidak boleh diterima.',
    ], bearerHeaders($hrToken))->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(SalaryAdvance::withoutGlobalScopes()->findOrFail($id)->status)->toBe('approved')
        ->and(DB::table('audit_logs')->where('action', 'salary_advance.approved')->where('entity_id', $id)->exists())->toBeTrue();
});

it('prevents every HR approval workflow from crossing tenant boundaries', function (string $model, string $endpoint, array $attributes) {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    DB::table('companies')->insert([
        'id' => 2, 'name' => 'Tenant UAT Lain', 'slug' => 'tenant-uat-lain',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherEmployee = User::factory()->create(['company_id' => 2]);
    if ($model === Reimbursement::class) {
        $attributes['category_id'] = ReimbursementCategory::create([
            'company_id' => 2, 'name' => 'UAT Tenant Lain', 'is_active' => true,
        ])->id;
    }
    $record = $model::withoutGlobalScopes()->create(array_merge([
        'company_id' => 2,
        'user_id' => $otherEmployee->id,
        'status' => 'pending',
    ], $attributes));

    $this->postJson(str_replace('{id}', (string) $record->id, $endpoint), [], bearerHeaders($token))
        ->assertNotFound();

    expect($record->fresh()->status)->toBe('pending');
})->with([
    'leave' => [Leave::class, '/api/v1/admin/leaves/{id}/approve', [
        'leave_type' => 'annual', 'start_date' => now()->addDay(), 'end_date' => now()->addDay(),
        'days_count' => 1, 'reason' => 'UAT tenant isolation',
    ]],
    'overtime' => [Overtime::class, '/api/v1/admin/overtimes/{id}/approve', [
        'date' => now(), 'check_in_time' => '17:00', 'check_out_time' => '19:00',
        'total_hours' => 2, 'reason' => 'UAT tenant isolation',
    ]],
    'salary advance' => [SalaryAdvance::class, '/api/v1/admin/salary-advances/{id}/approve', [
        'date' => now(), 'amount' => 100000, 'purpose' => 'UAT tenant isolation',
    ]],
    'reimbursement' => [Reimbursement::class, '/api/v1/admin/reimbursements/{id}/approve', [
        'date' => now(), 'event' => 'UAT tenant isolation', 'unit_price' => 100000,
        'quantity' => 1, 'total_amount' => 100000,
    ]],
    'business trip' => [BusinessTrip::class, '/api/v1/admin/business-trips/{id}/approve', [
        'date' => now(), 'destination' => 'Tenant lain', 'purpose' => 'UAT tenant isolation',
    ]],
]);

it('prevents terminal HR records from being approved or rejected again', function (string $model, string $endpoint, array $attributes) {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);
    if ($model === Reimbursement::class) {
        $attributes['category_id'] = ReimbursementCategory::create([
            'company_id' => 1, 'name' => 'UAT Terminal', 'is_active' => true,
        ])->id;
    }
    $record = $model::withoutGlobalScopes()->create(array_merge([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'approved',
    ], $attributes));

    $this->postJson(str_replace('{id}', (string) $record->id, $endpoint), ['notes' => 'UAT terminal guard'], bearerHeaders($token))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    expect($record->fresh()->status)->toBe('approved');
})->with([
    'leave' => [Leave::class, '/api/v1/admin/leaves/{id}/reject', [
        'leave_type' => 'annual', 'start_date' => now()->addDay(), 'end_date' => now()->addDay(),
        'days_count' => 1, 'reason' => 'UAT terminal state',
    ]],
    'overtime' => [Overtime::class, '/api/v1/admin/overtimes/{id}/reject', [
        'date' => now(), 'check_in_time' => '17:00', 'check_out_time' => '19:00',
        'total_hours' => 2, 'reason' => 'UAT terminal state',
    ]],
    'salary advance' => [SalaryAdvance::class, '/api/v1/admin/salary-advances/{id}/reject', [
        'date' => now(), 'amount' => 100000, 'purpose' => 'UAT terminal state',
    ]],
    'reimbursement' => [Reimbursement::class, '/api/v1/admin/reimbursements/{id}/reject', [
        'date' => now(), 'event' => 'UAT terminal state', 'unit_price' => 100000,
        'quantity' => 1, 'total_amount' => 100000,
    ]],
    'business trip' => [BusinessTrip::class, '/api/v1/admin/business-trips/{id}/reject', [
        'date' => now(), 'destination' => 'UAT', 'purpose' => 'UAT terminal state',
    ]],
]);
