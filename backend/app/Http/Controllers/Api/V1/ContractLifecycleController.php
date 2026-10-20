<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ContractLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoints untuk contract lifecycle multi-signer.
 * Complement dengan ContractController (existing single-signer) yang tetap
 * berfungsi untuk backward compatibility.
 *
 * Route grouping (di routes/api.php):
 *   HRD (auth capability 'employees.manage'):
 *     POST   /admin/contracts/generate
 *     PATCH  /admin/contracts/{id}/paraf
 *     POST   /admin/contracts/{id}/send-karyawan
 *     POST   /admin/contracts/{id}/materai-upload
 *     GET    /admin/contracts (list + filter status)
 *
 *   Karyawan (self):
 *     GET    /me/contracts
 *     POST   /me/contracts/{id}/sign
 *
 *   Direktur:
 *     GET    /direktur/contracts/pending-sign
 *     POST   /direktur/contracts/{id}/sign
 *
 *   Preview PDF (bisa diakses semua stakeholder yang authorized):
 *     GET    /contracts/{id}/preview
 */
class ContractLifecycleController extends ApiController
{
    public function __construct(private readonly ContractLifecycleService $service) {}

    // ═══════════════════ HRD Endpoints ═══════════════════

    public function generate(Request $request): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'employment_type' => ['required', 'in:permanent,probation,training,intern_campus,intern_hub,pkl_smk'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
        ]);

        $karyawan = User::withoutGlobalScopes()
            ->where('company_id', $hrd->company_id)
            ->findOrFail($data['user_id']);

        $tpl = ContractTemplate::where('company_id', $hrd->company_id)
            ->where('employment_type', $data['employment_type'])
            ->where('is_active', true)
            ->firstOrFail();

        $contract = $this->service->generate($hrd, $karyawan, $tpl, $data);

        return $this->created([
            'contract_id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'status' => $contract->status,
        ], 'Draft kontrak berhasil dibuat.');
    }

    public function paraf(Request $request, int $id): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $data = $request->validate([
            'signature' => ['nullable', 'string', 'starts_with:data:image/png;base64,'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $contract = Contract::where('company_id', $hrd->company_id)->findOrFail($id);
        $updated = $this->service->parafHrd($hrd, $contract, $data['signature'] ?? null, $data['notes'] ?? null);

        return $this->success([
            'contract_id' => $updated->id,
            'status' => $updated->status,
        ], 'Kontrak berhasil diparaf HRD.');
    }

    public function sendToKaryawan(Request $request, int $id): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $contract = Contract::where('company_id', $hrd->company_id)->findOrFail($id);
        $updated = $this->service->sendToKaryawan($hrd, $contract);

        return $this->success([
            'contract_id' => $updated->id,
            'status' => $updated->status,
        ], 'Kontrak dikirim ke karyawan untuk tanda tangan.');
    }

    public function uploadMaterai(Request $request, int $id): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $request->validate([
            'pdf' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $contract = Contract::where('company_id', $hrd->company_id)->findOrFail($id);
        $updated = $this->service->uploadMaterai($hrd, $contract, $request->file('pdf'));

        return $this->success([
            'contract_id' => $updated->id,
            'status' => $updated->status,
            'materai_available' => true,
        ], 'PDF ber-materai berhasil di-upload. Kontrak diarsipkan.');
    }

    /**
     * POST /admin/contracts/{id}/materai-skip
     * Selesaikan kontrak tanpa upload e-materai - materai fisik ditempel manual
     * atau tidak wajib per internal HR policy. Langsung archive.
     */
    public function skipMaterai(Request $request, int $id): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $contract = Contract::where('company_id', $hrd->company_id)->findOrFail($id);
        $updated = $this->service->skipMaterai($hrd, $contract);

        return $this->success([
            'contract_id' => $updated->id,
            'status' => $updated->status,
        ], 'Kontrak diarsipkan tanpa upload e-materai. Materai fisik dapat ditempel manual pada dokumen cetak.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $contract = Contract::where('company_id', $hrd->company_id)->findOrFail($id);

        // Cuma bisa delete kalau draft atau rejected - mencegah delete kontrak
        // yang sudah masuk lifecycle (menghindari kehilangan bukti audit).
        if (! in_array($contract->status, [Contract::STATE_DRAFT, Contract::STATE_REJECTED], true)) {
            abort(422, 'Hanya kontrak berstatus draft atau dibatalkan yang bisa dihapus.');
        }

        app(AuditLogService::class)->log(
            'contract.deleted', $hrd, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number, 'status_at_delete' => $contract->status],
        );

        $contract->delete();
        $this->service->deletePrivateAssets($contract);

        return $this->success(null, 'Kontrak berhasil dihapus permanen.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $contract = Contract::where('company_id', $hrd->company_id)->findOrFail($id);

        if ($contract->status === Contract::STATE_ARCHIVED || $contract->status === Contract::STATE_REJECTED) {
            abort(422, 'Kontrak sudah final atau sudah dibatalkan sebelumnya.');
        }

        $updated = $this->service->reject($hrd, $contract, $data['reason']);

        return $this->success([
            'contract_id' => $updated->id,
            'status' => $updated->status,
        ], 'Kontrak berhasil dibatalkan.');
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $query = $this->buildAdminQuery($hrd, $request);
        $contracts = $query->get()->map(fn ($c) => $this->transformContract($c));

        return $this->success([
            'contracts' => $contracts,
            'summary' => [
                'draft' => Contract::where('company_id', $hrd->company_id)->where('status', Contract::STATE_DRAFT)->count(),
                'pending_karyawan' => Contract::where('company_id', $hrd->company_id)->where('status', Contract::STATE_PENDING_KARYAWAN)->count(),
                'pending_direktur' => Contract::where('company_id', $hrd->company_id)->where('status', Contract::STATE_PENDING_DIREKTUR)->count(),
                'pending_materai' => Contract::where('company_id', $hrd->company_id)->where('status', Contract::STATE_PENDING_MATERAI)->count(),
                'archived' => Contract::where('company_id', $hrd->company_id)->where('status', Contract::STATE_ARCHIVED)->count(),
            ],
        ]);
    }

    /**
     * Query builder untuk admin list - support filter status + date range.
     * Dipakai bersama oleh adminIndex + exportCsv.
     */
    private function buildAdminQuery($hrd, Request $request)
    {
        $query = Contract::where('company_id', $hrd->company_id)
            ->with('user:id,name,email,nip')
            ->orderByDesc('issued_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->input('contract_type')) {
            $query->where('contract_type', $type);
        }
        if ($from = $request->input('date_from')) {
            $query->whereDate('issued_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('issued_at', '<=', $to);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    /** POST /admin/contracts/bulk-delete { ids: [1,2,3] } - hanya draft/rejected */
    public function bulkDelete(Request $request): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $contracts = Contract::where('company_id', $hrd->company_id)
            ->whereIn('id', $data['ids'])
            ->whereIn('status', [Contract::STATE_DRAFT, Contract::STATE_REJECTED])
            ->get();

        $deleted = 0;
        foreach ($contracts as $c) {
            app(AuditLogService::class)->log(
                'contract.bulk_deleted', $hrd, 'contract', (string) $c->id,
                ['contract_number' => $c->contract_number],
            );
            $c->delete();
            $this->service->deletePrivateAssets($c);
            $deleted++;
        }

        return $this->success([
            'deleted' => $deleted,
            'skipped' => count($data['ids']) - $deleted,
            'skip_reason' => 'Hanya kontrak berstatus draft atau dibatalkan yang dapat dihapus.',
        ], "{$deleted} kontrak berhasil dihapus.");
    }

    /** POST /admin/contracts/bulk-reject { ids: [...], reason: string } */
    public function bulkReject(Request $request): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $eligible = [Contract::STATE_DRAFT, Contract::STATE_PARAF_HRD, Contract::STATE_PENDING_KARYAWAN,
            Contract::STATE_SIGNED_KARYAWAN, Contract::STATE_PENDING_DIREKTUR, Contract::STATE_PENDING_MATERAI];

        $contracts = Contract::where('company_id', $hrd->company_id)
            ->whereIn('id', $data['ids'])
            ->whereIn('status', $eligible)
            ->get();

        $rejected = 0;
        foreach ($contracts as $c) {
            $this->service->reject($hrd, $c, $data['reason']);
            $rejected++;
        }

        return $this->success([
            'rejected' => $rejected,
            'skipped' => count($data['ids']) - $rejected,
        ], "{$rejected} kontrak berhasil dibatalkan.");
    }

    /**
     * GET /admin/contracts/export.csv - export sesuai filter (status/date_from/date_to/search)
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $contracts = $this->buildAdminQuery($hrd, $request)->get();
        $filename = 'kontrak-kerja-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($contracts) {
            $out = fopen('php://output', 'w');
            // BOM untuk UTF-8 Excel
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'Nomor Kontrak', 'Karyawan', 'Email', 'NIP', 'Jenis Kontrak', 'Status',
                'Terbit', 'Mulai', 'Berakhir',
                'Paraf HRD', 'Karyawan TTD', 'Direktur TTD', 'E-Materai',
            ]);
            foreach ($contracts as $c) {
                fputcsv($out, [
                    $c->contract_number,
                    $c->user?->name ?? '-',
                    $c->user?->email ?? '-',
                    $c->user?->nip ?? '-',
                    $c->contract_type,
                    $c->status,
                    $c->issued_at?->format('Y-m-d'),
                    $c->start_date?->format('Y-m-d'),
                    $c->end_date?->format('Y-m-d'),
                    $c->hrd_paraf_at?->format('Y-m-d H:i'),
                    $c->karyawan_signed_at?->format('Y-m-d H:i') ?? $c->signed_at?->format('Y-m-d H:i'),
                    $c->direktur_signed_at?->format('Y-m-d H:i'),
                    $c->materai_uploaded_at?->format('Y-m-d H:i'),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * GET /admin/contracts/reminder-log
     * List semua kontrak dengan history reminder yang sudah dikirim.
     * Data dari kolom `renewal_reminders_sent` (JSON: {"30_day": "date", "14_day": ..., "7_day": ...}).
     */
    public function reminderLog(Request $request): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $contracts = Contract::where('company_id', $hrd->company_id)
            ->whereNotNull('renewal_reminders_sent')
            ->with('user:id,name,email')
            ->orderByDesc('end_date')
            ->get()
            ->filter(fn ($c) => ! empty((array) $c->renewal_reminders_sent))
            ->values()
            ->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'employee_name' => $c->user?->name,
                'employee_email' => $c->user?->email,
                'end_date' => $c->end_date?->toDateString(),
                'reminders_sent' => (array) $c->renewal_reminders_sent,
            ]);

        return $this->success([
            'log' => $contracts,
            'total' => $contracts->count(),
        ]);
    }

    /**
     * GET /admin/contracts/expiring?days=30
     * List kontrak archived yang akan berakhir dalam N hari - untuk widget renewal reminder.
     */
    public function expiring(Request $request): JsonResponse
    {
        $hrd = $request->user();
        $this->requireCapability($hrd, 'employees.manage', 'Contract management access required.');

        $days = min(365, max(1, (int) $request->input('days', 30)));
        $today = now()->toDateString();
        $target = now()->addDays($days)->toDateString();

        $contracts = Contract::where('company_id', $hrd->company_id)
            ->where('status', Contract::STATE_ARCHIVED)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$today, $target])
            ->with('user:id,name,email')
            ->orderBy('end_date')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'employee_name' => $c->user?->name,
                'contract_type' => $c->contract_type,
                'end_date' => $c->end_date?->toDateString(),
                'days_left' => now()->diffInDays($c->end_date, false),
            ]);

        return $this->success([
            'contracts' => $contracts,
            'total' => $contracts->count(),
        ]);
    }

    // ═══════════════════ Karyawan Endpoints ═══════════════════

    public function myContracts(Request $request): JsonResponse
    {
        $karyawan = $request->user();
        $contracts = Contract::where('user_id', $karyawan->id)
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn ($c) => $this->transformContract($c));

        $pending = $this->service->pendingKaryawanSign($karyawan)
            ->map(fn ($c) => $this->transformContract($c));

        return $this->success([
            'pending' => $pending,
            'all' => $contracts,
        ]);
    }

    public function signAsKaryawan(Request $request, int $id): JsonResponse
    {
        $karyawan = $request->user();
        $data = $request->validate([
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,'],
            'consent' => ['required', 'accepted'],
        ]);

        $contract = Contract::where('user_id', $karyawan->id)->findOrFail($id);
        $updated = $this->service->signByKaryawan($karyawan, $contract, $data['signature'], $request->ip());

        // Auto-forward ke direktur setelah karyawan sign (per user's flow request)
        $updated = $this->service->sendToDirektur($karyawan, $updated);

        return $this->success([
            'contract_id' => $updated->id,
            'status' => $updated->status,
        ], 'Kontrak berhasil ditandatangani. Selanjutnya menunggu tanda tangan Direktur.');
    }

    // ═══════════════════ Direktur Endpoints ═══════════════════

    public function pendingSignDirektur(Request $request): JsonResponse
    {
        $direktur = $request->user();
        $this->requireCapability($direktur, 'contract.final_sign', 'Director contract signing access required.');

        $contracts = $this->service->pendingDirekturSign($direktur->company_id)
            ->map(fn ($c) => $this->transformContract($c));

        return $this->success(['contracts' => $contracts]);
    }

    public function signAsDirektur(Request $request, int $id): JsonResponse
    {
        $direktur = $request->user();
        $this->requireCapability($direktur, 'contract.final_sign', 'Director contract signing access required.');

        // Signature optional: kalau tidak ada, service akan pakai TTD tersimpan
        // dari company profile (director_signature_path) saat regenerate PDF.
        $data = $request->validate([
            'signature' => ['nullable', 'string', 'starts_with:data:image/png;base64,'],
        ]);

        $contract = Contract::where('company_id', $direktur->company_id)->findOrFail($id);

        $updated = $this->service->signByDirektur($direktur, $contract, $data['signature'] ?? null);

        return $this->success([
            'contract_id' => $updated->id,
            'status' => $updated->status,
        ], 'Kontrak berhasil ditandatangani Direktur. Menunggu HRD upload e-materai.');
    }

    // ═══════════════════ Preview / Download ═══════════════════

    public function preview(Request $request, int $id)
    {
        $user = $request->user();
        $contract = Contract::query()->withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->findOrFail($id);

        if ((string) $user->id !== (string) $contract->user_id) {
            $this->requireAnyCapability(
                $user,
                ['employees.manage', 'contract.final_sign'],
                'Contract document access required.',
            );
        }

        return $this->service->generateLivePdfResponse($contract);
    }

    // ═══════════════════ Utility ═══════════════════

    private function transformContract(Contract $c): array
    {
        return [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'contract_type' => $c->contract_type,
            'status' => $c->status,
            'status_label' => $this->statusLabel($c->status),
            'issued_at' => $c->issued_at?->toDateString(),
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'user_id' => $c->user_id,
            'employee_name' => $c->user?->name,
            'employee_email' => $c->user?->email,
            'hrd_paraf_at' => $c->hrd_paraf_at?->toIso8601String(),
            'karyawan_signed_at' => $c->karyawan_signed_at?->toIso8601String() ?? $c->signed_at?->toIso8601String(),
            'direktur_signed_at' => $c->direktur_signed_at?->toIso8601String(),
            'materai_uploaded_at' => $c->materai_uploaded_at?->toIso8601String(),
            'preview_url' => url("/api/v1/contracts/{$c->id}/preview"),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Contract::STATE_DRAFT => 'Draft (menunggu paraf HRD)',
            Contract::STATE_PARAF_HRD => 'Diparaf HRD',
            Contract::STATE_PENDING_KARYAWAN => 'Menunggu TTD Karyawan',
            Contract::STATE_SIGNED_KARYAWAN => 'Karyawan sudah TTD',
            Contract::STATE_PENDING_DIREKTUR => 'Menunggu TTD Direktur',
            Contract::STATE_SIGNED_DIREKTUR => 'Direktur sudah TTD',
            Contract::STATE_PENDING_MATERAI => 'Menunggu Upload E-Materai',
            Contract::STATE_ARCHIVED => 'Arsip Final',
            Contract::STATE_REJECTED => 'Dibatalkan',
            'pending_signature' => 'Menunggu TTD Karyawan (legacy)',
            'signed' => 'Sudah Ditandatangani (legacy)',
            default => ucfirst($status),
        };
    }
}
