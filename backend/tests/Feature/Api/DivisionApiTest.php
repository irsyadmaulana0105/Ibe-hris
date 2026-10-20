<?php

use App\Models\Division;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'master-data');

it('lists company divisions for master data admins only', function () {
    [$admin, $token] = adminWithToken();
    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Other Company',
        'slug' => 'other-company',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Division::factory()->create(['company_id' => 1, 'name' => 'HR']);
    Division::factory()->create(['company_id' => 2, 'name' => 'Other']);

    $this->getJson('/api/v1/admin/master-data/divisions', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'HR')
        ->assertJsonCount(1, 'data');
});

it('creates a division for the authenticated company', function () {
    [$admin, $token] = adminWithToken();

    $this->postJson('/api/v1/admin/master-data/divisions', [
        'name' => 'Operations',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Operations');

    expect(Division::withoutGlobalScopes()->where('company_id', 1)->where('name', 'Operations')->exists())->toBeTrue();
});

it('updates a division inside the authenticated company', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'Old Name']);

    $this->putJson("/api/v1/admin/master-data/divisions/{$division->id}", [
        'name' => 'New Name',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

it('deletes a division when it has no employees attached', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'Temporary']);

    $this->deleteJson("/api/v1/admin/master-data/divisions/{$division->id}", [], bearerHeaders($token))
        ->assertOk();

    expect(Division::withoutGlobalScopes()->find($division->id))->toBeNull();
});

it('rejects division deletion when employees still use it', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'Core']);
    User::factory()->create([
        'company_id' => 1,
        'division_id' => $division->id,
    ]);

    $this->deleteJson("/api/v1/admin/master-data/divisions/{$division->id}", [], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('id');
});

it('forbids regular employees from accessing division master data endpoints', function () {
    [$employee, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/master-data/divisions', bearerHeaders($token))
        ->assertForbidden();
});
