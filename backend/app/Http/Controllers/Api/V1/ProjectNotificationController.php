<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProjectNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectNotificationController extends ApiController
{
    /**
     * GET /api/v1/project-notifications/poll?since=<unix-ts>&max_wait=25
     *
     * Long-poll endpoint. Holds the connection up to max_wait seconds and
     * returns as soon as a notification is created after `since`. Frontend
     * re-issues the request immediately after each return, so the user
     * perceives notifications as pushed in real time (latency ≤ 1.5s).
     *
     * Tradeoff: ties up one PHP-FPM worker per active user. For small teams
     * that's fine; for >100 concurrent users move to Reverb/WebSocket.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        // Server-side cap of 25s - under Nginx default 30s proxy_read_timeout
        $maxWait = min(25, max(5, (int) $request->query('max_wait', 25)));
        $since = (int) $request->query('since', 0);
        $sincePoint = $since > 0 ? date('Y-m-d H:i:s.u', $since) : now()->subSecond()->toDateTimeString();

        $deadline = microtime(true) + $maxWait;
        $items = collect();

        while (microtime(true) < $deadline) {
            $items = ProjectNotification::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>', $sincePoint)
                ->with([
                    'actor:id,name,profile_photo_path',
                    'task:id,project_id,title',
                    'project:id,name',
                ])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            if ($items->isNotEmpty()) break;
            // 1.5s nap between DB checks
            usleep(1_500_000);
        }

        $unread = ProjectNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        // Server-clock cursor so the client doesn't see the same row twice
        // even if its clock drifts.
        return $this->success([
            'data'         => $items,
            'unread_count' => $unread,
            'cursor'       => (int) (microtime(true)),
        ]);
    }

    /**
     * GET /api/v1/project-notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $unreadOnly = $request->boolean('unread');

        $q = ProjectNotification::query()
            ->where('user_id', $user->id)
            ->with([
                'actor:id,name,profile_photo_path',
                'task:id,project_id,title',
                'project:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(100);

        if ($unreadOnly) $q->whereNull('read_at');

        $items = $q->get();

        $unreadCount = ProjectNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return $this->success([
            'data'         => $items,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(Request $request, ProjectNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403);
        }
        $notification->update(['read_at' => now()]);
        return $this->success($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        ProjectNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return $this->success(message: 'Semua notifikasi ditandai sudah dibaca.');
    }

    public function destroy(Request $request, ProjectNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403);
        }
        $notification->delete();
        return $this->success(message: 'Notifikasi dihapus.');
    }

    /**
     * GET /api/v1/project-notifications/preferences
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        return $this->success([
            'email_enabled' => (bool) ($user->project_email_notifications_enabled ?? true),
        ]);
    }

    /**
     * PUT /api/v1/project-notifications/preferences
     * Body: { email_enabled: boolean }
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $user->update(['project_email_notifications_enabled' => $data['email_enabled']]);

        return $this->success([
            'email_enabled' => (bool) $data['email_enabled'],
        ], 'Preferensi disimpan.');
    }
}
