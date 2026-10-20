<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\OutboundUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(OutboundUrlGuard $urlGuard): void
    {
        $delivery = WebhookDelivery::with(['event', 'endpoint'])->findOrFail($this->deliveryId);
        if ($delivery->status === 'delivered' || ! $delivery->endpoint->active) {
            return;
        }

        $event = $delivery->event;
        $endpoint = $delivery->endpoint;
        $destination = $urlGuard->resolvePublic($endpoint->url);
        $body = [
            'event' => $event->type,
            'event_id' => $event->event_id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'company_id' => $event->company_id,
            'correlation_id' => $event->correlation_id,
            'data' => $event->payload,
        ];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$json}", $endpoint->secret);

        $delivery->increment('attempts');
        try {
            $resolveIp = str_contains($destination['ip'], ':')
                ? "[{$destination['ip']}]"
                : $destination['ip'];
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions([
                    'curl' => [CURLOPT_RESOLVE => [sprintf(
                        '%s:%d:%s',
                        $destination['host'],
                        $destination['port'],
                        $resolveIp,
                    )]],
                ])
                ->acceptJson()
                ->withHeaders(array_merge($endpoint->headers ?? [], [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'IBE-ERP-Webhook/1.0',
                    'X-IBE-Event' => $event->type,
                    'X-IBE-Event-Id' => $event->event_id,
                    'X-IBE-Timestamp' => $timestamp,
                    'X-IBE-Signature' => "sha256={$signature}",
                ]))
                ->withBody($json, 'application/json')
                ->post($endpoint->url);

            if (! $response->successful()) {
                throw new RuntimeException("Webhook endpoint returned HTTP {$response->status()}");
            }

            $delivery->update(['status' => 'delivered', 'response_status' => $response->status(), 'response_body' => mb_substr($response->body(), 0, 4000), 'last_error' => null, 'next_attempt_at' => null, 'delivered_at' => now()]);
            $endpoint->update(['last_delivered_at' => now()]);
        } catch (Throwable $error) {
            $delivery->update(['status' => $this->attempts() >= $this->tries ? 'failed' : 'pending', 'last_error' => mb_substr($error->getMessage(), 0, 4000), 'next_attempt_at' => now()->addSeconds($this->backoff[min(max($this->attempts() - 1, 0), count($this->backoff) - 1)])]);
            $endpoint->update(['last_failed_at' => now()]);
            throw $error;
        }
    }
}
