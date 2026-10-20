<?php

use App\Models\User;
use App\Models\SharedMailbox;
use App\Services\WebmailGatewaySessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('services.stalwart.webmail_url', 'https://webmail.example.test');
    config()->set('webmail_gateway.internal_secret', 'test-internal-secret');
    config()->set('webmail_gateway.allow_legacy_credential_exchange', false);
});

it('issues an opaque post exchange without exposing mailbox credentials', function () {
    [$user, $token] = adminWithToken(['company_id' => 1]);
    $user->forceFill([
        'email' => 'mailbox@example.test',
        'mailbox_password_encrypted' => Crypt::encryptString('never-in-browser'),
    ])->save();

    $response = $this->postJson('/api/v1/auth/webmail-ticket', [], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.post_url', 'https://webmail.example.test/sso/exchange')
        ->assertJsonMissingPath('data.redirect_url')
        ->assertJsonMissingPath('data.accounts')
        ->assertJsonMissingPath('data.password');

    $plainTicket = $response->json('data.ticket');
    expect($plainTicket)->toHaveLength(64)
        ->and(DB::table('webmail_sso_tickets')->value('ticket'))
        ->toBe(hash('sha256', $plainTicket));
});

it('redeems once into an httponly server side gateway session', function () {
    $user = User::factory()->admin()->create([
        'company_id' => 1,
        'email' => 'mailbox@example.test',
        'mailbox_password_encrypted' => Crypt::encryptString('server-only-password'),
    ]);
    $plainTicket = str_repeat('a', 64);
    DB::table('webmail_sso_tickets')->insert([
        'ticket' => hash('sha256', $plainTicket), 'user_id' => $user->id,
        'expires_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = $this->post('/api/v1/auth/webmail-gateway/exchange', ['ticket' => $plainTicket]);
    $response->assertRedirect('/')->assertCookie(config('webmail_gateway.cookie'));
    expect(DB::table('webmail_gateway_sessions')->count())->toBe(1)
        ->and(DB::table('webmail_gateway_sessions')->value('token_hash'))->not->toBeNull()
        ->and((string) $response->getContent())->not->toContain('server-only-password');

    $this->post('/api/v1/auth/webmail-gateway/exchange', ['ticket' => $plainTicket])
        ->assertGone();
});

it('keeps upstream authorization private behind an internal secret', function () {
    $this->get('/api/v1/auth/webmail-gateway/authorize')->assertNotFound();
});

it('switches an authorized shared mailbox without exposing its password', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'group' => 'marketing',
        'email' => 'marketing@example.test',
        'mailbox_password_encrypted' => Crypt::encryptString('personal-secret'),
    ]);
    SharedMailbox::create([
        'email' => 'admin@example.test',
        'display_name' => 'Shared Admin',
        'password_encrypted' => Crypt::encryptString('shared-secret'),
        'allowed_groups' => [],
        'allowed_user_emails' => [$user->email],
        'is_active' => true,
    ]);
    $plainSession = str_repeat('b', 64);
    DB::table('webmail_gateway_sessions')->insert([
        'token_hash' => hash('sha256', $plainSession),
        'user_id' => $user->id,
        'mailbox_email' => $user->email,
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $request = Request::create('/sso/session', 'GET');
    $request->cookies->set(config('webmail_gateway.cookie'), $plainSession);
    $service = app(WebmailGatewaySessionService::class);
    $session = $service->resolve($request);
    $mailboxes = $service->availableMailboxes($session);
    expect($mailboxes)->toContainEqual([
        'email' => 'admin@example.test',
        'display_name' => 'Shared Admin',
        'shared' => true,
    ])->and(json_encode($mailboxes))->not->toContain('shared-secret');

    $session = $service->switchMailbox($request, 'admin@example.test');

    expect(DB::table('webmail_gateway_sessions')->value('mailbox_email'))
        ->toBe('admin@example.test');

    $authorization = $service->upstreamAuthorization($session);
    expect($authorization)->toBe('Basic '.base64_encode('admin@example.test:shared-secret'));
});

it('lists and switches an authorized shared mailbox through the HTTP gateway contract', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'group' => 'admin',
        'email' => 'aji@example.test',
        'mailbox_password_encrypted' => Crypt::encryptString('personal-secret'),
    ]);
    SharedMailbox::create([
        'email' => 'admin@example.test',
        'display_name' => 'Shared Admin',
        'password_encrypted' => Crypt::encryptString('shared-secret'),
        'allowed_groups' => [],
        'allowed_user_emails' => [$user->email],
        'is_active' => true,
    ]);
    $plainSession = str_repeat('d', 64);
    DB::table('webmail_gateway_sessions')->insert([
        'token_hash' => hash('sha256', $plainSession),
        'user_id' => $user->id,
        'mailbox_email' => $user->email,
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withCredentials()
        ->withUnencryptedCookie(config('webmail_gateway.cookie'), $plainSession)
        ->getJson('/api/v1/auth/webmail-gateway/session')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.mailboxes.1.email', 'admin@example.test')
        ->assertJsonPath('data.mailboxes.1.shared', true)
        ->assertJsonMissing(['shared-secret']);

    $this->withCredentials()
        ->withUnencryptedCookie(config('webmail_gateway.cookie'), $plainSession)
        ->postJson('/api/v1/auth/webmail-gateway/switch', ['email' => 'admin@example.test'])
        ->assertOk()
        ->assertJsonPath('data.email', 'admin@example.test')
        ->assertJsonPath('data.mailboxes.1.email', 'admin@example.test')
        ->assertJsonMissing(['shared-secret']);

    expect(DB::table('webmail_gateway_sessions')->value('mailbox_email'))
        ->toBe('admin@example.test');
});

it('rejects switching to a shared mailbox that is not assigned to the caller', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'group' => 'marketing',
        'email' => 'unassigned@example.test',
        'mailbox_password_encrypted' => Crypt::encryptString('personal-secret'),
    ]);
    SharedMailbox::create([
        'email' => 'restricted@example.test',
        'display_name' => 'Restricted',
        'password_encrypted' => Crypt::encryptString('restricted-secret'),
        'allowed_groups' => [],
        'allowed_user_emails' => ['someone-else@example.test'],
        'is_active' => true,
    ]);
    $plainSession = str_repeat('c', 64);
    DB::table('webmail_gateway_sessions')->insert([
        'token_hash' => hash('sha256', $plainSession),
        'user_id' => $user->id,
        'mailbox_email' => $user->email,
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $request = Request::create('/sso/switch', 'POST');
    $request->cookies->set(config('webmail_gateway.cookie'), $plainSession);

    expect(fn () => app(WebmailGatewaySessionService::class)
        ->switchMailbox($request, 'restricted@example.test'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('disables the legacy plaintext credential exchange by default', function () {
    $this->postJson('/api/v1/auth/exchange-webmail-ticket', ['ticket' => str_repeat('a', 64)])
        ->assertGone()
        ->assertJsonMissingPath('data.accounts');
});
