<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WebPushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Web Push subscription management.
 *
 * Flow:
 *   1. Frontend ambil VAPID public key dari GET /push/vapid-public-key
 *   2. Frontend `pushManager.subscribe({ applicationServerKey })` di SW
 *   3. Frontend POST /push/subscribe dengan endpoint + keys
 *   4. Backend simpan, return ok
 *   5. Saat email baru detected (cron), backend kirim push pakai web-push lib
 *      ke endpoint subscription itu
 */
class PushSubscriptionController extends ApiController
{
    public function vapidPublicKey(): JsonResponse
    {
        return $this->success([
            'public_key' => config('webpush.vapid.public_key'),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint'  => ['required', 'string', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth'   => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $endpointHash = hash('sha256', $data['endpoint']);

        WebPushSubscription::updateOrCreate(
            ['endpoint_hash' => $endpointHash],
            [
                'user_id'        => $user->id,
                'endpoint'       => $data['endpoint'],
                'p256dh'         => $data['keys']['p256dh'],
                'auth'           => $data['keys']['auth'],
                'user_agent'     => Str::limit((string) $request->userAgent(), 255, ''),
                'last_active_at' => now(),
                'push_failures'  => 0,
            ],
        );

        return $this->success(null, 'Push subscription saved.');
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url'],
        ]);

        WebPushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return $this->success(null, 'Push subscription removed.');
    }
}
