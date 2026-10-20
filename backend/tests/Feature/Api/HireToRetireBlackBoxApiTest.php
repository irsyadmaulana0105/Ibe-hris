<?php

use App\Models\Leave;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'hire-to-retire-black-box');

it('offboards an employee atomically while preserving history and revoking every active token', function () {
    [$admin, $adminToken] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    [$employee, $employeeToken] = employeeWithToken([
        'company_id' => 1,
        'employment_status' => 'permanent',
        'email' => 'retire-uat@example.test',
    ]);
    $employee->createToken('second-device');

    Leave::create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-03',
        'total_days' => 1,
        'reason' => 'Pending sebelum offboarding',
        'status' => 'pending',
    ]);

    $this->getJson('/api/v1/auth/me', bearerHeaders($employeeToken))->assertOk();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/admin/employees/'.$employee->id.'/resign', [
        'resignation_type' => 'resign',
        'resigned_at' => '2026-07-29',
        'last_working_date' => '2026-07-29',
        'reason' => 'UAT offboarding',
    ], bearerHeaders($adminToken))->assertOk()
        ->assertJsonPath('data.employment_status', 'resigned');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', bearerHeaders($employeeToken))->assertUnauthorized();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $employee->id)->count())->toBe(0)
        ->and(Leave::where('user_id', $employee->id)->value('status'))->toBe('rejected')
        ->and(DB::table('audit_logs')->where('action', 'employee.resigned')->where('entity_id', $employee->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $employee->id)->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/login', [
        'identifier' => $employee->email,
        'password' => 'password',
    ])->assertUnprocessable();
});

it('reactivation requires an administrator and never restores revoked sessions', function () {
    [$admin, $adminToken] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    [$employee, $oldToken] = employeeWithToken(['company_id' => 1]);

    $this->postJson('/api/v1/admin/employees/'.$employee->id.'/resign', [
        'resignation_type' => 'terminated',
        'resigned_at' => '2026-07-29',
    ], bearerHeaders($adminToken))->assertOk();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/admin/employees/'.$employee->id.'/reactivate', [
        'employment_status' => 'probation',
    ], bearerHeaders($oldToken))->assertUnauthorized();
    $this->postJson('/api/v1/admin/employees/'.$employee->id.'/reactivate', [
        'employment_status' => 'probation',
    ], bearerHeaders($adminToken))->assertOk()->assertJsonPath('data.employment_status', 'probation');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', bearerHeaders($oldToken))->assertUnauthorized();
    expect(DB::table('audit_logs')->where('action', 'employee.reactivated')->where('entity_id', $employee->id)->exists())->toBeTrue();
});
