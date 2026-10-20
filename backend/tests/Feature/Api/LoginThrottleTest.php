<?php

/**
 * 19 Okt 2026: Direktur tidak bisa login dari kantor ("Too Many Attempts.")
 * padahal baru mencoba sekali. Semua karyawan kantor keluar lewat satu IP
 * publik, dan semua rute `throttle:X,Y` berbagi satu penghitung per IP -
 * panggilan latar belakang (refresh, SSO verify) menghabiskan jatah login.
 */

use App\Models\User;

uses()->group('api', 'auth');

beforeEach(function () {
    \Illuminate\Support\Facades\RateLimiter::clear('dummy');
    cache()->flush();
});

function cobaLogin($test, string $identifier, string $password = 'salah-sandi-xyz', string $ip = '119.235.222.55')
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => $password]);
}

it('does not let other throttled routes from the same office IP use up the login quota', function () {
    // 25 panggilan latar belakang dari IP kantor yang sama (batas refresh 30/menit).
    for ($i = 0; $i < 25; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '119.235.222.55'])->postJson('/api/v1/auth/refresh');
    }

    $user = User::factory()->create(['email' => 'direktur@ibe.test', 'password' => bcrypt('Sandi-yang-benar-123')]);

    cobaLogin($this, 'direktur@ibe.test', 'Sandi-yang-benar-123')->assertStatus(200);
});

it('locks one account after 10 attempts without blocking colleagues on the same IP', function () {
    for ($i = 0; $i < 10; $i++) {
        cobaLogin($this, 'korban@ibe.test')->assertStatus(422);
    }
    cobaLogin($this, 'korban@ibe.test')->assertStatus(429);

    // Rekan sekantor (IP sama, akun lain) tetap bisa mencoba.
    cobaLogin($this, 'rekan@ibe.test')->assertStatus(422);
});

it('still stops a bot that sprays many accounts from one IP', function () {
    for ($i = 0; $i < 100; $i++) {
        cobaLogin($this, "akun{$i}@ibe.test", 'x', '203.0.113.9');
    }
    cobaLogin($this, 'akun-lain@ibe.test', 'x', '203.0.113.9')->assertStatus(429);
});
