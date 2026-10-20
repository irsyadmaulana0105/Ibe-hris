<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebhookService
{
    public function publish(int $companyId, string $type, array $data, ?string $correlationId = null): WebhookEvent
    {
        return DB::transaction(function () use ($companyId, $type, $data, $correlationId) {
            $event = WebhookEvent::create([
                'event_id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'type' => $type,
                'payload' => $data,
                'correlation_id' => $correlationId,
                'occurred_at' => now(),
            ]);

            WebhookEndpoint::query()
                ->where('company_id', $companyId)
                ->where('active', true)
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint) => in_array('*', $endpoint->events, true) || in_array($type, $endpoint->events, true))
                ->each(function (WebhookEndpoint $endpoint) use ($event) {
                    $delivery = WebhookDelivery::create([
                        'webhook_event_id' => $event->id,
                        'webhook_endpoint_id' => $endpoint->id,
                        'status' => 'pending',
                    ]);
                    DeliverWebhook::dispatch($delivery->id)->afterCommit();
                });

            return $event;
        });
    }
}
