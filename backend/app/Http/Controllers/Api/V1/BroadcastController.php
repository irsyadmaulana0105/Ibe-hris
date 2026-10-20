<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Division;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Broadcast pengumuman ke karyawan dengan audience grouping. Direksi & HR
 * bisa pilih: semua karyawan, by group/role, by division, by employment status,
 * atau pilih user spesifik. Tujuan: distribusi info valid + traceable.
 *
 * Endpoint admin di /admin/broadcasts (capability: broadcast.send).
 * Endpoint karyawan di /me/broadcasts (lihat inbox + tandai read).
 */
class BroadcastController extends ApiController
{
    public function __construct(
        private readonly BroadcastService $service,
        private readonly AuditLogService $audit,
    ) {}

    /** GET /api/v1/admin/broadcasts - list history broadcast */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'broadcast.send', 'Broadcast access required.');

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(5, (int) $request->integer('per_page', 20)));

        $paginator = Broadcast::query()
            ->where('company_id', $request->user()->company_id)
            ->with('sender:id,name,group')
            ->orderByDesc('sent_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(fn (Broadcast $b) => [
            'id'                => $b->id,
            'subject'           => $b->subject,
            'priority'          => $b->priority,
            'audience_filter'   => $b->audience_filter,
            'recipient_count'   => $b->recipient_count,
            'email_sent_count'  => $b->email_sent_count,
            'sent_at'           => $b->sent_at?->toIso8601String(),
            'sender'            => $b->sender ? ['id' => $b->sender->id, 'name' => $b->sender->name, 'group' => $b->sender->group] : null,
        ])->values();

        return $this->paginated($paginator, $rows);
    }

    /** GET /api/v1/admin/broadcasts/{id} - detail with recipients */
    public function adminShow(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'broadcast.send', 'Broadcast access required.');

        $bc = Broadcast::query()
            ->where('company_id', $admin->company_id)
            ->with(['sender:id,name,group', 'recipients.user:id,name,email,group'])
            ->findOrFail($id);

        $recipients = $bc->recipients->map(fn (BroadcastRecipient $r) => [
            'user_id'        => $r->user_id,
            'name'           => $r->user?->name,
            'email'          => $r->user?->email,
            'group'          => $r->user?->group,
            'email_sent_at'  => $r->email_sent_at?->toIso8601String(),
            'read_at'        => $r->read_at?->toIso8601String(),
        ])->values();

        return $this->success([
            'id'              => $bc->id,
            'subject'         => $bc->subject,
            'body'            => $bc->body,
            'priority'        => $bc->priority,
            'audience_filter' => $bc->audience_filter,
            'send_email'      => $bc->send_email,
            'recipient_count' => $bc->recipient_count,
            'email_sent_count'=> $bc->email_sent_count,
            'read_count'      => $bc->recipients->whereNotNull('read_at')->count(),
            'sent_at'         => $bc->sent_at?->toIso8601String(),
            'sender'          => $bc->sender ? ['id' => $bc->sender->id, 'name' => $bc->sender->name, 'group' => $bc->sender->group] : null,
            'recipients'      => $recipients,
        ]);
    }

    /**
     * POST /api/v1/admin/broadcasts/preview
     * Hitung jumlah recipient tanpa kirim. Dipakai UI compose untuk
     * tampilkan "akan dikirim ke N karyawan" sebelum confirm.
     */
    public function preview(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'broadcast.send', 'Broadcast access required.');

        $filter = $this->validateAudience($request);
        $audience = $this->service->resolveAudience((int) $admin->company_id, $filter);

        return $this->success([
            'recipient_count' => $audience->count(),
            'sample_names'    => $audience->take(10)->pluck('name')->values(),
        ]);
    }

    /** POST /api/v1/admin/broadcasts - kirim broadcast */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'broadcast.send', 'Broadcast access required.');

        $data = $request->validate([
            'subject'    => ['required', 'string', 'max:200'],
            'body'       => ['required', 'string', 'max:10000'],
            'priority'   => ['nullable', 'in:normal,important,urgent'],
            'send_email' => ['nullable', 'boolean'],
        ]);
        $data['audience_filter'] = $this->validateAudience($request);

        [$bc, $recipientCount, $emailSent] = $this->service->send($admin, $data);

        $this->audit->log(
            'broadcast.sent',
            $admin, 'broadcast', $bc->id,
            [
                'subject' => $bc->subject,
                'priority' => $bc->priority,
                'recipient_count' => $recipientCount,
                'email_sent_count' => $emailSent,
                'audience_filter' => $bc->audience_filter,
            ],
            $request,
        );

        return $this->created([
            'id'               => $bc->id,
            'recipient_count'  => $recipientCount,
            'email_sent_count' => $emailSent,
            'sent_at'          => $bc->sent_at?->toIso8601String(),
        ], "Pengumuman terkirim ke {$recipientCount} karyawan ({$emailSent} email terkirim).");
    }

    /** GET /api/v1/admin/broadcasts/audience-options - daftar groups, divisions, statuses */
    public function audienceOptions(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'broadcast.send', 'Broadcast access required.');

        $divisions = Division::query()
            ->where(function ($q) use ($admin) {
                $q->whereNull('company_id')->orWhere('company_id', $admin->company_id);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->success([
            'groups' => [
                ['value' => 'user',       'label' => 'Karyawan'],
                ['value' => 'admin',      'label' => 'HR Admin'],
                ['value' => 'finance',    'label' => 'Finance'],
                ['value' => 'superadmin', 'label' => 'Direksi / Superadmin'],
            ],
            'employment_statuses' => collect(User::EMPLOYMENT_STATUSES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'divisions' => $divisions->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])->values(),
        ]);
    }

    /** GET /api/v1/me/broadcasts - inbox karyawan */
    public function myInbox(Request $request): JsonResponse
    {
        $user = $request->user();
        $unreadOnly = (bool) $request->boolean('unread_only', false);

        $q = BroadcastRecipient::query()
            ->where('user_id', $user->id)
            ->with(['broadcast.sender:id,name,group'])
            ->orderByDesc('created_at');

        if ($unreadOnly) {
            $q->whereNull('read_at');
        }

        $rows = $q->limit(50)->get()->map(fn (BroadcastRecipient $r) => [
            'id'         => $r->id,
            'subject'    => $r->broadcast?->subject,
            'body'       => $r->broadcast?->body,
            'priority'   => $r->broadcast?->priority,
            'sent_at'    => $r->broadcast?->sent_at?->toIso8601String(),
            'read_at'    => $r->read_at?->toIso8601String(),
            'sender'     => $r->broadcast?->sender
                ? ['name' => $r->broadcast->sender->name, 'group' => $r->broadcast->sender->group]
                : null,
        ])->values();

        $unreadCount = BroadcastRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return $this->success([
            'rows'         => $rows,
            'unread_count' => $unreadCount,
        ]);
    }

    /** POST /api/v1/me/broadcasts/{id}/read - mark single as read */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $r = BroadcastRecipient::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        if (!$r->read_at) {
            $r->update(['read_at' => now()]);
        }
        return $this->success(message: 'Ditandai dibaca.');
    }

    /** POST /api/v1/me/broadcasts/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = BroadcastRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return $this->success(['count' => $count], "{$count} pengumuman ditandai dibaca.");
    }

    /** Validate audience filter shape; reused by preview + store. */
    private function validateAudience(Request $request): array
    {
        $request->validate([
            'audience_filter'                       => ['required', 'array'],
            'audience_filter.all'                   => ['nullable', 'boolean'],
            'audience_filter.groups'                => ['nullable', 'array'],
            'audience_filter.groups.*'              => ['in:user,admin,superadmin,finance'],
            'audience_filter.division_ids'          => ['nullable', 'array'],
            'audience_filter.division_ids.*'        => ['integer'],
            'audience_filter.employment_statuses'   => ['nullable', 'array'],
            'audience_filter.employment_statuses.*' => ['in:permanent,probation,intern_campus,intern_hub,pkl_smk'],
            'audience_filter.user_ids'              => ['nullable', 'array'],
            'audience_filter.user_ids.*'            => ['string', 'size:26'],
        ]);
        $f = $request->input('audience_filter', []);
        $hasAny = !empty($f['all'])
            || !empty($f['groups'])
            || !empty($f['division_ids'])
            || !empty($f['employment_statuses'])
            || !empty($f['user_ids']);
        abort_unless($hasAny, 422, 'Pilih minimal satu kelompok penerima.');
        return [
            'all'                  => (bool) ($f['all'] ?? false),
            'groups'               => array_values($f['groups'] ?? []),
            'division_ids'         => array_values($f['division_ids'] ?? []),
            'employment_statuses'  => array_values($f['employment_statuses'] ?? []),
            'user_ids'             => array_values($f['user_ids'] ?? []),
        ];
    }
}
