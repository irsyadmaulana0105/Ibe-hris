<?php

use App\Models\Leave;
use App\Models\User;
use Illuminate\Support\Carbon;

uses()->group('api', 'leave');

// ── Employee: List ─────────────────────────────────────────────────────────────

it('employee can list their own leaves', function () {
    [$user, $token] = employeeWithToken();

    Leave::factory()->count(3)->create(['user_id' => $user->id, 'company_id' => 1]);

    $this->getJson('/api/v1/leaves', bearerHeaders($token))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'annual_balance'])
        ->assertJsonPath('meta.total', 3);
});

it('employee cannot see another user leaves', function () {
    [$user, $token] = employeeWithToken();
    $other = User::factory()->create();
    Leave::factory()->count(2)->create(['user_id' => $other->id, 'company_id' => 1]);

    $this->getJson('/api/v1/leaves', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

// ── Employee: Submit ──────────────────────────────────────────────────────────

it('employee can submit a leave request', function () {
    [$user, $token] = employeeWithToken();

    $this->postJson('/api/v1/leaves', [
        'leave_type' => 'annual',
        'start_date' => Carbon::tomorrow()->toDateString(),
        'end_date'   => Carbon::tomorrow()->toDateString(),
        'reason'     => 'Medical appointment',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.leave_type.key', 'annual');

    expect(Leave::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects leave with past start_date', function () {
    [, $token] = employeeWithToken();

    $this->postJson('/api/v1/leaves', [
        'leave_type' => 'annual',
        'start_date' => Carbon::yesterday()->toDateString(),
        'end_date'   => Carbon::yesterday()->toDateString(),
        'reason'     => 'Some reason here',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_date');
});

it('rejects invalid leave_type', function () {
    [, $token] = employeeWithToken();

    $this->postJson('/api/v1/leaves', [
        'leave_type' => 'invalid_type',
        'start_date' => Carbon::tomorrow()->toDateString(),
        'end_date'   => Carbon::tomorrow()->toDateString(),
        'reason'     => 'Some reason here',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('leave_type');
});

// ── Employee: Cancel ──────────────────────────────────────────────────────────

it('employee can cancel their pending leave', function () {
    [$user, $token] = employeeWithToken();
    $leave = Leave::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'company_id' => 1]);

    $this->deleteJson("/api/v1/leaves/{$leave->id}", [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('message', __('Leave request cancelled.'));

    expect(Leave::find($leave->id))->toBeNull();
});

it('employee cannot cancel an approved leave', function () {
    [$user, $token] = employeeWithToken();
    $leave = Leave::factory()->create(['user_id' => $user->id, 'status' => 'approved', 'company_id' => 1]);

    $this->deleteJson("/api/v1/leaves/{$leave->id}", [], bearerHeaders($token))
        ->assertNotFound();
});

it('employee cannot cancel another users leave', function () {
    [, $token] = employeeWithToken();
    $other = User::factory()->create();
    $leave = Leave::factory()->create(['user_id' => $other->id, 'status' => 'pending', 'company_id' => 1]);

    $this->deleteJson("/api/v1/leaves/{$leave->id}", [], bearerHeaders($token))
        ->assertNotFound();
});

// ── Admin: List & Approve ─────────────────────────────────────────────────────

it('admin can list all company leaves', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);
    Leave::factory()->create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'status' => 'pending',
        'leave_type' => 'annual',
        'days_count' => 2,
    ]);
    Leave::factory()->create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'status' => 'approved',
        'leave_type' => 'sick',
        'days_count' => 1,
    ]);
    Leave::factory()->create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'status' => 'rejected',
        'leave_type' => 'personal',
        'days_count' => 3,
    ]);

    $this->getJson('/api/v1/admin/leaves', bearerHeaders($token))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'pending_count', 'summary'])
        ->assertJsonPath('summary.total_requests', 3)
        ->assertJsonPath('summary.total_days', 6)
        ->assertJsonPath('summary.status_counts.pending', 1)
        ->assertJsonPath('summary.status_counts.approved', 1)
        ->assertJsonPath('summary.status_counts.rejected', 1)
        ->assertJsonPath('summary.type_counts.annual', 1)
        ->assertJsonPath('summary.type_counts.sick', 1)
        ->assertJsonPath('summary.type_counts.personal', 1);
});

it('admin can approve a leave and attendance records are synced', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);

    $leave = Leave::factory()->create([
        'user_id'    => $employee->id,
        'company_id' => null,
        'status'     => 'pending',
        'leave_type' => 'annual',
        'start_date' => '2026-04-20',
        'end_date'   => '2026-04-20',
        'days_count' => 1,
        'reason'     => 'Test leave',
    ]);

    $this->postJson("/api/v1/admin/leaves/{$leave->id}/approve", [
        'notes' => 'Approved.',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('message', __('Leave request approved.'));

    expect($leave->fresh()->status)->toBe('approved');
    expect(\App\Models\Attendance::where('user_id', $employee->id)->where('date', '2026-04-20')->exists())->toBeTrue();
});

it('admin can reject a leave', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);
    $leave = Leave::factory()->create(['user_id' => $employee->id, 'status' => 'pending', 'company_id' => 1]);

    $this->postJson("/api/v1/admin/leaves/{$leave->id}/reject", [
        'notes' => 'Insufficient balance.',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('regular employee cannot access admin leave endpoints', function () {
    [, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/leaves', bearerHeaders($token))
        ->assertForbidden();
});

// ── Unauthenticated ───────────────────────────────────────────────────────────

it('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/leaves', ['Accept' => 'application/json'])
        ->assertUnauthorized();
});
