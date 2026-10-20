<?php

use App\Models\User;

uses()->group('api', 'profile-photo-privacy');

it('generates a local data avatar without contacting a third party', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'name' => 'Aji Sapta',
        'profile_photo_path' => null,
    ]);

    $url = (string) $user->profile_photo_url;
    expect($url)
        ->toStartWith('data:image/svg+xml,')
        ->not->toContain('http://')
        ->not->toContain('https://')
        ->not->toContain('ui-avatars.com')
        ->and(rawurldecode(substr($url, strlen('data:image/svg+xml,'))))
        ->toContain('A S');
});

it('keeps hostile display names inert in both the model and authenticated API', function () {
    [$user, $token] = employeeWithToken([
        'company_id' => 1,
        'name' => '<script>alert(1)</script> User',
        'profile_photo_path' => null,
    ]);

    $url = (string) $user->profile_photo_url;
    $decoded = rawurldecode(substr($url, strlen('data:image/svg+xml,')));

    expect($decoded)
        ->not->toContain('<script>')
        ->not->toContain('</script>')
        ->not->toContain('ui-avatars.com');

    $apiUrl = (string) $this->getJson('/api/v1/auth/me', bearerHeaders($token))
        ->assertOk()
        ->json('data.avatar');
    expect($apiUrl)
        ->toStartWith('data:image/svg+xml,')
        ->not->toContain('ui-avatars.com')
        ->not->toContain('http://')
        ->not->toContain('https://');
});
