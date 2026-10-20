<?php

/**
 * Paket pengembangan HRIS (20 Okt 2026): programmer IBE menjalankan
 * LocalHrisDevSeeder di laptop lalu login sebagai admin & karyawan.
 */

use App\Models\User;
use Database\Seeders\LocalHrisDevSeeder;

uses()->group('api', 'seeder');

function setDemoEnv(array $values): void
{
    foreach ($values as $key => $value) {
        putenv($value === null ? $key : "{$key}={$value}");
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }
}

$sandi = [
    'IBE_ALLOW_DEMO_ADMIN_SEEDING' => 'YES-I-UNDERSTAND',
    'IBE_DEMO_SUPERADMIN_PASSWORD' => 'uji-superadmin-9f2c1e7a44b3',
    'IBE_DEMO_ADMIN_PASSWORD' => 'uji-admin-hr-7d1a0c9e55f2',
    'IBE_DEMO_EMPLOYEE_PASSWORD' => 'uji-karyawan-3b8e6f1d22a9',
];

afterEach(fn () => setDemoEnv(array_fill_keys(array_keys($sandi), null)));

it('seeds admin and employee accounts that can log in and reach attendance', function () use ($sandi) {
    setDemoEnv($sandi);

    $this->seed(LocalHrisDevSeeder::class);
    $this->seed(LocalHrisDevSeeder::class); // idempoten: aman dijalankan ulang

    expect(User::where('email', LocalHrisDevSeeder::EMPLOYEE_EMAIL)->count())->toBe(1);

    foreach ([
        ['superadmin@example.com', $sandi['IBE_DEMO_SUPERADMIN_PASSWORD']],
        ['admin@example.com', $sandi['IBE_DEMO_ADMIN_PASSWORD']],
        [LocalHrisDevSeeder::EMPLOYEE_EMAIL, $sandi['IBE_DEMO_EMPLOYEE_PASSWORD']],
    ] as [$email, $password]) {
        app('auth')->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['identifier' => $email, 'password' => $password])
            ->assertOk()->json('data.token');
        expect($token)->toBeString()->not->toBeEmpty();

        if ($email === LocalHrisDevSeeder::EMPLOYEE_EMAIL) {
            $this->getJson('/api/v1/attendances/today', ['Authorization' => "Bearer {$token}"])->assertOk();
        }
        if ($email === 'admin@example.com') {
            $this->getJson('/api/v1/admin/face-approvals', ['Authorization' => "Bearer {$token}"])->assertOk();
        }
    }
});

it('refuses to seed without the explicit opt-in or a strong employee password', function () use ($sandi) {
    setDemoEnv(['IBE_ALLOW_DEMO_ADMIN_SEEDING' => null] + $sandi);
    expect(fn () => $this->seed(LocalHrisDevSeeder::class))->toThrow(RuntimeException::class);

    setDemoEnv(['IBE_DEMO_EMPLOYEE_PASSWORD' => 'pendek'] + $sandi);
    expect(fn () => $this->seed(LocalHrisDevSeeder::class))->toThrow(RuntimeException::class);
});
