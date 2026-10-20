<?php

use App\Models\Division;
use App\Models\Education;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'employees');

it('lists company employees with references for employee admins', function () {
    [$admin, $token] = adminWithToken();
    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Other Company',
        'slug' => 'other-company',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $division = Division::factory()->create(['company_id' => 1, 'name' => 'Operations']);
    $education = Education::factory()->create(['company_id' => 1, 'name' => 'S1']);
    $jobTitle = JobTitle::factory()->create(['division_id' => $division->id, 'name' => 'Supervisor']);

    User::factory()->create([
        'company_id' => 1,
        'name' => 'Alice Employee',
        'division_id' => $division->id,
        'education_id' => $education->id,
        'job_title_id' => $jobTitle->id,
    ]);
    User::factory()->create(['company_id' => 2, 'name' => 'Other Employee']);

    $this->getJson('/api/v1/admin/employees', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alice Employee')
        ->assertJsonPath('data.0.division.name', 'Operations')
        ->assertJsonPath('references.divisions.0.name', 'Operations')
        ->assertJsonCount(1, 'data');
});

it('shows a company employee detail', function () {
    [$admin, $token] = adminWithToken();
    $employee = User::factory()->create([
        'company_id' => 1,
        'name' => 'Detail Employee',
        'salary_monthly' => 4500000,
    ]);

    $this->getJson("/api/v1/admin/employees/{$employee->id}", bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'Detail Employee')
        ->assertJsonPath('data.salary_monthly', 4500000);
});

it('filters employee list by division', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'A Division']);
    $otherDivision = Division::factory()->create(['company_id' => 1, 'name' => 'B Division']);
    User::factory()->create(['company_id' => 1, 'name' => 'A Employee', 'division_id' => $division->id]);
    User::factory()->create(['company_id' => 1, 'name' => 'B Employee', 'division_id' => $otherDivision->id]);

    $this->getJson("/api/v1/admin/employees?division_id={$division->id}", bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'A Employee')
        ->assertJsonCount(1, 'data');
});

it('creates an employee with master data references', function () {
    [$admin, $token] = adminWithToken();
    $division = Division::factory()->create(['company_id' => 1, 'name' => 'Operations']);
    $education = Education::factory()->create(['company_id' => 1, 'name' => 'S1']);
    $jobTitle = JobTitle::factory()->create(['division_id' => $division->id, 'name' => 'Supervisor']);

    $this->postJson('/api/v1/admin/employees', [
        'name' => 'Created Employee',
        'nip' => 'EMP001',
        'email' => 'created@example.test',
        'phone' => '08123456789',
        'password' => 'secret123',
        'gender' => 'male',
        'city' => 'Surabaya',
        'address' => 'Jl. Contoh',
        'division_id' => $division->id,
        'education_id' => $education->id,
        'job_title_id' => $jobTitle->id,
        'employment_status' => 'permanent',
        'salary_monthly' => 5500000,
        'join_date' => '2026-04-23',
        'annual_leave_balance' => 12,
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Created Employee')
        ->assertJsonPath('data.division.name', 'Operations')
        ->assertJsonPath('data.salary_monthly', 5500000)
        ->assertJsonStructure(['data' => ['invite_token', 'invite_expires_at']])
        ->assertJsonMissingPath('data.raw_password');
});

it('updates an employee without requiring password changes', function () {
    [$admin, $token] = adminWithToken();
    $employee = User::factory()->create([
        'company_id' => 1,
        'name' => 'Before Employee',
        'email' => 'before@example.test',
    ]);

    $this->putJson("/api/v1/admin/employees/{$employee->id}", [
        'name' => 'After Employee',
        'nip' => $employee->nip,
        'email' => 'after@example.test',
        'phone' => $employee->phone,
        'gender' => $employee->gender,
        'city' => $employee->city,
        'address' => $employee->address,
        'division_id' => null,
        'education_id' => null,
        'job_title_id' => null,
        'employment_status' => 'probation',
        'salary_monthly' => 3500000,
        'join_date' => '2026-04-23',
        'annual_leave_balance' => 10,
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'After Employee')
        ->assertJsonPath('data.salary_monthly', 3500000);
});

it('rejects employee references from another company', function () {
    [$admin, $token] = adminWithToken();
    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Other Company',
        'slug' => 'other-company',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherDivision = Division::factory()->create(['company_id' => 2, 'name' => 'Other Division']);

    $this->postJson('/api/v1/admin/employees', [
        'name' => 'Invalid Employee',
        'nip' => 'EMP002',
        'email' => 'invalid@example.test',
        'phone' => '08123456789',
        'password' => 'secret123',
        'gender' => 'female',
        'city' => 'Surabaya',
        'address' => 'Jl. Contoh',
        'division_id' => $otherDivision->id,
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('division_id');
});

it('forbids regular employees from accessing employee master endpoints', function () {
    [$employee, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/employees', bearerHeaders($token))
        ->assertForbidden();
});
