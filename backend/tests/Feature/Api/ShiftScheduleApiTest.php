<?php

use App\Models\AutoShift;
use App\Models\Company;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftMapping;
use App\Models\User;

uses()->group('api', 'shift-schedule');

it('lists shift schedule references for attendance admins', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $shift = Shift::factory()->create(['company_id' => 1, 'name' => 'Morning']);
    $jobTitle = JobTitle::create(['company_id' => 1, 'name' => 'Supervisor']);
    User::factory()->create(['company_id' => 1, 'group' => 'user', 'name' => 'Schedule Employee']);

    $this->getJson('/api/v1/admin/shift-schedules/references', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.shifts.0.name', $shift->name)
        ->assertJsonPath('data.job_titles.0.name', $jobTitle->name)
        ->assertJsonPath('data.employees.0.name', 'Schedule Employee');
});

it('saves default shift per job title inside admin company', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $shift = Shift::factory()->create(['company_id' => 1, 'name' => 'Morning']);
    $jobTitle = JobTitle::create(['company_id' => 1, 'name' => 'Supervisor']);

    $this->postJson('/api/v1/admin/shift-schedules/auto-shifts', [
        'job_title_id' => $jobTitle->id,
        'shift_id' => $shift->id,
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.job_title.name', 'Supervisor')
        ->assertJsonPath('data.shift.name', 'Morning');

    expect(AutoShift::withoutGlobalScopes()->where('job_title_id', $jobTitle->id)->count())->toBe(1);
});

it('rejects default shift references from another company', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $otherCompany = Company::create(['name' => 'Other Company', 'slug' => 'other-shift-company']);
    $shift = Shift::factory()->create(['company_id' => 1]);
    $jobTitle = JobTitle::create(['company_id' => $otherCompany->id, 'name' => 'External']);

    $this->postJson('/api/v1/admin/shift-schedules/auto-shifts', [
        'job_title_id' => $jobTitle->id,
        'shift_id' => $shift->id,
    ], bearerHeaders($token))
        ->assertNotFound();
});

it('saves employee shift mapping inside admin company', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $shift = Shift::factory()->create(['company_id' => 1, 'name' => 'Evening']);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user', 'name' => 'Mapped Employee']);

    $this->postJson('/api/v1/admin/shift-schedules/mappings', [
        'user_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-04-23',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Mapped Employee')
        ->assertJsonPath('data.shift.name', 'Evening')
        ->assertJsonPath('data.date', '2026-04-23');

    expect(ShiftMapping::withoutGlobalScopes()->where('user_id', $employee->id)->count())->toBe(1);
});

it('lists and deletes shift mappings', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $shift = Shift::factory()->create(['company_id' => 1]);
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $mapping = ShiftMapping::create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-04-23',
    ]);

    $this->getJson('/api/v1/admin/shift-schedules/mappings?date=2026-04-23', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.id', $mapping->id);

    $this->deleteJson("/api/v1/admin/shift-schedules/mappings/{$mapping->id}", [], bearerHeaders($token))
        ->assertOk();

    expect(ShiftMapping::withoutGlobalScopes()->find($mapping->id))->toBeNull();
});

it('forbids regular employees from shift scheduling endpoints', function () {
    [, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/shift-schedules/references', bearerHeaders($token))
        ->assertForbidden();
});
