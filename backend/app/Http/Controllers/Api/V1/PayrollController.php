<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AdminPayrollResource;
use App\Jobs\SendPayrollSlipEmail;
use App\Models\Payroll;
use App\Services\AdminPayrollService;
use App\Services\AuditLogService;
use App\Services\PayrollDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PayrollController extends ApiController
{
    public function __construct(
        private readonly AdminPayrollService $service,
        private readonly PayrollDocumentService $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // HR (submit), Finance (approve/pay), Superadmin (manage) all see the list
        $this->requireAnyCapability($user, ['payroll.manage', 'payroll.submit', 'payroll.approve', 'payroll.pay']);

        $payrolls = $this->service->paginateForAdmin($user, $request->only(['month', 'search', 'status', 'page', 'per_page']));

        return $this->paginated($payrolls, AdminPayrollResource::collection($payrolls), [
            'references' => $this->service->references($user),
            'summary' => $this->service->summary($user, $request->input('month')),
            'permissions' => [
                'can_submit' => $user->hasCapability('payroll.submit') || $user->hasCapability('payroll.manage'),
                'can_approve' => $user->hasCapability('payroll.approve') || $user->hasCapability('payroll.manage'),
                'can_pay' => $user->hasCapability('payroll.pay') || $user->hasCapability('payroll.manage'),
            ],
        ]);
    }

    public function selfIndex(Request $request): JsonResponse
    {
        $payrolls = $this->service->selfIndexForEmployee(
            $request->user()->id,
            $request->only(['per_page']),
        );

        return $this->paginated($payrolls, AdminPayrollResource::collection($payrolls));
    }

    public function selfShow(Request $request, int $id): JsonResponse
    {
        $payroll = $this->documents->findForEmployee($id, $request->user()->id);

        return $this->success(new AdminPayrollResource($payroll));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['payroll.submit', 'payroll.manage']);

        $payload = $request->validate([
            'user_id' => ['required', 'string'],
            'month' => ['required', 'date_format:Y-m'],
            'bonus_extra' => ['nullable', 'integer', 'min:0'],
        ]);

        $payroll = $this->service->generate(
            $user,
            $payload['user_id'],
            $payload['month'],
            (int) ($payload['bonus_extra'] ?? 0)
        );

        return $this->success(new AdminPayrollResource($payroll), 'Payroll berhasil digenerate.');
    }

    public function bulkGenerate(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['payroll.submit', 'payroll.manage']);

        $payload = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'bonus_extra' => ['nullable', 'integer', 'min:0'],
            // Optional - kalau diisi, hanya generate untuk user_ids tsb.
            // Kalau kosong/null, generate untuk SEMUA karyawan aktif (legacy behavior).
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['string'],
        ]);

        $generated = $this->service->generateBulk(
            $user,
            $payload['month'],
            (int) ($payload['bonus_extra'] ?? 0),
            $payload['user_ids'] ?? null,
        );

        return $this->success([
            'generated_count' => $generated->count(),
            'user_ids' => $generated->pluck('user_id')->all(),
        ], 'Payroll berhasil digenerate untuk '.$generated->count().' karyawan.');
    }

    /**
     * HR submits draft payroll to Finance.
     * draft → submitted
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['payroll.submit', 'payroll.manage']);

        $payroll = $this->service->submit($user, $id);

        return $this->success(new AdminPayrollResource($payroll), 'Payroll diajukan ke Finance.');
    }

    /**
     * Bulk submit: HR mengajukan semua draft sekaligus untuk satu periode.
     */
    public function submitBulk(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['payroll.submit', 'payroll.manage']);

        $payload = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $count = $this->service->submitBulk($user, $payload['month']);

        return $this->success(['submitted_count' => $count], "{$count} payroll diajukan ke Finance.");
    }

    /**
     * Finance approves submission.
     * submitted → approved
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $candidate = $this->service->findForAdmin($user, $id);
        $this->requireAnyCapability($user, ['payroll.approve', 'payroll.manage'], context: [
            'company_id' => $candidate->company_id,
            'resource_type' => 'payroll',
            'resource_id' => $candidate->id,
            'amount' => $candidate->net_total,
            'maker_id' => $candidate->submitted_by ?? $candidate->generated_by,
            'irreversible' => true,
            'action' => 'approve',
            'status' => $candidate->status,
        ]);

        $payroll = $this->service->approve($user, $id);

        return $this->success(new AdminPayrollResource($payroll), 'Payroll disetujui Finance.');
    }

    /**
     * Finance marks payroll as transferred.
     * approved → paid
     */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $candidate = $this->service->findForAdmin($user, $id);
        $this->requireAnyCapability($user, ['payroll.pay', 'payroll.manage'], context: [
            'company_id' => $candidate->company_id,
            'resource_type' => 'payroll',
            'resource_id' => $candidate->id,
            'amount' => $candidate->net_total,
            'maker_id' => $candidate->approved_by,
            'irreversible' => true,
            'action' => 'pay',
            'status' => $candidate->status,
        ]);

        $request->validate([
            'transfer_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
            'transfer_reference' => ['nullable', 'string', 'max:200'],
        ], [
            'transfer_proof.mimes' => 'Bukti transfer harus JPG/PNG/PDF/WEBP.',
            'transfer_proof.max' => 'Ukuran maksimal 5 MB.',
        ]);

        $payroll = $this->service->markPaid(
            $user,
            $id,
            $request->file('transfer_proof'),
            $request->string('transfer_reference')->toString() ?: null,
        );

        return $this->success(new AdminPayrollResource($payroll), 'Payroll ditandai sudah ditransfer. Slip sekarang dapat dilihat karyawan.');
    }

    /** GET /api/v1/admin/payrolls/{id}/transfer-proof - download bukti transfer (admin/finance/karyawan terkait) */
    public function downloadTransferProof(Request $request, int $id)
    {
        $user = $request->user();
        // Admin/finance bisa selalu lihat. Karyawan hanya boleh lihat punyanya.
        $payroll = Payroll::withoutGlobalScopes()
            ->where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $isOwner = $payroll->user_id === $user->id;
        $isAdminLike = $user->hasCapability('payroll.manage')
            || $user->hasCapability('payroll.approve')
            || $user->hasCapability('payroll.pay')
            || $user->hasCapability('payroll.submit');
        if (! $isOwner && ! $isAdminLike) {
            abort(403);
        }

        if (! $payroll->transfer_proof_path) {
            abort(404, 'Bukti transfer belum diupload.');
        }

        return Storage::disk('local')->download(
            $payroll->transfer_proof_path,
            'bukti-transfer-'.$payroll->payroll_number.'.'.pathinfo($payroll->transfer_proof_path, PATHINFO_EXTENSION),
        );
    }

    public function export(Request $request)
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['payroll.manage', 'payroll.submit', 'payroll.approve', 'payroll.pay']);

        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        return $this->service->exportPeriodReport($user, $request->only(['month']));
    }

    public function downloadSlip(Request $request, int $id)
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['payroll.manage', 'payroll.submit', 'payroll.approve', 'payroll.pay']);

        $payroll = $this->documents->findForCompany($id, $user->company_id);

        return $this->documents->downloadResponse($payroll);
    }

    public function selfDownloadSlip(Request $request, int $id)
    {
        $payroll = $this->documents->findForEmployee($id, $request->user()->id);

        return $this->documents->downloadResponse($payroll);
    }

    public function sendSlipEmail(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['payroll.pay', 'payroll.manage']);

        $payroll = $this->documents->findForCompany($id, $user->company_id);

        if (! in_array($payroll->status, ['approved', 'paid'], true)) {
            abort(422, 'Salary slip can only be emailed after payroll is approved.');
        }

        if (blank($payroll->user->email)) {
            abort(422, 'Email karyawan belum tersedia.');
        }

        $internalRecipients = $this->documents->internalRecipientsForPayroll($payroll);

        SendPayrollSlipEmail::dispatchAfterResponse(
            $payroll->id,
            $payroll->user->email,
            $internalRecipients,
            Auth::user()?->email
        );

        app(AuditLogService::class)->log(
            'payroll.email_queued',
            $user,
            'payroll',
            $payroll->id,
            [
                'payroll_id' => $payroll->id,
                'recipient_email' => $payroll->user->email,
                'bcc_emails' => $internalRecipients,
            ]
        );

        return $this->success([
            'recipient_email' => $payroll->user->email,
            'bcc_emails' => $internalRecipients,
        ], 'Email slip gaji berhasil diantrikan.');
    }
}
