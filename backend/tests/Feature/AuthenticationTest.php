<?php

use App\Models\User;

/*
 * Login ke backend hanya lewat portal SSO (routes/web.php: Route::redirect('/login', …)
 * dan FortifyServiceProvider::loginView). Tes Jetstream bawaan yang mengharapkan
 * halaman login dan login form di backend sudah usang sejak migrasi ke SPA; tes
 * ini mengunci perilaku yang sekarang disengaja: tidak ada jalur login kedua.
 */

test('login screen redirects to the portal sso', function () {
    $this->get('/login')
        ->assertRedirectContains('/login?target_app=erp');
});

test('posting credentials to the backend login does not authenticate anyone', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirectContains('/login?target_app=erp');

    $this->assertGuest();
});

test('admins cannot bypass the portal through the backend login form either', function () {
    $admin = User::factory()->admin()->create();

    $this->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirectContains('/login?target_app=erp');

    $this->assertGuest();
});
