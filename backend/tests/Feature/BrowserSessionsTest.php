<?php

use App\Models\User;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm;
use Livewire\Livewire;


beforeEach(fn () => $this->markTestSkipped('UI profil Jetstream (Livewire) sengaja nonaktif: livewire/livewire ada di composer.json extra.laravel.dont-discover dan akun dikelola lewat portal SSO. Aktifkan lagi bila Livewire dihidupkan.'));

test('other browser sessions can be logged out', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(LogoutOtherBrowserSessionsForm::class)
        ->set('password', 'password')
        ->call('logoutOtherBrowserSessions')
        ->assertSuccessful();
});
