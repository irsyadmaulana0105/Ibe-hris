<?php

use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\User;

uses()->group('api', 'self-service');

it('lists approved payrolls for the authenticated employee only', function () {
    [$employee, $token] = employeeWithToken(['company_id' => 1]);
    // Employee self-service payroll list hanya menampilkan payroll ber-status
    // 'paid' dengan bukti transfer terunggah (lihat AdminPayrollService::selfIndexForEmployee).
    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'paid',
        'transfer_proof_path' => 'payroll/proofs/paid.pdf',
    ]);
    Payroll::factory()->create(['company_id' => 1, 'user_id' => $employee->id, 'status' => 'draft', 'month' => 3]);
    $otherEmployee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $otherEmployee->id,
        'status' => 'paid',
        'transfer_proof_path' => 'payroll/proofs/other.pdf',
    ]);

    $this->getJson('/api/v1/payrolls', bearerHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.id', $employee->id);
});

it('shows employee payroll detail and downloads own slip', function () {
    [$employee, $token] = employeeWithToken(['company_id' => 1, 'email' => 'employee@example.test']);
    // Detail + download slip juga dibatasi ke status 'paid' + transfer_proof_path.
    $payroll = Payroll::factory()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'paid',
        'transfer_proof_path' => 'payroll/proofs/employee.pdf',
    ]);

    $this->getJson("/api/v1/payrolls/{$payroll->id}", bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.id', $payroll->id);

    $this->get("/api/v1/payrolls/{$payroll->id}/slip", bearerHeaders($token))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('lists monthly attendance calendar and attendance detail for employee', function () {
    [$employee, $token] = employeeWithToken(['company_id' => 1]);
    $attendance = Attendance::factory()->create([
        'user_id' => $employee->id,
        'date' => '2026-04-10',
        'status' => 'late',
        'time_in' => '08:15:00',
        'time_out' => '17:00:00',
    ]);

    $this->getJson('/api/v1/attendances/calendar?month=2026-04', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-04-01')
        ->assertJsonPath('data.0.is_recorded', false)
        ->assertJsonPath('data.9.date', '2026-04-10')
        ->assertJsonPath('data.9.status', 'late')
        ->assertJsonPath('data.9.is_recorded', true);

    $this->getJson("/api/v1/attendances/{$attendance->id}", bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.id', $attendance->id)
        ->assertJsonPath('data.time_in', '08:15:00');
});

it('prevents employees from reading other employee payroll and attendance details', function () {
    [$employee, $token] = employeeWithToken(['company_id' => 1]);
    $otherEmployee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $payroll = Payroll::factory()->create(['company_id' => 1, 'user_id' => $otherEmployee->id, 'status' => 'approved']);
    $attendance = Attendance::factory()->create(['user_id' => $otherEmployee->id]);

    $this->getJson("/api/v1/payrolls/{$payroll->id}", bearerHeaders($token))
        ->assertNotFound();

    $this->getJson("/api/v1/attendances/{$attendance->id}", bearerHeaders($token))
        ->assertNotFound();
});
