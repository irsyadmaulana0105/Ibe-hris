<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\OutboundUrlGuard;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin, 403, 'Hanya superadmin dapat mengelola webhook.');
        $companyId = $request->user()->company_id;
        $endpoints = WebhookEndpoint::query()
            ->where('company_id', $companyId)
            ->withCount(['deliveries as delivered_count' => fn ($query) => $query->where('status', 'delivered'), 'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed')])
            ->latest()->get();

        return $this->success(['endpoints' => $endpoints, 'event_catalog' => config('webhooks.events')]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin, 403, 'Hanya superadmin dapat mengelola webhook.');
        $data = $this->validated($request);
        $secret = 'whsec_'.Str::random(48);
        $endpoint = WebhookEndpoint::create([...$data, 'company_id' => $request->user()->company_id, 'secret' => $secret]);

        return $this->created(['endpoint' => $endpoint, 'signing_secret' => $secret], 'Webhook berhasil dibuat. Simpan secret karena tidak akan ditampilkan lagi.');
    }

    public function update(Request $request, int $endpoint): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin, 403, 'Hanya superadmin dapat mengelola webhook.');
        $model = $this->endpoint($request, $endpoint);
        $model->update($this->validated($request, true));

        return $this->success($model->fresh(), 'Webhook berhasil diperbarui.');
    }

    public function destroy(Request $request, int $endpoint): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin, 403, 'Hanya superadmin dapat mengelola webhook.');
        // Preserve delivery history for audit and incident investigation.
        $this->endpoint($request, $endpoint)->update(['active' => false]);

        return $this->noContent();
    }

    public function test(Request $request, int $endpoint, WebhookService $webhooks): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin, 403, 'Hanya superadmin dapat mengelola webhook.');
        $model = $this->endpoint($request, $endpoint);
        if (! in_array('*', $model->events, true) && ! in_array('integration.test', $model->events, true)) {
            $model->update(['events' => [...$model->events, 'integration.test']]);
        }
        $event = $webhooks->publish($model->company_id, 'integration.test', ['message' => 'IBE ERP webhook connection test'], $request->attributes->get('correlation_id'));

        return $this->created(['event_id' => $event->event_id], 'Webhook test masuk antrean pengiriman.');
    }

    public function deliveries(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin, 403, 'Hanya superadmin dapat melihat delivery log.');
        $paginator = WebhookDelivery::query()
            ->whereHas('endpoint', fn ($query) => $query->where('company_id', $request->user()->company_id))
            ->with(['event:id,event_id,type,occurred_at', 'endpoint:id,name,url'])
            ->latest()->paginate(min($request->integer('per_page', 20), 100));

        return $this->paginated($paginator, $paginator->items());
    }

    public function retry(Request $request, int $delivery): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin, 403, 'Hanya superadmin dapat mengulang webhook.');
        $model = WebhookDelivery::query()
            ->whereHas('endpoint', fn ($query) => $query->where('company_id', $request->user()->company_id))
            ->findOrFail($delivery);
        abort_if($model->status === 'delivered', 422, 'Delivery yang berhasil tidak perlu diulang.');
        $model->update(['status' => 'pending', 'last_error' => null, 'next_attempt_at' => null]);
        DeliverWebhook::dispatch($model->id);

        return $this->success(['delivery_id' => $model->id], 'Webhook dijadwalkan ulang.');
    }

    private function endpoint(Request $request, int $id): WebhookEndpoint
    {
        return WebhookEndpoint::query()->where('company_id', $request->user()->company_id)->findOrFail($id);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'name' => [...$sometimes, 'string', 'max:120'],
            'url' => [...$sometimes, 'url:http,https', 'max:2048', function ($attribute, $value, $fail) {
                if (app()->isProduction() && parse_url($value, PHP_URL_SCHEME) !== 'https') {
                    $fail('Webhook production wajib menggunakan HTTPS.');
                }
                try {
                    app(OutboundUrlGuard::class)->resolvePublic((string) $value);
                } catch (\RuntimeException $error) {
                    $fail($error->getMessage());
                }
            }],
            'events' => [...$sometimes, 'array', 'min:1'],
            'events.*' => ['string', Rule::in(['*', ...config('webhooks.events')])],
            'headers' => ['sometimes', 'nullable', 'array'],
            'headers.*' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
