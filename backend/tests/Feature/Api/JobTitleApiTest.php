<?php

use App\Models\Division;
use App\Models\JobTitle;
use App\Models\User;

uses()->group('api', 'master-data');

it('lists company job titles for master data admins only', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'HR']);
    JobTitle::factory()->create(['company_id' => 1, 'division_id' => $division->id, 'name' => 'HR Manager']);

    $this->getJson('/api/v1/admin/master-data/job-titles', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'HR Manager')
        ->assertJsonPath('data.0.division.name', 'HR');
});

it('creates a job title for the authenticated company', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'Operations']);

    $this->postJson('/api/v1/admin/master-data/job-titles', [
        'name' => 'Supervisor',
        'division_id' => $division->id,
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Supervisor')
        ->assertJsonPath('data.division.name', 'Operations');
});

it('updates a job title inside the authenticated company', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'Operations']);
    $jobTitle = JobTitle::factory()->create([
        'company_id' => 1,
        'division_id' => $division->id,
        'name' => 'Old Title',
    ]);

    $this->putJson("/api/v1/admin/master-data/job-titles/{$jobTitle->id}", [
        'name' => 'New Title',
        'division_id' => $division->id,
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'New Title');
});

it('deletes a job title when it has no employees attached', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1]);
    $jobTitle = JobTitle::factory()->create([
        'company_id' => 1,
        'division_id' => $division->id,
    ]);

    $this->deleteJson("/api/v1/admin/master-data/job-titles/{$jobTitle->id}", [], bearerHeaders($token))
        ->assertOk();

    expect(JobTitle::withoutGlobalScopes()->find($jobTitle->id))->toBeNull();
});

it('rejects job title deletion when employees still use it', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1]);
    $jobTitle = JobTitle::factory()->create([
        'company_id' => 1,
        'division_id' => $division->id,
    ]);

    User::factory()->create([
        'company_id' => 1,
        'division_id' => $division->id,
        'job_title_id' => $jobTitle->id,
    ]);

    $this->deleteJson("/api/v1/admin/master-data/job-titles/{$jobTitle->id}", [], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('id');
});

it('forbids regular employees from accessing job title master data endpoints', function () {
    [$employee, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/master-data/job-titles', bearerHeaders($token))
        ->assertForbidden();
});
