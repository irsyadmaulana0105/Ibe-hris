<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/*
 * Reset sandi ditangani portal SSO; /forgot-password di backend dialihkan ke sana
 * (routes/web.php). Tes Jetstream bawaan (halaman reset + email ResetPassword dari
 * backend) sudah usang. Yang dikunci di sini: backend tidak mengirim email reset
 * sendiri, sehingga tidak ada jalur reset sandi kedua di luar portal.
 */

test('reset password link screen redirects to the portal sso', function () {
    $this->get('/forgot-password')
        ->assertRedirectContains('/login?target_app=erp');
});

test('requesting a reset link on the backend sends nothing and redirects to the portal', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertRedirectContains('/login?target_app=erp');

    Notification::assertNotSentTo($user, ResetPassword::class);
});
