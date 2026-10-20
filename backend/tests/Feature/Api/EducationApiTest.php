<?php

use App\Models\Education;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'master-data');

it('lists company educations for master data admins only', function () {
    [$admin, $token] = adminWithToken();
    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Other Company',
        'slug' => 'other-company',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Education::factory()->create(['company_id' => 1, 'name' => 'S1']);
    Education::factory()->create(['company_id' => 2, 'name' => 'Other']);

    $this->getJson('/api/v1/admin/master-data/educations', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'S1')
        ->assertJsonCount(1, 'data');
});

it('creates an education for the authenticated company', function () {
    [$admin, $token] = adminWithToken();

    $this->postJson('/api/v1/admin/master-data/educations', [
        'name' => 'Diploma',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Diploma');
});

it('updates an education inside the authenticated company', function () {
    [$admin, $token] = adminWithToken();
    $education = Education::factory()->create(['company_id' => 1, 'name' => 'Old']);

    $this->putJson("/api/v1/admin/master-data/educations/{$education->id}", [
        'name' => 'New',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'New');
});

it('deletes an education when it has no employees attached', function () {
    [$admin, $token] = adminWithToken();
    $education = Education::factory()->create(['company_id' => 1, 'name' => 'Temporary']);

    $this->deleteJson("/api/v1/admin/master-data/educations/{$education->id}", [], bearerHeaders($token))
        ->assertOk();

    expect(Education::withoutGlobalScopes()->find($education->id))->toBeNull();
});

it('rejects education deletion when employees still use it', function () {
    [$admin, $token] = adminWithToken();
    $education = Education::factory()->create(['company_id' => 1, 'name' => 'Core']);
    User::factory()->create([
        'company_id' => 1,
        'education_id' => $education->id,
    ]);

    $this->deleteJson("/api/v1/admin/master-data/educations/{$education->id}", [], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('id');
});

it('forbids regular employees from accessing education master data endpoints', function () {
    [$employee, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/master-data/educations', bearerHeaders($token))
        ->assertForbidden();
});
