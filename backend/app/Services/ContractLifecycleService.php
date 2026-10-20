<?php

namespace App\Services;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Helpers;
use App\Mail\ContractLifecycleNotification;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orchestrator untuk lifecycle kontrak multi-signer.
 *
 * State machine:
 *   generate() → draft
 *   parafHrd() → paraf_hrd → sendToKaryawan() → pending_karyawan
 *   signByKaryawan() → signed_karyawan → sendToDirektur() → pending_direktur
 *   signByDirektur() → signed_direktur → pending_materai
 *   uploadMaterai() → archived
 *   reject() → rejected
 *
 * Setiap transisi otomatis regenerate final PDF (dengan tanda tangan yg tersedia
 * di-embed) dan mencatat audit log via AuditLogService.
 */
class ContractLifecycleService
{
    public function __construct(
        private readonly ContractTemplateService $templateService,
        private readonly AuditLogService $auditLog,
        private readonly PrivateAssetStorage $privateAssets,
    ) {}

    // ═══════════════════ HRD: Generate + Paraf ═══════════════════

    /**
     * HRD generate kontrak baru dari template.
     * State: draft (belum paraf, isi bisa direview + edit).
     */
    public function generate(User $hrd, User $karyawan, ContractTemplate $tpl, array $overrides = []): Contract
    {
        $now = now();
        $start = isset($overrides['start_date']) ? Carbon::parse($overrides['start_date']) : $now;
        $end = isset($overrides['end_date']) ? Carbon::parse($overrides['end_date']) : null;

        $vars = $this->templateService->buildVariables(
            $karyawan,
            Company::find($hrd->company_id),
            [
                'nomor_kontrak' => $this->generateContractNumber($hrd->company_id, $tpl->employment_type),
                'tanggal_kontrak' => $now->translatedFormat('j F Y'),
                'tanggal_mulai_kerja' => $start->translatedFormat('j F Y'),
                'tanggal_akhir' => $end?->translatedFormat('j F Y'),
            ],
        );

        $contract = Contract::create([
            'user_id' => $karyawan->id,
            'company_id' => $hrd->company_id,
            'contract_number' => $vars['nomor_kontrak'],
            'contract_type' => $tpl->employment_type,
            'template_id' => $tpl->id,
            'issued_at' => $now->toDateString(),
            'start_date' => $start->toDateString(),
            'end_date' => $end?->toDateString(),
            'monthly_amount' => $karyawan->salary_monthly,
            'status' => Contract::STATE_DRAFT,
            'placeholders' => $vars,
            'issued_by' => $hrd->id,
        ]);

        $this->auditLog->log(
            'contract.generated', $hrd, 'contract', (string) $contract->id,
            ['user_id' => $karyawan->id, 'contract_number' => $contract->contract_number],
        );

        return $contract;
    }

    /**
     * HRD paraf → transisi ke paraf_hrd.
     * Signature HRD di-embed sebagai overlay di footer PDF.
     */
    public function parafHrd(User $hrd, Contract $contract, ?string $signatureDataUri, ?string $notes = null): Contract
    {
        $this->assertState($contract, [Contract::STATE_DRAFT]);

        $signature = $signatureDataUri
            ? $this->storeSignatureDataUri($contract, $signatureDataUri, 'hrd-paraf-signature')
            : null;

        try {
            $contract->update([
                'status' => Contract::STATE_PARAF_HRD,
                'hrd_paraf_by' => $hrd->id,
                'hrd_paraf_at' => now(),
                'hrd_paraf_signature_path' => $signature['path'] ?? null,
                'hrd_paraf_signature_sha256' => $signature['sha256'] ?? null,
                'hrd_paraf_notes' => $notes,
            ]);
        } catch (\Throwable $e) {
            $this->deleteStored($contract, $signature['path'] ?? null);
            throw $e;
        }

        $this->auditLog->log(
            'contract.hrd_paraf', $hrd, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number],
        );

        return $contract->fresh();
    }

    /**
     * Kirim ke karyawan untuk TTD.
     * State: paraf_hrd → pending_karyawan.
     */
    public function sendToKaryawan(User $hrd, Contract $contract): Contract
    {
        $this->assertState($contract, [Contract::STATE_PARAF_HRD]);
        $contract->update(['status' => Contract::STATE_PENDING_KARYAWAN]);

        $karyawan = User::withoutGlobalScopes()->find($contract->user_id);
        $this->sendMail($karyawan, $contract, 'contract.ready_for_karyawan', $this->karyawanPortalUrl().'/contract/sign');

        $this->auditLog->log(
            'contract.sent_to_karyawan', $hrd, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number],
        );

        return $contract->fresh();
    }

    // ═══════════════════ Karyawan: Sign ═══════════════════

    public function signByKaryawan(User $karyawan, Contract $contract, string $signatureDataUri, ?string $ip = null): Contract
    {
        $this->assertState($contract, [Contract::STATE_PENDING_KARYAWAN]);

        if ($contract->user_id !== $karyawan->id) {
            abort(403, 'Kontrak ini bukan milik Anda.');
        }

        $signature = $this->storeSignatureDataUri($contract, $signatureDataUri, 'employee-signature');

        try {
            $contract->update([
                'status' => Contract::STATE_SIGNED_KARYAWAN,
                'karyawan_signature_path' => $signature['path'],
                'karyawan_signature_sha256' => $signature['sha256'],
                'karyawan_signed_at' => now(),
                'karyawan_signed_ip' => $ip,
                // Legacy compat: same immutable private object and checksum.
                'signature_path' => $signature['path'],
                'signature_sha256' => $signature['sha256'],
                'signed_at' => now(),
                'signed_ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            $this->deleteStored($contract, $signature['path']);
            throw $e;
        }

        $this->auditLog->log(
            'contract.signed_karyawan', $karyawan, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number],
        );

        return $contract->fresh();
    }

    /**
     * Kirim ke direktur untuk TTD final.
     * State: signed_karyawan → pending_direktur.
     * Bisa auto-triggered saat karyawan sign, atau manual oleh HRD.
     */
    public function sendToDirektur(User $actor, Contract $contract): Contract
    {
        $this->assertState($contract, [Contract::STATE_SIGNED_KARYAWAN]);
        $contract->update(['status' => Contract::STATE_PENDING_DIREKTUR]);

        // Notify Direktur(s) - cari user dengan group 'direktur' di company yg sama
        $direkturs = User::withoutGlobalScopes()
            ->where('company_id', $contract->company_id)
            ->where('group', 'direktur')
            ->get();
        foreach ($direkturs as $d) {
            $this->sendMail($d, $contract, 'contract.ready_for_direktur', config('app.url').'/admin/direktur/tanda-tangan');
        }

        $this->auditLog->log(
            'contract.sent_to_direktur', $actor, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number],
        );

        return $contract->fresh();
    }

    // ═══════════════════ Direktur: Sign ═══════════════════

    public function signByDirektur(User $direktur, Contract $contract, ?string $signatureDataUri): Contract
    {
        $this->assertState($contract, [Contract::STATE_PENDING_DIREKTUR]);

        if ($signatureDataUri !== null) {
            $signature = $this->storeSignatureDataUri($contract, $signatureDataUri, 'director-signature');
        } else {
            $company = Company::query()->findOrFail($contract->company_id);
            if (! $company->director_signature_path) {
                throw new PrivateAssetIntegrityException('missing', 'Master director signature is unavailable.', 422);
            }
            $bytes = $this->privateAssets->readVerified(
                (int) $company->id,
                'company-signatures',
                (string) $company->id,
                $company->director_signature_path,
                $company->director_signature_sha256,
                'png',
            );
            $signature = $this->privateAssets->storePng(
                (int) $contract->company_id,
                'contracts',
                (string) $contract->id,
                'director-signature',
                $bytes,
            );
        }

        $pdf = null;
        try {
            DB::transaction(function () use ($contract, $direktur, $signature, &$pdf): void {
                $contract->update([
                    'status' => Contract::STATE_PENDING_MATERAI,
                    'direktur_signature_path' => $signature['path'],
                    'direktur_signature_sha256' => $signature['sha256'],
                    'direktur_signed_by' => $direktur->id,
                    'direktur_signed_at' => now(),
                ]);

                $pdf = $this->regenerateFinalPdf($contract->fresh());
                $contract->update([
                    'signed_pdf_path' => $pdf['path'],
                    'signed_pdf_sha256' => $pdf['sha256'],
                ]);
            });
        } catch (\Throwable $e) {
            $this->deleteStored($contract, $signature['path']);
            $this->deleteStored($contract, $pdf['path'] ?? null);
            throw $e;
        }

        // Notify HRD untuk upload materai
        $hrdList = User::withoutGlobalScopes()
            ->where('company_id', $contract->company_id)
            ->whereIn('group', ['admin', 'superadmin'])
            ->get();
        foreach ($hrdList as $hrd) {
            $this->sendMail($hrd, $contract, 'contract.materai_needed', config('app.url').'/admin/kontrak/kelola');
        }

        $this->auditLog->log(
            'contract.signed_direktur', $direktur, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number],
        );

        return $contract->fresh();
    }

    // ═══════════════════ E-Materai Upload (P5) ═══════════════════

    /**
     * HRD skip step e-materai - langsung archive kontrak.
     * Dipakai kalau materai akan ditempel manual (fisik) di dokumen cetak,
     * atau kalau internal HR policy tidak wajibkan e-materai.
     * State: pending_materai → archived.
     */
    public function skipMaterai(User $hrd, Contract $contract): Contract
    {
        $this->assertState($contract, [Contract::STATE_PENDING_MATERAI]);

        $contract->update([
            'status' => Contract::STATE_ARCHIVED,
            'materai_uploaded_at' => now(),
            'materai_uploaded_by' => $hrd->id,
        ]);

        // Notify karyawan bahwa kontrak sudah final
        $karyawan = User::withoutGlobalScopes()->find($contract->user_id);
        $this->sendMail($karyawan, $contract, 'contract.archived', $this->karyawanPortalUrl().'/contract/sign');

        $this->auditLog->log(
            'contract.materai_skipped', $hrd, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number, 'reason' => 'HRD skip - materai fisik atau tidak wajib'],
        );

        return $contract->fresh();
    }

    /**
     * HRD upload PDF yang sudah ditempel e-materai secara manual.
     * State: pending_materai → archived.
     */
    public function uploadMaterai(User $hrd, Contract $contract, UploadedFile $file): Contract
    {
        $this->assertState($contract, [Contract::STATE_PENDING_MATERAI]);

        $pdf = $this->privateAssets->storeUploadedPdf(
            (int) $contract->company_id,
            'contracts',
            (string) $contract->id,
            'materai-pdf',
            $file,
        );
        try {
            $contract->update([
                'status' => Contract::STATE_ARCHIVED,
                'materai_pdf_path' => $pdf['path'],
                'materai_pdf_sha256' => $pdf['sha256'],
                'materai_uploaded_at' => now(),
                'materai_uploaded_by' => $hrd->id,
            ]);
        } catch (\Throwable $e) {
            $this->deleteStored($contract, $pdf['path']);
            throw $e;
        }

        // Notify karyawan bahwa kontrak sudah final
        $karyawan = User::withoutGlobalScopes()->find($contract->user_id);
        $this->sendMail($karyawan, $contract, 'contract.archived', $this->karyawanPortalUrl().'/contract/sign');

        $this->auditLog->log(
            'contract.materai_uploaded', $hrd, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number],
        );

        return $contract->fresh();
    }

    /**
     * URL portal karyawan (PWA attendance) untuk deep link email notification.
     * Default: https://attendance.ibereality.com. Override via env KARYAWAN_PORTAL_URL.
     */
    private function karyawanPortalUrl(): string
    {
        return rtrim(env('KARYAWAN_PORTAL_URL', 'https://attendance.ibereality.com'), '/');
    }

    /**
     * Kirim notifikasi email - swallow error jangan sampai crash workflow.
     */
    private function sendMail(?User $recipient, Contract $contract, string $event, ?string $actionUrl = null): void
    {
        if (! $recipient || ! $recipient->email) {
            return;
        }

        try {
            Mail::to($recipient->email)->send(
                new ContractLifecycleNotification(
                    $contract,
                    $event,
                    $recipient->name ?? $recipient->email,
                    $actionUrl,
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Contract email notification failed: '.$e->getMessage(), [
                'contract_id' => $contract->id,
                'event' => $event,
                'to' => $recipient->email,
            ]);
        }
    }

    // ═══════════════════ Rejection ═══════════════════

    public function reject(User $actor, Contract $contract, string $reason): Contract
    {
        $contract->update([
            'status' => Contract::STATE_REJECTED,
            'rejection_reason' => $reason,
            'rejected_by' => $actor->id,
            'rejected_at' => now(),
        ]);

        $this->auditLog->log(
            'contract.rejected', $actor, 'contract', (string) $contract->id,
            ['contract_number' => $contract->contract_number, 'reason' => $reason],
        );

        return $contract->fresh();
    }

    // ═══════════════════ PDF Generation ═══════════════════

    /**
     * Regenerate PDF dengan tanda tangan yang tersedia di-embed.
     * Dipakai setelah direktur sign (siap materai) supaya PDF final rapi.
     */
    public function regenerateFinalPdf(Contract $contract): array
    {
        $data = $this->buildPdfTemplateData($contract);
        $pdf = Pdf::loadView('admin.contracts.template', $data)->setPaper('a4', 'portrait');

        return $this->privateAssets->storePdf(
            (int) $contract->company_id,
            'contracts',
            (string) $contract->id,
            'signed-pdf',
            $pdf->output(),
        );
    }

    /**
     * Generate PDF live untuk preview di modal.
     * State signed_karyawan+ → tampilkan tanda tangan yang tersedia.
     */
    public function generateLivePdfResponse(Contract $contract): StreamedResponse
    {
        $data = $this->buildPdfTemplateData($contract);
        $pdf = Pdf::loadView('admin.contracts.template', $data)->setPaper('a4', 'portrait');

        $safeName = 'kontrak-'.str_replace(['/', '\\'], '-', $contract->contract_number).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $safeName,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$safeName.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }

    private function buildPdfTemplateData(Contract $contract): array
    {
        $tpl = $contract->template_id ? ContractTemplate::find($contract->template_id) : null;
        $company = Company::find($contract->company_id);
        $karyawan = User::withoutGlobalScopes()->find($contract->user_id);

        // Render body_html dengan placeholders yang tersimpan
        $renderedHtml = $tpl
            ? $this->templateService->render($tpl->body_html, (array) $contract->placeholders)
            : '<p>Template kontrak tidak ditemukan.</p>';

        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $now = $contract->issued_at ?? now();
        $placeDate = ($company?->regency ?? 'Malang').', '
            .$now->day.' '.$bulan[$now->month - 1].' '.$now->year;

        // Signature Direktur HANYA muncul kalau Aldy sudah TTD kontrak ini.
        // Sebelum state signed_direktur (draft/paraf_hrd/pending_karyawan/signed_karyawan/pending_direktur),
        // slot TTD Direktur harus kosong - jangan fallback ke master TTD company,
        // karena itu bikin PDF preview tampak seakan-akan sudah di-approve direktur padahal belum.
        $direkturHasSigned = in_array($contract->status, [
            Contract::STATE_SIGNED_DIREKTUR,
            Contract::STATE_PENDING_MATERAI,
            Contract::STATE_ARCHIVED,
        ], true);

        $direkturSig = null;
        if ($direkturHasSigned) {
            if (! $contract->direktur_signature_path) {
                throw new PrivateAssetIntegrityException('missing', 'Contract director signature is unavailable.', 409);
            }
            $direkturSig = $this->contractDataUri($contract, 'direktur_signature_path', 'direktur_signature_sha256');
        }

        // Stempel juga cuma tampil setelah signed_direktur (biasanya tempel bersamaan TTD Direktur).
        $stampDataUri = $direkturHasSigned && $company?->stamp_path
            ? $this->privateAssets->dataUri(
                (int) $company->id,
                'company-signatures',
                (string) $company->id,
                $company->stamp_path,
                $company->stamp_sha256,
            )
            : null;

        return [
            'tplTitle' => $tpl?->title ?? $contract->contract_type,
            'renderedHtml' => $renderedHtml,
            'company' => $company,
            'employeeName' => $karyawan?->name ?? '-',
            'signerName' => $company?->director_name ?? 'Aldy Iyik Be',
            'signerTitle' => 'Direktur Utama',
            'placeDate' => $placeDate,
            'isPreview' => ! in_array($contract->status, [
                Contract::STATE_PENDING_MATERAI,
                Contract::STATE_ARCHIVED,
            ], true),
            'letterheadStyle' => $company?->letterhead_style ?? 'modern',
            'companyLogoDataUri' => Helpers::cachedResizedImageDataUri($company?->logo, 300, 300),
            'stampDataUri' => $stampDataUri,
            'signatureDataUri' => $direkturSig,
            'employeeSignatureDataUri' => $this->contractDataUri($contract, 'karyawan_signature_path', 'karyawan_signature_sha256'),
            'hrdSignatureDataUri' => $this->contractDataUri($contract, 'hrd_paraf_signature_path', 'hrd_paraf_signature_sha256'),
        ];
    }

    // ═══════════════════ Query Helpers ═══════════════════

    /** Kontrak yang menunggu paraf HRD (untuk HRD dashboard). */
    public function pendingHrdParaf(int $companyId)
    {
        return Contract::where('company_id', $companyId)
            ->where('status', Contract::STATE_DRAFT)
            ->orderByDesc('issued_at')
            ->get();
    }

    /** Kontrak yang menunggu TTD karyawan. */
    public function pendingKaryawanSign(User $karyawan)
    {
        return Contract::where('user_id', $karyawan->id)
            ->whereIn('status', [Contract::STATE_PENDING_KARYAWAN, 'pending_signature']) // legacy compat
            ->orderByDesc('issued_at')
            ->get();
    }

    /** Kontrak yang menunggu TTD direktur (untuk CEO dashboard). */
    public function pendingDirekturSign(int $companyId)
    {
        return Contract::where('company_id', $companyId)
            ->where('status', Contract::STATE_PENDING_DIREKTUR)
            ->with('user:id,name,email')
            ->orderByDesc('karyawan_signed_at')
            ->get();
    }

    /** Kontrak yang menunggu upload materai (untuk HRD). */
    public function pendingMateraiUpload(int $companyId)
    {
        return Contract::where('company_id', $companyId)
            ->where('status', Contract::STATE_PENDING_MATERAI)
            ->with('user:id,name,email')
            ->orderByDesc('direktur_signed_at')
            ->get();
    }

    /** Delete only private files owned by this contract; retain legacy public originals for rollback/UAT. */
    public function deletePrivateAssets(Contract $contract): void
    {
        $paths = array_unique(array_filter([
            $contract->signed_pdf_path,
            $contract->materai_pdf_path,
            $contract->signature_path,
            $contract->hrd_paraf_signature_path,
            $contract->karyawan_signature_path,
            $contract->direktur_signature_path,
        ], fn ($path): bool => is_string($path) && $path !== ''));

        foreach ($paths as $path) {
            if ($this->privateAssets->isPrivatePathFor(
                (int) $contract->company_id,
                'contracts',
                (string) $contract->id,
                $path,
            )) {
                $this->privateAssets->deletePrivate(
                    (int) $contract->company_id,
                    'contracts',
                    (string) $contract->id,
                    $path,
                );
            }
        }
    }

    // ═══════════════════ Utilities ═══════════════════

    private function assertState(Contract $contract, array $expected): void
    {
        if (! in_array($contract->status, $expected, true)) {
            abort(422, "Aksi tidak valid untuk kontrak berstatus '{$contract->status}'. Diharapkan salah satu: ".implode(', ', $expected));
        }
    }

    private function storeSignatureDataUri(Contract $contract, string $dataUri, string $field): array
    {
        return $this->privateAssets->storePng(
            (int) $contract->company_id,
            'contracts',
            (string) $contract->id,
            $field,
            $this->privateAssets->strictDecodePngDataUri($dataUri),
        );
    }

    private function contractDataUri(Contract $contract, string $pathField, string $checksumField): ?string
    {
        return $this->privateAssets->dataUri(
            (int) $contract->company_id,
            'contracts',
            (string) $contract->id,
            $contract->{$pathField},
            $contract->{$checksumField},
        );
    }

    private function deleteStored(Contract $contract, ?string $path): void
    {
        if ($path !== null) {
            $this->privateAssets->deletePrivate(
                (int) $contract->company_id,
                'contracts',
                (string) $contract->id,
                $path,
            );
        }
    }

    private function generateContractNumber(int $companyId, string $type): string
    {
        $year = now()->year;
        $prefix = match ($type) {
            'permanent' => 'PKWTT',
            'probation' => 'PROB',
            'pkwt' => 'PKWT',
            'training' => 'TRAIN',
            'intern_campus' => 'MAGANG-K',
            'intern_hub' => 'MAGANG-I',
            'pkl_smk' => 'PKL',
            default => 'KONTRAK',
        };

        // MAX-based sequence - tahan gap (deleted/rejected contracts).
        // Format nomor: {prefix}/{company:04d}/{seq:03d}/IBE/{year}
        // Extract seq dari contract_number existing, pakai MAX + 1.
        $pattern = sprintf('%s/%04d/', $prefix, $companyId);
        $suffix = '/IBE/'.$year;

        $maxSeq = Contract::withoutGlobalScopes()
            ->where('contract_number', 'LIKE', $pattern.'%'.$suffix)
            ->get()
            ->map(function ($c) use ($pattern, $suffix) {
                // Extract 3-digit sequence from between pattern and suffix
                $middle = substr($c->contract_number, strlen($pattern));
                $middle = substr($middle, 0, strpos($middle, $suffix) ?: strlen($middle));

                return (int) $middle;
            })
            ->max() ?? 0;

        $seq = $maxSeq + 1;

        return sprintf('%s/%04d/%03d/IBE/%d', $prefix, $companyId, $seq, $year);
    }
}
