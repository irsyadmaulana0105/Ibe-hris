<?php

use function Pest\Laravel\post;

/*
 * Pendaftaran mandiri tidak ada: akun karyawan dibuat HR lewat ERP dan diaktifkan
 * dengan undangan. /register di backend dialihkan ke portal SSO (routes/web.php).
 */

test('registration screen redirects to the portal sso', function () {
    $this->get('/register')
        ->assertRedirectContains('/login?target_app=erp');
});

test('posting a registration to the backend creates no account', function () {
    post('/register', [
        'name' => 'Test User',
        'email' => 'daftar-sendiri@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectContains('/login?target_app=erp');

    $this->assertGuest();
    expect(\App\Models\User::query()->where('email', 'daftar-sendiri@example.com')->exists())->toBeFalse();
});
