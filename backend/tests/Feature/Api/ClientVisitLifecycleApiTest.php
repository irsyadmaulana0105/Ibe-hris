<?php

use App\Models\ClientVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'client-visit');

it('uses the database status contract for the complete client visit lifecycle', function () {
    [$employee, $token] = employeeWithToken(['company_id' => 1]);

    $started = $this->postJson('/api/v1/client-visits/in', [
        'date' => today()->toDateString(),
        'notes_in' => 'Pertemuan dengan pelanggan',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.status', 'ongoing');

    $visitId = $started->json('data.id');
    $this->assertDatabaseHas('client_visits', [
        'id' => $visitId,
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'ongoing',
        'visit_out' => null,
    ]);

    $this->getJson('/api/v1/client-visits', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.0.id', $visitId)
        ->assertJsonPath('data.0.status', 'ongoing');

    $this->postJson("/api/v1/client-visits/{$visitId}/out", [
        'notes_out' => 'Pertemuan selesai dan tindak lanjut dicatat',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $this->assertDatabaseHas('client_visits', [
        'id' => $visitId,
        'company_id' => 1,
        'user_id' => $employee->id,
        'status' => 'completed',
    ]);
    expect(ClientVisit::withoutGlobalScopes()->findOrFail($visitId)->visit_out)->not->toBeNull();
});

it('filters admin client visits with the exact ongoing status and preserves tenant scope', function () {
    [, $adminToken] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $employee = User::factory()->create(['company_id' => 1]);
    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Tenant Lain',
        'slug' => 'tenant-lain-client-visit',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherTenantEmployee = User::factory()->create(['company_id' => 2]);

    $ongoing = ClientVisit::withoutGlobalScopes()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'date' => today()->toDateString(),
        'visit_in' => now(),
        'notes_in' => 'Masih berlangsung',
        'status' => 'ongoing',
    ]);
    ClientVisit::withoutGlobalScopes()->create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'date' => today()->subDay()->toDateString(),
        'visit_in' => now()->subDay(),
        'visit_out' => now()->subDay()->addHour(),
        'notes_in' => 'Sudah selesai',
        'notes_out' => 'Selesai',
        'status' => 'completed',
    ]);
    ClientVisit::withoutGlobalScopes()->create([
        'company_id' => 2,
        'user_id' => $otherTenantEmployee->id,
        'date' => today()->toDateString(),
        'visit_in' => now(),
        'notes_in' => 'Tenant lain',
        'status' => 'ongoing',
    ]);

    $this->getJson('/api/v1/admin/client-visits?status=ongoing', bearerHeaders($adminToken))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ongoing->id)
        ->assertJsonPath('data.0.status', 'ongoing');

    $this->getJson('/api/v1/admin/client-visits?status=in_progress', bearerHeaders($adminToken))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});
