<?php

use App\Models\AuditLog;
use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\Reimbursement;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'dashboard');

it('returns admin summary and recent audit logs for superadmin users', function () {
    $admin = User::factory()->admin()->create([
        'company_id' => 1,
        'group' => 'superadmin',
    ]);
    $token = $admin->createToken('test', ['admin', 'employee'])->plainTextToken;

    $employee = User::factory()->create([
        'company_id' => 1,
        'group' => 'user',
    ]);

    Leave::query()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => today()->addDay(),
        'end_date' => today()->addDay(),
        'days_count' => 1,
        'reason' => 'Annual leave',
        'status' => 'pending',
    ]);

    Overtime::query()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'date' => today()->toDateString(),
        'check_in_time' => '18:00:00',
        'check_out_time' => '20:00:00',
        'total_hours' => 2,
        'reason' => 'Server maintenance',
        'status' => 'pending',
    ]);

    SalaryAdvance::query()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'date' => today()->toDateString(),
        'amount' => 500000,
        'purpose' => 'Emergency',
        'status' => 'pending',
    ]);

    $categoryId = DB::table('reimbursement_categories')->insertGetId([
        'company_id' => 1,
        'name' => 'Transport',
        'limit_amount' => 1000000,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Reimbursement::query()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'category_id' => $categoryId,
        'date' => today()->toDateString(),
        'event' => 'Client meeting',
        'unit_price' => 150000,
        'quantity' => 1,
        'total_amount' => 150000,
        'status' => 'pending',
    ]);

    Payroll::query()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'payroll_number' => 'GJ/2026/04/00001',
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'month' => now()->month,
        'year' => now()->year,
        'attendance_percentage' => 100,
        'base_salary' => 7000000,
        'meal_transport_total' => 0,
        'overtime_count' => 1,
        'overtime_rate' => 0,
        'overtime_total' => 500000,
        'attendance_count' => 22,
        'attendance_rate' => 0,
        'attendance_total' => 0,
        'bonus_count' => 0,
        'bonus_rate' => 0,
        'bonus_total' => 0,
        'thr_count' => 0,
        'thr_rate' => 0,
        'thr_total' => 0,
        'reimbursement_total' => 150000,
        'late_count' => 0,
        'late_rate' => 0,
        'late_total' => 0,
        'absent_count' => 0,
        'absent_rate' => 0,
        'absent_total' => 0,
        'leave_count' => 0,
        'leave_rate' => 0,
        'leave_total' => 0,
        'salary_advance_balance' => 0,
        'salary_advance_payment' => 0,
        'gross_total' => 7650000,
        'deduction_total' => 150000,
        'net_total' => 7500000,
        'status' => 'approved',
    ]);

    \App\Models\Attendance::query()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'date' => today()->toDateString(),
        'time_in' => '08:10:00',
        'status' => 'late',
    ]);

    $employee->forceFill([
        'face_descriptor_pending' => json_encode([0.1, 0.2, 0.3]),
    ])->save();

    AuditLog::query()->create([
        'company_id' => 1,
        'actor_id' => $admin->id,
        'action' => 'company.updated',
        'entity_type' => 'company',
        'entity_id' => '1',
        'meta' => ['field' => 'name'],
    ]);

    $this->getJson('/api/v1/dashboard', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.admin_summary.pending_leave_count', 1)
        ->assertJsonPath('data.admin_summary.pending_overtime_count', 1)
        ->assertJsonPath('data.admin_summary.pending_salary_advance_count', 1)
        ->assertJsonPath('data.admin_summary.pending_reimbursement_count', 1)
        ->assertJsonPath('data.admin_summary.pending_face_approval_count', 1)
        ->assertJsonPath('data.admin_reporting.period.month', now()->format('Y-m'))
        ->assertJsonPath('data.admin_reporting.attendance_today.total_employees', 1)
        ->assertJsonPath('data.admin_reporting.attendance_today.checked_in', 1)
        ->assertJsonPath('data.admin_reporting.attendance_today.late', 1)
        ->assertJsonPath('data.admin_reporting.leave.total_requests', 1)
        ->assertJsonPath('data.admin_reporting.overtime.total_requests', 1)
        ->assertJsonPath('data.admin_reporting.reimbursement.requested_total', 150000)
        ->assertJsonPath('data.admin_reporting.salary_advance.requested_total', 500000)
        ->assertJsonPath('data.admin_reporting.payroll.net_total', 7500000)
        ->assertJsonPath('data.recent_audit_logs.0.action', 'company.updated')
        ->assertJsonPath('data.recent_audit_logs.0.actor.id', $admin->id);
});

it('does not expose admin dashboard data to regular employees', function () {
    [$user, $token] = employeeWithToken();

    $this->getJson('/api/v1/dashboard', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.admin_summary', null)
        ->assertJsonPath('data.admin_reporting', null)
        ->assertJsonCount(0, 'data.recent_audit_logs');
});
