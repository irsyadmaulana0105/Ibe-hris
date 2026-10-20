<?php

use App\Models\Company;
use App\Models\User;

uses()->group('api', 'face-approvals');

function faceDescriptor(array $values): string
{
    return json_encode($values, JSON_THROW_ON_ERROR);
}

it('lists pending face registrations for the admin company only', function () {
    [$admin, $token] = adminWithToken();
    $otherCompany = Company::create([
        'name' => 'PT Company Lain',
        'slug' => 'pt-company-lain',
        'is_active' => true,
    ]);

    $newRegistration = User::factory()->create([
        'company_id' => $admin->company_id,
        'name' => 'Pending Baru',
        'face_descriptor' => null,
        'face_descriptor_pending' => faceDescriptor([0.1, 0.2, 0.3]),
    ]);

    $faceUpdate = User::factory()->create([
        'company_id' => $admin->company_id,
        'name' => 'Pending Update',
        'face_descriptor' => faceDescriptor([0.4, 0.5, 0.6]),
        'face_descriptor_pending' => faceDescriptor([0.7, 0.8, 0.9]),
    ]);

    $differentCompanyUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Beda Company',
        'face_descriptor_pending' => faceDescriptor([1.0, 1.1, 1.2]),
    ]);

    $noPending = User::factory()->create([
        'company_id' => $admin->company_id,
        'name' => 'Tidak Pending',
        'face_descriptor' => faceDescriptor([1.3, 1.4, 1.5]),
        'face_descriptor_pending' => null,
    ]);

    $this->getJson('/api/v1/admin/face-approvals', bearerHeaders($token))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment([
            'id' => $newRegistration->id,
            'name' => 'Pending Baru',
            'has_active' => false,
        ])
        ->assertJsonFragment([
            'id' => $faceUpdate->id,
            'name' => 'Pending Update',
            'has_active' => true,
        ])
        ->assertJsonMissing(['id' => $differentCompanyUser->id])
        ->assertJsonMissing(['id' => $noPending->id]);
});

it('approves a pending face registration', function () {
    [$admin, $token] = adminWithToken();

    $employee = User::factory()->create([
        'company_id' => $admin->company_id,
        'face_descriptor' => null,
        'face_descriptor_pending' => faceDescriptor([0.1, 0.2, 0.3]),
    ]);

    $this->postJson("/api/v1/admin/face-approvals/{$employee->id}/approve", [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('message', "Wajah {$employee->name} berhasil disetujui.");

    $employee->refresh();

    expect($employee->face_descriptor_pending)->toBeNull();
    expect(json_decode($employee->face_descriptor, true))->toBe([0.1, 0.2, 0.3]);
});

it('rejects a pending face update without clearing the active face descriptor', function () {
    [$admin, $token] = adminWithToken();

    $employee = User::factory()->create([
        'company_id' => $admin->company_id,
        'face_descriptor' => faceDescriptor([0.4, 0.5, 0.6]),
        'face_descriptor_pending' => faceDescriptor([0.7, 0.8, 0.9]),
    ]);

    $this->postJson("/api/v1/admin/face-approvals/{$employee->id}/reject", [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('message', "Pendaftaran wajah {$employee->name} ditolak.");

    $employee->refresh();

    expect($employee->face_descriptor_pending)->toBeNull();
    expect(json_decode($employee->face_descriptor, true))->toBe([0.4, 0.5, 0.6]);
});

it('forbids non-admin users from accessing face approvals', function () {
    [$employee, $token] = employeeWithToken([
        'face_descriptor_pending' => faceDescriptor([0.1, 0.2, 0.3]),
    ]);

    $this->getJson('/api/v1/admin/face-approvals', bearerHeaders($token))
        ->assertForbidden();

    $this->postJson("/api/v1/admin/face-approvals/{$employee->id}/approve", [], bearerHeaders($token))
        ->assertForbidden();
});
