<?php

/**
 * Characterization test untuk AuthController API.
 *
 * Tujuannya BUKAN mendokumentasikan perilaku ideal, tapi mengunci perilaku
 * yang berlaku sekarang supaya refactor (mis. memecah controller 1.200 baris
 * ini) gagal keras kalau ada yang berubah diam-diam.
 *
 * Catatan: AuthenticationTest.php yang sudah ada menguji layar login
 * Jetstream (web), bukan API ini. Sebelum berkas ini, permukaan auth API
 * praktis tidak punya tes sama sekali.
 */

use App\Models\User;

uses()->group('api', 'auth');

// ── login ────────────────────────────────────────────────────────────────

it('logs in with email and returns a bearer token', function () {
    $user = User::factory()->create([
        'email' => 'budi@ibereality.com',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'budi@ibereality.com',
        'password' => 'rahasia123',
    ])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'budi@ibereality.com')
        ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'email']]]);

    expect($user->fresh()->tokens()->count())->toBe(1);
});

it('logs in with legacy_email so karyawan lama tetap bisa masuk', function () {
    User::factory()->create([
        'email' => 'sari@ibereality.com',
        'legacy_email' => 'sari.lama@gmail.com',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'sari.lama@gmail.com',
        'password' => 'rahasia123',
    ])->assertOk()->assertJsonPath('data.user.email', 'sari@ibereality.com');
});

it('logs in with nip when identifier bukan email', function () {
    User::factory()->create([
        'nip' => '12345678901234567890',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => '12345678901234567890',
        'password' => 'rahasia123',
    ])->assertOk();
});

it('rejects a wrong password on the identifier field', function () {
    User::factory()->create([
        'email' => 'budi@ibereality.com',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'budi@ibereality.com',
        'password' => 'salah',
    ])->assertUnprocessable()->assertJsonValidationErrors(['identifier']);
});

it('rejects an unknown identifier without leaking that the user is missing', function () {
    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'hantu@ibereality.com',
        'password' => 'apa saja',
    ])->assertUnprocessable()->assertJsonValidationErrors(['identifier']);
});

it('requires both identifier and password', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identifier', 'password']);
});

it('grants admin ability only to admin users', function () {
    [$admin, $adminToken] = adminWithToken();
    [$employee, $employeeToken] = employeeWithToken();

    expect($admin->tokens()->first()->abilities)->toContain('admin')
        ->and($employee->tokens()->first()->abilities)->not->toContain('admin');
});

// ── me / updateMe ────────────────────────────────────────────────────────

it('returns the current user on /me', function () {
    [$user, $token] = employeeWithToken(['email' => 'budi@ibereality.com']);

    $this->getJson('/api/v1/auth/me', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.email', 'budi@ibereality.com');
});

it('rejects /me without a token', function () {
    $this->getJson('/api/v1/auth/me', ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

it('lets a user update their own editable profile fields', function () {
    [$user, $token] = employeeWithToken(['name' => 'Nama Lama']);

    $this->putJson('/api/v1/auth/me', ['name' => 'Nama Baru', 'city' => 'Surabaya'], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'Nama Baru');

    expect($user->fresh()->city)->toBe('Surabaya');
});

it('refuses a ktp_number that is not 16 digits', function () {
    [, $token] = employeeWithToken();

    $this->putJson('/api/v1/auth/me', ['ktp_number' => '123'], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ktp_number']);
});

it('ignores sensitive fields that only admin may change', function () {
    [$user, $token] = employeeWithToken(['employment_status' => 'permanent']);

    $this->putJson('/api/v1/auth/me', [
        'name' => 'Nama Baru',
        'employment_status' => 'probation',
        'salary_monthly' => 999_000_000,
    ], bearerHeaders($token))->assertOk();

    // Tidak ada di daftar validate() jadi tidak pernah sampai ke update().
    expect($user->fresh()->employment_status)->toBe('permanent');
});

// ── changePassword ───────────────────────────────────────────────────────

it('changes the password when current_password is correct', function () {
    [$user, $token] = employeeWithToken(['password' => bcrypt('lama12345')]);

    $this->postJson('/api/v1/auth/me/password', [
        'current_password' => 'lama12345',
        'new_password' => 'Kw9mXzLp7QvR',
        'new_password_confirmation' => 'Kw9mXzLp7QvR',
    ], bearerHeaders($token))->assertOk();

    expect(Hash::check('Kw9mXzLp7QvR', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change when current_password is wrong', function () {
    [$user, $token] = employeeWithToken(['password' => bcrypt('lama12345')]);

    $this->postJson('/api/v1/auth/me/password', [
        'current_password' => 'bukan-ini',
        'new_password' => 'Kw9mXzLp7QvR',
        'new_password_confirmation' => 'Kw9mXzLp7QvR',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('lama12345', $user->fresh()->password))->toBeTrue();
});

it('refuses a new password shorter than the 12 character minimum', function () {
    [, $token] = employeeWithToken(['password' => bcrypt('lama12345')]);

    $this->postJson('/api/v1/auth/me/password', [
        'current_password' => 'lama12345',
        'new_password' => 'Pendek12345',
        'new_password_confirmation' => 'Pendek12345',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['new_password']);
});

it('refuses the literal password "password" that production accounts were using', function () {
    // Insiden 2026-09-22: akun produksi memakai kata sandi "password" dan itu
    // lolos validasi lama (min:8). Tes ini mengunci supaya tidak bisa lagi.
    [, $token] = employeeWithToken(['password' => bcrypt('lama12345')]);

    $this->postJson('/api/v1/auth/me/password', [
        'current_password' => 'lama12345',
        'new_password' => 'password',
        'new_password_confirmation' => 'password',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['new_password']);
});

it('refuses a new password that is not confirmed', function () {
    [, $token] = employeeWithToken(['password' => bcrypt('lama12345')]);

    $this->postJson('/api/v1/auth/me/password', [
        'current_password' => 'lama12345',
        'new_password' => 'Kw9mXzLp7QvR',
        'new_password_confirmation' => 'Zt4bNhSy8WcD',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['new_password']);
});

// ── logout ───────────────────────────────────────────────────────────────

it('revokes only the current token on logout', function () {
    [$user, $token] = employeeWithToken();
    $user->createToken('device-lain');
    expect($user->tokens()->count())->toBe(2);

    $this->postJson('/api/v1/auth/logout', [], bearerHeaders($token))->assertOk();

    // Device lain harus tetap hidup — ini pembeda utama dari logout-all.
    expect($user->fresh()->tokens()->count())->toBe(1);
});

it('revokes every token on logout-all and reports the count', function () {
    [$user, $token] = employeeWithToken();
    $user->createToken('device-lain');
    $user->createToken('device-ketiga');

    $this->postJson('/api/v1/auth/logout-all', [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.revoked_count', 3);

    expect($user->fresh()->tokens()->count())->toBe(0);
});

it('rejects logout without a token', function () {
    $this->postJson('/api/v1/auth/logout', [], ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

// ── refresh ──────────────────────────────────────────────────────────────

it('rejects refresh when there is no refresh cookie', function () {
    $this->postJson('/api/v1/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Refresh token invalid atau kadaluwarsa.');
});

it('sets an HttpOnly refresh cookie at login so it stays out of reach of JS', function () {
    User::factory()->create([
        'email' => 'budi@ibereality.com',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'budi@ibereality.com',
        'password' => 'rahasia123',
    ])->assertOk();

    $cookies = $login->headers->getCookies();
    expect($cookies)->not->toBeEmpty();

    // Sifat keamanan yang wajib dipertahankan refactor apa pun: cookie refresh
    // tidak boleh terbaca JavaScript, kalau tidak XSS bisa mencurinya.
    foreach ($cookies as $cookie) {
        expect($cookie->isHttpOnly())->toBeTrue("cookie {$cookie->getName()} harus HttpOnly");
    }
});

// ── webmail ticket ───────────────────────────────────────────────────────

it('rejects a webmail ticket request without a token', function () {
    $this->postJson('/api/v1/auth/webmail-ticket', [], ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

it('rejects exchanging a webmail ticket that does not exist with 410 Gone', function () {
    // 410 (bukan 404/422) dipilih supaya klien webmail bisa membedakan
    // "tiket hangus/terpakai" dari "request saya salah bentuk".
    $this->postJson('/api/v1/auth/exchange-webmail-ticket', ['ticket' => 'tidak-ada'])
        ->assertStatus(410);
});

// ── accept invite ────────────────────────────────────────────────────────

it('rejects an unknown invite token', function () {
    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => str_repeat('a', 64),
        'password' => 'Kw9mXzLp7QvR',
        'password_confirmation' => 'Kw9mXzLp7QvR',
    ])->assertStatus(422);
});
