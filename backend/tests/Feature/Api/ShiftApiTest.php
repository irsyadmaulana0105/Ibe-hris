<?php

use App\Models\Attendance;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'master-data');

it('lists company shifts for master data admins only', function () {
    [$admin, $token] = adminWithToken();
    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Other Company',
        'slug' => 'other-company',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Shift::factory()->create(['company_id' => 1, 'name' => 'Morning']);
    Shift::factory()->create(['company_id' => 2, 'name' => 'Other']);

    $this->getJson('/api/v1/admin/master-data/shifts', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Morning')
        ->assertJsonCount(1, 'data');
});

it('creates a shift for the authenticated company', function () {
    [$admin, $token] = adminWithToken();

    $this->postJson('/api/v1/admin/master-data/shifts', [
        'name' => 'Night',
        'start_time' => '19:00',
        'end_time' => '02:00',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Night')
        ->assertJsonPath('data.start_time', '19:00');
});

it('updates a shift inside the authenticated company', function () {
    [$admin, $token] = adminWithToken();
    $shift = Shift::factory()->create(['company_id' => 1, 'name' => 'Old Shift']);

    $this->putJson("/api/v1/admin/master-data/shifts/{$shift->id}", [
        'name' => 'Updated Shift',
        'start_time' => '08:30',
        'end_time' => '17:30',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Shift');
});

it('deletes a shift when it has no operational usage', function () {
    [$admin, $token] = adminWithToken();
    $shift = Shift::factory()->create(['company_id' => 1, 'name' => 'Temporary']);

    $this->deleteJson("/api/v1/admin/master-data/shifts/{$shift->id}", [], bearerHeaders($token))
        ->assertOk();

    expect(Shift::withoutGlobalScopes()->find($shift->id))->toBeNull();
});

it('rejects shift deletion when attendance still uses it', function () {
    [$admin, $token] = adminWithToken();
    $shift = Shift::factory()->create(['company_id' => 1, 'name' => 'Used Shift']);
    $employee = User::factory()->create(['company_id' => 1]);

    Attendance::factory()->create([
        'user_id' => $employee->id,
        'shift_id' => $shift->id,
    ]);

    $this->deleteJson("/api/v1/admin/master-data/shifts/{$shift->id}", [], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('id');
});

it('forbids regular employees from accessing shift master data endpoints', function () {
    [$employee, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/master-data/shifts', bearerHeaders($token))
        ->assertForbidden();
});
