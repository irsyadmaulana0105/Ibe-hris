<?php

/**
 * Verifikasi dua langkah pada login berbasis token.
 *
 * Yang paling penting dijaga di berkas ini bukan fitur barunya, melainkan
 * REGRESI: mayoritas akun tidak memakai 2FA, dan login mereka harus tetap
 * persis seperti sebelumnya. Kalau cabang 2FA bocor ke akun biasa, semua
 * orang terkunci dari semua platform sekaligus.
 */

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

uses()->group('api', 'auth');

/** Daftarkan 2FA pada satu pengguna, kembalikan rahasianya. */
function enableTwoFactorFor(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey();

    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(
            collect()->times(8, fn () => RecoveryCode::generate())->all()
        )),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $secret;
}

function currentOtp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

// ── regresi: akun tanpa 2FA ──────────────────────────────────────────────

it('leaves login untouched for accounts without two factor', function () {
    User::factory()->create([
        'email' => 'biasa@ibereality.com',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'biasa@ibereality.com',
        'password' => 'rahasia123',
    ])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['token']])
        // Tidak boleh ada jejak alur 2FA sama sekali.
        ->assertJsonMissingPath('data.two_factor_required');
});

// ── login dengan 2FA aktif ───────────────────────────────────────────────

it('withholds the token and returns a challenge when two factor is on', function () {
    $user = User::factory()->create([
        'email' => 'aman@ibereality.com',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);
    enableTwoFactorFor($user);

    $res = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'aman@ibereality.com',
        'password' => 'rahasia123',
    ])
        ->assertOk()
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonStructure(['data' => ['challenge', 'expires_in']]);

    // Yang paling kritis: tidak ada token yang lolos di tahap ini.
    expect($res->json('data.token'))->toBeNull()
        ->and($user->fresh()->tokens()->count())->toBe(0);
});

it('issues a token once the correct TOTP code is supplied', function () {
    $user = User::factory()->create([
        'email' => 'aman@ibereality.com',
        'password' => bcrypt('rahasia123'),
        'company_id' => 1,
    ]);
    $secret = enableTwoFactorFor($user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'aman@ibereality.com',
        'password' => 'rahasia123',
    ])->json('data.challenge');

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge' => $challenge,
        'code' => currentOtp($secret),
    ])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'aman@ibereality.com');

    expect($user->fresh()->tokens()->count())->toBe(1);
});

it('rejects a wrong TOTP code', function () {
    $user = User::factory()->create(['password' => bcrypt('rahasia123'), 'company_id' => 1]);
    enableTwoFactorFor($user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'identifier' => $user->email,
        'password' => 'rahasia123',
    ])->json('data.challenge');

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge' => $challenge,
        'code' => '000000',
    ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect($user->fresh()->tokens()->count())->toBe(0);
});

it('accepts a recovery code and burns it so it cannot be replayed', function () {
    $user = User::factory()->create(['password' => bcrypt('rahasia123'), 'company_id' => 1]);
    enableTwoFactorFor($user);
    $recovery = $user->fresh()->recoveryCodes()[0];

    $login = fn () => $this->postJson('/api/v1/auth/login', [
        'identifier' => $user->email,
        'password' => 'rahasia123',
    ])->json('data.challenge');

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge' => $login(),
        'code' => $recovery,
    ])->assertOk();

    expect($user->fresh()->recoveryCodes())->not->toContain($recovery);

    // Pemakaian kedua harus gagal.
    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge' => $login(),
        'code' => $recovery,
    ])->assertUnprocessable();
});

it('treats the challenge ticket as single use', function () {
    $user = User::factory()->create(['password' => bcrypt('rahasia123'), 'company_id' => 1]);
    $secret = enableTwoFactorFor($user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'identifier' => $user->email,
        'password' => 'rahasia123',
    ])->json('data.challenge');

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge' => $challenge,
        'code' => currentOtp($secret),
    ])->assertOk();

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge' => $challenge,
        'code' => currentOtp($secret),
    ])->assertUnprocessable()->assertJsonValidationErrors(['challenge']);
});

it('rejects a challenge ticket that was never issued', function () {
    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge' => str_repeat('a', 64),
        'code' => '123456',
    ])->assertUnprocessable()->assertJsonValidationErrors(['challenge']);
});

// ── pendaftaran ──────────────────────────────────────────────────────────

it('does not activate two factor until the code is confirmed', function () {
    [$user, $token] = employeeWithToken();

    $this->postJson('/api/v1/auth/two-factor/enable', [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonStructure(['data' => ['qr_svg', 'secret', 'recovery_codes']]);

    // Rahasia sudah ada, tapi belum dikonfirmasi — login harus tetap normal.
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $this->getJson('/api/v1/auth/two-factor', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.pending_confirmation', true);
});

it('activates two factor after a valid confirmation code', function () {
    [$user, $token] = employeeWithToken();

    $secret = $this->postJson('/api/v1/auth/two-factor/enable', [], bearerHeaders($token))
        ->json('data.secret');

    $this->postJson('/api/v1/auth/two-factor/confirm', [
        'code' => currentOtp($secret),
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonStructure(['data' => ['recovery_codes']]);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('refuses confirmation with a wrong code', function () {
    [$user, $token] = employeeWithToken();
    $this->postJson('/api/v1/auth/two-factor/enable', [], bearerHeaders($token));

    $this->postJson('/api/v1/auth/two-factor/confirm', [
        'code' => '000000',
    ], bearerHeaders($token))->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

// ── mematikan ────────────────────────────────────────────────────────────

it('requires the password to disable two factor', function () {
    [$user, $token] = employeeWithToken(['password' => bcrypt('rahasia123')]);
    enableTwoFactorFor($user);

    // Token curian saja tidak cukup untuk mematikan 2FA.
    $this->deleteJson('/api/v1/auth/two-factor', [
        'password' => 'salah',
    ], bearerHeaders($token))->assertUnprocessable()->assertJsonValidationErrors(['password']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->deleteJson('/api/v1/auth/two-factor', [
        'password' => 'rahasia123',
    ], bearerHeaders($token))->assertOk();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('rejects two factor management without a token', function () {
    $this->getJson('/api/v1/auth/two-factor', ['Accept' => 'application/json'])
        ->assertUnauthorized();
});
