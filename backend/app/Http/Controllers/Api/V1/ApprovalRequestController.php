<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Approval Request lifecycle - untuk Persetujuan Eksekutif Direktur.
 * HR/staff bisa create request, Direktur review + approve/reject.
 */
class ApprovalRequestController extends ApiController
{
    // ═══════════════════ HR/Staff endpoints ═══════════════════

    /** POST /admin/approvals */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'employees.manage', 'Approval management access required.');

        $data = $request->validate([
            'type'        => ['required', Rule::in(array_keys(ApprovalRequest::TYPES))],
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'amount'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $ar = ApprovalRequest::create([
            'company_id'   => $user->company_id,
            'type'         => $data['type'],
            'title'        => $data['title'],
            'description'  => $data['description'],
            'amount'       => $data['amount'] ?? null,
            'status'       => ApprovalRequest::STATUS_PENDING,
            'requested_by' => $user->id,
        ]);

        app(AuditLogService::class)->log(
            'approval.created', $user, 'approval_request', (string) $ar->id,
            ['type' => $data['type'], 'title' => $data['title']],
        );

        return $this->created(['approval' => $ar], 'Permintaan persetujuan berhasil dibuat.');
    }

    /** GET /admin/approvals - list all (HR view, semua status) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'employees.manage', 'Approval management access required.');

        $query = ApprovalRequest::where('company_id', $user->company_id)
            ->with(['requestor:id,name', 'approver:id,name'])
            ->orderByDesc('created_at');

        if ($status = $request->input('status')) $query->where('status', $status);
        if ($type = $request->input('type')) $query->where('type', $type);

        return $this->success([
            'approvals' => $query->get()->map(fn ($a) => $this->transform($a)),
            'summary'   => [
                'pending'  => ApprovalRequest::where('company_id', $user->company_id)->where('status', 'pending')->count(),
                'approved' => ApprovalRequest::where('company_id', $user->company_id)->where('status', 'approved')->count(),
                'rejected' => ApprovalRequest::where('company_id', $user->company_id)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    // ═══════════════════ Direktur endpoints ═══════════════════

    /** GET /direktur/approvals/pending */
    public function pendingForDirektur(Request $request): JsonResponse
    {
        $direktur = $request->user();
        if (!in_array($direktur->group, ['direktur', 'superadmin'], true)) {
            abort(403, 'Hanya Direktur yang dapat mengakses.');
        }

        $approvals = ApprovalRequest::where('company_id', $direktur->company_id)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->with('requestor:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($a) => $this->transform($a));

        return $this->success(['approvals' => $approvals]);
    }

    /** POST /direktur/approvals/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $direktur = $request->user();
        if (!in_array($direktur->group, ['direktur', 'superadmin'], true)) {
            abort(403, 'Hanya Direktur yang dapat menyetujui.');
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $ar = ApprovalRequest::where('company_id', $direktur->company_id)->findOrFail($id);
        if ($ar->status !== ApprovalRequest::STATUS_PENDING) {
            abort(422, 'Permintaan sudah diproses sebelumnya.');
        }

        $ar->update([
            'status'         => ApprovalRequest::STATUS_APPROVED,
            'approver_id'    => $direktur->id,
            'approver_notes' => $data['notes'] ?? null,
            'approved_at'    => now(),
        ]);

        app(AuditLogService::class)->log(
            'approval.approved', $direktur, 'approval_request', (string) $ar->id,
            ['title' => $ar->title, 'type' => $ar->type],
        );

        return $this->success($this->transform($ar->fresh(['requestor', 'approver'])), 'Persetujuan diberikan.');
    }

    /** POST /direktur/approvals/{id}/reject */
    public function reject(Request $request, int $id): JsonResponse
    {
        $direktur = $request->user();
        if (!in_array($direktur->group, ['direktur', 'superadmin'], true)) {
            abort(403, 'Hanya Direktur yang dapat menolak.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $ar = ApprovalRequest::where('company_id', $direktur->company_id)->findOrFail($id);
        if ($ar->status !== ApprovalRequest::STATUS_PENDING) {
            abort(422, 'Permintaan sudah diproses sebelumnya.');
        }

        $ar->update([
            'status'           => ApprovalRequest::STATUS_REJECTED,
            'approver_id'      => $direktur->id,
            'rejected_at'      => now(),
            'rejection_reason' => $data['reason'],
        ]);

        app(AuditLogService::class)->log(
            'approval.rejected', $direktur, 'approval_request', (string) $ar->id,
            ['title' => $ar->title, 'reason' => $data['reason']],
        );

        return $this->success($this->transform($ar->fresh(['requestor', 'approver'])), 'Permintaan ditolak.');
    }

    // ═══════════════════ Utility ═══════════════════

    private function transform(ApprovalRequest $a): array
    {
        return [
            'id'                => $a->id,
            'type'              => $a->type,
            'type_label'        => ApprovalRequest::TYPES[$a->type] ?? $a->type,
            'title'             => $a->title,
            'description'       => $a->description,
            'amount'            => $a->amount,
            'status'            => $a->status,
            'requestor_id'      => $a->requested_by,
            'requestor_name'    => $a->requestor?->name,
            'approver_name'     => $a->approver?->name,
            'approver_notes'    => $a->approver_notes,
            'rejection_reason'  => $a->rejection_reason,
            'created_at'        => $a->created_at?->toIso8601String(),
            'approved_at'       => $a->approved_at?->toIso8601String(),
            'rejected_at'       => $a->rejected_at?->toIso8601String(),
        ];
    }
}
