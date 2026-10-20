<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Complaint;
use App\Models\ServiceTicketActivity;
use App\Services\AuditLogService;
use App\Produksi\Services\ProductionService;
use App\Services\ServiceTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Complaint customer ticket. Admin endpoint untuk tim produksi handle ticket
 * (list, show, assign, resolve). Public endpoint untuk submit dari Web Profile
 * IBE - siapa saja boleh post, dikasih ticket_number sebagai bukti.
 */
class ComplaintController extends ApiController
{
    public function __construct(
        private readonly ProductionService $production,
        private readonly AuditLogService $audit,
        private readonly ServiceTicketService $tickets,
    ) {}

    /** POST /api/v1/public/complaints - public form submission (no auth) */
    public function publicStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name'    => ['required', 'string', 'max:200'],
            'customer_email'   => ['nullable', 'email', 'max:200'],
            'customer_phone'   => ['nullable', 'string', 'max:32'],
            'customer_company' => ['nullable', 'string', 'max:200'],
            'product_serial'   => ['nullable', 'string', 'max:64'],
            'product_name'     => ['nullable', 'string', 'max:200'],
            'subject'          => ['required', 'string', 'max:200'],
            'description'      => ['required', 'string', 'max:5000'],
        ]);

        // Default company_id = 1 (IBE Reality main). Future: support multi-company via subdomain.
        $companyId = 1;

        $complaint = $this->production->recordComplaint(array_merge($data, [
            'source'     => 'web_form',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]), $companyId);

        $this->audit->log(
            'complaint.submitted_public',
            null, 'complaint', $complaint->id,
            ['ticket_number' => $complaint->ticket_number, 'customer_email' => $complaint->customer_email, 'subject' => $complaint->subject],
            $request,
        );

        return $this->created([
            'ticket_number' => $complaint->ticket_number,
            'submitted_at'  => $complaint->created_at?->toIso8601String(),
        ], "Terima kasih. Komplain Anda diterima dengan nomor tiket {$complaint->ticket_number}. Tim kami akan menghubungi dalam 1×24 jam.");
    }

    /** GET /api/v1/admin/complaints */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'complaint.manage', 'Akses production role required.');

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        $status = $request->string('status')->toString();
        $priority = $request->string('priority')->toString();
        $q = $request->string('q')->toString();

        $paginator = Complaint::query()
            ->where('company_id', $request->user()->company_id)
            ->with('assignedTo:id,name')
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($priority, fn ($w) => $w->where('priority', $priority))
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('ticket_number', 'like', "%{$q}%")
                  ->orWhere('customer_name', 'like', "%{$q}%")
                  ->orWhere('subject', 'like', "%{$q}%")
                  ->orWhere('product_serial', 'like', "%{$q}%");
            }))
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(function (Complaint $c) {
            $this->tickets->refreshSla($c);
            return [
                'id'             => $c->id,
                'ticket_number'  => $c->ticket_number,
                'customer_name'  => $c->customer_name,
                'customer_email' => $c->customer_email,
                'customer_phone' => $c->customer_phone,
                'product_serial' => $c->product_serial,
                'product_name'   => $c->product_name,
                'category'       => $c->category,
                'subject'        => $c->subject,
                'priority'       => $c->priority,
                'status'         => $c->status,
                'source'         => $c->source,
                'assigned_to'    => $c->assignedTo ? ['id' => $c->assignedTo->id, 'name' => $c->assignedTo->name] : null,
                'sla'            => [
                    'target_response_at'      => $c->target_response_at?->toIso8601String(),
                    'target_resolution_at'    => $c->target_resolution_at?->toIso8601String(),
                    'first_response_at'       => $c->first_response_at?->toIso8601String(),
                    'is_response_breached'    => (bool) $c->is_response_breached,
                    'is_resolution_breached'  => (bool) $c->is_resolution_breached,
                ],
                'reopen_count'   => (int) $c->reopen_count,
                'created_at'     => $c->created_at?->toIso8601String(),
                'resolved_at'    => $c->resolved_at?->toIso8601String(),
            ];
        })->values();

        $unread = Complaint::query()
            ->where('company_id', $request->user()->company_id)
            ->whereIn('status', ['new', 'in_progress'])
            ->count();

        return $this->paginated($paginator, $rows, ['open_count' => $unread]);
    }

    /** GET /api/v1/admin/complaints/{id} */
    public function adminShow(Request $request, string $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'complaint.manage', 'Akses production role required.');

        $c = Complaint::query()
            ->where('company_id', $request->user()->company_id)
            ->with('assignedTo:id,name', 'resolvedBy:id,name', 'activities')
            ->findOrFail($id);

        $this->tickets->refreshSla($c);

        return $this->success([
            'id'              => $c->id,
            'ticket_number'   => $c->ticket_number,
            'customer_name'   => $c->customer_name,
            'customer_email'  => $c->customer_email,
            'customer_phone'  => $c->customer_phone,
            'customer_company'=> $c->customer_company,
            'product_serial'  => $c->product_serial,
            'product_name'    => $c->product_name,
            'category'        => $c->category,
            'subject'         => $c->subject,
            'description'     => $c->description,
            'attachments'     => $c->attachments,
            'source'          => $c->source,
            'priority'        => $c->priority,
            'status'          => $c->status,
            'assigned_to'     => $c->assignedTo ? ['id' => $c->assignedTo->id, 'name' => $c->assignedTo->name] : null,
            'resolution_notes'=> $c->resolution_notes,
            'resolved_at'     => $c->resolved_at?->toIso8601String(),
            'resolved_by'     => $c->resolvedBy ? ['id' => $c->resolvedBy->id, 'name' => $c->resolvedBy->name] : null,
            'reopened_at'     => $c->reopened_at?->toIso8601String(),
            'reopen_count'    => (int) $c->reopen_count,
            'sla'             => [
                'response_minutes'        => (int) $c->sla_response_minutes,
                'resolution_minutes'      => (int) $c->sla_resolution_minutes,
                'target_response_at'      => $c->target_response_at?->toIso8601String(),
                'target_resolution_at'    => $c->target_resolution_at?->toIso8601String(),
                'first_response_at'       => $c->first_response_at?->toIso8601String(),
                'is_response_breached'    => (bool) $c->is_response_breached,
                'is_resolution_breached'  => (bool) $c->is_resolution_breached,
            ],
            'activities'      => $c->activities->map(fn (ServiceTicketActivity $a) => [
                'id'           => $a->id,
                'type'         => $a->type,
                'actor_label'  => $a->actor_label,
                'body'         => $a->body,
                'meta'         => $a->meta,
                'is_internal'  => $a->is_internal,
                'created_at'   => $a->created_at?->toIso8601String(),
            ])->values(),
            'created_at'      => $c->created_at?->toIso8601String(),
        ]);
    }

    /** POST /api/v1/admin/complaints/{id}/comments */
    public function addComment(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'complaint.manage', 'Akses production role required.');
        $c = Complaint::query()->where('company_id', $admin->company_id)->findOrFail($id);

        $data = $request->validate([
            'body'        => ['required', 'string', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
        ]);
        $activity = $this->tickets->comment($admin, $c, $data['body'], $data['is_internal'] ?? true);

        return $this->created([
            'id'         => $activity->id,
            'created_at' => $activity->created_at?->toIso8601String(),
        ], 'Komentar ditambahkan.');
    }

    /** POST /api/v1/admin/complaints/{id}/transition */
    public function transition(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'complaint.manage', 'Akses production role required.');
        $c = Complaint::query()->where('company_id', $admin->company_id)->findOrFail($id);

        $data = $request->validate([
            'to'   => ['required', 'in:new,in_progress,on_hold,resolved,closed,reopened'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->tickets->transition($admin, $c, $data['to'], $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success(['status' => $c->status], 'Status diperbarui.');
    }

    /** POST /api/v1/admin/complaints/{id}/assign */
    public function assign(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'complaint.manage', 'Akses production role required.');
        $c = Complaint::query()->where('company_id', $admin->company_id)->findOrFail($id);

        $data = $request->validate([
            'assignee_id' => ['nullable', 'string', 'size:26'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);
        $this->tickets->assign($admin, $c, $data['assignee_id'] ?? null, $data['note'] ?? null);

        return $this->success(['assigned_to_user_id' => $c->assigned_to_user_id], 'Assignment diperbarui.');
    }

    /** POST /api/v1/admin/complaints - admin/produksi entry manual */
    public function adminStore(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'complaint.manage', 'Akses production role required.');

        $data = $request->validate([
            'customer_name'    => ['required', 'string', 'max:200'],
            'customer_email'   => ['nullable', 'email', 'max:200'],
            'customer_phone'   => ['nullable', 'string', 'max:32'],
            'customer_company' => ['nullable', 'string', 'max:200'],
            'product_serial'   => ['nullable', 'string', 'max:64'],
            'product_name'     => ['nullable', 'string', 'max:200'],
            'subject'          => ['required', 'string', 'max:200'],
            'description'      => ['required', 'string', 'max:5000'],
            'priority'         => ['nullable', 'in:low,normal,high,critical'],
            'source'           => ['nullable', 'in:manual,email,phone'],
        ]);

        $complaint = $this->production->recordComplaint(array_merge($data, [
            'source' => $data['source'] ?? 'manual',
        ]), (int) $admin->company_id);

        $this->audit->log(
            'complaint.created_admin',
            $admin, 'complaint', $complaint->id,
            ['ticket_number' => $complaint->ticket_number, 'subject' => $complaint->subject],
            $request,
        );

        return $this->created([
            'id'            => $complaint->id,
            'ticket_number' => $complaint->ticket_number,
        ], "Ticket {$complaint->ticket_number} dibuat.");
    }

    /** PATCH /api/v1/admin/complaints/{id} - update status, assignment, priority, resolution */
    public function adminUpdate(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'complaint.manage', 'Akses production role required.');

        $c = Complaint::query()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);

        $data = $request->validate([
            'status'              => ['nullable', 'in:new,in_progress,resolved,closed'],
            'priority'            => ['nullable', 'in:low,normal,high,critical'],
            'assigned_to_user_id' => ['nullable', 'string', 'size:26'],
            'resolution_notes'    => ['nullable', 'string', 'max:5000'],
        ]);

        // Mark resolved metadata kalau status berubah ke resolved
        if (($data['status'] ?? null) === 'resolved' && $c->status !== 'resolved') {
            $data['resolved_at'] = now();
            $data['resolved_by_user_id'] = $admin->id;
        }

        $c->update($data);

        $this->audit->log(
            'complaint.updated',
            $admin, 'complaint', $c->id,
            ['ticket_number' => $c->ticket_number, 'changes' => $data],
            $request,
        );

        return $this->success(['ticket_number' => $c->ticket_number], 'Ticket diperbarui.');
    }
}
