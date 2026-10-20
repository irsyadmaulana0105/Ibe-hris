<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Jetstream\Http\Livewire\UpdatePasswordForm;
use Livewire\Livewire;


beforeEach(fn () => $this->markTestSkipped('UI profil Jetstream (Livewire) sengaja nonaktif: livewire/livewire ada di composer.json extra.laravel.dont-discover dan akun dikelola lewat portal SSO. Aktifkan lagi bila Livewire dihidupkan.'));

test('password can be updated', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test(UpdatePasswordForm::class)
        ->set('state', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword');

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('current password must be correct', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test(UpdatePasswordForm::class)
        ->set('state', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('new passwords must match', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test(UpdatePasswordForm::class)
        ->set('state', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'wrong-password',
        ])
        ->call('updatePassword')
        ->assertHasErrors(['password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});
