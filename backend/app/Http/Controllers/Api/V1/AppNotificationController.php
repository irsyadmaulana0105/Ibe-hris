<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppNotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->service->forUser($request->user(), (int) $request->integer('limit', 50));
        return $this->success([
            'data'         => $rows,
            'unread_count' => $this->service->unreadCount($request->user()),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(['unread_count' => $this->service->unreadCount($request->user())]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $this->service->markRead($request->user(), $id);
        return $this->success(['marked' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->service->markRead($request->user());
        return $this->success(['marked_count' => $count]);
    }

    /** Trigger system alerts scan - biasanya dijadwal via cron, tapi bisa manual juga */
    public function generate(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $created = $this->service->generateSystemAlerts((int) $request->user()->company_id);
        return $this->success(['created' => $created], 'System alerts generated.');
    }
}
