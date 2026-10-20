<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ReimbursementResource;
use App\Models\ReimbursementCategory;
use App\Services\AttendanceReimbursementMediaService;
use App\Services\ReimbursementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReimbursementController extends ApiController
{
    public function __construct(
        private readonly ReimbursementService $service,
        private readonly AttendanceReimbursementMediaService $media,
    ) {}

    /** GET /api/v1/reimbursements */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reimbursements = $this->service->listForUser($user);
        $categories = $this->service->categoriesForUser($user);

        return $this->paginated($reimbursements, ReimbursementResource::collection($reimbursements), [
            'categories' => $categories,
        ]);
    }

    /** POST /api/v1/reimbursements */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'category_id' => [
                'required',
                Rule::exists('reimbursement_categories', 'id')
                    ->where('company_id', $request->user()->company_id)
                    ->where('is_active', true),
            ],
            'event' => ['required', 'string', 'min:3', 'max:255'],
            'unit_price' => ['required', 'numeric', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
            'attachment' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:3072'],
            // Bank info - required di workflow baru supaya Finance bisa langsung transfer
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9-]{6,30}$/'],
            'bank_account_holder' => ['required', 'string', 'max:200'],
        ]);

        $reimbursement = $this->service->submit(
            $request->user(),
            $data,
            $request->file('attachment')
        );

        return $this->created(new ReimbursementResource($reimbursement), __('Reimbursement request submitted.'));
    }

    /** DELETE /api/v1/reimbursements/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->cancel($request->user(), $id);

        return $this->success(message: __('Request cancelled.'));
    }

    /**
     * GET /api/v1/reimbursements/{id}/receipt
     * Karyawan download kwitansi PDF reimburse-nya sendiri (setelah Finance settle).
     */
    public function myReceipt(Request $request, int $id)
    {
        return $this->media->authenticatedReimbursement($request->user(), $id, 'receipt');
    }

    /** GET /api/v1/admin/reimbursements - accessible by HR (reimbursement.manage) OR Finance (payroll.manage) */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireAnyCapability($request->user(), ['reimbursement.manage', 'payroll.manage'], 'Reimbursement access required.');
        $reimbursements = $this->service->listForAdmin(
            $request->user(),
            $request->only(['status', 'month', 'division', 'category_id', 'search'])
        );

        return $this->paginated($reimbursements, ReimbursementResource::collection($reimbursements), [
            'pending_count' => $this->service->pendingCount($request->user()->company_id),
            'summary' => $this->service->summaryForAdmin(
                $request->user(),
                // Ringkasan harus memakai filter yang sama dengan tabel, kalau
                // tidak totalnya tidak cocok dengan baris yang tampil.
                $request->only(['month', 'division', 'category_id', 'search'])
            ),
        ]);
    }

    /** GET /api/v1/admin/reimbursements/{id} - HR or Finance can view detail */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        $this->requireAnyCapability($request->user(), ['reimbursement.manage', 'payroll.manage'], 'Reimbursement access required.');

        return $this->success(new ReimbursementResource($this->service->detail($request->user(), $id)));
    }

    /**
     * POST /api/v1/admin/reimbursements/{id}/approve
     * Admin dapat meng-override jumlah dengan `approved_amount` (partial approval).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $reimbursement = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'reimbursement.manage', 'Reimbursement management access required.', [
            'company_id' => $reimbursement->company_id,
            'resource_type' => 'reimbursement',
            'resource_id' => $reimbursement->id,
            'amount' => $request->input('approved_amount', $reimbursement->total_amount),
            'maker_id' => $reimbursement->user_id,
            'irreversible' => true,
            'action' => 'approve',
            'status' => $reimbursement->status,
        ]);
        $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'approved_amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $reimbursement = $this->service->approve(
            $request->user(),
            $id,
            $request->approved_amount ? (int) $request->approved_amount : null,
            $request->notes
        );

        return $this->success(new ReimbursementResource($reimbursement), __('Reimbursement approved.'));
    }

    /** POST /api/v1/admin/reimbursements/{id}/reject */
    public function reject(Request $request, int $id): JsonResponse
    {
        $reimbursement = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'reimbursement.manage', 'Reimbursement management access required.', [
            'company_id' => $reimbursement->company_id,
            'resource_type' => 'reimbursement',
            'resource_id' => $reimbursement->id,
            'amount' => $reimbursement->total_amount,
            'maker_id' => $reimbursement->user_id,
            'irreversible' => true,
            'action' => 'reject',
            'status' => $reimbursement->status,
        ]);
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $reimbursement = $this->service->reject($request->user(), $id, $request->notes);

        return $this->success(new ReimbursementResource($reimbursement), __('Reimbursement rejected.'));
    }

    /**
     * POST /api/v1/admin/reimbursements/{id}/settle
     * Finance step: upload bukti transfer + reference, auto-generate kwitansi PDF.
     * Capability: payroll.manage (finance role).
     */
    /**
     * Daftar kategori untuk dropdown filter di halaman admin.
     */
    public function adminCategories(Request $request): JsonResponse
    {
        $this->requireAnyCapability($request->user(), ['reimbursement.manage', 'payroll.manage'], 'Reimbursement access required.');

        $categories = ReimbursementCategory::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return $this->success(data: $categories);
    }

    /**
     * Hapus pengajuan reimbursement. Khusus Finance (payroll.manage), memakai
     * gate yang sama dengan settle. Baris yang sudah dijurnal ditolak di service.
     */
    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        $reimbursement = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'payroll.manage', 'Finance access required.', [
            'company_id' => $reimbursement->company_id,
            'resource_type' => 'reimbursement',
            'resource_id' => $reimbursement->id,
            'amount' => $reimbursement->approved_amount ?? $reimbursement->total_amount,
            'irreversible' => true,
            'action' => 'delete',
            'status' => $reimbursement->status,
        ]);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->service->adminDelete($request->user(), $id, $validated['reason'] ?? null);

        return $this->success(message: __('Pengajuan reimbursement dihapus.'));
    }

    public function settle(Request $request, int $id): JsonResponse
    {
        $reimbursement = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'payroll.manage', 'Finance access required.', [
            'company_id' => $reimbursement->company_id,
            'resource_type' => 'reimbursement',
            'resource_id' => $reimbursement->id,
            'amount' => $reimbursement->approved_amount ?? $reimbursement->total_amount,
            'maker_id' => $reimbursement->hr_approved_by ?? $reimbursement->approved_by,
            'irreversible' => true,
            'action' => 'settle',
            'status' => $reimbursement->status,
        ]);
        $request->validate([
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $reimbursement = $this->service->settle(
            $request->user(),
            $id,
            $request->file('proof'),
            $request->input('reference'),
        );

        return $this->success(new ReimbursementResource($reimbursement), __('Reimbursement settled & receipt generated.'));
    }

    /**
     * GET /api/v1/admin/reimbursements/{id}/receipt
     * Download kwitansi PDF (auth-protected).
     */
    public function receipt(Request $request, int $id)
    {
        $this->requireAnyCapability($request->user(), ['reimbursement.manage', 'payroll.manage'], 'Reimbursement access required.');

        return $this->media->authenticatedReimbursement($request->user(), $id, 'receipt');
    }
}
