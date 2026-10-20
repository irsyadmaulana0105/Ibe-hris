<?php

use App\Models\User;
use App\Models\UserRole;

uses()->group('api', 'role-switching');

it('returns marketing as a switchable role and exposes it in identity', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'group' => 'finance',
        'employment_status' => 'permanent',
    ]);
    UserRole::create([
        'user_id' => $user->id,
        'role' => 'marketing',
        'is_primary' => false,
        'granted_at' => now(),
    ]);
    $headers = bearerHeaders($user->createToken('role-switch', ['admin', 'employee'])->plainTextToken);

    $this->getJson('/api/v1/me/available-roles', $headers)
        ->assertOk()
        ->assertJsonPath('data.primary_role', 'finance')
        ->assertJsonFragment(['role' => 'marketing', 'label' => 'Marketing']);

    $this->getJson('/api/v1/auth/me', $headers)
        ->assertOk()
        ->assertJsonPath('data.group', 'finance')
        ->assertJsonFragment(['available_roles' => ['finance', 'marketing']]);

    expect($user->fresh()->hasCapability('crm.manage'))->toBeTrue();
});
