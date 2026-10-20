<?php

use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses()->group('api');

function webhookSuperadminToken(): string
{
    $user = User::factory()->admin(true)->create(['company_id' => 1]);

    return $user->createToken('webhook-test', ['admin', 'employee'])->plainTextToken;
}

it('returns a correlation id for unauthenticated api errors', function () {
    $this->getJson('/api/v1/admin/webhooks', [
        'X-Correlation-Id' => 'unauth-request-123',
    ])->assertUnauthorized()
        ->assertHeader('X-Correlation-Id', 'unauth-request-123');
});

it('allows only superadmin to manage tenant webhook endpoints', function () {
    [, $adminToken] = adminWithToken();
    $this->getJson('/api/v1/admin/webhooks', bearerHeaders($adminToken))->assertForbidden();
    $this->app['auth']->forgetGuards();

    $superToken = webhookSuperadminToken();
    $response = $this->postJson('/api/v1/admin/webhooks', [
        'name' => 'Commerce integration',
        'url' => 'https://93.184.216.34/ibe-events',
        'events' => ['invoice.issued', 'payment.completed'],
    ], [...bearerHeaders($superToken), 'Idempotency-Key' => 'webhook-create-001']);

    $response->assertCreated()
        ->assertJsonPath('data.endpoint.name', 'Commerce integration')
        ->assertJsonPath('data.endpoint.secret', null)
        ->assertJsonStructure(['data' => ['signing_secret']])
        ->assertHeader('X-Correlation-Id');

    $replay = $this->postJson('/api/v1/admin/webhooks', [
        'name' => 'Commerce integration',
        'url' => 'https://93.184.216.34/ibe-events',
        'events' => ['invoice.issued', 'payment.completed'],
    ], [...bearerHeaders($superToken), 'Idempotency-Key' => 'webhook-create-001']);

    $replay->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect(WebhookEndpoint::count())->toBe(1);
});

it('rejects an idempotency key reused for a different request', function () {
    $token = webhookSuperadminToken();
    $headers = [...bearerHeaders($token), 'Idempotency-Key' => 'webhook-create-002'];
    $this->postJson('/api/v1/admin/webhooks', ['name' => 'A', 'url' => 'https://93.184.216.34/a', 'events' => ['*']], $headers)->assertCreated();
    $this->postJson('/api/v1/admin/webhooks', ['name' => 'B', 'url' => 'https://93.184.216.34/b', 'events' => ['*']], $headers)->assertStatus(409);
});

it('creates an outbox event and sends a signed json webhook', function () {
    Queue::fake();
    $endpoint = WebhookEndpoint::create([
        'company_id' => 1,
        'name' => 'Accounting target',
        'url' => 'https://93.184.216.34/webhooks',
        'secret' => 'whsec_test_secret',
        'events' => ['invoice.issued'],
        'active' => true,
    ]);

    $event = app(WebhookService::class)->publish(1, 'invoice.issued', ['invoice_id' => 44], 'corr-test-123');
    $delivery = WebhookDelivery::firstOrFail();
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->deliveryId === $delivery->id);

    Http::fake(['93.184.216.34/*' => Http::response(['accepted' => true], 202)]);
    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    Http::assertSent(function (Request $request) use ($event) {
        $timestamp = $request->header('X-IBE-Timestamp')[0] ?? '';
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->body()}", 'whsec_test_secret');

        return $request->url() === 'https://93.184.216.34/webhooks'
            && $request->header('X-IBE-Event-Id')[0] === $event->event_id
            && $request->header('X-IBE-Signature')[0] === "sha256={$expected}"
            && $request['data']['invoice_id'] === 44;
    });
    expect($delivery->fresh()->status)->toBe('delivered');
    expect($endpoint->fresh()->last_delivered_at)->not->toBeNull();
});

it('rejects webhook destinations in private link-local and loopback networks', function (string $url) {
    $token = webhookSuperadminToken();

    $this->postJson('/api/v1/admin/webhooks', [
        'name' => 'Unsafe endpoint',
        'url' => $url,
        'events' => ['*'],
    ], [...bearerHeaders($token), 'Idempotency-Key' => 'unsafe-'.md5($url)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
})->with([
    'loopback' => 'http://127.0.0.1/hook',
    'rfc1918' => 'https://10.0.0.1/hook',
    'metadata' => 'http://169.254.169.254/latest/meta-data',
    'ipv6 loopback' => 'http://[::1]/hook',
]);
