<?php

use App\Models\User;
use App\Services\InviteTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

uses()->group('api', 'invite');

// ── Create employee returns invite token ──────────────────────────────────────

it('creates an employee and returns a one-time invite token', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $this->postJson('/api/v1/admin/employees', [
        'name'    => 'Invite Employee',
        'nip'     => 'EMP-INVITE',
        'email'   => 'invite@example.test',
        'phone'   => '08123456789',
        'gender'  => 'male',
        'city'    => 'Surabaya',
        'address' => 'Jl. Undangan No. 1',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Invite Employee')
        ->assertJsonStructure(['data' => ['invite_token', 'invite_expires_at']])
        ->assertJsonMissingPath('data.raw_password');
});

it('creates an employee without providing a password', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $response = $this->postJson('/api/v1/admin/employees', [
        'name'    => 'No Password Employee',
        'nip'     => 'EMP-NOPASS',
        'email'   => 'nopass@example.test',
        'phone'   => '08123456789',
        'gender'  => 'female',
        'city'    => 'Malang',
        'address' => 'Jl. Tanpa Password',
    ], bearerHeaders($token));

    $response->assertCreated();
    $inviteToken = $response->json('data.invite_token');
    expect($inviteToken)->toBeString()->toHaveLength(64);
});

// ── Accept invite ─────────────────────────────────────────────────────────────

it('employee can accept invite and receives an auth token', function () {
    $employee = User::factory()->create([
        'company_id' => 1,
        'group'      => 'user',
        'password'   => Hash::make('temporary'),
    ]);

    $plainToken = app(InviteTokenService::class)->generate($employee);

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                 => $plainToken,
        'password'              => 'Zq7-kuda-laut-84-bintang',
        'password_confirmation' => 'Zq7-kuda-laut-84-bintang',
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'token_type', 'user']])
        ->assertJsonPath('data.token_type', 'Bearer');

    // Token must be consumed - same token cannot be reused
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                 => $plainToken,
        'password'              => 'Wn9-cahaya-pagi-27-sawah',
        'password_confirmation' => 'Wn9-cahaya-pagi-27-sawah',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');
});

it('employee can login with the new password after accepting invite', function () {
    $employee = User::factory()->create([
        'company_id' => 1,
        'group'      => 'user',
        'email'      => 'newhire@example.test',
        'password'   => Hash::make('temporary'),
    ]);

    $plainToken = app(InviteTokenService::class)->generate($employee);

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                 => $plainToken,
        'password'              => 'Pj3-ombak-senja-52-karang',
        'password_confirmation' => 'Pj3-ombak-senja-52-karang',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'newhire@example.test',
        'password'   => 'Pj3-ombak-senja-52-karang',
    ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
});

it('rejects invalid invite token with 422', function () {
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                 => 'invalid-token-that-does-not-exist',
        'password'              => 'Zq7-kuda-laut-84-bintang',
        'password_confirmation' => 'Zq7-kuda-laut-84-bintang',
    ])->assertUnprocessable()
      ->assertJsonValidationErrors('token');
});

it('rejects expired invite token', function () {
    $employee = User::factory()->create([
        'company_id' => 1,
        'group'      => 'user',
        'password'   => Hash::make('temporary'),
    ]);

    $plainToken = app(InviteTokenService::class)->generate($employee);

    // Expire the token by backdating it
    $employee->update(['invite_token_expires_at' => Carbon::now()->subHour()]);

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                 => $plainToken,
        'password'              => 'Zq7-kuda-laut-84-bintang',
        'password_confirmation' => 'Zq7-kuda-laut-84-bintang',
    ])->assertUnprocessable()
      ->assertJsonValidationErrors('token');
});

it('rejects mismatched password confirmation', function () {
    $employee = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $plainToken = app(InviteTokenService::class)->generate($employee);

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                 => $plainToken,
        'password'              => 'Hk4-sungai-fajar-61-batu',
        'password_confirmation' => 'different456',
    ])->assertUnprocessable()
      ->assertJsonValidationErrors('password');
});

// ── Regenerate invite ─────────────────────────────────────────────────────────

it('admin can regenerate invite token for an employee', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $employee = User::factory()->create([
        'company_id' => 1,
        'group'      => 'user',
    ]);

    $firstInvite = app(InviteTokenService::class)->generate($employee);

    $response = $this->postJson(
        "/api/v1/admin/employees/{$employee->id}/regenerate-invite",
        [],
        bearerHeaders($token)
    );

    $response->assertOk()
             ->assertJsonStructure(['data' => ['invite_token', 'invite_expires_at']]);

    $newToken = $response->json('data.invite_token');
    expect($newToken)->not->toBe($firstInvite);

    // Old token is now invalid
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                 => $firstInvite,
        'password'              => 'Hk4-sungai-fajar-61-batu',
        'password_confirmation' => 'Hk4-sungai-fajar-61-batu',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');
});

it('regular employees cannot regenerate invite tokens', function () {
    [$employee, $token] = employeeWithToken(['company_id' => 1]);

    $target = User::factory()->create(['company_id' => 1, 'group' => 'user']);

    $this->postJson(
        "/api/v1/admin/employees/{$target->id}/regenerate-invite",
        [],
        bearerHeaders($token)
    )->assertForbidden();
});

// ── Security: raw_password never exposed ─────────────────────────────────────

it('raw_password is never returned in any employee API response', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    User::factory()->create(['company_id' => 1, 'name' => 'Secret Employee']);

    $this->getJson('/api/v1/admin/employees', bearerHeaders($token))
        ->assertOk()
        ->assertJsonMissingPath('data.0.raw_password');
});
