<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ContractLifecycleService;
use App\Services\PrivateAssetStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Workflow kontrak kerja:
 *   1. Admin set employment_status karyawan → "permanent"
 *   2. Admin klik "Terbitkan Kontrak" di profil karyawan (POST /admin/users/{id}/issue-contract)
 *   3. Backend buat record contracts (status=pending_signature) + render PDF dengan placeholder terisi
 *   4. Karyawan dapat notifikasi → buka /contract/sign → tanda tangan via canvas → submit base64 PNG
 *   5. Backend embed signature ke PDF, simpan signed_pdf_path, status=signed
 *   6. Admin & karyawan bisa download final PDF
 */
class ContractController extends ApiController
{
    public function __construct(private readonly PrivateAssetStorage $privateAssets) {}

    /** POST /api/v1/admin/users/{user}/issue-contract */
    public function issue(Request $request, string $user): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Karyawan management access required.');

        $target = User::query()->withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('id', $user)
            ->firstOrFail();

        // Per kebijakan IBE Reality (2026-06-10): hanya 2 jenis kontrak yang
        // diterbitkan resmi - Permanent (PKWTT) dan Probation. Magang/PKL juga
        // dirender via template Probation agar klausa disiplin & sistem absensi
        // konsisten. Status karyawan tetap bisa beragam untuk reporting HR,
        // tapi dokumen kontrak hanya 2.
        $statusToType = [
            'permanent' => 'permanent',
            'probation' => 'probation',
            'intern_campus' => 'probation',
            'intern_hub' => 'probation',
            'pkl_smk' => 'probation',
        ];
        $contractType = $statusToType[$target->employment_status] ?? null;
        if (! $contractType) {
            return response()->json([
                'message' => "Karyawan dengan status \"{$target->employment_status}\" belum bisa diterbitkan kontrak. Set employment_status dulu (permanent / probation / intern_campus / intern_hub / pkl_smk).",
            ], 422);
        }

        $existingPending = Contract::query()
            ->where('user_id', $target->id)
            ->where('status', 'pending_signature')
            ->first();
        if ($existingPending) {
            return $this->success([
                'contract_id' => $existingPending->id,
                'contract_number' => $existingPending->contract_number,
                'contract_type' => $existingPending->contract_type,
                'status' => $existingPending->status,
                'pdf_url' => url("/api/v1/admin/contracts/{$existingPending->id}/preview"),
            ], 'Kontrak sudah ada - tinggal menunggu tanda tangan karyawan.');
        }

        // Default durasi per tipe - admin bisa override nanti via edit (TODO)
        $start = now();
        $end = match ($contractType) {
            'probation' => $start->copy()->addMonths(3),
            'internship' => $start->copy()->addMonths(6),
            default => null, // permanent = tidak terbatas
        };

        $contractNumber = $this->generateContractNumber($admin->company_id, $contractType);

        $placeholders = [
            'name' => $target->name,
            'address' => $target->address,
            'nik' => $target->nip,
            'phone' => $target->phone,
            'email' => $target->email,
            'job_title' => $target->jobTitle?->name,
            'division' => $target->division?->name,
        ];

        $contract = Contract::create([
            'user_id' => $target->id,
            'company_id' => $admin->company_id,
            'contract_number' => $contractNumber,
            'contract_type' => $contractType,
            'issued_at' => $start->toDateString(),
            'start_date' => $start->toDateString(),
            'end_date' => $end?->toDateString(),
            'monthly_amount' => $target->salary_monthly,
            'status' => 'pending_signature',
            'placeholders' => $placeholders,
            'issued_by' => $admin->id,
        ]);

        // Audit
        app(AuditLogService::class)->log(
            'contract.issued',
            $admin, 'contract', (string) $contract->id,
            ['user_id' => $target->id, 'contract_number' => $contractNumber],
            $request,
        );

        return $this->created([
            'contract_id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'status' => $contract->status,
            'pdf_url' => url("/api/v1/admin/contracts/{$contract->id}/preview"),
        ], 'Kontrak diterbitkan. Karyawan dapat tanda tangan via portal.');
    }

    /**
     * GET /api/v1/me/contract - kontrak pending TTD + kontrak terakhir untuk view/download.
     * Selain pending, tetap return latest supaya karyawan bisa lihat kontrak yang sudah signed/archived.
     */
    public function myPending(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Kontrak butuh TTD karyawan.
        // Skip kontrak legacy yg template_id NULL - itu draft flow lama sebelum multi-signer,
        // isi contract-nya berasal dari template_id + placeholders di flow baru.
        $pending = Contract::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending_signature', Contract::STATE_PENDING_KARYAWAN])
            ->whereNotNull('template_id')
            ->orderByDesc('issued_at')
            ->first();

        // Guard: kalau ada kontrak lebih baru (issued_at lebih recent) yg sudah masuk lifecycle post-karyawan,
        // artinya kontrak "pending" yg dipilih di atas adalah stale - abaikan.
        if ($pending) {
            $newer = Contract::query()
                ->where('user_id', $userId)
                ->where('id', '!=', $pending->id)
                ->where('issued_at', '>=', $pending->issued_at)
                ->whereIn('status', [
                    Contract::STATE_SIGNED_KARYAWAN,
                    Contract::STATE_PENDING_DIREKTUR,
                    Contract::STATE_SIGNED_DIREKTUR,
                    Contract::STATE_PENDING_MATERAI,
                    Contract::STATE_ARCHIVED,
                ])
                ->exists();
            if ($newer) {
                $pending = null;
            }
        }

        // Kontrak paling recent (semua state kecuali rejected) untuk view/download
        $latest = Contract::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'rejected')
            ->orderByDesc('issued_at')
            ->first();

        $format = function (?Contract $c) {
            if (! $c) {
                return null;
            }

            return [
                'contract_id' => $c->id,
                'contract_number' => $c->contract_number,
                'contract_type' => $c->contract_type,
                'status' => $c->status,
                'issued_at' => $c->issued_at?->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'placeholders' => $c->placeholders,
                'pdf_url' => url("/api/v1/me/contract/{$c->id}/preview"),
                'is_new_flow' => ! empty($c->template_id),
            ];
        };

        return $this->success([
            'pending' => $format($pending),
            'latest' => $format($latest),
        ]);
    }

    /** POST /api/v1/me/contract/{id}/sign - karyawan kirim signature data URL */
    public function sign(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,'],
            'consent' => ['required', 'accepted'],
        ]);

        $contract = Contract::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending_signature', Contract::STATE_PENDING_KARYAWAN])
            ->findOrFail($id);

        // Kontrak new-flow (punya template_id) → delegate ke lifecycle service supaya
        // auto-forward ke direktur setelah karyawan sign.
        if ($contract->template_id) {
            $lifecycle = app(ContractLifecycleService::class);
            $updated = $lifecycle->signByKaryawan(
                $request->user(), $contract, $request->input('signature'), $request->ip(),
            );
            $updated = $lifecycle->sendToDirektur($request->user(), $updated);

            return $this->success([
                'contract_id' => $updated->id,
                'pdf_url' => url("/api/v1/contracts/{$updated->id}/preview"),
                'signed_at' => $updated->karyawan_signed_at?->toIso8601String(),
                'status' => $updated->status,
            ], 'Kontrak ditandatangani. Menunggu tanda tangan Direktur.');
        }

        $signature = $this->privateAssets->storePng(
            (int) $contract->company_id,
            'contracts',
            (string) $contract->id,
            'employee-signature',
            $this->privateAssets->strictDecodePngDataUri($request->input('signature')),
        );
        $pdf = null;
        try {
            DB::transaction(function () use ($contract, $request, $signature, &$pdf): void {
                $contract->forceFill([
                    'signature_path' => $signature['path'],
                    'signature_sha256' => $signature['sha256'],
                    'signed_at' => now(),
                    'signed_ip' => $request->ip(),
                    'status' => 'signed',
                ]);
                $pdf = $this->renderPdf($contract, $request->input('signature'), $request->ip());
                $contract->signed_pdf_path = $pdf['path'];
                $contract->signed_pdf_sha256 = $pdf['sha256'];
                $contract->save();
            });
        } catch (\Throwable $e) {
            $this->privateAssets->deletePrivate((int) $contract->company_id, 'contracts', (string) $contract->id, $signature['path']);
            if ($pdf) {
                $this->privateAssets->deletePrivate((int) $contract->company_id, 'contracts', (string) $contract->id, $pdf['path']);
            }
            throw $e;
        }

        app(AuditLogService::class)->log(
            'contract.signed',
            $request->user(), 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number],
            $request,
        );

        return $this->success([
            'contract_id' => $contract->id,
            'pdf_url' => url("/api/v1/me/contract/{$contract->id}/download"),
            'signed_at' => $contract->signed_at->toIso8601String(),
        ], 'Kontrak berhasil ditandatangani.');
    }

    /** GET /api/v1/me/contract/{id}/preview - preview HTML PDF (unsigned) */
    public function preview(Request $request, int $id)
    {
        $contract = Contract::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Kontrak new-flow (punya template_id) → delegate ke lifecycle service
        // agar render pakai blade template baru (admin.contracts.template)
        if ($contract->template_id) {
            return app(ContractLifecycleService::class)->generateLivePdfResponse($contract);
        }

        return $this->buildPdfResponse($contract, null, null, false);
    }

    /** GET /api/v1/me/contract/{id}/download - final signed PDF */
    public function downloadMine(Request $request, int $id)
    {
        $contract = Contract::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'signed')
            ->findOrFail($id);

        return $this->buildPdfResponse($contract, null, null, true);
    }

    /** GET /api/v1/admin/contracts/{id}/preview - admin preview/download */
    public function adminPreview(Request $request, int $id)
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Karyawan management access required.');
        $contract = Contract::query()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);

        return $this->buildPdfResponse($contract, null, null, $contract->status === 'signed');
    }

    /** GET /api/v1/admin/users/{user}/contracts - riwayat kontrak per karyawan */
    public function adminListForUser(Request $request, string $user): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'employees.manage', 'Karyawan management access required.');
        $rows = Contract::query()
            ->where('company_id', $admin->company_id)
            ->where('user_id', $user)
            ->orderByDesc('issued_at')
            ->get();

        return $this->success([
            'rows' => $rows->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'issued_at' => $c->issued_at->toDateString(),
                'status' => $c->status,
                'signed_at' => $c->signed_at?->toIso8601String(),
                'pdf_url' => url("/api/v1/admin/contracts/{$c->id}/preview"),
            ]),
        ]);
    }

    private function renderPdf(Contract $contract, ?string $signatureDataUri, ?string $signedIp): array
    {
        $pdf = $this->buildPdfInstance($contract, $signatureDataUri, $signedIp);

        return $this->privateAssets->storePdf(
            (int) $contract->company_id,
            'contracts',
            (string) $contract->id,
            'signed-pdf',
            $pdf->output(),
        );
    }

    private function buildPdfResponse(Contract $contract, ?string $signatureDataUri, ?string $signedIp, bool $useStored)
    {
        if ($useStored && $contract->signed_pdf_path) {
            return $this->privateAssets->downloadResponse(
                (int) $contract->company_id,
                'contracts',
                (string) $contract->id,
                $contract->signed_pdf_path,
                $contract->signed_pdf_sha256,
                'pdf',
                'kontrak-'.$contract->id.'.pdf',
            );
        }
        // Force Content-Disposition: inline supaya browser embed di iframe/embed
        // (default DomPDF stream() kadang bikin browser auto-download).
        $pdf = $this->buildPdfInstance($contract, $signatureDataUri, $signedIp);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="kontrak-'.$contract->contract_number.'.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function buildPdfInstance(Contract $contract, ?string $signatureDataUri, ?string $signedIp)
    {
        $issuedAt = $contract->issued_at;
        $signedAt = $contract->signed_at;
        $sigData = $signatureDataUri;
        if (! $sigData && $contract->signature_path) {
            $sigData = $this->privateAssets->dataUri(
                (int) $contract->company_id,
                'contracts',
                (string) $contract->id,
                $contract->signature_path,
                $contract->signature_sha256,
            );
        }

        // Embed company logo sebagai data URI supaya DomPDF reliable
        // (DomPDF kadang gagal load logo via URL relatif/absolute).
        $logoData = null;
        $company = Company::find($contract->company_id);
        if ($company && $company->logo) {
            $disk = Storage::disk('public');
            if ($disk->exists($company->logo)) {
                $ext = strtolower(pathinfo($company->logo, PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : ($ext === 'svg' ? 'image/svg+xml' : 'image/png');
                // Diperkecil dulu (lihat pdf/reimbursement-receipt) - logo utuh
                // pernah membuat Imagick di DomPDF memenuhi /tmp container.
                $logoData = \App\Helpers::cachedResizedImageDataUri($company->logo, 600, 600)
                    ?? ($ext === 'svg' ? 'data:'.$mime.';base64,'.base64_encode($disk->get($company->logo)) : null);
            }
        }

        // Pilih template per tipe kontrak. Default fallback ke permanent
        // supaya contracts lama (sebelum migration) tetap render.
        // Hanya 2 template aktif (Permanent + Probation). Legacy data type
        // 'internship' tetap di-render via probation agar tidak broken.
        $template = match ($contract->contract_type) {
            'permanent' => 'contracts.kontrak-kerja',  // PKWTT
            default => 'contracts.kontrak-probation', // probation, intern_*, pkl_smk
        };

        return Pdf::loadView($template, [
            'name' => $contract->placeholders['name'] ?? '-',
            'address' => $contract->placeholders['address'] ?? null,
            'nik' => $contract->placeholders['nik'] ?? null,
            'jobTitle' => $contract->placeholders['job_title'] ?? null,
            'divisionName' => $contract->placeholders['division'] ?? null,
            'dayName' => $issuedAt->translatedFormat('l'),
            'issuedDateLabel' => $issuedAt->translatedFormat('j F Y'),
            'startDateLabel' => $contract->start_date?->translatedFormat('j F Y'),
            'endDateLabel' => $contract->end_date?->translatedFormat('j F Y'),
            'monthlyAmount' => $contract->monthly_amount,
            'signatureDataUri' => $sigData,
            'signedDateLabel' => $signedAt?->translatedFormat('j F Y'),
            'signedTimeLabel' => $signedAt?->translatedFormat('H:i'),
            'signedAt' => $signedAt,
            'signedIp' => $signedIp ?? $contract->signed_ip,
            'contractNumber' => $contract->contract_number,
            'contractType' => $contract->contract_type,
            'logoData' => $logoData,
            'company' => $company,
        ])->setPaper('a4', 'portrait');
    }

    private function generateContractNumber(int $companyId, string $type = 'permanent'): string
    {
        $year = now()->year;
        $prefix = match ($type) {
            'probation' => 'PROB',
            'internship' => 'MAGANG',
            default => 'PKWTT',
        };
        $seq = Contract::query()
            ->whereYear('created_at', $year)
            ->where('company_id', $companyId)
            ->where('contract_type', $type)
            ->count() + 1;

        return sprintf('%s/%04d/%03d/IBE/%d', $prefix, $companyId, $seq, $year);
    }
}
