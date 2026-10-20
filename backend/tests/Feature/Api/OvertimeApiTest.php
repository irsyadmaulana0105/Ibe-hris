<?php

use App\Models\Overtime;
use App\Models\User;

uses()->group('api', 'overtime');

// ── Employee: List ────────────────────────────────────────────────────────────

it('employee can list their overtime requests', function () {
    [$user, $token] = employeeWithToken();
    Overtime::factory()->count(4)->create(['user_id' => $user->id]);

    $this->getJson('/api/v1/overtimes', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('meta.total', 4);
});

// ── Employee: Submit ──────────────────────────────────────────────────────────

it('employee can submit an overtime request', function () {
    [$user, $token] = employeeWithToken();

    $this->postJson('/api/v1/overtimes', [
        'date'       => today()->toDateString(),
        'start_time' => '17:00',
        'end_time'   => '19:00',
        'reason'     => 'Sprint deadline work',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.total_hours', 2)
        ->assertJsonPath('data.status', 'pending');
});

it('rejects overtime when end_time is before start_time', function () {
    [, $token] = employeeWithToken();

    $this->postJson('/api/v1/overtimes', [
        'date'       => today()->toDateString(),
        'start_time' => '19:00',
        'end_time'   => '17:00',
        'reason'     => 'Test',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_time');
});

it('requires reason with minimum 5 characters', function () {
    [, $token] = employeeWithToken();

    $this->postJson('/api/v1/overtimes', [
        'date'       => today()->toDateString(),
        'start_time' => '17:00',
        'end_time'   => '19:00',
        'reason'     => 'Hi',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

// ── Employee: Cancel ──────────────────────────────────────────────────────────

it('employee can cancel their pending overtime', function () {
    [$user, $token] = employeeWithToken();
    $overtime = Overtime::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    $this->deleteJson("/api/v1/overtimes/{$overtime->id}", [], bearerHeaders($token))
        ->assertOk();

    expect(Overtime::find($overtime->id))->toBeNull();
});

it('employee cannot cancel approved overtime', function () {
    [$user, $token] = employeeWithToken();
    $overtime = Overtime::factory()->approved()->create(['user_id' => $user->id]);

    $this->deleteJson("/api/v1/overtimes/{$overtime->id}", [], bearerHeaders($token))
        ->assertNotFound();
});

// ── Admin: Approve / Reject ───────────────────────────────────────────────────

it('admin can approve an overtime request', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);
    $overtime = Overtime::factory()->create(['user_id' => $employee->id, 'status' => 'pending']);

    $this->postJson("/api/v1/admin/overtimes/{$overtime->id}/approve", [
        'notes' => 'Approved for sprint.',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect($overtime->fresh()->status)->toBe('approved');
});

it('admin can reject an overtime request', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);
    $overtime = Overtime::factory()->create(['user_id' => $employee->id, 'status' => 'pending']);

    $this->postJson("/api/v1/admin/overtimes/{$overtime->id}/reject", [
        'notes' => 'Outside policy.',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('employee cannot access admin overtime endpoints', function () {
    [, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/overtimes', bearerHeaders($token))
        ->assertForbidden();
});

it('admin can view overtime summary from admin list', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1]);

    Overtime::factory()->create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'status' => 'pending',
        'total_hours' => 2,
    ]);
    Overtime::factory()->approved()->create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'status' => 'approved',
        'total_hours' => 3.5,
    ]);
    Overtime::factory()->create([
        'user_id' => $employee->id,
        'company_id' => 1,
        'status' => 'rejected',
        'total_hours' => 1.5,
    ]);

    $this->getJson('/api/v1/admin/overtimes', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('summary.total_requests', 3)
        ->assertJsonPath('summary.total_hours', 7)
        ->assertJsonPath('summary.average_hours', 2.33)
        ->assertJsonPath('summary.status_counts.pending', 1)
        ->assertJsonPath('summary.status_counts.approved', 1)
        ->assertJsonPath('summary.status_counts.rejected', 1);
});
